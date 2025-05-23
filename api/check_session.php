<?php
// Required headers
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    echo json_encode([
        'valid' => false,
        'error' => 'Session expired or invalid',
        'redirect' => '../login.php?error=session_expired'
    ]);
    exit();
}

// Get current contest ID from session
$current_contest_id = isset($_SESSION['student']['current_contest_id']) ? 
    $_SESSION['student']['current_contest_id'] : null;

// Check if user has been terminated from the contest
if ($current_contest_id && 
    isset($_SESSION['student']['terminated_contests'][$current_contest_id])) {
    echo json_encode([
        'valid' => false,
        'error' => 'Contest access terminated',
        'reason' => $_SESSION['student']['terminated_contests'][$current_contest_id]['reason'],
        'redirect' => 'dashboard.php?error=contest_terminated'
    ]);
    exit();
}

// Check if user has exited the contest
if ($current_contest_id && 
    isset($_SESSION['student']['exited_contests'][$current_contest_id])) {
    echo json_encode([
        'valid' => false,
        'error' => 'Contest exited',
        'redirect' => 'dashboard.php?error=contest_exited'
    ]);
    exit();
}

// If we get here, session is valid
echo json_encode([
    'valid' => true,
    'user_id' => $_SESSION['student']['user_id'],
    'contest_id' => $current_contest_id
]); 