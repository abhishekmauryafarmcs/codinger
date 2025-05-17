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

// Start HTML output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Test Cases - Codinger</title>
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
                        <a class="nav-link" href="cadmin/index.php">Admin Dashboard</a>
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
                <h3><i class="bi bi-database-gear"></i> Database Update: Test Cases Feature</h3>
            </div>
            <div class="card-body">
                <div class="progress mb-4">
                    <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                </div>
                
                <div class="update-log p-3 bg-light border rounded" style="max-height: 400px; overflow-y: auto;">
                    <h4>Update Log:</h4>
                    <div id="update-log">
<?php
// Perform updates
echo "<p><i class='bi bi-arrow-right-circle-fill text-primary'></i> <strong>Starting database update for test cases...</strong></p>";

// Update progress bar
echo "<script>document.getElementById('progress-bar').style.width = '20%';</script>";
flush();

// Check if test_cases table exists
if (!tableExists($conn, 'test_cases')) {
    echo "<p><i class='bi bi-plus-circle text-success'></i> Creating test_cases table...</p>";
    
    $sql = "CREATE TABLE IF NOT EXISTS `test_cases` (
        `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
        `problem_id` INT(11) NOT NULL,
        `input` TEXT NOT NULL,
        `expected_output` TEXT NOT NULL,
        `is_visible` TINYINT(1) DEFAULT 1 COMMENT 'Whether this test case is visible to students or hidden',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`problem_id`) REFERENCES `problems`(`id`) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql) === TRUE) {
        echo "<p><i class='bi bi-check-circle-fill text-success'></i> Test cases table created successfully.</p>";
    } else {
        echo "<p><i class='bi bi-exclamation-triangle-fill text-danger'></i> Error creating test_cases table: " . $conn->error . "</p>";
    }
    
    // Create index
    echo "<p><i class='bi bi-plus-circle text-success'></i> Adding index for better performance...</p>";
    $sql = "ALTER TABLE `test_cases` ADD INDEX `idx_problem_id` (`problem_id`)";
    if ($conn->query($sql) === TRUE) {
        echo "<p><i class='bi bi-check-circle-fill text-success'></i> Index added successfully.</p>";
    } else {
        echo "<p><i class='bi bi-exclamation-triangle-fill text-warning'></i> Note: " . $conn->error . "</p>";
    }
} else {
    echo "<p><i class='bi bi-check-circle-fill text-success'></i> Test cases table already exists.</p>";
    
    // Check if visible column exists
    if (!columnExists($conn, 'test_cases', 'is_visible')) {
        echo "<p><i class='bi bi-plus-circle text-success'></i> Adding is_visible column to test_cases table...</p>";
        $sql = "ALTER TABLE `test_cases` ADD COLUMN `is_visible` TINYINT(1) DEFAULT 1 COMMENT 'Whether this test case is visible to students or hidden'";
        if ($conn->query($sql) === TRUE) {
            echo "<p><i class='bi bi-check-circle-fill text-success'></i> is_visible column added successfully.</p>";
        } else {
            echo "<p><i class='bi bi-exclamation-triangle-fill text-danger'></i> Error adding is_visible column: " . $conn->error . "</p>";
        }
    }
}

// Update progress bar
echo "<script>document.getElementById('progress-bar').style.width = '60%';</script>";
flush();

// Migrate existing sample test cases if needed
echo "<p><i class='bi bi-arrow-right-circle-fill text-primary'></i> Checking for existing problems to migrate sample data...</p>";

$result = $conn->query("SELECT COUNT(*) as count FROM problems WHERE sample_input IS NOT NULL AND sample_output IS NOT NULL");
$row = $result->fetch_assoc();
$problem_count = $row['count'];

if ($problem_count > 0) {
    echo "<p><i class='bi bi-info-circle text-info'></i> Found {$problem_count} problems with sample inputs/outputs.</p>";
    
    // Check if problems already have test cases
    $result = $conn->query("SELECT COUNT(DISTINCT problem_id) as count FROM test_cases");
    $row = $result->fetch_assoc();
    $problems_with_test_cases = $row['count'];
    
    if ($problems_with_test_cases < $problem_count) {
        echo "<p><i class='bi bi-arrow-right-circle-fill text-primary'></i> Migrating sample test cases to new format...</p>";
        
        // Fetch problems without test cases
        $sql = "SELECT p.id, p.sample_input, p.sample_output 
                FROM problems p 
                LEFT JOIN (
                    SELECT DISTINCT problem_id FROM test_cases
                ) t ON p.id = t.problem_id 
                WHERE t.problem_id IS NULL
                AND p.sample_input IS NOT NULL 
                AND p.sample_output IS NOT NULL";
        
        $result = $conn->query($sql);
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
                    
                    $stmt = $conn->prepare("INSERT INTO test_cases (problem_id, input, expected_output, is_visible) VALUES (?, ?, ?, 1)");
                    $stmt->bind_param("iss", $problem['id'], $input, $output);
                    $stmt->execute();
                    $migrated++;
                }
            }
        }
        
        echo "<p><i class='bi bi-check-circle-fill text-success'></i> Migrated {$migrated} sample test cases.</p>";
    } else {
        echo "<p><i class='bi bi-check-circle-fill text-success'></i> All problems already have test cases.</p>";
    }
}

// Update progress bar
echo "<script>document.getElementById('progress-bar').style.width = '100%';</script>";
flush();

echo "<p><i class='bi bi-check-circle-fill text-success'></i> <strong>Database update complete!</strong></p>";
?>
                    </div>
                </div>
                
                <div class="mt-4">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle-fill"></i> Test cases feature has been installed. Admins can now add visible and hidden test cases for each problem.
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="index.php" class="btn btn-secondary">Back to Home</a>
                        <a href="cadmin/manage_problems.php" class="btn btn-primary">Go to Problem Management</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 