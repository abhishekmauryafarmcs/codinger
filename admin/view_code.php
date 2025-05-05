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
           p.contest_id, c.title as contest_title
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submission - Codinger Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        }
        .status-badge {
            font-size: 0.9em;
            padding: 5px 10px;
            border-radius: 15px;
            color: white;
        }
        .status-accepted { background-color: #198754; }
        .status-wrong { background-color: #dc3545; }
        .status-pending { background-color: #ffc107; color: black; }
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