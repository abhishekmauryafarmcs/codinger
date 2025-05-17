<?php
// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'codinger_db';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h1>Database Fix: Adding Missing Columns to Contests Table</h1>";

// Check and fix 'allowed_tab_switches' column
$check_column1 = $conn->query("SHOW COLUMNS FROM `contests` LIKE 'allowed_tab_switches'");
if ($check_column1->num_rows == 0) {
    echo "<p>Column 'allowed_tab_switches' does not exist. Adding it now...</p>";
    
    $add_column1 = $conn->query("ALTER TABLE `contests` ADD COLUMN `allowed_tab_switches` INT(11) DEFAULT 0");
    
    if ($add_column1) {
        echo "<p style='color:green;'>Column 'allowed_tab_switches' added successfully!</p>";
    } else {
        echo "<p style='color:red;'>Error adding column: " . $conn->error . "</p>";
    }
} else {
    echo "<p>Column 'allowed_tab_switches' already exists.</p>";
}

// Check and fix 'prevent_copy_paste' column
$check_column2 = $conn->query("SHOW COLUMNS FROM `contests` LIKE 'prevent_copy_paste'");
if ($check_column2->num_rows == 0) {
    echo "<p>Column 'prevent_copy_paste' does not exist. Adding it now...</p>";
    
    $add_column2 = $conn->query("ALTER TABLE `contests` ADD COLUMN `prevent_copy_paste` TINYINT(1) DEFAULT 0");
    
    if ($add_column2) {
        echo "<p style='color:green;'>Column 'prevent_copy_paste' added successfully!</p>";
    } else {
        echo "<p style='color:red;'>Error adding column: " . $conn->error . "</p>";
    }
} else {
    echo "<p>Column 'prevent_copy_paste' already exists.</p>";
}

// Verify columns exist now
$verify1 = $conn->query("SHOW COLUMNS FROM `contests` LIKE 'allowed_tab_switches'");
$verify2 = $conn->query("SHOW COLUMNS FROM `contests` LIKE 'prevent_copy_paste'");

if ($verify1->num_rows > 0 && $verify2->num_rows > 0) {
    echo "<p style='color:green;'>Verification complete. All required columns exist in the contests table.</p>";
} else {
    echo "<p style='color:red;'>Verification failed. Some columns still don't exist. Please check your database permissions.</p>";
}

// Close connection
$conn->close();

echo "<p><a href='cadmin/create_contest.php' style='display: inline-block; padding: 10px 15px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 4px;'>Return to Create Contest</a></p>";
?> 