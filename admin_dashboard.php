<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/dashboard_ui.php';
require_once 'includes/project_helper.php';

requireLogin();

if ($_SESSION['role'] !== 'admin') {
    redirectAfterLogin($_SESSION['role']);
}

$admin_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

// Fetch full admin details for profile/settings
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$admin_id]);
$admin_details = $stmt->fetch();
$profile_image = $admin_details['profile_image'] ?? null;
$admin_name = $user_name;
$admin_email = $admin_details['email'] ?? 'admin@projectflow.com';
$initials = getProfileInitials($admin_name);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_user'])) {
        $target_id = (int)$_POST['user_id'];
        if ($target_id !== $admin_id) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt->execute([$target_id])) {
                header("Location: admin_dashboard.php?view=users&success=UserDeleted");
                exit();
            }
        }
    }

    if (isset($_POST['delete_project'])) {
        $proj_id = (int)$_POST['project_id'];
        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
        if ($stmt->execute([$proj_id])) {
            header("Location: admin_dashboard.php?view=projects&success=ProjectDeleted");
            exit();
        }
    }

    if (isset($_POST['mark_all_read'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$admin_id]);
        header("Location: admin_dashboard.php");
        exit();
    }

    if (isset($_POST['update_profile_info'])) {
        $new_name = trim($_POST['full_name']);
        $new_email = trim($_POST['email']);
        try {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $stmt->execute([$new_name, $new_email, $admin_id]);
            $_SESSION['name'] = $new_name;
            header("Location: admin_dashboard.php?view=profile&success=ProfileUpdated");
            exit();
        } catch (PDOException $e) {
            header("Location: admin_dashboard.php?view=profile&error=EmailExists");
            exit();
        }
    }

    if (isset($_POST['update_profile_photo']) && isset($_FILES['profile_photo'])) {
        $file = $_FILES['profile_photo'];
        $upload_dir = 'uploads/profile_photos/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'admin_' . $admin_id . '_' . time() . '.' . $ext;
        $target_path = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            if ($profile_image && file_exists($profile_image)) unlink($profile_image);
            $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
            $stmt->execute([$target_path, $admin_id]);
            header("Location: admin_dashboard.php?view=profile&success=PhotoUpdated");
            exit();
        }
    }

    if (isset($_POST['change_password'])) {
        $current = $_POST['current_password'];
        $new_pass = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$admin_id]);
        $user_data = $stmt->fetch();

        if (!password_verify($current, $user_data['password'])) {
            header("Location: admin_dashboard.php?view=security&error=InvalidCurrentPassword");
            exit();
        } else if ($new_pass !== $confirm) {
            header("Location: admin_dashboard.php?view=security&error=PasswordMismatch");
            exit();
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $admin_id]);
            header("Location: admin_dashboard.php?view=security&success=PasswordChanged");
            exit();
        }
    }

    // Password reset via admin
    if (isset($_POST['reset_user_password'])) {
        $target_id = (int)$_POST['user_id'];
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$target_id]);
        $role = $stmt->fetchColumn();
        if ($role) {
            $default_pass = ($role == 'mentor') ? 'mentor123' : 'student@123';
            $hashed = password_hash($default_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $target_id]);
            header("Location: admin_dashboard.php?view=users&success=PasswordReset");
            exit();
        }
    }

    if (isset($_POST['toggle_user_status'])) {
        $target_id = (int)$_POST['user_id'];
        $new_status = (int)$_POST['new_status'];
        $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$new_status, $target_id]);
        header("Location: admin_dashboard.php?view=users&success=StatusUpdated");
        exit();
    }

    if (isset($_POST['add_faculty'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $dept = trim($_POST['department']);
        $desig = trim($_POST['designation'] ?? 'Assistant Professor');

        // Check if email already exists
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            header("Location: admin_dashboard.php?view=faculty&error=EmailExists");
            exit();
        }

        $pass = password_hash('mentor123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, role, password, department, designation) VALUES (?, ?, 'mentor', ?, ?, ?)");
        $stmt->execute([$name, $email, $pass, $dept, $desig]);
        header("Location: admin_dashboard.php?view=faculty&success=FacultyAdded");
        exit();
    }

    if (isset($_POST['update_admin_notifications'])) {
        $notif_project = isset($_POST['notif_new_project']) ? 1 : 0;
        $notif_user = isset($_POST['notif_new_user']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET notif_new_project = ?, notif_new_user = ? WHERE id = ?");
            $stmt->execute([$notif_project, $notif_user, $admin_id]);
        } catch (PDOException $e) {
            // Auto-create missing preference columns if they don't exist
            $pdo->exec("ALTER TABLE users ADD COLUMN notif_new_project TINYINT(1) DEFAULT 1, ADD COLUMN notif_new_user TINYINT(1) DEFAULT 1");
            $stmt = $pdo->prepare("UPDATE users SET notif_new_project = ?, notif_new_user = ? WHERE id = ?");
            $stmt->execute([$notif_project, $notif_user, $admin_id]);
        }
        
        header("Location: admin_dashboard.php?view=security&success=SettingsUpdated");
        exit();
    }
}

// Handle View Selection
$view = $_GET['view'] ?? 'dashboard';
if ($view == 'settings') $view = 'security'; // Alias

// Handle individual notification read status
if (isset($_GET['read_notif'])) {
    $notif_id = (int)$_GET['read_notif'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $admin_id]);
}

// System Stats
$user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$project_count = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$mentor_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'mentor'")->fetchColumn();
$student_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$active_teams = $pdo->query("SELECT COUNT(*) FROM projects WHERE status = 'Approved'")->fetchColumn();
$pending_approvals = $pdo->query("SELECT COUNT(*) FROM projects WHERE status = 'Pending'")->fetchColumn();

