<?php
require_once '../includes/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';

// Set JSON content type
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Get POST data (JSON)
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (!$data) {
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

// Extract code and language
$code = isset($data['code']) ? $data['code'] : '';
$language = isset($data['language']) ? $data['language'] : '';

if (empty($code)) {
    echo json_encode(['error' => 'No code provided']);
    exit;
}

if ($language !== 'java') {
    echo json_encode(['error' => 'This API is for Java code only']);
    exit;
}

// Check if the code contains a public class named Solution
$has_solution_class = preg_match('/public\s+class\s+Solution\b/', $code);

if ($has_solution_class) {
    // Success: code has the required public class Solution
    echo json_encode([
        'valid' => true,
        'message' => 'Your code correctly uses public class Solution. Good to go!'
    ]);
} else {
    // Try to find what public class is defined
    $found_class = null;
    if (preg_match('/public\s+class\s+([a-zA-Z0-9_]+)/', $code, $matches)) {
        $found_class = $matches[1];
    }
    
    // Create suggestions and fixes
    $suggestions = [];
    $fixed_code = null;
    
    if ($found_class) {
        $suggestions[] = "Change 'public class $found_class' to 'public class Solution'";
        // Create a fixed version
        $fixed_code = preg_replace('/public\s+class\s+' . preg_quote($found_class, '/') . '\b/', 'public class Solution', $code);
    } else {
        // Check if there's any class declaration
        if (preg_match('/class\s+([a-zA-Z0-9_]+)/', $code, $matches)) {
            $class_name = $matches[1];
            $suggestions[] = "Change 'class $class_name' to 'public class Solution'";
            $fixed_code = preg_replace('/class\s+' . preg_quote($class_name, '/') . '\b/', 'public class Solution', $code);
        } else {
            // No class found at all
            $suggestions[] = "Add 'public class Solution { ... }' around your code";
            // Don't attempt to fix - would be too complex without knowing code structure
        }
    }
    
    // Add general advice
    $suggestions[] = "In Java, your solution must be wrapped in a public class named 'Solution'";
    $suggestions[] = "Make sure all other classes are defined as non-public classes";
    
    echo json_encode([
        'valid' => false,
        'message' => 'Your Java code does not have the required public class Solution',
        'suggestions' => $suggestions,
        'fixedCode' => $fixed_code
    ]);
} 
?> 