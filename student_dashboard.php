<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/dashboard_ui.php';
require_once 'includes/project_helper.php';

requireLogin();

if ($_SESSION['role'] !== 'student') {
    redirectAfterLogin($_SESSION['role']);
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

// Fetch full user details for profile/settings
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_details = $stmt->fetch();
$profile_image = $user_details['profile_image'] ?? null;
$enrollment_no = $user_details['enrollment_no'] ?? 'N/A';
$user_dept = $user_details['department'] ?? 'Not Assigned';
$notif_mentor_msg = $user_details['notif_mentor_msg'] ?? 1;
$notif_project_approved = $user_details['notif_project_approved'] ?? 1;

// Handle View Selection
$view = $_GET['view'] ?? 'dashboard';
$project_id = $_GET['project_id'] ?? null;

// Handle individual notification read status
if (isset($_GET['read_notif'])) {
    $notif_id = (int)$_GET['read_notif'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $user_id]);
}

// Security: Verify user has access to project if a project_id is provided
if ($project_id && !isUserInProject($pdo, $project_id, $user_id, 'student')) {
    header("Location: student_dashboard.php?error=AccessDenied");
    exit();
}

// Get counts for stats
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM projects WHERE student_id = ?");
$stmt->execute([$user_id]);
$total_projects = $stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) as pending FROM projects WHERE student_id = ? AND status = 'Pending'");
$stmt->execute([$user_id]);
$pending_projects = $stmt->fetch()['pending'];

$stmt = $pdo->prepare("SELECT COUNT(*) as approved FROM projects WHERE student_id = ? AND status = 'Approved'");
$stmt->execute([$user_id]);
$approved_projects = $stmt->fetch()['approved'];

// Get all projects where user is owner OR a member
$stmt = $pdo->prepare("SELECT DISTINCT p.*, m.name as mentor_name 
                      FROM projects p 
                      JOIN users m ON p.mentor_id = m.id 
                      LEFT JOIN project_members pm ON p.id = pm.project_id
                      WHERE p.student_id = ? OR pm.student_id = ?
                      ORDER BY p.created_at DESC");
$stmt->execute([$user_id, $user_id]);
$all_projects = $stmt->fetchAll();

// Check if student already has a pending or approved project
$has_active_project = false;
$is_leader = false;
$is_member = false;
$user_project = null;

foreach ($all_projects as $p) {
    if ($p['status'] === 'Pending' || $p['status'] === 'Approved' || $p['status'] === 'Rejected') {
        $has_active_project = true;
        $user_project = $p;
        if ($p['student_id'] == $user_id) {
            $is_leader = true;
        } else {
            $is_member = true;
        }
        break;
    }
}

// Get recent projects for dashboard
$recent_projects = array_slice($all_projects, 0, 5);

// Get recent mentor feedback for dashboard
$stmt = $pdo->prepare("SELECT s.*, p.project_title, m.name as mentor_name 
                      FROM submissions s 
                      JOIN projects p ON s.project_id = p.id 
                      JOIN users m ON p.mentor_id = m.id
                      WHERE (p.student_id = ? OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.student_id = ?))
                      AND s.mentor_comment IS NOT NULL AND s.mentor_comment != ''
                      ORDER BY s.submitted_at DESC LIMIT 3");
$stmt->execute([$user_id, $user_id]);
$recent_feedback = $stmt->fetchAll();

// Get list of mentors
$stmt = $pdo->query("SELECT id, name FROM users WHERE role = 'mentor'");
$mentors = $stmt->fetchAll();

// Handle Actions (Create, Add Member, Chat, Upload)
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_project'])) {
        $title = trim($_POST['project_title']);
        $desc = trim($_POST['description']);
        $mentor_id_req = $_POST['mentor_id'];
        $edit_id = $_POST['edit_project_id'] ?? null;

        if ($edit_id) {
            $stmt = $pdo->prepare("UPDATE projects SET project_title = ?, description = ?, mentor_id = ?, status = 'Pending' WHERE id = ? AND student_id = ?");
            if ($stmt->execute([$title, $desc, $mentor_id_req, $edit_id, $user_id])) {
                header("Location: student_dashboard.php?view=my-projects&success=ProjectUpdated");
                exit();
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO projects (student_id, mentor_id, project_title, description) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$user_id, $mentor_id_req, $title, $desc])) {
                header("Location: student_dashboard.php?success=ProjectCreated");
                exit();
            }
        }
    }

    if (isset($_POST['add_member'])) {
        if (!$is_leader) {
            $error_msg = "Only team leaders can add members.";
        } else {
            $proj_id = $_POST['proj_id'];
            $member_id = $_POST['member_id'];
            
            // Check current team size
            $stmtCount = $pdo->prepare("SELECT COUNT(*) as team_size FROM project_members WHERE project_id = ?");
            $stmtCount->execute([$proj_id]);
            $current_team_size = $stmtCount->fetch()['team_size'] + 1; // +1 for the leader
            
            // Check project status
            $stmtStatus = $pdo->prepare("SELECT status FROM projects WHERE id = ?");
            $stmtStatus->execute([$proj_id]);
            $proj_status = $stmtStatus->fetchColumn();

            if ($proj_status === 'Approved') {
                $error_msg = "Team members cannot be added after the project is Approved.";
            } elseif ($current_team_size >= 4) {
                $error_msg = "Maximum team size of 4 members reached.";
            } elseif (isStudentInAnyProject($pdo, $member_id)) {
                $error_msg = "This student is already part of a project.";
            } else {
                $stmt = $pdo->prepare("INSERT IGNORE INTO project_members (project_id, student_id) VALUES (?, ?)");
                $stmt->execute([$proj_id, $member_id]);
                header("Location: student_dashboard.php?view=project-details&project_id=$proj_id&success=MemberAdded");
                exit();
            }
        }
    }

    if (isset($_POST['remove_member'])) {
        if (!$is_leader) {
            $error_msg = "Only team leaders can remove members.";
        } else {
            $proj_id = $_POST['proj_id'];
            $member_id = $_POST['member_id'];
            
            $stmt = $pdo->prepare("DELETE FROM project_members WHERE project_id = ? AND student_id = ?");
            if ($stmt->execute([$proj_id, $member_id])) {
                header("Location: student_dashboard.php?view=project-details&project_id=$proj_id&success=MemberRemoved");
                exit();
            }
        }
    }

    if (isset($_POST['upload_work'])) {
        if (!$is_leader) {
            $error_msg = "Only team leaders can submit project work.";
        } else {
            $proj_id = $_POST['proj_id'];
            $title = trim($_POST['file_title']);
            $file = $_FILES['pdf_file'];

            $upload_dir = 'uploads/submissions/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $file_name = time() . '_' . basename($file['name']);
            $target_file = $upload_dir . $file_name;

            if (move_uploaded_file($file['tmp_name'], $target_file)) {
                $stmt = $pdo->prepare("INSERT INTO submissions (project_id, student_id, submission_title, file_path, status) VALUES (?, ?, ?, ?, 'Pending')");
                if ($stmt->execute([$proj_id, $user_id, $title, $target_file])) {
                    // Notify Mentor
                    $p_stmt = $pdo->prepare("SELECT mentor_id, project_title FROM projects WHERE id = ?");
                    $p_stmt->execute([$proj_id]);
                    $p_info = $p_stmt->fetch();
                    $msg = "Student uploaded new work: '$title' for '{$p_info['project_title']}'";
                    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, type, message) VALUES (?, ?, 'submission', ?)");
                    $stmt->execute([$p_info['mentor_id'], $proj_id, $msg]);

                    header("Location: student_dashboard.php?view=project-details&project_id=$proj_id&success=WorkUploaded");
                    exit();
                } else {
                    $error_msg = "Database error.";
                }
            } else {
                $error_msg = "Upload failed.";
            }
        }
    }

    if (isset($_POST['send_msg'])) {
        $proj_id = $_POST['proj_id'];
        $msg = trim($_POST['message']);
        $from_view = $_POST['from_view'] ?? 'project-details';
        
        if (!empty($msg) && isUserInProject($pdo, $proj_id, $user_id, 'student')) {
            sendMessage($pdo, $proj_id, $user_id, $msg);
            
            // Notify Mentor
            $p_stmt = $pdo->prepare("SELECT mentor_id, project_title FROM projects WHERE id = ?");
            $p_stmt->execute([$proj_id]);
            $p_info = $p_stmt->fetch();
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, type, message) VALUES (?, ?, 'message', ?)");
            $stmt->execute([$p_info['mentor_id'], $proj_id, "New message from student in '{$p_info['project_title']}'"]);

            if ($from_view === 'feedback') {
                header("Location: student_dashboard.php?view=feedback#chat");
            } else {
                header("Location: student_dashboard.php?view=project-details&project_id=$proj_id#chat");
            }
            exit();
        }
    }

    if (isset($_POST['update_profile_photo']) && isset($_FILES['profile_photo'])) {
        $file = $_FILES['profile_photo'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        
        if (in_array($file['type'], $allowed_types) && $file['size'] < 2000000) { // 2MB limit
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
            $target_path = 'uploads/profile_photos/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                // Delete old photo if exists
                if ($profile_image && file_exists($profile_image)) {
                    unlink($profile_image);
                }
                
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$target_path, $user_id]);
                header("Location: student_dashboard.php?view=profile&success=PhotoUpdated");
                exit();
            }
        } else {
            $error_msg = "Invalid image. Only JPG, PNG, GIF under 2MB are allowed.";
        }
    }

    if (isset($_POST['remove_profile_photo'])) {
        if ($profile_image && file_exists($profile_image)) {
            unlink($profile_image);
        }
        $stmt = $pdo->prepare("UPDATE users SET profile_image = NULL WHERE id = ?");
        $stmt->execute([$user_id]);
        header("Location: student_dashboard.php?view=profile&success=PhotoRemoved");
        exit();
    }

    if (isset($_POST['change_password'])) {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        if ($new !== $confirm) {
            $error_msg = "New passwords do not match!";
        } elseif (password_verify($current, $user_details['password'])) {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $user_id]);
            header("Location: student_dashboard.php?view=settings&success=PasswordChanged");
            exit();
        } else {
            $error_msg = "Incorrect current password!";
        }
    }

    if (isset($_POST['update_notifications'])) {
        $mentor_msg = isset($_POST['notif_mentor_msg']) ? 1 : 0;
        $proj_approved = isset($_POST['notif_project_approved']) ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE users SET notif_mentor_msg = ?, notif_project_approved = ? WHERE id = ?");
        $stmt->execute([$mentor_msg, $proj_approved, $user_id]);
        header("Location: student_dashboard.php?view=settings&success=SettingsUpdated");
        exit();
    }

    if (isset($_POST['update_profile_info'])) {
        $new_name = trim($_POST['full_name']);
        $new_email = trim($_POST['email']);
        
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
        $stmt->execute([$new_name, $new_email, $user_id]);
        
        // Update session info
        $_SESSION['name'] = $_SESSION['role'] === 'student' ? $new_name . ' | ' . $enrollment_no : $new_name;
        $_SESSION['email'] = $new_email;
        
        header("Location: student_dashboard.php?view=profile&success=ProfileUpdated");
        exit();
    }

    if (isset($_POST['mark_all_read'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $ref = $_SERVER['HTTP_REFERER'] ?? 'student_dashboard.php';
        header("Location: $ref");
        exit();
    }

    if (isset($_POST['delete_project'])) {
        $proj_id = (int)$_POST['proj_id'];
        
        // Verify ownership and status (only Pending or Rejected projects can be deleted by student)
        $stmt = $pdo->prepare("SELECT status, student_id FROM projects WHERE id = ?");
        $stmt->execute([$proj_id]);
        $project_data = $stmt->fetch();
        
        if ($project_data && $project_data['student_id'] == $user_id && ($project_data['status'] == 'Pending' || $project_data['status'] == 'Rejected')) {
            $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
            if ($stmt->execute([$proj_id])) {
                header("Location: student_dashboard.php?view=my-projects&success=ProjectDeleted");
                exit();
            }
        } else {
            $error_msg = "You cannot delete this project. Only pending or rejected projects can be removed by the team leader.";
        }
    }
}

// Success feedback
if (isset($_GET['success'])) {
    $success_msg = str_replace(
        ['ProjectCreated', 'ProjectUpdated', 'MemberAdded', 'MemberRemoved', 'WorkUploaded', 'ProjectSubmitted', 'PhotoUpdated', 'PhotoRemoved', 'PasswordChanged', 'SettingsUpdated', 'ProfileUpdated', 'ProjectDeleted'],
        ['Project created successfully!', 'Project updated and resubmitted!', 'Team member added!', 'Member removed from team!', 'Work uploaded successfully!', 'Project Submitted Successfully - Status: Waiting for Mentor Approval', 'Profile photo updated!', 'Profile photo removed!', 'Password changed successfully!', 'Settings updated!', 'Profile information updated!', 'Project registration removed successfully!'],
        $_GET['success']
    );
}

if (isset($_GET['success']) && $_GET['success'] == 'ProjectDeleted') {
    $success_msg = "Project registration removed successfully!";
}

// Fetch unread notifications
$unread_notifications = [];
$unread_count = 0;

try {
    $notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
    $notif_stmt->execute([$user_id]);
    $unread_notifications = $notif_stmt->fetchAll();
    $unread_count = count($unread_notifications);
} catch (PDOException $e) {
    error_log("Notification fetch failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - ProjectFlow</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--shadow-card);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .icon-blue {
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
        }

        .icon-green {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent);
        }

        .icon-orange {
            background: rgba(245, 158, 11, 0.1);
            color: #F59E0B;
        }

        .stat-info h3 {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .stat-info p {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
        }

        .table-container {
            background: var(--bg-card);
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--shadow-card);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 16px;
            border-bottom: 1px solid #F1F5F9;
            color: var(--text-muted);
            font-size: 14px;
        }

        td {
            padding: 16px;
            border-bottom: 1px solid #F1F5F9;
            font-size: 14px;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pending {
            background: #FEF3C7;
            color: #92400E;
        }

        .status-approved {
            background: #DCFCE7;
            color: #166534;
        }

        .status-rejected {
            background: #FEE2E2;
            color: #991B1B;
        }

        .form-card {
            max-width: 600px;
        }

        textarea.form-input {
            height: 150px;
            resize: vertical;
        }

        .centered-form-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 200px);
            padding: 20px 0;
        }

        .glass-form {
            background: rgba(255, 255, 255, 0.1) !important;
            backdrop-filter: blur(20px) !important;
            -webkit-backdrop-filter: blur(20px) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
            width: 100%;
            max-width: 600px;
        }

        .glass-form h3,
        .glass-form label {
            color: #ffffff !important;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .glass-form .form-input {
            background: rgba(255, 255, 255, 0.9) !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
        }

        /* Modern Project Card Enhancements */
        .project-card-modern {
            transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1) !important;
        }
        .project-card-modern:hover {
            transform: translateY(-6px) scale(1.002);
            border-color: rgba(99, 102, 241, 0.4) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 25px rgba(99, 102, 241, 0.1) !important;
        }
        .project-card-modern .card-hover-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.08), transparent 70%);
            opacity: 0;
            transition: opacity 0.4s ease;
            z-index: 1;
            pointer-events: none;
        }
        .project-card-modern:hover .card-hover-overlay {
            opacity: 1;
        }
        .tech-tag {
            transition: all 0.3s ease;
        }
        .project-card-modern:hover .tech-tag {
            background: rgba(99, 102, 241, 0.12) !important;
            color: white !important;
            border-color: rgba(99, 102, 241, 0.3) !important;
        }
    </style>
