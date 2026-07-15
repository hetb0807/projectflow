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
$display_name = explode('|', $user_name)[0];

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

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$project_data = null;
$edit_members = [];

if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND student_id = ? AND status = 'Rejected'");
    $stmt->execute([$edit_id, $user_id]);
    $project_data = $stmt->fetch();
    
    if (!$project_data) {
        header("Location: student_dashboard.php?error=InvalidProject");
        exit();
    }
    
    // Fetch team members
    $stmt = $pdo->prepare("SELECT student_id FROM project_members WHERE project_id = ?");
    $stmt->execute([$edit_id]);
    $edit_members = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt 
                          FROM projects p 
                          LEFT JOIN project_members pm ON p.id = pm.project_id
                          WHERE (p.student_id = ? OR pm.student_id = ?) 
                          AND (p.status = 'Pending' OR p.status = 'Approved')");
    $stmt->execute([$user_id, $user_id]);
    $has_active = $stmt->fetch()['cnt'] > 0;

    if ($has_active) {
        header("Location: student_dashboard.php?view=my-projects");
        exit();
    }
}

$stmt = $pdo->prepare("SELECT u.id, u.name, u.department,
                     (SELECT COUNT(*) FROM projects WHERE mentor_id = u.id AND (status = 'Approved' OR status = 'Pending')) as active_projects
                     FROM users u WHERE role = 'mentor' 
                     HAVING active_projects < 5 OR u.id = ?
                     ORDER BY u.department ASC, u.name ASC");
$stmt->execute([$project_data['mentor_id'] ?? -1]);
$mentors = $stmt->fetchAll();

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
$students = $stmt->fetchAll();

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_project'])) {
    $title = trim($_POST['project_title']);
    $dept = trim($_POST['department']);
    $mentor_id = $_POST['mentor_id'];
    $seminar = trim($_POST['seminar_name']);
    $type = $_POST['project_type'];
    $desc = trim($_POST['description']);
    $tech_json = $_POST['technologies_json'] ?? '[]';
    
    $member02 = $_POST['member02'] ?? null;
    $member03 = $_POST['member03'] ?? null;
    $member04 = $_POST['member04'] ?? null;
    $members = array_filter([$member02, $member03, $member04]);

    try {
        $pdo->beginTransaction();

        if ($edit_id) {
            // Update existing rejected project
            $stmt = $pdo->prepare("UPDATE projects SET project_title = ?, department = ?, mentor_id = ?, seminar_name = ?, project_type = ?, technologies = ?, description = ?, status = 'Pending' WHERE id = ? AND student_id = ?");
            $stmt->execute([$title, $dept, $mentor_id, $seminar, $type, $tech_json, $desc, $edit_id, $user_id]);

            // Update team members: delete and insert
            $stmt = $pdo->prepare("DELETE FROM project_members WHERE project_id = ?");
            $stmt->execute([$edit_id]);

            if (!empty($members)) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO project_members (project_id, student_id) VALUES (?, ?)");
                foreach ($members as $mem_id) {
                    if ($mem_id != $user_id) {
                        $stmt->execute([$edit_id, $mem_id]);
                    }
                }
            }
        } else {
            // Insert new project
            $stmt = $pdo->prepare("INSERT INTO projects (student_id, mentor_id, project_title, department, seminar_name, project_type, technologies, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $mentor_id, $title, $dept, $seminar, $type, $tech_json, $desc]);
            $project_id = $pdo->lastInsertId();

            if (!empty($members)) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO project_members (project_id, student_id) VALUES (?, ?)");
                foreach ($members as $mem_id) {
                    if ($mem_id != $user_id) {
                        $stmt->execute([$project_id, $mem_id]);
                    }
                }
            }
        }

        $pdo->commit();
        header("Location: student_dashboard.php?view=my-projects&success=" . ($edit_id ? "ProjectUpdated" : "ProjectSubmitted"));
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "Error submitting project: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Project - ProjectFlow</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/registration.css?v=26">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="registration-page dashboard-body">
    <?php renderSidebar('student', 'register_project', false, null, false); ?>

    <?php renderTopNavbar('Register Project', $user_name, 'STUDENT PORTAL', $unread_count, $unread_notifications, $profile_image, $_SESSION['email'] ?? $enrollment_no); ?>

    <div class="content-area">
        <div class="registration-container">
            <div style="width: 100%; text-align: left; padding-left: 10px;">
                <div class="premium-header">
                    <a href="student_dashboard.php" class="go-back-link">
                        <i class="fa-solid fa-chevron-left"></i> Go Back
                    </a>
                    <div class="premium-header-label-badge">
                        <i class="fa-solid fa-building-columns"></i> <?php echo $edit_id ? 'Edit Proposal' : 'New Project'; ?>
                    </div>
                    <h1 class="premium-header-title"><?php echo $edit_id ? 'Edit Project Proposal' : 'Tell us about your project'; ?></h1>
                    <p class="premium-header-subtitle"><?php echo $edit_id ? 'Update the details of your project below to re-submit it for mentor approval.' : 'Fill in the details below to register your team. Once you\'re done, we\'ll send it to your mentor for review.'; ?></p>
                </div>
            </div>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px; border-radius: 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); padding: 15px; color: #FCA5A5;">
                    <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success" style="margin-bottom: 20px; border-radius: 12px; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.2); padding: 15px; color: #86EFAC;">
                    <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>

            <form id="projectForm" method="POST">
                <input type="hidden" name="register_project" value="1">
                <input type="hidden" name="technologies_json" id="technologies_json" value="[]">

                <div class="reg-step-container">
                    <div class="reg-step-group">
                        <div class="reg-step-indicator">
                            <div class="reg-step-number">01</div>
                            <div class="reg-step-line"></div>
                        </div>
                        <div class="reg-step-content">
                            <h2 class="reg-step-title">The Basics</h2>
                            <div class="premium-glass-card">
                                <div class="reg-form-grid">
                                    <div class="form-group">
                                        <label class="reg-field-label">Choose Department</label>
                                        <div class="reg-input-wrapper">
                                            <select name="department" id="department_select" class="reg-input reg-select" required>
                                                <option value="">Choose Department</option>
                                                <option value="Computer Engineering" <?php echo (isset($project_data) && $project_data['department'] === 'Computer Engineering') ? 'selected' : ''; ?>>Computer Engineering</option>
                                                <option value="Information Technology" <?php echo (isset($project_data) && $project_data['department'] === 'Information Technology') ? 'selected' : ''; ?>>Information Technology</option>
                                                <option value="Civil Engineering" <?php echo (isset($project_data) && $project_data['department'] === 'Civil Engineering') ? 'selected' : ''; ?>>Civil Engineering</option>
                                                <option value="Mechanical Engineering" <?php echo (isset($project_data) && $project_data['department'] === 'Mechanical Engineering') ? 'selected' : ''; ?>>Mechanical Engineering</option>
                                                <option value="Electrical Engineering" <?php echo (isset($project_data) && $project_data['department'] === 'Electrical Engineering') ? 'selected' : ''; ?>>Electrical Engineering</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="reg-field-label">Select Mentor</label>
                                        <div class="reg-input-wrapper">
                                            <select name="mentor_id" class="reg-input reg-select" required>
                                                <option value="">Choose Mentor</option>
                                                <?php foreach ($mentors as $mentor): ?>
                                                    <option value="<?php echo $mentor['id']; ?>" <?php echo (isset($project_data) && $project_data['mentor_id'] == $mentor['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($mentor['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="reg-field-label">What's the project name?</label>
                                        <div class="reg-input-wrapper no-arrow">
                                            <input type="text" name="project_title" class="reg-input" placeholder="e.g. Smart Campus App" required value="<?php echo isset($project_data) ? htmlspecialchars($project_data['project_title']) : ''; ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="reg-field-label">Seminar Name</label>
                                        <div class="reg-input-wrapper no-arrow">
                                            <input type="text" name="seminar_name" class="reg-input" placeholder="Enter your seminar name" required value="<?php echo isset($project_data) ? htmlspecialchars($project_data['seminar_name']) : ''; ?>">
                                        </div>
                                    </div>
                                    <div class="form-group reg-full-width">
                                        <label class="reg-field-label">Project Type</label>
                                        <div class="reg-input-wrapper">
                                            <select name="project_type" class="reg-input reg-select" required>
                                                <option value="Web Application" <?php echo (isset($project_data) && $project_data['project_type'] === 'Web Application') ? 'selected' : ''; ?>>Web Application</option>
                                                <option value="Mobile App" <?php echo (isset($project_data) && $project_data['project_type'] === 'Mobile App') ? 'selected' : ''; ?>>Mobile App</option>
                                                <option value="AI/ML Project" <?php echo (isset($project_data) && $project_data['project_type'] === 'AI/ML Project') ? 'selected' : ''; ?>>AI/ML Project</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="reg-step-group">
                        <div class="reg-step-indicator">
                            <div class="reg-step-number">02</div>
                            <div class="reg-step-line"></div>
                        </div>
                        <div class="reg-step-content">
                            <h2 class="reg-step-title">Team Members</h2>
                            <div class="premium-glass-card">
                                <div id="team_member_list" style="display: flex; flex-direction: column; gap: 20px;">
                                    <div class="team-member-row" style="display: flex; align-items: center; gap: 16px;">
                                        <div style="width: 32px; height: 32px; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #818CF8;">01</div>
                                        <div class="reg-input-wrapper no-arrow" style="flex: 1;">
                                            <input type="text" class="reg-input" value="<?php echo htmlspecialchars($user_name); ?> (Leader)" readonly style="background: rgba(255, 255, 255, 0.02); color: rgba(255, 255, 255, 0.4); font-weight: 600;">
                                        </div>
                                        <div style="width: 80px; display: flex; justify-content: flex-end;"></div> <!-- Fixed width spacer -->
                                    </div>
                                    
                                    <?php
                                    $member02_val = $edit_members[0] ?? null;
                                    $member03_val = $edit_members[1] ?? null;
                                    $member04_val = $edit_members[2] ?? null;
                                    ?>
                                    
                                    <div class="team-member-row" id="member_row_2" style="display: flex; align-items: center; gap: 16px;">
                                        <div style="width: 32px; height: 32px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--reg-text-muted);">02</div>
                                        <div class="reg-input-wrapper" style="flex: 1;">
                                            <select name="member02" class="reg-input reg-select">
                                                <option value="">Select Member 02</option>
                                                <?php foreach ($students as $s): ?>
                                                    <option value="<?php echo $s['id']; ?>" <?php echo ($member02_val == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name'] . " | " . $s['enrollment_no']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div style="width: 80px; display: flex; justify-content: flex-end;">
                                            <button type="button" class="btn-add-member" onclick="showNextMember(3)" id="add_btn_2" style="display: <?php echo empty($member03_val) ? 'flex' : 'none'; ?>;">
                                                <i class="fa-solid fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="team-member-row <?php echo empty($member03_val) ? 'member-slot-hidden' : ''; ?>" id="member_row_3" style="display: flex; align-items: center; gap: 16px;">
                                        <div style="width: 32px; height: 32px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--reg-text-muted);">03</div>
                                        <div class="reg-input-wrapper" style="flex: 1;">
                                            <select name="member03" id="member_select_3" class="reg-input reg-select">
                                                <option value="">Select Member 03</option>
                                                <?php foreach ($students as $s): ?>
                                                    <option value="<?php echo $s['id']; ?>" <?php echo ($member03_val == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name'] . " | " . $s['enrollment_no']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div style="width: 80px; display: flex; gap: 8px; justify-content: flex-end;">
                                            <button type="button" class="btn-add-member" onclick="showNextMember(4)" id="add_btn_3" style="display: <?php echo empty($member04_val) ? 'flex' : 'none'; ?>;">
                                                <i class="fa-solid fa-plus"></i>
                                            </button>
                                            <button type="button" class="btn-remove-member" onclick="cancelMember(3)" title="Remove Member">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="team-member-row <?php echo empty($member04_val) ? 'member-slot-hidden' : ''; ?>" id="member_row_4" style="display: flex; align-items: center; gap: 16px;">
                                        <div style="width: 32px; height: 32px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--reg-text-muted);">04</div>
                                        <div class="reg-input-wrapper" style="flex: 1;">
                                            <select name="member04" id="member_select_4" class="reg-input reg-select">
                                                <option value="">Select Member 04</option>
                                                <?php foreach ($students as $s): ?>
                                                    <option value="<?php echo $s['id']; ?>" <?php echo ($member04_val == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name'] . " | " . $s['enrollment_no']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div style="width: 80px; display: flex; justify-content: flex-end;">
                                            <button type="button" class="btn-remove-member" onclick="cancelMember(4)" title="Remove Member">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="reg-step-group">
                        <div class="reg-step-indicator">
                            <div class="reg-step-number">03</div>
                            <div class="reg-step-line"></div>
                        </div>
                        <div class="reg-step-content">
                            <h2 class="reg-step-title">Project Details</h2>
                            <div class="premium-glass-card">
                                <label class="reg-field-label">Technologies You'll Use</label>
                                <div style="display: flex; gap: 12px; margin-bottom: 24px;">
                                    <div class="reg-input-wrapper" style="width: 200px;">
                                        <select id="tech_category" class="reg-input reg-select">
                                            <option value="Frontend">Frontend</option>
                                            <option value="Backend">Backend</option>
                                            <option value="Database">Database</option>
                                        </select>
                                    </div>
                                    <div class="reg-input-wrapper no-arrow" style="flex: 1;">
                                        <input type="text" id="tech_input" class="reg-input" placeholder="Add technology..." style="height: 52px; box-sizing: border-box;">
                                    </div>
                                    <button type="button" id="add_tech_btn" class="btn-reg-submit" style="width: auto; height: 52px; border-radius: 12px; padding: 0 24px; font-size: 14px; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; margin: 0;">Add</button>
                                </div>

                                <div class="reg-tech-grid">
                                    <div class="reg-tech-card">
                                        <div class="reg-tech-card-title"><i class="fa-solid fa-code"></i> Frontend</div>
                                        <div id="tech-list-Frontend"><span class="reg-tech-empty">Empty</span></div>
                                    </div>
                                    <div class="reg-tech-card">
                                        <div class="reg-tech-card-title"><i class="fa-solid fa-server"></i> Backend</div>
                                        <div id="tech-list-Backend"><span class="reg-tech-empty">Empty</span></div>
                                    </div>
                                    <div class="reg-tech-card">
                                        <div class="reg-tech-card-title"><i class="fa-solid fa-database"></i> Database</div>
                                        <div id="tech-list-Database"><span class="reg-tech-empty">Empty</span></div>
                                    </div>
                                </div>

                                <div style="margin-top: 32px;">
                                    <label class="reg-field-label">Project Summary</label>
                                    <div class="reg-input-wrapper no-arrow">
                                        <textarea name="description" class="reg-input" style="height: 120px; resize: none;" placeholder="Explain what your project is about..." required><?php echo isset($project_data) ? htmlspecialchars($project_data['description']) : ''; ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="reg-footer">
                    <button type="button" class="btn-reg-cancel" onclick="window.location.href='student_dashboard.php'">Cancel</button>
                    <button type="submit" class="btn-reg-submit"><?php echo $edit_id ? 'Re-Submit Proposal' : 'Submit Project'; ?> <i class="fa-solid fa-arrow-right"></i></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const technologies = <?php echo isset($project_data) ? $project_data['technologies'] : '{"Frontend": [], "Backend": [], "Database": []}'; ?>;

        function showNextMember(num) {
            const row = document.getElementById(`member_row_${num}`);
            if (row) {
                row.classList.remove('member-slot-hidden');
                // Hide the plus button on the previous row
                const prevBtn = document.getElementById(`add_btn_${num - 1}`);
                if (prevBtn) prevBtn.style.display = 'none';
            }
        }

        function cancelMember(num) {
            const row = document.getElementById(`member_row_${num}`);
            const select = document.getElementById(`member_select_${num}`);
            if (row) {
                row.classList.add('member-slot-hidden');
                if (select) select.value = '';
                
                const prevBtn = document.getElementById(`add_btn_${num - 1}`);
                if (prevBtn) prevBtn.style.display = 'flex';

                if (num === 3) {
                    cancelMember(4);
                }
            }
        }

        document.getElementById('add_tech_btn').addEventListener('click', () => {
            const cat = document.getElementById('tech_category').value;
            const val = document.getElementById('tech_input').value.trim();
            if (val && !technologies[cat].includes(val)) {
                technologies[cat].push(val);
                updateTechUI();
                document.getElementById('tech_input').value = '';
            }
        });

        // Prevent Enter key from submitting the form when typing in the tech input
        document.getElementById('tech_input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('add_tech_btn').click();
            }
        });

        function updateTechUI() {
            for (const cat in technologies) {
                const container = document.getElementById(`tech-list-${cat}`);
                if (!container) continue;
                container.innerHTML = '';
                if (technologies[cat].length === 0) {
                    container.innerHTML = '<span class="reg-tech-empty">Empty</span>';
                } else {
                    technologies[cat].forEach(t => {
                        const tag = document.createElement('div');
                        tag.className = "tech-tag";
                        tag.style = "margin: 4px;";
                        tag.innerHTML = `${t} <i class="fa-solid fa-xmark" onclick="removeTech('${cat}', '${t}')"></i>`;
                        container.appendChild(tag);
                    });
                }
            }
            document.getElementById('technologies_json').value = JSON.stringify(technologies);
        }

        window.removeTech = (cat, val) => {
            technologies[cat] = technologies[cat].filter(t => t !== val);
            updateTechUI();
        };

        // Department Change Logic to filter Mentor Dropdown
        document.addEventListener('DOMContentLoaded', () => {
            updateTechUI();

            const departmentSelect = document.getElementById('department_select');
            const mentorSelect = document.querySelector('select[name="mentor_id"]');
            const allMentors = <?php echo json_encode($mentors); ?>;
            
            if (departmentSelect && mentorSelect) {
                departmentSelect.addEventListener('change', () => {
                    const selectedDept = departmentSelect.value;
                    mentorSelect.innerHTML = '<option value="">Choose Mentor</option>';
                    
                    if (selectedDept) {
                        const filteredMentors = allMentors.filter(m => m.department === selectedDept);
                        filteredMentors.forEach(m => {
                            const opt = document.createElement('option');
                            opt.value = m.id;
                            opt.textContent = m.name;
                            mentorSelect.appendChild(opt);
                        });
                    }
                    
                    // Re-render the custom UI since the native select options changed
                    if (mentorSelect._renderCustomOptions) {
                        mentorSelect._renderCustomOptions();
                    }
                });

                // Auto-select mentor if passed in URL
                const urlParams = new URLSearchParams(window.location.search);
                const preMentorId = urlParams.get('mentor_id');
                const preDept = urlParams.get('department');

                if (preDept && preMentorId) {
                    departmentSelect.value = preDept;
                    if (departmentSelect._renderCustomOptions) {
                        departmentSelect._renderCustomOptions();
                    }
                    // Trigger change event to populate mentors
                    departmentSelect.dispatchEvent(new Event('change'));
                    
                    // After mentors are populated, set the selected mentor
                    setTimeout(() => {
                        mentorSelect.value = preMentorId;
                        if (mentorSelect._renderCustomOptions) {
                            mentorSelect._renderCustomOptions();
                        }
                    }, 50);
                }

                <?php if ($edit_id && isset($project_data)): ?>
                // For edit mode, programmatically set the values to trigger filtration
                const editDept = <?php echo json_encode($project_data['department']); ?>;
                const editMentorId = <?php echo json_encode($project_data['mentor_id']); ?>;
                
                if (editDept && editMentorId) {
                    departmentSelect.value = editDept;
                    if (departmentSelect._renderCustomOptions) {
                        departmentSelect._renderCustomOptions();
                    }
                    departmentSelect.dispatchEvent(new Event('change'));
                    
                    // Delay setting mentor select so change event finishes execution
                    setTimeout(() => {
                        mentorSelect.value = editMentorId;
                        if (mentorSelect._renderCustomOptions) {
                            mentorSelect._renderCustomOptions();
                        }
                    }, 50);
                }
                <?php endif; ?>
            }
        });

        // Dropdown Toggle Logic
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

        // Close dropdowns if clicking outside
        document.addEventListener('click', function(event) {
            const isClickInside = event.target.closest('.notification-wrapper') || event.target.closest('.profile-wrapper');
            if (!isClickInside) {
                document.querySelectorAll('.glass-dropdown').forEach(d => {
                    d.classList.remove('show');
                });
            }
        });
    </script>
    <script src="js/premium-select.js?v=<?php echo time(); ?>"></script>
</body>
</html>
