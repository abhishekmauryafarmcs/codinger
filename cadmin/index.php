<?php
require_once '../config/session.php';
require_once '../config/db.php';  // Add database configuration

// Debug session
error_log("Session data: " . json_encode($_SESSION));

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    error_log("Admin session invalid, redirecting to login");
    
    // Set a cookie to prevent redirect loops
    setcookie('redirect_attempted', 'true', time() + 30, '/codinger/cadmin/');
    
    header("Location: login.php?error=session_expired");
    exit();
}

// Debug successful admin access
error_log("Admin access successful - User ID: " . $_SESSION['admin']['user_id']);

// Add session debug information
error_log("Admin Session Debug - Admin ID: " . $_SESSION['admin']['user_id']);

// Get statistics
try {
    // Use the existing connection from db.php
    if (!isset($conn) || $conn->connect_error) {
        die("Database connection failed. Please check your configuration.");
    }

    $stmt = $conn->prepare("SELECT COUNT(*) as total_users FROM users");
    $stmt->execute();
    $stmt->bind_result($total_users);
    $stmt->fetch();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total_contests FROM contests");
    $stmt->execute();
    $stmt->bind_result($total_contests);
    $stmt->fetch();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total_submissions FROM submissions");
    $stmt->execute();
    $stmt->bind_result($total_submissions);
    $stmt->fetch();
    $stmt->close();

    // Get recent contests with creator info
    $stmt = $conn->prepare("
        SELECT c.id, c.title, c.start_time, c.end_time, a.full_name as creator_name 
        FROM contests c 
        LEFT JOIN admins a ON c.created_by = a.id 
        ORDER BY c.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_contests = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Database Error: " . $e->getMessage());
    die("An error occurred while fetching data. Please try again later.");
}

// Set cookie to indicate successful admin login
setcookie('adminLoggedIn', 'true', [
    'expires' => time() + 86400, // 24 hours
    'path' => '/codinger/cadmin/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - LNCT Group of Colleges</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
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
        .stats-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .activity-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .contest-title {
            font-weight: bold;
            color: #333;
        }
        .creator {
            color: #666;
        }
        .time-info {
            color: #666;
        }
        .mx-2 {
            margin: 0 8px;
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
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_contests.php' ? 'active' : ''; ?>" href="manage_contests.php">Manage Contests</a>
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
        <h1 class="mb-4">Admin Dashboard</h1>

        <!-- Statistics -->
        <div class="row">
            <div class="col-md-4">
                <div class="stats-card">
                    <h3>Total Students</h3>
                    <p class="display-4"><?php echo $total_users; ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h3>Total Contests</h3>
                    <p class="display-4"><?php echo $total_contests; ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h3>Total Submissions</h3>
                    <p class="display-4"><?php echo $total_submissions; ?></p>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <!-- Recent Contests -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title mb-0">Recent Contests</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_contests)): ?>
                            <?php foreach ($recent_contests as $contest): ?>
                                <div class="activity-item d-flex align-items-center">
                                    <div class="contest-info">
                                        <span class="contest-title"><?php echo htmlspecialchars($contest['title']); ?></span>
                                        <span class="mx-2">|</span>
                                        <span class="creator">Created by: <?php echo htmlspecialchars($contest['creator_name']); ?></span>
                                        <span class="mx-2">|</span>
                                        <span class="time-info">
                                            Start: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?>
                                            <span class="mx-2">|</span>
                                            End: <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No contests found.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="text-center py-4 bg-white mt-4 border-top">
        <img src="../images/lnct-logo-footer-300x106-1.png" alt="LNCT Footer Logo" style="height:40px;">
        <div class="mt-2 text-muted" style="font-size:0.95rem;">&copy; <?php echo date('Y'); ?> LNCT Group of Colleges. All rights reserved.</div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 