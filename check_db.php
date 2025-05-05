<?php
require_once 'config/db.php';

// Check if contest_violations table exists
$stmt = $conn->prepare("SHOW TABLES LIKE 'contest_violations'");
$stmt->execute();
$tableExists = $stmt->get_result()->num_rows > 0;

// If the table doesn't exist, create it
if (!$tableExists) {
    $createTableSQL = "CREATE TABLE contest_violations (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        user_id INT(11) NOT NULL,
        contest_id INT(11) NOT NULL,
        violation_type VARCHAR(100) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX user_contest_idx (user_id, contest_id)
    )";
    $conn->query($createTableSQL);
    echo "Created contest_violations table.<br>";
} else {
    echo "contest_violations table already exists.<br>";
}

// Check if dashboard.php handles termination properly
$file = file_get_contents('student/dashboard.php');
if (strpos($file, 'termination_reason') !== false) {
    echo "dashboard.php has termination handling code.<br>";
} else {
    echo "WARNING: dashboard.php doesn't appear to handle termination properly.<br>";
}

// Check if contest.php sets the session variable
$file = file_get_contents('student/contest.php');
if (strpos($file, '$_SESSION[\'current_contest_id\']') !== false) {
    echo "contest.php properly sets current_contest_id.<br>";
} else {
    echo "WARNING: contest.php doesn't set current_contest_id in session.<br>";
}

// Check if prevent_cheating.js exists and has the right code
$file = file_get_contents('js/prevent_cheating.js');
if (
    strpos($file, 'terminateContest') !== false && 
    strpos($file, 'handlePageSwitchViolation') !== false &&
    strpos($file, 'visibilitychange') !== false
) {
    echo "prevent_cheating.js appears to have correct violation handling code.<br>";
} else {
    echo "WARNING: prevent_cheating.js may not have all required components.<br>";
}

echo "Checks completed. You can now delete this file.";
?> 