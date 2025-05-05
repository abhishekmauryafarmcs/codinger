<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

// Get user's statistics
$user_id = $_SESSION['user_id'];

// Get user's enrollment number
$enrollment_number = $_SESSION['enrollment_number'] ?? '';

// Get total submissions
$stmt = $conn->prepare("SELECT COUNT(*) as total_submissions FROM submissions WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$submissions_result = $stmt->get_result()->fetch_assoc();
$total_submissions = $submissions_result['total_submissions'];

// Get successful submissions
$stmt = $conn->prepare("SELECT COUNT(*) as successful_submissions FROM submissions WHERE user_id = ? AND status = 'accepted'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$successful_result = $stmt->get_result()->fetch_assoc();
$successful_submissions = $successful_result['successful_submissions'];

// Set timezone and get current time
date_default_timezone_set('UTC');
$current_time = date('Y-m-d H:i:s');

// Debug current time
error_log("Current Time: " . $current_time);

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

// Add this after session_start() and before the first HTML output
$error_messages = [
    'invalid_contest' => 'The contest you tried to enter does not exist.',
    'contest_not_started' => 'This contest has not started yet.',
    'contest_ended' => 'This contest has already ended.',
    'invalid_status' => 'Unable to enter contest due to invalid status.',
    'contest_terminated' => 'Your contest session has been terminated due to multiple violations.',
    'invalid_enrollment' => 'The enrollment number you provided is not authorized for this contest.',
    'enrollment_required' => 'You must verify your enrollment number to participate in this private contest.',
    'missing_parameters' => 'Missing required information to process your request.',
    'user_not_found' => 'User information not found. Please contact an administrator.'
];

$error = isset($_GET['error']) ? $_GET['error'] : (isset($error) ? $error : '');
$error_message = isset($error_messages[$error]) ? $error_messages[$error] : '';

// Update the active contests query to only show public contests and private contests that the user is authorized for
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
$stmt = $conn->prepare($active_query);
$stmt->bind_param("s", $enrollment_number);
$stmt->execute();
$active_contests = $stmt->get_result();

// Debug active contests
while ($contest = $active_contests->fetch_assoc()) {
    error_log("Active Contest Found - ID: " . $contest['id'] . 
              ", Title: " . $contest['title'] . 
              ", Type: " . $contest['type'] .
              ", Seconds Remaining: " . $contest['seconds_remaining']);
}
$active_contests->data_seek(0); // Reset the result pointer

// Get upcoming contests - only show public contests and private contests the user is authorized for
$upcoming_query = "
    SELECT c.* FROM contests c 
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
$stmt = $conn->prepare($upcoming_query);
$stmt->bind_param("s", $enrollment_number);
$stmt->execute();
$upcoming_contests = $stmt->get_result();

// Get completed contests - only show public contests and private contests the user was authorized for
$completed_query = "
    SELECT c.* FROM contests c 
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
$stmt = $conn->prepare($completed_query);
$stmt->bind_param("s", $enrollment_number);
$stmt->execute();
$completed_contests = $stmt->get_result();

// Debug information
echo "<!-- Debug Information
Current Time: " . $current_time . "
Active Contests: " . $active_contests->num_rows . "
Upcoming Contests: " . $upcoming_contests->num_rows . "
Completed Contests: " . $completed_contests->num_rows . "
-->";

// Get a sample of each type for verification
$debug_contests = $conn->query("
    SELECT 
        id,
        title,
        start_time,
        end_time,
        CASE
            WHEN start_time <= NOW() AND end_time > NOW() THEN 'ACTIVE'
            WHEN start_time > NOW() THEN 'UPCOMING'
            WHEN end_time <= NOW() THEN 'COMPLETED'
        END as status
    FROM contests
    ORDER BY start_time DESC
");

echo "<!-- Contest Status Debug\n";
while ($contest = $debug_contests->fetch_assoc()) {
    echo "Contest: " . $contest['title'] . "\n";
    echo "Start: " . $contest['start_time'] . "\n";
    echo "End: " . $contest['end_time'] . "\n";
    echo "Status: " . $contest['status'] . "\n\n";
}
echo "-->";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        /* Sticky navbar styles */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            background-color: #212529;
        }
        body {
            padding-top: 56px; /* Height of navbar */
        }
        /* Dashboard specific styles */
        .dashboard-stats {
            margin-top: 20px;
        }
    </style>
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
                        <a class="nav-link active" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contests.php">All Contests</a>
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
        <h1 class="mb-4">Dashboard</h1>

        <!-- Statistics -->
        <div class="row dashboard-stats">
            <div class="col-md-4">
                <div class="stats-card">
                    <h3>Total Submissions</h3>
                    <p class="display-4"><?php echo $total_submissions; ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h3>Successful Submissions</h3>
                    <p class="display-4"><?php echo $successful_submissions; ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h3>Success Rate</h3>
                    <p class="display-4">
                        <?php 
                        echo $total_submissions > 0 
                            ? round(($successful_submissions / $total_submissions) * 100, 1) . '%'
                            : '0%';
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Active Contests -->
        <h2 class="mt-5 mb-4">Active Contests</h2>
        <?php if ($active_contests->num_rows > 0): ?>
            <div class="row">
                <?php while ($contest = $active_contests->fetch_assoc()): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card contest-card">
                            <div class="card-body">
                                <span class="badge bg-success status-badge">Active</span>
                                <?php if ($contest['type'] == 'private'): ?>
                                    <span class="badge bg-warning ms-1">Private</span>
                                <?php endif; ?>
                                <h5 class="card-title"><?php echo htmlspecialchars($contest['title']); ?></h5>
                                <p class="card-text"><?php echo htmlspecialchars($contest['description']); ?></p>
                                <p class="text-muted">
                                    Started: <?php echo date('M d, Y H:i', strtotime($contest['start_time'])); ?><br>
                                    Ends: <?php echo date('M d, Y H:i', strtotime($contest['end_time'])); ?>
                                </p>
                                <a href="contest.php?id=<?php echo $contest['id']; ?>" 
                                   class="btn btn-primary <?php echo $contest['seconds_remaining'] <= 0 ? 'disabled' : ''; ?>">
                                    <?php echo $contest['seconds_remaining'] > 0 ? 'Enter Contest' : 'Contest Ending'; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>No active contests at the moment.</p>
        <?php endif; ?>

        <!-- Upcoming Contests -->
        <h2 class="mt-5 mb-4">Upcoming Contests</h2>
        <?php if ($upcoming_contests->num_rows > 0): ?>
            <div class="row">
                <?php while ($contest = $upcoming_contests->fetch_assoc()): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card contest-card">
                            <div class="card-body">
                                <span class="badge bg-warning status-badge">Upcoming</span>
                                <h5 class="card-title"><?php echo htmlspecialchars($contest['title']); ?></h5>
                                <p class="card-text"><?php echo htmlspecialchars($contest['description']); ?></p>
                                <p class="text-muted">
                                    Starts: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?><br>
                                    Duration: <?php 
                                        $duration = strtotime($contest['end_time']) - strtotime($contest['start_time']);
                                        if ($duration < 3600) {
                                            echo floor($duration / 60) . ' minutes';
                                        } else {
                                            echo floor($duration / 3600) . ' hours';
                                        }
                                    ?><br>
                                    <span class="starts-in" data-start-time="<?php echo $contest['start_time']; ?>">
                                        Starts in: <span class="countdown"></span>
                                    </span>
                                </p>
                                <button class="btn btn-secondary" disabled>Coming Soon</button>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>No upcoming contests scheduled.</p>
        <?php endif; ?>

        <!-- Completed Contests -->
        <h2 class="mt-5 mb-4">Completed Contests</h2>
        <?php if ($completed_contests->num_rows > 0): ?>
            <div class="row">
                <?php while ($contest = $completed_contests->fetch_assoc()): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card contest-card">
                            <div class="card-body">
                                <span class="badge bg-secondary status-badge">Completed</span>
                                <h5 class="card-title"><?php echo htmlspecialchars($contest['title']); ?></h5>
                                <p class="card-text"><?php echo htmlspecialchars($contest['description']); ?></p>
                                <p class="text-muted">
                                    Started: <?php echo date('M d, Y H:i', strtotime($contest['start_time'])); ?><br>
                                    Ended: <?php echo date('M d, Y H:i', strtotime($contest['end_time'])); ?>
                                </p>
                                <a href="view_results.php?id=<?php echo $contest['id']; ?>" class="btn btn-info">View Results</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>No completed contests yet.</p>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to update countdown timers for upcoming contests
        function updateCountdowns() {
            const startsInElements = document.querySelectorAll('.starts-in');
            
            startsInElements.forEach(element => {
                const startTime = new Date(element.dataset.startTime).getTime();
                const countdownElement = element.querySelector('.countdown');
                
                function updateTimer() {
                    const now = new Date().getTime();
                    const distance = startTime - now;
                    
                    const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                    
                    let countdownText = '';
                    if (days > 0) {
                        countdownText += `${days}d `;
                    }
                    if (hours > 0 || days > 0) {
                        countdownText += `${hours}h `;
                    }
                    countdownText += `${minutes}m ${seconds}s`;
                    
                    if (distance < 0) {
                        countdownElement.textContent = 'Starting now...';
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } else {
                        countdownElement.textContent = countdownText;
                    }
                }
                
                updateTimer();
                setInterval(updateTimer, 1000);
            });
        }

        // Initialize countdown timers when page loads
        document.addEventListener('DOMContentLoaded', updateCountdowns);
    </script>
</body>
</html> 