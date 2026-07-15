<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    redirectAfterLogin($_SESSION['role']);
}

$error = '';
$success = '';

if (isset($_GET['registered'])) {
    $success = "Registration Successful.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $selected_role = $_POST['selected_role'] ?? 'student';

    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (enrollment_no = ? OR email = ?) AND role = ?");
        $stmt->execute([$email, $email, $selected_role]); 
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if (isset($user['is_active']) && !$user['is_active']) {
                $error = "Your account has been deactivated. Please contact the administrator.";
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['role'] === 'student' ? $user['name'] . ' | ' . $user['enrollment_no'] : $user['name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['email'] = $user['email']; 
                
                if ($user['role'] === 'student') {
                    $check_proj = $pdo->prepare("SELECT COUNT(*) as cnt FROM projects p LEFT JOIN project_members pm ON p.id = pm.project_id WHERE (p.student_id = ? OR pm.student_id = ?) AND (p.status = 'Pending' OR p.status = 'Approved')");
                    $check_proj->execute([$user['id'], $user['id']]);
                    if ($check_proj->fetch()['cnt'] == 0) {
                        header("Location: register_project.php");
                        exit();
                    }
                }

                redirectAfterLogin($user['role']);
            }
        } else {
            $error = "Invalid email or password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ProjectFlow</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body class="auth-body">
    <div class="login-page-wrapper">
        <div class="page-banner">
            <div class="logo-container">
                <svg class="logo-svg-container" width="100" height="100" viewBox="0 0 120 120"
                    xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <linearGradient id="swooshGrad" x1="0%" y1="100%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#1E73BE" />
                            <stop offset="100%" stop-color="#0A3D62" />
                        </linearGradient>
                        <style>
                            @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@800&display=swap');

                            .monogram-initials {
                                font-family: 'Montserrat', sans-serif;
                                font-weight: 800;
                                font-size: 56px;
                                fill: #4A4A4A;
                                letter-spacing: -2px;
                            }
                        </style>
                    </defs>
                    <path class="swoosh-path" d="M 15 105 C 20 45, 45 20, 105 15 C 65 35, 35 65, 15 105 Z"
                        fill="url(#swooshGrad)" opacity="0.85" />
                    <text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle"
                        class="monogram-initials">PF</text>
                </svg>
                <div class="branding shadow-sm">
                    <div class="brand-main">PROJECTFLOW</div>
                    <div class="brand-sub">PROJECT MANAGEMENT PORTAL</div>
                </div>
            </div>
        </div>

        <div class="auth-container">
            <div class="auth-card">
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i>
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fa-solid fa-circle-exclamation" style="margin-right: 8px;"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <div class="role-selector-horizontal">
                    <button type="button" class="role-panel student-panel active" onclick="switchRole('student')">
                        <span>Student</span>
                    </button>
                    <button type="button" class="role-panel mentor-panel" onclick="switchRole('mentor')">
                        <span>Mentor</span>
                    </button>
                    <button type="button" class="role-panel admin-panel" onclick="switchRole('admin')">
                        <span>Admin</span>
                    </button>
                </div>

                <form id="loginForm" method="POST" action="">
                    <input type="hidden" name="selected_role" id="selectedRoleInput" value="student">

                    <div class="form-group">
                        <label id="loginIdLabel">Enrollment No.</label>
                        <div class="input-container">
                            <input type="text" name="email" id="loginIdInput" class="form-input" placeholder="23BECE30000" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-container">
                            <input type="password" name="password" id="password" class="form-input"
                                placeholder="Enter Password" autocomplete="new-password" required>
                            <div class="toggle-password" id="togglePassword">
                                <i class="fa-regular fa-eye-slash"></i>
                                <i class="fa-regular fa-eye"></i>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-shine">
                        <span style="position: relative; z-index: 5;">Login <i class="fa-solid fa-chevron-right" style="margin-left: 10px; font-size: 16px;"></i></span>
                    </button>
                </form>

                <div class="auth-footer">
                    Don't have an account? <a href="register.php">Register</a>
                </div>
            </div>
        </div>

        <script>
            const panels = document.querySelectorAll('.role-panel');
            const roleInput = document.getElementById('selectedRoleInput');

            panels.forEach(panel => {
                panel.addEventListener('click', function () {
                    panels.forEach(p => p.classList.remove('active'));
                    this.classList.add('active');

                    const role = this.classList.contains('student-panel') ? 'student' :
                        this.classList.contains('mentor-panel') ? 'mentor' : 'admin';

                    roleInput.value = role;
                    
                    const idLabel = document.getElementById('loginIdLabel');
                    const idInput = document.getElementById('loginIdInput');
                    
                    if (role === 'student') {
                        idLabel.innerText = 'Enrollment No.';
                        idInput.placeholder = '23BECE30000';
                    } else if (role === 'mentor') {
                        idLabel.innerText = 'Mentor ID';
                        idInput.placeholder = 'Enter Mentor ID / Email';
                    } else if (role === 'admin') {
                        idLabel.innerText = 'Admin ID';
                        idInput.placeholder = 'Enter Admin ID / Email';
                    }

                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 100);
                });
            });

            document.getElementById('togglePassword').addEventListener('click', function () {
                const passwordInput = document.getElementById('password');
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                this.classList.toggle('active');
            });

            document.getElementById('loginForm').addEventListener('submit', function (e) {
                const email = this.querySelector('input[name="email"]').value;
                const password = this.querySelector('input[name="password"]').value;

                if (!email || !password) {
                    e.preventDefault();
                    alert('Please fill in all fields');
                }
            });
        </script>
</body>

</html>