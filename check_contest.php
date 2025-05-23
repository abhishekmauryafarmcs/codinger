<?php
require_once 'config/db.php';

// Get the most recent contest
$result = $conn->query("SELECT * FROM contests ORDER BY id DESC LIMIT 1");
$contest = $result->fetch_assoc();

if ($contest) {
    echo "<h2>Contest Details</h2>";
    echo "ID: " . $contest['id'] . "<br>";
    echo "Title: " . $contest['title'] . "<br>";
    echo "Status: " . ($contest['start_time'] <= date('Y-m-d H:i:s') && $contest['end_time'] > date('Y-m-d H:i:s') ? 'Active' : 'Not Active') . "<br>";
    echo "Start Time: " . $contest['start_time'] . "<br>";
    echo "End Time: " . $contest['end_time'] . "<br>";
    echo "Type: " . $contest['type'] . "<br><br>";

    // Get problems for this contest
    $stmt = $conn->prepare("
        SELECT p.* 
        FROM problems p
        JOIN contest_problems cp ON p.id = cp.problem_id
        WHERE cp.contest_id = ?
    ");
    $stmt->bind_param("i", $contest['id']);
    $stmt->execute();
    $problems = $stmt->get_result();

    echo "<h2>Associated Problems</h2>";
    if ($problems->num_rows > 0) {
        while ($problem = $problems->fetch_assoc()) {
            echo "Problem ID: " . $problem['id'] . "<br>";
            echo "Title: " . $problem['title'] . "<br>";
            echo "Points: " . $problem['points'] . "<br><br>";
        }
    } else {
        echo "No problems associated with this contest.<br>";
    }
} else {
    echo "No contests found in the database.";
}
?> 