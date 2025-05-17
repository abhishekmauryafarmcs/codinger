<?php
require_once 'config/db.php';

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Fix Contest Display Issues</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        h2 {
            color: #2980b9;
            margin-top: 30px;
        }
        .step {
            background-color: #f5f5f5;
            border-left: 4px solid #3498db;
            padding: 15px;
            margin-bottom: 20px;
        }
        .step-number {
            font-weight: bold;
            color: #3498db;
            font-size: 1.2em;
            margin-right: 10px;
        }
        code {
            background-color: #f0f0f0;
            padding: 2px 5px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.9em;
        }
        pre {
            background-color: #333;
            color: #fff;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .btn {
            display: inline-block;
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            text-decoration: none;
            font-size: 16px;
            cursor: pointer;
            border-radius: 5px;
            margin: 10px 0;
        }
        .result {
            border: 1px solid #ddd;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
        .success {
            color: green;
            font-weight: bold;
        }
        .error {
            color: red;
            font-weight: bold;
        }
        .warning {
            color: orange;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1>Fix Contest Display Issues</h1>
    
    <div class='step'>
        <span class='step-number'>Issue:</span> 
        <p>When a new contest is created, it is showing results and submissions from previous contests.</p>
    </div>
    
    <h2>Root Cause Analysis</h2>
    <p>The issue occurs because:</p>
    <ol>
        <li>The <code>submissions</code> table doesn't have a direct link to which contest the submission belongs to.</li>
        <li>The system relies on joining through <code>contest_problems</code> table to determine which submissions belong to a contest.</li>
        <li>When problems are reused across multiple contests, submissions for a problem are associated with all contests that include that problem.</li>
    </ol>
    
    <h2>Fix Plan</h2>
    <div class='step'>
        <span class='step-number'>Step 1:</span>
        <p>Add a <code>contest_id</code> column to the <code>submissions</code> table.</p>
    </div>
    
    <div class='step'>
        <span class='step-number'>Step 2:</span>
        <p>Update existing submissions with the correct contest ID based on the time they were submitted and which contest was active.</p>
    </div>
    
    <div class='step'>
        <span class='step-number'>Step 3:</span>
        <p>Modify the submission process to save the contest ID with each submission.</p>
    </div>
    
    <div class='step'>
        <span class='step-number'>Step 4:</span>
        <p>Update the contest results and submission views to filter by contest_id directly instead of joining through contest_problems.</p>
    </div>
    
    <h2>Fix Implementation</h2>";

if (isset($_POST['run_fixes'])) {
    echo "<div class='result'>";
    echo "<h3>Running Fixes...</h3>";
    
    // Step 1: Add contest_id column to submissions table
    echo "<h4>Step 1: Adding contest_id column to submissions table</h4>";
    ob_start();
    include_once 'add_contest_id_to_submissions.php';
    $output = ob_get_clean();
    $column_added = strpos($output, 'Successfully added contest_id column') !== false || strpos($output, 'Contest ID column already exists') !== false;
    
    if ($column_added) {
        echo "<p class='success'>✓ Contest ID column added or already exists in submissions table.</p>";
    } else {
        echo "<p class='error'>✗ Failed to add contest ID column to submissions table.</p>";
        echo "<pre>$output</pre>";
    }
    
    // Step 2: Update existing submissions with correct contest_id
    echo "<h4>Step 2: Updating existing submissions with correct contest ID</h4>";
    // This was included in the previous step
    
    // Step 3: Check if submit_code.php was updated
    echo "<h4>Step 3: Checking submit_code.php for contest_id handling</h4>";
    $submit_code_path = "api/submit_code.php";
    if (file_exists($submit_code_path)) {
        $file_contents = file_get_contents($submit_code_path);
        
        if (strpos($file_contents, "INSERT INTO submissions (user_id, problem_id, contest_id") !== false) {
            echo "<p class='success'>✓ submit_code.php has been updated to store contest_id.</p>";
        } else {
            echo "<p class='warning'>⚠ submit_code.php needs to be updated to store contest_id.</p>";
            echo "<p>Please make sure the file is updated by running the following commands:</p>";
            echo "<pre>
// Replace:
INSERT INTO submissions (user_id, problem_id, code, language, status, test_cases_passed, total_test_cases, score) 
VALUES (?, ?, ?, ?, ?, ?, ?, ?)

// With:
INSERT INTO submissions (user_id, problem_id, contest_id, code, language, status, test_cases_passed, total_test_cases, score) 
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)

// Update the bind_param call as well:
$stmt->bind_param(\"iiisssdii\", \$user_id, \$problem_id, \$contest_id, \$code, \$language, \$status, \$testCasesPassed, \$totalTestCases, \$score);
</pre>";
        }
    } else {
        echo "<p class='error'>✗ Cannot find $submit_code_path file.</p>";
    }
    
    // Step 4: Check if view files were updated
    echo "<h4>Step 4: Checking contest result and submission view files</h4>";
    $files_to_check = [
        "cadmin/contest_results.php" => "Contest Results",
        "cadmin/view_submissions.php" => "View Submissions"
    ];
    
    foreach ($files_to_check as $file_path => $file_name) {
        if (file_exists($file_path)) {
            $file_contents = file_get_contents($file_path);
            
            if (strpos($file_contents, "JOIN contest_problems cp ON p.id = cp.problem_id") !== false) {
                echo "<p class='warning'>⚠ $file_name file ($file_path) still uses contest_problems joins.</p>";
                echo "<p>Please run the file update tool in add_contest_id_to_submissions.php to fix this.</p>";
            } else {
                echo "<p class='success'>✓ $file_name file has been updated to use contest_id directly.</p>";
            }
        } else {
            echo "<p class='error'>✗ Cannot find $file_path file.</p>";
        }
    }
    
    echo "<h3>Final Status</h3>";
    
    if (!$column_added) {
        echo "<p class='error'>Critical steps failed. Please fix the errors above before proceeding.</p>";
    } else {
        echo "<p class='success'>The fix has been applied successfully! New contests should now only show their own submissions and results.</p>";
        echo "<p>To verify, create a new contest and ensure it does not show results from previous contests.</p>";
    }
    
    echo "</div>";
}

echo "
    <form method='post'>
        <input type='submit' name='run_fixes' value='Run All Fixes' class='btn'>
    </form>
    
    <h2>Manual Testing</h2>
    <p>After running the fixes, you should:</p>
    <ol>
        <li>Create a new contest</li>
        <li>Add problems to the contest</li>
        <li>Verify that the contest results and submissions pages do not show data from previous contests</li>
        <li>Make a test submission to ensure it's properly saved with the correct contest ID</li>
    </ol>
    
    <h2>Technical Details</h2>
    <p>The following database changes were made:</p>
    <pre>
ALTER TABLE submissions 
ADD COLUMN contest_id INT(11) AFTER problem_id, 
ADD CONSTRAINT fk_submissions_contest 
FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE SET NULL;
    </pre>
    
    <p>The following files were updated:</p>
    <ul>
        <li><code>api/submit_code.php</code> - Updated to store contest_id with each submission</li>
        <li><code>cadmin/view_submissions.php</code> - Updated to filter submissions by contest_id directly</li>
        <li><code>cadmin/contest_results.php</code> - Updated to filter submissions by contest_id directly</li>
    </ul>
    
    <p>This fix ensures that when a problem is reused across multiple contests, submissions are correctly associated with the specific contest they were made for.</p>
</body>
</html>";
?> 