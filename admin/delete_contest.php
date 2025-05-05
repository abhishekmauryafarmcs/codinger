<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Check if contest ID is provided
if (!isset($_GET['id'])) {
    header("Location: manage_contests.php");
    exit();
}

$contest_id = (int)$_GET['id'];

// Function to recursively delete directory
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }

    return rmdir($dir);
}

// Begin transaction
$conn->begin_transaction();

try {
    // Get all submissions for this contest to find temp directories
    $stmt = $conn->prepare("
        SELECT s.id as submission_id 
        FROM submissions s
        JOIN problems p ON s.problem_id = p.id
        WHERE p.contest_id = ?
    ");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Store submission IDs
    $submission_ids = [];
    while ($row = $result->fetch_assoc()) {
        $submission_ids[] = $row['submission_id'];
    }

    // Delete temp directories for each submission
    foreach ($submission_ids as $submission_id) {
        $temp_dir = "../temp/" . $submission_id;
        if (file_exists($temp_dir)) {
            deleteDirectory($temp_dir);
        }
    }

    // Delete submissions for problems in this contest
    $stmt = $conn->prepare("
        DELETE s FROM submissions s
        JOIN problems p ON s.problem_id = p.id
        WHERE p.contest_id = ?
    ");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();

    // Delete problems
    $stmt = $conn->prepare("DELETE FROM problems WHERE contest_id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();

    // Delete contest
    $stmt = $conn->prepare("DELETE FROM contests WHERE id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();

    // Commit transaction
    $conn->commit();
    
    // Log the deletion
    error_log("Contest ID: $contest_id deleted with all related temp files");
    
    header("Location: manage_contests.php?deleted=1");
    exit();

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    error_log("Error deleting contest: " . $e->getMessage());
    header("Location: manage_contests.php?error=delete");
    exit();
}
?> 