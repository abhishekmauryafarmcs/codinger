<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    // Check if we still have user ID but session was invalidated (another login)
    if (isset($_SESSION['student']['user_id']) && !isset($_SESSION['student']['validated'])) {
        header("Location: ../login.php?error=another_login");
    } else {
        header("Location: ../login.php?error=session_expired");
    }
    exit();
}

// Mark this session as validated for this page load
$_SESSION['student']['validated'] = true;

// Get user's statistics
$user_id = $_SESSION['student']['user_id'];
$user_full_name = $_SESSION['student']['full_name'];

// Get user's enrollment number
$enrollment_number = $_SESSION['student']['enrollment_number'] ?? '';

// Get complete user details for profile
$stmt = $conn->prepare("SELECT enrollment_number, full_name, college_name, mobile_number, email, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user_profile = $result->fetch_assoc();
} else {
    $user_profile = [
        'enrollment_number' => $enrollment_number,
        'full_name' => $user_full_name,
        'college_name' => '',
        'mobile_number' => '',
        'email' => '',
        'created_at' => date('Y-m-d H:i:s')
    ];
}
$stmt->close();

// Get total contests participated
$stmt = $conn->prepare("SELECT COUNT(DISTINCT contest_id) as total_contests FROM submissions WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$contests_result = $stmt->get_result()->fetch_assoc();
$total_contests = $contests_result['total_contests'];
$stmt->close();

// Get successful contests (where all problems were solved with all test cases passed)
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT s1.contest_id) as successful_contests
    FROM submissions s1
    WHERE s1.user_id = ?
    AND NOT EXISTS (
        SELECT 1
        FROM problems p
        JOIN contest_problems cp ON p.id = cp.problem_id
        WHERE cp.contest_id = s1.contest_id
        AND NOT EXISTS (
            SELECT 1
            FROM submissions s2
            WHERE s2.user_id = s1.user_id
            AND s2.contest_id = s1.contest_id
            AND s2.problem_id = p.id
            AND s2.status = 'accepted'
            AND s2.test_cases_passed = s2.total_test_cases
        )
    )
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$successful_result = $stmt->get_result()->fetch_assoc();
$successful_contests = $successful_result['successful_contests'];
$stmt->close();

// Calculate success rate
$success_rate = $total_contests > 0 ? round(($successful_contests / $total_contests) * 100) : 0;

// Set timezone and get current time
date_default_timezone_set('Asia/Kolkata'); // Or your server's timezone
$current_time_dt = new DateTime();
$current_time_sql = $current_time_dt->format('Y-m-d H:i:s');

// Debug current time
error_log("Current Time: " . $current_time_sql);

$error = ''; // Initialize error variable

// Process termination form submission from prevent_cheating.js
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['termination_reason']) && isset($_POST['status'])) {
    $termination_reason = $_POST['termination_reason'];
    $status = $_POST['status'];
    
    if ($status === 'terminated') {
        // Log the termination to the database if needed
        if ($termination_reason === 'page_switch_violations') {
            // You could log this to a dedicated table if needed
            $error = 'contest_terminated';
            $error_message = 'Your contest has been terminated due to multiple page switching violations.';
        }
    }
}

$error_messages = [
    'session_expired' => 'Your session has expired. Please log in again.',
    'contest_not_started' => 'The contest has not started yet.',
    'contest_ended' => 'The contest has ended.',
    'invalid_contest' => 'Invalid contest ID.',
    'enrollment_required' => 'You need to be enrolled to participate in this contest.',
    'contest_exited' => 'You have exited this contest and cannot re-enter.',
    'contest_terminated' => 'Your access to this contest has been permanently revoked due to rule violations.',
    'user_not_found' => 'User not found.'
];

$error_get = isset($_GET['error']) ? $_GET['error'] : '';
// Check for custom termination message from the URL
$custom_message = isset($_GET['message']) ? $_GET['message'] : '';

