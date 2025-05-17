<?php
// Database configuration
require_once 'config/db.php';

$contest_id = isset($_GET['id']) ? (int)$_GET['id'] : 20; // Default to contest ID 20 or use from URL

echo "<h1>Foreign Key Constraint Analysis for Contest ID: $contest_id</h1>";

// Get contest details
$stmt = $conn->prepare("SELECT * FROM contests WHERE id = ?");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$contest = $result->fetch_assoc();

if (!$contest) {
    die("<p style='color:red'>Contest with ID $contest_id not found!</p>");
}

echo "<h2>Contest Details</h2>";
echo "<p>Title: " . htmlspecialchars($contest['title']) . "</p>";
echo "<p>Status: " . htmlspecialchars($contest['status']) . "</p>";

// Check for references in contest_problems table
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM contest_problems WHERE contest_id = ?");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$count = $result->fetch_assoc()['count'];
echo "<h3>contest_problems References</h3>";
echo "<p>Count: $count</p>";

// Get problem IDs
$problem_ids = [];
if ($count > 0) {
    $stmt = $conn->prepare("SELECT problem_id FROM contest_problems WHERE contest_id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $problem_ids[] = $row['problem_id'];
    }
    echo "<p>Problem IDs: " . implode(", ", $problem_ids) . "</p>";
}

// Check for references in contest_enrollments table
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM contest_enrollments WHERE contest_id = ?");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$count = $result->fetch_assoc()['count'];
echo "<h3>contest_enrollments References</h3>";
echo "<p>Count: $count</p>";

// Check for references in contest_violations table
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM contest_violations WHERE contest_id = ?");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$count = $result->fetch_assoc()['count'];
echo "<h3>contest_violations References</h3>";
echo "<p>Count: $count</p>";

// Check for submissions related to problems in this contest
if (!empty($problem_ids)) {
    $problem_id_list = implode(',', $problem_ids);
    $query = "SELECT COUNT(*) as count FROM submissions WHERE problem_id IN ($problem_id_list)";
    $result = $conn->query($query);
    $count = $result->fetch_assoc()['count'];
    echo "<h3>Submissions Related to Contest Problems</h3>";
    echo "<p>Count: $count</p>";
    
    if ($count > 0) {
        // Check submissions per problem
        foreach ($problem_ids as $problem_id) {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM submissions WHERE problem_id = ?");
            $stmt->bind_param("i", $problem_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $submission_count = $result->fetch_assoc()['count'];
            
            // Check if this problem is used in other contests too
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM contest_problems WHERE problem_id = ? AND contest_id != ?");
            $stmt->bind_param("ii", $problem_id, $contest_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $other_contest_count = $result->fetch_assoc()['count'];
            
            echo "<p>Problem ID $problem_id: $submission_count submissions, used in $other_contest_count other contests</p>";
        }
    }
}

// List all foreign key constraints in the database
echo "<h2>All Foreign Key Constraints Affecting Contests Table</h2>";
$query = "
    SELECT 
        TABLE_NAME as table_name,
        COLUMN_NAME as column_name,
        CONSTRAINT_NAME as constraint_name, 
        REFERENCED_TABLE_NAME as referenced_table_name,
        REFERENCED_COLUMN_NAME as referenced_column_name
    FROM
        INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE
        REFERENCED_TABLE_SCHEMA = '$db_name' AND
        (REFERENCED_TABLE_NAME = 'contests' OR TABLE_NAME = 'contests')
";

$result = $conn->query($query);
if ($result) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Table</th><th>Column</th><th>Constraint Name</th><th>Referenced Table</th><th>Referenced Column</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['table_name'] . "</td>";
        echo "<td>" . $row['column_name'] . "</td>";
        echo "<td>" . $row['constraint_name'] . "</td>";
        echo "<td>" . $row['referenced_table_name'] . "</td>";
        echo "<td>" . $row['referenced_column_name'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p>Error fetching constraints: " . $conn->error . "</p>";
}

echo "<h2>Manual Delete Script</h2>";
echo "<pre>";
echo "-- Execute these statements in order to manually delete the contest\n\n";
echo "START TRANSACTION;\n\n";
echo "-- Delete contest_problems associations\n";
echo "DELETE FROM contest_problems WHERE contest_id = $contest_id;\n\n";
echo "-- Delete contest_enrollments\n";
echo "DELETE FROM contest_enrollments WHERE contest_id = $contest_id;\n\n";
echo "-- Delete contest_violations\n";
echo "DELETE FROM contest_violations WHERE contest_id = $contest_id;\n\n";

if (!empty($problem_ids)) {
    echo "-- For each problem in the contest, check if it's used elsewhere\n";
    foreach ($problem_ids as $problem_id) {
        echo "-- Check if problem $problem_id is used in other contests\n";
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM contest_problems WHERE problem_id = ? AND contest_id != ?");
        $stmt->bind_param("ii", $problem_id, $contest_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $other_contest_count = $result->fetch_assoc()['count'];
        
        if ($other_contest_count == 0) {
            echo "-- Problem $problem_id is not used in other contests, safe to delete its submissions\n";
            echo "DELETE FROM submissions WHERE problem_id = $problem_id;\n\n";
        } else {
            echo "-- Problem $problem_id is used in $other_contest_count other contests, keeping its submissions\n\n";
        }
    }
}

echo "-- Finally delete the contest\n";
echo "DELETE FROM contests WHERE id = $contest_id;\n\n";
echo "COMMIT;\n";
echo "</pre>";
?> 