<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Check if contest ID is provided
if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

$contest_id = (int)$_GET['id'];
$user_id = $_SESSION['student']['user_id'];

// Get contest details
$stmt = $conn->prepare("
    SELECT *, 
    CASE
        WHEN start_time <= NOW() AND end_time > NOW() THEN 'active'
        WHEN start_time > NOW() THEN 'upcoming'
        ELSE 'completed'
    END as contest_status
    FROM contests 
    WHERE id = ?
");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$contest = $result->fetch_assoc();
$stmt->close();

// If contest doesn't exist, redirect to dashboard
if (!$contest) {
    header("Location: dashboard.php?error=invalid_contest");
    exit();
}

// Check if results are published
$resultsPublished = isset($contest['results_published']) && $contest['results_published'] == 1;

// Get all problems in this contest with their total test cases
$stmt = $conn->prepare("
    SELECT p.id, p.title, p.points, COUNT(tc.id) as total_test_cases
    FROM problems p
    JOIN contest_problems cp ON p.id = cp.problem_id
    LEFT JOIN test_cases tc ON p.id = tc.problem_id
    WHERE cp.contest_id = ?
    GROUP BY p.id
    ORDER BY p.points ASC
");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$problems_result = $stmt->get_result();
$problems = $problems_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all students who participated and calculate results
if ($resultsPublished) {
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id, u.full_name, u.enrollment_number
        FROM users u
        LEFT JOIN submissions s ON u.id = s.user_id AND s.contest_id = ?
        LEFT JOIN problem_access_logs pal ON u.id = pal.user_id AND pal.contest_id = ?
        LEFT JOIN contest_exits ce ON u.id = ce.user_id AND ce.contest_id = ?
        WHERE s.contest_id = ? OR pal.contest_id = ? OR ce.contest_id = ?
        ORDER BY u.full_name
    ");
    $stmt->bind_param("iiiiii", $contest_id, $contest_id, $contest_id, $contest_id, $contest_id, $contest_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
    $students = $students_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // If no students have submissions for this contest
    if (empty($students)) {
        $leaderboard = [];
    } else {
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
                $submission_query = "
                    SELECT s.*, 
                        (SELECT COUNT(*) FROM test_cases WHERE problem_id = ? AND is_visible = 1) as visible_cases,
                        (SELECT COUNT(*) FROM test_cases WHERE problem_id = ? AND is_visible = 0) as hidden_cases
                    FROM submissions s
                    JOIN (
                        SELECT MAX(s2.id) as latest_id
                        FROM submissions s2
                        WHERE s2.user_id = ? 
                        AND s2.problem_id = ?
                        AND s2.contest_id = ?
                    ) latest ON s.id = latest.latest_id
                    LIMIT 1
                ";
                
                $stmt = $conn->prepare($submission_query);
                $stmt->bind_param("iiiii", $problem_id, $problem_id, $student_id, $problem_id, $contest_id);
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
                    
                    // Add 50 points bonus if all test cases are passed
                    if ($test_cases_passed == $total_test_cases && $total_test_cases > 0) {
                        $score += 50;
                    }
                    
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
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contest['title']); ?> - Results - Codinger</title>
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
        /* Add new styles for rank circle */
        .rounded-circle span.display-1 {
            font-size: 3rem !important;
            line-height: 1;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">Codinger</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contests.php">All Contests</a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link">Welcome, <?php echo htmlspecialchars($_SESSION['student']['full_name']); ?></span>
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
                <h1><?php echo htmlspecialchars($contest['title']); ?> - Results</h1>
                <p class="text-muted">
                    Duration: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?> - 
                             <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?>
                </p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-secondary mb-2">Back to Dashboard</a>
            </div>
        </div>

        <?php if (!$resultsPublished): ?>
        <div class="alert alert-warning">
            <h4 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Results Not Published Yet</h4>
            <p>The results for this contest have not been published by the administrator yet. Please check back later.</p>
        </div>
        <?php else: ?>

        <div class="contest-info">
            <h2 class="contest-title"><?php echo htmlspecialchars($contest['title']); ?></h2>
            <div class="contest-dates">
                Start: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?> | 
                End: <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?>
            </div>
            <div class="mt-2">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#scoringInfo" aria-expanded="false" aria-controls="scoringInfo">
                    Scoring System <i class="bi bi-info-circle"></i>
                </button>
                <div class="collapse mt-2" id="scoringInfo">
                    <div class="card card-body">
                        <ul class="mb-0">
                            <li><strong>Submission criteria:</strong> Only the most recent submission per problem is counted</li>
                            <li><strong>Points per problem:</strong> Each problem has its own point value</li>
                            <li><strong>Points per test case:</strong> Problem points divided by the number of test cases</li>
                            <li><strong>Bonus points:</strong> +50 points for passing all test cases of a problem</li>
                            <li><strong>Ranking criteria:</strong>
                                <ol class="mb-0">
                                    <li>Number of successfully submitted problems (all test cases passed)</li>
                                    <li>Total score across all problems (including bonus points)</li>
                                    <li>Total time taken (less time is better)</li>
                                </ol>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <?php 
        /* 
         * Calculating user rank and displaying performance dashboard below 
         * The enhanced version shows more details including rank, score and percentile
         */
        
        // Calculate and display user rank before showing the leaderboard
        $userRank = null;
        $userScore = 0;
        $totalParticipants = count($leaderboard);
        
        if (!empty($leaderboard)) {
            foreach ($leaderboard as $rank => $student) {
                if ($student['id'] == $user_id) {
                    $userRank = $rank + 1;
                    $userScore = $student['total_score'];
                    break;
                }
            }
        }
        
        if ($userRank): 
            // Determine rank medal/color
            $rankClass = '';
            $rankIcon = '';
            
            if ($userRank == 1) {
                $rankClass = 'bg-warning text-dark'; // Gold
                $rankIcon = '<i class="bi bi-trophy-fill text-warning me-2"></i>';
            } elseif ($userRank == 2) {
                $rankClass = 'bg-light text-dark border'; // Silver
                $rankIcon = '<i class="bi bi-trophy-fill text-secondary me-2"></i>';
            } elseif ($userRank == 3) {
                $rankClass = 'bg-danger bg-opacity-25 text-dark'; // Bronze
                $rankIcon = '<i class="bi bi-trophy-fill text-danger me-2"></i>';
            } else {
                $rankClass = 'bg-info bg-opacity-25';
                $rankIcon = '<i class="bi bi-award me-2"></i>';
            }
            
            // Calculate percentile (top X%)
            $percentile = $totalParticipants > 0 ? round(($userRank / $totalParticipants) * 100) : 0;
            $topPercentile = $totalParticipants > 0 ? round(((($totalParticipants - $userRank) + 1) / $totalParticipants) * 100) : 0;
        ?>
        <div class="card mb-4 contest-info border-primary">
            <div class="card-header bg-primary text-white">
                <h3 class="mb-0"><i class="bi bi-person-circle me-2"></i>Your Contest Performance</h3>
            </div>
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-3 text-center">
                        <div class="d-flex align-items-center justify-content-center <?php echo $rankClass; ?> rounded-circle mx-auto" style="width: 120px; height: 120px;">
                            <span class="display-1 fw-bold">#<?php echo $userRank; ?></span>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <h3 class="mb-3"><?php echo $rankIcon; ?>Your Performance in <?php echo htmlspecialchars($contest['title']); ?></h3>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title">Rank</h5>
                                        <p class="card-text fs-4 fw-bold">#<?php echo $userRank; ?> of <?php echo $totalParticipants; ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title">Score</h5>
                                        <p class="card-text fs-4 fw-bold"><?php echo round($userScore, 1); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title">Percentile</h5>
                                        <p class="card-text fs-4 fw-bold">
                                            <?php 
                                            if ($userRank == 1) echo "1st Place!";
                                            elseif ($userRank == 2) echo "2nd Place!";
                                            elseif ($userRank == 3) echo "3rd Place!";
                                            else echo "Top " . $topPercentile . "%";
                                            ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
                                <td colspan="<?php echo 4 + count($problems); ?>" class="text-center">No participants found for this contest</td>
                            </tr>
                            <?php else: ?>
                                <?php 
                                // Highlight current user in results
                                foreach ($leaderboard as $rank => $student): 
                                    $isCurrentUser = ($student['id'] == $user_id);
                                ?>
                                <tr class="<?php echo $rank < 3 ? 'rank-' . ($rank + 1) : ''; ?> <?php echo $isCurrentUser ? 'table-primary' : ''; ?>">
                                    <td class="<?php echo $rank < 3 ? 'top-rank' : ''; ?>"><?php echo $rank + 1; ?></td>
                                    <td>
                                        <div>
                                            <?php echo htmlspecialchars($student['name']); ?>
                                            <?php if ($isCurrentUser): ?>
                                                <span class="badge bg-primary">You</span>
                                            <?php endif; ?>
                                            <?php if ($student['total_score'] == 0 && $student['total_time'] == 0): ?>
                                                <span class="badge bg-secondary">No attempts</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($student['enrollment']); ?></small>
                                    </td>
                                    <td class="<?php echo $rank < 3 ? 'top-rank' : ''; ?>"><?php echo round($student['total_score'], 1); ?></td>
                                    <td><?php echo gmdate("H:i:s", $student['total_time']); ?></td>
                                    
                                    <?php foreach ($problems as $problem): ?>
                                        <?php 
                                        $problem_data = $student['problems'][$problem['id']] ?? null;
                                        $status = $problem_data ? $problem_data['status'] : 'not_attempted';
                                        $score = $problem_data ? $problem_data['score'] : 0;
                                        $max_score = $problem_data ? $problem_data['max_score'] : $problem['points'];
                                        $test_cases = $problem_data ? "{$problem_data['test_cases_passed']}/{$problem_data['total_test_cases']}" : "0/0";
                                        
                                        $cell_class = '';
                                        if ($status === 'accepted') {
                                            $cell_class = 'accepted';
                                        } elseif ($status === 'wrong_answer' || $status === 'runtime_error' || $status === 'compilation_error') {
                                            $cell_class = 'rejected';
                                        } elseif ($score > 0) {
                                            $cell_class = 'partial';
                                        }
                                        ?>
                                        <td class="problem-cell <?php echo $cell_class; ?>" 
                                            data-bs-toggle="tooltip" 
                                            data-bs-html="true"
                                            title="Score: <?php echo $score; ?>/<?php echo $max_score; ?><br>Test Cases: <?php echo $test_cases; ?>">
                                            <?php if ($status === 'not_attempted'): ?>
                                                -
                                            <?php else: ?>
                                                <?php echo round($score, 1); ?>
                                            <?php endif; ?>
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

        <?php endif; // End of results published check ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
        });
    </script>
</body>
</html> 