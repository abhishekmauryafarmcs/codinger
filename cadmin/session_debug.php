<?php
// Debug file to check session status
header('Content-Type: text/plain');
require_once '../config/session.php';

echo "Session Debug Tool\n";
echo "=================\n\n";

echo "Session ID: " . session_id() . "\n\n";

echo "SESSION Data:\n";
echo "--------------\n";
print_r($_SESSION);
echo "\n";

echo "Cookie Data:\n";
echo "------------\n";
print_r($_COOKIE);
echo "\n";

echo "Admin Session Valid: " . (isAdminSessionValid() ? "YES" : "NO") . "\n";
echo "Student Session Valid: " . (isStudentSessionValid() ? "YES" : "NO") . "\n\n";

echo "Request Info:\n";
echo "------------\n";
echo "Request URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n";

// Create fix link
echo "\n\nTroubleshooting:\n";
echo "---------------\n";
echo "To fix your session, you can try:\n";
echo "1. Visit: http://localhost/codinger/cadmin/login_direct.php\n";
echo "2. Or clear your browser cookies and try again\n";
?> 