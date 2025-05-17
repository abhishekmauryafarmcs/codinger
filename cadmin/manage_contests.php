<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    header("Location: login.php?error=invalid_credentials");
    exit();
}

// Get all contests with creator info
$stmt = $conn->prepare("
    SELECT c.*, 
           COALESCE(a.full_name, 'Unknown') as creator_name,
           COUNT(DISTINCT ce.enrollment_number) as registered_students,
           (SELECT COUNT(*) FROM contest_problems WHERE contest_id = c.id) as problem_count
    FROM contests c 
    LEFT JOIN admins a ON c.created_by = a.id 
    LEFT JOIN contest_enrollments ce ON c.id = ce.contest_id
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
$contests = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Contests - Codinger Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        .contest-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 20px;
        }
        .contest-actions {
            display: flex;
            gap: 10px;
        }
        .contest-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .contest-title {
            font-weight: bold;
            color: #333;
            margin: 0;
        }
        .contest-details {
            color: #666;
        }
        .separator {
            color: #ccc;
            margin: 0 8px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Manage Contests</h1>
            <a href="create_contest.php" class="btn btn-primary">Create New Contest</a>
        </div>

        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Contest was successfully deleted.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error']) && $_GET['error'] == 'delete_failed'): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                Failed to delete contest. The contest may have related data that prevents deletion.
                <a href="force_delete_contest.php?id=<?php echo isset($_GET['id']) ? (int)$_GET['id'] : ''; ?>" class="alert-link">Try force delete</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($contests)): ?>
            <?php foreach ($contests as $contest): ?>
                <div class="contest-card">
                    <div class="row align-items-center">
                        <div class="col-md-9">
                            <div class="contest-info">
                                <h3 class="contest-title"><?php echo htmlspecialchars($contest['title']); ?></h3>
                                <span class="separator">|</span>
                                <span class="contest-details"><?php echo htmlspecialchars($contest['creator_name']); ?></span>
                                <span class="separator">|</span>
                                <span class="contest-details">Start: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?></span>
                                <span class="separator">|</span>
                                <span class="contest-details">End: <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="contest-actions justify-content-end">
                                <a href="edit_contest.php?id=<?php echo $contest['id']; ?>" class="btn btn-warning">Edit</a>
                                <a href="view_submissions.php?id=<?php echo $contest['id']; ?>" class="btn btn-info">View Submissions</a>
                                <a href="contest_results.php?id=<?php echo $contest['id']; ?>" class="btn btn-success">View Results</a>
                                <button class="btn btn-danger" onclick="deleteContest(<?php echo $contest['id']; ?>)">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info">No contests found. Create your first contest!</div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function deleteContest(contestId) {
        window.location.href = 'delete_contest.php?id=' + contestId + '&redirect=1';
    }
    </script>
</body>
</html> 