<?php
require_once 'config/db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Timezone Information</h1>";

// Show current PHP timezone
echo "<h2>Current Settings:</h2>";
echo "PHP Timezone: " . date_default_timezone_get() . "<br>";
echo "Current PHP Time: " . date('Y-m-d H:i:s') . "<br>";
echo "Current MySQL Time: ";

// Get MySQL timezone and time
$result = $conn->query("SELECT @@time_zone as tz, NOW() as now");
$row = $result->fetch_assoc();
echo $row['tz'] . " / " . $row['now'] . "<br>";

// Set PHP timezone to match MySQL if needed
date_default_timezone_set('Asia/Kolkata'); // or your appropriate timezone

echo "<h2>After Setting Timezone:</h2>";
echo "PHP Timezone: " . date_default_timezone_get() . "<br>";
echo "Current PHP Time: " . date('Y-m-d H:i:s') . "<br>";

// Get contest times
$stmt = $conn->prepare("SELECT id, title, start_time, end_time FROM contests WHERE id = ?");
$contest_id = 97;
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$contest = $stmt->get_result()->fetch_assoc();

echo "<h2>Contest Times:</h2>";
echo "Contest ID: " . $contest['id'] . "<br>";
echo "Title: " . $contest['title'] . "<br>";
echo "Start Time: " . $contest['start_time'] . "<br>";
echo "End Time: " . $contest['end_time'] . "<br>";

// Check if contest is active
$now = date('Y-m-d H:i:s');
$isActive = ($contest['start_time'] <= $now && $contest['end_time'] > $now);
echo "<br>Current Time: " . $now . "<br>";
echo "Contest Status: " . ($isActive ? 'ACTIVE' : 'NOT ACTIVE') . "<br>";
?> 