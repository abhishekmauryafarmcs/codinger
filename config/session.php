<?php
// First, check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    // Set session configuration before starting the session
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);

    // Set session cookie parameters
    session_set_cookie_params([
        'lifetime' => 0,  // Session cookie (expires when browser closes)
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']), // Only set secure if HTTPS is enabled
        'httponly' => true,  // Prevent JavaScript access
        'samesite' => 'Strict'  // CSRF protection
    ]);

    // Now start the session
    session_start();
}

// Function to check admin session
function isAdminSessionValid() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Function to check student session
function isStudentSessionValid() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'student';
}

// Function to regenerate session ID periodically
function regenerateSessionId() {
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
        return;
    }
    
    // Regenerate session ID every 30 minutes
    if (time() - $_SESSION['last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Call regenerate function
regenerateSessionId();
?> 