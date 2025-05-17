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

// Define session namespaces
if (!isset($_SESSION['admin'])) {
    $_SESSION['admin'] = [];
}

if (!isset($_SESSION['student'])) {
    $_SESSION['student'] = [];
}

// Function to register a user session in the database
function registerUserSession($user_id, $role) {
    global $conn;
    
    if (!isset($conn)) {
        require_once __DIR__ . '/db.php';
    }
    
    try {
        // First invalidate any existing sessions for this specific user and role
        $stmt = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ? AND role = ?");
        $stmt->bind_param("is", $user_id, $role);
        $success = $stmt->execute();
        $stmt->close();
        
        if (!$success) {
            error_log("Failed to delete existing sessions for user $user_id with role $role: " . $conn->error);
        }
        
        // Now register the new session
        $session_id = session_id();
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        
        error_log("Registering session with ID: $session_id for user $user_id with role $role");
        
        $stmt = $conn->prepare("INSERT INTO user_sessions (user_id, role, session_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $user_id, $role, $session_id, $ip_address, $user_agent);
        $success = $stmt->execute();
        $stmt->close();
        
        if (!$success) {
            error_log("Failed to register new session for user $user_id with role $role: " . $conn->error);
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Exception in registerUserSession: " . $e->getMessage());
        return false;
    }
}

// Function to validate if a session is the only active one for the user in a specific role
function validateUniqueSession($user_id, $role) {
    global $conn;
    
    if (!isset($conn)) {
        require_once __DIR__ . '/db.php';
    }
    
    $session_id = session_id();
    
    // Debug for session validation
    error_log("Validating session for user ID: $user_id, role: $role, session_id: $session_id");
    
    // Check if this session ID is valid for this specific user_id and role
    $stmt = $conn->prepare("SELECT * FROM user_sessions WHERE user_id = ? AND role = ? AND session_id = ?");
    $stmt->bind_param("iss", $user_id, $role, $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $is_valid = $result->num_rows > 0;
    $stmt->close();
    
    // Log the result of the validation
    if ($is_valid) {
        error_log("Session validation successful for user ID: $user_id, role: $role");
    } else {
        // Try to discover why it's not valid - check for any sessions for this user+role
        $stmt = $conn->prepare("SELECT session_id FROM user_sessions WHERE user_id = ? AND role = ?");
        $stmt->bind_param("is", $user_id, $role);
        $stmt->execute();
        $alt_result = $stmt->get_result();
        
        if ($alt_result->num_rows > 0) {
            $row = $alt_result->fetch_assoc();
            error_log("Found different session for user ID: $user_id, role: $role: " . $row['session_id'] . " vs current: $session_id");
        } else {
            error_log("No sessions found at all for user ID: $user_id, role: $role");
        }
        $stmt->close();
    }
    
    // Update last activity timestamp
    if ($is_valid) {
        $stmt = $conn->prepare("UPDATE user_sessions SET last_activity = CURRENT_TIMESTAMP WHERE session_id = ? AND user_id = ? AND role = ?");
        $stmt->bind_param("sis", $session_id, $user_id, $role);
        $stmt->execute();
        $stmt->close();
    }
    
    return $is_valid;
}

// Function to destroy a user's session by user ID
function destroyUserSession($user_id, $role) {
    global $conn;
    
    if (!isset($conn)) {
        require_once __DIR__ . '/db.php';
    }
    
    $stmt = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ? AND role = ?");
    $stmt->bind_param("is", $user_id, $role);
    $stmt->execute();
    $stmt->close();
}

// Function to check admin session with unique session validation
function isAdminSessionValid() {
    $is_valid = isset($_SESSION['admin']['user_id']) && 
               isset($_SESSION['admin']['role']) && 
               $_SESSION['admin']['role'] === 'admin';
               
    if ($is_valid) {
        // Log for debugging
        error_log("Checking admin session validity for admin ID: " . $_SESSION['admin']['user_id']);
        
        // Also check if this is the only active session for this admin
        $is_valid = validateUniqueSession($_SESSION['admin']['user_id'], 'admin');
        
        if (!$is_valid) {
            // Log the invalidation
            error_log("Admin session invalidated for admin ID: " . $_SESSION['admin']['user_id']);
            // Only clear admin session data, not entire session
            $_SESSION['admin'] = [];
        } else {
            error_log("Admin session validated successfully");
        }
    }
    
    return $is_valid;
}

// Function to check student session with unique session validation
function isStudentSessionValid() {
    $is_valid = isset($_SESSION['student']['user_id']) && 
               isset($_SESSION['student']['role']) && 
               $_SESSION['student']['role'] === 'student';
               
    if ($is_valid) {
        // Log for debugging
        error_log("Checking student session validity for user ID: " . $_SESSION['student']['user_id']);
        
        // Also check if this is the only active session for this student
        $is_valid = validateUniqueSession($_SESSION['student']['user_id'], 'student');
        
        if (!$is_valid) {
            // Log the invalidation
            error_log("Student session invalidated for user ID: " . $_SESSION['student']['user_id']);
            // Only clear student session data, not entire session
            $_SESSION['student'] = [];
        } else {
            error_log("Student session validated successfully");
        }
    }
    
    return $is_valid;
}

// Function to regenerate session ID periodically
function regenerateSessionId() {
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
        return;
    }
    
    // Regenerate session ID every 30 minutes
    if (time() - $_SESSION['last_regeneration'] > 1800) {
        // Store the old session ID
        $old_session_id = session_id();
        
        // Log the regeneration
        error_log("Regenerating session ID from: $old_session_id");
        
        // Generate new session ID
        session_regenerate_id(true);
        $new_session_id = session_id();
        
        error_log("New session ID: $new_session_id");
        
        // Update the session ID in the database for all user roles using this session
        global $conn;
        
        if (!isset($conn)) {
            require_once __DIR__ . '/db.php';
        }
        
        // Update all roles that might be using this session
        $stmt = $conn->prepare("UPDATE user_sessions SET session_id = ? WHERE session_id = ?");
        $stmt->bind_param("ss", $new_session_id, $old_session_id);
        $result = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        error_log("Updated $affected session records with new session ID");
        
        // Reregister sessions for active roles to ensure they stay valid
        if (isset($_SESSION['student']['user_id']) && isset($_SESSION['student']['role'])) {
            registerUserSession($_SESSION['student']['user_id'], 'student');
            error_log("Re-registered student session after ID regeneration");
        }
        
        if (isset($_SESSION['admin']['user_id']) && isset($_SESSION['admin']['role'])) {
            registerUserSession($_SESSION['admin']['user_id'], 'admin');
            error_log("Re-registered admin session after ID regeneration");
        }
        
        $_SESSION['last_regeneration'] = time();
    }
}

// Clean up old sessions (older than 24 hours)
function cleanupOldSessions() {
    global $conn;
    
    if (!isset($conn)) {
        require_once __DIR__ . '/db.php';
    }
    
    // Delete sessions that haven't been active for 24 hours
    $stmt = $conn->prepare("DELETE FROM user_sessions WHERE last_activity < (NOW() - INTERVAL 24 HOUR)");
    $stmt->execute();
    $stmt->close();
}

// Call session maintenance functions
regenerateSessionId();

// Clean up old sessions (run occasionally to avoid overhead)
if (mt_rand(1, 100) <= 5) { // 5% chance to run on each page load
    cleanupOldSessions();
}
?> 