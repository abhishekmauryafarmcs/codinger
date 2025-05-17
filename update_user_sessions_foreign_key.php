<?php
// Script to update user_sessions table foreign key constraints
require_once 'config/db.php';

echo "Starting user_sessions table foreign key update...\n";

try {
    // First check if there's any foreign key constraint
    $sql = "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_sessions'
            AND REFERENCED_TABLE_NAME IS NOT NULL";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        // Drop existing foreign key constraints
        while ($row = $result->fetch_assoc()) {
            $constraint_name = $row['CONSTRAINT_NAME'];
            echo "Dropping foreign key constraint: $constraint_name\n";
            
            $sql = "ALTER TABLE user_sessions DROP FOREIGN KEY `$constraint_name`";
            if ($conn->query($sql)) {
                echo "Successfully dropped foreign key constraint: $constraint_name\n";
            } else {
                echo "Error dropping constraint: " . $conn->error . "\n";
            }
        }
    } else {
        echo "No foreign key constraints found on user_sessions table.\n";
    }
    
    // No foreign key constraints will be added since we need to support both users and admins tables
    // which have different structures
    
    echo "Update complete! The user_sessions table now supports both student and admin roles without foreign key constraints.\n";
    echo "This is intentional as it allows flexibility between different user tables (users and admins).\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 