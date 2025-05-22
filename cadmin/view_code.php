<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    header("Location: login.php?error=session_expired");
    exit();
}

// Check if submission ID is provided
if (!isset($_GET['id'])) {
    header("Location: manage_contests.php");
    exit();
}

$submission_id = $_GET['id'];
error_log("Viewing submission with ID: $submission_id");

// Get submission details with user and problem info
$stmt = $conn->prepare("
    SELECT s.*, u.full_name as student_name, p.title as problem_title, 
           cp.contest_id, c.title as contest_title, p.points as problem_points,
           c.start_time, p.id as problem_id
    FROM submissions s
    JOIN users u ON s.user_id = u.id
    JOIN problems p ON s.problem_id = p.id
    JOIN contest_problems cp ON p.id = cp.problem_id
    JOIN contests c ON cp.contest_id = c.id
    WHERE s.id = ?
");

$stmt->bind_param("i", $submission_id);
$stmt->execute();
$result = $stmt->get_result();
$submission = $result->fetch_assoc();

if (!$submission) {
    // Add debug logging
    error_log("Could not find submission with ID: $submission_id");
    
    // Try a simpler query to see if the submission exists at all
    $check_query = $conn->prepare("SELECT * FROM submissions WHERE id = ?");
    $check_query->bind_param("i", $submission_id);
    $check_query->execute();
    $check_result = $check_query->get_result();
    
    if ($check_result->num_rows > 0) {
        $basic_submission = $check_result->fetch_assoc();
        error_log("Basic submission found with problem_id: " . $basic_submission['problem_id'] . " and user_id: " . $basic_submission['user_id']);
        
        // Check if the problem exists
        $problem_query = $conn->prepare("SELECT * FROM problems WHERE id = ?");
        $problem_query->bind_param("i", $basic_submission['problem_id']);
        $problem_query->execute();
        $problem_result = $problem_query->get_result();
        
        if ($problem_result->num_rows > 0) {
            error_log("Problem exists for this submission");
            
            // Check if there's a contest_problems entry
            $cp_query = $conn->prepare("SELECT * FROM contest_problems WHERE problem_id = ?");
            $cp_query->bind_param("i", $basic_submission['problem_id']);
            $cp_query->execute();
            $cp_result = $cp_query->get_result();
            
            if ($cp_result->num_rows > 0) {
                $cp_row = $cp_result->fetch_assoc();
                error_log("Problem is associated with contest ID: " . $cp_row['contest_id']);
            } else {
                error_log("Problem is not associated with any contest in contest_problems table");
            }
        } else {
            error_log("Problem does not exist for this submission");
        }
    } else {
        error_log("No submission exists with this ID");
    }
    
    header("Location: manage_contests.php");
    exit();
}

// Get test case details for this problem
$test_cases_query = $conn->prepare("
    SELECT COUNT(*) as total_cases, 
           SUM(CASE WHEN is_visible = 1 THEN 1 ELSE 0 END) as visible_cases
    FROM test_cases 
    WHERE problem_id = ?
");
$test_cases_query->bind_param("i", $submission['problem_id']);
$test_cases_query->execute();
$test_cases_result = $test_cases_query->get_result()->fetch_assoc();
$test_cases_query->close();

// Calculate time taken (from contest start to submission)
$contest_start = new DateTime($submission['start_time']);
$submission_time = new DateTime($submission['submitted_at']);
$time_diff = $contest_start->diff($submission_time);

$time_taken = '';
if ($time_diff->h > 0) {
    $time_taken .= $time_diff->h . ' hour' . ($time_diff->h > 1 ? 's' : '') . ' ';
}
if ($time_diff->i > 0) {
    $time_taken .= $time_diff->i . ' minute' . ($time_diff->i > 1 ? 's' : '') . ' ';
}
$time_taken .= $time_diff->s . ' second' . ($time_diff->s != 1 ? 's' : '');

// Get the first time the student accessed this problem
$problem_access_stmt = $conn->prepare("
    SELECT MIN(access_time) as first_access
    FROM problem_access_logs
    WHERE user_id = ? AND problem_id = ? AND contest_id = ?
");

// If problem_access_logs table doesn't exist or has no data, 
// we'll continue using the contest start time as fallback
$actual_time_taken = $time_taken;
$has_accurate_time = false;

if ($problem_access_stmt) {
    $problem_access_stmt->bind_param("iii", $submission['user_id'], $submission['problem_id'], $submission['contest_id']);
    $problem_access_stmt->execute();
    $access_result = $problem_access_stmt->get_result();
    
    if ($access_result && $access_result->num_rows > 0) {
        $access_data = $access_result->fetch_assoc();
        if ($access_data['first_access']) {
            $problem_start = new DateTime($access_data['first_access']);
            $actual_time_diff = $problem_start->diff($submission_time);
            
            $actual_time_taken = '';
            if ($actual_time_diff->h > 0) {
                $actual_time_taken .= $actual_time_diff->h . ' hour' . ($actual_time_diff->h > 1 ? 's' : '') . ' ';
            }
            if ($actual_time_diff->i > 0) {
                $actual_time_taken .= $actual_time_diff->i . ' minute' . ($actual_time_diff->i > 1 ? 's' : '') . ' ';
            }
            $actual_time_taken .= $actual_time_diff->s . ' second' . ($actual_time_diff->s != 1 ? 's' : '');
            
            $has_accurate_time = true;
        }
    }
}

// If we couldn't get accurate time, we'll display a note in the UI

// Calculate test case percentage
$total_cases = $test_cases_result['total_cases'] ?? 0;
$test_cases_passed = $submission['test_cases_passed'] ?? 0;
$test_case_percentage = $total_cases > 0 ? round(($test_cases_passed / $total_cases) * 100) : 0;

// Calculate score
$score = 0;
if ($total_cases > 0) {
    $score = ($test_cases_passed / $total_cases) * $submission['problem_points'];
    
    // Add 50 points bonus if all test cases are passed
    if ($test_cases_passed == $total_cases) {
        $score += 50;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submission - Codinger Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/dracula.min.css" rel="stylesheet">
    <style>
        .CodeMirror {
            height: auto;
            min-height: 500px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .submission-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .status-badge {
            font-size: 1rem;
            padding: 8px 15px;
            border-radius: 50px;
            color: white;
            font-weight: 500;
            display: inline-block;
        }
        .status-accepted { background-color: #198754; }
        .status-wrong { background-color: #dc3545; }
        .status-pending { background-color: #ffc107; color: black; }
        
        .submission-info h5 {
            margin-bottom: 1rem;
            font-weight: 600;
            color: #333;
        }
        .submission-info p {
            line-height: 1.7;
            margin-bottom: 0;
        }
        .submission-info strong {
            color: #495057;
        }
        .score-badge {
            background-color: #0d6efd;
            color: white;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 20px;
            margin-bottom: 10px;
            display: inline-block;
        }
        .test-cases-bar {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
            margin-top: 5px;
            overflow: hidden;
        }
        .test-cases-progress {
            height: 100%;
            background-color: #198754;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">Codinger</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage_contests.php' || basename($_SERVER['PHP_SELF']) == 'contest_results.php' || basename($_SERVER['PHP_SELF']) == 'view_submissions.php' || basename($_SERVER['PHP_SELF']) == 'view_code.php') ? 'active' : ''; ?>" href="manage_contests.php">Manage Contests</a>
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

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>View Submission</h1>
            <a href="view_submissions.php?id=<?php echo $submission['contest_id']; ?>" class="btn btn-secondary">Back to Submissions</a>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="submission-info">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h5>Submission Details</h5>
                            <p>
                                <strong>Student:</strong> <?php echo htmlspecialchars($submission['student_name']); ?><br>
                                <strong>Contest:</strong> <?php echo htmlspecialchars($submission['contest_title']); ?><br>
                                <strong>Problem:</strong> <?php echo htmlspecialchars($submission['problem_title']); ?><br>
                                <strong>Language:</strong> <?php echo htmlspecialchars($submission['language']); ?><br>
                                <strong>Submitted:</strong> <?php echo date('M d, Y h:i A', strtotime($submission['submitted_at'])); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <div class="text-md-end">
                                <span class="score-badge">
                                    Score: <?php echo number_format($score, 1); ?>/<?php echo $submission['problem_points']; ?>
                                </span>
                                <p>
                                    <strong>Time Taken:</strong> 
                                    <?php if ($has_accurate_time): ?>
                                        <?php echo $actual_time_taken; ?>
                                    <?php else: ?>
                                        <?php echo $time_taken; ?> <small class="text-muted">(from contest start)</small>
                                    <?php endif; ?>
                                </p>
                                <span class="status-badge <?php 
                                    $badge_class = '';
                                    if ($test_cases_passed == 0) {
                                        $badge_class = 'status-wrong';
                                    } elseif ($test_cases_passed == $total_cases) {
                                        $badge_class = 'status-accepted';
                                    } else {
                                        $badge_class = 'status-pending';
                                    }
                                    echo $badge_class;
                                ?>">
                                    <?php 
                                        if ($test_cases_passed == 0) {
                                            echo 'Wrong Answer';
                                        } elseif ($test_cases_passed == $total_cases) {
                                            echo 'All Accepted';
                                        } else {
                                            echo 'Partially Accepted';
                                        }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <strong>Test Cases:</strong> 
                        <?php echo $test_cases_passed; ?> / <?php echo $total_cases; ?> passed 
                        (<?php echo $test_case_percentage; ?>%)
                        <div class="test-cases-bar">
                            <div class="test-cases-progress" style="width: <?php echo $test_case_percentage; ?>%"></div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Submitted Code</h5>
                    </div>
                    <div class="card-body">
                        <textarea id="codeEditor"><?php echo htmlspecialchars($submission['code']); ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Submission Analysis</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <strong>Status:</strong>
                            <span class="<?php 
                                if ($test_cases_passed == 0) {
                                    echo 'text-danger';
                                } elseif ($test_cases_passed == $total_cases) {
                                    echo 'text-success';
                                } else {
                                    echo 'text-warning';
                                }
                            ?>">
                                <?php 
                                    if ($test_cases_passed == 0) {
                                        echo 'Wrong Answer';
                                    } elseif ($test_cases_passed == $total_cases) {
                                        echo 'All Accepted';
                                    } else {
                                        echo 'Partially Accepted';
                                    }
                                ?>
                            </span>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Test Cases:</strong>
                            <div class="d-flex justify-content-between">
                                <span>Passed: <span class="text-success"><?php echo $test_cases_passed; ?></span></span>
                                <span>Failed: <span class="text-danger"><?php echo $total_cases - $test_cases_passed; ?></span></span>
                                <span>Total: <?php echo $total_cases; ?></span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Submission Time:</strong>
                            <div><?php echo date('M d, Y h:i:s A', strtotime($submission['submitted_at'])); ?></div>
                        </div>
                        
                        <div>
                            <strong>Time Taken:</strong>
                            <div>
                            <?php if ($has_accurate_time): ?>
                                <?php echo $actual_time_taken; ?>
                            <?php else: ?>
                                <?php echo $time_taken; ?> <small class="text-muted">(from contest start)</small>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/clike/clike.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/python/python.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const language = '<?php echo $submission['language']; ?>';
            const modes = {
                'cpp': 'text/x-c++src',
                'java': 'text/x-java',
                'python': 'text/x-python'
            };

            const editor = CodeMirror.fromTextArea(document.getElementById('codeEditor'), {
                lineNumbers: true,
                theme: 'dracula',
                mode: modes[language] || 'text/x-c++src',
                readOnly: true,
                viewportMargin: Infinity
            });
        });
    </script>
</body>
</html> 