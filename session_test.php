<?php
// Session diagnostic tool
header('Content-Type: text/plain');
require_once 'config/session.php';
require_once 'config/db.php';

echo "Session Diagnostic Tool\n";
echo "=====================\n\n";

echo "Current Session ID: " . session_id() . "\n\n";

echo "Admin Session Data:\n";
echo "-----------------\n";
if (isset($_SESSION['admin']) && !empty($_SESSION['admin'])) {
    echo "Admin ID: " . ($_SESSION['admin']['user_id'] ?? 'Not set') . "\n";
    echo "Admin Role: " . ($_SESSION['admin']['role'] ?? 'Not set') . "\n";
    echo "Admin Name: " . ($_SESSION['admin']['full_name'] ?? 'Not set') . "\n";
    echo "Admin Session Valid: " . (isAdminSessionValid() ? "YES" : "NO") . "\n";
} else {
    echo "No admin session data\n";
}
echo "\n";

echo "Student Session Data:\n";
echo "-------------------\n";
if (isset($_SESSION['student']) && !empty($_SESSION['student'])) {
    echo "Student ID: " . ($_SESSION['student']['user_id'] ?? 'Not set') . "\n";
    echo "Student Role: " . ($_SESSION['student']['role'] ?? 'Not set') . "\n";
    echo "Student Name: " . ($_SESSION['student']['full_name'] ?? 'Not set') . "\n";
    echo "Student Enrollment: " . ($_SESSION['student']['enrollment_number'] ?? 'Not set') . "\n";
    echo "Student Session Valid: " . (isStudentSessionValid() ? "YES" : "NO") . "\n";
} else {
    echo "No student session data\n";
}
echo "\n";

echo "Database Session Records:\n";
echo "----------------------\n";
$session_id = session_id();
$stmt = $conn->prepare("SELECT * FROM user_sessions WHERE session_id = ?");
$stmt->bind_param("s", $session_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "Found " . $result->num_rows . " session records:\n";
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . "\n";
        echo "  User ID: " . $row['user_id'] . "\n";
        echo "  Role: " . $row['role'] . "\n";
        echo "  Session ID: " . $row['session_id'] . "\n";
        echo "  Last Activity: " . $row['last_activity'] . "\n";
        echo "  Created: " . $row['created_at'] . "\n";
        echo "  IP: " . $row['ip_address'] . "\n";
        echo "\n";
    }
} else {
    echo "No database records found for this session ID\n";
}
$stmt->close();

echo "\nVerify Session Table Structure:\n";
echo "--------------------------\n";
$stmt = $conn->prepare("SHOW KEYS FROM user_sessions");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "Found " . $result->num_rows . " keys on user_sessions table:\n";
    while ($row = $result->fetch_assoc()) {
        echo "Key Name: " . $row['Key_name'] . "\n";
        echo "  Column: " . $row['Column_name'] . "\n";
        echo "  Non Unique: " . $row['Non_unique'] . "\n";
        echo "\n";
    }
} else {
    echo "No keys found on user_sessions table\n";
}
$stmt->close();

echo "\nDatabase Connection Status: " . ($conn->connect_error ? "Error: " . $conn->connect_error : "Connected") . "\n";
echo "Session Cookie Parameters: \n";
print_r(session_get_cookie_params());

?> 