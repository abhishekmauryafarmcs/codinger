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

// Check if test_cases table exists
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $result->num_rows > 0;
}

// Initialize response array
$response = [
    'visible_test_cases' => [],
    'using_legacy' => false
];

// Get test cases from test_cases table if it exists
if (tableExists($conn, 'test_cases')) {
    $stmt = $conn->prepare("
        SELECT input, expected_output FROM test_cases 
        WHERE problem_id = ? AND is_visible = 1
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $problem_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['visible_test_cases'][] = [
                'input' => $row['input'],
                'output' => $row['expected_output']
            ];
        }
    } else {
        // Fall back to legacy method if no visible test cases found
        $response['using_legacy'] = true;
    }
} else {
    // If test_cases table doesn't exist, use legacy method
    $response['using_legacy'] = true;
}

// If using legacy method, get sample input/output from the problems table
if ($response['using_legacy']) {
    $stmt = $conn->prepare("
        SELECT sample_input, sample_output 
        FROM problems 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $problem_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $problem = $result->fetch_assoc();
    
    if ($problem) {
        // Split sample input and output into separate test cases
        $inputs = explode("\n", trim($problem['sample_input']));
        $outputs = explode("\n", trim($problem['sample_output']));
        
        $count = min(count($inputs), count($outputs));
        
        for ($i = 0; $i < $count; $i++) {
            if (!empty($inputs[$i]) || !empty($outputs[$i])) {
                $response['visible_test_cases'][] = [
                    'input' => $inputs[$i],
                    'output' => $outputs[$i]
                ];
            }
        }
    }
}

// Set response headers
header('Content-Type: application/json');
echo json_encode($response); 