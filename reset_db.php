<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Only allow Admin to reset the database
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access. Only administrators can reset the database.");
}

$sql = file_get_contents('database.sql');

try {
    // Split SQL by semicolon, but handle potential issues with triggers/functions if they existed
    // For this project, simple split is fine as database.sql is simple
    $queries = explode(';', $sql);
    
    $pdo->beginTransaction();
    foreach ($queries as $query) {
        $q = trim($query);
        if (!empty($q)) {
            $pdo->exec($q);
        }
    }
    $pdo->commit();
    
    echo "Database reset successfully! All tables have been dropped and recreated from database.sql.";
    echo "<br><br><a href='admin_dashboard.php'>Return to Dashboard</a>";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error resetting database: " . $e->getMessage();
}
?>
