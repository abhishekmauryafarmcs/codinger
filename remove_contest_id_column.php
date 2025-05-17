<?php
// Database configuration
require_once 'config/db.php';

// Create a backup first
$timestamp = date('Y-m-d_H-i-s');
$backup_file = "backup_problems_$timestamp.sql";

echo "Starting modification of problems table structure...\n";
echo "Creating backup of problems table to $backup_file\n";

try {
    // Export problems table
    $command = "mysqldump -h localhost -u root --no-tablespaces codinger_db problems > $backup_file";
    $output = shell_exec($command);
    
    echo "Backup created. Proceeding with modification...\n";
    
    // Begin transaction for database changes
    $conn->begin_transaction();
    
    // Check if we have successfully migrated data to contest_problems table
    $result = $conn->query("SELECT COUNT(*) as count FROM contest_problems");
    $count = $result->fetch_assoc()['count'];
    
    if ($count == 0) {
        throw new Exception("contest_problems table is empty. Run migrate_contest_problems.php first!");
    }
    
    // Remove the contest_id column from problems table
    $stmt = $conn->prepare("ALTER TABLE problems DROP FOREIGN KEY problems_ibfk_1");
    $stmt->execute();
    
    $stmt = $conn->prepare("ALTER TABLE problems DROP COLUMN contest_id");
    $stmt->execute();
    
    echo "Column contest_id successfully removed from problems table.\n";
    
    // Commit the transaction
    $conn->commit();
    
    echo "Database structure updated successfully!\n";
    
} catch (Exception $e) {
    // Rollback the transaction if any error occurs
    if (isset($conn)) {
        $conn->rollback();
    }
    
    echo "Error: " . $e->getMessage() . "\n";
    echo "No changes were made to the database.\n";
}

// Close connection
$conn->close();
?> 