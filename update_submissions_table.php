<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include database connection
require_once 'config/db.php';

// Check if columns already exist
$check_columns = $conn->query("SHOW COLUMNS FROM submissions LIKE 'test_cases_passed'");
if ($check_columns->num_rows > 0) {
    echo "Columns already exist!";
    exit();
}

// Add columns to track test cases passed, total test cases, and score
$alter_table_query = "
    ALTER TABLE submissions 
    ADD COLUMN test_cases_passed INT DEFAULT 0 AFTER status,
    ADD COLUMN total_test_cases INT DEFAULT 0 AFTER test_cases_passed,
    ADD COLUMN score FLOAT DEFAULT 0 AFTER total_test_cases
";

if ($conn->query($alter_table_query)) {
    echo "Submissions table updated successfully!";
} else {
    echo "Error updating submissions table: " . $conn->error;
}

// Create indexes for faster queries
$create_indexes_query = "
    CREATE INDEX idx_user_problem ON submissions (user_id, problem_id);
    CREATE INDEX idx_problem_status ON submissions (problem_id, status);
    CREATE INDEX idx_submitted_at ON submissions (submitted_at);
";

if ($conn->multi_query($create_indexes_query)) {
    echo "<br>Indexes created successfully!";
} else {
    echo "<br>Error creating indexes: " . $conn->error;
}

$conn->close();
?> 