// View-specific Logic
if ($view == 'users') {
    $search = $_GET['search'] ?? '';
    $role_filter = $_GET['role'] ?? '';
    $query = "SELECT id, name, email, role, enrollment_no, department, is_active, created_at FROM users WHERE 1=1";
    $params = [];
    // Search by Enrollment No if provided
    if (!empty($search)) {
        $query .= " AND (name LIKE ? OR email LIKE ? OR enrollment_no LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if (!empty($role_filter)) {
        $query .= " AND role = ?";
        $params[] = $role_filter;
    }
    $query .= " ORDER BY role, name";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $all_users = $stmt->fetchAll();
} elseif ($view == 'projects') {
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $query = "SELECT p.*, s.name as student_name, m.name as mentor_name, 
              (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id = p.id) as member_count,
              (SELECT COUNT(*) FROM submissions sub WHERE sub.project_id = p.id) as sub_count
              FROM projects p 
              JOIN users s ON p.student_id = s.id 
              JOIN users m ON p.mentor_id = m.id 
              WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $query .= " AND (p.project_title LIKE ? OR s.name LIKE ? OR m.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if (!empty($status_filter)) {
        $query .= " AND p.status = ?";
        $params[] = $status_filter;
    }
    $query .= " ORDER BY p.created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $all_projects = $stmt->fetchAll();
} elseif ($view == 'faculty') {
    $all_faculty = $pdo->query("SELECT u.*, 
                                (SELECT COUNT(*) FROM projects p WHERE p.mentor_id = u.id AND p.status = 'Approved') as team_count 
                                FROM users u WHERE role = 'mentor' ORDER BY name")->fetchAll();
} elseif ($view == 'documents') {
    $all_documents = $pdo->query("SELECT s.*, p.project_title, u.name as student_name 
                                  FROM submissions s 
                                  JOIN projects p ON s.project_id = p.id 
                                  JOIN users u ON s.student_id = u.id 
                                  ORDER BY s.submitted_at DESC")->fetchAll();
} elseif ($view == 'activity') {
    $system_activity = $pdo->query("SELECT 'Project' as type, project_title as title, created_at as date, 'New Project Registration' as action FROM projects
                                    UNION ALL
                                    SELECT 'User' as type, name as title, created_at as date, 'New Account Created' as action FROM users
                                    UNION ALL
                                    SELECT 'Submission' as type, submission_title as title, submitted_at as date, 'New Document Upload' as action FROM submissions
                                    ORDER BY date DESC LIMIT 20")->fetchAll();
}

// Dashboard Recent Data
$recent_users = $pdo->query("SELECT name, role, created_at FROM users ORDER BY created_at DESC LIMIT 8")->fetchAll();
$recent_projects = $pdo->query("SELECT p.id, p.project_title, p.status, s.name as student_name, p.seminar_name 
                                FROM projects p 
                                JOIN users s ON p.student_id = s.id 
                                ORDER BY p.created_at DESC LIMIT 8")->fetchAll();

// Notifications
$notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$notif_stmt->execute([$admin_id]);
$notifications = $notif_stmt->fetchAll();
$unread_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_count->execute([$admin_id]);
$unread_count = $unread_count->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ProjectFlow</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard-body" style="min-height: 100vh; color: white;">
    <?php renderSidebar('admin', $view); ?>

    <?php renderTopNavbar('Admin Central', $admin_name, 'ADMIN PORTAL', $unread_count, $notifications, $profile_image, $admin_email); ?>


    <div class="content-area">



        <?php if ($view == 'dashboard'): ?>
            <!-- DASHBOARD OVERVIEW -->
            <div class="premium-header">
                <div class="premium-header-label-badge"><i class="fa-solid fa-chart-line"></i> Analytics Overview</div>
                <h1 class="premium-header-title">System Insights</h1>
                <p class="premium-header-subtitle">Real-time performance metrics and recent system activity.</p>
            </div>

            <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 50px;">
                <div class="premium-glass-card" style="position: relative; overflow: hidden; display: flex; align-items: center; gap: 20px; padding: 20px !important;">
                    <div style="width: 50px; height: 50px; border-radius: 14px; background: rgba(79, 70, 229, 0.1); border: 1px solid rgba(79, 70, 229, 0.2); display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--primary);">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px;">Students</div>
                        <div style="font-size: 24px; font-weight: 800; color: white;"><?php echo $student_count; ?></div>
                    </div>
                </div>

                <div class="premium-glass-card" style="position: relative; overflow: hidden; display: flex; align-items: center; gap: 20px; padding: 20px !important;">
                    <div style="width: 50px; height: 50px; border-radius: 14px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--accent);">
                        <i class="fa-solid fa-diagram-project"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px;">Projects</div>
                        <div style="font-size: 24px; font-weight: 800; color: white;"><?php echo $project_count; ?></div>
                    </div>
                </div>

                <div class="premium-glass-card" style="position: relative; overflow: hidden; display: flex; align-items: center; gap: 20px; padding: 20px !important;">
                    <div style="width: 50px; height: 50px; border-radius: 14px; background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.2); display: flex; align-items: center; justify-content: center; font-size: 20px; color: #38BDF8;">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px;">Mentors</div>
                        <div style="font-size: 24px; font-weight: 800; color: white;"><?php echo $mentor_count; ?></div>
                    </div>
                </div>

                <div class="premium-glass-card" style="position: relative; overflow: hidden; display: flex; align-items: center; gap: 20px; padding: 20px !important;">
                    <div style="width: 50px; height: 50px; border-radius: 14px; background: rgba(251, 146, 60, 0.1); border: 1px solid rgba(251, 146, 60, 0.2); display: flex; align-items: center; justify-content: center; font-size: 20px; color: #FB923C;">
                        <i class="fa-solid fa-users-viewfinder"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px;">Active Teams</div>
                        <div style="font-size: 24px; font-weight: 800; color: white;"><?php echo $active_teams; ?></div>
                    </div>
                </div>

                <div class="premium-glass-card" style="position: relative; overflow: hidden; display: flex; align-items: center; gap: 20px; padding: 20px !important;">
                    <div style="width: 50px; height: 50px; border-radius: 14px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); display: flex; align-items: center; justify-content: center; font-size: 20px; color: #F87171;">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px;">Pending</div>
                        <div style="font-size: 24px; font-weight: 800; color: white;"><?php echo $pending_approvals; ?></div>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">
                <div class="premium-glass-card" style="padding: 30px !important; background: rgba(13, 20, 36, 0.6) !important;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <h3 style="color: white; font-size: 18px; font-weight: 700;">Latest Projects</h3>
                        <a href="?view=projects" style="color: var(--accent); font-size: 12px; font-weight: 700; text-decoration: none; text-transform: uppercase; letter-spacing: 1px;">View Repository <i class="fa-solid fa-arrow-right" style="margin-left: 5px;"></i></a>
                    </div>
                    <div class="table-container" style="overflow-x: auto;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding-left: 20px;">Project</th>
                                    <th style="text-align: left;">Student</th>
                                    <th style="text-align: right; padding-right: 20px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_projects as $p): ?>
                                    <tr style="background: rgba(255,255,255,0.02);">
                                        <td style="padding: 15px 20px;">
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <div style="width: 34px; height: 34px; border-radius: 8px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); display: flex; align-items: center; justify-content: center; font-size: 14px; color: rgba(255,255,255,0.8); flex-shrink: 0;">
                                                    <i class="fa-solid fa-folder-open"></i>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 700; color: white; font-size: 14px;">[<?php echo formatProjectNumber($p['id']); ?>] <?php echo htmlspecialchars($p['project_title']); ?></div>
                                                    <div style="color: var(--primary); font-size: 10px; font-weight: 800; text-transform: uppercase; margin-top: 4px;"><?php echo htmlspecialchars($p['seminar_name'] ?? 'N/A'); ?> Seminar</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 500;"><?php echo htmlspecialchars($p['student_name']); ?></td>
                                        <td style="text-align: right; padding-right: 20px;">
                                            <span class="status-badge" style="background: <?php echo $p['status'] == 'Approved' ? 'rgba(16, 185, 129, 0.1)' : ($p['status'] == 'Rejected' ? 'rgba(239, 68, 68, 0.1)' : 'rgba(245, 158, 11, 0.1)'); ?>; color: <?php echo $p['status'] == 'Approved' ? '#10B981' : ($p['status'] == 'Rejected' ? '#F87171' : '#F59E0B'); ?>; font-size: 9px; font-weight: 800; text-transform: uppercase; padding: 4px 10px; border-radius: 6px; border: 1px solid currentColor;">
                                                <?php echo $p['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="premium-glass-card" style="padding: 30px !important;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <h3 style="color: white; font-size: 18px; font-weight: 700;">New Users</h3>
                        <a href="?view=users" style="color: var(--accent); font-size: 12px; font-weight: 700; text-decoration: none; text-transform: uppercase; letter-spacing: 1px;">Manage <i class="fa-solid fa-arrow-right" style="margin-left: 5px;"></i></a>
                    </div>
                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 20px;">
                        <?php foreach ($recent_users as $u): ?>
                            <li style="display: flex; align-items: center; gap: 15px;">
                                <div class="navbar-avatar" style="width: 38px; height: 38px; font-size: 12px; pointer-events: none;">
                                    <?php echo getProfileInitials($u['name']); ?>
                                </div>
                                <div style="flex: 1;">
                                    <div style="color: white; font-size: 13px; font-weight: 700;"><?php echo htmlspecialchars($u['name']); ?></div>
                                    <div style="display: flex; align-items: center; gap: 10px; margin-top: 3px;">
                                        <span class="role-badge role-<?php echo strtolower($u['role']); ?>" style="font-size: 9px;"><?php echo $u['role']; ?></span>
                                        <span style="color: rgba(255,255,255,0.4); font-size: 10px;"><?php echo date('M d', strtotime($u['created_at'])); ?></span>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

        <?php elseif ($view == 'users'): ?>
            <!-- USER MANAGEMENT -->
            <div class="premium-header" style="display: flex; justify-content: space-between; align-items: flex-end;">
                <div>
                    <div class="premium-header-label-badge"><i class="fa-solid fa-users"></i> Directory</div>
                    <h1 class="premium-header-title">User Management</h1>
                    <p class="premium-header-subtitle">Manage all system users, roles, and administrative access.</p>
                </div>
                <a href="admin_export.php?type=users" class="btn" style="height: 48px; padding: 0 25px; border-radius: 14px; font-size: 13px; font-weight: 750; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; white-space: nowrap; width: fit-content; flex-shrink: 0;">
                    <i class="fa-solid fa-file-csv" style="margin-right: 8px;"></i> EXPORT CSV
                </a>
            </div>

            <div class="premium-glass-card" style="padding: 24px !important; margin-bottom: 35px; border-radius: 24px !important;">
                <form method="GET" style="display: grid; grid-template-columns: 1fr 220px 160px; gap: 20px; align-items: center;">
                    <input type="hidden" name="view" value="users">
                    <div class="search-container-premium">
                        <i class="fa-solid fa-magnifying-glass" style="color: rgba(255,255,255,0.3); margin-left: 5px;"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, email, or enrollment #..." class="search-input-premium">
                    </div>
                    <div class="select-wrapper">
                        <select name="role" class="select-premium">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="mentor" <?php echo $role_filter == 'mentor' ? 'selected' : ''; ?>>Mentor</option>
                            <option value="student" <?php echo $role_filter == 'student' ? 'selected' : ''; ?>>Student</option>
                        </select>
                    </div>
                    <button type="submit" class="filter-btn-premium" style="height: 52px; width: 100%;">
                        <i class="fa-solid fa-magnifying-glass" style="margin-right: 8px;"></i> SEARCH
                    </button>
                    <?php if(!empty($search) || !empty($role_filter)): ?>
                        <div style="grid-column: 1 / -1; text-align: right; margin-top: -10px;">
                            <a href="?view=users" style="color: var(--text-muted); font-size: 13px; font-weight: 600; text-decoration: none;">Clear Filters</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="premium-glass-card" style="padding: 0 !important; overflow: hidden; margin-top: 35px;">
                <div class="table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>User Profile</th>
                                <th>Enrollment / Dept</th>
                                <th>System Role</th>
                                <th>Status</th>
                                <th style="text-align: right;">Management</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_users as $u): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <div class="navbar-avatar" style="width: 42px; height: 42px; font-size: 14px; box-shadow: 0 4px 10px rgba(0,0,0,0.2); pointer-events: none;">
                                                <?php echo getProfileInitials($u['name']); ?>
                                            </div>
                                            <div>
                                                <div style="color: white; font-weight: 700; font-size: 14px;"><?php echo htmlspecialchars($u['name']); ?></div>
                                                <div style="color: rgba(255,255,255,0.4); font-size: 11px;"><?php echo htmlspecialchars($u['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="color: white; font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($u['enrollment_no'] ?? 'N/A'); ?></div>
                                        <div style="color: rgba(255,255,255,0.4); font-size: 11px;"><?php echo htmlspecialchars($u['department'] ?? 'General'); ?></div>
                                    </td>
                                    <td><span class="status-badge" style="background: <?php echo $u['role'] == 'admin' ? 'rgba(239, 68, 68, 0.1)' : ($u['role'] == 'mentor' ? 'rgba(245, 158, 11, 0.1)' : 'rgba(16, 185, 129, 0.1)'); ?>; color: <?php echo $u['role'] == 'admin' ? '#F87171' : ($u['role'] == 'mentor' ? '#F59E0B' : '#10B981'); ?>; font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 5px 12px; border-radius: 8px; border: 1px solid currentColor;"><?php echo $u['role']; ?></span></td>
                                    <td>
                                        <?php if ($u['role'] !== 'admin'): ?>
                                            <form method="POST" style="margin: 0;">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo ($u['is_active'] ?? 1) ? 0 : 1; ?>">
                                                <button type="submit" name="toggle_user_status" class="status-badge" style="background: <?php echo ($u['is_active'] ?? 1) ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'; ?>; color: <?php echo ($u['is_active'] ?? 1) ? '#10B981' : '#F87171'; ?>; border: 1px solid currentColor; cursor: pointer; padding: 4px 10px; border-radius: 6px; font-size: 9px; font-weight: 800; text-transform: uppercase;">
                                                    <?php echo ($u['is_active'] ?? 1) ? 'ACTIVE' : 'DISABLED'; ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color: rgba(255,255,255,0.3); font-size: 10px; font-weight: 700; text-transform: uppercase;">SYSTEM ADMIN</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if ($u['id'] != $admin_id): ?>
                                            <form method="POST" onsubmit="return confirm('Reset this user\'s password back to the default system password?');" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <button type="submit" name="reset_user_password" title="Reset Password" style="background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); color: var(--primary); cursor: pointer; width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.3s; margin-right: 5px;" onmouseover="this.style.background='var(--primary)'; this.style.color='white'" onmouseout="this.style.background='rgba(99, 102, 241, 0.1)'; this.style.color='var(--primary)'">
                                                    <i class="fa-solid fa-key"></i>
                                                </button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Security Level 2: Are you absolutely sure you want to delete this user?');" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <button type="submit" name="delete_user" title="Delete User" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #F87171; cursor: pointer; width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.3s;" onmouseover="this.style.background='#EF4444'; this.style.color='white'" onmouseout="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.color='#F87171'">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($view == 'projects'): ?>
            <!-- PROJECT REPOSITORY -->
            <div class="premium-header" style="display: flex; justify-content: space-between; align-items: flex-end;">
                <div>
                    <div class="premium-header-label-badge"><i class="fa-solid fa-folder-tree"></i> Repository</div>
                    <h1 class="premium-header-title">All Projects</h1>
                    <p class="premium-header-subtitle">Global repository of all submitted and active projects across seminaries.</p>
                </div>
                <a href="admin_export.php?type=projects" class="btn" style="height: 48px; padding: 0 25px; border-radius: 14px; font-size: 13px; font-weight: 750; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; white-space: nowrap; width: fit-content; flex-shrink: 0;">
                    <i class="fa-solid fa-file-csv" style="margin-right: 8px;"></i> EXPORT CSV
                </a>
            </div>

            <div class="premium-glass-card" style="padding: 24px !important; margin-bottom: 35px; border-radius: 24px !important;">
                <form method="GET" style="display: grid; grid-template-columns: 1fr 240px 160px; gap: 20px; align-items: center;">
                    <input type="hidden" name="view" value="projects">
                    <div class="search-container-premium">
                        <i class="fa-solid fa-magnifying-glass" style="color: rgba(255,255,255,0.3); margin-left: 5px;"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by title, student, or mentor..." class="search-input-premium">
                    </div>
                    <div class="select-wrapper">
                        <select name="status" class="select-premium">
                            <option value="">All Status</option>
                            <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Approved" <?php echo $status_filter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="Rejected" <?php echo $status_filter == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <button type="submit" class="filter-btn-premium" style="height: 52px; width: 100%;">
                        <i class="fa-solid fa-filter" style="margin-right: 8px;"></i> FILTER
                    </button>
                    <?php if(!empty($search) || !empty($status_filter)): ?>
                        <div style="grid-column: 1 / -1; text-align: right; margin-top: -10px;">
                            <a href="?view=projects" style="color: var(--text-muted); font-size: 13px; font-weight: 600; text-decoration: none;">Reset Filters</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="premium-glass-card" style="padding: 0 !important; overflow: hidden;">
                <div class="table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Project Detail</th>
                                <th>Student Lead</th>
                                <th>Team Info</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_projects as $p): ?>
                                <tr>
                                    <td style="padding: 15px 20px;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(99, 102, 241, 0.05)); border: 1px solid rgba(99, 102, 241, 0.2); display: flex; align-items: center; justify-content: center; font-size: 18px; color: white; flex-shrink: 0;">
                                                <i class="fa-solid fa-layer-group"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700; color: white; font-size: 14px;">[<?php echo formatProjectNumber($p['id']); ?>] <?php echo htmlspecialchars($p['project_title']); ?></div>
                                                <div style="color: var(--primary); font-size: 10px; font-weight: 800; text-transform: uppercase; margin-top: 4px; letter-spacing: 0.5px;"><?php echo htmlspecialchars($p['seminar_name'] ?? 'General'); ?> Seminar</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="color: white; font-size: 14px; font-weight: 500;"><?php echo htmlspecialchars($p['student_name']); ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px; color: white; font-size: 13px; font-weight: 600;">
                                            <i class="fa-solid fa-users" style="color: var(--primary); font-size: 14px;"></i> <?php echo $p['member_count']; ?> Members
                                        </div>
                                        <div style="font-size: 11px; color: rgba(255,255,255,0.4); margin-top: 3px;">Mentor: <?php echo htmlspecialchars($p['mentor_name']); ?></div>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="flex: 1; height: 6px; background: rgba(255,255,255,0.05); border-radius: 10px; overflow: hidden; width: 80px;">
                                                <div style="width: <?php echo min(100, ($p['sub_count'] / 5) * 100); ?>%; height: 100%; background: var(--accent); border-radius: 10px;"></div>
                                            </div>
                                            <span style="color: white; font-size: 11px; font-weight: 700;"><?php echo $p['sub_count']; ?>/5</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge" style="background: <?php echo $p['status'] == 'Approved' ? 'rgba(16, 185, 129, 0.1)' : ($p['status'] == 'Rejected' ? 'rgba(239, 68, 68, 0.1)' : 'rgba(245, 158, 11, 0.1)'); ?>; color: <?php echo $p['status'] == 'Approved' ? '#10B981' : ($p['status'] == 'Rejected' ? '#F87171' : '#F59E0B'); ?>; font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 5px 12px; border-radius: 8px; border: 1px solid currentColor;">
                                            <?php echo $p['status']; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <form method="POST" onsubmit="return confirm('Security Check Level 3: This will PERMANENTLY remove this project and all associated files. Continue?');">
                                            <input type="hidden" name="project_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" name="delete_project" title="Expunge Project" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #F87171; cursor: pointer; width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.3s;" onmouseover="this.style.background='#EF4444'; this.style.color='white'" onmouseout="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.color='#F87171'">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($view == 'faculty'): ?>
            <!-- FACULTY MANAGEMENT -->
            <div class="premium-header" style="display: flex; justify-content: space-between; align-items: flex-end;">
                <div>
                    <div class="premium-header-label-badge"><i class="fa-solid fa-user-tie"></i> Faculty</div>
                    <h1 class="premium-header-title">Mentor Management</h1>
                    <p class="premium-header-subtitle">Overview of faculty mentors and their assigned project teams.</p>
                </div>
                <button onclick="toggleModal('addFacultyModal')" class="btn" style="height: 48px; padding: 0 25px; border-radius: 14px; font-size: 13px; font-weight: 750; white-space: nowrap; width: fit-content; flex-shrink: 0;">
                    <i class="fa-solid fa-plus" style="margin-right: 8px;"></i> ADD NEW MENTOR
                </button>
            </div>

            <div id="addFacultyModal" class="premium-glass-card" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 4000; width: 500px; padding: 40px !important; box-shadow: 0 0 100px rgba(0,0,0,0.8) !important;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                    <h2 style="color: white; font-size: 22px; font-weight: 800;">Register Faculty</h2>
                    <i class="fa-solid fa-xmark" onclick="toggleModal('addFacultyModal')" style="cursor: pointer; color: rgba(255,255,255,0.4); font-size: 20px;"></i>
                </div>
                <form method="POST" style="display: flex; flex-direction: column; gap: 20px;">
                    <div class="form-group-premium">
                        <label>Full Name</label>
                        <input type="text" name="name" required class="admin-input-premium" placeholder="e.g. Dr. John Doe">
                    </div>
                    <div class="form-group-premium">
                        <label>Email Address</label>
                        <input type="email" name="email" required class="admin-input-premium" placeholder="e.g. john@university.edu">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group-premium">
                            <label>Department</label>
                            <div class="select-wrapper">
                                <select name="department" required class="admin-input-premium" style="width: 100%;">
                                    <option value="" disabled selected>Select Department</option>
                                    <option value="Computer Engineering">Computer Engineering</option>
                                    <option value="Information Technology">Information Technology</option>
                                    <option value="Electronics & Communication">Electronics & Communication</option>
                                    <option value="Mechanical Engineering">Mechanical Engineering</option>
                                    <option value="Civil Engineering">Civil Engineering</option>
                                    <option value="Electrical Engineering">Electrical Engineering</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group-premium">
                            <label>Designation</label>
                            <div class="select-wrapper">
                                <select name="designation" required class="admin-input-premium" style="width: 100%;">
                                    <option value="" disabled selected>Select Designation</option>
                                    <option value="Professor">Professor</option>
                                    <option value="Associate Professor">Associate Professor</option>
                                    <option value="Assistant Professor">Assistant Professor</option>
                                    <option value="Lecturer">Lecturer</option>
                                    <option value="Head of Department">Head of Department</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="add_faculty" class="btn" style="height: 52px; width: 100%; margin-top: 10px; border-radius: 14px;">REGISTER MENTOR</button>
                </form>
            </div>

            <div class="premium-glass-card" style="padding: 0 !important; overflow: hidden;">
                <div class="table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Mentor Name</th>
                                <th>Department</th>
                                <th>Assigned Teams</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_faculty as $f): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <div class="navbar-avatar" style="width: 38px; height: 38px; font-size: 13px; pointer-events: none;">
                                                <?php echo getProfileInitials($f['name']); ?>
                                            </div>
                                            <div>
                                                <div style="color: white; font-weight: 700; font-size: 14px;"><?php echo htmlspecialchars($f['name']); ?></div>
                                                <div style="color: rgba(255,255,255,0.4); font-size: 11px;"><?php echo htmlspecialchars($f['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="color: white; font-size: 13px; font-weight: 600;"><?php echo htmlspecialchars($f['department']); ?></div>
                                        <div style="color: rgba(255,255,255,0.4); font-size: 11px;"><?php echo htmlspecialchars($f['designation']); ?></div>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span style="color: white; font-weight: 800; font-size: 16px;"><?php echo $f['team_count']; ?></span>
                                            <span style="color: rgba(255,255,255,0.4); font-size: 11px; text-transform: uppercase;">Active Teams</span>
                                        </div>
                                    </td>
                                    <td style="text-align: right;">
                                        <form method="POST" onsubmit="return confirm('Remove this mentor from the system? Warning: This will also permanently delete all projects and submissions assigned to this mentor.');">
                                            <input type="hidden" name="user_id" value="<?php echo $f['id']; ?>">
                                            <button type="submit" name="delete_user" class="btn-icon" style="background: rgba(239, 68, 68, 0.1); color: #F87171; border: 1px solid rgba(239, 68, 68, 0.2);">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($view == 'documents'): ?>
            <!-- DOCUMENT MANAGEMENT -->
            <div class="premium-header" style="display: flex; justify-content: space-between; align-items: flex-end;">
                <div>
                    <div class="premium-header-label-badge"><i class="fa-solid fa-file-shield"></i> Vault</div>
                    <h1 class="premium-header-title">Document Repository</h1>
                    <p class="premium-header-subtitle">Monitor all project submissions and file uploads globally.</p>
                </div>
                <a href="admin_export.php?type=documents" class="btn" style="height: 48px; padding: 0 25px; border-radius: 14px; font-size: 13px; font-weight: 750; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; white-space: nowrap; width: fit-content; flex-shrink: 0;">
                    <i class="fa-solid fa-file-csv" style="margin-right: 8px;"></i> EXPORT CSV
                </a>
            </div>

            <div class="premium-glass-card" style="padding: 0 !important; overflow: hidden;">
                <div class="table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Submission Title</th>
                                <th>Project Source</th>
                                <th>Submitted By</th>
                                <th>Date</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_documents as $doc): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(56, 189, 248, 0.1); color: #38BDF8; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                                <i class="fa-solid fa-file-pdf"></i>
                                            </div>
                                            <div style="font-weight: 700; color: white; font-size: 14px;"><?php echo htmlspecialchars($doc['submission_title']); ?></div>
                                        </div>
                                    </td>
                                    <td><div style="color: var(--primary); font-size: 12px; font-weight: 700;">[<?php echo formatProjectNumber($doc['project_id']); ?>] <?php echo htmlspecialchars($doc['project_title']); ?></div></td>
                                    <td style="color: white; font-size: 13px; font-weight: 500;"><?php echo htmlspecialchars($doc['student_name']); ?></td>
                                    <td style="color: rgba(255,255,255,0.4); font-size: 12px; font-weight: 600;"><?php echo date('M d, Y', strtotime($doc['submitted_at'])); ?></td>
                                    <td style="text-align: right;">
                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn-icon" style="background: rgba(16, 185, 129, 0.1); color: #10B981; border: 1px solid rgba(16, 185, 129, 0.2);"><i class="fa-solid fa-download"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($view == 'activity'): ?>
            <!-- SYSTEM ACTIVITY -->
            <div class="premium-header">
                <div class="premium-header-label-badge"><i class="fa-solid fa-clock-rotate-left"></i> Security Log</div>
                <h1 class="premium-header-title">System Activity</h1>
                <p class="premium-header-subtitle">Real-time audit log of all critical system events and user actions.</p>
            </div>

            <div class="premium-glass-card" style="padding: 30px !important;">
                <div style="display: flex; flex-direction: column; gap: 18px;">
                    <?php foreach ($system_activity as $act): ?>
                        <div style="display: flex; align-items: center; gap: 18px; padding: 14px 20px; background: rgba(255,255,255,0.02); border-radius: 18px; border: 1px solid rgba(255,255,255,0.05); transition: all 0.3s; overflow: hidden;" onmouseover="this.style.background='rgba(255,255,255,0.04)'; this.style.transform='translateX(5px)'" onmouseout="this.style.background='rgba(255,255,255,0.02)'; this.style.transform='translateX(0)'">
                            <div style="width: 42px; height: 42px; border-radius: 12px; border: 1px solid currentColor; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; 
                                color: <?php echo $act['type'] == 'Project' ? 'var(--primary)' : ($act['type'] == 'User' ? 'var(--accent)' : '#FB923C'); ?>;
                                background: <?php echo $act['type'] == 'Project' ? 'rgba(79, 70, 229, 0.1)' : ($act['type'] == 'User' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(251, 146, 60, 0.1)'); ?>;">
                                <i class="fa-solid <?php echo $act['type'] == 'Project' ? 'fa-diagram-project' : ($act['type'] == 'User' ? 'fa-user-plus' : 'fa-file-arrow-up'); ?>"></i>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                                    <h4 style="color: white; font-size: 13.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($act['action']); ?></h4>
                                    <span style="color: rgba(255,255,255,0.3); font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; flex-shrink: 0;"><?php echo date('M d, H:i', strtotime($act['date'])); ?></span>
                                </div>
                                <p style="color: rgba(255,255,255,0.5); font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;"><?php echo htmlspecialchars($act['title']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($view == 'profile'): ?>
            <!-- PROFILE VIEW -->
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge"><i class="fa-solid fa-user-circle"></i> Account</div>
                <h1 class="premium-header-title">My Profile</h1>
                <p class="premium-header-subtitle">View and manage your administrative personal information.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 30px;">
                <!-- LEFT PILLAR: IDENTITY & INFO -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <!-- Identity Card -->
                    <div class="premium-glass-card" style="padding: 40px 30px; text-align: center;">
                        <div class="profile-avatar-large">
                            <?php if ($profile_image && file_exists($profile_image)): ?>
                                <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo $initials; ?>
                            <?php endif; ?>
                            <label class="avatar-edit-overlay">
                                <i class="fa-solid fa-camera" style="font-size: 24px; color: white;"></i>
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="file" name="profile_photo" hidden onchange="this.form.submit()">
                                    <input type="hidden" name="update_profile_photo" value="1">
                                </form>
                            </label>
                        </div>
                        <div style="margin-top: 25px;">
                            <h2 style="color: white; margin-bottom: 8px; font-size: 20px; font-weight: 800;"><?php echo htmlspecialchars(explode('|', $user_name)[0]); ?></h2>
                            <div style="display: flex; justify-content: center; gap: 10px; align-items: center;">
                                <span class="role-badge" style="background: rgba(99, 102, 241, 0.1); color: var(--primary); border: 1px solid currentColor; padding: 4px 12px; border-radius: 8px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;">Root Admin</span>
                            </div>
                        </div>
                    </div>

                    <!-- Authority Information (Form) -->
                    <div class="premium-glass-card" style="padding: 30px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 700;">
                            <i class="fa-solid fa-id-card-clip" style="color: var(--primary);"></i> Authority Details
                        </h3>
                        <form method="POST" style="display: flex; flex-direction: column; gap: 15px;">
                            <div>
                                <label style="color: var(--text-muted); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">Full Access Name</label>
                                <input type="text" name="full_name" class="form-input admin-input-premium" value="<?php echo htmlspecialchars(explode('|', $user_name)[0]); ?>" required style="height: 48px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="color: var(--text-muted); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">Security Email</label>
                                <input type="email" name="email" class="form-input admin-input-premium" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>" required style="height: 48px; font-size: 14px;">
                            </div>
                            <button type="submit" name="update_profile_info" class="btn" style="width: 100%; border-radius: 12px; margin-top: 5px; height: 48px; font-size: 14px;">Save Changes</button>
                        </form>
                    </div>
                </div>

                <!-- RIGHT PILLAR: OVERVIEW -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <!-- System Snapshot -->
                    <div class="premium-glass-card" style="padding: 24px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 700;">
                            <i class="fa-solid fa-chart-line" style="color: var(--accent);"></i> System Snapshot
                        </h3>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                            <div style="padding: 16px; background: rgba(255,255,255,0.03); border-radius: 16px; border: 1px solid rgba(255,255,255,0.05); text-align: center;">
                                <small style="color: var(--text-muted); display: block; font-size: 10px; margin-bottom: 6px; font-weight: 700; text-transform: uppercase;">Students</small>
                                <span style="color: white; font-weight: 800; font-size: 20px;"><?php echo $student_count; ?></span>
                            </div>
                            <div style="padding: 16px; background: rgba(255,255,255,0.03); border-radius: 16px; border: 1px solid rgba(255,255,255,0.05); text-align: center;">
                                <small style="color: var(--text-muted); display: block; font-size: 10px; margin-bottom: 6px; font-weight: 700; text-transform: uppercase;">Mentors</small>
                                <span style="color: white; font-weight: 800; font-size: 20px;"><?php echo $mentor_count; ?></span>
                            </div>
                            <div style="padding: 16px; background: rgba(255,255,255,0.03); border-radius: 16px; border: 1px solid rgba(255,255,255,0.05); text-align: center;">
                                <small style="color: var(--text-muted); display: block; font-size: 10px; margin-bottom: 6px; font-weight: 700; text-transform: uppercase;">Projects</small>
                                <span style="color: white; font-weight: 800; font-size: 20px;"><?php echo $project_count; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Privilege & Audit -->
                    <div class="premium-glass-card" style="padding: 24px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 700;">
                            <i class="fa-solid fa-shield-halved" style="color: #10B981;"></i> Administrative Privileges
                        </h3>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(16, 185, 129, 0.05); border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.1);">
                                <div style="width: 32px; height: 32px; background: #10B981; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px;"><i class="fa-solid fa-check"></i></div>
                                <div style="flex: 1;">
                                    <span style="color: white; font-weight: 600; font-size: 14px;">Full System Control</span>
                                    <span style="font-size: 10px; color: #10B981; margin-left: 5px; font-weight: 700; text-transform: uppercase;">Active</span>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,0.03); border-radius: 12px;">
                                <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.1); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px;"><i class="fa-solid fa-check"></i></div>
                                <div style="flex: 1;">
                                    <span style="color: white; font-weight: 500; font-size: 14px;">Master Access Key Holder</span>
                                    <span style="font-size: 10px; color: var(--text-muted); margin-left: 5px; text-transform: uppercase;">Verified</span>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,0.03); border-radius: 12px;">
                                <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.1); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px;"><i class="fa-solid fa-check"></i></div>
                                <div style="flex: 1;">
                                    <span style="color: white; font-weight: 500; font-size: 14px;">Database Management</span>
                                    <span style="font-size: 10px; color: var(--text-muted); margin-left: 5px; text-transform: uppercase;">Root Level</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($view == 'security'): ?>
            <!-- SECURITY VIEW -->
            <div class="premium-header" style="margin-bottom: 30px;">
                <div class="premium-header-label-badge"><i class="fa-solid fa-gear"></i> Preferences</div>
                <h1 class="premium-header-title">Settings</h1>
                <p class="premium-header-subtitle">Customize your notifications and update account security master keys.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <!-- LEFT PILLAR: PASSWORD -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <div class="premium-glass-card" style="padding: 30px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; font-weight: 700;">
                            <i class="fa-solid fa-key" style="color: #F87171;"></i> Master Security Key
                        </h3>
                        <form method="POST">
                            <input type="hidden" name="change_password" value="1">
                            <div class="form-group-premium" style="margin-bottom: 15px;">
                                <label style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.4);">Current Master Key</label>
                                <div class="input-container">
                                    <input type="password" name="current_password" required placeholder="Verify identity..." class="admin-input-premium" style="height: 48px; width: 100%;">
                                    <div class="toggle-password" onclick="togglePasswordVisibility(this)">
                                        <i class="fa-regular fa-eye-slash"></i>
                                        <i class="fa-regular fa-eye"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group-premium" style="margin-bottom: 15px;">
                                <label style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.4);">New Security Key</label>
                                <div class="input-container">
                                    <input type="password" name="new_password" required placeholder="Entropy required..." class="admin-input-premium" style="height: 48px; width: 100%;">
                                    <div class="toggle-password" onclick="togglePasswordVisibility(this)">
                                        <i class="fa-regular fa-eye-slash"></i>
                                        <i class="fa-regular fa-eye"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group-premium" style="margin-bottom: 24px;">
                                <label style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.4);">Confirm Master Key</label>
                                <div class="input-container">
                                    <input type="password" name="confirm_password" required placeholder="Finalize mapping..." class="admin-input-premium" style="height: 48px; width: 100%;">
                                    <div class="toggle-password" onclick="togglePasswordVisibility(this)">
                                        <i class="fa-regular fa-eye-slash"></i>
                                        <i class="fa-regular fa-eye"></i>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn" style="width: 100%; height: 48px; border-radius: 12px; font-size: 14px; font-weight: 700;">Update Security Key</button>
                        </form>
                    </div>
                </div>

                <!-- RIGHT PILLAR: PREFERENCES -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <div class="premium-glass-card" style="padding: 30px;">
                        <h3 style="color: white; font-size: 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; font-weight: 700;">
                            <i class="fa-solid fa-bell" style="color: #6366F1;"></i> System Notifications
                        </h3>
                        <form method="POST">
                            <input type="hidden" name="update_admin_notifications" value="1">
                            <div style="display: flex; flex-direction: column; gap: 20px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                    <div>
                                        <div style="color: white; font-weight: 600; font-size: 14px;">New Registrations</div>
                                        <div style="color: rgba(255,255,255,0.5); font-size: 12px;">Alerts for new project team registrations.</div>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="notif_new_project" <?php echo ($admin_details['notif_new_project'] ?? 1) ? 'checked' : ''; ?>>
                                        <span class="slider round"></span>
                                    </label>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                    <div>
                                        <div style="color: white; font-weight: 600; font-size: 14px;">User Lifecycle</div>
                                        <div style="color: rgba(255,255,255,0.5); font-size: 12px;">Get notified when new users join the platform.</div>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="notif_new_user" <?php echo ($admin_details['notif_new_user'] ?? 1) ? 'checked' : ''; ?>>
                                        <span class="slider round"></span>
                                    </label>
                                </div>
                                <button type="submit" class="btn" style="width: 100%; border-radius: 12px; height: 48px; background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%); font-size: 14px; font-weight: 700;">Save Preferences</button>
                            </div>
                        </form>
                    </div>

                    <div class="premium-glass-card" style="padding: 24px; border: 1px solid rgba(239, 68, 68, 0.2);">
                        <h3 style="color: #F87171; font-size: 15px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; font-weight: 700;">
                            <i class="fa-solid fa-triangle-exclamation"></i> Danger Zone
                        </h3>
                        <div style="background: rgba(239, 68, 68, 0.05); padding: 20px; border-radius: 16px; border: 1px solid rgba(239, 68, 68, 0.1);">
                            <p style="color: rgba(255,255,255,0.5); font-size: 11px; margin-bottom: 15px;">Wipe all project data, messages, and student registrations. This will reset the system to its initial state using <code>database.sql</code>.</p>
                            <a href="reset_db.php" onclick="return confirm('CRITICAL ACTION: This will delete ALL data (projects, messages, submissions) and reset the system. Are you absolutely sure?');" class="btn" style="width: 100%; height: 42px; border-radius: 10px; font-size: 13px; font-weight: 700; background: rgba(239, 68, 68, 0.15); color: #F87171; border: 1px solid rgba(239, 68, 68, 0.2); text-decoration: none; display: flex; align-items: center; justify-content: center; transition: all 0.3s;" onmouseover="this.style.background='rgba(239, 68, 68, 0.25)'; this.style.color='white';" onmouseout="this.style.background='rgba(239, 68, 68, 0.15)'; this.style.color='#F87171';">Reset System Database</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- FLASH MESSAGES (Relocated to bottom-right toast) -->
    <?php if(isset($_GET['success']) || isset($_GET['error'])): ?>
        <?php 
            $is_success = isset($_GET['success']);
            $msg_type = $is_success ? 'success' : 'error';
            $bg = $is_success ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)';
            $border = $is_success ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)';
            $color = $is_success ? '#10B981' : '#F87171';
            $accent = $is_success ? '#10B981' : '#EF4444';
            $icon = $is_success ? 'fa-circle-check' : 'fa-circle-exclamation';
            $title = $is_success ? 'Success!' : 'Authority Alert';
            
            if ($is_success) {
                $msgs = [
                    'ProfileUpdated' => 'Profile information updated successfully!',
                    'PasswordChanged' => 'Password reset successfully!',
                    'PhotoUpdated' => 'Profile picture updated successfully!',
                    'PasswordReset' => 'User password was successfully reset to default.',
                    'UserDeleted' => 'User account has been permanently removed.',
                    'ProjectDeleted' => 'Project records have been permanently removed.',
                    'StatusUpdated' => 'User activation status has been successfully updated.',
                    'FacultyAdded' => 'New faculty mentor has been registered successfully.',
                    'SettingsUpdated' => 'System preferences have been saved.'
                ];
                $message = $msgs[$_GET['success']] ?? 'Administrative task completed successfully.';
            } else {
                $errors = [
                    'InvalidCurrentPassword' => 'Current password matches incorrectly.',
                    'PasswordMismatch' => 'New passwords do not match.',
                    'EmailExists' => 'The email address is already in use by another account.',
                    'InvalidPassword' => 'The password update was invalid.'
                ];
                $message = $errors[$_GET['error']] ?? 'An error occurred. Please try again.';
            }
        ?>
        <div class="alert" style="position: fixed; bottom: 30px; right: 30px; background: <?php echo $bg; ?>; backdrop-filter: blur(20px); border: 1px solid <?php echo $border; ?>; color: <?php echo $color; ?>; padding: 16px 24px; border-radius: 16px; font-weight: 700; display: flex; align-items: center; gap: 12px; z-index: 2000; box-shadow: 0 10px 40px rgba(0,0,0,0.3); animation: slideIn 0.5s cubic-bezier(0.16, 1, 0.3, 1); border-left: 5px solid <?php echo $accent; ?>;">
            <i class="fa-solid <?php echo $icon; ?>" style="font-size: 20px;"></i>
            <div>
                <div style="font-size: 14px;"><?php echo $title; ?></div>
                <div style="font-size: 11px; opacity: 0.8; font-weight: 500;"><?php echo $message; ?></div>
            </div>
        </div>
    <?php endif; ?>

    <style>
        @keyframes slideIn { from { transform: translateX(100%) scale(0.9); opacity: 0; } to { transform: translateX(0) scale(1); opacity: 1; } }
        .nav-link:hover { color: white !important; }

        :root {
            --text-muted: rgba(255, 255, 255, 0.7);
            --border-light: rgba(255, 255, 255, 0.08);
        }

        .premium-glass-card {
            background: rgba(13, 20, 36, 0.7) !important; 
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5) !important;
            backdrop-filter: blur(25px) !important;
        }

        .stats-grid .premium-glass-card {
            transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
            cursor: pointer;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
        }

        .stats-grid .premium-glass-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 25px rgba(99, 102, 241, 0.1) !important;
            border-color: rgba(99, 102, 241, 0.3) !important;
        }

        .profile-avatar-large {
            width: 140px !important;
            height: 140px !important;
            border-radius: 50%;
            border: 4px solid rgba(255, 255, 255, 0.1);
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 800;
            color: white;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            position: relative;
            overflow: hidden;
            margin: 0 auto;
        }

        .avatar-edit-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .profile-avatar-large:hover .avatar-edit-overlay {
            opacity: 1;
        }

        .table-container {
            background: rgba(0, 0, 0, 0.2) !important;
            border-radius: 20px;
            padding: 5px;
            margin-top: 10px;
            border: none !important;
            overflow-x: auto;
            width: 100%;
            scrollbar-width: thin;
            scrollbar-color: rgba(99, 102, 241, 0.5) transparent;
        }

        .admin-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            color: white !important;
        }

        .admin-table thead th {
            color: rgba(255, 255, 255, 0.8) !important;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 15px 25px;
            border: none;
        }

        .admin-table tbody tr {
            background: rgba(255, 255, 255, 0.03);
            transition: all 0.3s ease;
        }

        .admin-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.06);
            transform: translateY(-2px);
        }

        .admin-table td {
            padding: 20px 25px;
            border: none;
            color: white !important;
        }

        .admin-table td:first-child { border-radius: 16px 0 0 16px; }
        .admin-table td:last-child { border-radius: 0 16px 16px 0; }

        /* SELECT ANIMATIONS */
        .select-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .select-wrapper::after {
            content: "\f107";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 20px;
            color: rgba(255, 255, 255, 0.4);
            pointer-events: none;
        }

        .select-wrapper.custom-select-initialized::after {
            display: none !important;
        }

        select[data-select-init="true"] {
            display: none !important;
        }

        .select-wrapper:focus-within::after {
            color: var(--primary);
        }

        .select-wrapper select {
            appearance: none;
            -webkit-appearance: none;
            width: 100%;
        }

        /* SEARCH BOX REFINEMENTS */
        .search-container-premium {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 18px;
            padding: 2px 15px;
            display: flex;
            gap: 12px;
            align-items: center;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }

        .search-container-premium:focus-within {
            background: rgba(0, 0, 0, 0.4);
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15), inset 0 2px 4px rgba(0,0,0,0.2);
        }

        .search-container-premium i {
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .search-container-premium:focus-within i {
            color: var(--primary) !important;
            transform: scale(1.1);
        }

        .search-input-premium {
            background: transparent !important;
            border: none !important;
            padding: 12px 0 !important;
            color: white !important;
            font-size: 15px !important;
            font-weight: 500 !important;
            width: 100%;
            outline: none !important;
        }

        .search-input-premium::placeholder {
            color: rgba(255,255,255,0.3) !important;
        }

        .filter-btn-premium {
            background: linear-gradient(135deg, var(--secondary), var(--primary)) !important;
            border: none !important;
            border-radius: 16px !important;
            color: white !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 1px !important;
            font-size: 13px !important;
            cursor: pointer !important;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 10px !important;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3) !important;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            border: 1px solid transparent;
        }

        .btn-icon:hover {
            transform: translateY(-2px);
            filter: brightness(1.2);
        }

        .filter-btn-premium:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.4) !important;
            filter: brightness(1.1);
        }

        .filter-btn-premium:active {
            transform: translateY(0) scale(0.98);
        }

        .select-premium {
            height: 52px;
            background: rgba(0,0,0,0.25) !important;
            border: 1px solid rgba(255,255,255,0.1) !important;
            border-radius: 16px !important;
            padding: 0 20px !important;
            color: white !important;
            font-weight: 600 !important;
            font-size: 14px !important;
            outline: none !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
        }

        .select-premium:hover {
            background: rgba(0,0,0,0.4) !important;
            border-color: rgba(255,255,255,0.2) !important;
        }

        .select-premium:focus {
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1) !important;
        }

        /* FORM INPUT REFINEMENTS */
        .form-group-premium {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .form-group-premium label {
            color: rgba(255, 255, 255, 0.4);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .admin-input-premium {
            width: 100%;
            height: 56px;
            background: rgba(0, 0, 0, 0.2) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 16px;
            padding: 0 20px;
            color: white !important;
            font-size: 15px;
            outline: none !important;
            transition: all 0.3s ease;
        }

        .admin-input-premium:focus {
            border-color: var(--primary) !important;
            background: rgba(0, 0, 0, 0.3) !important;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        /* COMPONENT ANIMATIONS */
        .glass-dropdown {
            transform-origin: top right;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px) scale(0.95);
        }

        .glass-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }
        /* TOGGLE SWITCH STYLES */
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .switch input { 
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(255, 255, 255, 0.1);
            transition: .4s;
            border-radius: 34px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }

        input:checked + .slider {
            background: linear-gradient(135deg, #6366F1, #8B5CF6);
            border-color: #6366F1;
        }

        input:checked + .slider:before {
            transform: translateX(20px);
        }

    </style>

    <script>
        function toggleDropdown(id) {
            const dropdown = document.getElementById(id);
            const isVisible = dropdown.classList.contains('show');
            document.querySelectorAll('.glass-dropdown').forEach(d => d.classList.remove('show'));
            if (!isVisible) dropdown.classList.add('show');
        }

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

        window.onclick = function(e) {
            if (!e.target.closest('.notification-wrapper') && !e.target.closest('.profile-wrapper')) {
                document.querySelectorAll('.glass-dropdown').forEach(d => d.classList.remove('show'));
            }
        }

        // Auto-hide alert messages
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                const alert = document.querySelector('.alert');
                if (alert) {
                    alert.style.transition = 'all 0.5s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(20px)';
                    setTimeout(() => alert.remove(), 500);
                }
            }, 4000);
        });
        function toggleModal(id) {
            const modal = document.getElementById(id);
            const overlay = document.getElementById('modalOverlay');
            if (modal.style.display === 'none') {
                modal.style.display = 'block';
                overlay.style.display = 'block';
            } else {
                modal.style.display = 'none';
                overlay.style.display = 'none';
            }
        }
    </script>
    <div id="modalOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); backdrop-filter: blur(5px); z-index: 3500;" onclick="document.querySelectorAll('.premium-glass-card[style*=\'position: fixed\']').forEach(m => m.style.display='none'); this.style.display='none';"></div>
    <script src="js/premium-select.js?v=<?php echo time(); ?>"></script>
</body>
</html>