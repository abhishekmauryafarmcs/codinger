<?php
// Script to add a new problem to the database
require_once 'config/db.php';

echo "Adding a new problem to the database...\n";

// Prepare problem data
$title = "Fibonacci Sequence";
$description = "Write a function to generate the nth Fibonacci number. The Fibonacci sequence starts with 0 and 1, and each subsequent number is the sum of the two preceding ones.";
$input_format = "The input consists of a single integer n (0 <= n <= 30), representing the position in the Fibonacci sequence.";
$output_format = "Output the nth Fibonacci number.";
$constraints = "0 <= n <= 30";
$sample_input = "5";
$sample_output = "5";
$points = 100;
$difficulty = "medium";
$category = "algorithms";
$problem_type = "algorithm";
$time_limit = 1;
$memory_limit = 256;

// Start a transaction
$conn->begin_transaction();

try {
    // Use direct SQL query to avoid bind_param issues
    $sql = "INSERT INTO problems (
        title, description, input_format, output_format, constraints,
        sample_input, sample_output, points, difficulty, category,
        problem_type, time_limit, memory_limit
    ) VALUES (
        '$title', '$description', '$input_format', '$output_format', '$constraints',
        '$sample_input', '$sample_output', $points, '$difficulty', '$category',
        '$problem_type', $time_limit, $memory_limit
    )";
    
    if ($conn->query($sql)) {
        $problem_id = $conn->insert_id;
        echo "Problem added with ID: $problem_id\n";
        
        // Add test cases
        echo "Adding test cases...\n";
        
        // Add the sample test case (visible)
        $sql = "INSERT INTO test_cases (problem_id, input, expected_output, is_visible) 
                VALUES ($problem_id, '$sample_input', '$sample_output', 1)";
        $conn->query($sql);
        
        // Add some hidden test cases
        $test_cases = [
            ['0', '0', 0],  // n=0, result=0, hidden
            ['1', '1', 0],  // n=1, result=1, hidden
            ['10', '55', 0], // n=10, result=55, hidden
            ['20', '6765', 0] // n=20, result=6765, hidden
        ];
        
        foreach ($test_cases as $case) {
            $sql = "INSERT INTO test_cases (problem_id, input, expected_output, is_visible) 
                    VALUES ($problem_id, '{$case[0]}', '{$case[1]}', {$case[2]})";
            $conn->query($sql);
        }
        
        $conn->commit();
        echo "Success! New problem added with ID: $problem_id\n";
        echo "This problem is now available for assignment to contests.\n";
    } else {
        throw new Exception($conn->error);
    }
} catch (Exception $e) {
    $conn->rollback();
    echo "Error adding problem: " . $e->getMessage() . "\n";
}

echo "\nYou can now create a new contest and select this problem.\n";
?> 