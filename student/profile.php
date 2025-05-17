<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Get user's data
$user_id = $_SESSION['student']['user_id'];

// Get user details from database
$stmt = $conn->prepare("SELECT enrollment_number, full_name, college_name, mobile_number, email, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: dashboard.php?error=user_not_found");
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

// Get total contests participated
$stmt = $conn->prepare("SELECT COUNT(DISTINCT contest_id) as total_contests FROM submissions WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$contests_result = $stmt->get_result()->fetch_assoc();
$total_contests = $contests_result['total_contests'];
$stmt->close();

// Get successful contests (where all problems were solved correctly)
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT s1.contest_id) as successful_contests
    FROM submissions s1
    WHERE s1.user_id = ?
    AND NOT EXISTS (
        SELECT 1
        FROM problems p
        WHERE p.contest_id = s1.contest_id
        AND NOT EXISTS (
            SELECT 1
            FROM submissions s2
            WHERE s2.user_id = s1.user_id
            AND s2.contest_id = s1.contest_id
            AND s2.problem_id = p.id
            AND s2.status = 'accepted'
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        body {
            padding-top: 70px;
            background-color: #f8f9fa;
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .profile-card {
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            background-color: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 2rem;
            font-size: 2.5rem;
            color: #6c757d;
        }
        .profile-details-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .profile-details-card h3 {
            color: #0d6efd;
            margin-bottom: 1rem;
            font-size: 1.25rem;
            font-weight: 600;
        }
        .profile-detail {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }
        .profile-detail:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .profile-detail-label {
            font-weight: 600;
            color: #495057;
        }
        .profile-detail-value {
            color: #212529;
        }
        .stats-card {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            display: flex;
            align-items: center;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card .icon {
            font-size: 2.5rem;
            margin-right: 1rem;
            color: #0d6efd;
        }
        .stats-card .icon.text-success {
            color: #198754;
        }
        .stats-card .icon.text-warning {
            color: #ffc107;
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
        .modal-profile .modal-header {
            background: linear-gradient(to right, #007bff, #0056b3);
            color: white;
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
                        <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contests.php"><i class="bi bi-trophy"></i> All Contests</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($user['full_name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item active" href="profile.php"><i class="bi bi-person-fill"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="my_submissions.php"><i class="bi bi-card-list"></i> My Submissions</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <!-- Left Column - Profile Info -->
            <div class="col-md-8">
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <i class="bi bi-person"></i>
                        </div>
                        <div>
                            <h1><?php echo htmlspecialchars($user['full_name']); ?></h1>
                            <p class="text-muted mb-0"><?php echo htmlspecialchars($user['enrollment_number']); ?></p>
                            <p class="text-muted">Member since <?php echo date('F Y', strtotime($user['created_at'])); ?></p>
                        </div>
                    </div>

                    <div class="profile-details-card">
                        <h3><i class="bi bi-person-vcard"></i> Personal Information</h3>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="profile-detail">
                                    <div class="profile-detail-label">Enrollment Number</div>
                                    <div class="profile-detail-value"><?php echo htmlspecialchars($user['enrollment_number']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-detail">
                                    <div class="profile-detail-label">Full Name</div>
                                    <div class="profile-detail-value"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="profile-detail">
                                    <div class="profile-detail-label">Email Address</div>
                                    <div class="profile-detail-value"><?php echo htmlspecialchars($user['email']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-detail">
                                    <div class="profile-detail-label">Mobile Number</div>
                                    <div class="profile-detail-value"><?php echo htmlspecialchars($user['mobile_number']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="profile-detail">
                                    <div class="profile-detail-label">College</div>
                                    <div class="profile-detail-value"><?php echo htmlspecialchars($user['college_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-detail">
                                    <div class="profile-detail-label">Joined On</div>
                                    <div class="profile-detail-value"><?php echo date('d M Y', strtotime($user['created_at'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column - Stats -->
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="icon"><i class="bi bi-journal-code"></i></div>
                    <div class="stat-info">
                        <h3>Total Contests</h3>
                        <p><?php echo $total_contests; ?></p>
                    </div>
                </div>

                <div class="stats-card">
                    <div class="icon text-success"><i class="bi bi-check-circle-fill"></i></div>
                    <div class="stat-info">
                        <h3>Successful Contests</h3>
                        <p><?php echo $successful_contests; ?></p>
                    </div>
                </div>

                <div class="stats-card">
                    <div class="icon text-warning"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="stat-info">
                        <h3>Success Rate</h3>
                        <p><?php echo $success_rate; ?>%</p>
                    </div>
                </div>

                <button type="button" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#profileModal">
                    <i class="bi bi-eye"></i> View Profile in Dialog
                </button>
            </div>
        </div>
    </div>

    <!-- Profile Modal -->
    <div class="modal fade modal-profile" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel"><i class="bi bi-person-circle me-2"></i>User Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div class="profile-avatar mx-auto">
                            <i class="bi bi-person"></i>
                        </div>
                        <h3 class="mt-3"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                        <p class="text-muted"><?php echo htmlspecialchars($user['enrollment_number']); ?></p>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="profile-detail">
                                <div class="profile-detail-label">Email Address</div>
                                <div class="profile-detail-value"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="profile-detail">
                                <div class="profile-detail-label">Mobile Number</div>
                                <div class="profile-detail-value"><?php echo htmlspecialchars($user['mobile_number']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="profile-detail">
                                <div class="profile-detail-label">College</div>
                                <div class="profile-detail-value"><?php echo htmlspecialchars($user['college_name']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="profile-detail">
                                <div class="profile-detail-label">Joined On</div>
                                <div class="profile-detail-value"><?php echo date('d M Y', strtotime($user['created_at'])); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="row text-center mt-4">
                        <div class="col-md-4">
                            <div class="profile-stat">
                                <h5><?php echo $total_contests; ?></h5>
                                <div class="text-muted small">Total Contests</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="profile-stat">
                                <h5><?php echo $successful_contests; ?></h5>
                                <div class="text-muted small">Successful</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="profile-stat">
                                <h5><?php echo $success_rate; ?>%</h5>
                                <div class="text-muted small">Success Rate</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-dark text-white text-center py-3 mt-5">
        <div class="container">
            &copy; <?php echo date("Y"); ?> Codinger. All Rights Reserved.
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-show the profile dialog when URL has "show_dialog" parameter
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('show_dialog')) {
                const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
                profileModal.show();
            }
        });
    </script>
</body>
</html> 