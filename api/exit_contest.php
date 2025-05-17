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
    
    // Check if the contest exists and user is enrolled
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
    
    // Record the student's exit in the database
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
                (user_id, contest_id, exit_time) 
                VALUES (?, ?, NOW())
            ");
            $stmt->bind_param("ii", $user_id, $contest_id);
            $stmt->execute();
        } else {
            // Update existing entry
            $stmt = $conn->prepare("
                UPDATE contest_exits 
                SET exit_time = NOW() 
                WHERE user_id = ? AND contest_id = ?
            ");
            $stmt->bind_param("ii", $user_id, $contest_id);
            $stmt->execute();
        }
        
        // Also mark this in the session
        $_SESSION['student']['exited_contests'][$contest_id] = true;
        
        echo json_encode(["success" => true, "message" => "Successfully exited the contest"]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
    }
} else {
    // Return error for non-POST requests
    echo json_encode(["success" => false, "error" => "Invalid request method"]);
}
?> 