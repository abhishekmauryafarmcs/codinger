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

// Get submission details with user and problem info
$stmt = $conn->prepare("
    SELECT s.*, u.full_name as student_name, p.title as problem_title, 
           p.contest_id, c.title as contest_title, p.points as problem_points,
           c.start_time
    FROM submissions s
    JOIN users u ON s.user_id = u.id
    JOIN problems p ON s.problem_id = p.id
    JOIN contests c ON p.contest_id = c.id
    WHERE s.id = ?
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$result = $stmt->get_result();
$submission = $result->fetch_assoc();

if (!$submission) {
    header("Location: manage_contests.php");
    exit();
}

// Calculate the time taken (from contest start to submission)
$contest_start = new DateTime($submission['start_time']);
$submission_time = new DateTime($submission['submitted_at']);
$time_diff = $contest_start->diff($submission_time);
$time_taken_formatted = '';

if ($time_diff->d > 0) {
    $time_taken_formatted .= $time_diff->d . ' day' . ($time_diff->d > 1 ? 's' : '') . ', ';
}
if ($time_diff->h > 0) {
    $time_taken_formatted .= $time_diff->h . ' hour' . ($time_diff->h > 1 ? 's' : '') . ', ';
}
if ($time_diff->i > 0) {
    $time_taken_formatted .= $time_diff->i . ' minute' . ($time_diff->i > 1 ? 's' : '') . ', ';
}
$time_taken_formatted .= $time_diff->s . ' second' . ($time_diff->s != 1 ? 's' : '');

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

// Calculate test case percentage
$total_cases = $test_cases_result['total_cases'] ?? 0;
$test_cases_passed = $submission['test_cases_passed'] ?? 0;
$test_case_percentage = $total_cases > 0 ? round(($test_cases_passed / $total_cases) * 100) : 0;
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
            <h1>View Submission</h1>
            <a href="view_submissions.php?id=<?php echo $submission['contest_id']; ?>" class="btn btn-secondary">Back to Submissions</a>
        </div>

        <div class="submission-info">
            <div class="row">
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
                <div class="col-md-6 text-md-end">
                    <div class="mb-3">
                        <span class="status-badge <?php 
                            echo $submission['status'] === 'accepted' ? 'status-accepted' : 
                                ($submission['status'] === 'pending' ? 'status-pending' : 'status-wrong'); 
                        ?>">
                            <?php 
                                $status = $submission['status'];
                                if ($status === 'wrong_answer') {
                                    echo 'Wrong Answer';
                                } elseif ($status === 'accepted') {
                                    echo 'Accepted';
                                } else {
                                    echo ucfirst($status);
                                }
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">Submitted Code</h5>
                <textarea id="codeEditor"><?php echo htmlspecialchars($submission['code']); ?></textarea>
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