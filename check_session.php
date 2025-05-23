<?php
require_once 'config/session.php';
session_start();

echo "<h1>Session Status Check</h1>";
echo "<pre>";

// Check if session is active
echo "Session active: " . (session_status() === PHP_SESSION_ACTIVE ? "Yes" : "No") . "\n";

// Check session ID
echo "Session ID: " . session_id() . "\n";

// Check student session
echo "\nStudent Session Data:\n";
if (isset($_SESSION['student'])) {
    print_r($_SESSION['student']);
} else {
    echo "No student session data found\n";
}

// Check if the session validation function exists
echo "\nSession Validation Function:\n";
if (function_exists('isStudentSessionValid')) {
    echo "isStudentSessionValid() exists\n";
    echo "Session is " . (isStudentSessionValid() ? "valid" : "invalid") . "\n";
} else {
    echo "isStudentSessionValid() function not found\n";
}

// Check session configuration
echo "\nSession Configuration:\n";
echo "session.save_handler: " . ini_get('session.save_handler') . "\n";
echo "session.save_path: " . ini_get('session.save_path') . "\n";
echo "session.gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . "\n";
echo "session.cookie_lifetime: " . ini_get('session.cookie_lifetime') . "\n";

echo "</pre>";
?> 