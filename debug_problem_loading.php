<?php
require_once 'config/db.php';

echo "<h1>Debug Problem Loading</h1>";

// First, check the problems table
$result = $conn->query("SELECT COUNT(*) as count FROM problems");
$row = $result->fetch_assoc();
echo "<p>Total problems in database: " . $row['count'] . "</p>";

// Get a list of all contests
echo "<h2>Contests</h2>";
$result = $conn->query("SELECT * FROM contests ORDER BY id");
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Title</th><th>Status</th><th>Problem Count</th></tr>";

while ($contest = $result->fetch_assoc()) {
    // Count problems in this contest
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM problems WHERE contest_id = ?");
    $stmt->bind_param("i", $contest['id']);
    $stmt->execute();
    $count_result = $stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    
    echo "<tr>";
    echo "<td>" . $contest['id'] . "</td>";
    echo "<td>" . $contest['title'] . "</td>";
    echo "<td>" . $contest['status'] . "</td>";
    echo "<td>" . $count_row['count'] . "</td>";
    echo "</tr>";
}

echo "</table>";

// Check a specific contest's problems in detail (using the first contest as an example)
$result = $conn->query("SELECT id FROM contests ORDER BY id LIMIT 1");
if ($contest = $result->fetch_assoc()) {
    $contest_id = $contest['id'];
    
    echo "<h2>Problems in Contest ID: " . $contest_id . "</h2>";
    
    $stmt = $conn->prepare("SELECT * FROM problems WHERE contest_id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $problems = $stmt->get_result();
    
    if ($problems->num_rows > 0) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Title</th><th>Description Length</th><th>Test Cases</th></tr>";
        
        while ($problem = $problems->fetch_assoc()) {
            // Get test cases count
            $test_case_count = 0;
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM test_cases WHERE problem_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $problem['id']);
                $stmt->execute();
                $count_result = $stmt->get_result();
                $count_row = $count_result->fetch_assoc();
                $test_case_count = $count_row['count'];
            }
            
            echo "<tr>";
            echo "<td>" . $problem['id'] . "</td>";
            echo "<td>" . $problem['title'] . "</td>";
            echo "<td>" . (strlen($problem['description']) > 0 ? strlen($problem['description']) . " chars" : "<strong>EMPTY</strong>") . "</td>";
            echo "<td>" . $test_case_count . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "<p>No problems found in this contest.</p>";
    }
}

// Test the problem loading function
echo "<h2>Problem Loading Test</h2>";
$result = $conn->query("SELECT * FROM problems LIMIT 1");
if ($problem = $result->fetch_assoc()) {
    echo "<p>Testing problem ID: " . $problem['id'] . "</p>";
    
    echo "<h3>Problem Data</h3>";
    echo "<pre>";
    print_r($problem);
    echo "</pre>";
    
    // Try to load test cases
    echo "<h3>Test Cases</h3>";
    $stmt = $conn->prepare("SELECT * FROM test_cases WHERE problem_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $problem['id']);
        $stmt->execute();
        $test_cases = $stmt->get_result();
        
        if ($test_cases->num_rows > 0) {
            echo "<table border='1'>";
            echo "<tr><th>ID</th><th>Input</th><th>Expected Output</th><th>Visible</th></tr>";
            
            while ($test_case = $test_cases->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $test_case['id'] . "</td>";
                echo "<td><pre>" . htmlspecialchars($test_case['input']) . "</pre></td>";
                echo "<td><pre>" . htmlspecialchars($test_case['expected_output']) . "</pre></td>";
                echo "<td>" . ($test_case['is_visible'] ? "Yes" : "No") . "</td>";
                echo "</tr>";
            }
            
            echo "</table>";
        } else {
            echo "<p>No test cases found for this problem.</p>";
        }
    } else {
        echo "<p>Test cases table might not exist.</p>";
    }
}

// Check for database structure issues
echo "<h2>Database Structure Check</h2>";

// Check if all required columns exist in problems table
$required_columns = ['title', 'description', 'input_format', 'output_format', 'constraints', 'sample_input', 'sample_output'];
$missing_columns = [];

foreach ($required_columns as $column) {
    $result = $conn->query("SHOW COLUMNS FROM problems LIKE '$column'");
    if ($result->num_rows === 0) {
        $missing_columns[] = $column;
    }
}

if (count($missing_columns) > 0) {
    echo "<p style='color: red;'>Missing columns in problems table: " . implode(", ", $missing_columns) . "</p>";
} else {
    echo "<p style='color: green;'>All required columns exist in problems table.</p>";
}

// Check for problems with NULL values in important fields
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM problems WHERE description IS NULL OR description = ''");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
if ($row['count'] > 0) {
    echo "<p style='color: red;'>" . $row['count'] . " problems have NULL or empty descriptions.</p>";
} else {
    echo "<p style='color: green;'>No problems with empty descriptions.</p>";
} 