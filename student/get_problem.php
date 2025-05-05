<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Check if problem ID is provided
if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Problem ID is required']);
    exit();
}

$problem_id = $_GET['id'];

// Get problem details
$stmt = $conn->prepare("
    SELECT p.*, c.start_time, c.end_time 
    FROM problems p 
    JOIN contests c ON p.contest_id = c.id 
    WHERE p.id = ?
");
$stmt->bind_param("i", $problem_id);
$stmt->execute();
$result = $stmt->get_result();
$problem = $result->fetch_assoc();

if (!$problem) {
    http_response_code(404);
    echo json_encode(['error' => 'Problem not found']);
    exit();
}

// Check if contest is ongoing
$now = new DateTime();
$start = new DateTime($problem['start_time']);
$end = new DateTime($problem['end_time']);

if ($now < $start) {
    http_response_code(403);
    echo json_encode(['error' => 'Contest has not started yet']);
    exit();
}

if ($now > $end) {
    http_response_code(403);
    echo json_encode(['error' => 'Contest has ended']);
    exit();
}

// Remove sensitive information
unset($problem['start_time']);
unset($problem['end_time']);

// Set response headers
header('Content-Type: application/json');
echo json_encode($problem); 