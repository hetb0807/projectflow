<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/dashboard_ui.php';
require_once 'includes/project_helper.php';

requireLogin();

if ($_SESSION['role'] !== 'mentor') {
    redirectAfterLogin($_SESSION['role']);
}

$mentor_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

// Fetch mentor stats & info
$stmt = $pdo->prepare("SELECT department, max_teams, profile_image FROM users WHERE id = ?");
$stmt->execute([$mentor_id]);
$mentor_data = $stmt->fetch();
$mentor_dept = $mentor_data['department'] ?? 'Department';
$max_teams = $mentor_data['max_teams'] ?? 5;
$profile_image = $mentor_data['profile_image'] ?? null;

// Handle View Selection
$view = $_GET['view'] ?? 'dashboard';
$project_id = $_GET['project_id'] ?? null;

// Handle individual notification read status
if (isset($_GET['read_notif'])) {
    $notif_id = (int)$_GET['read_notif'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $mentor_id]);
}

// Security: Verify mentor has access to project
if ($project_id && !isUserInProject($pdo, $project_id, $mentor_id, 'mentor')) {
    header("Location: mentor_dashboard.php?error=AccessDenied");
    exit();
}

// Handle Actions (Project Approval, Submission Feedback, Chat, Reviews)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['project_id'])) {
        $proj_id = $_POST['project_id'];
        if ($_POST['action'] === 'approve') {
            // Check capacity before approving
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE mentor_id = ? AND status = 'Approved'");
            $stmt->execute([$mentor_id]);
            $current_approved = $stmt->fetchColumn();
            
            if ($current_approved < $max_teams) {
                $stmt = $pdo->prepare("UPDATE projects SET status = 'Approved' WHERE id = ? AND mentor_id = ?");
                $stmt->execute([$proj_id, $mentor_id]);
                
                // Notify student
                $p_stmt = $pdo->prepare("SELECT student_id, project_title FROM projects WHERE id = ?");
                $p_stmt->execute([$proj_id]);
                $p_data = $p_stmt->fetch();
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, type, message) VALUES (?, ?, 'approval', ?)");
                $stmt->execute([$p_data['student_id'], $proj_id, "Your project '{$p_data['project_title']}' has been Approved!"]);

                header("Location: mentor_dashboard.php?view=approved-projects&success=ProjectApproved");
            } else {
                header("Location: mentor_dashboard.php?view=team-requests&error=CapacityFull");
            }
        } else {
            $stmt = $pdo->prepare("UPDATE projects SET status = 'Rejected' WHERE id = ? AND mentor_id = ?");
            $stmt->execute([$proj_id, $mentor_id]);
            
            // Notify student
            $p_stmt = $pdo->prepare("SELECT student_id, project_title FROM projects WHERE id = ?");
            $p_stmt->execute([$proj_id]);
            $p_data = $p_stmt->fetch();
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, type, message) VALUES (?, ?, 'approval', ?)");
            $stmt->execute([$p_data['student_id'], $proj_id, "Your project request '{$p_data['project_title']}' was rejected."]);

            header("Location: mentor_dashboard.php?view=team-requests&success=ProjectRejected");
        }
        exit();
    }


    if (isset($_POST['send_msg'])) {
        $proj_id = $_POST['proj_id'];
        $msg = trim($_POST['message']);
        if (!empty($msg) && isUserInProject($pdo, $proj_id, $mentor_id, 'mentor')) {
            sendMessage($pdo, $proj_id, $mentor_id, $msg);
            
            // Notify students in the project
            $p_stmt = $pdo->prepare("SELECT student_id, project_title FROM projects WHERE id = ?");
            $p_stmt->execute([$proj_id]);
            $p_info = $p_stmt->fetch();
            
            // Notify leader
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, type, message) VALUES (?, ?, 'message', ?)");
            $stmt->execute([$p_info['student_id'], $proj_id, "New message from mentor in '{$p_info['project_title']}'"]);
            
            // Notify other members
            $m_stmt = $pdo->prepare("SELECT student_id FROM project_members WHERE project_id = ?");
            $m_stmt->execute([$proj_id]);
            $members = $m_stmt->fetchAll();
            foreach ($members as $m) {
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, project_id, type, message) VALUES (?, ?, 'message', ?)");
                $stmt->execute([$m['student_id'], $proj_id, "New message from mentor in '{$p_info['project_title']}'"]);
            }

            header("Location: mentor_dashboard.php?view=chat&project_id=$proj_id#chat");
            exit();
        }
    }

    if (isset($_POST['submit_review'])) {
        $proj_id = $_POST['proj_id'];
        $type = $_POST['review_type'];
        $status = $_POST['status'];
        $feedback = trim($_POST['feedback']);
        $score = $_POST['score'] ?? 'N/A';
        
        // Add score to feedback internally if needed, or keep separate if we had columns
        // For now, let's keep it simple as the user requested
        if ($score !== 'N/A') {
            $feedback .= "\n\n[SCORE: " . $score . "/10]";
        }

        // Check if review already exists
        $stmt = $pdo->prepare("SELECT id FROM project_reviews WHERE project_id = ? AND review_type = ?");
        $stmt->execute([$proj_id, $type]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE project_reviews SET status = ?, feedback = ? WHERE id = ?");
            $stmt->execute([$status, $feedback, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO project_reviews (project_id, mentor_id, review_type, status, feedback) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$proj_id, $mentor_id, $type, $status, $feedback]);
        }
        header("Location: mentor_dashboard.php?view=reviews&project_id=$proj_id&success=ReviewSaved");
        exit();
    }

    if (isset($_POST['update_profile_info'])) {
        $new_name = trim($_POST['full_name']);
        $new_email = trim($_POST['email']);
        
        if (!empty($new_name) && !empty($new_email)) {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $stmt->execute([$new_name, $new_email, $mentor_id]);
            $_SESSION['name'] = $new_name;
            $_SESSION['email'] = $new_email;
            header("Location: mentor_dashboard.php?view=profile&success=ProfileUpdated");
            exit();
        }
    }

    if (isset($_POST['update_profile_photo']) && isset($_FILES['profile_photo'])) {
        $file = $_FILES['profile_photo'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        
        if (in_array($file['type'], $allowed_types) && $file['size'] < 2000000) { 
            $upload_dir = 'uploads/profile_photos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $mentor_id . '_' . time() . '.' . $ext;
            $target_path = $upload_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                // Delete old photo
                if ($profile_image && file_exists($profile_image)) unlink($profile_image);
                
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$target_path, $mentor_id]);
                header("Location: mentor_dashboard.php?view=profile&success=PhotoUpdated");
                exit();
            }
        }
    }

    if (isset($_POST['remove_profile_photo'])) {
        if ($profile_image && file_exists($profile_image)) {
            unlink($profile_image);
        }
        $stmt = $pdo->prepare("UPDATE users SET profile_image = NULL WHERE id = ?");
        $stmt->execute([$mentor_id]);
        header("Location: mentor_dashboard.php?view=profile&success=PhotoRemoved");
        exit();
    }

    if (isset($_POST['change_password'])) {
        $curr = $_POST['current_password'];
        $new = $_POST['new_password'];
        $conf = $_POST['confirm_password'];

        if ($new === $conf) {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$mentor_id]);
            $user = $stmt->fetch();

            if (password_verify($curr, $user['password'])) {
                $hashed = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed, $mentor_id]);
                header("Location: mentor_dashboard.php?view=settings&success=PasswordChanged");
                exit();
            } else {
                header("Location: mentor_dashboard.php?view=settings&error=WrongPassword");
                exit();
            }
        } else {
            header("Location: mentor_dashboard.php?view=settings&error=PasswordMismatch");
            exit();
        }
    }

    if (isset($_POST['mark_all_read'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$mentor_id]);
        $ref = $_SERVER['HTTP_REFERER'] ?? 'mentor_dashboard.php';
        header("Location: $ref");
        exit();
    }
}

// Global Initials & Display Name
$display_name = explode('|', $user_name)[0];
$name_parts = explode(' ', trim($display_name));
$initials = strtoupper(substr($name_parts[0], 0, 1));
if (count($name_parts) > 1) {
    $initials .= strtoupper(substr(end($name_parts), 0, 1));
} else if (strlen($name_parts[0]) > 1) {
    $initials .= strtoupper(substr($name_parts[0], 1, 1));
}
if (empty($initials)) $initials = 'M';

