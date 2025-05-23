<?php
// Database configuration constants
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'codinger_db');

// Create connection with error handling
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Create database if not exists
    $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
    if ($conn->query($sql) === TRUE) {
        $conn->select_db(DB_NAME);
        
        // Create admins table
        $sql = "CREATE TABLE IF NOT EXISTS admins (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->query($sql);
        
        // Create users table (for students only)
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            enrollment_number VARCHAR(50) UNIQUE NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            college_name VARCHAR(200) NOT NULL,
            mobile_number VARCHAR(15) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->query($sql);

        // Create contests table
        $sql = "CREATE TABLE IF NOT EXISTS contests (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            description TEXT,
            start_time DATETIME NOT NULL,
            end_time DATETIME NOT NULL,
            status ENUM('upcoming', 'ongoing', 'completed') DEFAULT 'upcoming',
            type ENUM('public', 'private') DEFAULT 'public',
            created_by INT(11),
            allowed_tab_switches INT(11) DEFAULT 0,
            prevent_copy_paste TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
        )";
        $conn->query($sql);

        // Create contest_enrollments table for private contests
        $sql = "CREATE TABLE IF NOT EXISTS contest_enrollments (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            contest_id INT(11) NOT NULL,
            enrollment_number VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
            UNIQUE KEY (contest_id, enrollment_number)
        )";
        $conn->query($sql);

        // Create problems table
        $sql = "CREATE TABLE IF NOT EXISTS problems (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            contest_id INT(11),
            title VARCHAR(200) NOT NULL,
            description TEXT,
            input_format TEXT,
            output_format TEXT,
            constraints TEXT,
            sample_input TEXT,
            sample_output TEXT,
            points INT(11) DEFAULT 0,
            difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
            category VARCHAR(50) DEFAULT NULL,
            problem_type VARCHAR(50) DEFAULT 'algorithm',
            time_limit INT(11) DEFAULT 1,
            memory_limit INT(11) DEFAULT 256,
            created_by INT(11),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
        )";
        $conn->query($sql);

        // Create submissions table
        $sql = "CREATE TABLE IF NOT EXISTS submissions (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11),
            problem_id INT(11),
            code TEXT,
            language VARCHAR(50),
            status ENUM('accepted', 'wrong_answer', 'time_limit', 'runtime_error') NOT NULL,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (problem_id) REFERENCES problems(id)
        )";
        $conn->query($sql);
        
        // Create contest violations table
        $sql = "CREATE TABLE IF NOT EXISTS contest_violations (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            contest_id INT(11) NOT NULL,
            violation_type VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX user_contest_idx (user_id, contest_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE
        )";
        $conn->query($sql);

        // Create contest_problems table
        $sql = "CREATE TABLE IF NOT EXISTS contest_problems (
            `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
            `contest_id` INT(11) NOT NULL,
            `problem_id` INT(11) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (`contest_id`, `problem_id`),
            FOREIGN KEY (`contest_id`) REFERENCES `contests`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`problem_id`) REFERENCES `problems`(`id`) ON DELETE CASCADE
        )";
        if ($conn->query($sql) === TRUE) {
            // Table created successfully or already exists
        } else {
            // Error creating table
        }
        
        // Create user_sessions table to track active user sessions
        $sql = "CREATE TABLE IF NOT EXISTS user_sessions (
            `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(11) NOT NULL,
            `role` ENUM('student', 'admin') NOT NULL,
            `session_id` VARCHAR(255) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `user_agent` VARCHAR(255) NOT NULL,
            `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`user_id`, `role`),
            UNIQUE KEY `unique_user_session` (`user_id`, `role`, `session_id`)
        )";
        $conn->query($sql);
        
        // Ensure the user_sessions table has the correct structure
        $sql = "SHOW KEYS FROM user_sessions WHERE Key_name = 'session_id'";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            // If the old unique constraint exists, drop it and add the new one
            $sql = "ALTER TABLE `user_sessions` DROP INDEX `session_id`;
                   ALTER TABLE `user_sessions` ADD UNIQUE KEY `unique_user_session` (`user_id`, `role`, `session_id`)";
            $conn->multi_query($sql);
            // Clear results to continue
            while ($conn->more_results() && $conn->next_result()) {
                // Consume any pending results
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            }
        }
        
        // Check and remove any foreign key constraints on user_sessions
        $sql = "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_sessions'
                AND REFERENCED_TABLE_NAME IS NOT NULL";
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            // Drop existing foreign key constraints
            while ($row = $result->fetch_assoc()) {
                $constraint_name = $row['CONSTRAINT_NAME'];
                $sql = "ALTER TABLE user_sessions DROP FOREIGN KEY `$constraint_name`";
                $conn->query($sql);
            }
        }
    }
} catch (Exception $e) {
    error_log("Database Error: " . $e->getMessage());
    die("Database connection failed. Please check your configuration.");
}
?> 