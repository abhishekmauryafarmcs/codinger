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
    header("Location: manage_contests.php?error=invalid_contest");
    exit();
}

$contest_id = (int)$_GET['id'];

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

// If contest doesn't exist, redirect
if (!$contest) {
    header("Location: manage_contests.php?error=invalid_contest");
    exit();
}

// Get all problems in this contest with their total test cases
$stmt = $conn->prepare("
    SELECT p.id, p.title, p.points, COUNT(tc.id) as total_test_cases
    FROM problems p
    LEFT JOIN test_cases tc ON p.id = tc.problem_id
    WHERE p.contest_id = ?
    GROUP BY p.id
    ORDER BY p.points ASC
");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$problems_result = $stmt->get_result();
$problems = $problems_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all students who participated in the contest with their submissions
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.full_name, u.enrollment_number
    FROM users u
    JOIN submissions s ON u.id = s.user_id
    JOIN problems p ON s.problem_id = p.id
    WHERE p.contest_id = ?
    ORDER BY u.full_name
");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$students_result = $stmt->get_result();
$students = $students_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Create an array to store student performance
$leaderboard = [];

// For each student, get their performance data for each problem
foreach ($students as $student) {
    $student_id = $student['id'];
    $student_data = [
        'id' => $student_id,
        'name' => $student['full_name'],
        'enrollment' => $student['enrollment_number'],
        'total_score' => 0,
        'total_time' => 0,
        'successful_submissions' => 0,
        'problems' => []
    ];
    
    // For each problem, get the student's best submission
    foreach ($problems as $problem) {
        $problem_id = $problem['id'];
        $total_test_cases = $problem['total_test_cases'] > 0 ? $problem['total_test_cases'] : 1;
        $points_per_test_case = $problem['points'] / $total_test_cases;
        
        // Get the student's best submission for this problem
        $stmt = $conn->prepare("
            SELECT s.*, 
                   (SELECT COUNT(*) FROM test_cases WHERE problem_id = ? AND is_visible = 1) as visible_cases,
                   (SELECT COUNT(*) FROM test_cases WHERE problem_id = ? AND is_visible = 0) as hidden_cases
            FROM submissions s
            JOIN (
                SELECT MAX(id) as latest_id
                FROM submissions 
                WHERE user_id = ? AND problem_id = ?
            ) latest ON s.id = latest.latest_id
            LIMIT 1
        ");
        $stmt->bind_param("iiii", $problem_id, $problem_id, $student_id, $problem_id);
        $stmt->execute();
        $submission_result = $stmt->get_result();
        
        if ($submission_result->num_rows > 0) {
            $submission = $submission_result->fetch_assoc();
            
            // Calculate time taken (in seconds from contest start)
            $contest_start = new DateTime($contest['start_time']);
            $submission_time = new DateTime($submission['submitted_at']);
            $time_taken = $contest_start->diff($submission_time);
            $seconds_taken = $time_taken->days * 86400 + $time_taken->h * 3600 + $time_taken->i * 60 + $time_taken->s;
            
            // Use values directly from the submissions table
            $test_cases_passed = $submission['test_cases_passed'] ?? 0;
            $total_test_cases = $submission['total_test_cases'] ?? $total_test_cases;
            $score = $submission['score'] ?? 0;
            
            $problem_data = [
                'problem_id' => $problem_id,
                'score' => $score,
                'max_score' => $problem['points'],
                'time_taken' => $seconds_taken,
                'status' => $submission['status'],
                'submitted_at' => $submission['submitted_at'],
                'test_cases_passed' => $test_cases_passed,
                'total_test_cases' => $total_test_cases
            ];
            
            $student_data['problems'][$problem_id] = $problem_data;
            $student_data['total_score'] += $score;
            $student_data['total_time'] += $seconds_taken;
            
            if ($submission['status'] === 'accepted') {
                $student_data['successful_submissions']++;
            }
        } else {
            // No submission for this problem
            $student_data['problems'][$problem_id] = [
                'problem_id' => $problem_id,
                'score' => 0,
                'max_score' => $problem['points'],
                'time_taken' => 0,
                'status' => 'not_attempted',
                'submitted_at' => null,
                'test_cases_passed' => 0,
                'total_test_cases' => $total_test_cases
            ];
        }
        
        $stmt->close();
    }
    
    $leaderboard[] = $student_data;
}

// Sort the leaderboard:
// 1. Most successful submissions
// 2. Highest total score
// 3. Shortest total time
usort($leaderboard, function($a, $b) {
    // First priority: successful submissions (descending)
    if ($a['successful_submissions'] != $b['successful_submissions']) {
        return $b['successful_submissions'] - $a['successful_submissions'];
    }
    
    // Second priority: total score (descending)
    if ($a['total_score'] != $b['total_score']) {
        return $b['total_score'] - $a['total_score'];
    }
    
    // Third priority: total time (ascending)
    return $a['total_time'] - $b['total_time'];
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contest Results - <?php echo htmlspecialchars($contest['title']); ?> - Codinger Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        .leaderboard {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        .table th {
            position: sticky;
            top: 0;
            background-color: #f8f9fa;
            z-index: 1;
        }
        .problem-header {
            min-width: 100px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .rank-column {
            width: 60px;
        }
        .student-column {
            min-width: 200px;
        }
        .score-column {
            width: 100px;
        }
        .time-column {
            width: 100px;
        }
        .problem-cell {
            text-align: center;
        }
        .problem-cell.accepted {
            background-color: rgba(40, 167, 69, 0.1);
        }
        .problem-cell.partial {
            background-color: rgba(255, 193, 7, 0.1);
        }
        .problem-cell.rejected {
            background-color: rgba(220, 53, 69, 0.1);
        }
        .top-rank {
            font-weight: bold;
        }
        .rank-1 {
            background-color: rgba(255, 215, 0, 0.1);
        }
        .rank-2 {
            background-color: rgba(192, 192, 192, 0.1);
        }
        .rank-3 {
            background-color: rgba(205, 127, 50, 0.1);
        }
        .contest-info {
            margin-bottom: 20px;
        }
        .contest-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .contest-dates {
            color: #666;
        }
        .tooltip-inner {
            max-width: 300px;
            text-align: left;
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
            <h1>Contest Results</h1>
            <a href="manage_contests.php" class="btn btn-secondary">Back to Contests</a>
        </div>

        <div class="contest-info">
            <h2 class="contest-title"><?php echo htmlspecialchars($contest['title']); ?></h2>
            <div class="contest-dates">
                Start: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?> | 
                End: <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h3>Leaderboard</h3>
            </div>
            <div class="card-body p-0">
                <div class="leaderboard">
                    <table class="table table-hover table-striped mb-0">
                        <thead>
                            <tr>
                                <th class="rank-column">Rank</th>
                                <th class="student-column">Student</th>
                                <th class="score-column">Total Score</th>
                                <th class="time-column">Time</th>
                                <?php foreach ($problems as $problem): ?>
                                <th class="problem-header" title="<?php echo htmlspecialchars($problem['title']); ?>">
                                    P<?php echo $problem['id']; ?> (<?php echo $problem['points']; ?>)
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($leaderboard)): ?>
                            <tr>
                                <td colspan="<?php echo 4 + count($problems); ?>" class="text-center">No submissions found for this contest</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($leaderboard as $rank => $student): ?>
                                <tr class="<?php echo $rank < 3 ? 'rank-' . ($rank + 1) : ''; ?>">
                                    <td class="<?php echo $rank < 3 ? 'top-rank' : ''; ?>"><?php echo $rank + 1; ?></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($student['name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($student['enrollment']); ?></small>
                                    </td>
                                    <td class="<?php echo $rank < 3 ? 'top-rank' : ''; ?>"><?php echo round($student['total_score'], 1); ?></td>
                                    <td><?php echo gmdate("H:i:s", $student['total_time']); ?></td>
                                    
                                    <?php foreach ($problems as $problem): ?>
                                        <?php 
                                        $problem_result = $student['problems'][$problem['id']] ?? null;
                                        $status_class = '';
                                        $status_text = 'Not attempted';
                                        
                                        if ($problem_result) {
                                            if ($problem_result['status'] === 'accepted') {
                                                $status_class = 'accepted';
                                                $status_text = 'Accepted';
                                            } elseif ($problem_result['score'] > 0) {
                                                $status_class = 'partial';
                                                $status_text = 'Partial';
                                            } elseif ($problem_result['status'] !== 'not_attempted') {
                                                $status_class = 'rejected';
                                                $status_text = 'Failed';
                                            }
                                        }
                                        ?>
                                        <td class="problem-cell <?php echo $status_class; ?>" 
                                            data-bs-toggle="tooltip" 
                                            data-bs-html="true"
                                            data-bs-title="<?php 
                                                if ($problem_result && $problem_result['status'] !== 'not_attempted') {
                                                    echo "Status: $status_text<br>";
                                                    echo "Score: " . round($problem_result['score'], 1) . "/" . $problem_result['max_score'] . "<br>";
                                                    echo "Test Cases: " . $problem_result['test_cases_passed'] . "/" . $problem_result['total_test_cases'] . "<br>";
                                                    if ($problem_result['submitted_at']) {
                                                        echo "Submitted: " . date('M d, Y h:i:s A', strtotime($problem_result['submitted_at'])) . "<br>";
                                                        echo "Time Taken: " . gmdate("H:i:s", $problem_result['time_taken']);
                                                    }
                                                } else {
                                                    echo "Not attempted";
                                                }
                                            ?>">
                                            <?php 
                                            if ($problem_result && $problem_result['status'] !== 'not_attempted') {
                                                echo round($problem_result['score'], 1) . "/" . $problem_result['max_score']; 
                                            } else {
                                                echo "-";
                                            }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Scoring System</h3>
            </div>
            <div class="card-body">
                <ul>
                    <li><strong>Submission criteria:</strong> Only the most recent submission per problem is counted</li>
                    <li><strong>Points per problem:</strong> Each problem has its own point value</li>
                    <li><strong>Points per test case:</strong> Problem points divided by the number of test cases</li>
                    <li><strong>Ranking criteria:</strong>
                        <ol>
                            <li>Number of successfully submitted problems (all test cases passed)</li>
                            <li>Total score across all problems</li>
                            <li>Total time taken (less time is better)</li>
                        </ol>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function () {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
        });
    </script>
</body>
</html> 