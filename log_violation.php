<?php
require_once 'config/session.php';
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['violation_type'])) {
    $user_id = $_SESSION['user_id'];
    $violation_type = $_POST['violation_type'];
    $contest_id = $_SESSION['current_contest_id']; // Make sure to set this when contest starts
    
    // Log the violation
    $stmt = $conn->prepare("INSERT INTO contest_violations (user_id, contest_id, violation_type, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $user_id, $contest_id, $violation_type);
    $stmt->execute();
    $stmt->close();
    
    // After 3 violations, end the contest for this user
    $stmt = $conn->prepare("SELECT COUNT(*) as violation_count FROM contest_violations WHERE user_id = ? AND contest_id = ?");
    $stmt->bind_param("ii", $user_id, $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['violation_count'] >= 3) {
        // Update submission status or take appropriate action
        header("Location: contest_ended.php?reason=violations");
        exit();
    }
}

// Redirect back to contest
header("Location: contest.php");
exit();
?> 