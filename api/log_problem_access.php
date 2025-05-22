<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Set headers
header('Content-Type: application/json');

// Check if user is logged in as student
if (!isStudentSessionValid()) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit();
}

// Get the JSON data from the request
$data = json_decode(file_get_contents('php://input'), true);

// Check if the required fields are provided
if (!isset($data['problem_id']) || !isset($data['contest_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// Get user ID from session
$user_id = $_SESSION['student']['user_id'];
$problem_id = (int)$data['problem_id'];
$contest_id = (int)$data['contest_id'];

// First check if this is the first time the user is accessing this problem
// We don't want to create duplicate entries for the same problem
$stmt = $conn->prepare("
    SELECT id FROM problem_access_logs
    WHERE user_id = ? AND problem_id = ? AND contest_id = ?
");
$stmt->bind_param("iii", $user_id, $problem_id, $contest_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // This is the first time they're accessing this problem
    // Insert a new record
    $insert_stmt = $conn->prepare("
        INSERT INTO problem_access_logs 
        (user_id, problem_id, contest_id, access_time) 
        VALUES (?, ?, ?, NOW())
    ");
    $insert_stmt->bind_param("iii", $user_id, $problem_id, $contest_id);
    
    if ($insert_stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Problem access logged successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'error' => 'Failed to log problem access: ' . $conn->error
        ]);
    }
    $insert_stmt->close();
} else {
    // They've already accessed this problem before
    echo json_encode([
        'success' => true,
        'message' => 'Problem access already logged'
    ]);
}

$stmt->close();
?> 