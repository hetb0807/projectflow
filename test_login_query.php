<?php
require_once 'includes/db.php';

$stmt = $pdo->query("SELECT * FROM project_members");
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

print_r($members);

if (count($members) > 0) {
    $user_id = $members[0]['student_id'];
    echo "Testing user_id = $user_id\n";
    $check_proj = $pdo->prepare("SELECT COUNT(*) as cnt FROM projects p LEFT JOIN project_members pm ON p.id = pm.project_id WHERE (p.student_id = ? OR pm.student_id = ?) AND (p.status = 'Pending' OR p.status = 'Approved')");
    $check_proj->execute([$user_id, $user_id]);
    $cnt = $check_proj->fetch()['cnt'];
    echo "Count is: " . $cnt . "\n";
} else {
    echo "No team members found in DB to test.\n";
}
?>