// Data Fetching for Views
$stats = [];
if ($view === 'dashboard') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE mentor_id = ? AND status = 'Pending'");
    $stmt->execute([$mentor_id]);
    $stats['pending'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE mentor_id = ? AND status = 'Approved'");
    $stmt->execute([$mentor_id]);
    $stats['approved'] = $stmt->fetchColumn();

    $stats['available'] = max(0, $max_teams - $stats['approved']);

    // Unified Activity Feed (Messages + Submissions) - Aggregated by Project
    $stmt = $pdo->prepare("
        (SELECT 'message' as type, m.message as content, m.sent_at as timestamp, u.name as user_name, p.project_title, p.seminar_name, p.id as project_id, NULL as extra, lu.name as leader_name, lu.enrollment_no as leader_enrollment
         FROM messages m 
         JOIN users u ON m.user_id = u.id 
         JOIN projects p ON m.project_id = p.id 
         JOIN users lu ON p.student_id = lu.id
         WHERE p.mentor_id = ?)
        UNION ALL
        (SELECT 'submission' as type, s.submission_title as content, s.submitted_at as timestamp, u.name as user_name, p.project_title, p.seminar_name, p.id as project_id, s.status as extra, lu.name as leader_name, lu.enrollment_no as leader_enrollment
         FROM submissions s
         JOIN users u ON s.student_id = u.id
         JOIN projects p ON s.project_id = p.id
         JOIN users lu ON p.student_id = lu.id
         WHERE p.mentor_id = ?)
        ORDER BY timestamp DESC LIMIT 30
    ");
    $stmt->execute([$mentor_id, $mentor_id]);
    $raw_activities = $stmt->fetchAll();

    $activity_feed = [];
    foreach ($raw_activities as $act) {
        $pid = $act['project_id'];
        if (!isset($activity_feed[$pid])) {
            $activity_feed[$pid] = [
                'project_title' => $act['project_title'],
                'seminar_name' => $act['seminar_name'],
                'latest_timestamp' => $act['timestamp'],
                'latest_user' => $act['user_name'],
                'latest_content' => $act['content'],
                'latest_type' => $act['type'],
                'msg_count' => 0,
                'sub_count' => 0,
                'project_id' => $pid,
                'leader_name' => $act['leader_name'],
                'leader_enrollment' => $act['leader_enrollment']
            ];
        }
        if ($act['type'] === 'message') {
            $activity_feed[$pid]['msg_count']++;
        } else {
            $activity_feed[$pid]['sub_count']++;
        }
    }
    // Limit to top 6 active projects
    $activity_feed = array_slice($activity_feed, 0, 6, true);
}

if ($view === 'team-requests') {
    $stmt = $pdo->prepare("SELECT p.*, u.name as leader_name, u.email as leader_email, u.enrollment_no as leader_enrollment FROM projects p JOIN users u ON p.student_id = u.id WHERE p.mentor_id = ? AND p.status = 'Pending' ORDER BY p.created_at DESC");
    $stmt->execute([$mentor_id]);
    $pending_requests = $stmt->fetchAll();
}

if ($view === 'dashboard' || $view === 'approved-projects' || $view === 'chat' || $view === 'reviews') {
    $stmt = $pdo->prepare("
        SELECT p.*, u.name as leader_name, u.email as leader_email, u.enrollment_no as leader_enrollment 
        FROM projects p 
        JOIN users u ON p.student_id = u.id 
        INNER JOIN (
            SELECT MIN(id) as first_id
            FROM projects
            WHERE mentor_id = ? AND status = 'Approved'
            GROUP BY student_id
        ) as unique_p ON p.id = unique_p.first_id
        ORDER BY p.project_title ASC
    ");
    $stmt->execute([$mentor_id]);
    $approved_list = $stmt->fetchAll();

    if ($project_id) {
        $stmt = $pdo->prepare("SELECT p.*, u.name as owner_name, u.email as owner_email, u.enrollment_no as owner_enrollment FROM projects p JOIN users u ON p.student_id = u.id WHERE p.id = ?");
        $stmt->execute([$project_id]);
        $project_details = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT u.name, u.email, u.enrollment_no FROM project_members pm JOIN users u ON pm.student_id = u.id WHERE pm.project_id = ?");
        $stmt->execute([$project_id]);
        $team_members = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT s.*, u.name as student_name FROM submissions s JOIN users u ON s.student_id = u.id WHERE s.project_id = ? ORDER BY s.submitted_at DESC");
        $stmt->execute([$project_id]);
        $submissions = $stmt->fetchAll();
        
        // Fetch messages if in chat view
        if ($view === 'chat') {
            $messages = getMessages($pdo, $project_id);
            markMessagesAsRead($pdo, $project_id, $mentor_id);
        }
    }
}

// Fetch unread notifications
$unread_notifications = [];
$unread_count = 0;

try {
    $notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
    $notif_stmt->execute([$mentor_id]);
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
    <title>Mentor Dashboard - ProjectFlow</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--shadow-card);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 25px rgba(99, 102, 241, 0.1);
            border-color: rgba(99, 102, 241, 0.3) !important;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .btn-approve {
            background: #DCFCE7;
            color: #166534;
        }

        .btn-reject {
            background: #FEE2E2;
            color: #991B1B;
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

        /* Activity Feed Styles */
        .activity-card:hover {
            background: rgba(255,255,255,0.05) !important;
            border-color: rgba(99, 102, 241, 0.2) !important;
            transform: translateY(-2px);
        }
        .activity-card:hover .chevron-hover {
            color: #818CF8 !important;
            transform: translateX(0px) !important;
        }

        /* Submission Review Styles */
        .submission-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.04);
            border-radius: 24px;
            padding: 24px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .submission-card:hover {
            background: rgba(255, 255, 255, 0.03);
            border-color: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
        }
        .review-area {
            display: grid;
            grid-template-columns: 1.2fr 180px 100px;
            gap: 15px;
            background: rgba(0, 0, 0, 0.2);
            padding: 15px;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.03);
            margin-top: 15px;
        }
        .status-pill-select {
            appearance: none;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: white;
            padding: 0 15px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            height: 48px;
            transition: 0.3s;
        }
        .status-pill-select:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(79, 70, 229, 0.05);
        }

    </style>
</head>