// If there's a custom message provided (especially for terminations), use it
if (!empty($custom_message)) {
    $error_message_display = htmlspecialchars($custom_message);
} else {
    $error_message_display = isset($error_messages[$error_get]) ? $error_messages[$error_get] : (isset($error_messages[$error]) ? $error_messages[$error] : '');
}

// Update the alert type based on error type
$alert_type = 'danger';
if ($error_get === 'contest_terminated') {
    $alert_type = 'danger';
} elseif ($error_get === 'contest_exited') {
    $alert_type = 'warning';
}

// Display success messages
if (isset($_GET['exit_status']) && $_GET['exit_status'] === 'success') {
    echo '<div class="alert alert-success">You have successfully exited the contest.</div>';
} elseif (isset($_GET['exit_status']) && $_GET['exit_status'] === 'error') {
    echo '<div class="alert alert-warning">There was an issue recording your contest exit, but you have been redirected to the dashboard.</div>';
}

// Active Contests
$active_query = "
    SELECT c.*, 
    TIMESTAMPDIFF(SECOND, NOW(), c.end_time) as seconds_remaining
    FROM contests c 
    WHERE c.start_time <= NOW()
    AND c.end_time > NOW()
    AND (
        c.type = 'public' 
        OR (
            c.type = 'private' 
            AND EXISTS (
                SELECT 1 FROM contest_enrollments ce 
                WHERE ce.contest_id = c.id 
                AND ce.enrollment_number = ?
            )
        )
    )
    ORDER BY c.end_time ASC";
$stmt_active = $conn->prepare($active_query);
$stmt_active->bind_param("s", $enrollment_number);
$stmt_active->execute();
$active_contests_result = $stmt_active->get_result();
$stmt_active->close();

// Upcoming Contests
$upcoming_query = "
    SELECT c.*, TIMESTAMPDIFF(SECOND, NOW(), c.start_time) as seconds_to_start
    FROM contests c 
    WHERE c.start_time > NOW()
    AND (
        c.type = 'public' 
        OR (
            c.type = 'private' 
            AND EXISTS (
                SELECT 1 FROM contest_enrollments ce 
                WHERE ce.contest_id = c.id 
                AND ce.enrollment_number = ?
            )
        )
    )
    ORDER BY c.start_time ASC 
    LIMIT 5";
$stmt_upcoming = $conn->prepare($upcoming_query);
$stmt_upcoming->bind_param("s", $enrollment_number);
$stmt_upcoming->execute();
$upcoming_contests_result = $stmt_upcoming->get_result();
$stmt_upcoming->close();

// Completed Contests
$completed_query = "
    SELECT c.* 
    FROM contests c 
    WHERE c.end_time <= NOW()
    AND (
        c.type = 'public' 
        OR (
            c.type = 'private' 
            AND EXISTS (
                SELECT 1 FROM contest_enrollments ce 
                WHERE ce.contest_id = c.id 
                AND ce.enrollment_number = ?
            )
        )
    )
    ORDER BY c.end_time DESC 
    LIMIT 5";
