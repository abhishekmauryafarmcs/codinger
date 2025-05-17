<?php
require_once '../../config/session.php';
require_once '../../config/db.php';

// Set the content type to JSON
header('Content-Type: application/json');

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit();
}

// Get the student ID
$student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;

if ($student_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid student ID'
    ]);
    exit();
}

// Begin a transaction to ensure data integrity
$conn->begin_transaction();

try {
    // First, delete all related records from other tables
    // Delete submissions
    $stmt = $conn->prepare("DELETE FROM submissions WHERE user_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete from contest_exits
    $stmt = $conn->prepare("DELETE FROM contest_exits WHERE user_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete from user_sessions
    $stmt = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $stmt->close();
    
    // Finally, delete the user
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    
    // Check if the user was actually deleted
    if ($stmt->affected_rows === 0) {
        throw new Exception("Student not found or could not be deleted");
    }
    
    $stmt->close();
    
    // Commit the transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Student has been successfully removed'
    ]);
    
} catch (Exception $e) {
    // Roll back the transaction if an error occurs
    $conn->rollback();
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?> 