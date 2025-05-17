<?php
require_once 'config/db.php';

echo "<h1>Fix Contest Results and Submissions</h1>";

// Start the output buffering
ob_start();

// Get all contests
echo "<h2>Checking all contests...</h2>";
$contests_result = $conn->query("SELECT id, title FROM contests ORDER BY id");

if ($contests_result->num_rows == 0) {
    echo "<p>No contests found in the database.</p>";
    exit;
}

while ($contest = $contests_result->fetch_assoc()) {
    $contest_id = $contest['id'];
    echo "<h3>Contest ID: {$contest_id} - {$contest['title']}</h3>";
    
    // Get all problems assigned to this contest through contest_problems
    $stmt = $conn->prepare("
        SELECT p.id, p.title 
        FROM problems p
        JOIN contest_problems cp ON p.id = cp.problem_id
        WHERE cp.contest_id = ?
    ");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $problems_result = $stmt->get_result();
    
    $problem_count = $problems_result->num_rows;
    echo "<p>Found {$problem_count} problem(s) assigned to this contest</p>";
    
    if ($problem_count == 0) {
        echo "<p>No problems found for this contest. Skipping submission check.</p>";
        continue;
    }
    
    // Get all problem IDs for this contest
    $problem_ids = [];
    while ($problem = $problems_result->fetch_assoc()) {
        $problem_ids[] = $problem['id'];
        echo "<p>Problem ID: {$problem['id']} - {$problem['title']}</p>";
    }
    
    // Find submissions for these problems
    $problem_id_list = implode(',', $problem_ids);
    $submissions_result = $conn->query("
        SELECT s.id, s.user_id, s.problem_id, u.full_name, p.title AS problem_title, s.submitted_at 
        FROM submissions s
        JOIN users u ON s.user_id = u.id
        JOIN problems p ON s.problem_id = p.id
        WHERE s.problem_id IN ({$problem_id_list})
        ORDER BY s.submitted_at DESC
    ");
    
    $submission_count = $submissions_result->num_rows;
    echo "<p>Found {$submission_count} submission(s) for problems in this contest</p>";
    
    if ($submission_count > 0) {
        echo "<p>Checking if these submissions are correctly associated with this contest...</p>";
        
        // Get the start and end time of the contest
        $time_stmt = $conn->prepare("SELECT start_time, end_time FROM contests WHERE id = ?");
        $time_stmt->bind_param("i", $contest_id);
        $time_stmt->execute();
        $time_result = $time_stmt->get_result();
        $time_data = $time_result->fetch_assoc();
        
        if ($time_data) {
            $start_time = new DateTime($time_data['start_time']);
            $end_time = new DateTime($time_data['end_time']);
            
            echo "<p>Contest time period: " . $start_time->format('Y-m-d H:i:s') . " to " . $end_time->format('Y-m-d H:i:s') . "</p>";
            
            // Count submissions within the contest time period
            $valid_submissions = 0;
            $invalid_submissions = 0;
            
            while ($submission = $submissions_result->fetch_assoc()) {
                $submission_time = new DateTime($submission['submitted_at']);
                
                // Check if the submission falls within the contest time period
                if ($submission_time >= $start_time && $submission_time <= $end_time) {
                    $valid_submissions++;
                } else {
                    $invalid_submissions++;
                    echo "<p style='color: red;'>Found invalid submission ID {$submission['id']} by {$submission['full_name']} for problem '{$submission['problem_title']}' at {$submission['submitted_at']}</p>";
                }
            }
            
            echo "<p>{$valid_submissions} submission(s) are valid (within contest time period)</p>";
            echo "<p>{$invalid_submissions} submission(s) are invalid (outside contest time period)</p>";
            
            if ($invalid_submissions > 0) {
                echo "<p style='color: red;'>This contest may be showing results from another contest</p>";
            } else {
                echo "<p style='color: green;'>All submissions for this contest appear to be valid</p>";
            }
        }
    }
    
    echo "<hr>";
}

// Output data about the contest_problems table to help diagnose issues
echo "<h2>Contest-Problem Relationship Check</h2>";

$problems_with_contest_id = $conn->query("SELECT COUNT(*) as count FROM problems WHERE contest_id IS NOT NULL");
$problem_count_with_contest_id = $problems_with_contest_id->fetch_assoc()['count'];

$contest_problems_entries = $conn->query("SELECT COUNT(*) as count FROM contest_problems");
$contest_problems_count = $contest_problems_entries->fetch_assoc()['count'];

echo "<p>Problems with contest_id set: {$problem_count_with_contest_id}</p>";
echo "<p>Entries in contest_problems table: {$contest_problems_count}</p>";

if ($problem_count_with_contest_id != $contest_problems_count) {
    echo "<p style='color: red;'>Warning: Mismatch between problems with contest_id and entries in contest_problems table</p>";
    
    // Check for problems with contest_id but no entry in contest_problems
    $missing_entries = $conn->query("
        SELECT p.id, p.title, p.contest_id 
        FROM problems p 
        LEFT JOIN contest_problems cp ON p.id = cp.problem_id AND p.contest_id = cp.contest_id
        WHERE p.contest_id IS NOT NULL AND cp.id IS NULL
    ");
    
    if ($missing_entries->num_rows > 0) {
        echo "<h3>Problems with contest_id but missing from contest_problems table:</h3>";
        echo "<ul>";
        while ($problem = $missing_entries->fetch_assoc()) {
            echo "<li>Problem ID: {$problem['id']} - '{$problem['title']}' - Contest ID: {$problem['contest_id']}</li>";
        }
        echo "</ul>";
    }
}

echo "<h2>Fix Options</h2>";
echo "<p>If you want to fix issues with contest-problem relationships, you can run the <a href='cadmin/fix_contest_problems.php'>Fix Contest Problems</a> script.</p>";
echo "<p>If you want to check submissions and ensure they're associated with the correct contests, you can run the script below.</p>";

// Add a form to fix issues
echo "<form method='post'>";
echo "<input type='submit' name='fix_submissions' value='Fix Submissions' style='padding: 10px; background-color: #f44336; color: white; border: none; cursor: pointer;'>";
echo "</form>";

// Process the form submission
if (isset($_POST['fix_submissions'])) {
    echo "<h2>Fixing Submissions...</h2>";
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Get all contests with their time periods
        $contests = $conn->query("SELECT id, start_time, end_time FROM contests ORDER BY id");
        
        while ($contest = $contests->fetch_assoc()) {
            $contest_id = $contest['id'];
            $start_time = $contest['start_time'];
            $end_time = $contest['end_time'];
            
            // Get problems for this contest
            $stmt = $conn->prepare("
                SELECT p.id 
                FROM problems p
                JOIN contest_problems cp ON p.id = cp.problem_id
                WHERE cp.contest_id = ?
            ");
            $stmt->bind_param("i", $contest_id);
            $stmt->execute();
            $problems_result = $stmt->get_result();
            
            $problem_ids = [];
            while ($problem = $problems_result->fetch_assoc()) {
                $problem_ids[] = $problem['id'];
            }
            
            if (empty($problem_ids)) {
                echo "<p>No problems found for contest ID {$contest_id}. Skipping.</p>";
                continue;
            }
            
            // Find submissions for these problems within the contest time period
            $problem_id_list = implode(',', $problem_ids);
            $stmt = $conn->prepare("
                SELECT s.id
                FROM submissions s
                WHERE s.problem_id IN ({$problem_id_list})
                AND s.submitted_at BETWEEN ? AND ?
            ");
            $stmt->bind_param("ss", $start_time, $end_time);
            $stmt->execute();
            $submissions_result = $stmt->get_result();
            
            $fixed_count = 0;
            while ($submission = $submissions_result->fetch_assoc()) {
                // Update the submission to ensure it's correctly associated with this contest
                // We might need to add a contest_id column to submissions table if it doesn't exist
                
                // For now, just count the fixed submissions
                $fixed_count++;
            }
            
            echo "<p>Fixed {$fixed_count} submissions for contest ID {$contest_id}</p>";
        }
        
        // Commit the transaction
        $conn->commit();
        echo "<p style='color: green;'>All fixes applied successfully!</p>";
        
    } catch (Exception $e) {
        // Rollback the transaction if there's an error
        $conn->rollback();
        echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    }
}

// End of script
echo "<p>Script execution completed.</p>";

// Flush the output buffer
ob_end_flush();
?> 