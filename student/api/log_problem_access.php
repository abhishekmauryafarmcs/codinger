<?php
require_once '../../config/session.php';
require_once '../../config/db.php';

// Set headers for JSON response
header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    echo json_encode(['error' => 'Invalid session']);
    exit();
}

// Check if required parameters are provided
if (!isset($_POST['problem_id']) || !isset($_POST['contest_id'])) {
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$problem_id = (int)$_POST['problem_id'];
$contest_id = (int)$_POST['contest_id'];
$user_id = $_SESSION['student']['user_id'];

try {
    // First verify the problem belongs to the contest
    $stmt = $conn->prepare("
        SELECT 1 
        FROM contest_problems 
        WHERE contest_id = ? AND problem_id = ?
    ");
    $stmt->bind_param("ii", $contest_id, $problem_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['error' => 'Invalid problem or contest']);
        exit();
    }

    // Log the access
    $stmt = $conn->prepare("
        INSERT INTO problem_access_logs 
        (user_id, problem_id, contest_id, access_time) 
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE access_count = access_count + 1, last_access = NOW()
    ");
    $stmt->bind_param("iii", $user_id, $problem_id, $contest_id);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Access logged successfully'
    ]);

} catch (Exception $e) {
    error_log("Error logging problem access: " . $e->getMessage());
    echo json_encode([
        'error' => 'Error logging access',
        'details' => $e->getMessage()
    ]);
}
?> 