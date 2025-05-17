<?php
// Simple diagnostic script to check problems in database
require_once 'config/db.php';

echo "=== Codinger Database Diagnostics ===\n\n";

// Check if problems table exists
$result = $conn->query("SHOW TABLES LIKE 'problems'");
if ($result->num_rows == 0) {
    echo "ERROR: Problems table doesn't exist!\n";
    exit;
}

// Count total problems
$result = $conn->query("SELECT COUNT(*) as total FROM problems");
$row = $result->fetch_assoc();
echo "Total problems in database: " . $row['total'] . "\n";

// Count unassigned problems (available for contests)
$result = $conn->query("SELECT COUNT(*) as total FROM problems WHERE contest_id IS NULL");
$row = $result->fetch_assoc();
echo "Unassigned problems (available for contests): " . $row['total'] . "\n";

// Check if there are any problems with null values that should not be null
$result = $conn->query("SELECT COUNT(*) as total FROM problems WHERE title IS NULL OR title = ''");
$row = $result->fetch_assoc();
if ($row['total'] > 0) {
    echo "WARNING: " . $row['total'] . " problems have NULL or empty titles\n";
}

// List all problems
echo "\n=== Problems List ===\n";
$result = $conn->query("SELECT id, title, contest_id, created_at FROM problems ORDER BY id ASC");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . 
             ", Title: " . $row['title'] . 
             ", Contest ID: " . ($row['contest_id'] ? $row['contest_id'] : "NULL") . 
             ", Created: " . $row['created_at'] . "\n";
    }
} else {
    echo "No problems found in the database.\n";
}

// Check contests table
echo "\n=== Contests List ===\n";
$result = $conn->query("SELECT id, title, status, created_at FROM contests ORDER BY id ASC");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . 
             ", Title: " . $row['title'] . 
             ", Status: " . $row['status'] . 
             ", Created: " . $row['created_at'] . "\n";
             
        // Check problems assigned to this contest
        $stmt = $conn->prepare("SELECT COUNT(*) as problem_count FROM problems p JOIN contest_problems cp ON p.id = cp.problem_id WHERE cp.contest_id = ?");
        $stmt->bind_param("i", $row['id']);
        $stmt->execute();
        $problemResult = $stmt->get_result();
        $problemRow = $problemResult->fetch_assoc();
        echo "   - Problems assigned: " . $problemRow['problem_count'] . "\n";
    }
} else {
    echo "No contests found in the database.\n";
}

echo "\n=== Diagnostic Complete ===\n";

$result = $conn->query('SELECT id, title, contest_id FROM problems');

echo "<h2>All Problems in Database</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Title</th><th>Contest ID</th></tr>";

while($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['title']}</td>";
    echo "<td>" . ($row['contest_id'] === NULL ? 'NULL' : $row['contest_id']) . "</td>";
    echo "</tr>";
}

echo "</table>";
?> 