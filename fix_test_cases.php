<?php
session_start();
require_once 'config/db.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Function to check if a table exists
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $result->num_rows > 0;
}

// Function to check if a column exists in a table
function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    return $result->num_rows > 0;
}

// Results array to store messages
$results = [];

// Check if test_cases table exists
if (tableExists($conn, 'test_cases')) {
    $results[] = "✓ Test cases table exists.";
    
    // Get all columns in the table to help with debugging
    $columns = [];
    $columnsResult = $conn->query("SHOW COLUMNS FROM test_cases");
    while ($col = $columnsResult->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
    $results[] = "Current columns: " . implode(", ", $columns);
    
    // Create backup of current table
    $conn->query("CREATE TABLE IF NOT EXISTS test_cases_backup LIKE test_cases");
    $conn->query("INSERT INTO test_cases_backup SELECT * FROM test_cases");
    $results[] = "✓ Created backup of current test_cases table.";

    // Check for required columns
    $hasInput = columnExists($conn, 'test_cases', 'input');
    $hasExpectedOutput = columnExists($conn, 'test_cases', 'expected_output');
    
    if (!$hasInput) {
        // Check for alternative column names that might exist
        $alternativeInputColumns = ['test_input', 'input_data', 'case_input'];
        $foundAlternative = false;
        $alternativeColumn = '';
        
        foreach ($alternativeInputColumns as $altCol) {
            if (columnExists($conn, 'test_cases', $altCol)) {
                $foundAlternative = true;
                $alternativeColumn = $altCol;
                break;
            }
        }
        
        if ($foundAlternative) {
            // Rename the alternative column to 'input'
            $conn->query("ALTER TABLE test_cases CHANGE {$alternativeColumn} input TEXT");
            $results[] = "✓ Renamed column '{$alternativeColumn}' to 'input'.";
        } else {
            // Create the input column
            $conn->query("ALTER TABLE test_cases ADD COLUMN input TEXT");
            $results[] = "✓ Added missing 'input' column.";
        }
    }
    
    if (!$hasExpectedOutput) {
        // Check for alternative column names that might exist
        $alternativeOutputColumns = ['test_output', 'output_data', 'case_output', 'output', 'expected'];
        $foundAlternative = false;
        $alternativeColumn = '';
        
        foreach ($alternativeOutputColumns as $altCol) {
            if (columnExists($conn, 'test_cases', $altCol)) {
                $foundAlternative = true;
                $alternativeColumn = $altCol;
                break;
            }
        }
        
        if ($foundAlternative) {
            // Rename the alternative column to 'expected_output'
            $conn->query("ALTER TABLE test_cases CHANGE {$alternativeColumn} expected_output TEXT");
            $results[] = "✓ Renamed column '{$alternativeColumn}' to 'expected_output'.";
        } else {
            // Create the expected_output column
            $conn->query("ALTER TABLE test_cases ADD COLUMN expected_output TEXT");
            $results[] = "✓ Added missing 'expected_output' column.";
        }
    }
    
    // Make sure columns are not null
    $conn->query("ALTER TABLE test_cases MODIFY input TEXT NOT NULL");
    $conn->query("ALTER TABLE test_cases MODIFY expected_output TEXT NOT NULL");
    $results[] = "✓ Updated column definitions to NOT NULL.";
    
    // Ensure is_visible column exists
    if (!columnExists($conn, 'test_cases', 'is_visible')) {
        $conn->query("ALTER TABLE test_cases ADD COLUMN is_visible TINYINT(1) DEFAULT 1");
        $results[] = "✓ Added missing 'is_visible' column.";
    }
    
    // Ensure we have all other expected columns
    if (!columnExists($conn, 'test_cases', 'created_at')) {
        $conn->query("ALTER TABLE test_cases ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $results[] = "✓ Added missing 'created_at' column.";
    }
    
    // Check for index
    $indexResult = $conn->query("SHOW INDEX FROM test_cases WHERE Key_name = 'idx_problem_id'");
    if ($indexResult->num_rows == 0) {
        $conn->query("ALTER TABLE test_cases ADD INDEX idx_problem_id (problem_id)");
        $results[] = "✓ Added missing index on problem_id.";
    }
    
    $results[] = "✓ Test cases table structure has been fixed!";
} else {
    // Create the test_cases table from scratch
    $sql = "CREATE TABLE IF NOT EXISTS `test_cases` (
        `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
        `problem_id` INT(11) NOT NULL,
        `input` TEXT NOT NULL,
        `expected_output` TEXT NOT NULL,
        `is_visible` TINYINT(1) DEFAULT 1 COMMENT 'Whether this test case is visible to students or hidden',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`problem_id`) REFERENCES `problems`(`id`) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql)) {
        $results[] = "✓ Created test_cases table from scratch.";
        
        // Add index
        $conn->query("ALTER TABLE `test_cases` ADD INDEX `idx_problem_id` (`problem_id`)");
        $results[] = "✓ Added index on problem_id.";
        
        // Migrate sample input/output data
        $stmt = $conn->prepare("SELECT id, sample_input, sample_output FROM problems WHERE sample_input IS NOT NULL AND sample_output IS NOT NULL");
        $stmt->execute();
        $result = $stmt->get_result();
        $migrated = 0;
        
        while ($problem = $result->fetch_assoc()) {
            // Split sample inputs and outputs
            $inputs = explode("\n", trim($problem['sample_input']));
            $outputs = explode("\n", trim($problem['sample_output']));
            
            $count = min(count($inputs), count($outputs));
            
            for ($i = 0; $i < $count; $i++) {
                if (!empty($inputs[$i]) || !empty($outputs[$i])) {
                    $input = $inputs[$i];
                    $output = $outputs[$i];
                    
                    $insertStmt = $conn->prepare("INSERT INTO test_cases (problem_id, input, expected_output, is_visible) VALUES (?, ?, ?, 1)");
                    $insertStmt->bind_param("iss", $problem['id'], $input, $output);
                    $insertStmt->execute();
                    $migrated++;
                }
            }
        }
        
        $results[] = "✓ Migrated {$migrated} test cases from sample inputs/outputs.";
    } else {
        $results[] = "❌ Error creating test_cases table: " . $conn->error;
    }
}

// Verify that everything is working now
$verifyResult = $conn->query("SELECT id, problem_id, input, expected_output, is_visible FROM test_cases LIMIT 5");
if ($verifyResult && $verifyResult->num_rows > 0) {
    $results[] = "✓ Verification successful: test_cases table is now properly configured.";
} else {
    $results[] = "❌ Verification failed: " . $conn->error;
}

// Display results
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Test Cases Table - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">Codinger</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="admin/index.php">Admin Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h3><i class="bi bi-wrench"></i> Fix Test Cases Table</h3>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-4">
                    <i class="bi bi-info-circle"></i> This script checks and fixes issues with the test_cases table structure.
                </div>
                
                <div class="mb-4">
                    <h4>Results:</h4>
                    <ul class="list-group">
                        <?php foreach ($results as $message): ?>
                            <li class="list-group-item">
                                <?php echo $message; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <div class="d-flex justify-content-between">
                    <a href="index.php" class="btn btn-secondary">Back to Home</a>
                    <a href="admin/manage_problems.php" class="btn btn-primary">Go to Problem Management</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 