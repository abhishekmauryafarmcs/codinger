<?php
require_once 'config/db.php';

echo "<h1>Creating Problem Access Logs Table</h1>";

// Begin transaction
$conn->begin_transaction();

try {
    // First check if the table already exists
    $result = $conn->query("SHOW TABLES LIKE 'problem_access_logs'");
    
    if ($result->num_rows > 0) {
        echo "<p>Table problem_access_logs already exists.</p>";
    } else {
        // Create the table
        $sql = "CREATE TABLE problem_access_logs (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            problem_id INT(11) NOT NULL,
            contest_id INT(11) NOT NULL,
            access_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (problem_id) REFERENCES problems(id) ON DELETE CASCADE,
            FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
            INDEX idx_user_problem (user_id, problem_id),
            INDEX idx_contest_problem (contest_id, problem_id)
        )";
        
        if ($conn->query($sql)) {
            echo "<p style='color: green;'>✓ Successfully created problem_access_logs table.</p>";
        } else {
            throw new Exception("Error creating table: " . $conn->error);
        }
    }
    
    // Commit transaction
    $conn->commit();
    echo "<p>Database update completed successfully!</p>";
    
    echo "<h2>Next Steps</h2>";
    echo "<p>1. You need to modify the student/contest.php file to log when students access problems.</p>";
    echo "<p>2. Add code to the loadProblem() function to record access time via AJAX.</p>";
    echo "<pre style='background-color: #f5f5f5; padding: 10px; border-radius: 5px;'>
// Add this inside the loadProblem() function in contest.php
fetch('../api/log_problem_access.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        problem_id: problem.id,
        contest_id: <?php echo \$contest_id; ?> // This should be available in the PHP context
    })
})
.then(response => response.json())
.catch(error => console.error('Error logging problem access:', error));
</pre>";
    
} catch (Exception $e) {
    // Rollback transaction
    $conn->rollback();
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

$conn->close();
?>

<a href="index.php" class="btn btn-primary">Return to Home</a> 