<?php
require_once '../../config/db.php';

try {
    // Create problem_access_logs table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS problem_access_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        problem_id INT NOT NULL,
        contest_id INT NOT NULL,
        access_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        access_count INT DEFAULT 1,
        last_access DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_access (user_id, problem_id, contest_id),
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (problem_id) REFERENCES problems(id),
        FOREIGN KEY (contest_id) REFERENCES contests(id)
    )";
    
    if ($conn->query($sql)) {
        echo "Problem access logs table created successfully";
    } else {
        echo "Error creating table: " . $conn->error;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?> 