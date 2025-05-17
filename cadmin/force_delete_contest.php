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
$logs = [];
$error = '';
$success = false;

// Get contest details for confirmation page
$stmt = $conn->prepare("SELECT * FROM contests WHERE id = ?");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$contest = $result->fetch_assoc();

if (!$contest) {
    header("Location: manage_contests.php?error=contest_not_found");
    exit();
}

// Function to log a message
function addLog($message) {
    global $logs;
    $logs[] = date('H:i:s') . " - " . $message;
}

// Function to execute a query safely
function executeQuery($conn, $query, $params = []) {
    global $error;
    
    try {
        $stmt = $conn->prepare($query);
        
        if ($stmt === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        
        $result = $stmt->execute();
        
        if ($result === false) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        return $affected;
    } catch (Exception $e) {
        $error = $e->getMessage();
        addLog("ERROR: " . $error);
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    // Begin transaction
    $conn->begin_transaction();
    addLog("Started transaction");
    
    try {
        // Disable foreign key checks temporarily
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        addLog("Disabled foreign key checks");
        
        // 1. Get all problems in this contest
        $problem_ids = [];
        $stmt = $conn->prepare("SELECT problem_id FROM contest_problems WHERE contest_id = ?");
        $stmt->bind_param("i", $contest_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $problem_ids[] = $row['problem_id'];
        }
        addLog("Found " . count($problem_ids) . " problems in contest");
        
        // 2. Delete contest_violations
        $count = executeQuery($conn, "DELETE FROM contest_violations WHERE contest_id = ?", [$contest_id]);
        if ($count !== false) {
            addLog("Deleted $count contest violations");
        }
        
        // 3. Delete contest_enrollments
        $count = executeQuery($conn, "DELETE FROM contest_enrollments WHERE contest_id = ?", [$contest_id]);
        if ($count !== false) {
            addLog("Deleted $count contest enrollments");
        }
        
        // 4. Delete contest_problems
        $count = executeQuery($conn, "DELETE FROM contest_problems WHERE contest_id = ?", [$contest_id]);
        if ($count !== false) {
            addLog("Deleted $count contest problem associations");
        }
        
        // 5. Find submissions related to problems in this contest that aren't used elsewhere
        foreach ($problem_ids as $problem_id) {
            // Check if problem is used in any other contest
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM contest_problems WHERE problem_id = ?");
            $stmt->bind_param("i", $problem_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            
            if ($count == 0) {
                // Delete submissions for this problem
                $deleted = executeQuery($conn, "DELETE FROM submissions WHERE problem_id = ?", [$problem_id]);
                if ($deleted !== false) {
                    addLog("Deleted $deleted submissions for problem $problem_id");
                }
            } else {
                addLog("Problem $problem_id is used in other contests, keeping submissions");
            }
        }
        
        // 6. Delete the contest itself
        $count = executeQuery($conn, "DELETE FROM contests WHERE id = ?", [$contest_id]);
        if ($count !== false) {
            addLog("Deleted contest #$contest_id successfully");
        }
        
        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        addLog("Re-enabled foreign key checks");
        
        // Commit the transaction
        $conn->commit();
        addLog("Transaction committed successfully");
        $success = true;
        
    } catch (Exception $e) {
        // Re-enable foreign key checks on error
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        addLog("Re-enabled foreign key checks after error");
        
        // Rollback transaction
        $conn->rollback();
        addLog("Transaction rolled back due to error");
        
        $error = "Failed to delete contest: " . $e->getMessage();
        addLog("ERROR: " . $error);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Force Delete Contest - Codinger</title>
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
                        <h4 class="mb-0">Force Delete Contest</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <h5>Success!</h5>
                                <p>Contest <strong><?php echo htmlspecialchars($contest['title']); ?></strong> (ID: <?php echo $contest_id; ?>) has been successfully deleted.</p>
                                <a href="manage_contests.php" class="btn btn-primary mt-3">Return to Contest List</a>
                            </div>
                            
                            <h5 class="mt-4">Operations Log</h5>
                            <div class="border p-3 bg-light">
                                <pre class="mb-0"><?php echo implode("\n", $logs); ?></pre>
                            </div>
                        <?php elseif ($error): ?>
                            <div class="alert alert-danger">
                                <h5>Error</h5>
                                <p><?php echo htmlspecialchars($error); ?></p>
                            </div>
                            
                            <h5 class="mt-4">Operations Log</h5>
                            <div class="border p-3 bg-light">
                                <pre class="mb-0"><?php echo implode("\n", $logs); ?></pre>
                            </div>
                            
                            <div class="mt-3">
                                <form method="post">
                                    <button type="submit" name="confirm" value="1" class="btn btn-danger me-2">Try Again</button>
                                    <a href="manage_contests.php" class="btn btn-secondary">Cancel</a>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <strong>Warning!</strong> This is a forced deletion utility that will bypass normal foreign key constraints.
                                Only use this when normal deletion methods fail.
                            </div>
                            
                            <p class="lead">Are you absolutely sure you want to force delete the contest <strong><?php echo htmlspecialchars($contest['title']); ?></strong> (ID: <?php echo $contest_id; ?>)?</p>
                            <p>This action cannot be undone. All related data will be forcefully deleted.</p>
                            
                            <h5 class="mt-4">Contest Details</h5>
                            <table class="table">
                                <tr>
                                    <th>ID</th>
                                    <td><?php echo $contest_id; ?></td>
                                </tr>
                                <tr>
                                    <th>Title</th>
                                    <td><?php echo htmlspecialchars($contest['title']); ?></td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td><?php echo htmlspecialchars($contest['status']); ?></td>
                                </tr>
                                <tr>
                                    <th>Start Time</th>
                                    <td><?php echo htmlspecialchars($contest['start_time']); ?></td>
                                </tr>
                                <tr>
                                    <th>End Time</th>
                                    <td><?php echo htmlspecialchars($contest['end_time']); ?></td>
                                </tr>
                            </table>
                            
                            <form method="post" class="mt-4">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="confirm-check" required>
                                    <label class="form-check-label" for="confirm-check">
                                        I understand that this action is irreversible and may affect data integrity
                                    </label>
                                </div>
                                
                                <div class="d-flex">
                                    <button type="submit" name="confirm" value="1" class="btn btn-danger me-2">Force Delete Contest</button>
                                    <a href="manage_contests.php" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 