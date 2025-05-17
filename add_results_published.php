<?php
require_once 'config/db.php';

echo "<h1>Adding results_published Column to Contests Table</h1>";

// Check if column already exists
$result = $conn->query("SHOW COLUMNS FROM contests LIKE 'results_published'");
$column_exists = $result->num_rows > 0;

if (!$column_exists) {
    echo "<p>Results Published column does not exist in contests table. Adding it now...</p>";
    
    // Add results_published column to contests table
    $sql = "ALTER TABLE contests ADD COLUMN results_published TINYINT(1) DEFAULT 0 COMMENT 'Whether contest results are published for students to view'";
    
    if ($conn->query($sql)) {
        echo "<p style='color: green;'>Successfully added results_published column to contests table.</p>";
    } else {
        echo "<p style='color: red;'>Error adding column: " . $conn->error . "</p>";
    }
} else {
    echo "<p>Results Published column already exists in contests table.</p>";
}

echo "<p>Script execution completed.</p>";
?> 