<?php
require_once 'config/db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$contest_id = 97; // Your current contest ID
$problem_ids = [7, 18]; // The problem IDs we want to associate

// Start transaction
$conn->begin_transaction();

try {
    // First, remove any existing associations for these problems
    $stmt = $conn->prepare("DELETE FROM contest_problems WHERE problem_id IN (?, ?)");
    $stmt->bind_param("ii", $problem_ids[0], $problem_ids[1]);
    $stmt->execute();

    // Now add the new associations
    $stmt = $conn->prepare("INSERT INTO contest_problems (contest_id, problem_id) VALUES (?, ?)");
    foreach ($problem_ids as $problem_id) {
        $stmt->bind_param("ii", $contest_id, $problem_id);
        $stmt->execute();
    }

    // Update the problems table to clear or set contest_id if needed
    $stmt = $conn->prepare("UPDATE problems SET contest_id = ? WHERE id IN (?, ?)");
    $stmt->bind_param("iii", $contest_id, $problem_ids[0], $problem_ids[1]);
    $stmt->execute();

    // Commit the transaction
    $conn->commit();
    echo "Successfully updated problem associations!";

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo "Error: " . $e->getMessage();
}

// Verify the changes
$stmt = $conn->prepare("
    SELECT p.id, p.title, cp.contest_id 
    FROM problems p 
    LEFT JOIN contest_problems cp ON p.id = cp.problem_id 
    WHERE p.id IN (?, ?)
");
$stmt->bind_param("ii", $problem_ids[0], $problem_ids[1]);
$stmt->execute();
$result = $stmt->get_result();

echo "<h2>Updated Problem Associations:</h2>";
while ($row = $result->fetch_assoc()) {
    echo "<pre>";
    print_r($row);
    echo "</pre>";
}
?> 