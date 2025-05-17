<?php
header('Content-Type: application/json');
session_start();
require_once '../config/db.php';
require_once '../config/session.php';

// Check if user is logged in
if (!isStudentSessionValid()) {
    echo json_encode(['canSubmit' => false, 'message' => 'User not logged in']);
    exit();
}

// Get problem ID from request
if (!isset($_GET['problem_id'])) {
    echo json_encode(['canSubmit' => false, 'message' => 'Problem ID is required']);
    exit();
}

$problem_id = intval($_GET['problem_id']);
$user_id = $_SESSION['student']['user_id'];

// If contest_id is provided directly in the GET parameters, use it
if (isset($_GET['contest_id']) && !empty($_GET['contest_id'])) {
    $contest_id = intval($_GET['contest_id']);
} else {
    // Otherwise, get it from the database
    // First, get the contest ID for this problem from contest_problems table
    $stmt = $conn->prepare("
        SELECT cp.contest_id 
        FROM contest_problems cp
        WHERE cp.problem_id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("i", $problem_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $problem_contest = $result->fetch_assoc();
    $stmt->close();

    // If problem doesn't exist in any contest
    if (!$problem_contest) {
        // Try to get it directly from the problems table for backward compatibility
        $stmt = $conn->prepare("SELECT contest_id FROM problems WHERE id = ?");
        $stmt->bind_param("i", $problem_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $problem = $result->fetch_assoc();
        $stmt->close();
        
        if (!$problem || !$problem['contest_id']) {
            echo json_encode(['canSubmit' => false, 'message' => 'Problem not found in any contest']);
            exit();
        }
        
        $contest_id = $problem['contest_id'];
    } else {
        $contest_id = $problem_contest['contest_id'];
    }
}

// Add a debug logging function
function debug_log($message) {
    $log_file = '../logs/submission_limits.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $message = "[$timestamp] $message\n";
    file_put_contents($log_file, $message, FILE_APPEND);
}

// Log the request parameters and contest/submission details
debug_log("Request: problem_id=$problem_id, user_id=$user_id" . (isset($_GET['contest_id']) ? ", contest_id=" . $_GET['contest_id'] : ""));
debug_log("Contest ID resolved: $contest_id");

// Get the max_submissions setting for this contest
$stmt = $conn->prepare("SELECT max_submissions FROM contests WHERE id = ?");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$contest = $result->fetch_assoc();
$stmt->close();

// If contest doesn't exist (shouldn't happen)
if (!$contest) {
    debug_log("Error: Contest not found for ID: $contest_id");
    echo json_encode(['canSubmit' => false, 'message' => 'Contest not found']);
    exit();
}

$max_submissions = intval($contest['max_submissions']);
debug_log("Contest max_submissions: $max_submissions");

// If max_submissions is 0, it means unlimited submissions
if ($max_submissions === 0) {
    debug_log("Unlimited submissions allowed for this contest");
    echo json_encode([
        'canSubmit' => true,
        'message' => 'Unlimited submissions allowed',
        'maxSubmissions' => 0,
        'submissionsUsed' => 0,
        'submissionsRemaining' => -1  // -1 indicates unlimited
    ]);
    exit();
}

// Count how many submissions the user has already made for this problem in THIS CONTEST
$stmt = $conn->prepare("SELECT COUNT(*) AS submission_count FROM submissions WHERE user_id = ? AND problem_id = ? AND contest_id = ?");
$stmt->bind_param("iii", $user_id, $problem_id, $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();

$submissions_used = intval($data['submission_count']);
$submissions_remaining = $max_submissions - $submissions_used;

debug_log("SQL Query: SELECT COUNT(*) AS submission_count FROM submissions WHERE user_id = $user_id AND problem_id = $problem_id AND contest_id = $contest_id");
debug_log("Submissions used: $submissions_used, Submissions remaining: $submissions_remaining");

// Check if user has reached the limit
if ($submissions_used >= $max_submissions) {
    debug_log("DECISION: Limit reached! User cannot submit (used $submissions_used out of $max_submissions)");
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
debug_log("DECISION: User can submit (used $submissions_used out of $max_submissions)");
echo json_encode([
    'canSubmit' => true,
    'message' => "You can submit. You have used $submissions_used out of $max_submissions allowed submissions",
    'maxSubmissions' => $max_submissions,
    'submissionsUsed' => $submissions_used,
    'submissionsRemaining' => $submissions_remaining
]); 