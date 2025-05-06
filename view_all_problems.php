<?php
require_once 'config/db.php';

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