<body class="dashboard-body">
    <?php renderSidebar('mentor', $view); ?>


    <?php renderTopNavbar('Mentor Portal', $user_name, 'MENTOR PORTAL', $unread_count, $unread_notifications, $profile_image, $_SESSION['email'] ?? 'Mentor'); ?>


    <div class="content-area">


        <?php if ($view == 'dashboard'): ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge" style="background: rgba(79, 70, 229, 0.1); color: var(--primary); padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; width: fit-content; margin-bottom: 20px; border: 1px solid rgba(79, 70, 229, 0.2);">
                    <i class="fa-solid fa-gauge"></i> <span style="margin-left: 8px; text-transform: uppercase; letter-spacing: 1px;">DASHBOARD</span>
                </div>
                <h1 class="premium-header-title" style="font-size: 32px; font-weight: 800; color: white; margin-bottom: 10px;">Mentor Overview</h1>
                <p class="premium-header-subtitle" style="color: rgba(255,255,255,0.6); font-size: 15px;">Welcome back! Monitor your teams and recent project updates here.</p>
            </div>
            <div class="stats-grid">
                <div class="stat-card premium-glass-card" style="padding: 24px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.05);">
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #F59E0B; width: 50px; height: 50px; font-size: 20px;"><i class="fa-solid fa-clock"></i></div>
                    <div class="stat-info">
                        <h3 style="font-size:12px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;">Pending Requests</h3>
                        <p style="font-size:28px;font-weight:800;color:white;"><?php echo $stats['pending']; ?></p>
                    </div>
                </div>
                <div class="stat-card premium-glass-card" style="padding: 24px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.05);">
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10B981; width: 50px; height: 50px; font-size: 20px;"><i class="fa-solid fa-check-double"></i></div>
                    <div class="stat-info">
                        <h3 style="font-size:12px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;">Approved Teams</h3>
                        <p style="font-size:28px;font-weight:800;color:white;"><?php echo $stats['approved']; ?></p>
                    </div>
                </div>
                <div class="stat-card premium-glass-card" style="padding: 24px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.05);">
                    <div class="stat-icon" style="background: rgba(99, 102, 241, 0.2); color: #818CF8; width: 50px; height: 50px; font-size: 20px;"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-info">
                        <h3 style="font-size:12px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;">Team Slots</h3>
                        <p style="font-size:28px;font-weight:800;color:white;"><?php echo $stats['approved']; ?> <span style="font-size: 14px; color: rgba(255,255,255,0.3); font-weight: 500;">/ <?php echo $max_teams; ?></span></p>
                    </div>
                </div>
            </div>

            <div class="premium-glass-card" style="padding:30px; border-radius:24px; border: 1px solid rgba(255,255,255,0.08);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                    <h3 style="color: white; font-size: 20px;">Recent Activity</h3>
                    <a href="mentor_dashboard.php?view=approved-projects" style="color: var(--primary); text-decoration: none; font-size: 13px; font-weight: 600;">View All Work <i class="fa-solid fa-arrow-right" style="margin-left: 5px;"></i></a>
                </div>
                <?php if (!empty($activity_feed)): ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($activity_feed as $act): 
                            $total_actions = $act['msg_count'] + $act['sub_count'];
                            $is_multi = $total_actions > 1;
                            $main_color = $act['latest_type'] === 'message' ? 'var(--primary)' : '#10B981';
                            $target_view = $act['latest_type'] === 'message' ? 'chat' : 'approved-projects';
                        ?>
                            <div class="activity-card" style="padding: 20px; background: rgba(255,255,255,0.02); border-radius: 20px; border: 1px solid rgba(255,255,255,0.04); display: flex; gap: 20px; align-items: center; cursor: pointer; transition: all 0.3s;" onclick="location.href='mentor_dashboard.php?view=<?php echo $target_view; ?>&project_id=<?php echo $act['project_id']; ?>'">
                                <div class="project-mini-avatar" style="width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.2) 0%, rgba(99, 102, 241, 0.05) 100%); border: 1px solid rgba(99, 102, 241, 0.3); display: flex; align-items: center; justify-content: center; position: relative; flex-shrink: 0; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                                    <span style="color: rgba(255,255,255,0.7); font-size: 24px; display: flex; justify-content: center; align-items: center;">
                                        <i class="fa-solid fa-folder-open"></i>
                                    </span>
                                    <div style="position: absolute; bottom: -4px; right: -4px; width: 22px; height: 22px; border-radius: 7px; background: <?php echo $main_color; ?>; display: flex; align-items: center; justify-content: center; font-size: 10px; color: white; border: 2px solid #0f172a; box-shadow: 0 2px 5px rgba(0,0,0,0.5);">
                                        <i class="fa-solid <?php echo $act['latest_type'] === 'message' ? 'fa-comment' : 'fa-file-arrow-up'; ?>"></i>
                                    </div>
                                    <?php if ($is_multi): ?>
                                        <span style="position: absolute; top: -5px; left: -5px; background: #F59E0B; color: white; font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 8px; border: 2px solid #0f172a; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">+<?php echo $total_actions; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="flex: 1;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span style="color: white; font-weight: 700; font-size: 16px; letter-spacing: -0.2px;">[<?php echo formatProjectNumber($act['project_id']); ?>] <?php echo htmlspecialchars($act['project_title']); ?></span>
                                        </div>
                                        <small style="color: rgba(255,255,255,0.3); font-size: 11px; font-weight: 500;"><?php echo date('h:i A', strtotime($act['latest_timestamp'])); ?></small>
                                    </div>
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <span style="color: rgba(255,255,255,0.4); font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                                                <?php echo htmlspecialchars($act['leader_name']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div style="color: rgba(255,255,255,0.1); font-size: 18px; transform: translateX(-5px); transition: 0.3s;" class="chevron-hover">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px;">
                        <i class="fa-solid fa-bolt-lightning" style="font-size: 30px; color: rgba(255,255,255,0.05); margin-bottom: 15px; display: block;"></i>
                        <p style="color: rgba(255,255,255,0.3); font-size: 14px;">No recent project activity to show.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($view == 'team-requests'): ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge" style="background: rgba(79, 70, 229, 0.1); color: var(--primary); padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; width: fit-content; margin-bottom: 20px; border: 1px solid rgba(79, 70, 229, 0.2);">
                    <i class="fa-solid fa-user-plus"></i> <span style="margin-left: 8px; text-transform: uppercase; letter-spacing: 1px;">TEAM REQUESTS</span>
                </div>
                <h1 class="premium-header-title" style="font-size: 32px; font-weight: 800; color: white; margin-bottom: 10px;">Pending Requests</h1>
                <p class="premium-header-subtitle" style="color: rgba(255,255,255,0.6); font-size: 15px;">Manage incoming project requests from student teams.</p>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 24px;">
                <?php if (!empty($pending_requests)): ?>
                    <?php foreach ($pending_requests as $req): ?>
                        <div class="premium-glass-card" style="padding: 28px; border-radius: 24px; border: 1px solid rgba(255,255,255,0.08); display: flex; flex-direction: column; gap: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        <h3 style="color: white; font-size: 18px; line-height: 1.2; font-weight: 800; margin: 0;">[<?php echo formatProjectNumber($req['id']); ?>] <?php echo htmlspecialchars($req['project_title']); ?></h3>
                                        <?php if (!empty($req['seminar_name'])): ?>
                                            <span style="color: var(--primary); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;"><?php echo htmlspecialchars($req['seminar_name']); ?> SEMINAR</span>
                                        <?php endif; ?>
                                    </div>
                                <span class="status-badge status-pending" style="margin-left: 15px;">Pending</span>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; padding: 15px; background: rgba(0,0,0,0.15); border-radius: 16px; border: 1px solid rgba(255,255,255,0.03);">
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <small style="color: rgba(255,255,255,0.4); text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px;">Team Lead</small>
                                    <span style="color: white; font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($req['leader_name']); ?></span>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <small style="color: var(--primary); font-size: 10px; opacity: 0.8;"><?php echo htmlspecialchars($req['leader_enrollment']); ?></small>
                                        <span style="color: rgba(255,255,255,0.1); font-size: 10px;">•</span>
                                        <?php 
                                            $gen_email = $req['leader_email'] ?: strtolower(explode(' ', $req['leader_name'])[0]) . "@123";
                                        ?>
                                        <small style="color: rgba(255,255,255,0.4); font-size: 10px;"><?php echo htmlspecialchars($gen_email); ?></small>
                                    </div>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <small style="color: rgba(255,255,255,0.4); text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px;">Department</small>
                                    <span style="color: white; font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($req['department']); ?></span>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 4px; grid-column: 1 / -1;">
                                    <small style="color: rgba(255,255,255,0.4); text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px;">Technologies</small>
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 2px;">
                                        <?php 
                                        $tech_input = $req['technologies'] ?? '';
                                        $tech_list = [];
                                        
                                        $decoded = json_decode($tech_input, true);
                                        if (is_array($decoded)) {
                                            foreach ($decoded as $items) {
                                                if (is_array($items)) {
                                                    $tech_list = array_merge($tech_list, $items);
                                                }
                                            }
                                        } else {
                                            $tech_list = explode(',', $tech_input);
                                        }
                                        
                                        if (empty(array_filter($tech_list))) {
                                            echo '<span style="color: rgba(255,255,255,0.3); font-size: 13px;">Not Specified</span>';
                                        } else {
                                            foreach($tech_list as $tech): 
                                                if (empty(trim($tech))) continue;
                                        ?>
                                            <span style="background: rgba(99, 102, 241, 0.1); color: #818CF8; padding: 3px 10px; border-radius: 6px; font-size: 11px; border: 1px solid rgba(99, 102, 241, 0.2); font-weight: 500;">
                                                <?php echo htmlspecialchars(trim($tech)); ?>
                                            </span>
                                        <?php 
                                            endforeach;
                                        } 
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <div style="background: rgba(255,255,255,0.02); padding: 15px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.03);">
                                <small style="color: rgba(255,255,255,0.4); text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; display: block; margin-bottom: 8px;">Project Summary</small>
                                <p style="color: rgba(255,255,255,0.7); font-size: 13px; line-height: 1.6;">
                                    <?php echo htmlspecialchars(substr($req['description'], 0, 140)) . (strlen($req['description']) > 140 ? '...' : ''); ?>
                                </p>
                            </div>

                            <div style="display: flex; gap: 12px; margin-top: auto;">
                                <form method="POST" style="flex: 2; display: flex; gap: 10px;">
                                    <input type="hidden" name="project_id" value="<?php echo $req['id']; ?>">
                                    <button type="submit" name="action" value="approve" class="btn" style="padding: 14px; margin: 0; font-size: 13px; background: linear-gradient(135deg, #10B981 0%, #059669 100%); flex: 1; border-radius: 14px; color: white; display: flex; align-items: center; justify-content: center; gap: 8px;"><i class="fa-solid fa-check"></i> Approve</button>
                                    <button type="submit" name="action" value="reject" class="btn" style="padding: 14px; margin: 0; font-size: 13px; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2); color: #F87171; flex: 1; border-radius: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: none;" onmouseover="this.style.boxShadow='none';" onmouseout="this.style.boxShadow='none';"><i class="fa-solid fa-xmark"></i> Reject</button>
                                </form>
                                <a href="mentor_dashboard.php?view=approved-projects&project_id=<?php echo $req['id']; ?>" class="btn" style="padding: 14px; margin: 0; font-size: 13px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); color: white; width: 50px; min-width: 50px; border-radius: 14px;" title="View Full Details">
                                    <i class="fa-solid fa-chevron-right" style="margin: 0;"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 100px 0; background: rgba(0,0,0,0.15); border-radius: 32px; border: 2px dashed rgba(255,255,255,0.05);">
                        <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.02); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 25px;">
                            <i class="fa-solid fa-inbox" style="font-size: 32px; color: rgba(255,255,255,0.1);"></i>
                        </div>
                        <h3 style="color: white; font-size: 20px;">No Pending Requests</h3>
                        <p style="color: rgba(255,255,255,0.4); margin-top: 10px; max-width: 300px; margin-inline: auto;">New project requests from student teams will appear here for your approval.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($view == 'approved-projects'): ?>
            <?php if (!$project_id): ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge" style="background: rgba(79, 70, 229, 0.1); color: var(--primary); padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; width: fit-content; margin-bottom: 20px; border: 1px solid rgba(79, 70, 229, 0.2);">
                    <i class="fa-solid fa-list-check"></i> <span style="margin-left: 8px; text-transform: uppercase; letter-spacing: 1px;">APPROVED PROJECTS</span>
                </div>
                <h1 class="premium-header-title" style="font-size: 32px; font-weight: 800; color: white; margin-bottom: 10px;">Active Work</h1>
                <p class="premium-header-subtitle" style="color: rgba(255,255,255,0.6); font-size: 15px;">Review active projects, submissions, and give team feedback.</p>
            </div>
            <?php endif; ?>
            <?php if ($project_id && isset($project_details)): ?>
                <!-- Project Detail View (Same as before but refined) -->
                <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 30px;">
                    <div style="display: flex; flex-direction: column; gap: 30px;">
                        <div class="premium-glass-card" style="padding: 35px; border-radius: 28px; border: 1px solid rgba(255,255,255,0.08); background: linear-gradient(135deg, rgba(255,255,255,0.03), transparent) !important;">
                            <a href="mentor_dashboard.php?view=approved-projects" style="color: var(--primary); text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 25px;"><i class="fa-solid fa-arrow-left"></i> Back to Projects</a>
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 25px;">
                                <div style="flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                                        <?php if ($project_details['status'] === 'Approved'): ?>
                                            <span style="background: rgba(16, 185, 129, 0.1); color: #10B981; padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; border: 1px solid rgba(16, 185, 129, 0.2); text-transform: uppercase; letter-spacing: 1px;">Active Workspace</span>
                                        <?php else: ?>
                                            <span style="background: rgba(245, 158, 11, 0.1); color: #F59E0B; padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; border: 1px solid rgba(245, 158, 11, 0.2); text-transform: uppercase; letter-spacing: 1px;">Project Request</span>
                                        <?php endif; ?>
                                        <div style="display: flex; flex-direction: column; gap: 6px; width: 100%;">
                                            <div style="display: flex; justify-content: space-between; align-items: start; width: 100%;">
                                                <h2 style="font-size: 32px; color: white; font-weight: 800; line-height: 1.1; margin: 0;">[<?php echo formatProjectNumber($project_details['id']); ?>] <?php echo htmlspecialchars($project_details['project_title']); ?></h2>
                                                <?php if ($project_details['status'] === 'Pending'): ?>
                                                    <div style="display: flex; gap: 10px;">
                                                        <form method="POST">
                                                            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                                            <button type="submit" name="action" value="approve" class="btn" style="padding: 10px 20px; background: #10B981; margin: 0; font-size: 13px; border-radius: 10px; font-weight: 700; box-shadow: none; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">Approve Project</button>
                                                            <button type="submit" name="action" value="reject" class="btn" style="padding: 10px 20px; background: rgba(239, 68, 68, 0.1); color: #EF4444; border: 1px solid rgba(239, 68, 68, 0.2); margin: 0; font-size: 13px; border-radius: 10px; font-weight: 700; box-shadow: none; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">Reject</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($project_details['seminar_name'])): ?>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <span style="color: var(--primary); font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;"><?php echo htmlspecialchars($project_details['seminar_name']); ?> Seminar</span>
                                                    <span style="width: 4px; height: 4px; border-radius: 50%; background: rgba(255,255,255,0.2);"></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <p style="color: rgba(255,255,255,0.4); font-size: 14px; font-weight: 500;">Type: <span style="color: white;"><?php echo htmlspecialchars($project_details['project_type']); ?></span></p>
                                        <span style="color: rgba(255,255,255,0.2);">|</span>
                                        <p style="color: rgba(255,255,255,0.4); font-size: 14px; font-weight: 500;">Department: <span style="color: white;"><?php echo htmlspecialchars($project_details['department']); ?></span></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="color: rgba(255,255,255,0.7); line-height: 1.8; font-size: 15px; background: rgba(0,0,0,0.2); padding: 25px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 30px;">
                                <h4 style="color: rgba(255,255,255,0.3); font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 12px; font-weight: 700;">Project Objective</h4>
                                <?php echo nl2br(htmlspecialchars($project_details['description'])); ?>
                            </div>

                            <div style="padding-top:20px;">
                                <h4 style="margin-bottom:18px; color: rgba(255,255,255,0.3); font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700;">Team Composition</h4>
                                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                                    <div style="padding:15px 20px; background:rgba(79, 70, 229, 0.1); color:white; border-radius:18px; font-size:13px; font-weight:600; border: 1px solid rgba(79, 70, 229, 0.2); display: flex; align-items: center; gap: 15px; flex: 1; min-width: 280px;">
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 16px; color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3); flex-shrink: 0;"><i class="fa-solid fa-award"></i></div>
                                        <div style="display: flex; flex-direction: column; gap: 2px;">
                                            <span style="font-size: 15px;"><?php echo htmlspecialchars($project_details['owner_name']); ?></span>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <small style="font-size: 10px; color: var(--primary); font-weight: 700; text-transform: uppercase;">Lead</small>
                                                <small style="font-size: 11px; opacity: 0.7;"><?php echo htmlspecialchars($project_details['owner_enrollment']); ?></small>
                                            </div>
                                            <?php $owner_email = $project_details['owner_email'] ?: strtolower(explode(' ', $project_details['owner_name'])[0]) . "@123"; ?>
                                            <small style="font-size: 11px; opacity: 0.6; font-weight: 400;"><i class="fa-regular fa-envelope" style="margin-right: 4px;"></i><?php echo htmlspecialchars($owner_email); ?></small>
                                        </div>
                                    </div>
                                    <?php foreach ($team_members as $m): ?>
                                        <div style="padding:15px 20px; background:rgba(255,255,255,0.03); color:rgba(255,255,255,0.9); border-radius:18px; font-size:13px; border: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 15px; flex: 1; min-width: 280px; font-weight: 500;">
                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; font-size: 16px; color: rgba(255,255,255,0.4); border: 1px solid rgba(255,255,255,0.05); flex-shrink: 0;"><i class="fa-solid fa-user"></i></div>
                                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                                <span style="font-size: 15px;"><?php echo htmlspecialchars($m['name']); ?></span>
                                                <small style="font-size: 11px; opacity: 0.7;"><?php echo htmlspecialchars($m['enrollment_no']); ?></small>
                                                <?php $m_email = $m['email'] ?: strtolower(explode(' ', $m['name'])[0]) . "@123"; ?>
                                                <small style="font-size: 11px; opacity: 0.6; font-weight: 400;"><i class="fa-regular fa-envelope" style="margin-right: 4px;"></i><?php echo htmlspecialchars($m_email); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="premium-glass-card" id="submissions" style="padding: 35px; border-radius: 28px; border: 1px solid rgba(255,255,255,0.08);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                                <h3 style="color: white; font-size: 20px;">Work Submissions</h3>
                                <div style="padding: 6px 12px; background: rgba(255,255,255,0.05); border-radius: 8px; font-size: 12px; color: rgba(255,255,255,0.5); font-weight: 600;"><?php echo count($submissions); ?> Total</div>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 20px;">
                                <?php if (!empty($submissions)): ?>
                                    <?php foreach ($submissions as $sub): 
                                        $status_color = [
                                            'Pending' => '#F59E0B',
                                            'Approved' => '#10B981',
                                            'Rejected' => '#EF4444'
                                        ][$sub['status']] ?? '#6366F1';
                                    ?>
                                        <div class="submission-card">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                                <div style="display: flex; align-items: center; gap: 16px; min-width: 0;">
                                                    <a href="<?php echo htmlspecialchars($sub['file_path']); ?>" target="_blank" style="width: 48px; height: 48px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 14px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 20px; text-decoration: none; transition: 0.3s;" onmouseover="this.style.background='rgba(79, 70, 229, 0.1)'; this.style.borderColor='rgba(79, 70, 229, 0.2)';">
                                                        <i class="fa-solid fa-file-pdf"></i>
                                                    </a>
                                                    <div style="min-width: 0;">
                                                        <a href="<?php echo htmlspecialchars($sub['file_path']); ?>" target="_blank" style="color: white; font-size: 16px; font-weight: 700; margin: 0; text-decoration: none; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($sub['submission_title']); ?></a>
                                                        <small style="color: rgba(255,255,255,0.4); font-size: 12px; font-weight: 500;">
                                                            Submitted by <strong style="color: rgba(255,255,255,0.7);"><?php echo htmlspecialchars($sub['student_name']); ?></strong> • <?php echo date('M d, Y', strtotime($sub['submitted_at'])); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                                <a href="<?php echo htmlspecialchars($sub['file_path']); ?>" target="_blank" style="background: rgba(79, 70, 229, 0.1); border: 1px solid rgba(79, 70, 229, 0.2); color: #818CF8; padding: 10px 18px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 8px; transition: 0.3s;" onmouseover="this.style.background='rgba(79, 70, 229, 0.2)';" onmouseout="this.style.background='rgba(79, 70, 229, 0.1)';">
                                                    <i class="fa-solid fa-up-right-from-square" style="font-size: 11px;"></i> Open Work
                                                </a>
                                            </div>

                                            <div style="background: rgba(255,255,255,0.02); padding: 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.03); display: flex; justify-content: space-between; align-items: center;">
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <div style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $status_color; ?>;"></div>
                                                    <span style="font-size: 13px; font-weight: 600; color: white;">Status: <?php echo $sub['status']; ?></span>
                                                </div>
                                                <?php if (!empty($sub['mentor_comment'])): ?>
                                                    <span style="color: rgba(255,255,255,0.4); font-size: 12px; font-style: italic;">"<?php echo htmlspecialchars($sub['mentor_comment']); ?>"</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 50px; background: rgba(0,0,0,0.15); border-radius: 20px; border: 2px dashed rgba(255,255,255,0.05);">
                                        <i class="fa-solid fa-cloud-arrow-up" style="font-size: 30px; color: rgba(255,255,255,0.05); margin-bottom: 15px; display: block;"></i>
                                        <p style="color: rgba(255,255,255,0.3); font-size: 14px;">Team hasn't uploaded any documents yet.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 30px;">
                        <div class="premium-glass-card" style="padding: 30px; border-radius: 28px; border: 1px solid rgba(255,255,255,0.08);">
                            <h4 style="color: white; margin-bottom: 20px; font-size: 16px; font-weight: 700;">Quick Actions</h4>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <a href="mentor_dashboard.php?view=chat&project_id=<?php echo $project_id; ?>" class="btn" style="width: 100%; margin: 0; font-size: 14px; padding: 16px; background: rgba(79, 70, 229, 0.05); border: 1px solid rgba(79, 70, 229, 0.3); border-radius: 14px; color: white; display: flex; align-items: center; justify-content: center; gap: 10px; text-decoration: none; box-shadow: none;"><i class="fa-solid fa-comments" style="color: var(--primary);"></i> Open Group Chat</a>
                                <a href="mentor_dashboard.php?view=reviews&project_id=<?php echo $project_id; ?>" class="btn" style="width: 100%; margin: 0; font-size: 14px; padding: 16px; background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 14px; color: white; display: flex; align-items: center; justify-content: center; gap: 10px; text-decoration: none; box-shadow: none;"><i class="fa-solid fa-file-signature" style="color: #F59E0B;"></i> Submit Review</a>
                            </div>

                            <div style="margin-top: 30px; background: rgba(0,0,0,0.2); border-radius: 20px; padding: 20px; border: 1px solid rgba(255,255,255,0.03);">
                                <h5 style="color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px;">Project Metrics</h5>
                                <div style="display: flex; flex-direction: column; gap: 12px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: rgba(255,255,255,0.6); font-size: 13px;">Total Submissions</span>
                                        <span style="color: white; font-weight: 700; font-size: 14px;"><?php echo count($submissions); ?></span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: rgba(255,255,255,0.6); font-size: 13px;">Team Size</span>
                                        <span style="color: white; font-weight: 700; font-size: 14px;"><?php echo count($team_members) + 1; ?> Members</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: rgba(255,255,255,0.6); font-size: 13px;">Days Active</span>
                                        <span style="color: white; font-weight: 700; font-size: 14px;"><?php 
                                            $diff = time() - strtotime($project_details['created_at']);
                                            echo max(1, floor($diff / (60 * 60 * 24)));
                                        ?> Days</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- List View (Refined) -->
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 24px;">
                    <?php if (!empty($approved_list)): ?>
                        <?php foreach ($approved_list as $proj): ?>
                             <div class="premium-glass-card" style="padding: 28px; border-radius: 24px; display: flex; flex-direction: column; gap: 20px; border: 1px solid rgba(255,255,255,0.08); transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); cursor: pointer; position: relative; overflow: hidden;" onclick="window.location.href='?view=approved-projects&project_id=<?php echo $proj['id']; ?>'">
                                <!-- Modern Hover Effect Card -->
                                <div style="position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--accent);"></div>
                                
                                <div style="display: flex; justify-content: space-between; align-items: start;">
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        <h3 style="color: white; font-size: 19px; font-weight: 800; line-height: 1.2; margin: 0;">[<?php echo formatProjectNumber($proj['id']); ?>] <?php echo htmlspecialchars($proj['project_title']); ?></h3>
                                        <?php if (!empty($proj['seminar_name'])): ?>
                                            <span style="color: var(--primary); font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo htmlspecialchars($proj['seminar_name']); ?> Seminar</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="width: 32px; height: 32px; border-radius: 10px; background: rgba(16, 185, 129, 0.1); color: #10B981; display: flex; align-items: center; justify-content: center; font-size: 14px;"><i class="fa-solid fa-check"></i></div>
                                </div>
                                
                                <div style="display: flex; flex-direction: column; gap: 12px; padding: 15px; background: rgba(0,0,0,0.15); border-radius: 16px; border: 1px solid rgba(255,255,255,0.03);">
                                    <div style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.6); font-size: 13px;">
                                        <div style="width: 24px; height: 24px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800;"><?php echo substr($proj['leader_name'], 0, 1); ?></div>
                                        <div style="display: flex; flex-direction: column;">
                                            <span style="color: white; font-weight: 700;">Team: <?php echo htmlspecialchars($proj['leader_name']); ?></span>
                                            <small style="color: var(--primary); font-size: 10px; font-weight: 800; letter-spacing: 0.5px; opacity: 0.8;"><?php echo htmlspecialchars($proj['leader_enrollment']); ?></small>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.6); font-size: 13px;">
                                        <div style="width: 24px; display: flex; justify-content: center;"><i class="fa-solid fa-layer-group" style="color: var(--primary);"></i></div>
                                        <span>Type: <strong style="color: white;"><?php echo htmlspecialchars($proj['project_type']); ?></strong></span>
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: auto;">
                                    <div style="display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.3); font-size: 12px;">
                                        <i class="fa-regular fa-calendar"></i>
                                        <?php echo date('M d, Y', strtotime($proj['created_at'])); ?>
                                    </div>
                                    <span style="color: var(--primary); font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 8px;">View details <i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1/-1; text-align: center; padding: 100px 0; background: rgba(0,0,0,0.15); border-radius: 32px; border: 2px dashed rgba(255,255,255,0.05);">
                            <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.02); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 25px;">
                                <i class="fa-solid fa-list-check" style="font-size: 32px; color: rgba(255,255,255,0.1);"></i>
                            </div>
                            <h3 style="color: white; font-size: 20px;">No Active Projects</h3>
                            <p style="color: rgba(255,255,255,0.4); margin-top: 10px;">Once you approve teams, they will appear here as active projects.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($view == 'chat'): ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge" style="background: rgba(79, 70, 229, 0.1); color: var(--primary); padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; width: fit-content; margin-bottom: 20px; border: 1px solid rgba(79, 70, 229, 0.2);">
                    <i class="fa-solid fa-comments"></i> <span style="margin-left: 8px; text-transform: uppercase; letter-spacing: 1px;">COMMUNICATIONS</span>
                </div>
                <h1 class="premium-header-title" style="font-size: 32px; font-weight: 800; color: white; margin-bottom: 10px;">Student Chat</h1>
                <p class="premium-header-subtitle" style="color: rgba(255,255,255,0.6); font-size: 15px;">Stay connected with your approved project teams.</p>
            </div>
            <div class="premium-glass-card" style="padding: 0; display: flex; flex-direction: row; height: 650px; overflow: hidden; border-radius: 24px; border: 1px solid rgba(255,255,255,0.08);">
                <!-- Project Sidebar -->
                <div style="width: 300px; border-right: 1px solid rgba(255,255,255,0.05); background: rgba(0,0,0,0.2); display: flex; flex-direction: column;">
                    <div style="padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <h4 style="color: white; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Teams</h4>
                    </div>
                    <div style="flex: 1; overflow-y: auto; padding: 10px;">
                        <?php foreach ($approved_list as $p): ?>
                            <a href="?view=chat&project_id=<?php echo $p['id']; ?>" style="display: flex; align-items: center; gap: 12px; padding: 15px; border-radius: 12px; text-decoration: none; transition: 0.3s; margin-bottom: 5px; background: <?php echo ($project_id == $p['id']) ? 'rgba(79, 70, 229, 0.1)' : 'transparent'; ?>; border: 1px solid <?php echo ($project_id == $p['id']) ? 'rgba(79, 70, 229, 0.2)' : 'transparent'; ?>;">
                                <div style="width: 38px; height: 38px; border-radius: 10px; background: <?php echo ($project_id == $p['id']) ? 'var(--primary)' : 'rgba(255,255,255,0.05)'; ?>; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px;"><?php echo strtoupper(substr($p['project_title'], 0, 1)); ?></div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="color: white; font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">[<?php echo formatProjectNumber($p['id']); ?>] <?php echo htmlspecialchars($p['project_title']); ?></div>
                                    <small style="color: rgba(255,255,255,0.4); font-size: 11px;"><?php echo htmlspecialchars($p['leader_name']); ?></small>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Chat Area -->
                <div style="flex: 1; display: flex; flex-direction: column; background: rgba(0,0,0,0.1);">
                    <?php if ($project_id): ?>
                        <div style="padding: 20px 30px; border-bottom: 1px solid rgba(255,255,255,0.05); background: rgba(255,255,255,0.02); display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <div style="width: 44px; height: 44px; background: rgba(79, 70, 229, 0.1); color: var(--primary); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                    <i class="fa-solid fa-comments"></i>
                                </div>
                                <div>
                                    <h3 style="color: white; font-size: 16px; font-weight: 800; margin: 0; letter-spacing: -0.3px;">Group Chat - [<?php echo formatProjectNumber($project_id); ?>]</h3>
                                    <small style="color: rgba(255,255,255,0.4); font-weight: 500;">Direct communication with the team</small>
                                </div>
                            </div>
                            <a href="?view=approved-projects&project_id=<?php echo $project_id; ?>" style="color: var(--primary); font-size: 13px; text-decoration: none; font-weight: 700; background: rgba(79, 70, 229, 0.05); padding: 8px 16px; border-radius: 10px; border: 1px solid rgba(79, 70, 229, 0.1);">View Project <i class="fa-solid fa-chevron-right" style="margin-left: 6px; font-size: 10px;"></i></a>
                        </div>
                        <div style="flex: 1; overflow-y: auto; padding: 30px; display: flex; flex-direction: column; gap: 20px;">
                            <?php if (empty($messages)): ?>
                                <div style="margin: auto; text-align: center;">
                                    <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.02); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                                        <i class="fa-solid fa-comments" style="color: rgba(255,255,255,0.1); font-size: 24px;"></i>
                                    </div>
                                    <p style="color: rgba(255,255,255,0.3); font-size: 14px;">No messages yet. Start the conversation!</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($messages as $msg): 
                                    $isMe = ($msg['user_id'] == $_SESSION['user_id']);
                                ?>
                                    <div style="max-width: 70%; align-self: <?php echo $isMe ? 'flex-end' : 'flex-start'; ?>">
                                        <div style="display: flex; flex-direction: column; align-items: <?php echo $isMe ? 'flex-end' : 'flex-start'; ?>;">
                                            <small style="margin-bottom: 6px; color: rgba(255,255,255,0.4); font-size: 11px; font-weight: 600;">
                                                <?php echo htmlspecialchars($msg['user_name']); ?> 
                                                <?php if ($msg['role'] == 'mentor'): ?>
                                                    <span style="color: var(--primary);">• Mentor</span>
                                                <?php else: ?>
                                                    <span style="color: var(--accent); opacity: 0.8; font-weight: 500;">• <?php echo htmlspecialchars($msg['enrollment_no']); ?></span>
                                                <?php endif; ?>
                                            </small>
                                            <div style="padding: 12px 18px; border-radius: 16px; font-size: 14px; line-height: 1.5; background: <?php echo $isMe ? 'var(--primary)' : 'rgba(255,255,255,0.08)'; ?>; color: white; border-bottom-right-radius: <?php echo $isMe ? '4px' : '16px'; ?>; border-bottom-left-radius: <?php echo $isMe ? '16px' : '4px'; ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                                                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                            </div>
                                            <div style="display: flex; align-items: center; justify-content: <?php echo $isMe ? 'flex-end' : 'flex-start'; ?>; gap: 8px; margin-top: 6px;">
                                                <small style="font-size: 10px; color: rgba(255,255,255,0.3);"><?php echo date('h:i A', strtotime($msg['sent_at'])); ?></small>
                                                <?php if ($isMe): ?>
                                                    <span style="font-size: 10px; font-weight: 700; color: <?php echo $msg['is_read'] ? '#10B981' : 'rgba(255,255,255,0.15)'; ?>; display: flex; align-items: center; gap: 4px;">
                                                        <?php if ($msg['is_read']): ?>
                                                            <i class="fa-solid fa-check-double" style="font-size: 9px;"></i> Seen
                                                        <?php else: ?>
                                                            <i class="fa-solid fa-check" style="font-size: 9px;"></i> Sent
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div style="padding: 25px 30px; background: rgba(0,0,0,0.2); border-top: 1px solid rgba(255,255,255,0.05);">
                            <form method="POST" style="display: flex; gap: 12px; align-items: center;">
                                <input type="hidden" name="proj_id" value="<?php echo $project_id; ?>">
                                <input type="text" name="message" class="form-input" style="background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.1); color: white; border-radius: 14px; padding: 15px;" placeholder="Type your message..." autocomplete="off" required>
                                <button type="submit" name="send_msg" class="chat-send-btn"><i class="fa-solid fa-paper-plane"></i></button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div style="margin: auto; text-align: center; max-width: 300px;">
                            <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.02); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 25px;">
                                <i class="fa-solid fa-comment-dots" style="color: rgba(255,255,255,0.1); font-size: 32px;"></i>
                            </div>
                            <h3 style="color: white; font-size: 18px; margin-bottom: 10px;">Your Workspace</h3>
                            <p style="color: rgba(255,255,255,0.4); font-size: 14px;">Select a project from the sidebar to start collaborating with the student team.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($view == 'reviews'): ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge" style="background: rgba(79, 70, 229, 0.1); color: var(--primary); padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; width: fit-content; margin-bottom: 20px; border: 1px solid rgba(79, 70, 229, 0.2);">
                    <i class="fa-solid fa-file-signature"></i> <span style="margin-left: 8px; text-transform: uppercase; letter-spacing: 1px;">EVALUATIONS</span>
                </div>
                <h1 class="premium-header-title" style="font-size: 32px; font-weight: 800; color: white; margin-bottom: 10px;">Project Reviews</h1>
                <p class="premium-header-subtitle" style="color: rgba(255,255,255,0.6); font-size: 15px;">Submit formal project evaluations and milestone checks.</p>
            </div>
            <?php if ($project_id && isset($project_details)): ?>
                <!-- Evaluation Header -->
                <div class="premium-glass-card" style="padding: 24px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.08); margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, rgba(79, 70, 229, 0.05), transparent) !important;">
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <div style="width: 54px; height: 54px; border-radius: 14px; background: rgba(79, 70, 229, 0.1); border: 1px solid rgba(79, 70, 229, 0.2); display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 24px;"><i class="fa-solid fa-file-shield"></i></div>
                        <div>
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <h2 style="color: white; font-size: 20px; font-weight: 800; margin: 0; line-height: 1.2;">[<?php echo formatProjectNumber($project_details['id']); ?>] <?php echo htmlspecialchars($project_details['project_title']); ?></h2>
                                <?php if (!empty($project_details['seminar_name'])): ?>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="color: var(--primary); font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo htmlspecialchars($project_details['seminar_name']); ?> Seminar</span>
                                        <?php if ($project_details['status'] === 'Pending'): ?>
                                            <span style="width: 4px; height: 4px; border-radius: 50%; background: rgba(255,255,255,0.2);"></span>
                                            <span style="color: #F59E0B; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;"><i class="fa-solid fa-triangle-exclamation"></i> Pending Approval</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <p style="color: rgba(255,255,255,0.4); font-size: 13px; font-weight: 500; margin: 0;">
                                    Team Lead: <strong style="color: white;"><?php echo htmlspecialchars($project_details['owner_name']); ?></strong> 
                                    <span style="color: var(--primary); font-size: 11px; margin-left: 8px;">(<?php echo htmlspecialchars($project_details['owner_enrollment']); ?>)</span>
                                </p>
                                <?php if ($project_details['status'] === 'Pending'): ?>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                        <button type="submit" name="action" value="approve" class="btn" style="padding: 6px 12px; margin: 0; font-size: 11px; border-radius: 6px; background: #10B981; box-shadow: none; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">Approve Now</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <a href="mentor_dashboard.php?view=reviews" class="action-btn" style="background: rgba(255,255,255,0.05); color: white; border: 1px solid rgba(255,255,255,0.1); padding: 10px 20px; font-size: 12px; border-radius: 12px; text-decoration: none;"><i class="fa-solid fa-arrow-left"></i> Back to All Projects</a>
                </div>

                <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 24px;">
                    <div class="premium-glass-card" style="padding: 35px; border-radius: 28px; background: linear-gradient(135deg, rgba(255,255,255,0.03), transparent) !important; border: 1px solid rgba(255,255,255,0.08);">
                        <?php if ($project_details['status'] === 'Approved'): ?>
                            <h3 style="color: white; font-size: 20px; margin-bottom: 25px; font-weight: 800; display: flex; align-items: center; gap: 12px;"><i class="fa-solid fa-pen-nib" style="color: var(--primary);"></i> Submit Formal Review</h3>
                            <form method="POST" style="display: flex; flex-direction: column; gap: 24px;">
                                <input type="hidden" name="submit_review" value="1">
                                <input type="hidden" name="proj_id" value="<?php echo $project_id; ?>">
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                                    <div class="form-group">
                                        <label style="color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 12px; display: block; font-weight: 700;">Evaluation Stage</label>
                                        <div class="select-wrapper">
                                            <select name="review_type" required>
                                                <option value="Review 1">Review 1 (Initial Stage)</option>
                                                <option value="Review 2">Review 2 (Mid-Term)</option>
                                                <option value="Final Review">Final Review (Completion)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label style="color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 12px; display: block; font-weight: 700;">Decision Status</label>
                                        <div class="select-wrapper">
                                            <select name="status" required>
                                                <option value="Completed">Completed / Passed</option>
                                                <option value="Approved">Approved with Minor Fixes</option>
                                                <option value="Needs Improvement">Needs Improvement</option>
                                                <option value="Pending">Still Under Review</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Simplified Feedback -->
                                <div style="display: flex; flex-direction: column; gap: 20px;">
                                    <div class="form-group">
                                        <label style="color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 12px; display: block; font-weight: 700;">Evaluation Feedback & Observations</label>
                                        <textarea name="feedback" class="form-input" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.08); color: white; padding: 20px; min-height: 180px; border-radius: 16px; font-size: 15px; line-height: 1.6;" placeholder="Provide technical feedback, strengths, and necessary improvements..." required></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label style="color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 10px; display: block; font-weight: 700;">Overall Performance Score (1-10)</label>
                                        <div style="display: flex; gap: 15px; align-items: center; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.03);">
                                            <input type="range" name="score" min="1" max="10" value="7" class="form-range" style="flex: 1; height: 6px; border-radius: 5px; background: rgba(255,255,255,0.1); appearance: none; cursor: pointer;" oninput="this.nextElementSibling.value = this.value">
                                            <output style="color: var(--primary); font-weight: 800; font-size: 20px; min-width: 30px; text-align: center;">7</output>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn" style="width: 100%; padding: 18px; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); border-radius: 16px; font-weight: 700; font-size: 16px; border: none; box-shadow: none; margin-top: 10px; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                                    <i class="fa-solid fa-check-double" style="margin-right: 8px;"></i> Finalize & Publish Assessment
                                </button>
                            </form>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px 20px;">
                                <div style="width: 60px; height: 60px; background: rgba(245, 158, 11, 0.1); color: #F59E0B; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 24px;">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                                <h3 style="color: white; font-size: 18px; font-weight: 700; margin-bottom: 10px;">Action Required</h3>
                                <p style="color: rgba(255,255,255,0.5); font-size: 14px; line-height: 1.6; max-width: 300px; margin: 0 auto;">Projects must be <strong>Approved</strong> before they can be evaluated. Please approve this team to unlock the review form.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 24px;">
                        <!-- Materials for Review -->
                        <div class="premium-glass-card" style="padding: 25px; border-radius: 24px; border: 1px solid rgba(16, 185, 129, 0.1);">
                            <h3 style="color: white; font-size: 15px; margin-bottom: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px;"><i class="fa-solid fa-folder-open" style="color: #10B981;"></i> Reference Materials</h3>
                            <div style="display: flex; flex-direction: column; gap: 12px; max-height: 300px; overflow-y: auto; padding-right: 5px;">
                                <?php if (!empty($submissions)): ?>
                                    <?php foreach ($submissions as $sub): ?>
                                        <div style="padding: 16px; background: rgba(255, 255, 255, 0.02); border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.04); display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                                            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                                                <div style="width: 36px; height: 36px; background: rgba(16, 185, 129, 0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #10B981; flex-shrink: 0;">
                                                    <i class="fa-solid fa-file-pdf" style="font-size: 16px;"></i>
                                                </div>
                                                <div style="min-width: 0;">
                                                    <p style="color: white; font-weight: 700; font-size: 13px; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($sub['submission_title']); ?></p>
                                                    <small style="color: rgba(255,255,255,0.3); font-size: 10px;"><?php echo date('M d, Y', strtotime($sub['submitted_at'])); ?></small>
                                                </div>
                                            </div>
                                            <a href="<?php echo htmlspecialchars($sub['file_path']); ?>" target="_blank" style="width: 34px; height: 34px; background: rgba(79, 70, 229, 0.1); border: 1px solid rgba(79, 70, 229, 0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--primary); transition: 0.3s;" onmouseover="this.style.background='rgba(79, 70, 229, 0.2)';" onmouseout="this.style.background='rgba(79, 70, 229, 0.1)';">
                                                <i class="fa-solid fa-up-right-from-square" style="font-size: 14px;"></i>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 20px;">
                                        <p style="color: rgba(255,255,255,0.2); font-size: 13px;">No work submitted for review yet.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="premium-glass-card" style="padding: 30px; border-radius: 28px;">
                            <h3 style="color: white; font-size: 18px; margin-bottom: 20px;">Previous Reviews</h3>
                            <div style="display: flex; flex-direction: column; gap: 15px;">
                                <?php 
                                    $stmt = $pdo->prepare("SELECT * FROM project_reviews WHERE project_id = ? ORDER BY reviewed_at DESC");
                                    $stmt->execute([$project_id]);
                                    $prev_reviews = $stmt->fetchAll();
                                    
                                    if (!empty($prev_reviews)):
                                        foreach ($prev_reviews as $rev):
                                ?>
                                    <div style="padding: 18px; background: rgba(255,255,255,0.02); border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                            <span style="color: white; font-weight: 700; font-size: 14px;"><?php echo $rev['review_type']; ?></span>
                                            <span style="font-size: 11px; padding: 4px 10px; border-radius: 8px; background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.5);"><?php echo date('M d, Y', strtotime($rev['reviewed_at'])); ?></span>
                                        </div>
                                        <div style="background: rgba(0,0,0,0.15); padding: 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.03);">
                                            <?php 
                                            // Handle both structured and unstructured feedback
                                            $raw_feedback = $rev['feedback'];
                                            // Replace legacy structured tags if they exist
                                            $formatted = preg_replace('/\[(TECHNICAL|DOCUMENTATION|PRESENTATION)\]/', '<strong style="color: var(--primary); display: block; margin-top: 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">$1 Evaluation</strong>', $raw_feedback);
                                            // Highlight score
                                            $formatted = preg_replace('/\[SCORE: (.*?)\/10\]/', '<div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.05); color: #10B981; font-weight: 800;">Score: $1/10</div>', $formatted);
                                            echo nl2br($formatted); 
                                            ?>
                                        </div>
                                        <div style="margin-top: 12px; display: flex; align-items: center; justify-content: space-between;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <div style="width: 8px; height: 8px; border-radius: 50%; background: <?php 
                                                    echo $rev['status'] == 'Completed' ? '#10B981' : ($rev['status'] == 'Needs Improvement' ? '#EF4444' : '#F59E0B');
                                                ?>;"></div>
                                                <span style="font-size: 12px; font-weight: 600; color: white;"><?php echo $rev['status']; ?></span>
                                            </div>
                                            <span style="color: rgba(255,255,255,0.3); font-size: 11px;">Ref ID: #R-<?php echo $rev['id']; ?></span>
                                        </div>
                                    </div>
                                <?php 
                                        endforeach;
                                    else:
                                ?>
                                    <p style="color: rgba(255,255,255,0.3); font-size: 13px; text-align: center; padding: 20px;">No evaluation history found.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="premium-header" style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end;">
                    <div style="flex: 1;">
                        <div class="premium-header-label-badge" style="background: rgba(16, 185, 129, 0.1); color: #10B981; border: 1px solid rgba(16, 185, 129, 0.2);">
                            <i class="fa-solid fa-check-double"></i> <span style="margin-left: 8px; text-transform: uppercase; letter-spacing: 1px;">Approved Work</span>
                        </div>
                        <h1 class="premium-header-title">My Guided Teams</h1>
                        <p class="premium-header-subtitle">Manage and evaluate teams that have been officially approved by you.</p>
                    </div>
                    <a href="export_teams.php" target="_blank" class="btn" style="width: auto; padding: 12px 24px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; border-radius: 12px; text-decoration: none; font-size: 13px; display: flex; align-items: center; gap: 8px; margin: 0; box-shadow: none; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.08)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.background='rgba(255,255,255,0.05)'; this.style.transform='translateY(0)';">
                        <i class="fa-solid fa-file-pdf"></i> Export Team Report
                    </a>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 24px;">
                    <?php if (!empty($approved_list)): ?>
                        <?php foreach ($approved_list as $p): ?>
                            <div class="premium-glass-card" style="padding: 28px; border-radius: 24px; border: 1px solid rgba(255,255,255,0.06);">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                                    <div style="display: flex; gap: 15px; align-items: start;">
                                        <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--primary); flex-shrink: 0;">
                                            <i class="fa-solid fa-folder-open"></i>
                                        </div>
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <h3 style="color: white; font-size: 18px; font-weight: 800; margin: 0;">[<?php echo formatProjectNumber($p['id']); ?>] <?php echo htmlspecialchars($p['project_title']); ?></h3>
                                                <span style="color: var(--primary); font-size: 12px; font-weight: 800; text-transform: uppercase;"><?php echo htmlspecialchars($p['seminar_name']); ?></span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span style="color: rgba(255,255,255,0.4); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Team Lead: <?php echo htmlspecialchars($p['leader_name']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="width: 32px; height: 32px; border-radius: 10px; background: rgba(79, 70, 229, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-file-signature"></i></div>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 10px; border-left: 2px solid rgba(255,255,255,0.05); padding-left: 15px; margin-bottom: 20px;">
                                    <small style="color: rgba(255,255,255,0.4); text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px;">Latest Phase</small>
                                    <span style="color: white; font-weight: 600; font-size: 13px;">
                                        <?php 
                                            $st = $pdo->prepare("SELECT review_type FROM project_reviews WHERE project_id = ? ORDER BY reviewed_at DESC LIMIT 1");
                                            $st->execute([$p['id']]);
                                            $last = $st->fetch();
                                            echo $last ? $last['review_type'] : 'Not Evaluated Yet';
                                        ?>
                                    </span>
                                </div>
                                <a href="?view=reviews&project_id=<?php echo $p['id']; ?>" class="btn" style="width: 100%; margin: 0; padding: 12px; font-size: 13px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); color: white; border-radius: 12px; text-decoration: none;">Start Evaluation</a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1/-1; text-align: center; padding: 80px 0;">
                            <p style="color: rgba(255,255,255,0.3);">Approve teams first to evaluate their projects.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


        <?php elseif ($view == 'profile'): ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge">
                    <i class="fa-solid fa-user-circle"></i> Account
                </div>
                <h1 class="premium-header-title">My Profile</h1>
                <p class="premium-header-subtitle">View and manage your professional profile and information.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 30px;">
                <!-- Left Column -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <!-- Profile summary -->
                    <div class="premium-glass-card" style="padding: 30px; text-align: center;">
                        <div style="width: 120px; height: 120px; border-radius: 50%; background: rgba(255,255,255,0.05); border: 4px solid rgba(255,255,255,0.1); margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: bold; color: var(--primary); overflow: hidden; position: relative;">
                            <?php if ($profile_image && file_exists($profile_image)): ?>
                                <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <span style="font-weight: 700; letter-spacing: 2px; color: var(--primary);"><?php echo $initials; ?></span>
                            <?php endif; ?>
                        </div>
                        <h2 style="color: white; margin-bottom: 5px; font-size: 20px; font-weight: 700;"><?php echo htmlspecialchars($display_name); ?></h2>
                        <p style="color: var(--text-muted); margin-bottom: 24px; font-size: 13px; font-weight: 600;"><?php echo htmlspecialchars($mentor_dept); ?> • MENTOR</p>
                        
                        <form method="POST" enctype="multipart/form-data" style="text-align: left; display: flex; flex-direction: column; gap: 10px;">
                            <label class="btn" style="width: 100%; padding: 12px; cursor: pointer; text-align: center; border-radius: 12px; display: block; margin: 0; font-weight: 700; font-size: 14px;">
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

                    <!-- Profile Info Form -->
                    <div class="premium-glass-card" style="padding: 24px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 700;">
                            <i class="fa-solid fa-id-card-clip" style="color: var(--primary);"></i> Edit Profile Information
                        </h3>
                        <form method="POST" style="display: flex; flex-direction: column; gap: 15px;">
                            <input type="hidden" name="update_profile_info" value="1">
                            <div>
                                <label style="color: var(--text-muted); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; font-weight: 700;">Full Name</label>
                                <input type="text" name="full_name" class="form-input" value="<?php echo htmlspecialchars($display_name); ?>" required style="background: rgba(255,255,255,0.05); color: white; border-color: rgba(255,255,255,0.1); border-radius: 12px;">
                            </div>
                            <div>
                                <label style="color: var(--text-muted); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; font-weight: 700;">Email Address</label>
                                <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>" required style="background: rgba(255,255,255,0.05); color: white; border-color: rgba(255,255,255,0.1); border-radius: 12px;">
                            </div>
                            <button type="submit" class="btn" style="width: 100%; border-radius: 12px; margin-top: 5px; font-weight: 700;">Save Changes</button>
                        </form>
                    </div>
                </div>

                <!-- Right Column -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <!-- Academic context -->
                    <div class="premium-glass-card" style="padding: 24px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 700;">
                            <i class="fa-solid fa-graduation-cap" style="color: var(--accent);"></i> Academic Assignment
                        </h3>
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <div style="padding: 16px; background: rgba(255,255,255,0.03); border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                                <small style="color: var(--text-muted); display: block; font-size: 10px; margin-bottom: 6px; font-weight: 700; text-transform: uppercase;">Primary Department</small>
                                <span style="color: white; font-weight: 600; font-size: 15px;"><?php echo htmlspecialchars($mentor_dept); ?></span>
                            </div>
                            <div style="padding: 16px; background: rgba(255,255,255,0.03); border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                                <small style="color: var(--text-muted); display: block; font-size: 10px; margin-bottom: 6px; font-weight: 700; text-transform: uppercase;">Team Capacity</small>
                                <span style="color: white; font-weight: 600; font-size: 15px;"><?php echo $max_teams; ?> Supervised Teams Max</span>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics cards -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <?php
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE mentor_id = ? AND status = 'Approved'");
                        $stmt->execute([$mentor_id]);
                        $total_active = $stmt->fetchColumn();

                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_reviews r JOIN projects p ON r.project_id = p.id WHERE p.mentor_id = ?");
                        $stmt->execute([$mentor_id]);
                        $total_reviews = $stmt->fetchColumn();
                        ?>
                        <div class="premium-glass-card" style="padding: 24px; text-align: center; border: 1px solid rgba(16, 185, 129, 0.1);">
                            <h4 style="color: #10B981; font-size: 28px; font-weight: 800; margin-bottom: 4px;"><?php echo $total_active; ?></h4>
                            <p style="color: rgba(255,255,255,0.4); font-size: 10px; font-weight: 700; text-transform: uppercase;">Active Teams</p>
                        </div>
                        <div class="premium-glass-card" style="padding: 24px; text-align: center; border: 1px solid rgba(79, 70, 229, 0.1);">
                            <h4 style="color: var(--primary); font-size: 28px; font-weight: 800; margin-bottom: 4px;"><?php echo $total_reviews; ?></h4>
                            <p style="color: rgba(255,255,255,0.4); font-size: 10px; font-weight: 700; text-transform: uppercase;">Total Reviews</p>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($view == 'settings'): ?>
            <div style="max-width: 800px; margin: 0 auto;">
                <div class="premium-header" style="margin-bottom: 40px; text-align: center;">
                    <div class="premium-header-label-badge" style="background: rgba(245, 158, 11, 0.1); color: #F59E0B; padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; width: fit-content; margin: 0 auto 20px; border: 1px solid rgba(245, 158, 11, 0.2);">
                        <i class="fa-solid fa-lock"></i> <span style="margin-left: 8px; text-transform: uppercase; letter-spacing: 1px;">PRIVACY & SECURITY</span>
                    </div>
                    <h1 class="premium-header-title" style="font-size: 32px; font-weight: 800; color: white; margin-bottom: 10px;">Security Vault</h1>
                    <p class="premium-header-subtitle" style="color: rgba(255,255,255,0.6); font-size: 15px;">Manage your account credentials and system preferences.</p>
                </div>

                <div class="premium-glass-card" style="padding: 40px; border: 1px solid rgba(255,255,255,0.08); background: linear-gradient(135deg, rgba(15, 23, 42, 0.6) 0%, rgba(15, 23, 42, 0.4) 100%) !important;">
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(79, 70, 229, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 20px;"><i class="fa-solid fa-key"></i></div>
                        <div>
                            <h3 style="color: white; font-size: 18px; font-weight: 700; margin: 0;">Change Password</h3>
                            <p style="color: rgba(255,255,255,0.4); font-size: 12px; margin-top: 2px;">Ensure your account uses a secure, unique password.</p>
                        </div>
                    </div>

                    <form method="POST" style="display: flex; flex-direction: column; gap: 24px;">
                        <input type="hidden" name="change_password" value="1">
                        <div>
                            <label style="color: rgba(255,255,255,0.4); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; font-weight: 700;">Current Password</label>
                            <input type="password" name="current_password" class="form-input" required style="background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255,255,255,0.1); color: white; padding: 18px 20px; border-radius: 14px; width: 100%; font-size: 15px;">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                            <div>
                                <label style="color: rgba(255,255,255,0.4); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; font-weight: 700;">New Password</label>
                                <input type="password" name="new_password" class="form-input" required style="background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255,255,255,0.1); color: white; padding: 18px 20px; border-radius: 14px; width: 100%; font-size: 15px;">
                            </div>
                            <div>
                                <label style="color: rgba(255,255,255,0.4); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; font-weight: 700;">Confirm Password</label>
                                <input type="password" name="confirm_password" class="form-input" required style="background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255,255,255,0.1); color: white; padding: 18px 20px; border-radius: 14px; width: 100%; font-size: 15px;">
                            </div>
                        </div>
                        <div style="margin-top: 10px;">
                            <button type="submit" class="btn" style="width: 100%; height: 60px; background: var(--primary); border: none; color: white; border-radius: 16px; font-weight: 800; font-size: 16px; letter-spacing: 0.5px; text-transform: uppercase; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 10px 20px rgba(79, 70, 229, 0.2); transition: 0.3s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                                <i class="fa-solid fa-circle-check"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="premium-glass-card" style="padding: 30px; margin-top: 30px; border: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(79, 70, 229, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 16px;"><i class="fa-solid fa-bell-slash"></i></div>
                        <div>
                            <h4 style="color: white; font-size: 14px; font-weight: 700; margin: 0;">Notification Delivery</h4>
                            <p style="color: rgba(255,255,255,0.3); font-size: 11px; margin-top: 2px;">Manage how you receive project updates.</p>
                        </div>
                    </div>
                    <div style="color: rgba(255,255,255,0.4); font-size: 11px; font-weight: 600; text-transform: uppercase; background: rgba(255,255,255,0.02); padding: 8px 16px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.05);">Controlled by System</div>
                </div>
            </div>

        <?php elseif ($view == 'faculty-pool'): ?>
            <?php
            // Fetch mentors and their project counts, matching Student Panel type but for Mentors
            // Also replacing Lecturer with Professor on-the-fly as requested
            $stmt = $pdo->query("SELECT u.id, u.name, u.email, u.department, 
                                REPLACE(u.designation, 'Lecturer', 'Assistant Professor') as designation, 
                                u.qualification, u.research_area, u.profile_image,
                                (SELECT COUNT(*) FROM projects WHERE mentor_id = u.id AND (status = 'Approved' OR status = 'Pending')) as total_projects,
                                (SELECT COUNT(*) FROM projects WHERE mentor_id = u.id AND status = 'Approved') as active_projects,
                                (SELECT COUNT(*) FROM projects WHERE mentor_id = u.id AND status = 'Pending') as pending_projects
                                FROM users u WHERE role = 'mentor' 
                                ORDER BY u.department ASC, u.name ASC");
            $faculty = $stmt->fetchAll();
            ?>
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge" style="background: rgba(79, 70, 229, 0.1); color: var(--primary); padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; width: fit-content; margin-bottom: 20px; border: 1px solid rgba(79, 70, 229, 0.2);">
                    <i class="fa-solid fa-graduation-cap"></i> <span style="margin-left: 8px; text-transform: uppercase; letter-spacing: 1px;">FACULTY</span>
                </div>
                <h1 class="premium-header-title" style="font-size: 32px; font-weight: 800; color: white; margin-bottom: 10px;">Faculty Pool</h1>
                <p class="premium-header-subtitle" style="color: rgba(255,255,255,0.6); font-size: 15px;">Connect with your colleagues across different departments.</p>
            </div>

            <div style="margin-bottom: 24px; display: flex; justify-content: flex-end;">
                <div style="position: relative; width: 100%; max-width: 500px;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #6366F1; font-size: 16px; opacity: 0.6;"></i>
                    <input type="text" id="facultySearch" placeholder="Search by mentor name, department, or email..." class="form-input" style="padding-left: 48px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); width: 100%; color: white; border-radius: 12px;">
                </div>
            </div>

            <div class="table-container premium-glass-card" style="padding: 0; border-radius: 24px; overflow: hidden; border: 1px solid rgba(255,255,255,0.08); background: rgba(30, 41, 59, 0.4);">
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <th style="padding: 20px 25px; color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Mentor Name</th>
                                <th style="padding: 20px 25px; color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Department</th>
                                <th style="padding: 20px 25px; color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Designation</th>
                                <th style="padding: 20px 25px; color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Active</th>
                                <th style="padding: 20px 25px; color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Pending</th>
                                <th style="padding: 20px 25px; color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Status</th>
                                <th style="padding: 20px 25px; color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($faculty as $mentor): 
                                $mentor_json = json_encode($mentor);
                                $isFull = $mentor['total_projects'] >= 5;
                                $isNearFull = $mentor['total_projects'] == 4;
                            ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: 0.3s; cursor: pointer;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'" onclick='showMentorDetails(<?php echo htmlspecialchars($mentor_json, ENT_QUOTES); ?>)'>
                                    <td style="padding: 18px 25px;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 38px; height: 38px; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--accent)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);">
                                                <?php echo substr($mentor['name'], 0, 1); ?>
                                            </div>
                                            <span style="color: white; font-weight: 600; font-size: 14px;"><?php echo htmlspecialchars($mentor['name']); ?></span>
                                        </div>
                                    </td>
                                    <td style="padding: 18px 25px;">
                                        <span style="background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.7); padding: 5px 12px; border-radius: 8px; font-size: 11px; font-weight: 600; border: 1px solid rgba(255,255,255,0.08); text-transform: uppercase; letter-spacing: 0.5px;">
                                            <?php echo htmlspecialchars($mentor['department'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 18px 25px; color: rgba(255,255,255,0.6); font-size: 13px; font-weight: 500;">
                                        <?php echo htmlspecialchars($mentor['designation'] ?? 'N/A'); ?>
                                    </td>
                                    <td style="padding: 18px 25px;">
                                        <div style="color: white; font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-check-circle" style="color: #10B981; font-size: 12px;"></i>
                                            <?php echo $mentor['active_projects']; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 18px 25px;">
                                        <div style="color: white; font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-clock" style="color: #F59E0B; font-size: 12px;"></i>
                                            <?php echo $mentor['pending_projects']; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 18px 25px;">
                                        <?php if ($isFull): ?>
                                            <span class="status-badge" style="background: rgba(239, 68, 68, 0.1); color: #EF4444; border: 1px solid rgba(239, 68, 68, 0.2); font-size: 10px; padding: 4px 10px;">Full Capacity</span>
                                        <?php elseif ($isNearFull): ?>
                                            <span class="status-badge" style="background: rgba(245, 158, 11, 0.1); color: #F59E0B; border: 1px solid rgba(245, 158, 11, 0.2); font-size: 10px; padding: 4px 10px;">Almost Full</span>
                                        <?php else: ?>
                                            <span class="status-badge" style="background: rgba(16, 185, 129, 0.1); color: #10B981; border: 1px solid rgba(16, 185, 129, 0.2); font-size: 10px; padding: 4px 10px;">Available</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 18px 25px;">
                                        <button class="btn" style="width: auto; padding: 8px 16px; font-size: 11px; margin: 0; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; color: white;">View Info</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>


    <!-- Mentor Details Modal -->
    <div id="mentorModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box;">
        <div class="premium-glass-card" style="width: 100%; max-width: 500px; margin: auto; padding: 36px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); display: flex; flex-direction: column; box-sizing: border-box; border-radius: 24px; position: relative; background: rgba(30, 41, 59, 0.4);">
            
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
                        <span id="m-status-text" style="color: #10B981; font-weight: 700; font-size: 12px; letter-spacing: 0.5px; text-transform: uppercase;">Available</span>
                    </div>
                    <div style="color: var(--primary-light); font-size: 13px; font-weight: 600;">Faculty Pool</div>
                </div>
            </div>
        </div>
    </div>

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
            document.getElementById('m-email').href = 'mailto:' + mentor.email;
            document.getElementById('m-email-text').innerText = mentor.email;
            
            const modal = document.getElementById('mentorModal');
            modal.style.display = 'flex';
        }

        function closeMentorModal() {
            document.getElementById('mentorModal').style.display = 'none';
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


    </script>
    <!-- FLASH MESSAGES (Relocated to bottom-right toast) -->
    <?php if(isset($_GET['success']) || isset($_GET['error'])): ?>
        <?php 
            $is_success = isset($_GET['success']);
            $bg = $is_success ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)';
            $border = $is_success ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)';
            $color = $is_success ? '#10B981' : '#F87171';
            $accent = $is_success ? '#10B981' : '#EF4444';
            $icon = $is_success ? 'fa-circle-check' : 'fa-circle-exclamation';
            $title = $is_success ? 'Success!' : 'System Alert';
            
            if ($is_success) {
                $msgs = [
                    'ProfileUpdated' => 'Profile information updated successfully!',
                    'PasswordChanged' => 'Password reset successfully!',
                    'FeedbackSaved' => 'Feedback has been saved successfully.',
                    'ProjectApproved' => 'Project has been approved and moved to active list.',
                    'ProjectRejected' => 'Project request has been rejected.',
                    'ReviewSaved' => 'Project review has been recorded.',
                    'PhotoUpdated' => 'Profile picture updated successfully!'
                ];
                $message = $msgs[$_GET['success']] ?? 'Administrative task completed successfully.';
            } else {
                $errors = [
                    'InvalidCurrentPassword' => 'Current password matches incorrectly.',
                    'PasswordMismatch' => 'New passwords do not match.',
                    'AccessDenied' => 'You do not have permission to access that project.',
                    'CapacityFull' => 'You have reached your maximum team capacity (5 teams).'
                ];
                $message = $errors[$_GET['error']] ?? 'An error occurred. Please try again.';
            }
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