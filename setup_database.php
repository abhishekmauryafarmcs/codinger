<?php
// Setup script for Codinger database
echo "Starting Codinger database setup...\n";

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'codinger_db';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}

echo "Connected to MySQL server successfully!\n";

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS `$db_name`";
if ($conn->query($sql) === TRUE) {
    echo "Database '$db_name' created or already exists\n";
} else {
    die("Error creating database: " . $conn->error . "\n");
}

// Select the database
$conn->select_db($db_name);
echo "Selected database: $db_name\n";

// Create admins table
$sql = "CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) UNIQUE NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) UNIQUE NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($conn->query($sql) === TRUE) {
    echo "Table 'admins' created or already exists\n";
} else {
    echo "Error creating table 'admins': " . $conn->error . "\n";
}

// Create users table (for students)
$sql = "CREATE TABLE IF NOT EXISTS `users` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `enrollment_number` VARCHAR(50) UNIQUE NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `college_name` VARCHAR(200) NOT NULL,
    `mobile_number` VARCHAR(15) NOT NULL,
    `email` VARCHAR(100) UNIQUE NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($conn->query($sql) === TRUE) {
    echo "Table 'users' created or already exists\n";
} else {
    echo "Error creating table 'users': " . $conn->error . "\n";
}

// Create contests table
$sql = "CREATE TABLE IF NOT EXISTS `contests` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `status` ENUM('upcoming', 'ongoing', 'completed') DEFAULT 'upcoming',
    `type` ENUM('public', 'private') DEFAULT 'public',
    `created_by` INT(11),
    `allowed_tab_switches` INT(11) DEFAULT 0,
    `prevent_copy_paste` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
)";
if ($conn->query($sql) === TRUE) {
    echo "Table 'contests' created or already exists\n";
} else {
    echo "Error creating table 'contests': " . $conn->error . "\n";
}

// Create contest_enrollments table
$sql = "CREATE TABLE IF NOT EXISTS `contest_enrollments` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `contest_id` INT(11) NOT NULL,
    `enrollment_number` VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`contest_id`) REFERENCES `contests`(`id`) ON DELETE CASCADE,
    UNIQUE KEY (`contest_id`, `enrollment_number`)
)";
if ($conn->query($sql) === TRUE) {
    echo "Table 'contest_enrollments' created or already exists\n";
} else {
    echo "Error creating table 'contest_enrollments': " . $conn->error . "\n";
}

// Create problems table
$sql = "CREATE TABLE IF NOT EXISTS `problems` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `contest_id` INT(11),
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `input_format` TEXT,
    `output_format` TEXT,
    `constraints` TEXT,
    `sample_input` TEXT,
    `sample_output` TEXT,
    `points` INT(11) DEFAULT 0,
    `difficulty` ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
    `category` VARCHAR(50) DEFAULT NULL,
    `problem_type` VARCHAR(50) DEFAULT 'algorithm',
    `time_limit` INT(11) DEFAULT 1,
    `memory_limit` INT(11) DEFAULT 256,
    `created_by` INT(11),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`contest_id`) REFERENCES `contests`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
)";
if ($conn->query($sql) === TRUE) {
    echo "Table 'problems' created or already exists\n";
} else {
    echo "Error creating table 'problems': " . $conn->error . "\n";
}

// Create test_cases table
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
    echo "Table 'test_cases' created or already exists\n";
} else {
    echo "Error creating table 'test_cases': " . $conn->error . "\n";
}

// Create submissions table
$sql = "CREATE TABLE IF NOT EXISTS `submissions` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11),
    `problem_id` INT(11),
    `code` TEXT,
    `language` VARCHAR(50),
    `status` ENUM('accepted', 'wrong_answer', 'time_limit', 'runtime_error') NOT NULL,
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`problem_id`) REFERENCES `problems`(`id`)
)";
if ($conn->query($sql) === TRUE) {
    echo "Table 'submissions' created or already exists\n";
} else {
    echo "Error creating table 'submissions': " . $conn->error . "\n";
}

// Create contest violations table
$sql = "CREATE TABLE IF NOT EXISTS `contest_violations` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `contest_id` INT(11) NOT NULL,
    `violation_type` VARCHAR(100) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `user_contest_idx` (`user_id`, `contest_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`contest_id`) REFERENCES `contests`(`id`) ON DELETE CASCADE
)";
if ($conn->query($sql) === TRUE) {
    echo "Table 'contest_violations' created or already exists\n";
} else {
    echo "Error creating table 'contest_violations': " . $conn->error . "\n";
}

// Add necessary indexes
$indexQueries = [
    "ALTER TABLE `problems` ADD INDEX IF NOT EXISTS `idx_category` (`category`)",
    "ALTER TABLE `problems` ADD INDEX IF NOT EXISTS `idx_difficulty` (`difficulty`)",
    "ALTER TABLE `problems` ADD INDEX IF NOT EXISTS `idx_problem_type` (`problem_type`)",
    "ALTER TABLE `test_cases` ADD INDEX IF NOT EXISTS `idx_problem_id` (`problem_id`)"
];

foreach ($indexQueries as $query) {
    if ($conn->query($query) === TRUE) {
        echo "Index added successfully\n";
    } else {
        // Don't fail if index already exists or similar issues
        echo "Note: " . $conn->error . "\n";
    }
}

echo "\nDatabase setup completed successfully!\n";
echo "Your Codinger website database is now ready to use.\n";

// Close connection
$conn->close();
?> 