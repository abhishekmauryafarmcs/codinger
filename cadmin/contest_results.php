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

// Add debugging to see what contest is being queried
$debug = [
    'contest_id' => $contest_id,
    'contest_title' => $contest['title'],
    'problems' => [],
    'students' => [],
    'sql_error' => null
];

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

// Add problem info to debug data
foreach ($problems as $problem) {
    $debug['problems'][] = ['id' => $problem['id'], 'title' => $problem['title']];
}

// Get all students who participated in the contest with their submissions
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

// Add student info to debug data
foreach ($students as $student) {
    $debug['students'][] = ['id' => $student['id'], 'name' => $student['full_name']];
}

// If no students have entered this contest, show debug info and exit
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
                
                // We've already verified in the query that this submission belongs to the current contest
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
    <!-- Excel export libraries -->
    <script src="https://cdn.sheetjs.com/xlsx-0.19.3/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/file-saver@2.0.5/dist/FileSaver.min.js"></script>
    <style>
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
        /* Search styles */
        .input-group {
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        #enrollmentSearch {
            border-right: none;
        }
        #enrollmentSearch:focus {
            box-shadow: none;
            border-color: #ced4da;
        }
        .input-group .btn-outline-secondary {
            border-left: none;
            background: white;
        }
        .input-group .btn-outline-secondary:hover {
            background: #f8f9fa;
        }
        .search-highlight {
            background-color: #ffc107;
            padding: 0.1em 0;
            border-radius: 2px;
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
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_contests.php' || basename($_SERVER['PHP_SELF']) == 'contest_results.php' ? 'active' : ''; ?>" href="manage_contests.php">Manage Contests</a>
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
                <h1><?php echo htmlspecialchars($contest['title']); ?> - Results</h1>
                <p class="text-muted">
                    Created by: <?php echo htmlspecialchars($contest['creator_name']); ?><br>
                    Duration: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?> - 
                             <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?>
                </p>
            </div>
            <div>
                <?php 
                // Check if results are published
                $published = isset($contest['results_published']) && $contest['results_published'] == 1;
                $publish_action = $published ? 'unpublish' : 'publish';
                $publish_text = $published ? 'Unpublish Results' : 'Publish Results';
                $publish_class = $published ? 'btn-warning' : 'btn-primary';
                ?>
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="<?php echo $publish_action; ?>_results">
                    <button type="submit" class="btn <?php echo $publish_class; ?> mb-2 me-2">
                        <i class="bi bi-<?php echo $published ? 'eye-slash' : 'eye'; ?>"></i> <?php echo $publish_text; ?>
                    </button>
                </form>
                <button id="exportExcel" class="btn btn-success mb-2 me-2">
                    <i class="bi bi-file-earmark-excel"></i> Export to Excel
                </button>
                <a href="manage_contests.php" class="btn btn-secondary mb-2">Back to Contests</a>
            </div>
        </div>

<?php
// Process publish/unpublish requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'publish_results') {
            // Publish the results
            $stmt = $conn->prepare("UPDATE contests SET results_published = 1 WHERE id = ?");
            $stmt->bind_param("i", $contest_id);
            if ($stmt->execute()) {
                echo '<div class="alert alert-success">Results have been published successfully!</div>';
                // Refresh the page to show updated status
                echo '<script>window.location.href = "contest_results.php?id=' . $contest_id . '";</script>';
            } else {
                echo '<div class="alert alert-danger">Failed to publish results: ' . $conn->error . '</div>';
            }
            $stmt->close();
        } else if ($_POST['action'] === 'unpublish_results') {
            // Unpublish the results
            $stmt = $conn->prepare("UPDATE contests SET results_published = 0 WHERE id = ?");
            $stmt->bind_param("i", $contest_id);
            if ($stmt->execute()) {
                echo '<div class="alert alert-success">Results have been unpublished!</div>';
                // Refresh the page to show updated status
                echo '<script>window.location.href = "contest_results.php?id=' . $contest_id . '";</script>';
            } else {
                echo '<div class="alert alert-danger">Failed to unpublish results: ' . $conn->error . '</div>';
            }
            $stmt->close();
        }
    }
}
?>

        <div class="mt-2">
            <div class="row align-items-center mb-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <input type="text" id="enrollmentSearch" class="form-control" placeholder="Search by Enrollment Number">
                        <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            </div>
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

        <?php if (empty($leaderboard)): ?>
        <!-- Removed the debug information section -->
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
                                <?php foreach ($leaderboard as $rank => $student): ?>
                                <tr class="<?php echo $rank < 3 ? 'rank-' . ($rank + 1) : ''; ?>">
                                    <td class="<?php echo $rank < 3 ? 'top-rank' : ''; ?>">
                                        <?php echo ($student['total_score'] > 0 || $student['total_time'] > 0) ? ($rank + 1) : '-'; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <?php echo htmlspecialchars($student['name']); ?>
                                            <?php if ($student['total_score'] == 0 && $student['total_time'] == 0): ?>
                                                <span class="badge bg-warning">No submissions</span>
                                            <?php endif; ?>
                                        </div>
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
                                                echo round($problem_result['score'], 1); 
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
            
            // Search functionality
            const searchInput = document.getElementById('enrollmentSearch');
            searchInput.addEventListener('input', function() {
                const searchValue = this.value.toLowerCase();
                const tableRows = document.querySelectorAll('.leaderboard tbody tr');
                
                tableRows.forEach(row => {
                    const enrollmentCell = row.querySelector('td:nth-child(2) small');
                    if (enrollmentCell) {
                        const enrollment = enrollmentCell.textContent.toLowerCase();
                        if (enrollment.includes(searchValue)) {
                            row.style.display = '';
                            // Highlight the matching text
                            if (searchValue) {
                                const regex = new RegExp(`(${searchValue})`, 'gi');
                                enrollmentCell.innerHTML = enrollmentCell.textContent.replace(
                                    regex, 
                                    '<span class="bg-warning">$1</span>'
                                );
                            } else {
                                enrollmentCell.innerHTML = enrollmentCell.textContent;
                            }
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            });
            
            // Clear search function
            window.clearSearch = function() {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
            }
            
            // Export to Excel functionality
            document.getElementById('exportExcel').addEventListener('click', function() {
                try {
                    // Verify XLSX library is loaded
                    if (typeof XLSX === 'undefined') {
                        alert('Excel export library not loaded. Please refresh the page and try again.');
                        return;
                    }
                    
                    // Create worksheet data
                    const contestTitle = <?php echo json_encode($contest['title']); ?>;
                    let wsData = [];
                    
                    // Add header row with problem titles
                    const headerRow = ['Rank', 'Student Name', 'Enrollment', 'Total Score', 'Total Time'];
                    
                    <?php foreach ($problems as $problem): ?>
                    headerRow.push(<?php echo json_encode($problem['title'] . ' (' . $problem['points'] . ' pts)'); ?>);
                    <?php endforeach; ?>
                    
                    wsData.push(headerRow);
                    
                    <?php if (!empty($leaderboard)): ?>
                    // Prepare all student data first as JavaScript data
                    const allStudentData = [
                        <?php foreach ($leaderboard as $index => $student): ?>
                        {
                            rank: <?php echo $index + 1; ?>,
                            name: <?php echo json_encode($student['name']); ?>,
                            enrollment: <?php echo json_encode($student['enrollment']); ?>,
                            totalScore: <?php echo $student['total_score']; ?>,
                            formattedTime: formatTime(<?php echo $student['total_time']; ?>),
                            problemScores: [
                                <?php foreach ($problems as $problem): ?>
                                <?php if (isset($student['problems'][$problem['id']])): ?>
                                <?php echo json_encode($student['problems'][$problem['id']]['score']); ?>,
                                <?php else: ?>
                                0,
                                <?php endif; ?>
                                <?php endforeach; ?>
                            ]
                        },
                        <?php endforeach; ?>
                    ];
                    
                    // Now use the prepared data to build the rows
                    allStudentData.forEach(student => {
                        const row = [
                            student.rank,
                            student.name,
                            student.enrollment,
                            student.totalScore,
                            student.formattedTime,
                            ...student.problemScores
                        ];
                        wsData.push(row);
                    });
                    <?php else: ?>
                    // Add an empty row indicating no data
                    wsData.push(["No submissions found for this contest"]);
                    <?php endif; ?>
                    
                    // Create workbook and add worksheet
                    const wb = XLSX.utils.book_new();
                    const ws = XLSX.utils.aoa_to_sheet(wsData);
                    
                    // Set column widths
                    const colWidths = [
                        { wch: 5 },  // Rank
                        { wch: 30 }, // Student Name
                        { wch: 15 }, // Enrollment
                        { wch: 12 }, // Total Score
                        { wch: 12 }  // Total Time
                    ];
                    
                    // Add width for each problem column
                    <?php foreach ($problems as $problem): ?>
                    colWidths.push({ wch: 15 });
                    <?php endforeach; ?>
                    
                    ws['!cols'] = colWidths;
                    
                    // Add worksheet to workbook
                    XLSX.utils.book_append_sheet(wb, ws, 'Leaderboard');
                    
                    // Generate filename: contest-title-results.xlsx
                    const filename = contestTitle.replace(/[^a-z0-9]/gi, '_').toLowerCase() + '-results.xlsx';
                    
                    // Save the file
                    XLSX.writeFile(wb, filename);
                    
                    console.log("Excel file exported successfully!");
                } catch (err) {
                    console.error("Error exporting to Excel:", err);
                    alert("An error occurred while exporting to Excel: " + err.message);
                }
            });
            
            // Helper function to format time in HH:MM:SS format
            function formatTime(seconds) {
                const hrs = Math.floor(seconds / 3600);
                const mins = Math.floor((seconds % 3600) / 60);
                const secs = seconds % 60;
                return `${String(hrs).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
            }
        });
    </script>
</body>
</html> 