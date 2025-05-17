<?php
// Include database connection
require_once 'config/db.php';

echo "<h2>Setting up Codinger Database Tables</h2>";

// SQL to create contest_exits table
$sql = "CREATE TABLE IF NOT EXISTS contest_exits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    contest_id INT NOT NULL,
    exit_time DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (contest_id) REFERENCES contests(id),
    UNIQUE KEY unique_user_contest (user_id, contest_id)
)";

// Execute query
if ($conn->query($sql) === TRUE) {
    echo "<p style='color: green;'>✓ Table 'contest_exits' created successfully!</p>";
} else {
    echo "<p style='color: red;'>✗ Error creating table: " . $conn->error . "</p>";
}

echo "<p>You can now <a href='student/dashboard.php'>return to the dashboard</a>.</p>";

// Close connection
$conn->close();
?> 