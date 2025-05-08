<?php
require_once 'config/db.php';

echo "<h1>Debug Problem Loading</h1>";

// Get a list of problems
$result = $conn->query("SELECT id, title, description, contest_id FROM problems");
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Title</th><th>Description</th><th>Contest ID</th></tr>";

while ($problem = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $problem['id'] . "</td>";
    echo "<td>" . $problem['title'] . "</td>";
    echo "<td>" . (empty($problem['description']) ? "<strong>EMPTY</strong>" : substr($problem['description'], 0, 50) . "...") . "</td>";
    echo "<td>" . ($problem['contest_id'] === NULL ? "NULL" : $problem['contest_id']) . "</td>";
    echo "</tr>";
}

echo "</table>";
?> 