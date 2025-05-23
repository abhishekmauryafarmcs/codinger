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

// Debug: Check if problems exist for this contest
$check_problems = $conn->prepare("SELECT COUNT(*) as problem_count FROM problems p JOIN contest_problems cp ON p.id = cp.problem_id WHERE cp.contest_id = ?");
$check_problems->bind_param("i", $contest_id);
$check_problems->execute();
$problem_count = $check_problems->get_result()->fetch_assoc()['problem_count'];
$check_problems->close();

// Debug: Check if submissions exist for these problems
$check_submissions = $conn->prepare("
    SELECT COUNT(*) as submission_count 
    FROM submissions s 
    WHERE s.contest_id = ?
");
$check_submissions->bind_param("i", $contest_id);
$check_submissions->execute();
$submission_count = $check_submissions->get_result()->fetch_assoc()['submission_count'];
$check_submissions->close();

// Add debug information
error_log("Contest ID: $contest_id, Problem Count: $problem_count, Submission Count: $submission_count");

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
            WHERE s.contest_id = ?
            GROUP BY s.user_id, s.problem_id
        )
        SELECT s.*, 
               u.id as user_id,
               u.full_name as student_name, 
               u.enrollment_number,
               p.title as problem_title,
               p.points as problem_points,
               s.test_cases_passed,
               s.total_test_cases,
               (SELECT COUNT(*) FROM submissions s2 
                WHERE s2.user_id = s.user_id 
                AND s2.problem_id = s.problem_id 
                AND s2.status = 'accepted'
                AND s2.contest_id = ?) as successful_submissions
        FROM submissions s
        JOIN users u ON s.user_id = u.id
        JOIN problems p ON s.problem_id = p.id
        JOIN LatestSubmissions ls ON s.id = ls.max_id
        WHERE s.contest_id = ?
        ORDER BY u.full_name ASC, s.submitted_at DESC
    ");
    $stmt->bind_param("iii", $contest_id, $contest_id, $contest_id);
} else {
    // Fallback approach for older MySQL versions
    $stmt = $conn->prepare("
        SELECT s.*, 
               u.id as user_id,
               u.full_name as student_name, 
               u.enrollment_number,
               p.title as problem_title,
               p.points as problem_points,
               s.test_cases_passed,
               s.total_test_cases,
               (SELECT COUNT(*) FROM submissions s2 
                WHERE s2.user_id = s.user_id 
                AND s2.problem_id = s.problem_id 
                AND s2.status = 'accepted'
                AND s2.contest_id = ?) as successful_submissions
        FROM submissions s
        JOIN users u ON s.user_id = u.id
        JOIN problems p ON s.problem_id = p.id
        WHERE s.contest_id = ? AND s.id IN (
            SELECT MAX(s2.id) 
            FROM submissions s2
            WHERE s2.contest_id = ?
            GROUP BY s2.user_id, s2.problem_id
        )
        ORDER BY u.full_name ASC, s.submitted_at DESC
    ");
    $stmt->bind_param("iii", $contest_id, $contest_id, $contest_id);
}
$stmt->execute();
$result = $stmt->get_result();
$all_submissions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Add debugging to show what data we retrieved
error_log("Retrieved " . count($all_submissions) . " submissions for contest ID: $contest_id");

// Group submissions by student
$students = [];
foreach ($all_submissions as $submission) {
    $user_id = $submission['user_id'];
    error_log("Processing submission ID: " . $submission['id'] . " for user ID: " . $user_id);
    
    if (!isset($students[$user_id])) {
        $students[$user_id] = [
            'name' => $submission['student_name'],
            'enrollment' => $submission['enrollment_number'],
            'total_score' => 0,
            'total_submissions' => 0,
            'latest_submission_time' => $submission['submitted_at'],
            'submissions' => []
        ];
    }
    
    // Calculate score for this submission
    $score = 0;
    if ($submission['total_test_cases'] > 0) {
        $score = ($submission['test_cases_passed'] / $submission['total_test_cases']) * $submission['problem_points'];
        // Add 50 points bonus if all test cases are passed
        if ($submission['test_cases_passed'] == $submission['total_test_cases']) {
            $score += 50;
        }
    }
    
    // Add to student's total score
    $students[$user_id]['total_score'] += $score;
    $students[$user_id]['total_submissions']++;
    
    // Track the latest submission time
    if (strtotime($submission['submitted_at']) > strtotime($students[$user_id]['latest_submission_time'])) {
        $students[$user_id]['latest_submission_time'] = $submission['submitted_at'];
    }
    
    // Add submission details
    $students[$user_id]['submissions'][] = $submission;
}

// Sort students by total score (highest first)
uasort($students, function($a, $b) {
    return $b['total_score'] <=> $a['total_score'];
});
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
        .student-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: all 0.2s ease;
        }
        .student-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .student-header {
            padding: 15px;
            border-bottom: 1px solid #eee;
            background-color: #f8f9fa;
        }
        .navbar {
            background: #1a1a1a !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .navbar-brand img {
            height: 48px;
        }
        .navbar .nav-link,
        .navbar .navbar-brand,
        .navbar .navbar-text {
            color: rgba(255,255,255,0.9) !important;
        }
        .navbar .nav-link:hover {
            color: #fff !important;
        }
        .submission-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }
        .submission-item:last-child {
            border-bottom: none;
        }
        .student-name {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0;
        }
        .score-badge {
            background-color: #0d6efd;
            color: white;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 20px;
        }
        .text-muted {
            font-size: 0.9rem;
        }
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="../index.php">
                <img src="../images/LNCT-Logo.png" alt="LNCT Logo">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage_contests.php' || basename($_SERVER['PHP_SELF']) == 'contest_results.php' || basename($_SERVER['PHP_SELF']) == 'view_submissions.php') ? 'active' : ''; ?>" href="manage_contests.php">Manage Contests</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'create_contest.php' ? 'active' : ''; ?>" href="create_contest.php">Create Contest</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_problems.php' ? 'active' : ''; ?>" href="manage_problems.php">Manage Problems</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'active' : ''; ?>" href="students.php">Manage Students</a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link">Welcome, <?php echo isset($_SESSION['admin']['full_name']) ? htmlspecialchars($_SESSION['admin']['full_name']) : 'Administrator'; ?></span>
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
                <?php if (empty($students)): ?>
                <div class="alert alert-warning">
                    <h5>Debug Information:</h5>
                    <p>
                        Problems in contest: <?php echo $problem_count; ?><br>
                        Total submissions: <?php echo $submission_count; ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
            <a href="manage_contests.php" class="btn btn-secondary">Back to Contests</a>
        </div>

        <!-- Search Filter -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <input type="hidden" name="id" value="<?php echo $contest_id; ?>">
                    <div class="col-md-4">
                        <label for="enrollment_search" class="form-label">Search by Enrollment Number</label>
                        <input type="text" class="form-control" id="enrollment_search" name="enrollment" 
                               placeholder="Enter enrollment number" 
                               value="<?php echo isset($_GET['enrollment']) ? htmlspecialchars($_GET['enrollment']) : ''; ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">Search</button>
                        <?php if(isset($_GET['enrollment'])): ?>
                            <a href="view_submissions.php?id=<?php echo $contest_id; ?>" class="btn btn-secondary">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php 
        // Filter students based on enrollment number if search is active
        $filtered_students = $students;
        if(isset($_GET['enrollment']) && !empty($_GET['enrollment'])) {
            $search_term = $_GET['enrollment'];
            $filtered_students = array_filter($students, function($student) use ($search_term) {
                return stripos($student['enrollment'], $search_term) !== false;
            });
        }
        
        if (!empty($filtered_students)): 
        ?>
            <?php foreach ($filtered_students as $student): ?>
                <div class="student-card">
                    <div class="student-header">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h4 class="student-name"><?php echo htmlspecialchars($student['name']); ?></h4>
                                <p class="text-muted mb-0"><?php echo htmlspecialchars($student['enrollment']); ?></p>
                            </div>
                            <div class="col-md-2">
                                <span class="score-badge">
                                    Total Score: <?php echo number_format($student['total_score'], 1); ?>
                                </span>
                            </div>
                            <div class="col-md-2">
                                <p class="mb-0">
                                    <strong>Total Submissions:</strong> <?php echo $student['total_submissions']; ?>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <p class="mb-0 text-muted">
                                    Latest Activity: <?php echo date('M d, Y h:i A', strtotime($student['latest_submission_time'])); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <?php foreach ($student['submissions'] as $submission): ?>
                        <div class="submission-item">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <p class="mb-0"><strong>Problem:</strong> <?php echo htmlspecialchars($submission['problem_title']); ?></p>
                                </div>
                                <div class="col-md-3">
                                    <?php 
                                        $score = 0;
                                        if ($submission['total_test_cases'] > 0) {
                                            $score = ($submission['test_cases_passed'] / $submission['total_test_cases']) * $submission['problem_points'];
                                            // Add 50 points bonus if all test cases are passed
                                            if ($submission['test_cases_passed'] == $submission['total_test_cases']) {
                                                $score += 50;
                                            }
                                        }
                                    ?>
                                    <p class="mb-0">Score: <?php echo number_format($score, 1); ?>/<?php echo $submission['problem_points'] + ($submission['test_cases_passed'] == $submission['total_test_cases'] ? 50 : 0); ?></p>
                                </div>
                                <div class="col-md-3">
                                    <p class="mb-0 text-muted">
                                        Submitted: <?php echo date('M d, Y h:i A', strtotime($submission['submitted_at'])); ?>
                                    </p>
                                </div>
                                <div class="col-md-2 text-end">
                                    <a href="view_code.php?id=<?php echo $submission['id']; ?>" class="btn btn-primary btn-sm">View Code</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info">
                <?php if(isset($_GET['enrollment']) && !empty($_GET['enrollment'])): ?>
                    No students found matching enrollment number "<?php echo htmlspecialchars($_GET['enrollment']); ?>".
                <?php else: ?>
                    No submissions found for this contest.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize code highlighting
            document.querySelectorAll('pre code').forEach((el) => {
                hljs.highlightElement(el);
            });
            
            // Log for debugging
            console.log("Displaying <?php echo count($students); ?> students with submissions");
            <?php
            // Add server-side logging too
            error_log("Displaying " . count($students) . " students with a total of " . count($all_submissions) . " submissions");
            ?>
        });
    </script>
</body>
</html> 