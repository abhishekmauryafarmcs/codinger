<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Set headers for JSON response
header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    echo json_encode(['error' => 'Invalid session']);
    exit();
}

// Check if contest ID is provided
if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Contest ID is required']);
    exit();
}

$contest_id = (int)$_GET['id'];
$user_id = $_SESSION['student']['user_id'];

try {
    // Get problems for this contest
    $stmt = $conn->prepare("
        SELECT p.* 
        FROM problems p
        JOIN contest_problems cp ON p.id = cp.problem_id
        WHERE cp.contest_id = ? 
        ORDER BY p.points ASC
    ");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $problems_result = $stmt->get_result();

    $problems = [];
    while ($row = $problems_result->fetch_assoc()) {
        $problems[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'input_format' => $row['input_format'],
            'output_format' => $row['output_format'],
            'constraints' => $row['constraints'],
            'points' => (int)$row['points']
        ];
    }

    echo json_encode([
        'success' => true,
        'problems' => $problems,
        'total' => count($problems)
    ]);

} catch (Exception $e) {
    error_log("Error fetching contest problems: " . $e->getMessage());
    echo json_encode([
        'error' => 'Error fetching problems',
        'details' => $e->getMessage()
    ]);
}
?> 