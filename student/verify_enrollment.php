<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    header("Location: ../login.php");
    exit();
}

// Check if contest_id and enrollment_number are provided
if (!isset($_POST['contest_id']) || !isset($_POST['enrollment_number'])) {
    header("Location: dashboard.php?error=missing_parameters");
    exit();
}

$contest_id = (int)$_POST['contest_id'];
$enrollment_number = trim($_POST['enrollment_number']);
$user_id = $_SESSION['student']['user_id'];

// Verify that the contest exists and is active
$stmt = $conn->prepare("
    SELECT *, 
    CASE
        WHEN start_time <= NOW() AND end_time > NOW() THEN 'active'
        WHEN start_time > NOW() THEN 'upcoming'
        ELSE 'completed'
    END as contest_status,
    type
    FROM contests 
    WHERE id = ?
");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$contest = $result->fetch_assoc();

// If contest doesn't exist or isn't active, redirect to dashboard
if (!$contest || $contest['contest_status'] !== 'active') {
    header("Location: dashboard.php?error=invalid_contest");
    exit();
}

// If contest is not private, redirect directly to the contest
if ($contest['type'] !== 'private') {
    header("Location: contest.php?id=" . $contest_id);
    exit();
}

// Check if the provided enrollment number is valid for this contest
$stmt = $conn->prepare("
    SELECT * FROM contest_enrollments 
    WHERE contest_id = ? AND enrollment_number = ?
");
$stmt->bind_param("is", $contest_id, $enrollment_number);
$stmt->execute();
$result = $stmt->get_result();

// If there's a match, allow access
if ($result->num_rows > 0) {
    // Check if the student is registered with the same enrollment number
    $stmt = $conn->prepare("
        SELECT enrollment_number FROM users
        WHERE id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $user = $user_result->fetch_assoc();
    
    // Double verification to ensure user's own enrollment number matches (optional security)
    if ($user && $user['enrollment_number'] === $enrollment_number) {
        // Initialize verified_contests array if it doesn't exist
        if (!isset($_SESSION['student']['verified_contests'])) {
            $_SESSION['student']['verified_contests'] = array();
        }
        
        // Store enrollment information in session for reference
        $_SESSION['student']['verified_contests'][$contest_id] = $enrollment_number;
        
        // Redirect to the contest
        header("Location: contest.php?id=" . $contest_id);
        exit();
    } else {
        // Enrollment number doesn't match the user's enrollment
        header("Location: dashboard.php?error=invalid_enrollment&contest_id=" . $contest_id);
        exit();
    }
} else {
    // No matching enrollment found in contest_enrollments
    header("Location: dashboard.php?error=invalid_enrollment&contest_id=" . $contest_id);
    exit();
}
?> 