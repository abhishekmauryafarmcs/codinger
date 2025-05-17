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
    echo json_encode(["success" => false, "error" => "Unauthorized access"]);
    exit();
}

// Get user ID from session
$user_id = $_SESSION['student']['user_id'];

// Process only POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get posted data
    $data = json_decode(file_get_contents("php://input"));
    
    // Check if all required fields are present
    if (!isset($data->contest_id)) {
        echo json_encode(["success" => false, "error" => "Missing required parameters"]);
        exit();
    }
    
    $contest_id = (int)$data->contest_id;
    $reason = isset($data->reason) ? $data->reason : 'page_switch_violations';
    $permanent = isset($data->permanent) && $data->permanent === true;
    
    // Check if the contest exists
    $stmt = $conn->prepare("
        SELECT id FROM contests WHERE id = ?
    ");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(["success" => false, "error" => "Contest not found"]);
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
        
        echo json_encode([
            "success" => true, 
            "message" => "Successfully recorded contest termination",
            "permanent" => $permanent
        ]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
    }
} else {
    // Return error for non-POST requests
    echo json_encode(["success" => false, "error" => "Invalid request method"]);
}
?> 