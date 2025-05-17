<?php
// Script to update user_sessions table structure
require_once 'config/db.php';

echo "Starting user_sessions table update...\n";

try {
    // Check if the old constraint exists
    $sql = "SHOW KEYS FROM user_sessions WHERE Key_name = 'session_id'";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "Found old unique constraint on session_id, dropping it...\n";
        
        // Drop the old unique constraint
        $sql = "ALTER TABLE `user_sessions` DROP INDEX `session_id`";
        if ($conn->query($sql)) {
            echo "Successfully dropped old constraint.\n";
        } else {
            throw new Exception("Failed to drop constraint: " . $conn->error);
        }
        
        // Add the new unique constraint
        $sql = "ALTER TABLE `user_sessions` ADD UNIQUE KEY `unique_user_session` (`user_id`, `role`, `session_id`)";
        if ($conn->query($sql)) {
            echo "Successfully added new composite constraint.\n";
        } else {
            throw new Exception("Failed to add new constraint: " . $conn->error);
        }
        
        echo "Table structure updated successfully!\n";
    } else {
        echo "No old constraint found. Table structure is already correct.\n";
    }
    
    // Clear out any stale sessions
    $sql = "DELETE FROM user_sessions WHERE last_activity < (NOW() - INTERVAL 24 HOUR)";
    if ($conn->query($sql)) {
        $count = $conn->affected_rows;
        echo "Cleaned up $count stale session records.\n";
    }
    
    echo "Update complete!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 