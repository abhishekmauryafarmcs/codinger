<?php
// Include session file and set content type
require_once '../config/session.php';
header('Content-Type: application/json');

// Get the contest ID from the query string
$contest_id = isset($_GET['contest_id']) ? (int)$_GET['contest_id'] : null;

if (!$contest_id) {
    echo json_encode(['error' => 'No contest ID provided']);
    exit;
}

// Update the session with the contest ID
$_SESSION['student']['current_contest_id'] = $contest_id;

// Log for debugging
error_log("Session updated with contest ID: " . $contest_id);
error_log("Session ID: " . session_id());

// Return success response
echo json_encode([
    'success' => true,
    'contest_id' => $contest_id,
    'session_id' => session_id()
]); 