<?php
require_once 'config/db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Contest Debug Information</h1>";

// Get the most recent contest
$result = $conn->query("SELECT * FROM contests ORDER BY id DESC LIMIT 1");
if (!$result) {
    die("Error querying contests: " . $conn->error);
}

$contest = $result->fetch_assoc();

if ($contest) {
    echo "<h2>Contest Details</h2>";
    echo "<pre>";
    print_r($contest);
    echo "</pre>";

    // Check if contest is active
    $now = date('Y-m-d H:i:s');
    $isActive = ($contest['start_time'] <= $now && $contest['end_time'] > $now);
    echo "<p>Current time: $now</p>";
    echo "<p>Contest is: " . ($isActive ? 'ACTIVE' : 'NOT ACTIVE') . "</p>";

    // Get problems for this contest
    $stmt = $conn->prepare("
        SELECT p.* 
        FROM problems p
        JOIN contest_problems cp ON p.id = cp.problem_id
        WHERE cp.contest_id = ?
    ");
    
    if (!$stmt) {
        die("Error preparing statement: " . $conn->error);
    }
    
    $stmt->bind_param("i", $contest['id']);
    $stmt->execute();
    $problems = $stmt->get_result();

    echo "<h2>Associated Problems</h2>";
    if ($problems->num_rows > 0) {
        while ($problem = $problems->fetch_assoc()) {
            echo "<pre>";
            print_r($problem);
            echo "</pre>";
        }
        echo "<p>Total problems found: " . $problems->num_rows . "</p>";
    } else {
        echo "<p style='color: red;'>No problems associated with this contest.</p>";
        
        // Debug: Check contest_problems table directly
        echo "<h3>Checking contest_problems table:</h3>";
        $check_cp = $conn->query("SELECT * FROM contest_problems WHERE contest_id = " . $contest['id']);
        if ($check_cp->num_rows > 0) {
            echo "<p>Found " . $check_cp->num_rows . " entries in contest_problems table:</p>";
            while ($cp = $check_cp->fetch_assoc()) {
                echo "<pre>";
                print_r($cp);
                echo "</pre>";
            }
        } else {
            echo "<p>No entries found in contest_problems table for this contest.</p>";
        }
        
        // Debug: Check if problems exist at all
        $check_problems = $conn->query("SELECT COUNT(*) as count FROM problems");
        $problems_count = $check_problems->fetch_assoc();
        echo "<p>Total problems in database: " . $problems_count['count'] . "</p>";
    }
} else {
    echo "<p>No contests found in the database.</p>";
}
?> 