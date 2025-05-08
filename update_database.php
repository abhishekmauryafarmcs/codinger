<?php
// Check if mysqli extension is loaded
if (!extension_loaded('mysqli')) {
    die('<h1>Database Error</h1><p>The mysqli extension is not loaded in your PHP installation. Contact your server administrator.</p>');
}

// Include database configuration
try {
    require_once 'config/db.php';
} catch (Exception $e) {
    die('<h1>Database Connection Error</h1><p>' . $e->getMessage() . '</p>');
}

// Check if this is being accessed from manage_problems
$redirect_back = isset($_GET['from']) && $_GET['from'] === 'manage_problems';

// Function to check if a column exists
function columnExists($conn, $table, $column) {
    $sql = "SHOW COLUMNS FROM {$table} LIKE '{$column}'";
    $result = $conn->query($sql);
    return $result->num_rows > 0;
}

// Begin transaction
$conn->begin_transaction();

try {
    echo "<h2>Database Update</h2>";
    echo "<p>Starting database update process...</p>";

    // Update contests table schema
    echo "<h3>Updating contests table</h3>";
    
    // Check if new column exists, if not add it
    if (!columnExists($conn, 'contests', 'allowed_tab_switches')) {
        echo "<p>Adding allowed_tab_switches column...</p>";
        $conn->query("ALTER TABLE contests ADD COLUMN allowed_tab_switches INT(11) DEFAULT 0");
    }
    
    // Check if old columns exist, if they do, migrate data and drop them
    if (columnExists($conn, 'contests', 'prevent_tab_switch')) {
        echo "<p>Migrating tab switch settings...</p>";
        // Update allowed_tab_switches based on prevent_tab_switch (0 if prevented, 3 if allowed)
        $conn->query("UPDATE contests SET allowed_tab_switches = CASE WHEN prevent_tab_switch = 1 THEN 0 ELSE 3 END");
        
        // Drop old columns
        echo "<p>Removing old columns...</p>";
        if (columnExists($conn, 'contests', 'is_proctored')) {
            $conn->query("ALTER TABLE contests DROP COLUMN is_proctored");
        }
        if (columnExists($conn, 'contests', 'allow_webcam')) {
            $conn->query("ALTER TABLE contests DROP COLUMN allow_webcam");
        }
        if (columnExists($conn, 'contests', 'allow_screen_record')) {
            $conn->query("ALTER TABLE contests DROP COLUMN allow_screen_record");
        }
        if (columnExists($conn, 'contests', 'prevent_tab_switch')) {
            $conn->query("ALTER TABLE contests DROP COLUMN prevent_tab_switch");
        }
    }

    // Add max_submissions column to contests table if it doesn't exist
    if (!columnExists($conn, 'contests', 'max_submissions')) {
        echo "<p>Adding max_submissions column to contests table...</p>";
        $sql = "ALTER TABLE contests ADD COLUMN max_submissions INT(11) DEFAULT 0 COMMENT 'Maximum number of submissions allowed per problem (0 = unlimited)'";
        
        if ($conn->query($sql)) {
            echo "<p style='color: green;'>✓ Added max_submissions column successfully.</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding max_submissions column: " . $conn->error . "</p>";
        }
    }

    // Add prevent_right_click column to contests table if it doesn't exist
    if (!columnExists($conn, 'contests', 'prevent_right_click')) {
        echo "<p>Adding prevent_right_click column to contests table...</p>";
        $sql = "ALTER TABLE contests ADD COLUMN prevent_right_click TINYINT(1) DEFAULT 0 COMMENT 'Whether to prevent right-clicking during the contest'";
        
        if ($conn->query($sql)) {
            echo "<p style='color: green;'>✓ Added prevent_right_click column successfully.</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding prevent_right_click column: " . $conn->error . "</p>";
        }
    }

    // Update problems table schema with new columns
    echo "<h3>Updating problems table</h3>";
    
    // Add created_at column if it doesn't exist
    if (!columnExists($conn, 'problems', 'created_at')) {
        echo "<p>Adding created_at column...</p>";
        $conn->query("ALTER TABLE problems ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
    
    // Add difficulty column
    if (!columnExists($conn, 'problems', 'difficulty')) {
        echo "<p>Adding difficulty column...</p>";
        $conn->query("ALTER TABLE problems ADD COLUMN difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'medium'");
    }
    
    // Add category column
    if (!columnExists($conn, 'problems', 'category')) {
        echo "<p>Adding category column...</p>";
        $conn->query("ALTER TABLE problems ADD COLUMN category VARCHAR(50) DEFAULT NULL");
    }
    
    // Add problem_type column
    if (!columnExists($conn, 'problems', 'problem_type')) {
        echo "<p>Adding problem_type column...</p>";
        $conn->query("ALTER TABLE problems ADD COLUMN problem_type VARCHAR(50) DEFAULT 'algorithm'");
    }
    
    // Add time_limit column
    if (!columnExists($conn, 'problems', 'time_limit')) {
        echo "<p>Adding time_limit column...</p>";
        $conn->query("ALTER TABLE problems ADD COLUMN time_limit INT(11) DEFAULT 1");
    }
    
    // Add memory_limit column
    if (!columnExists($conn, 'problems', 'memory_limit')) {
        echo "<p>Adding memory_limit column...</p>";
        $conn->query("ALTER TABLE problems ADD COLUMN memory_limit INT(11) DEFAULT 256");
    }

    // Commit transaction
    $conn->commit();
    echo "<p style='color:green;'>Database update completed successfully!</p>";
    
    // Provide next steps with appropriate action buttons
    echo "<div style='margin-top: 20px;'>";
    if ($redirect_back) {
        echo "<p><a href='admin/manage_problems.php' class='btn btn-primary'>Return to Problem Bank</a></p>";
        // Auto-redirect after 2 seconds
        echo "<script>setTimeout(function() { window.location.href = 'admin/manage_problems.php'; }, 2000);</script>";
    } else {
        echo "<p><a href='index.php' class='btn btn-primary'>Return to Home</a></p>";
        echo "<p><a href='admin/manage_problems.php' class='btn btn-success'>Go to Problem Bank</a></p>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo "<p style='color:red;'>Error updating database: " . $e->getMessage() . "</p>";
    echo "<p>Try running the following SQL statements manually in phpMyAdmin:</p>";
    echo "<pre style='background-color: #f8f9fa; padding: 15px; border-radius: 5px;'>";
    echo "ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;\n";
    echo "ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `difficulty` ENUM('easy', 'medium', 'hard') DEFAULT 'medium';\n";
    echo "ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `category` VARCHAR(50) DEFAULT NULL;\n";
    echo "ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `problem_type` VARCHAR(50) DEFAULT 'algorithm';\n";
    echo "ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `time_limit` INT(11) DEFAULT 1;\n";
    echo "ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `memory_limit` INT(11) DEFAULT 256;\n";
    echo "</pre>";
    
    echo "<div style='margin-top: 20px;'>";
    if ($redirect_back) {
        echo "<p><a href='admin/manage_problems.php' class='btn btn-warning'>Return to Problem Bank</a></p>";
    } else {
        echo "<p><a href='index.php' class='btn btn-warning'>Return to Home</a></p>";
    }
    echo "</div>";
}
?> 