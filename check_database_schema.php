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
    <title>Database Schema Checker</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h1 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
        .missing { color: red; font-weight: bold; }
        .exists { color: green; }
        .fix-button { display: inline-block; padding: 8px 15px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Database Schema Checker</h1>";

// Define expected table schemas (based on db.php)
$expected_schema = [
    'contests' => [
        'id' => 'INT',
        'title' => 'VARCHAR',
        'description' => 'TEXT',
        'start_time' => 'DATETIME',
        'end_time' => 'DATETIME',
        'status' => 'ENUM',
        'type' => 'ENUM',
        'created_by' => 'INT',
        'allowed_tab_switches' => 'INT',
        'prevent_copy_paste' => 'TINYINT',
        'created_at' => 'TIMESTAMP'
    ],
    'problems' => [
        'id' => 'INT',
        'contest_id' => 'INT',
        'title' => 'VARCHAR',
        'description' => 'TEXT',
        'input_format' => 'TEXT',
        'output_format' => 'TEXT',
        'constraints' => 'TEXT',
        'sample_input' => 'TEXT',
        'sample_output' => 'TEXT',
        'points' => 'INT',
        'difficulty' => 'ENUM',
        'category' => 'VARCHAR',
        'problem_type' => 'VARCHAR',
        'time_limit' => 'INT',
        'memory_limit' => 'INT',
        'created_by' => 'INT',
        'created_at' => 'TIMESTAMP'
    ],
    'test_cases' => [
        'id' => 'INT',
        'problem_id' => 'INT',
        'input' => 'TEXT',
        'expected_output' => 'TEXT',
        'is_visible' => 'TINYINT',
        'created_at' => 'TIMESTAMP'
    ]
];

// Track missing columns to suggest fixes
$missing_columns = [];

// Check each table
foreach ($expected_schema as $table => $columns) {
    echo "<h2>Checking '{$table}' table:</h2>";
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '{$table}'");
    if ($table_check->num_rows == 0) {
        echo "<p class='missing'>Table '{$table}' does not exist in the database!</p>";
        continue;
    }
    
    // Get existing columns
    $existing_columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
    while ($row = $result->fetch_assoc()) {
        $existing_columns[$row['Field']] = strtoupper(preg_replace('/\(.*\)/', '', $row['Type']));
    }
    
    // Start table for this schema
    echo "<table>
            <tr>
                <th>Column Name</th>
                <th>Expected Type</th>
                <th>Actual Type</th>
                <th>Status</th>
            </tr>";
    
    // Check each expected column
    foreach ($columns as $column => $type) {
        $status = isset($existing_columns[$column]) ? "<span class='exists'>✓ Exists</span>" : "<span class='missing'>✗ Missing</span>";
        $actual_type = isset($existing_columns[$column]) ? $existing_columns[$column] : "N/A";
        
        echo "<tr>
                <td>{$column}</td>
                <td>{$type}</td>
                <td>{$actual_type}</td>
                <td>{$status}</td>
              </tr>";
        
        // Track missing columns
        if (!isset($existing_columns[$column])) {
            $missing_columns[$table][] = $column;
        }
    }
    
    echo "</table>";
}

// Show fix script if there are missing columns
if (!empty($missing_columns)) {
    echo "<h2>SQL Fix Script</h2>";
    echo "<p>The following SQL commands will add all missing columns:</p>";
    echo "<pre style='background-color: #f8f9fa; padding: 15px; border-radius: 5px;'>";
    
    foreach ($missing_columns as $table => $columns) {
        if (!empty($columns)) {
            echo "-- Fix for {$table} table\n";
            echo "ALTER TABLE `{$table}`\n";
            
            $column_definitions = [];
            foreach ($columns as $column) {
                $type = $expected_schema[$table][$column];
                
                // Create appropriate column definition based on column name and type
                $definition = "ADD COLUMN `{$column}` ";
                
                switch ($column) {
                    case 'allowed_tab_switches':
                        $definition .= "INT(11) DEFAULT 0";
                        break;
                    case 'prevent_copy_paste':
                        $definition .= "TINYINT(1) DEFAULT 0";
                        break;
                    case 'input':
                    case 'expected_output':
                        $definition .= "TEXT NOT NULL";
                        break;
                    case 'created_at':
                        $definition .= "TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
                        break;
                    default:
                        // Default definition based on type
                        if (strpos($type, 'INT') !== false) {
                            $definition .= "INT(11) DEFAULT 0";
                        } elseif ($type == 'TEXT') {
                            $definition .= "TEXT";
                        } elseif ($type == 'VARCHAR') {
                            $definition .= "VARCHAR(255) DEFAULT ''";
                        } elseif ($type == 'TIMESTAMP') {
                            $definition .= "TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
                        } elseif ($type == 'ENUM') {
                            // For ENUM types, we'd need more specific info, defaulting to VARCHAR
                            $definition .= "VARCHAR(50) DEFAULT ''";
                        } else {
                            $definition .= "VARCHAR(255)";
                        }
                }
                
                $column_definitions[] = $definition;
            }
            
            echo implode(",\n", $column_definitions) . ";\n\n";
        }
    }
    
    echo "</pre>";
    
    // Create a fix button that will run the fix_contests_column.php script
    echo "<p><a href='fix_contests_column.php' class='fix-button'>Run Fix Script for Contests Table</a></p>";
}

// Close connection
$conn->close();

echo "<p><a href='admin/create_contest.php' class='fix-button'>Return to Create Contest</a></p>";
echo "</body></html>";
?> 