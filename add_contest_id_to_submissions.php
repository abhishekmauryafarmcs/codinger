<?php
require_once 'config/db.php';

echo "<h1>Add Contest ID to Submissions Table</h1>";

// Check if contest_id column already exists in submissions table
$result = $conn->query("SHOW COLUMNS FROM submissions LIKE 'contest_id'");
$column_exists = $result->num_rows > 0;

if (!$column_exists) {
    echo "<p>Contest ID column does not exist in submissions table. Adding it now...</p>";
    
    // Add contest_id column to submissions table
    $sql = "ALTER TABLE submissions ADD COLUMN contest_id INT(11) AFTER problem_id, 
            ADD CONSTRAINT fk_submissions_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE SET NULL";
    
    if ($conn->query($sql)) {
        echo "<p style='color: green;'>Successfully added contest_id column to submissions table.</p>";
    } else {
        echo "<p style='color: red;'>Error adding column: " . $conn->error . "</p>";
        exit;
    }
} else {
    echo "<p>Contest ID column already exists in submissions table.</p>";
}

// Count submissions without contest_id
$stmt = $conn->query("SELECT COUNT(*) as count FROM submissions WHERE contest_id IS NULL");
$row = $stmt->fetch_assoc();
$submissions_to_update = $row['count'];

echo "<p>Found {$submissions_to_update} submissions without contest ID.</p>";

