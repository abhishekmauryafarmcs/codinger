<?php
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    header("Location: login.php?error=session_expired");
    exit();
}

// Get all students
$stmt = $conn->prepare("
    SELECT * FROM users 
    ORDER BY created_at DESC
");
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get contest-wise participation data
$stmt = $conn->prepare("
    SELECT 
        c.id as contest_id,
        c.title as contest_title,
        c.start_time,
        c.end_time,
        COUNT(DISTINCT s.user_id) as participant_count,
        GROUP_CONCAT(DISTINCT CONCAT(u.full_name, '|', u.enrollment_number, '|', u.college_name) SEPARATOR '||') as participants
    FROM contests c
    LEFT JOIN problems p ON c.id = p.contest_id
    LEFT JOIN submissions s ON p.id = s.problem_id
    LEFT JOIN users u ON s.user_id = u.id
    GROUP BY c.id
    ORDER BY c.start_time DESC
");
$stmt->execute();
$contest_participation = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Codinger Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        .stats-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .student-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 15px;
            padding: 15px;
            transition: transform 0.2s;
        }
        .student-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .contest-section {
            margin-top: 30px;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .participant-list {
            max-height: 200px;
            overflow-y: auto;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-top: 10px;
        }
        .participant-item {
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        .participant-item:last-child {
            border-bottom: none;
        }
        .search-box {
            margin-bottom: 20px;
        }
        .nav-tabs {
            margin-bottom: 20px;
        }
        .badge-count {
            background: #6c757d;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8em;
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
        <h1 class="mb-4">Manage Students</h1>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card">
                    <h3>Total Students</h3>
                    <p class="display-4"><?php echo count($students); ?></p>
                </div>
            </div>
        </div>

        <!-- Search Box -->
        <div class="search-box">
            <input type="text" id="searchInput" class="form-control" placeholder="Search students by name, enrollment number, or college...">
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs" id="studentTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="all-students-tab" data-bs-toggle="tab" data-bs-target="#all-students" type="button" role="tab">
                    All Students
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="contest-wise-tab" data-bs-toggle="tab" data-bs-target="#contest-wise" type="button" role="tab">
                    Contest-wise Participation
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="studentTabsContent">
            <!-- All Students Tab -->
            <div class="tab-pane fade show active" id="all-students" role="tabpanel">
                <?php if (!empty($students)): ?>
                    <div class="row" id="studentsList">
                        <?php foreach ($students as $student): ?>
                            <div class="col-md-6">
                                <div class="student-card">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5><?php echo htmlspecialchars($student['full_name']); ?></h5>
                                            <p class="mb-1">
                                                <strong>Enrollment:</strong> <?php echo htmlspecialchars($student['enrollment_number']); ?><br>
                                                <strong>College:</strong> <?php echo htmlspecialchars($student['college_name']); ?><br>
                                                <strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?><br>
                                                <strong>Mobile:</strong> <?php echo htmlspecialchars($student['mobile_number']); ?>
                                            </p>
                                            <small class="text-muted">
                                                Joined: <?php echo date('M d, Y', strtotime($student['created_at'])); ?>
                                            </small>
                                        </div>
                                        <div>
                                            <button class="btn btn-danger btn-sm remove-student-btn" 
                                                    data-student-id="<?php echo $student['id']; ?>"
                                                    data-student-name="<?php echo htmlspecialchars($student['full_name']); ?>">
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">No students registered yet.</div>
                <?php endif; ?>
            </div>

            <!-- Contest-wise Participation Tab -->
            <div class="tab-pane fade" id="contest-wise" role="tabpanel">
                <?php if (!empty($contest_participation)): ?>
                    <?php foreach ($contest_participation as $contest): ?>
                        <div class="contest-section mb-4">
                            <h4><?php echo htmlspecialchars($contest['contest_title']); ?></h4>
                            <p class="text-muted">
                                <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?> - 
                                <?php echo date('M d, Y h:i A', strtotime($contest['end_time'])); ?>
                            </p>
                            <div class="d-flex align-items-center mb-3">
                                <span class="badge-count me-2">
                                    <?php echo $contest['participant_count']; ?> Participants
                                </span>
                            </div>
                            <?php if ($contest['participants']): ?>
                                <div class="participant-list">
                                    <?php 
                                    $participants = explode('||', $contest['participants']);
                                    foreach ($participants as $participant): 
                                        list($name, $enrollment, $college) = explode('|', $participant);
                                    ?>
                                        <div class="participant-item">
                                            <strong><?php echo htmlspecialchars($name); ?></strong><br>
                                            <small>
                                                Enrollment: <?php echo htmlspecialchars($enrollment); ?><br>
                                                College: <?php echo htmlspecialchars($college); ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">No participants yet.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info">No contest participation data available.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Student Confirmation Modal -->
    <div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Remove Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to remove <strong id="studentName"></strong>?</p>
                    <p class="text-danger"><strong>Warning:</strong> This action cannot be undone. All data related to this student, including submissions and contest participation, will be permanently deleted.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete Student</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success/Error Alert Modal -->
    <div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" id="alertModalHeader">
                    <h5 class="modal-title" id="alertModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="alertModalMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const studentCards = document.querySelectorAll('.student-card');
            
            studentCards.forEach(card => {
                const text = card.textContent.toLowerCase();
                const parentCol = card.closest('.col-md-6');
                if (text.includes(searchValue)) {
                    parentCol.style.display = '';
                } else {
                    parentCol.style.display = 'none';
                }
            });
        });

        // Student Deletion Functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Variables for the modals and selected student
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteStudentModal'));
            const alertModal = new bootstrap.Modal(document.getElementById('alertModal'));
            let selectedStudentId = null;

            // Add event listeners for all remove buttons
            document.querySelectorAll('.remove-student-btn').forEach(button => {
                button.addEventListener('click', function() {
                    // Get student details from data attributes
                    selectedStudentId = this.getAttribute('data-student-id');
                    const studentName = this.getAttribute('data-student-name');
                    
                    // Update the modal with student name
                    document.getElementById('studentName').textContent = studentName;
                    
                    // Show the confirmation modal
                    deleteModal.show();
                });
            });

            // Handle confirm delete button click
            document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
                if (!selectedStudentId) return;
                
                // Create form data for the API request
                const formData = new FormData();
                formData.append('student_id', selectedStudentId);
                
                // Send delete request to the API
                fetch('api/delete_student.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Hide the delete confirmation modal
                    deleteModal.hide();
                    
                    // Set up the alert modal based on the response
                    const alertModalEl = document.getElementById('alertModal');
                    const headerEl = document.getElementById('alertModalHeader');
                    const titleEl = document.getElementById('alertModalTitle');
                    const messageEl = document.getElementById('alertModalMessage');
                    
                    if (data.success) {
                        // Success message
                        headerEl.className = 'modal-header bg-success text-white';
                        titleEl.textContent = 'Success';
                        messageEl.textContent = data.message;
                        
                        // Remove the student card from the page
                        const studentCard = document.querySelector(`button[data-student-id="${selectedStudentId}"]`).closest('.col-md-6');
                        studentCard.remove();
                        
                        // Update the total students count
                        const totalStudentsElement = document.querySelector('.stats-card p.display-4');
                        if (totalStudentsElement) {
                            const currentCount = parseInt(totalStudentsElement.textContent);
                            totalStudentsElement.textContent = currentCount - 1;
                        }
                    } else {
                        // Error message
                        headerEl.className = 'modal-header bg-danger text-white';
                        titleEl.textContent = 'Error';
                        messageEl.textContent = data.message;
                    }
                    
                    // Show the alert modal
                    alertModal.show();
                    
                    // Reset selected student ID
                    selectedStudentId = null;
                })
                .catch(error => {
                    console.error('Error:', error);
                    deleteModal.hide();
                    
                    // Show error in alert modal
                    const alertModalEl = document.getElementById('alertModal');
                    const headerEl = document.getElementById('alertModalHeader');
                    const titleEl = document.getElementById('alertModalTitle');
                    const messageEl = document.getElementById('alertModalMessage');
                    
                    headerEl.className = 'modal-header bg-danger text-white';
                    titleEl.textContent = 'Error';
                    messageEl.textContent = 'An unexpected error occurred. Please try again.';
                    
                    alertModal.show();
                    selectedStudentId = null;
                });
            });
        });
    </script>
</body>
</html> 