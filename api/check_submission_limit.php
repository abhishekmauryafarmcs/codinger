<?php
header('Content-Type: application/json');
session_start();
require_once '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['canSubmit' => false, 'message' => 'User not logged in']);
    exit();
}

// Get problem ID from request
if (!isset($_GET['problem_id'])) {
    echo json_encode(['canSubmit' => false, 'message' => 'Problem ID is required']);
    exit();
}

$problem_id = intval($_GET['problem_id']);
$user_id = $_SESSION['user_id'];

// First, get the contest ID for this problem to check the max submissions setting
$stmt = $conn->prepare("SELECT contest_id FROM problems WHERE id = ?");
$stmt->bind_param("i", $problem_id);
$stmt->execute();
$result = $stmt->get_result();
$problem = $result->fetch_assoc();
$stmt->close();

// If problem doesn't exist
if (!$problem) {
    echo json_encode(['canSubmit' => false, 'message' => 'Problem not found']);
    exit();
}

$contest_id = $problem['contest_id'];

// Get the max_submissions setting for this contest
$stmt = $conn->prepare("SELECT max_submissions FROM contests WHERE id = ?");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$contest = $result->fetch_assoc();
$stmt->close();

// If contest doesn't exist (shouldn't happen)
if (!$contest) {
    echo json_encode(['canSubmit' => false, 'message' => 'Contest not found']);
    exit();
}

$max_submissions = intval($contest['max_submissions']);

// If max_submissions is 0, it means unlimited submissions
if ($max_submissions === 0) {
    echo json_encode([
        'canSubmit' => true,
        'message' => 'Unlimited submissions allowed',
        'maxSubmissions' => 0,
        'submissionsUsed' => 0,
        'submissionsRemaining' => -1  // -1 indicates unlimited
    ]);
    exit();
}

// Count how many submissions the user has already made for this problem
$stmt = $conn->prepare("SELECT COUNT(*) AS submission_count FROM submissions WHERE user_id = ? AND problem_id = ?");
$stmt->bind_param("ii", $user_id, $problem_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();

$submissions_used = intval($data['submission_count']);
$submissions_remaining = $max_submissions - $submissions_used;

// Check if user has reached the limit
if ($submissions_used >= $max_submissions) {
    echo json_encode([
        'canSubmit' => false,
        'message' => "You have reached the maximum limit of $max_submissions submissions for this problem",
        'maxSubmissions' => $max_submissions,
        'submissionsUsed' => $submissions_used,
        'submissionsRemaining' => 0
    ]);
    exit();
}

// User can submit
echo json_encode([
    'canSubmit' => true,
    'message' => "You can submit. You have used $submissions_used out of $max_submissions allowed submissions",
    'maxSubmissions' => $max_submissions,
    'submissionsUsed' => $submissions_used,
    'submissionsRemaining' => $submissions_remaining
]); 