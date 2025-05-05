<?php
require_once 'config/db.php';

// Check if the 'type' column exists in the contests table
$result = $conn->query("SHOW COLUMNS FROM contests LIKE 'type'");
if ($result->num_rows == 0) {
    // Add the type column to contests table
    $sql = "ALTER TABLE contests ADD COLUMN type ENUM('public', 'private') DEFAULT 'public' AFTER status";
    if ($conn->query($sql)) {
        echo "Added 'type' column to contests table successfully.<br>";
    } else {
        echo "Error adding 'type' column: " . $conn->error . "<br>";
    }
}

// Check if contest_enrollments table exists
$result = $conn->query("SHOW TABLES LIKE 'contest_enrollments'");
if ($result->num_rows == 0) {
    // Create contest_enrollments table
    $sql = "CREATE TABLE IF NOT EXISTS contest_enrollments (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        contest_id INT(11) NOT NULL,
        enrollment_number VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
        UNIQUE KEY (contest_id, enrollment_number)
    )";
    
    if ($conn->query($sql)) {
        echo "Created 'contest_enrollments' table successfully.<br>";
    } else {
        echo "Error creating 'contest_enrollments' table: " . $conn->error . "<br>";
    }
}

echo "Database update complete.";
?> 