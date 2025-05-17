<?php
// Ensure session is started (config/session.php will handle this if not already started)
require_once 'config/session.php'; // This already calls session_start() if needed

// Log which user is logging out, if any
if (isAdminSessionValid()) {
    error_log("Admin logging out - ID: " . ($_SESSION['admin']['user_id'] ?? 'N/A'));
} elseif (isStudentSessionValid()) {
    error_log("Student logging out - ID: " . ($_SESSION['student']['user_id'] ?? 'N/A'));
} else {
    error_log("User logging out - No specific admin/student session found.");
}

// 1. Unset all session variables
$_SESSION = array();

// 2. Destroy the session
session_destroy();

// 3. Clear the session cookie
// This is important to ensure the browser forgets the session.
// The parameters should match how the session cookie was set.
// From config/session.php: path is '/', domain is '', secure is based on HTTPS, httponly is true.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear any other specific cookies if necessary (e.g., 'adminLoggedIn')
// The original code had this, so let's keep it for 'adminLoggedIn'.
if (isset($_COOKIE['adminLoggedIn'])) {
    setcookie('adminLoggedIn', '', time() - 3600, '/codinger/cadmin/'); // Ensure path is correct
}
if (isset($_COOKIE['redirect_attempted'])) { // From original code
    setcookie('redirect_attempted', '', time() - 3600, '/codinger/cadmin/'); // Ensure path is correct
}

// 4. Redirect to the homepage (or login page)
// The original script redirected to index.php which is fine.
header("Location: index.php");
exit();
?> 