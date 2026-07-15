<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    redirectAfterLogin($_SESSION['role']);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $combined_name = trim($_POST['combined_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = 'student';

    $name = '';
    $enrollment_no = '';
    if (strpos($combined_name, '|') !== false) {
        list($name, $enrollment_no) = array_map('trim', explode('|', $combined_name));
    }

    if (empty($combined_name)) {
        $error = "Please enter your Name and Enrollment No.";
    } elseif (strpos($combined_name, '|') === false) {
        $error = "Please use the 'Full Name | Enrollment No.' format.";
    } elseif (empty($name) || empty($enrollment_no)) {
        $error = "Both Name and Enrollment No. are required.";
    } elseif (empty($password)) {
        $error = "Password is required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $parts = explode(' ', trim($name));
        $first_name = (count($parts) >= 3) ? $parts[1] : $parts[0];
        
        $exceptions = ['JILL', 'TRUSHA', 'KARTIK', 'DEEPRAJSINH', 'JINALBA'];
        if (in_array(strtoupper($parts[0]), $exceptions)) {
            $first_name = $parts[0];
        }

        $suffix = substr($enrollment_no, -2);
        $email = strtolower($first_name) . $suffix . '@123';
        
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id, email, password FROM users WHERE LOWER(name) = LOWER(?) AND enrollment_no = ? AND role = 'student'");
            $stmt->execute([$name, $enrollment_no]);
            $existing_user = $stmt->fetch();

            if (!$existing_user) {
                throw new Exception("No record found matching the provided Name and Enrollment No. Please contact the administrator.");
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $existing_user['id']]);
            if ($stmt->fetch()) {
                throw new Exception("This email address is already registered to another account.");
            }

            $pwd = $existing_user['password'];
            $is_unregistered = ($pwd === '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' || password_verify('student@123', $pwd));
            
            if (!$is_unregistered) {
                throw new Exception("This account has already been registered. Please login or contact the administrator.");
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("UPDATE users SET email = ?, password = ? WHERE id = ?");
            $stmt->execute([$email, $hashed_password, $existing_user['id']]);

            $user_id = $existing_user['id'];
            $pdo->commit();

            header("Location: login.php?registered=1");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$stmt = $pdo->query("SELECT name, enrollment_no FROM users WHERE role = 'student' ORDER BY enrollment_no ASC");
$all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - ProjectFlow</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
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
            </div>
        </div>

        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-header">
                    <h1 style="color: white; font-weight: 800; font-size: 32px;">Registration Portal</h1>
                    <p style="color: rgba(255,255,255,0.4); font-size: 14px; margin-top: 8px;">Activate your academic project account</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <form id="registerForm" method="POST" action="">
                    <div class="form-group">
                        <label>Name & Enrollment No.</label>
                        <select name="combined_name" required>
                            <option value="">Search your name or enrollment...</option>
                            <?php foreach ($all_students as $student): ?>
                                <?php 
                                $val = $student['name'] . ' | ' . $student['enrollment_no']; 
                                $selected = (!empty($combined_name) && $combined_name === $val) ? 'selected' : '';
                                ?>
                                <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($val); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-container">
                            <input type="password" name="password" id="password" class="form-input"
                                placeholder="••••••••" required>
                            <div class="toggle-password" data-target="password">
                                <i class="fa-regular fa-eye-slash"></i>
                                <i class="fa-regular fa-eye"></i>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="input-container">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-input"
                                placeholder="••••••••" required>
                            <div class="toggle-password" data-target="confirm_password">
                                <i class="fa-regular fa-eye-slash"></i>
                                <i class="fa-regular fa-eye"></i>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-shine">
                        <span style="position: relative; z-index: 5;">Register <i class="fa-solid fa-chevron-right" style="margin-left: 10px; font-size: 16px;"></i></span>
                    </button>
                </form>

                <div class="auth-footer">
                    Already have an account? <a href="login.php">Login</a>
                </div>
            </div>
        </div>

        <script>
            document.querySelectorAll('.toggle-password').forEach(toggle => {
                toggle.addEventListener('click', function () {
                    const targetId = this.getAttribute('data-target');
                    const input = document.getElementById(targetId);
                    const isPassword = input.getAttribute('type') === 'password';
                    input.setAttribute('type', isPassword ? 'text' : 'password');
                    this.classList.toggle('active');
                });
            });
        </script>
        <script src="js/premium-select.js?v=<?php echo time(); ?>"></script>
    </div>
</body>
</html>