if ($submissions_to_update > 0) {
    echo "<h2>Updating Submissions</h2>";
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Get all submissions that need updating
        $submissions = $conn->query("
            SELECT s.id, s.problem_id, s.submitted_at
            FROM submissions s
            WHERE s.contest_id IS NULL
        ");
        
        $updated_count = 0;
        $errors_count = 0;
        
        while ($submission = $submissions->fetch_assoc()) {
            $submission_id = $submission['id'];
            $problem_id = $submission['problem_id'];
            $submitted_at = $submission['submitted_at'];
            
            // Find the contest this problem belongs to at the time of submission
            $stmt = $conn->prepare("
                SELECT cp.contest_id 
                FROM contest_problems cp
                JOIN contests c ON cp.contest_id = c.id
                WHERE cp.problem_id = ?
                AND ? BETWEEN c.start_time AND c.end_time
                ORDER BY c.start_time DESC
                LIMIT 1
            ");
            $stmt->bind_param("is", $problem_id, $submitted_at);
            $stmt->execute();
            $contest_result = $stmt->get_result();
            
            if ($contest_result->num_rows > 0) {
                $contest_data = $contest_result->fetch_assoc();
                $contest_id = $contest_data['contest_id'];
                
                // Update the submission with the correct contest_id
                $update_stmt = $conn->prepare("UPDATE submissions SET contest_id = ? WHERE id = ?");
                $update_stmt->bind_param("ii", $contest_id, $submission_id);
                
                if ($update_stmt->execute()) {
                    $updated_count++;
                } else {
                    $errors_count++;
                    echo "<p style='color: red;'>Error updating submission ID {$submission_id}: " . $conn->error . "</p>";
                }
            } else {
                // If no contest found, look for any contest that has this problem
                $stmt = $conn->prepare("
                    SELECT cp.contest_id 
                    FROM contest_problems cp
                    WHERE cp.problem_id = ?
                    LIMIT 1
                ");
                $stmt->bind_param("i", $problem_id);
                $stmt->execute();
                $contest_result = $stmt->get_result();
                
                if ($contest_result->num_rows > 0) {
                    $contest_data = $contest_result->fetch_assoc();
                    $contest_id = $contest_data['contest_id'];
                    
                    // Update the submission with this contest_id, but mark it as a "best guess"
                    $update_stmt = $conn->prepare("UPDATE submissions SET contest_id = ? WHERE id = ?");
                    $update_stmt->bind_param("ii", $contest_id, $submission_id);
                    
                    if ($update_stmt->execute()) {
                        echo "<p style='color: orange;'>Updated submission ID {$submission_id} with best guess contest ID {$contest_id} (submission time outside contest period)</p>";
                        $updated_count++;
                    } else {
                        $errors_count++;
                        echo "<p style='color: red;'>Error updating submission ID {$submission_id}: " . $conn->error . "</p>";
                    }
                } else {
                    $errors_count++;
                    echo "<p style='color: red;'>No contest found for submission ID {$submission_id}, problem ID {$problem_id}</p>";
                }
            }
        }
        
        // Commit the transaction
        $conn->commit();
        
        echo "<p>Updated {$updated_count} submissions with correct contest ID.</p>";
        if ($errors_count > 0) {
            echo "<p style='color: red;'>{$errors_count} submissions could not be updated.</p>";
        }
        
    } catch (Exception $e) {
        // Rollback the transaction if there's an error
        $conn->rollback();
        echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    }
}

// Let's also update the view_submissions.php and contest_results.php files to use the new contest_id column
// This part just checks if the files need to be updated but doesn't modify them directly
echo "<h2>Checking Contest Results Files</h2>";

// Check cadmin/contest_results.php
$file_path = "cadmin/contest_results.php";
if (file_exists($file_path)) {
    $file_contents = file_get_contents($file_path);
    
    // Check if the file uses JOIN contest_problems cp
    if (strpos($file_contents, "JOIN contest_problems cp") !== false) {
        echo "<p>The file {$file_path} uses the contest_problems join. You may need to update it to use the new contest_id column in submissions.</p>";
    } else {
        echo "<p style='color: green;'>The file {$file_path} does not appear to use contest_problems join directly.</p>";
    }
}

// Check cadmin/view_submissions.php
$file_path = "cadmin/view_submissions.php";
if (file_exists($file_path)) {
    $file_contents = file_get_contents($file_path);
    
    // Check if the file uses JOIN contest_problems cp
    if (strpos($file_contents, "JOIN contest_problems cp") !== false) {
        echo "<p>The file {$file_path} uses the contest_problems join. You may need to update it to use the new contest_id column in submissions.</p>";
    } else {
        echo "<p style='color: green;'>The file {$file_path} does not appear to use contest_problems join directly.</p>";
    }
}

echo "<h2>Next Steps</h2>";
echo "<p>1. Use the following SQL to update the view_submissions.php and contest_results.php files:</p>";
echo "<pre style='background-color: #f5f5f5; padding: 10px; border-radius: 5px;'>
-- Replace:
JOIN problems p ON s.problem_id = p.id
JOIN contest_problems cp ON p.id = cp.problem_id
WHERE cp.contest_id = ?

-- With:
JOIN problems p ON s.problem_id = p.id
WHERE s.contest_id = ?
</pre>";

echo "<p>2. Update the API submit_code.php to store the contest_id when a submission is made:</p>";
echo "<pre style='background-color: #f5f5f5; padding: 10px; border-radius: 5px;'>
// Add contest_id to the INSERT statement:
INSERT INTO submissions (user_id, problem_id, contest_id, code, language, status) 
VALUES (?, ?, ?, ?, ?, ?)
</pre>";

echo "<p>3. Run this script again after making these changes to ensure all submissions have the correct contest ID.</p>";

// Provide a form to fix any view_submissions.php and contest_results.php files
echo "<form method='post'>";
echo "<input type='submit' name='fix_files' value='Update Query Files' style='padding: 10px; background-color: #4CAF50; color: white; border: none; cursor: pointer;'>";
echo "</form>";

// Process the form submission to fix files
if (isset($_POST['fix_files'])) {
    echo "<h2>Updating Files...</h2>";
    
    $files_to_update = [
        "cadmin/contest_results.php",
        "cadmin/view_submissions.php"
    ];
    
    foreach ($files_to_update as $file_path) {
        if (file_exists($file_path)) {
            $file_contents = file_get_contents($file_path);
            
            // Replace the old JOIN pattern with the new one using contest_id
            $old_pattern = "JOIN problems p ON s.problem_id = p.id\s+JOIN contest_problems cp ON p.id = cp.problem_id\s+WHERE cp.contest_id = ?";
            $new_replacement = "JOIN problems p ON s.problem_id = p.id\nWHERE s.contest_id = ?";
            
            $updated_contents = preg_replace("/$old_pattern/", $new_replacement, $file_contents);
            
            if ($updated_contents != $file_contents) {
                // Create a backup of the original file
                file_put_contents($file_path . ".bak", $file_contents);
                
                // Write the updated contents
                if (file_put_contents($file_path, $updated_contents)) {
                    echo "<p style='color: green;'>Successfully updated {$file_path} to use contest_id column. Backup created at {$file_path}.bak</p>";
                } else {
                    echo "<p style='color: red;'>Error updating {$file_path}: Could not write to file.</p>";
                }
            } else {
                echo "<p>No changes needed in {$file_path} or pattern not found.</p>";
            }
        } else {
            echo "<p style='color: red;'>File {$file_path} does not exist.</p>";
        }
    }
}
?> 