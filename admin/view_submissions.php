<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    header("Location: login.php?error=session_expired");
    exit();
}

// Check if contest ID is provided
if (!isset($_GET['id'])) {
    header("Location: manage_contests.php");
    exit();
}

$contest_id = $_GET['id'];

// Get contest details
$stmt = $conn->prepare("
    SELECT c.*, a.full_name as creator_name 
    FROM contests c 
    LEFT JOIN admins a ON c.created_by = a.id 
    WHERE c.id = ?
");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$contest = $result->fetch_assoc();
$stmt->close();

if (!$contest) {
    header("Location: manage_contests.php");
    exit();
}

// Get all submissions for this contest with user info
// First, check if MySQL supports CTEs (MySQL 8.0+)
$mysql_version_check = $conn->query("SELECT VERSION() as version");
$mysql_version = $mysql_version_check->fetch_assoc()['version'];
$supports_cte = version_compare($mysql_version, '8.0.0', '>=');

if ($supports_cte) {
    // Use CTE approach for MySQL 8.0+
    $stmt = $conn->prepare("
        WITH LatestSubmissions AS (
            SELECT MAX(s.id) as max_id
            FROM submissions s
            JOIN problems p ON s.problem_id = p.id
            WHERE p.contest_id = ?
            GROUP BY s.user_id, s.problem_id
        )
        SELECT s.*, 
               u.full_name as student_name, 
               p.title as problem_title,
               p.points as problem_points,
               s.test_cases_passed,
               (SELECT COUNT(*) FROM test_cases WHERE problem_id = p.id) as total_test_cases,
               (SELECT COUNT(*) FROM submissions s2 
                WHERE s2.user_id = s.user_id 
                AND s2.problem_id = s.problem_id 
                AND s2.status = 'accepted') as successful_submissions
        FROM submissions s
        JOIN users u ON s.user_id = u.id
        JOIN problems p ON s.problem_id = p.id
        JOIN LatestSubmissions ls ON s.id = ls.max_id
        ORDER BY s.submitted_at DESC
    ");
    $stmt->bind_param("i", $contest_id);
} else {
    // Fallback approach for older MySQL versions
    $stmt = $conn->prepare("
        SELECT s.*, 
               u.full_name as student_name, 
               p.title as problem_title,
               p.points as problem_points,
               s.test_cases_passed,
               (SELECT COUNT(*) FROM test_cases WHERE problem_id = p.id) as total_test_cases,
               (SELECT COUNT(*) FROM submissions s2 
                WHERE s2.user_id = s.user_id 
                AND s2.problem_id = s.problem_id 
                AND s2.status = 'accepted') as successful_submissions
        FROM submissions s
        JOIN users u ON s.user_id = u.id
        JOIN problems p ON s.problem_id = p.id
        WHERE p.contest_id = ? AND s.id IN (
            SELECT MAX(s2.id) 
            FROM submissions s2
            JOIN problems p2 ON s2.problem_id = p2.id
            WHERE p2.contest_id = ?
            GROUP BY s2.user_id, s2.problem_id
        )
        ORDER BY s.submitted_at DESC
    ");
    $stmt->bind_param("ii", $contest_id, $contest_id);
}
$stmt->execute();
$result = $stmt->get_result();
$submissions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contest Submissions - <?php echo htmlspecialchars($contest['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        .submission-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 12px;
            padding: 15px;
            transition: all 0.2s ease;
        }
        .submission-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .status-badge {
            font-size: 0.85em;
            padding: 5px 12px;
            border-radius: 50px;
            display: inline-block;
            font-weight: 500;
        }
        .status-accepted { 
            background-color: #198754; 
            color: #fff; 
        }
        .status-wrong { 
            background-color: #dc3545; 
            color: #fff; 
        }
        .status-pending { 
            background-color: #ffc107; 
            color: #000; 
        }
        .submission-card h5 {
            margin-bottom: 0;
            font-size: 1.1rem;
        }
        .text-muted {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">Codinger Admin</a>
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
                        <a class="nav-link" href="students.php">Manage Students</a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1><?php echo htmlspecialchars($contest['title']); ?> - Submissions</h1>
                <p class="text-muted">
                    Created by: <?php echo htmlspecialchars($contest['creator_name']); ?><br>
                    Duration: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?> - 
                             <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?>
                </p>
                <div class="alert alert-info">
                    <small><i class="bi bi-info-circle"></i> Only showing the latest submission for each student per problem. Rankings are determined based on these latest submissions.</small>
                </div>
            </div>
            <a href="manage_contests.php" class="btn btn-secondary">Back to Contests</a>
        </div>

        <?php if (!empty($submissions)): ?>
            <?php foreach ($submissions as $submission): ?>
                <div class="submission-card">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <h5><?php echo htmlspecialchars($submission['student_name']); ?></h5>
                        </div>
                        <div class="col-md-2">
                            <?php
                                $score = 0;
                                if ($submission['total_test_cases'] > 0) {
                                    $score = ($submission['test_cases_passed'] / $submission['total_test_cases']) * $submission['problem_points'];
                                }
                            ?>
                            <p class="mb-0">Score: <?php echo number_format($score, 1); ?>/<?php echo $submission['problem_points']; ?></p>
                        </div>
                        <div class="col-md-2">
                            <p class="mb-0">
                                Successful Submissions: <?php echo $submission['successful_submissions']; ?>
                            </p>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-0 text-muted">
                                Submitted: <?php echo date('M d, Y h:i A', strtotime($submission['submitted_at'])); ?>
                            </p>
                        </div>
                        <div class="col-md-2 text-end">
                            <a href="view_code.php?id=<?php echo $submission['id']; ?>" class="btn btn-primary">View Code</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info">No submissions found for this contest.</div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 