</head>

<body class="dashboard-body">
    <?php renderSidebar('student', $view, $is_member, $user_project, $has_active_project); ?>


    <?php renderTopNavbar('Student Dashboard', $user_name, 'STUDENT PORTAL', $unread_count, $unread_notifications, $profile_image, $_SESSION['email'] ?? $enrollment_no); ?>


    <div class="content-area">


        <?php if ($is_member && $view == 'dashboard' && $user_project): ?>
            <div class="alert alert-info" style="background: rgba(99, 102, 241, 0.1); color: #6366F1; border: 1px solid rgba(99, 102, 241, 0.2); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; border-radius: 12px; padding: 20px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="width: 40px; height: 40px; background: rgba(99, 102, 241, 0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #6366F1;">
                        <i class="fa-solid fa-users-rays"></i>
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: white;">Welcome to your Team!</h4>
                        <div style="font-size: 13px; color: rgba(255,255,255,0.7); margin-top: 4px;">You have been added to <strong><?php echo htmlspecialchars($user_project['project_title']); ?></strong> by the team leader.</div>
                    </div>
                </div>
                <a href="?view=project-details&project_id=<?php echo $user_project['id']; ?>" class="btn" style="background: var(--primary); padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700; text-decoration: none;">View Details <i class="fa-solid fa-arrow-right" style="margin-left: 8px;"></i></a>
            </div>
        <?php endif; ?>

        <?php if ($view == 'dashboard'): ?>
            <div class="premium-header">
                <div class="premium-header-label-badge">
                    <i class="fa-solid fa-house"></i> Overview
                </div>
                <h1 class="premium-header-title">Hello, <?php echo htmlspecialchars(explode('|', $user_name)[0]); ?>!</h1>
                <p class="premium-header-subtitle" style="color: rgba(255,255,255,0.6);">Manage your projects and track your academic progress here.</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card premium-glass-card">
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-briefcase"></i></div>
                    <div class="stat-info">
                        <h3 style="color: rgba(255,255,255,0.7); font-size: 14px; margin-bottom: 4px;">Total Projects</h3>
                        <p style="color: white;"><?php echo $total_projects; ?></p>
                    </div>
                </div>
                <div class="stat-card premium-glass-card">
                    <div class="stat-icon icon-orange"><i class="fa-solid fa-clock"></i></div>
                    <div class="stat-info">
                        <h3 style="color: rgba(255,255,255,0.6);">Pending</h3>
                        <p style="color: white;"><?php echo $pending_projects; ?></p>
                    </div>
                </div>
                <div class="stat-card premium-glass-card">
                    <div class="stat-icon icon-green"><i class="fa-solid fa-check-circle"></i></div>
                    <div class="stat-info">
                        <h3 style="color: rgba(255,255,255,0.6);">Approved</h3>
                        <p style="color: white;"><?php echo $approved_projects; ?></p>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-top: 40px;">
                <div class="table-container premium-glass-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="font-size: 18px; color: white;">Recent Projects</h3>
                        <a href="?view=my-projects"
                            style="color: var(--primary); text-decoration: none; font-size: 14px; font-weight: 600;">View
                            All</a>
                    </div>
                    <table>
                        <thead>
                            <tr style="border-bottom: 2px solid rgba(255,255,255,0.05);">
                                <th style="color: rgba(255,255,255,0.4); text-transform: uppercase; font-size: 11px; letter-spacing: 1px; padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); background: transparent;">Project Title</th>
                                <th style="color: rgba(255,255,255,0.4); text-transform: uppercase; font-size: 11px; letter-spacing: 1px; padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); background: transparent;">Mentor</th>
                                <th style="color: rgba(255,255,255,0.4); text-transform: uppercase; font-size: 11px; letter-spacing: 1px; padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); background: transparent;">Status</th>
                                <th style="color: rgba(255,255,255,0.4); text-transform: uppercase; font-size: 11px; letter-spacing: 1px; padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); background: transparent;">Created Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recent_projects) > 0): ?>
                                <?php foreach ($recent_projects as $project): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <td style="padding: 15px 20px;">
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); display: flex; align-items: center; justify-content: center; color: var(--primary); flex-shrink: 0;">
                                                    <i class="fa-solid fa-folder-open" style="font-size: 14px;"></i>
                                                </div>
                                                <div style="font-weight: 500; color: white;">[<?php echo formatProjectNumber($project['id']); ?>] <?php echo htmlspecialchars($project['project_title']); ?></div>
                                            </div>
                                        </td>
                                        <td style="font-size: 13px; color: rgba(255,255,255,0.6); padding: 15px 20px;">
                                            <?php echo htmlspecialchars($project['mentor_name']); ?>
                                        </td>
                                        <td style="padding: 15px 20px;">
                                            <span class="status-badge <?php
                                            echo $project['status'] == 'Approved' ? 'status-approved' : ($project['status'] == 'Rejected' ? 'status-rejected' : 'status-pending');
                                            ?>" style="font-size: 11px; padding: 4px 10px;">
                                                <?php echo $project['status']; ?>
                                            </span>
                                        </td>
                                        <td style="color: rgba(255,255,255,0.5); padding: 15px 20px; font-size: 13px;">
                                            <?php echo date('M d, Y', strtotime($project['created_at'])); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--text-muted);">No projects found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="premium-glass-card" style="padding: 24px; align-self: start;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="font-size: 18px; color: white;">Recent Feedback</h3>
                        <a href="?view=feedback" style="color: var(--primary); text-decoration: none; font-size: 14px; font-weight: 600;">View All</a>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <?php if (count($recent_feedback) > 0): ?>
                            <?php foreach ($recent_feedback as $fb): ?>
                                <div style="padding: 14px; background: rgba(255, 255, 255, 0.05); border-left: 3px solid rgba(255, 255, 255, 0.3); border-radius: 8px;">
                                    <div style="font-size: 13px; font-weight: 600; color: white; margin-bottom: 6px;"><?php echo htmlspecialchars($fb['project_title']); ?></div>
                                    <div style="color: rgba(255,255,255,0.7); font-size: 12px; line-height: 1.5; margin-bottom: 10px;"><?php echo htmlspecialchars(substr($fb['mentor_comment'], 0, 100)) . (strlen($fb['mentor_comment']) > 100 ? '...' : ''); ?></div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div style="font-size: 11px; color: rgba(255,255,255,0.4);"><i class="fa-solid fa-user" style="font-size: 10px; margin-right: 4px;"></i> <?php echo htmlspecialchars($fb['mentor_name']); ?></div>
                                        <div style="font-size: 11px; color: rgba(255,255,255,0.4);"><?php echo date('M d', strtotime($fb['submitted_at'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 30px 0;">
                                <i class="fa-regular fa-comments" style="font-size: 24px; color: rgba(255,255,255,0.1); margin-bottom: 10px;"></i>
                                <p style="color: rgba(255,255,255,0.4); font-size: 13px;">No recent feedback.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($view == 'project-details' && $project_id): ?>
            <?php
            // Fetch project details
            $stmt = $pdo->prepare("SELECT p.*, m.name as mentor_name, u.name as owner_name 
                                  FROM projects p 
                                  JOIN users m ON p.mentor_id = m.id 
                                  JOIN users u ON p.student_id = u.id
                                  WHERE p.id = ?");
            $stmt->execute([$project_id]);
            $project = $stmt->fetch();

            // Fetch team members
            $stmt = $pdo->prepare("SELECT u.id, u.name, u.email FROM project_members pm JOIN users u ON pm.student_id = u.id WHERE pm.project_id = ?");
            $stmt->execute([$project_id]);
            $team = $stmt->fetchAll();

            // Fetch submissions
            $stmt = $pdo->prepare("SELECT s.*, u.name as student_name FROM submissions s JOIN users u ON s.student_id = u.id WHERE s.project_id = ? ORDER BY s.submitted_at DESC");
            $stmt->execute([$project_id]);
            $submissions = $stmt->fetchAll();

            // Fetch messages
            $messages = getMessages($pdo, $project_id);
            markMessagesAsRead($pdo, $project_id, $user_id);

            if ($project['student_id'] == $user_id) {
                $stmt = $pdo->prepare("
                    SELECT id, name, enrollment_no 
                    FROM users u 
                    WHERE role = 'student' 
                    AND id != ?
                    AND NOT EXISTS (
                        SELECT 1 FROM projects p WHERE p.student_id = u.id AND p.status IN ('Pending', 'Approved')
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM project_members pm JOIN projects p ON pm.project_id = p.id WHERE pm.student_id = u.id AND p.status IN ('Pending', 'Approved')
                    )
                    ORDER BY name ASC
                ");
                $stmt->execute([$user_id]);
                $other_students = $stmt->fetchAll();
            }
            ?>
            <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                <!-- Left Column: Project Info & Team -->
                <div style="flex: 1; min-width: 400px; display: flex; flex-direction: column; gap: 24px;">
                    <?php 
                    // CRITICAL: Recalculate leader status for THIS specific project view
                    $is_current_leader = ($project['student_id'] == $user_id);
                    ?>
                    <div class="premium-glass-card" style="padding: 32px; position: relative; z-index: 1; display: flex; flex-direction: column; gap: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <h2 style="font-size: 20px; color: white; font-weight: 800; margin: 0;">Project Details</h2>
                            <span class="status-badge <?php echo 'status-' . strtolower($project['status']); ?>"><?php echo $project['status']; ?></span>
                        </div>
                        
                            <div style="grid-column: 1 / -1;">
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <h3 style="color: white; font-size: 20px; font-weight: 800; line-height: 1.2; margin: 0;">[<?php echo formatProjectNumber($project['id']); ?>] <?php echo htmlspecialchars($project['project_title']); ?></h3>
                                    <?php if (!empty($project['seminar_name'])): ?>
                                        <span style="color: var(--primary); font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo htmlspecialchars($project['seminar_name']); ?> Seminar</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                        <!-- Description -->
                        <div>
                            <small style="color: rgba(255,255,255,0.5); display: block; margin-bottom: 6px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Description</small>
                            <p style="color: rgba(255,255,255,0.8); line-height: 1.6; font-size: 14px; margin: 0;">
                                <?php echo nl2br(htmlspecialchars($project['description'])); ?>
                            </p>
                        </div>

                        <!-- Row 2: Leader & Assigned Mentor -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 24px;">
                            <div>
                                <small style="color: rgba(255,255,255,0.5); display: block; margin-bottom: 6px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Leader</small>
                                <strong style="color: white; font-size: 15px;"><?php echo htmlspecialchars($project['owner_name']); ?></strong>
                            </div>
                            <div>
                                <small style="color: rgba(255,255,255,0.5); display: block; margin-bottom: 6px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Assigned Mentor</small>
                                <strong style="color: white; font-size: 15px;"><?php echo htmlspecialchars($project['mentor_name']); ?></strong>
                            </div>
                        </div>

                        <!-- Row 3: Technologies Used -->
                        <div style="border-top: 1px solid rgba(255,255,255,0.08); padding-top: 24px;">
                            <small style="color: rgba(255,255,255,0.5); display: block; margin-bottom: 12px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Technologies Used</small>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php 
                                $tech_input = $project['technologies'] ?? 'Not Specified';
                                $tech_list = [];
                                
                                // Try to decode if it's JSON (as sent by the register form)
                                $decoded = json_decode($tech_input, true);
                                if (is_array($decoded)) {
                                    foreach ($decoded as $category => $items) {
                                        if (is_array($items)) {
                                            $tech_list = array_merge($tech_list, $items);
                                        }
                                    }
                                } else {
                                    // Fallback to comma separated if not JSON
                                    $tech_list = explode(',', $tech_input);
                                }
                                
                                foreach($tech_list as $tech): 
                                    if (empty(trim($tech))) continue;
                                ?>
                                    <span class="tech-tag">
                                        <?php echo htmlspecialchars(trim($tech)); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="premium-glass-card" style="padding: 32px; position: relative; z-index: 2;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                            <h3 style="color: white; font-weight: 700; font-size: 18px; margin: 0;">Project Team</h3>
                            <?php 
                            $current_team_size = count($team) + 1; // Team members + 1 Leader
                            $is_approved = ($project['status'] === 'Approved');
                            
                            if ($is_current_leader): 
                                if ($is_approved):
                            ?>
                                <span style="font-size: 12px; color: #6366F1; background: rgba(99, 102, 241, 0.1); padding: 8px 14px; border-radius: 8px; font-weight: 600; border: 1px solid rgba(99, 102, 241, 0.2);"><i class="fa-solid fa-lock" style="margin-right: 6px;"></i> Team Locked</span>
                            <?php 
                                elseif ($current_team_size < 4):
                            ?>
                                <button onclick="document.getElementById('addMemberModal').style.display='flex'" class="btn"
                                    style="padding: 10px 20px; font-size: 13px; width: auto; background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%); border: none; border-radius: 12px; font-weight: 600; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3); margin: 0;">
                                    <i class="fa-solid fa-user-plus" style="font-size: 14px;"></i> Add Member
                                </button>
                            <?php else: ?>
                                <span style="font-size: 12px; color: var(--accent); background: rgba(16, 185, 129, 0.1); padding: 8px 14px; border-radius: 8px; font-weight: 600; border: 1px solid rgba(16, 185, 129, 0.2);"><i class="fa-solid fa-check-circle" style="margin-right: 6px;"></i> Team Full</span>
                            <?php 
                                endif; 
                            endif; 
                            ?>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <div
                                style="display: flex; align-items: center; gap: 14px; padding: 12px 16px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); min-height: 60px;">
                                <div
                                    style="width: 36px; height: 36px; background: rgba(99, 102, 241, 0.2); color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; flex-shrink: 0;">
                                    <?php echo substr($project['owner_name'], 0, 1); ?>
                                </div>
                                <div style="display: flex; flex-direction: row; align-items: center; justify-content: space-between; flex: 1; min-width: 0; gap: 10px;">
                                    <strong
                                        style="font-size: 14px; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1;"><?php echo htmlspecialchars($project['owner_name']); ?></strong>
                                    <span
                                        style="font-size: 11px; color: var(--primary); background: rgba(79, 70, 229, 0.15); padding: 4px 10px; border-radius: 20px; font-weight: 600; flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px;"><i class="fa-solid fa-crown" style="font-size: 10px;"></i> Leader</span>
                                </div>
                            </div>
                            <?php foreach ($team as $m): ?>
                                <div
                                    style="display: flex; align-items: center; gap: 14px; padding: 12px 16px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); min-height: 60px;">
                                    <div
                                        style="width: 36px; height: 36px; background: rgba(255,255,255,0.08); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; flex-shrink: 0;">
                                        <?php echo substr($m['name'], 0, 1); ?>
                                    </div>
                                    <strong style="font-size: 14px; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1;"><?php echo htmlspecialchars($m['name']); ?></strong>
                                    
                                    <?php if ($is_current_leader && !$is_approved): ?>
                                        <form method="POST" onsubmit="return confirm('Remove <?php echo htmlspecialchars($m['name']); ?> from the team?');" style="margin: 0;">
                                            <input type="hidden" name="proj_id" value="<?php echo $project_id; ?>">
                                            <input type="hidden" name="member_id" value="<?php echo $m['id']; ?>">
                                            <button type="submit" name="remove_member" title="Remove Member" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #EF4444; cursor: pointer; padding: 8px 12px; border-radius: 8px; transition: all 0.3s ease; font-size: 13px; display: inline-flex; align-items: center; justify-content: center;" onmouseover="this.style.background='rgba(239, 68, 68, 0.2)'; this.style.color='#F87171'; this.style.transform='translateY(-2px)';" onmouseout="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.color='#EF4444'; this.style.transform='translateY(0)';">
                                                <i class="fa-solid fa-user-minus"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="premium-glass-card" style="padding: 32px; border-top: 1px solid rgba(255,255,255,0.05); position: relative; z-index: 3;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 style="color: white; font-weight: 700;">Work Submissions (PDF)</h3>
                            <?php if ($is_current_leader): ?>
                            <button onclick="document.getElementById('uploadModal').style.display='flex'" class="btn"
                                style="padding: 10px 20px; font-size: 13px; width: auto; background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%); border: none; border-radius: 12px; font-weight: 600; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);">
                                <i class="fa-solid fa-cloud-arrow-up" style="font-size: 14px;"></i> Upload Work
                            </button>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <?php foreach ($submissions as $sub): ?>
                                <div style="padding: 15px; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; background: rgba(255,255,255,0.02);">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div>
                                            <a href="<?php echo htmlspecialchars($sub['file_path']); ?>" target="_blank"
                                                style="color: var(--primary); text-decoration: none; font-weight: 600;">
                                                <i class="fa-solid fa-file-pdf"></i>
                                                <?php echo htmlspecialchars($sub['submission_title']); ?>
                                            </a>
                                            <small style="display: block; color: rgba(255,255,255,0.6); margin-top: 4px;">Uploaded
                                                by <?php echo htmlspecialchars($sub['student_name']); ?> on
                                                <?php echo date('M d, Y', strtotime($sub['submitted_at'])); ?></small>
                                        </div>
                                        <span class="status-badge <?php echo 'status-' . strtolower($sub['status']); ?>"
                                            style="font-size: 10px;"><?php echo $sub['status']; ?></span>
                                    </div>
                                    <?php if ($sub['mentor_comment']): ?>
                                        <div
                                            style="margin-top: 12px; padding: 10px; background: rgba(255, 255, 255, 0.05); border-radius: 8px; border-left: 3px solid rgba(255, 255, 255, 0.3); font-size: 12px;">
                                            <strong style="color: white; opacity: 0.9;">Mentor
                                                Feedback:</strong><br><span style="color: white;"><?php echo nl2br(htmlspecialchars($sub['mentor_comment'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($submissions))
                                echo '<p style="text-align: center; color: rgba(255,255,255,0.4); font-size: 13px;">No work submitted yet.</p>'; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Group Chat -->
                <div style="flex: 1; min-width: 400px;">
                    <div class="premium-glass-card" id="chat"
                        style="padding: 0; display: flex; flex-direction: column; height: 600px; overflow: hidden; border: 1px solid rgba(255,255,255,0.1);">
                        <div style="padding: 20px 24px; border-bottom: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.02); display: flex; align-items: center; gap: 12px;">
                            <div style="width: 40px; height: 40px; background: rgba(99, 102, 241, 0.15); color: #6366F1; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                                <i class="fa-solid fa-comments"></i>
                            </div>
                            <div>
                                <h3 style="font-size: 16px; color: white; font-weight: 800; margin: 0; letter-spacing: -0.3px;">Group Chat - [<?php echo formatProjectNumber($project_id); ?>]</h3>
                                <small style="color: rgba(255,255,255,0.5); font-weight: 500;">Collaborate with your team and mentor</small>
                            </div>
                        </div>
                        <div
                            style="flex: 1; overflow-y: auto; padding: 20px; background: rgba(0,0,0,0.15); display: flex; flex-direction: column; gap: 15px;">
                            <?php foreach ($messages as $msg):
                                $isMe = ($msg['user_id'] == $user_id);
                                $isMentor = ($msg['role'] == 'mentor');
                                ?>
                                <div style="max-width: 80%; align-self: <?php echo $isMe ? 'flex-end' : 'flex-start'; ?>">
                                    <small
                                        style="display: block; margin-bottom: 4px; color: rgba(255,255,255,0.6); text-align: <?php echo $isMe ? 'right' : 'left'; ?>">
                                        <?php echo htmlspecialchars($msg['user_name']); ?>
                                        <?php echo $isMentor ? '<span style="color:rgba(255,255,255,0.7); font-weight:700;">(Faculty)</span>' : ''; ?>
                                    </small>
                                    <div
                                        style="padding: 10px 14px; border-radius: 12px; font-size: 14px; line-height: 1.4; background: <?php echo $isMe ? 'var(--primary)' : ($isMentor ? 'rgba(255, 255, 255, 0.08)' : 'rgba(255,255,255,0.05)'); ?>; color: white; border: <?php echo $isMe ? 'none' : ($isMentor ? '1px solid rgba(255, 255, 255, 0.2)' : '1px solid rgba(255,255,255,0.1)'); ?>; border-bottom-right-radius: <?php echo $isMe ? '2px' : '12px'; ?>; border-bottom-left-radius: <?php echo $isMe ? '12px' : '2px'; ?>;">
                                        <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                    </div>
                                    <div style="display: flex; align-items: center; justify-content: <?php echo $isMe ? 'flex-end' : 'flex-start'; ?>; gap: 6px; margin-top: 4px;">
                                        <small style="font-size: 10px; color: rgba(255,255,255,0.4);"><?php echo date('h:i A', strtotime($msg['sent_at'])); ?></small>
                                        <?php if ($isMe): ?>
                                            <span style="font-size: 10px; font-weight: 700; color: <?php echo $msg['is_read'] ? '#10B981' : 'rgba(255,255,255,0.2)'; ?>;">
                                                <?php if ($msg['is_read']): ?>
                                                    <i class="fa-solid fa-check-double" style="margin-right: 2px;"></i> Seen
                                                <?php else: ?>
                                                    <i class="fa-solid fa-check"></i> Sent
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="padding: 20px; background: rgba(0,0,0,0.2); border-top: 1px solid rgba(255,255,255,0.1);">
                            <form method="POST" style="display: flex; gap: 10px; align-items: center;">
                                <input type="hidden" name="proj_id" value="<?php echo $project_id; ?>">
                                <input type="text" name="message" class="form-input" placeholder="Type your message..."
                                    autocomplete="off" required style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white;">
                                <button type="submit" name="send_msg" class="chat-send-btn"><i class="fa-solid fa-paper-plane"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modals -->
            <div id="addMemberModal"
                style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
                <div class="premium-glass-card" style="width: 100%; max-width: 440px; padding: 32px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); display: flex; flex-direction: column; box-sizing: border-box; border-radius: 20px; margin: auto;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                        <h3 style="color: white; font-size: 18px; font-weight: 700; margin: 0;">Add Team Member</h3>
                        <button type="button" onclick="document.getElementById('addMemberModal').style.display='none'" style="background: none; border: none; color: rgba(255,255,255,0.4); cursor: pointer; font-size: 18px; transition: color 0.2s; padding: 4px; display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <form method="POST" style="display: flex; flex-direction: column; width: 100%; margin: 0;">
                        <input type="hidden" name="proj_id" value="<?php echo $project_id; ?>">
                        <div class="form-group" style="margin-bottom: 32px; width: 100%;">
                            <label style="color: rgba(255,255,255,0.5); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-bottom: 12px; display: block;">Select Registered Student</label>
                            <div class="select-wrapper" style="position: relative; width: 100%;">
                                <select name="member_id" required>
                                    <option value="">-- Choose Student --</option>
                                    <?php if (isset($other_students))
                                        foreach ($other_students as $s): ?>
                                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name'] . " | " . $s['enrollment_no']); ?></option>
                                        <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; width: 100%;">
                            <button type="button" onclick="document.getElementById('addMemberModal').style.display='none'"
                                class="btn" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; height: 48px; border-radius: 12px; font-weight: 600; font-size: 14px; width: 100%; margin: 0; padding: 0; cursor: pointer; transition: all 0.2s;">Cancel</button>
                            <button type="submit" name="add_member" class="btn" style="background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%); border: none; color: white; height: 48px; border-radius: 12px; font-weight: 600; font-size: 14px; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3); display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin: 0; padding: 0; cursor: pointer; transition: all 0.2s;">
                                <i class="fa-solid fa-plus"></i> Add Member
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Upload Modal -->
            <div id="uploadModal"
                style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box;">
                <div class="premium-glass-card" style="width: 100%; max-width: 440px; margin: auto; padding: 36px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); display: flex; flex-direction: column; box-sizing: border-box; border-radius: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                        <h3 style="color: white; font-size: 18px; font-weight: 700; margin: 0;">Upload Work Submission</h3>
                        <button type="button" onclick="document.getElementById('uploadModal').style.display='none'" style="background: rgba(255,255,255,0.05); border: none; color: white; cursor: pointer; font-size: 16px; width: 32px; height: 32px; border-radius: 50%; transition: all 0.2s; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='rgba(239, 68, 68, 0.2)'; this.style.color='#EF4444';" onmouseout="this.style.background='rgba(255,255,255,0.05)'; this.style.color='white';"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; width: 100%; margin: 0;">
                        <input type="hidden" name="proj_id" value="<?php echo $project_id; ?>">
                        <div class="form-group" style="margin-bottom: 24px; width: 100%;">
                            <label style="color: rgba(255,255,255,0.5); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-bottom: 12px; display: block;">Submission Title</label>
                            <div style="position: relative; width: 100%;">
                                <i class="fa-solid fa-tag" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.4); font-size: 14px;"></i>
                                <input type="text" name="file_title" placeholder="e.g. Requirement Doc, UI Design" required style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); color: white; height: 50px; border-radius: 12px; padding: 0 16px 0 45px; width: 100%; font-family: inherit; font-size: 14px; outline: none; transition: border-color 0.2s; box-sizing: border-box;">
                            </div>
                        </div>
                        <style>
                            .premium-file-input::file-selector-button {
                                background: rgba(99, 102, 241, 0.15);
                                border: 1px solid rgba(99, 102, 241, 0.3);
                                color: #818CF8;
                                padding: 6px 16px;
                                border-radius: 8px;
                                margin-right: 16px;
                                font-weight: 600;
                                cursor: pointer;
                                transition: all 0.2s;
                                font-family: inherit;
                                font-size: 13px;
                            }
                            .premium-file-input::file-selector-button:hover {
                                background: rgba(99, 102, 241, 0.25);
                                color: white;
                            }
                        </style>
                        <div class="form-group" style="margin-bottom: 32px; width: 100%;">
                            <label style="color: rgba(255,255,255,0.5); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-bottom: 12px; display: block;">Select PDF File</label>
                            <div style="position: relative; width: 100%;">
                                <i class="fa-solid fa-file-pdf" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.4); font-size: 14px; pointer-events: none;"></i>
                                <input type="file" name="pdf_file" accept="application/pdf" required class="premium-file-input" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); color: white; height: 50px; border-radius: 12px; padding: 7px 16px 7px 45px; width: 100%; font-family: inherit; font-size: 14px; outline: none; transition: border-color 0.2s; box-sizing: border-box; cursor: pointer;">
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; width: 100%;">
                            <button type="button" onclick="document.getElementById('uploadModal').style.display='none'"
                                class="btn" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; height: 48px; border-radius: 12px; font-weight: 600; font-size: 14px; width: 100%; margin: 0; padding: 0; cursor: pointer; transition: all 0.2s;">Cancel</button>
                            <button type="submit" name="upload_work" class="btn" style="background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%); border: none; color: white; height: 48px; border-radius: 12px; font-weight: 600; font-size: 14px; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3); display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin: 0; padding: 0; cursor: pointer; transition: all 0.2s;">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Upload
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php elseif ($view == 'faculty-pool'): ?>
            <?php
            // Fetch mentors and their project counts, including new details
            $stmt = $pdo->query("SELECT u.id, u.name, u.email, u.department, 
                                REPLACE(u.designation, 'Lecturer', 'Assistant Professor') as designation, 
                                u.qualification, u.research_area,
                                (SELECT COUNT(*) FROM projects WHERE mentor_id = u.id AND (status = 'Approved' OR status = 'Pending')) as active_projects,
                                (SELECT COUNT(*) FROM projects WHERE mentor_id = u.id AND status = 'Pending') as pending_projects
                                FROM users u WHERE role = 'mentor' 
                                HAVING active_projects < 5
                                ORDER BY u.department ASC, u.name ASC");
            $faculty = $stmt->fetchAll();
            ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge">
                    <i class="fa-solid fa-graduation-cap"></i> Faculty
                </div>
                <h1 class="premium-header-title">Faculty Pool</h1>
                <p class="premium-header-subtitle">Browse available mentors and their respective departments.</p>
            </div>

            <div style="margin-bottom: 24px; display: flex; justify-content: flex-end;">
                <div style="position: relative; width: 100%; max-width: 500px;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #6366F1; font-size: 16px; opacity: 0.6;"></i>
                    <input type="text" id="facultySearch" placeholder="Search by mentor name, department, or email..." class="form-input" style="padding-left: 48px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); width: 100%; color: white;">
                </div>
            </div>

            <div class="table-container premium-glass-card">
                <table>
                    <thead>
                        <tr>
                            <th>Mentor Name</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Active Teams</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($faculty) > 0): ?>
                            <?php foreach ($faculty as $mentor): 
                                $mentor_json = json_encode($mentor);
                            ?>
                                <tr style="cursor: pointer;" onclick='showMentorDetails(<?php echo htmlspecialchars($mentor_json, ENT_QUOTES); ?>)'>
                                    <td style="font-weight: 500;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="width: 38px; height: 38px; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--accent)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);">
                                                <?php echo substr($mentor['name'], 0, 1); ?>
                                            </div>
                                            <span style="color: rgba(255,255,255,0.95); font-weight: 600; letter-spacing: 0.5px;"><?php echo htmlspecialchars($mentor['name']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge" style="background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.7); border: 1px solid rgba(255,255,255,0.1);">
                                            <?php echo htmlspecialchars($mentor['department'] ?? 'Not Assigned'); ?>
                                        </span>
                                    </td>
                                    <td style="color: rgba(255,255,255,0.6); font-size: 13px;"><?php echo htmlspecialchars($mentor['designation'] ?? 'N/A'); ?></td>
                                    <td>
                                        <div style="font-weight: 600; color: white;">
                                            <?php echo $mentor['active_projects']; ?> <span style="color: rgba(255,255,255,0.4); font-weight: 400;">/</span> 5
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($mentor['active_projects'] == 4): ?>
                                            <span class="status-badge" style="background: rgba(245, 158, 11, 0.15); color: #F59E0B; border: 1px solid rgba(245, 158, 11, 0.3); font-size: 11px; padding: 4px 12px;">Almost Full</span>
                                        <?php else: ?>
                                            <span class="status-badge" style="background: rgba(16, 185, 129, 0.15); color: #10B981; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 11px; padding: 4px 12px;">Available</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn" style="padding: 6px 14px; font-size: 11px; width: auto; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);">View Info</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">No faculty members found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <script>
                document.getElementById('facultySearch')?.addEventListener('keyup', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('.table-container tbody tr');
                    
                    rows.forEach(row => {
                        // Skip the 'No faculty members found' row if it exists
                        if (row.cells.length === 1) return;
                        
                        // We check the text of the first three columns (Name, Dept, Email)
                        const name = row.cells[0]?.innerText.toLowerCase() || '';
                        const dept = row.cells[1]?.innerText.toLowerCase() || '';
                        const email = row.cells[2]?.innerText.toLowerCase() || '';
                        
                        if (name.includes(filter) || dept.includes(filter) || email.includes(filter)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            </script>

        <?php elseif ($view == 'feedback'): ?>
            <?php
            // We need the student's active project to show the chat
            $active_project_id = null;
            $active_project_title = '';
            $active_mentor_name = '';
            
            foreach ($all_projects as $p) {
                if ($p['status'] === 'Pending' || $p['status'] === 'Approved') {
                    $active_project_id = $p['id'];
                    $active_project_title = $p['project_title'];
                    $active_mentor_name = $p['mentor_name'];
                    break;
                }
            }
            
            if ($active_project_id):
                $messages = getMessages($pdo, $active_project_id);
                markMessagesAsRead($pdo, $active_project_id, $user_id);
            ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge">
                    <i class="fa-solid fa-comments"></i> Communication
                </div>
                <h1 class="premium-header-title">Mentor Chat</h1>
                <p class="premium-header-subtitle">Directly chat with <?php echo htmlspecialchars($active_mentor_name); ?> regarding <?php echo htmlspecialchars($active_project_title); ?>.</p>
            </div>

            <div class="premium-glass-card" id="chat"
                style="padding: 0; display: flex; flex-direction: column; height: 600px; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); border-radius: 20px !important;">
                <div style="padding: 20px 24px; border-bottom: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.02); display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: rgba(99, 102, 241, 0.15); color: #6366F1; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                        <i class="fa-solid fa-comments"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 16px; color: white; font-weight: 800; margin: 0; letter-spacing: -0.3px;">Group Chat - [<?php echo formatProjectNumber($active_project_id); ?>]</h3>
                        <small style="color: rgba(255,255,255,0.5); font-weight: 500;">Collaborate with your team and mentor</small>
                    </div>
                </div>
                <div id="chatMessages"
                    style="flex: 1; overflow-y: auto; padding: 20px; background: rgba(0,0,0,0.15); display: flex; flex-direction: column; gap: 15px;">
                    <?php foreach ($messages as $msg):
                        $isMe = ($msg['user_id'] == $user_id);
                        $isMentor = ($msg['role'] == 'mentor');
                        ?>
                        <div style="max-width: 80%; align-self: <?php echo $isMe ? 'flex-end' : 'flex-start'; ?>">
                            <small
                                style="display: block; margin-bottom: 4px; color: rgba(255,255,255,0.6); text-align: <?php echo $isMe ? 'right' : 'left'; ?>">
                                <?php echo htmlspecialchars($msg['user_name']); ?>
                                <?php echo $isMentor ? '<span style="color:var(--primary); font-weight:700;">(Faculty)</span>' : ''; ?>
                            </small>
                            <div
                                style="padding: 10px 14px; border-radius: 12px; font-size: 14px; line-height: 1.4; background: <?php echo $isMe ? 'var(--primary)' : ($isMentor ? 'rgba(255, 255, 255, 0.08)' : 'rgba(255,255,255,0.05)'); ?>; color: white; border: <?php echo $isMe ? 'none' : ($isMentor ? '1px solid rgba(255, 255, 255, 0.2)' : '1px solid rgba(255,255,255,0.1)'); ?>; border-bottom-right-radius: <?php echo $isMe ? '2px' : '12px'; ?>; border-bottom-left-radius: <?php echo $isMe ? '12px' : '2px'; ?>;">
                                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: <?php echo $isMe ? 'flex-end' : 'flex-start'; ?>; gap: 6px; margin-top: 4px;">
                                <small style="font-size: 10px; color: rgba(255,255,255,0.4);"><?php echo date('h:i A', strtotime($msg['sent_at'])); ?></small>
                                <?php if ($isMe): ?>
                                    <span style="font-size: 10px; font-weight: 700; color: <?php echo $msg['is_read'] ? '#10B981' : 'rgba(255,255,255,0.2)'; ?>;">
                                        <?php if ($msg['is_read']): ?>
                                            <i class="fa-solid fa-check-double" style="margin-right: 2px;"></i> Seen
                                        <?php else: ?>
                                            <i class="fa-solid fa-check"></i> Sent
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="padding: 20px; background: rgba(0,0,0,0.2); border-top: 1px solid rgba(255,255,255,0.1);">
                    <form method="POST" style="display: flex; gap: 10px; align-items: center;">
                        <input type="hidden" name="proj_id" value="<?php echo $active_project_id; ?>">
                        <input type="hidden" name="from_view" value="feedback">
                        <input type="text" name="message" class="form-input" placeholder="Type your message..."
                            required style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white;">
                        <button type="submit" name="send_msg" class="chat-send-btn">
                            <i class="fa-solid fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
            
            <script>
                // Auto-scroll to bottom of chat
                const chatMessages = document.getElementById('chatMessages');
                if (chatMessages) {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
            </script>
            
            <?php else: ?>
                <div class="premium-header" style="margin-bottom: 30px;">
                    <div class="premium-header-label-badge">
                        <i class="fa-solid fa-comments"></i> Communication
                    </div>
                    <h1 class="premium-header-title">Mentor Chat</h1>
                    <p class="premium-header-subtitle">Chat is only available for active projects.</p>
                </div>
                <div class="premium-glass-card" style="text-align: center; padding: 60px 20px;">
                    <i class="fa-solid fa-ban" style="font-size: 48px; color: rgba(255,255,255,0.1); margin-bottom: 16px;"></i>
                    <h3 style="color: white; margin-bottom: 8px;">No Active Project</h3>
                    <p style="color: var(--text-muted);">You need an active (Pending or Approved) project to chat with a mentor.</p>
                </div>
            <?php endif; ?>

        <?php elseif ($view == 'my-projects'): ?>

            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge">
                    <i class="fa-solid fa-clipboard-check"></i> Status
                </div>
                <h1 class="premium-header-title">My Projects</h1>
                <p class="premium-header-subtitle">Track the approval status and progress of your project teams.</p>
            </div>

            <div style="display: flex; flex-direction: column; gap: 20px;">
                <?php if (count($all_projects) > 0): ?>
                    <?php foreach ($all_projects as $project):
                        $isOwner = ($project['student_id'] == $user_id);
                        
                        // Get team member count for this project
                        $stmtCount = $pdo->prepare("SELECT COUNT(*) as team_size FROM project_members WHERE project_id = ?");
                        $stmtCount->execute([$project['id']]);
                        $teamSize = $stmtCount->fetch()['team_size'] + 1; // +1 for the leader
                        ?>
                        <?php 
                        // Parse technologies
                        $techStack = json_decode($project['technologies'], true);
                        if (!is_array($techStack)) $techStack = ['Frontend' => [], 'Backend' => [], 'Database' => []];
                        
                        // Combined flat list for tags
                        $allTechs = array_merge($techStack['Frontend'] ?? [], $techStack['Backend'] ?? [], $techStack['Database'] ?? []);
                        
                        // Icon mapping
                        $projectIcon = 'fa-code';
                        if (stripos($project['project_type'], 'mobile') !== false) $projectIcon = 'fa-mobile-screen-button';
                        if (stripos($project['project_type'], 'ai') !== false || stripos($project['project_type'], 'ml') !== false) $projectIcon = 'fa-brain';
                        ?>
                        
                        <div class="premium-glass-card project-card-modern" style="padding: 0; position: relative; overflow: hidden; margin-bottom: 12px; border: 1px solid rgba(255,255,255,0.08);">
                            <!-- Status Banner Edge -->
                            <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: <?php echo $project['status'] == 'Approved' ? '#10B981' : ($project['status'] == 'Rejected' ? '#EF4444' : '#F59E0B'); ?>; z-index: 10;"></div>
                            
                            <!-- Card Hover Overlay -->
                            <div class="card-hover-overlay"></div>

                            <!-- Top Metadata Bar -->
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 24px; background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <div style="display: flex; gap: 20px; font-size: 11px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">
                                    <span><i class="fa-solid fa-hashtag" style="color: var(--primary); margin-right: 6px;"></i> <?php echo formatProjectNumber($project['id']); ?></span>
                                    <span><i class="fa-solid fa-user-tie" style="color: var(--primary); margin-right: 6px;"></i> Mentor: <span style="color: rgba(255,255,255,0.7);"><?php echo htmlspecialchars($project['mentor_name']); ?></span></span>
                                    <span><i class="fa-solid fa-users" style="color: var(--primary); margin-right: 6px;"></i> Team Size: <span style="color: rgba(255,255,255,0.7);"><?php echo $teamSize; ?></span></span>
                                </div>
                                <div style="display: flex; gap: 8px;">
                                    <span class="status-badge <?php echo $project['status'] == 'Approved' ? 'status-approved' : ($project['status'] == 'Rejected' ? 'status-rejected' : 'status-pending'); ?>" style="font-size: 10px; padding: 4px 12px; border-radius: 6px;">
                                        <?php echo strtoupper($project['status']); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Main Content Area -->
                            <div style="padding: 24px; display: grid; grid-template-columns: auto 1fr auto; gap: 30px; align-items: center; position: relative; z-index: 2;">
                                <!-- Project Icon -->
                                <div style="width: 64px; height: 64px; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 28px; color: var(--primary); box-shadow: inset 0 0 15px rgba(99, 102, 241, 0.1);">
                                    <i class="fa-solid <?php echo $projectIcon; ?>"></i>
                                </div>

                                <!-- Title and Tech Tags -->
                                <div style="min-width: 0;">
                                    <div style="margin-bottom: 12px;">
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <h3 style="margin: 0; font-size: 20px; color: white; font-weight: 800; letter-spacing: -0.5px;"><?php echo htmlspecialchars($project['project_title']); ?></h3>
                                            <?php if (!empty($project['seminar_name'])): ?>
                                                <span style="color: var(--primary); font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo htmlspecialchars($project['seminar_name']); ?> Seminar</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.4); font-size: 13px; font-weight: 500; margin-top: 6px;">
                                            <i class="fa-solid fa-code-branch" style="font-size: 12px;"></i>
                                            <span><?php echo $project['project_type']; ?></span>
                                        </div>
                                    </div>
                                    
                                    <!-- Tech Tags -->
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                        <?php if (empty($allTechs)): ?>
                                            <span style="font-size: 11px; color: rgba(255,255,255,0.2); font-style: italic;">No specific technologies listed</span>
                                        <?php else: ?>
                                            <?php foreach (array_slice($allTechs, 0, 5) as $tech): ?>
                                                <span class="tech-tag"><?php echo htmlspecialchars($tech); ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($allTechs) > 5): ?>
                                                <span style="font-size: 11px; color: rgba(255,255,255,0.3); align-self: center;">+<?php echo count($allTechs) - 5; ?> more</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Role and Action Area -->
                                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 16px;">
                                    <div style="text-align: right;">
                                        <div style="font-size: 10px; color: rgba(255,255,255,0.3); text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 4px;">My Role</div>
                                        <div style="padding: 6px 14px; border-radius: 8px; background: <?php echo $isOwner ? 'rgba(99, 102, 241, 0.15)' : 'rgba(255,255,255,0.05)'; ?>; color: <?php echo $isOwner ? 'var(--primary)' : 'rgba(255,255,255,0.6)'; ?>; border: 1px solid <?php echo $isOwner ? 'rgba(99, 102, 241, 0.3)' : 'rgba(255,255,255,0.1)'; ?>; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                                            <?php if ($isOwner): ?><i class="fa-solid fa-crown" style="font-size: 10px;"></i><?php endif; ?>
                                            <?php echo $isOwner ? 'TEAM LEADER' : 'TEAM MEMBER'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bottom Utility Bar -->
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-top: 1px solid rgba(255,255,255,0.05); background: rgba(0,0,0,0.1); position: relative; z-index: 2;">
                                <div style="color: rgba(255,255,255,0.3); font-size: 12px; font-weight: 500;">
                                    <i class="fa-regular fa-calendar-check" style="margin-right: 6px; color: var(--primary);"></i> 
                                    Registration: <span style="color: rgba(255,255,255,0.5);"><?php echo date('M d, Y', strtotime($project['created_at'])); ?></span>
                                </div>
                                
                                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                    <?php if ($isOwner && $project['status'] == 'Rejected'): ?>
                                        <a href="register_project.php?edit=<?php echo $project['id']; ?>" class="btn" style="background: rgba(239, 68, 68, 0.1); color: #EF4444; border: 1px solid rgba(239, 68, 68, 0.2); padding: 8px 16px; font-size: 13px; height: auto; border-radius: 10px; font-weight: 600; white-space: nowrap;">
                                            <i class="fa-solid fa-rotate-right" style="margin-right: 8px;"></i> Re-Submit
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($isOwner && ($project['status'] == 'Pending' || $project['status'] == 'Rejected')): ?>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to cancel and remove this project registration? This action cannot be undone.');" style="margin: 0; display: flex;">
                                            <input type="hidden" name="proj_id" value="<?php echo $project['id']; ?>">
                                            <button type="submit" name="delete_project" class="btn" style="background: rgba(239, 68, 68, 0.15); color: #F87171; border: 1px solid rgba(239, 68, 68, 0.3); padding: 10px 18px; font-size: 13px; height: auto; width: fit-content; border-radius: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; transition: all 0.2s; box-shadow: none;" onmouseover="this.style.background='rgba(239, 68, 68, 0.3)'; this.style.color='white';" onmouseout="this.style.background='rgba(239, 68, 68, 0.15)'; this.style.color='#F87171';">
                                                Cancel Registration <i class="fa-solid fa-trash-can" style="font-size: 12px;"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <a href="?view=project-details&project_id=<?php echo $project['id']; ?>" class="btn login-btn" style="padding: 10px 24px; font-size: 13px; height: auto; width: fit-content; border-radius: 10px; font-weight: 700; background: linear-gradient(135deg, var(--primary) 0%, #4F46E5 100%); display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap;">
                                        Open Workspace <i class="fa-solid fa-arrow-right" style="font-size: 12px;"></i>
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Large faint background icon as watermark -->
                            <div style="position: absolute; right: -20px; top: 10px; font-size: 120px; color: rgba(255,255,255,0.02); pointer-events: none; z-index: 1;">
                                <i class="fa-solid <?php echo $projectIcon; ?>"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="premium-glass-card" style="text-align: center; padding: 60px 20px;">
                        <i class="fa-solid fa-clipboard-list" style="font-size: 48px; color: rgba(255,255,255,0.1); margin-bottom: 16px;"></i>
                        <h3 style="color: white; margin-bottom: 8px;">No Projects Yet</h3>
                        <p style="color: var(--text-muted); margin-bottom: 24px;">You aren't a member of any project teams right now.</p>
                        <?php if (!$is_member): ?>
                        <a href="register_project.php" class="btn" style="display: inline-flex; width: auto; padding: 12px 24px; font-size: 14px; border-radius: 12px;">
                            <i class="fa-solid fa-plus" style="margin-right: 8px; font-size: 14px;"></i> Register New Project
                        </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($view == 'profile'): ?>
            <?php
            // Get active project details
            $active_proj = null;
            $active_team = [];
            foreach ($all_projects as $p) {
                if ($p['status'] !== 'Rejected') {
                    // Fetch owner name specifically
                    $stmtOwner = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                    $stmtOwner->execute([$p['student_id']]);
                    $p['owner_name'] = $stmtOwner->fetch()['name'];

                    $active_proj = $p;
                    // Get team
                    $stmt = $pdo->prepare("SELECT u.name, u.role, pm.student_id FROM project_members pm JOIN users u ON pm.student_id = u.id WHERE pm.project_id = ?");
                    $stmt->execute([$p['id']]);
                    $active_team = $stmt->fetchAll();
                    break;
                }
            }
            ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge">
                    <i class="fa-solid fa-user-circle"></i> Account
                </div>
                <h1 class="premium-header-title">My Profile</h1>
                <p class="premium-header-subtitle">View and manage your personal and project information.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 30px;">
                <!-- Left: Profile Info -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <div class="premium-glass-card" style="padding: 30px; text-align: center;">
                        <div style="width: 120px; height: 120px; border-radius: 50%; background: rgba(255,255,255,0.05); border: 4px solid rgba(255,255,255,0.1); margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: bold; color: var(--primary); overflow: hidden; position: relative;">
                            <?php if ($profile_image && file_exists($profile_image)): ?>
                                <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <span style="font-weight: 700; letter-spacing: 2px;">
                                <?php 
                                    $clean_name = explode('|', $user_name)[0];
                                    $name_parts = explode(' ', trim($clean_name));
                                    $initials = strtoupper(substr($name_parts[0], 0, 1));
                                    if (count($name_parts) > 1) {
                                        $initials .= strtoupper(substr(end($name_parts), 0, 1));
                                    } else {
                                        $initials .= strtoupper(substr($name_parts[0], 1, 1));
                                    }
                                    echo $initials;
                                ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h2 style="color: white; margin-bottom: 5px;"><?php echo htmlspecialchars(explode('|', $user_name)[0]); ?></h2>
                        <p style="color: var(--text-muted); margin-bottom: 24px;"><?php echo htmlspecialchars($enrollment_no); ?></p>
                        
                        <form method="POST" enctype="multipart/form-data" style="text-align: left; display: flex; flex-direction: column; gap: 10px;">
                            <label class="btn" style="width: 100%; padding: 10px; cursor: pointer; text-align: center; border-radius: 12px; display: block; margin: 0;">
                                <input type="file" name="profile_photo" hidden onchange="this.form.submit()">
                                <i class="fa-solid fa-camera" style="margin-right: 8px;"></i> Change Photo
                                <input type="hidden" name="update_profile_photo" value="1">
                            </label>
                            <?php if ($profile_image): ?>
                                <button type="submit" name="remove_profile_photo" style="background: none; border: none; color: rgba(239, 68, 68, 0.7); font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 5px; transition: color 0.3s;" onmouseover="this.style.color='#EF4444'" onmouseout="this.style.color='rgba(239, 68, 68, 0.7)'">
                                    <i class="fa-solid fa-trash-can"></i> Remove Profile Photo
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="premium-glass-card" style="padding: 24px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-id-card-clip" style="color: var(--primary);"></i> Edit Profile Information
                        </h3>
                        <form method="POST" style="display: flex; flex-direction: column; gap: 15px;">
                            <div>
                                <label style="color: var(--text-muted); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">Full Name</label>
                                <input type="text" class="form-input" value="<?php echo htmlspecialchars(explode('|', $user_name)[0]); ?>" readonly style="background: rgba(255,255,255,0.02); color: rgba(255,255,255,0.7); border-color: rgba(255,255,255,0.05); cursor: not-allowed;">
                            </div>
                            <div>
                                <label style="color: var(--text-muted); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">Email Address</label>
                                <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>" style="background: rgba(255,255,255,0.05); color: white; border-color: rgba(255,255,255,0.1);">
                            </div>
                            <div>
                                <label style="color: var(--text-muted); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">Enrollment Number</label>
                                <input type="text" class="form-input" value="<?php echo htmlspecialchars($enrollment_no); ?>" readonly style="background: rgba(255,255,255,0.02); color: rgba(255,255,255,0.7); border-color: rgba(255,255,255,0.05); cursor: not-allowed;">
                            </div>
                            <div>
                                <label style="color: var(--text-muted); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">Department</label>
                                <input type="text" class="form-input" value="<?php echo htmlspecialchars($user_dept); ?>" readonly style="background: rgba(255,255,255,0.02); color: rgba(255,255,255,0.7); border-color: rgba(255,255,255,0.05); cursor: not-allowed;">
                            </div>
                            <button type="submit" name="update_profile_info" class="btn" style="width: 100%; border-radius: 12px; margin-top: 5px;">Save Changes</button>
                        </form>
                    </div>
                </div>

                <!-- Right: Team & Project -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <!-- Project Info -->
                    <div class="premium-glass-card" style="padding: 24px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-briefcase" style="color: var(--accent);"></i> Active Project
                        </h3>
                        <?php if ($active_proj): ?>
                            <div style="background: rgba(255,255,255,0.03); padding: 20px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                                <h4 style="color: white; font-size: 18px; margin-bottom: 5px;"><?php echo htmlspecialchars($active_proj['project_title']); ?></h4>
                                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px; line-height: 1.5;"><?php echo htmlspecialchars(substr($active_proj['description'], 0, 150)) . '...'; ?></p>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                    <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                                        <small style="color: var(--text-muted); display: block; font-size: 10px;">MENTOR</small>
                                        <span style="color: white; font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($active_proj['mentor_name']); ?></span>
                                    </div>
                                    <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                                        <small style="color: var(--text-muted); display: block; font-size: 10px;">STATUS</small>
                                        <span class="status-badge <?php echo 'status-' . strtolower($active_proj['status']); ?>" style="font-size: 11px;"><?php echo $active_proj['status']; ?></span>
                                    </div>
                                </div>
                                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                                    <small style="color: var(--text-muted); display: block; font-size: 10px;">PROJECT TYPE</small>
                                    <span style="color: white; font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($active_proj['project_type'] ?? 'Standard'); ?></span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; border: 2px dashed rgba(255,255,255,0.05); border-radius: 16px;">
                                <p style="color: var(--text-muted);">No active projects found.</p>
                                <a href="register_project.php" style="color: var(--primary); text-decoration: none; font-weight: 600; font-size: 13px; margin-top: 10px; display: inline-block;">Register a new project <i class="fa-solid fa-plus"></i></a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Team Info -->
                    <div class="premium-glass-card" style="padding: 24px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-users" style="color: #10B981;"></i> Team Structure
                        </h3>
                        <?php if ($active_proj): ?>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(79, 70, 229, 0.05); border-radius: 12px; border: 1px solid rgba(79, 70, 229, 0.1);">
                                    <div style="width: 32px; height: 32px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px;"><?php echo substr($active_proj['owner_name'] ?? 'U', 0,1); ?></div>
                                    <div style="flex: 1;">
                                        <span style="color: white; font-weight: 600; font-size: 14px;"><?php echo htmlspecialchars($active_proj['owner_name'] ?? 'You'); ?></span>
                                        <span style="font-size: 10px; color: var(--primary); background: rgba(79, 70, 229, 0.1); padding: 2px 6px; border-radius: 4px; margin-left: 5px; font-weight: 700; text-transform: uppercase;">Leader</span>
                                    </div>
                                </div>
                                <?php foreach ($active_team as $tm): ?>
                                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,0.03); border-radius: 12px;">
                                        <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.1); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px;"><?php echo substr($tm['name'], 0,1); ?></div>
                                        <div style="flex: 1;">
                                            <span style="color: white; font-weight: 500; font-size: 14px;"><?php echo htmlspecialchars($tm['name']); ?></span>
                                            <span style="font-size: 10px; color: var(--text-muted); margin-left: 5px; text-transform: uppercase;">Member</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; color: var(--text-muted); padding: 20px;">No team information available.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($view == 'settings'): ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge">
                    <i class="fa-solid fa-gear"></i> Preferences
                </div>
                <h1 class="premium-header-title">Settings</h1>
                <p class="premium-header-subtitle">Customize your notifications and update account security.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <!-- Account Settings -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <div class="premium-glass-card" style="padding: 24px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-lock" style="color: var(--primary);"></i> Security & Password
                        </h3>
                        <form method="POST">
                            <div class="form-group">
                                <label style="font-size: 12px; color: var(--text-muted);">Current Password</label>
                            <div class="input-container">
                                <input type="password" name="current_password" class="form-input" required style="background: rgba(255,255,255,0.05); color: white; border-color: rgba(255,255,255,0.1); width: 100%;">
                                <div class="toggle-password" onclick="togglePasswordVisibility(this)">
                                    <i class="fa-regular fa-eye-slash"></i>
                                    <i class="fa-regular fa-eye"></i>
                                </div>
                            </div>
                            </div>
                            <div class="form-group">
                                <label style="font-size: 12px; color: var(--text-muted);">New Password</label>
                            <div class="input-container">
                                <input type="password" name="new_password" class="form-input" required style="background: rgba(255,255,255,0.05); color: white; border-color: rgba(255,255,255,0.1); width: 100%;">
                                <div class="toggle-password" onclick="togglePasswordVisibility(this)">
                                    <i class="fa-regular fa-eye-slash"></i>
                                    <i class="fa-regular fa-eye"></i>
                                </div>
                            </div>
                            </div>
                            <div class="form-group" style="margin-bottom: 24px;">
                                <label style="font-size: 12px; color: var(--text-muted);">Confirm New Password</label>
                            <div class="input-container">
                                <input type="password" name="confirm_password" class="form-input" required style="background: rgba(255,255,255,0.05); color: white; border-color: rgba(255,255,255,0.1); width: 100%;">
                                <div class="toggle-password" onclick="togglePasswordVisibility(this)">
                                    <i class="fa-regular fa-eye-slash"></i>
                                    <i class="fa-regular fa-eye"></i>
                                </div>
                            </div>
                            </div>
                            <button type="submit" name="change_password" class="btn" style="width: 100%; border-radius: 12px;">Update Password</button>
                        </form>
                    </div>
                </div>

                <!-- Preferences -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <div class="premium-glass-card" style="padding: 24px; border: 1px solid rgba(255,255,255,0.05);">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-bell" style="color: #6366F1;"></i> Notification Preferences
                        </h3>
                        <form method="POST">
                            <div style="display: flex; flex-direction: column; gap: 20px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                    <div>
                                        <div style="color: white; font-weight: 600; font-size: 14px;">Mentor Messages</div>
                                        <div style="color: rgba(255,255,255,0.5); font-size: 12px;">Receive alerts when a mentor sends a message.</div>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="notif_mentor_msg" <?php echo (isset($user_details['notif_mentor_msg']) && $user_details['notif_mentor_msg']) ? 'checked' : ''; ?>>
                                        <span class="slider round"></span>
                                    </label>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <div style="color: white; font-weight: 600; font-size: 14px;">Project Approval</div>
                                        <div style="color: rgba(255,255,255,0.5); font-size: 12px;">Get notified when your project status changes.</div>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="notif_project_approved" <?php echo (isset($user_details['notif_project_approved']) && $user_details['notif_project_approved']) ? 'checked' : ''; ?>>
                                        <span class="slider round"></span>
                                    </label>
                                </div>
                                <button type="submit" name="update_notifications" class="btn" style="width: 100%; border-radius: 12px; margin-top: 10px; background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%);">Save Preferences</button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Mentor Details Modal -->
    <div id="mentorModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box;">
        <div class="premium-glass-card" style="width: 100%; max-width: 500px; margin: auto; padding: 36px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); display: flex; flex-direction: column; box-sizing: border-box; border-radius: 20px; position: relative;">
            
            <button onclick="closeMentorModal()" style="position: absolute; top: 24px; right: 24px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: rgba(255,255,255,0.6); width: 32px; height: 32px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;"><i class="fa-solid fa-xmark"></i></button>
            
            <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 30px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 24px;">
                <div id="m-initial" style="width: 72px; height: 72px; background: linear-gradient(135deg, var(--primary), var(--accent)); color: white; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 800; flex-shrink: 0; box-shadow: 0 8px 16px rgba(0,0,0,0.2);"></div>
                <div>
                    <h2 id="m-name" style="color: white; margin: 0 0 6px 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;"></h2>
                    <div id="m-dept" style="display: inline-block; background: rgba(99, 102, 241, 0.1); padding: 5px 14px; border-radius: 10px; color: #818CF8; font-size: 11px; font-weight: 800; border: 1px solid rgba(99, 102, 241, 0.2); text-transform: uppercase; letter-spacing: 0.8px;"></div>
                </div>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 24px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div style="background: rgba(255,255,255,0.02); padding: 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                        <small style="color: rgba(255,255,255,0.5); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; font-weight: 600;">Designation</small>
                        <div id="m-desg" style="color: white; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-user-tie" style="color: #6366F1; font-size: 14px;"></i>
                            <span id="m-desg-text"></span>
                        </div>
                    </div>
                    <div style="background: rgba(255,255,255,0.02); padding: 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                        <small style="color: rgba(255,255,255,0.5); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; font-weight: 600;">Qualification</small>
                        <div id="m-qual" style="color: white; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-graduation-cap" style="color: #F59E0B; font-size: 14px;"></i>
                            <span id="m-qual-text"></span>
                        </div>
                    </div>
                </div>

                <div style="background: rgba(255,255,255,0.02); padding: 20px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                    <small style="color: rgba(255,255,255,0.4); display: flex; align-items: center; gap: 8px; font-size: 10px; text-transform: uppercase; letter-spacing: 1.2px; margin-bottom: 12px; font-weight: 700;">
                        <i class="fa-solid fa-flask" style="color: #6366F1; font-size: 12px;"></i> Research Expertise
                    </small>
                    <div id="m-research" style="color: rgba(255,255,255,0.85); font-size: 14px; line-height: 1.6; font-weight: 500;"></div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <small style="color: rgba(255,255,255,0.4); font-size: 10px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 700;">Email Address</small>
                    <a id="m-email" href="" class="btn" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); color: white; height: 50px; border-radius: 12px; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: flex-start; gap: 14px; padding: 0 20px; text-decoration: none; transition: all 0.2s; box-shadow: none;">
                        <i class="fa-solid fa-envelope" style="color: #6366F1;"></i> <span id="m-email-text"></span>
                    </a>
                </div>

                <div style="margin-top: 8px; padding-top: 24px; border-top: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 10px; background: rgba(16, 185, 129, 0.1); padding: 8px 16px; border-radius: 20px; border: 1px solid rgba(16, 185, 129, 0.2);">
                        <div style="width: 8px; height: 8px; background: #10B981; border-radius: 50%;"></div>
                        <span id="m-status" style="color: #10B981; font-weight: 600; font-size: 12px; letter-spacing: 0.5px;">Available</span>
                    </div>
                    <?php if (!$has_active_project): ?>
                        <a href="register_project.php" id="m-req-btn" class="btn" style="width: auto; height: 42px; padding: 0 24px; border-radius: 12px; font-size: 13px; font-weight: 600; background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%); border: none; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);">Request Mentor</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <style>
        @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes modalSlideUp { from { transform: translateY(30px) scale(0.95); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }
        .modal-animate-in { animation: modalFadeIn 0.3s ease-out forwards; }
        .modal-content-animate { animation: modalSlideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .modal-close-btn:hover { background: rgba(255,255,255,0.2) !important; transform: rotate(90deg); }
    </style>

    <!-- Dropdown Toggle Logic -->
    <script>
        function toggleDropdown(id) {
            const dropdown = document.getElementById(id);
            
            // Close all other dropdowns first
            document.querySelectorAll('.glass-dropdown').forEach(d => {
                if (d.id !== id) {
                    d.classList.remove('show');
                }
            });

            // Toggle target dropdown
            dropdown.classList.toggle('show');
        }

        function showMentorDetails(mentor) {
            document.getElementById('m-initial').innerText = mentor.name.charAt(0);
            document.getElementById('m-name').innerText = mentor.name;
            document.getElementById('m-dept').innerText = mentor.department || 'Not Assigned';
            document.getElementById('m-desg-text').innerText = mentor.designation || 'N/A';
            document.getElementById('m-qual-text').innerText = mentor.qualification || 'N/A';
            document.getElementById('m-research').innerText = mentor.research_area || 'Not specified';
            document.getElementById('m-email-text').innerText = mentor.email;
            document.getElementById('m-email').href = 'mailto:' + mentor.email;
            
            const reqBtn = document.getElementById('m-req-btn');
            if (reqBtn) {
                reqBtn.href = 'register_project.php?mentor_id=' + mentor.id + '&department=' + encodeURIComponent(mentor.department);
            }
            
            const statusText = document.getElementById('m-status');
            if (mentor.active_projects >= 5) {
                statusText.innerText = 'Mentorship Full';
                statusText.style.color = '#EF4444';
                statusText.previousElementSibling.style.background = '#EF4444';
                statusText.previousElementSibling.style.boxShadow = 'none';
                statusText.parentElement.style.background = 'rgba(239, 68, 68, 0.1)';
                statusText.parentElement.style.borderColor = 'rgba(239, 68, 68, 0.2)';
            } else {
                statusText.innerText = 'Available';
                statusText.style.color = '#10B981';
                statusText.previousElementSibling.style.background = '#10B981';
                statusText.previousElementSibling.style.boxShadow = 'none';
                statusText.parentElement.style.background = 'rgba(16, 185, 129, 0.1)';
                statusText.parentElement.style.borderColor = 'rgba(16, 185, 129, 0.2)';
            }

            const modal = document.getElementById('mentorModal');
            const modalContent = modal.querySelector('.premium-glass-card');
            
            modal.style.display = 'flex';
            modal.classList.add('modal-animate-in');
            modalContent.classList.add('modal-content-animate');
        }

        function closeMentorModal() {
            const modal = document.getElementById('mentorModal');
            modal.style.display = 'none';
            modal.classList.remove('modal-animate-in');
            modal.querySelector('.premium-glass-card').classList.remove('modal-content-animate');
        }

        // Search logic for faculty
        if (document.getElementById('facultySearch')) {
            document.getElementById('facultySearch').addEventListener('input', function(e) {
                const search = e.target.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.innerText.toLowerCase();
                    row.style.display = text.includes(search) ? '' : 'none';
                });
            });
        }

        // Close dropdowns if clicking outside
        document.addEventListener('click', function(event) {
            const isClickInside = event.target.closest('.notification-wrapper') || event.target.closest('.profile-wrapper');
            if (!isClickInside) {
                document.querySelectorAll('.glass-dropdown').forEach(d => {
                    d.classList.remove('show');
                });
            }
            
            // Modal closing logic
            const modal = document.getElementById('mentorModal');
                if (event.target === modal) {
                closeMentorModal();
            }
        });

        function togglePasswordVisibility(btn) {
            const input = btn.parentElement.querySelector('input');
            const isPassword = input.getAttribute('type') === 'password';
            input.setAttribute('type', isPassword ? 'text' : 'password');
            btn.classList.toggle('active');
        }

        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
        }
    </script>
    <!-- CSS for Toggle Switch -->
    <style>
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(255,255,255,0.1); transition: .4s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--primary); }
        input:checked + .slider:before { transform: translateX(20px); }
    </style>
    <!-- FLASH MESSAGES (Relocated to bottom-right toast) -->
    <?php if($success_msg || $error_msg): ?>
        <?php 
            $is_success = !empty($success_msg);
            $bg = $is_success ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)';
            $border = $is_success ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)';
            $color = $is_success ? '#10B981' : '#F87171';
            $accent = $is_success ? '#10B981' : '#EF4444';
            $icon = $is_success ? 'fa-circle-check' : 'fa-circle-exclamation';
            $title = $is_success ? 'Success!' : 'System Alert';
            $message = $is_success ? $success_msg : $error_msg;
        ?>
        <div class="alert-toast" style="position: fixed; bottom: 30px; right: 30px; background: <?php echo $bg; ?>; backdrop-filter: blur(20px); border: 1px solid <?php echo $border; ?>; color: <?php echo $color; ?>; padding: 16px 24px; border-radius: 16px; font-weight: 700; display: flex; align-items: center; gap: 12px; z-index: 2000; box-shadow: 0 10px 40px rgba(0,0,0,0.3); animation: slideIn 0.5s cubic-bezier(0.16, 1, 0.3, 1); border-left: 5px solid <?php echo $accent; ?>;">
            <i class="fa-solid <?php echo $icon; ?>" style="font-size: 20px;"></i>
            <div>
                <div style="font-size: 14px;"><?php echo $title; ?></div>
                <div style="font-size: 11px; opacity: 0.8; font-weight: 500;"><?php echo htmlspecialchars($message); ?></div>
            </div>
        </div>

        <script>
            setTimeout(() => {
                const toast = document.querySelector('.alert-toast');
                if (toast) {
                    toast.style.transition = 'all 0.5s ease';
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(20px)';
                    setTimeout(() => toast.remove(), 500);
                }
            }, 4000);
        </script>
        
        <style>
            @keyframes slideIn { from { transform: translateX(100%) scale(0.9); opacity: 0; } to { transform: translateX(0) scale(1); opacity: 1; } }
        </style>
    <?php endif; ?>
    <script src="js/premium-select.js?v=<?php echo time(); ?>"></script>
</body>

</html>