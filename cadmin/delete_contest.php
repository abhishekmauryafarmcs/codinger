<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    header("Location: login.php?error=invalid_credentials");
    exit();
}

// Check if contest ID is provided
if (!isset($_GET['id'])) {
    header("Location: manage_contests.php");
    exit();
}

$contest_id = (int)$_GET['id'];
$error = '';
$contest = null;
$debug_output = '';

// Get contest details for confirmation page
$stmt = $conn->prepare("SELECT title FROM contests WHERE id = ?");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
if ($contest = $result->fetch_assoc()) {
    $contest_title = $contest['title'];
} else {
    header("Location: manage_contests.php?error=contest_not_found");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    // Enable error reporting for debugging
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    
    // Start a transaction
$conn->begin_transaction();

try {
        // Disable foreign key checks temporarily to avoid constraint errors
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        
        // Debug info
        $debug_output .= "Started transaction and disabled foreign key checks\n";
        
        // 1. Get problem IDs associated with this contest first
        $problem_ids = [];
        $stmt = $conn->prepare("SELECT problem_id FROM contest_problems WHERE contest_id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
            $problem_ids[] = $row['problem_id'];
    }
        $debug_output .= "Found " . count($problem_ids) . " problems associated with this contest\n";
        
        // 2. Delete from contest_violations table
        $stmt = $conn->prepare("DELETE FROM contest_violations WHERE contest_id = ?");
        $stmt->bind_param("i", $contest_id);
        $stmt->execute();
        $violations_deleted = $stmt->affected_rows;
        $debug_output .= "Deleted $violations_deleted contest violations\n";
        
        // 3. Delete from contest_enrollments table
        $stmt = $conn->prepare("DELETE FROM contest_enrollments WHERE contest_id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
        $enrollments_deleted = $stmt->affected_rows;
        $debug_output .= "Deleted $enrollments_deleted contest enrollments\n";

        // 4. Delete from contest_problems table
        $stmt = $conn->prepare("DELETE FROM contest_problems WHERE contest_id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
        $contest_problems_deleted = $stmt->affected_rows;
        $debug_output .= "Deleted $contest_problems_deleted contest problem associations\n";
        
        // 5. Check if problems are used in other contests and remove submissions if not
        foreach ($problem_ids as $problem_id) {
            // Check if this problem is used in any other contest
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM contest_problems WHERE problem_id = ?");
            $stmt->bind_param("i", $problem_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            
            // If the problem is not used in any contest (including the one we're deleting), 
            // we can safely delete its submissions
            if ($count == 0) {
                $stmt = $conn->prepare("DELETE FROM submissions WHERE problem_id = ?");
                $stmt->bind_param("i", $problem_id);
                $stmt->execute();
                $submissions_deleted = $stmt->affected_rows;
                $debug_output .= "Deleted $submissions_deleted submissions for problem $problem_id\n";
            } else {
                $debug_output .= "Problem $problem_id is used in other contests, kept its submissions\n";
            }
        }
        
        // 6. Finally delete the contest itself
    $stmt = $conn->prepare("DELETE FROM contests WHERE id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
        $contests_deleted = $stmt->affected_rows;
        $debug_output .= "Deleted $contests_deleted contest record\n";
        
        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        $debug_output .= "Re-enabled foreign key checks\n";

        // Commit the transaction
    $conn->commit();
        $debug_output .= "Committed transaction\n";
    
        // Redirect to contests list with success message
    header("Location: manage_contests.php?deleted=1");
    exit();
} catch (Exception $e) {
        // Re-enable foreign key checks even on error
        $conn->query("SET FOREIGN_KEY_CHECKS=1");

        // Rollback the transaction
    $conn->rollback();
        
        // Set error message for display
        $error = "Error deleting contest: " . $e->getMessage();
        $debug_output .= "ERROR: " . $e->getMessage() . "\n";
        $debug_output .= "Transaction rolled back\n";
        
        // If requested via $_POST['redirect_on_error'], redirect instead of showing error on this page
        if (isset($_POST['redirect_on_error']) && $_POST['redirect_on_error'] == '1') {
            header("Location: manage_contests.php?error=delete_failed&id=" . $contest_id);
    exit();
}
    }
}

// If redirect parameter is set, add the hidden field to the form
$redirect_on_error = isset($_GET['redirect']) && $_GET['redirect'] == '1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Contest - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="../index.php">Codinger</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_contests.php">Manage Contests</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0">Delete Contest</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <h5>Error</h5>
                                <p><?php echo htmlspecialchars($error); ?></p>
                                <h5>Debug Information</h5>
                                <pre><?php echo htmlspecialchars($debug_output); ?></pre>
                                <p>Please try again or contact the administrator for help.</p>
                            </div>
                        <?php endif; ?>
                    
                        <p class="lead">Are you sure you want to delete the contest <strong><?php echo htmlspecialchars($contest_title); ?></strong>?</p>
                        <p>This action cannot be undone. All associated data including problems, enrollments, and submissions for this contest will be permanently deleted.</p>
                        
                        <form method="post">
                            <?php if ($redirect_on_error): ?>
                                <input type="hidden" name="redirect_on_error" value="1">
                            <?php endif; ?>
                            <div class="d-flex mt-4">
                                <button type="submit" name="confirm" value="1" class="btn btn-danger me-2">Yes, Delete Contest</button>
                                <a href="manage_contests.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 