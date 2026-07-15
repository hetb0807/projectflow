<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Only allow Admin to export data
requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access. Only administrators can export data.");
}

$type = $_GET['type'] ?? '';
if (!in_array($type, ['users', 'projects', 'documents'])) {
    die("Invalid export type.");
}

$filename = "projectflow_" . $type . "_" . date('Y-m-d') . ".csv";

// Set headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

if ($type === 'users') {
    // Write CSV Headers
    fputcsv($output, ['ID', 'Name', 'Email', 'Enrollment No', 'Role', 'Department', 'Designation', 'Status', 'Registered At']);
    
    // Fetch user data
    $stmt = $pdo->query("SELECT id, name, email, enrollment_no, role, department, designation, is_active, created_at FROM users ORDER BY role, name");
    
    while ($row = $stmt->fetch()) {
        $status = ($row['is_active']) ? 'Active' : 'Disabled';
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['email'],
            $row['enrollment_no'] ?? 'N/A',
            ucfirst($row['role']),
            $row['department'] ?? 'N/A',
            $row['designation'] ?? 'N/A',
            $status,
            $row['created_at']
        ]);
    }
} elseif ($type === 'projects') {
    // Write CSV Headers
    fputcsv($output, ['Project ID', 'Project Title', 'Student Lead', 'Mentor', 'Department', 'Seminar', 'Type', 'Technologies', 'Status', 'Team Members', 'Submissions', 'Created At']);
    
    // Fetch project data
    $stmt = $pdo->query("SELECT p.id, p.project_title, s.name as student_name, m.name as mentor_name, 
                               p.department, p.seminar_name, p.project_type, p.technologies, p.status, p.created_at,
                               (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id = p.id) as member_count,
                               (SELECT COUNT(*) FROM submissions sub WHERE sub.project_id = p.id) as sub_count
                        FROM projects p
                        JOIN users s ON p.student_id = s.id
                        JOIN users m ON p.mentor_id = m.id
                        ORDER BY p.created_at DESC");
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            'PRJ-' . str_pad($row['id'], 3, '0', STR_PAD_LEFT),
            $row['project_title'],
            $row['student_name'],
            $row['mentor_name'],
            $row['department'] ?? 'N/A',
            $row['seminar_name'] ?? 'N/A',
            $row['project_type'] ?? 'N/A',
            $row['technologies'] ?? 'N/A',
            $row['status'],
            $row['member_count'],
            $row['sub_count'],
            $row['created_at']
        ]);
    }
} elseif ($type === 'documents') {
    // Write CSV Headers
    fputcsv($output, ['Submission ID', 'Submission Title', 'Project ID', 'Project Title', 'Submitted By', 'Status', 'Mentor Comment', 'File Path', 'Submitted At']);
    
    // Fetch submission data
    $stmt = $pdo->query("SELECT s.id, s.submission_title, s.file_path, s.status, s.mentor_comment, s.submitted_at, 
                               p.id as project_id, p.project_title, u.name as student_name
                        FROM submissions s
                        JOIN projects p ON s.project_id = p.id
                        JOIN users u ON s.student_id = u.id
                        ORDER BY s.submitted_at DESC");
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['id'],
            $row['submission_title'],
            'PRJ-' . str_pad($row['project_id'], 3, '0', STR_PAD_LEFT),
            $row['project_title'],
            $row['student_name'],
            $row['status'],
            $row['mentor_comment'] ?? 'No comments',
            $row['file_path'],
            $row['submitted_at']
        ]);
    }
}

fclose($output);
exit();
?>
