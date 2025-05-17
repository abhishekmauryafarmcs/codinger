<?php
// Database configuration
require_once 'config/db.php';

// Migration script for contest problems
echo "Starting migration of problem-contest relationships...\n";

try {
    // Begin transaction
    $conn->begin_transaction();

    // First, check if contest_problems table exists
    $result = $conn->query("SHOW TABLES LIKE 'contest_problems'");
    if ($result->num_rows == 0) {
        // Create the table if it doesn't exist
        $sql = "CREATE TABLE IF NOT EXISTS contest_problems (
            `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
            `contest_id` INT(11) NOT NULL,
            `problem_id` INT(11) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (`contest_id`, `problem_id`),
            FOREIGN KEY (`contest_id`) REFERENCES `contests`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`problem_id`) REFERENCES `problems`(`id`) ON DELETE CASCADE
        )";
        $conn->query($sql);
        echo "Created contest_problems table\n";
    }

    // Get all problems with a contest_id
    $stmt = $conn->prepare("SELECT id, contest_id FROM problems WHERE contest_id IS NOT NULL");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $count = 0;
    $insert_stmt = $conn->prepare("INSERT IGNORE INTO contest_problems (contest_id, problem_id) VALUES (?, ?)");
    
    while ($row = $result->fetch_assoc()) {
        $problem_id = $row['id'];
        $contest_id = $row['contest_id'];
        
        $insert_stmt->bind_param("ii", $contest_id, $problem_id);
        $insert_stmt->execute();
        $count++;
    }
    
    echo "Migrated $count problem-contest relationships\n";
    
    // Commit transaction
    $conn->commit();
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo "Migration failed: " . $e->getMessage() . "\n";
}

// Close connection
$conn->close();
?> 