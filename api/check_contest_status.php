<?php
// Required headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include database and session handling
require_once '../config/db.php';
require_once '../config/session.php';

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    echo json_encode([
        "success" => false, 
        "error" => "Unauthorized access",
        "terminated" => false
    ]);
    exit();
}

// Get user ID from session
$user_id = $_SESSION['student']['user_id'];

// Process only GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if contest_id is provided
    if (!isset($_GET['contest_id'])) {
        echo json_encode([
            "success" => false, 
            "error" => "Missing contest ID parameter",
            "terminated" => false
        ]);
        exit();
    }
    
    $contest_id = (int)$_GET['contest_id'];
    
    // First check session for faster response
    if (isset($_SESSION['student']['terminated_contests']) && 
        isset($_SESSION['student']['terminated_contests'][$contest_id])) {
        echo json_encode([
            "success" => true,
            "terminated" => true,
            "reason" => $_SESSION['student']['terminated_contests'][$contest_id]['reason'],
            "time" => $_SESSION['student']['terminated_contests'][$contest_id]['time'],
            "source" => "session"
        ]);
        exit();
    }
    
    // Check the database for permanent termination record
    try {
        $stmt = $conn->prepare("
            SELECT reason, exit_time, is_permanent 
            FROM contest_exits 
            WHERE user_id = ? AND contest_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $contest_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $exit_data = $result->fetch_assoc();
            
            // Check if this is a permanent termination
            if ($exit_data['is_permanent']) {
                // Store in session for future checks
                if (!isset($_SESSION['student']['terminated_contests'])) {
                    $_SESSION['student']['terminated_contests'] = array();
                }
                $_SESSION['student']['terminated_contests'][$contest_id] = [
                    'reason' => $exit_data['reason'],
                    'time' => strtotime($exit_data['exit_time'])
                ];
                
                echo json_encode([
                    "success" => true,
                    "terminated" => true,
                    "reason" => $exit_data['reason'],
                    "time" => strtotime($exit_data['exit_time']),
                    "source" => "database"
                ]);
                exit();
            }
        }
        
        // If we get here, no termination record was found
        echo json_encode([
            "success" => true,
            "terminated" => false,
            "message" => "No termination record found"
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false, 
            "error" => "Database error: " . $e->getMessage(),
            "terminated" => false
        ]);
    }
} else {
    // Return error for non-GET requests
    echo json_encode([
        "success" => false, 
        "error" => "Invalid request method",
        "terminated" => false
    ]);
}
?> 