<?php
// Required headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include database and session handling
require_once '../config/db.php';
require_once '../config/session.php';

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    // Redirect to login page with appropriate error
    header("Location: ../login.php?error=unauthorized");
    exit();
}

// Get user ID from session
$user_id = $_SESSION['student']['user_id'];

// Process only POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get posted data
    if (!isset($_POST['contest_id'])) {
        header("Location: dashboard.php?error=missing_parameters");
        exit();
    }
    
    $contest_id = (int)$_POST['contest_id'];
    $reason = isset($_POST['reason']) ? $_POST['reason'] : 'page_switch_violations';
    $permanent = isset($_POST['permanent']) ? ($_POST['permanent'] === 'true') : true;
    
    // Check if the contest exists
    $stmt = $conn->prepare("
        SELECT id FROM contests WHERE id = ?
    ");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: dashboard.php?error=invalid_contest");
        exit();
    }
    
    // Record the student's exit with termination data in the database
    try {
        // First, check if an entry already exists
        $stmt = $conn->prepare("
            SELECT * FROM contest_exits 
            WHERE user_id = ? AND contest_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $contest_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            // Create a new entry
            $stmt = $conn->prepare("
                INSERT INTO contest_exits 
                (user_id, contest_id, exit_time, reason, is_permanent) 
                VALUES (?, ?, NOW(), ?, ?)
            ");
            $stmt->bind_param("iisi", $user_id, $contest_id, $reason, $permanent);
            $stmt->execute();
        } else {
            // Update existing entry
            $stmt = $conn->prepare("
                UPDATE contest_exits 
                SET exit_time = NOW(), reason = ?, is_permanent = ? 
                WHERE user_id = ? AND contest_id = ?
            ");
            $stmt->bind_param("siii", $reason, $permanent, $user_id, $contest_id);
            $stmt->execute();
        }
        
        // Also mark this in the session
        if (!isset($_SESSION['student']['exited_contests'])) {
            $_SESSION['student']['exited_contests'] = array();
        }
        $_SESSION['student']['exited_contests'][$contest_id] = true;
        
        // If this is a permanent termination, also record it in a separate session variable
        if ($permanent) {
            if (!isset($_SESSION['student']['terminated_contests'])) {
                $_SESSION['student']['terminated_contests'] = array();
            }
            $_SESSION['student']['terminated_contests'][$contest_id] = [
                'reason' => $reason,
                'time' => time()
            ];
        }
        
        // Create a user-friendly message based on the reason code
        $contestName = "The contest"; // Default contest name
        $customMessage = "";
        
        switch($reason) {
            case 'fullscreen_violation':
                $customMessage = "Your access to the contest has been permanently revoked due to fullscreen mode violations.";
                break;
            case 'developer_tools':
                $customMessage = "Your access to the contest has been permanently revoked due to the use of developer tools.";
                break;
            case 'page_switch_violations':
                $customMessage = "Your access to the contest has been permanently revoked due to excessive page switches.";
                break;
            default:
                $customMessage = "Your access to the contest has been permanently revoked due to contest rule violations.";
        }
        
        // Redirect with appropriate message
        header("Location: dashboard.php?error=contest_terminated&reason={$reason}&message=" . urlencode($customMessage));
        exit();
    } catch (Exception $e) {
        header("Location: dashboard.php?error=database_error&message=" . urlencode("An error occurred while recording contest termination: " . $e->getMessage()));
        exit();
    }
} else {
    // Return error for non-POST requests
    header("Location: dashboard.php?error=invalid_request");
    exit();
}
?> 