<?php
// Display errors for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include database connection
require_once 'config/db.php';

echo "<h2>Test Cases Table Structure</h2>";

// Check if table exists
$table_check = $conn->query("SHOW TABLES LIKE 'test_cases'");
if ($table_check->num_rows == 0) {
    echo "<p>Table 'test_cases' does not exist!</p>";
    exit;
}

// Get table structure
$columns = $conn->query("SHOW COLUMNS FROM test_cases");
echo "<h3>Columns:</h3>";
echo "<ul>";
while ($col = $columns->fetch_assoc()) {
    echo "<li><strong>" . $col['Field'] . "</strong> - " . $col['Type'] . " " . ($col['Null'] == 'NO' ? 'NOT NULL' : 'NULL') . "</li>";
}
echo "</ul>";

// Check if is_hidden or is_visible column exists
$hidden_check = $conn->query("SHOW COLUMNS FROM test_cases LIKE 'is_hidden'");
$visible_check = $conn->query("SHOW COLUMNS FROM test_cases LIKE 'is_visible'");

echo "<h3>Visibility Column:</h3>";
if ($hidden_check->num_rows > 0) {
    echo "<p>Column 'is_hidden' exists</p>";
} else {
    echo "<p>Column 'is_hidden' does NOT exist</p>";
}

if ($visible_check->num_rows > 0) {
    echo "<p>Column 'is_visible' exists</p>";
} else {
    echo "<p>Column 'is_visible' does NOT exist</p>";
}

// Show a sample of data
echo "<h3>Sample Data:</h3>";
$sample = $conn->query("SELECT * FROM test_cases LIMIT 5");
if ($sample->num_rows > 0) {
    echo "<table border='1'>";
    
    // Headers
    $first_row = $sample->fetch_assoc();
    echo "<tr>";
    foreach (array_keys($first_row) as $header) {
        echo "<th>" . htmlspecialchars($header) . "</th>";
    }
    echo "</tr>";
    
    // Output first row
    echo "<tr>";
    foreach ($first_row as $value) {
        echo "<td>" . htmlspecialchars(substr($value, 0, 50)) . (strlen($value) > 50 ? '...' : '') . "</td>";
    }
    echo "</tr>";
    
    // Output other rows
    while ($row = $sample->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars(substr($value, 0, 50)) . (strlen($value) > 50 ? '...' : '') . "</td>";
        }
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p>No data in test_cases table</p>";
}

// Close connection
$conn->close();
?> 