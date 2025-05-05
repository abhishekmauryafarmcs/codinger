<?php
// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'codinger_db';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Database Fix Tool</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; margin: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .section { margin-bottom: 30px; padding: 20px; border-radius: 5px; background-color: #f5f5f5; }
        .success { color: #198754; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; font-weight: bold; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .button { display: inline-block; padding: 10px 15px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 4px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Database Fix Tool</h1>";

// Function to check if a column exists
function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result->num_rows > 0;
}

// Function to check if a table exists
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $result->num_rows > 0;
}

// Begin transaction
$conn->begin_transaction();

try {
    echo "<div class='section'>";
    echo "<h2>Fixing Contests Table</h2>";
    
    // Check if allowed_tab_switches column exists
    if (!columnExists($conn, 'contests', 'allowed_tab_switches')) {
        echo "<p>Adding 'allowed_tab_switches' column...</p>";
        $conn->query("ALTER TABLE contests ADD COLUMN allowed_tab_switches INT(11) DEFAULT 0");
        echo "<p class='success'>Column 'allowed_tab_switches' added successfully.</p>";
    } else {
        echo "<p>Column 'allowed_tab_switches' already exists.</p>";
    }

    // Check if prevent_copy_paste column exists
    if (!columnExists($conn, 'contests', 'prevent_copy_paste')) {
        echo "<p>Adding 'prevent_copy_paste' column...</p>";
        $conn->query("ALTER TABLE contests ADD COLUMN prevent_copy_paste TINYINT(1) DEFAULT 0");
        echo "<p class='success'>Column 'prevent_copy_paste' added successfully.</p>";
    } else {
        echo "<p>Column 'prevent_copy_paste' already exists.</p>";
    }
    
    echo "</div>";
    
    echo "<div class='section'>";
    echo "<h2>Fixing Test Cases Table</h2>";
    
    // Check if test_cases table exists
    if (!tableExists($conn, 'test_cases')) {
        echo "<p>Creating 'test_cases' table...</p>";
        $sql = "CREATE TABLE IF NOT EXISTS `test_cases` (
            `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
            `problem_id` INT(11) NOT NULL,
            `input` TEXT NOT NULL,
            `expected_output` TEXT NOT NULL,
            `is_visible` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`problem_id`) REFERENCES `problems`(`id`) ON DELETE CASCADE
        )";
        $conn->query($sql);
        echo "<p class='success'>Table 'test_cases' created successfully.</p>";
    } else {
        echo "<p>Table 'test_cases' already exists. Checking columns...</p>";
        
        // Check if input column exists
        if (!columnExists($conn, 'test_cases', 'input')) {
            echo "<p>Adding 'input' column...</p>";
            $conn->query("ALTER TABLE test_cases ADD COLUMN input TEXT NOT NULL");
            echo "<p class='success'>Column 'input' added successfully.</p>";
        } else {
            echo "<p>Column 'input' already exists.</p>";
        }
        
        // Check if expected_output column exists
        if (!columnExists($conn, 'test_cases', 'expected_output')) {
            echo "<p>Adding 'expected_output' column...</p>";
            $conn->query("ALTER TABLE test_cases ADD COLUMN expected_output TEXT NOT NULL");
            echo "<p class='success'>Column 'expected_output' added successfully.</p>";
        } else {
            echo "<p>Column 'expected_output' already exists.</p>";
        }
        
        // Check if is_visible column exists
        if (!columnExists($conn, 'test_cases', 'is_visible')) {
            echo "<p>Adding 'is_visible' column...</p>";
            $conn->query("ALTER TABLE test_cases ADD COLUMN is_visible TINYINT(1) DEFAULT 1");
            echo "<p class='success'>Column 'is_visible' added successfully.</p>";
        } else {
            echo "<p>Column 'is_visible' already exists.</p>";
        }
    }
    
    echo "</div>";
    
    // Commit transaction
    $conn->commit();
    echo "<div class='section success'>";
    echo "<h2>All Fixes Applied Successfully!</h2>";
    echo "<p>Your database has been updated with all required columns.</p>";
    echo "<p>You can now return to creating and managing contests.</p>";
    echo "</div>";
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo "<div class='section error'>";
    echo "<h2>Error Updating Database</h2>";
    echo "<p>An error occurred: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

// Close connection
$conn->close();

echo "<p><a href='admin/create_contest.php' class='button'>Return to Create Contest</a></p>";
echo "<p><a href='admin/manage_contests.php' class='button'>Manage Contests</a></p>";
echo "<p><a href='check_database_schema.php' class='button'>Check Database Schema</a></p>";
echo "</div></body></html>";
?> 