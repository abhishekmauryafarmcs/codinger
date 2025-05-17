<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    header("Location: login.php?error=session_expired");
    exit();
}

// Log start of the process
error_log("Starting contest_problems fix script");

// Step 1: Find all problems that have contest_id but don't have an entry in contest_problems
$problems_query = $conn->query("
    SELECT p.id, p.title, p.contest_id 
    FROM problems p 
    LEFT JOIN contest_problems cp ON p.id = cp.problem_id 
    WHERE p.contest_id IS NOT NULL 
    AND cp.id IS NULL
");

$fixed_problems = 0;
$errors = [];

// Step 2: Create missing entries in contest_problems table
if ($problems_query->num_rows > 0) {
    while ($problem = $problems_query->fetch_assoc()) {
        $problem_id = $problem['id'];
        $contest_id = $problem['contest_id'];
        
        error_log("Found problem {$problem['title']} (ID: $problem_id) with contest_id $contest_id but no entry in contest_problems");
        
        // Insert into contest_problems table
        $insert_query = $conn->prepare("
            INSERT INTO contest_problems (contest_id, problem_id) 
            VALUES (?, ?)
        ");
        
        $insert_query->bind_param("ii", $contest_id, $problem_id);
        if ($insert_query->execute()) {
            $fixed_problems++;
            error_log("Fixed: Added problem ID $problem_id to contest_problems with contest ID $contest_id");
        } else {
            $errors[] = "Failed to add problem ID $problem_id to contest_problems: " . $insert_query->error;
            error_log("Error: Failed to add problem ID $problem_id to contest_problems: " . $insert_query->error);
        }
        $insert_query->close();
    }
}

// Step 3: Check for submissions without associated contest_problems entries
$submissions_query = $conn->query("
    SELECT s.id, s.problem_id, s.user_id, p.title
    FROM submissions s
    JOIN problems p ON s.problem_id = p.id
    LEFT JOIN contest_problems cp ON p.id = cp.problem_id
    WHERE cp.id IS NULL
");

$orphaned_submissions = $submissions_query->num_rows;
$fixed_submissions = 0;

if ($orphaned_submissions > 0) {
    error_log("Found $orphaned_submissions submissions without contest_problems associations");
    
    // Get the submission details for debugging
    while ($submission = $submissions_query->fetch_assoc()) {
        $sub_id = $submission['id'];
        $problem_id = $submission['problem_id'];
        $user_id = $submission['user_id'];
        
        error_log("Found orphaned submission ID $sub_id for problem ID $problem_id by user ID $user_id");
        
        // Check if problem exists
        $problem_check = $conn->prepare("SELECT contest_id FROM problems WHERE id = ?");
        $problem_check->bind_param("i", $problem_id);
        $problem_check->execute();
        $problem_result = $problem_check->get_result();
        
        if ($problem_result->num_rows > 0) {
            $problem_data = $problem_result->fetch_assoc();
            $contest_id = $problem_data['contest_id'];
            
            if ($contest_id) {
                error_log("Problem ID $problem_id has contest_id $contest_id, creating contest_problems entry");
                
                // Create the missing contest_problems entry
                $fix_query = $conn->prepare("INSERT INTO contest_problems (contest_id, problem_id) VALUES (?, ?)");
                $fix_query->bind_param("ii", $contest_id, $problem_id);
                
                if ($fix_query->execute()) {
                    $fixed_submissions++;
                    error_log("Fixed: Added problem ID $problem_id to contest_problems with contest ID $contest_id");
                } else {
                    $errors[] = "Failed to add problem ID $problem_id to contest_problems: " . $fix_query->error;
                    error_log("Error: Failed to add problem ID $problem_id to contest_problems: " . $fix_query->error);
                }
                $fix_query->close();
            } else {
                $errors[] = "Problem ID $problem_id has no contest_id, can't fix";
                error_log("Problem ID $problem_id has no contest_id, can't fix");
            }
        } else {
            $errors[] = "Problem ID $problem_id not found";
            error_log("Problem ID $problem_id not found");
        }
        $problem_check->close();
    }
}

// Log completion
error_log("Fix script completed. Fixed $fixed_problems problems and $fixed_submissions submissions");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Contest Problems - Codinger Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container py-5">
        <div class="card">
            <div class="card-header">
                <h3>Fix Contest-Problem Associations</h3>
            </div>
            <div class="card-body">
                <h4>Process Completed</h4>
                
                <div class="alert alert-info">
                    <p><strong>Problems checked:</strong> <?php echo $problems_query->num_rows; ?></p>
                    <p><strong>Problems fixed:</strong> <?php echo $fixed_problems; ?></p>
                    <p><strong>Orphaned submissions found:</strong> <?php echo $orphaned_submissions; ?></p>
                    <p><strong>Submissions fixed:</strong> <?php echo $fixed_submissions; ?></p>
                </div>
                
                <?php if (!empty($errors)): ?>
                <div class="alert alert-warning">
                    <h5>Errors:</h5>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <a href="manage_contests.php" class="btn btn-primary">Back to Contests</a>
            </div>
        </div>
    </div>
</body>
</html> 