$stmt_completed = $conn->prepare($completed_query);
$stmt_completed->bind_param("s", $enrollment_number);
$stmt_completed->execute();
$completed_contests_result = $stmt_completed->get_result();
$stmt_completed->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        body {
            padding-top: 70px; /* Adjust for fixed navbar height */
            background-color: #f8f9fa; /* Light background for the page */
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .welcome-banner {
            background: linear-gradient(to right, #007bff, #0056b3);
            color: white;
            padding: 2rem 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
        }
        .welcome-banner h1 {
            font-weight: 300;
        }
        .stats-card {
            background-color: #ffffff;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            display: flex;
            align-items: center;
            transition: transform 0.2s ease-in-out;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card .icon {
            font-size: 2.5rem;
            margin-right: 1rem;
            color: #007bff;
        }
        .stats-card .stat-info h3 {
            font-size: 1rem;
            color: #6c757d;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }
        .stats-card .stat-info p {
            font-size: 1.75rem;
            font-weight: 600;
            color: #343a40;
            margin-bottom: 0;
        }
        .contest-section h2 {
            margin-bottom: 1.5rem;
            font-weight: 500;
            color: #343a40;
            border-bottom: 2px solid #007bff;
            padding-bottom: 0.5rem;
            display: inline-block;
        }
        .contest-card {
            background-color: #ffffff;
            border: none;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            transition: box-shadow 0.2s ease-in-out;
        }
        .contest-card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }
        .contest-card .card-header {
            background-color: #007bff;
            color: white;
            font-weight: 500;
            border-bottom: none;
            border-top-left-radius: 0.5rem;
            border-top-right-radius: 0.5rem;
        }
         .contest-card .card-header.bg-success {
            background-color: #28a745 !important;
        }
        .contest-card .card-header.bg-warning {
            background-color: #ffc107 !important;
            color: #212529 !important;
        }
        .contest-card .card-header.bg-secondary {
            background-color: #6c757d !important;
        }

        .contest-card .card-body {
            padding: 1.5rem;
        }
        .contest-card .card-title {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        .contest-card .card-text {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        .contest-card .time-remaining, .contest-card .time-to-start {
            font-weight: 500;
            color: #dc3545;
        }
         .contest-card .time-to-start {
            color: #198754;
        }
        .contest-card .btn {
            margin-top: 1rem;
        }
        .countdown-timer {
            font-size: 1.1rem;
            font-weight: bold;
        }
        .footer {
            text-align: center;
            padding: 1.5rem 0;
            margin-top: 2rem;
            background-color: #e9ecef;
            color: #6c757d;
            font-size: 0.9rem;
        }
        .alert-dismissible {
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container">
            <a class="navbar-brand" href="../index.php"><i class="bi bi-code-slash"></i> Codinger</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($user_full_name); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item" href="#" onclick="openProfileDialog(); return false;"><i class="bi bi-person-fill"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#clearTerminationDataModal"><i class="bi bi-eraser"></i> Fix Contest Access</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">

        <?php if (!empty($error_message_display)): ?>
            <?php if ($error_get === 'contest_terminated'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-left: 4px solid #dc3545; box-shadow: 0 0.5rem 1rem rgba(220, 53, 69, 0.15);">
                    <h4 class="alert-heading">
                        <?php 
                        $icon = 'exclamation-triangle-fill';
                        if (isset($_GET['reason'])) {
                            $reason = htmlspecialchars($_GET['reason']);
                            if (strpos($reason, 'fullscreen') !== false) {
                                $icon = 'arrows-fullscreen';
                            } elseif (strpos($reason, 'developer') !== false) {
                                $icon = 'tools';
                            } elseif (strpos($reason, 'page_switch') !== false) {
                                $icon = 'window-stack';
                            }
                        }
                        echo '<i class="bi bi-' . $icon . ' text-danger"></i> Contest Access Denied';
                        ?>
                    </h4>
                    <p><?php echo htmlspecialchars($error_message_display); ?></p>
                    <hr>
                    <p class="mb-0">If you believe this is an error, please contact an administrator.</p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php elseif ($error_get === 'contest_exited'): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error_message_display); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php else: ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error_message_display); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="welcome-banner">
            <h1>Welcome back, <?php echo htmlspecialchars($user_full_name); ?>!</h1>
            <p class="lead">Ready to tackle some challenges? Your coding journey continues here.</p>
        </div>

        <!-- Statistics -->
        <div class="row dashboard-stats">
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="icon"><i class="bi bi-journal-code"></i></div>
                    <div class="stat-info">
                    <h3>Total Contests</h3>
                        <p><?php echo $total_contests; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="icon"><i class="bi bi-check-circle-fill text-success"></i></div>
                     <div class="stat-info">
                    <h3>Successful Contests</h3>
                        <p><?php echo $successful_contests; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="icon"><i class="bi bi-graph-up-arrow text-primary"></i></div>
                    <div class="stat-info">
                    <h3>Success Rate</h3>
                        <p><?php echo $success_rate; ?>%</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Contests -->
        <section class="contest-section" id="active-contests">
            <h2><i class="bi bi-play-circle-fill text-danger"></i> Active Contests</h2>
            <div class="row">
                <?php if ($active_contests_result->num_rows > 0): ?>
                    <?php while ($contest = $active_contests_result->fetch_assoc()): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="contest-card">
                                <div class="card-header bg-danger">
                                    <?php echo htmlspecialchars($contest['title']); ?>
                                    <?php if ($contest['type'] === 'private'): ?>
                                        <span class="badge bg-light text-dark float-end"><i class="bi bi-lock-fill"></i> Private</span>
                                    <?php endif; ?>
                                </div>
                            <div class="card-body">
                                    <p class="card-text"><i class="bi bi-info-circle"></i> <?php echo substr(htmlspecialchars($contest['description']), 0, 100) . '...'; ?></p>
                                    <p class="card-text"><i class="bi bi-calendar-event"></i> Ends: <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?></p>
                                    <p class="card-text time-remaining countdown-timer" data-seconds-remaining="<?php echo $contest['seconds_remaining']; ?>">
                                        <i class="bi bi-hourglass-split"></i> Time Remaining: <span id="timer-<?php echo $contest['id']; ?>"></span>
                                    </p>
                                    <a href="#" class="btn btn-danger w-100" onclick="showContestInfoModal(<?php echo $contest['id']; ?>); return false;"><i class="bi bi-arrow-right-square"></i> Enter Contest</a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col">
                        <div class="alert alert-info"><i class="bi bi-info-circle"></i> No active contests at the moment. Check back soon!</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Upcoming Contests -->
        <section class="contest-section mt-4" id="upcoming-contests">
            <h2><i class="bi bi-calendar-plus-fill text-success"></i> Upcoming Contests</h2>
            <div class="row">
                <?php if ($upcoming_contests_result->num_rows > 0): ?>
                    <?php while ($contest = $upcoming_contests_result->fetch_assoc()): ?>
                         <div class="col-md-6 col-lg-4">
                            <div class="contest-card">
                                <div class="card-header bg-success">
                                    <?php echo htmlspecialchars($contest['title']); ?>
                                    <?php if ($contest['type'] === 'private'): ?>
                                        <span class="badge bg-light text-dark float-end"><i class="bi bi-lock-fill"></i> Private</span>
                                    <?php endif; ?>
                                </div>
                            <div class="card-body">
                                    <p class="card-text"><i class="bi bi-info-circle"></i> <?php echo substr(htmlspecialchars($contest['description']), 0, 100) . '...'; ?></p>
                                    <p class="card-text"><i class="bi bi-calendar-event"></i> Starts: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?></p>
                                     <p class="card-text time-to-start countdown-timer" data-seconds-to-start="<?php echo $contest['seconds_to_start']; ?>">
                                        <i class="bi bi-hourglass-bottom"></i> Starts In: <span id="timer-upcoming-<?php echo $contest['id']; ?>"></span>
                                    </p>
                                    <button class="btn btn-success w-100" disabled><i class="bi bi-clock-history"></i> Not Started Yet</button>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col">
                        <div class="alert alert-info"><i class="bi bi-info-circle"></i> No upcoming contests scheduled yet.</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Past Contests -->
        <section class="contest-section mt-4" id="past-contests">
            <h2><i class="bi bi-check-circle-fill text-secondary"></i> Past Contests</h2>
            <div class="row">
                <?php if ($completed_contests_result->num_rows > 0): ?>
                    <?php while ($contest = $completed_contests_result->fetch_assoc()): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="contest-card">
                                <div class="card-header bg-secondary">
                                    <?php echo htmlspecialchars($contest['title']); ?>
                                     <?php if ($contest['type'] === 'private'): ?>
                                        <span class="badge bg-light text-dark float-end"><i class="bi bi-lock-fill"></i> Private</span>
                                    <?php endif; ?>
                                </div>
                            <div class="card-body">
                                    <p class="card-text"><i class="bi bi-info-circle"></i> <?php echo substr(htmlspecialchars($contest['description']), 0, 100) . '...'; ?></p>
                                    <p class="card-text"><i class="bi bi-calendar-check"></i> Ended: <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?></p>
                                    <?php if (isset($contest['results_published']) && $contest['results_published'] == 1): ?>
                                        <a href="view_results.php?id=<?php echo $contest['id']; ?>" class="btn btn-primary w-100"><i class="bi bi-bar-chart-line"></i> View Results</a>
                                    <?php else: ?>
                                         <button class="btn btn-outline-secondary w-100" disabled><i class="bi bi-hourglass"></i> Results Pending</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                     <div class="col">
                        <div class="alert alert-info"><i class="bi bi-info-circle"></i> No past contests to display.</div>
                    </div>
        <?php endif; ?>
            </div>
        </section>
    </div>

    <footer class="footer">
        <div class="container">
            &copy; <?php echo date("Y"); ?> Codinger. All Rights Reserved.
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function formatTime(totalSeconds) {
            if (totalSeconds < 0) totalSeconds = 0;
            const days = Math.floor(totalSeconds / (3600 * 24));
            const hours = Math.floor((totalSeconds % (3600 * 24)) / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = Math.floor(totalSeconds % 60);

            let timeString = '';
            if (days > 0) timeString += `${days}d `;
            if (hours > 0 || days > 0) timeString += `${hours}h `;
            if (minutes > 0 || hours > 0 || days > 0) timeString += `${minutes}m `;
            timeString += `${seconds}s`;
            return timeString.trim();
        }

        function startCountdown(elementId, totalSeconds, isUpcoming) {
            const countdownElement = document.getElementById(elementId);
            if (!countdownElement) return;

            let remainingSeconds = totalSeconds;

            const interval = setInterval(() => {
                if (remainingSeconds <= 0) {
                    clearInterval(interval);
                    countdownElement.textContent = isUpcoming ? "Starting soon!" : "Ended";
                    // Optionally, refresh the page or specific sections
                    if (!isUpcoming) {
                        // For active contests ending, you might want to disable "Enter Contest" button
                        // or redirect, or change status dynamically.
                        // For now, just updates text.
                        const enterButton = countdownElement.closest('.contest-card').querySelector('a[href*="contest.php"]');
                        if(enterButton) {
                            enterButton.classList.add('disabled');
                            enterButton.innerHTML = '<i class="bi bi-stop-circle"></i> Contest Ended';
                        }
                    } else {
                         // For upcoming contests starting, you might want to change button
                        const upcomingButton = countdownElement.closest('.contest-card').querySelector('button[disabled]');
                        if(upcomingButton) {
                            const contestId = elementId.replace('timer-upcoming-', '');
                            upcomingButton.outerHTML = `<a href="#" onclick="showContestInfoModal(${contestId}); return false;" class="btn btn-success w-100"><i class="bi bi-arrow-right-square"></i> Join Now</a>`;
                            location.reload(); // Refresh the page to update contest sections
                        }
                    }
                    return;
                }
                countdownElement.textContent = formatTime(remainingSeconds);
                remainingSeconds--;
            }, 1000);
        }

        // Function to open profile dialog
        function openProfileDialog() {
            // Show the profile modal directly
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Active contest countdowns
            document.querySelectorAll('.countdown-timer[data-seconds-remaining]').forEach(timerEl => {
                const contestId = timerEl.querySelector('span').id.replace('timer-', '');
                const secondsRemaining = parseInt(timerEl.dataset.secondsRemaining, 10);
                startCountdown('timer-' + contestId, secondsRemaining, false);
            });

            // Upcoming contest countdowns
            document.querySelectorAll('.countdown-timer[data-seconds-to-start]').forEach(timerEl => {
                const contestId = timerEl.querySelector('span').id.replace('timer-upcoming-', '');
                const secondsToStart = parseInt(timerEl.dataset.secondsToStart, 10);
                startCountdown('timer-upcoming-' + contestId, secondsToStart, true);
            });
            
            // Setup Clear Termination Data functionality
            const clearButton = document.getElementById('confirmClearTerminationData');
            if (clearButton) {
                clearButton.addEventListener('click', function() {
                    // Get all localStorage keys
                    const keys = Object.keys(localStorage);
                    
                    // Filter for contest-related keys
                    const contestKeys = keys.filter(key => 
                        key.startsWith('contest_') || 
                        key.startsWith('pageSwitchCount_') || 
                        key.includes('_terminated') || 
                        key.includes('_termination_')
                    );
                    
                    // Remove all contest termination-related items
                    contestKeys.forEach(key => localStorage.removeItem(key));
                    
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('clearTerminationDataModal'));
                    modal.hide();
                    
                    // Show success alert
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-success alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <strong>Success!</strong> Contest termination data has been cleared. 
                        You can now try accessing your contests again.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    `;
                    
                    // Insert at top of container
                    const container = document.querySelector('.container');
                    container.insertBefore(alertDiv, container.firstChild);
                    
                    // Auto-dismiss after 5 seconds
                    setTimeout(() => {
                        if (alertDiv.parentNode) {
                            alertDiv.classList.remove('show');
                            setTimeout(() => {
                                if (alertDiv.parentNode) {
                                    alertDiv.parentNode.removeChild(alertDiv);
                                }
                            }, 300);
                        }
                    }, 5000);
                });
            }
        });

        let currentContestId = null;

        function showContestInfoModal(contestId) {
            currentContestId = contestId;
            const contestInfoModal = new bootstrap.Modal(document.getElementById('contestInfoModal'));
            contestInfoModal.show();
            
            // Set up the proceed button event handler
            document.getElementById('proceedToContestBtn').onclick = function() {
                window.location.href = 'contest.php?id=' + currentContestId;
            };
        }
    </script>
    
    <!-- Contest Information Modal -->
    <div class="modal fade" id="contestInfoModal" tabindex="-1" aria-labelledby="contestInfoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="contestInfoModalLabel"><i class="bi bi-info-circle-fill me-2"></i>Important Contest Information</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="p-2">
                        <div class="alert alert-primary">
                            <h4 class="alert-heading"><i class="bi bi-star-fill text-warning"></i> Welcome to the Contest!</h4>
                            <p>Please read the following information carefully before proceeding.</p>
                        </div>
                        
                        <div class="card mb-4">
                            <div class="card-header bg-info text-white">
                                <h5><i class="bi bi-trophy"></i> Scoring System</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success"></i> Each problem has a specific point value based on its difficulty.</li>
                                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success"></i> Points are awarded only for solutions that pass all test cases.</li>
                                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success"></i> Partial solutions (passing some test cases) may receive partial credits.</li>
                                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success"></i> The final ranking is determined by total points earned.</li>
                                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success"></i> In case of tied points, the contestant who completed challenges in less time will receive a higher rank.</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="card mb-4">
                            <div class="card-header bg-danger text-white">
                                <h5><i class="bi bi-exclamation-triangle"></i> Important Rules & Violations</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-danger">
                                    <p><strong>The following actions are strictly prohibited and may result in disqualification:</strong></p>
                                </div>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex">
                                        <div class="me-3 text-danger"><i class="bi bi-x-circle-fill"></i></div>
                                        <div><strong>DO NOT</strong> switch tabs or navigate away from the contest window</div>
                                    </li>
                                    <li class="list-group-item d-flex">
                                        <div class="me-3 text-danger"><i class="bi bi-x-circle-fill"></i></div>
                                        <div><strong>DO NOT</strong> copy code from external sources or use AI tools</div>
                                    </li>
                                    <li class="list-group-item d-flex">
                                        <div class="me-3 text-danger"><i class="bi bi-x-circle-fill"></i></div>
                                        <div><strong>DO NOT</strong> communicate with others during the contest</div>
                                    </li>
                                    <li class="list-group-item d-flex">
                                        <div class="me-3 text-danger"><i class="bi bi-x-circle-fill"></i></div>
                                        <div><strong>DO NOT</strong> open browser developer tools</div>
                                    </li>
                                    <li class="list-group-item d-flex">
                                        <div class="me-3 text-danger"><i class="bi bi-x-circle-fill"></i></div>
                                        <div><strong>DO NOT</strong> attempt to bypass the system's security measures</div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5><i class="bi bi-lightbulb"></i> Motivation</h5>
                            </div>
                            <div class="card-body">
                                <div class="p-4 text-center bg-light rounded">
                                    <h4 class="mb-3">You've Got This!</h4>
                                    <p class="lead">"Believe in yourself! With dedication and focus, every challenge becomes an opportunity to shine."</p>
                                    <p>Remember that each problem you solve is a step toward mastery. Approach each challenge methodically, remain calm, and trust in your abilities.</p>
                                    <div class="mt-3">
                                        <i class="bi bi-stars text-warning" style="font-size: 2rem;"></i>
                                    </div>
                                    <p class="mt-3"><strong>Good luck and enjoy the contest!</strong></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="proceedToContestBtn" class="btn btn-primary">I Understand, Proceed to Contest</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Clear Termination Data Modal -->
    <div class="modal fade" id="clearTerminationDataModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Clear Contest Termination Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>This will clear any saved contest termination data from your browser. Use this only if you cannot access a contest even though it's currently active and you should have access to it.</p>
                    <p class="text-danger"><strong>Note:</strong> This will only clear client-side data. If you were permanently terminated from a contest by the system, you'll still need to contact an administrator.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmClearTerminationData">Clear Termination Data</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="profileModalLabel"><i class="bi bi-person-badge me-2"></i>Student Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div class="d-inline-block bg-primary text-white rounded-circle p-3 mb-3" style="width: 100px; height: 100px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-person-fill" style="font-size: 3rem;"></i>
                        </div>
                        <h3><?php echo htmlspecialchars($user_profile['full_name']); ?></h3>
                        <p class="text-muted"><?php echo htmlspecialchars($user_profile['enrollment_number']); ?></p>
                    </div>
                    
                    <div class="mb-3 p-3 border rounded">
                        <div class="row mb-2">
                            <div class="col-md-6">
                                <strong>Enrollment:</strong> 
                                <div><?php echo htmlspecialchars($user_profile['enrollment_number']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <strong>Full Name:</strong>
                                <div><?php echo htmlspecialchars($user_profile['full_name']); ?></div>
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-md-6">
                                <strong>Email:</strong> 
                                <div><?php echo htmlspecialchars($user_profile['email']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <strong>Mobile:</strong>
                                <div><?php echo htmlspecialchars($user_profile['mobile_number']); ?></div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>College:</strong> 
                                <div><?php echo htmlspecialchars($user_profile['college_name']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <strong>Joined On:</strong>
                                <div><?php echo date('d M Y', strtotime($user_profile['created_at'])); ?></div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <!-- Statistics -->
                        <div class="row mt-4 text-center">
                            <div class="col-md-4">
                                <div class="border-end">
                                    <h4><?php echo $total_contests; ?></h4>
                                    <div class="small text-muted">Total Contests</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border-end">
                                    <h4><?php echo $successful_contests; ?></h4>
                                    <div class="small text-muted">Successful</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div>
                                    <h4><?php echo $success_rate; ?>%</h4>
                                    <div class="small text-muted">Success Rate</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-center">
                        <p class="text-muted small">View your personal information and statistics</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 