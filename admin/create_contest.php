<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$errors = [];
$success = false;

// Initialize variables to store form data
$form_data = [
    'title' => isset($_POST['title']) ? $_POST['title'] : 'Coding Challenge #1',
    'description' => isset($_POST['description']) ? $_POST['description'] : 'Welcome to our first coding challenge! Test your skills with algorithmic problems.',
    'start_time' => isset($_POST['start_time']) ? $_POST['start_time'] : '',
    'end_time' => isset($_POST['end_time']) ? $_POST['end_time'] : '',
    'type' => isset($_POST['contest_type']) ? $_POST['contest_type'] : 'public',
    'allowed_tab_switches' => isset($_POST['allowed_tab_switches']) ? $_POST['allowed_tab_switches'] : 0,
    'prevent_copy_paste' => isset($_POST['prevent_copy_paste']) ? $_POST['prevent_copy_paste'] : 0,
    'problems' => isset($_POST['problems']) ? $_POST['problems'] : []
];

// Get all existing problems from the database
$all_problems = [];
$stmt = $conn->prepare("SELECT id, title, description, input_format, output_format, constraints, sample_input, sample_output, points FROM problems WHERE contest_id IS NULL");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_problems[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contest'])) {
    // Store form data
    $form_data = [
        'title' => trim($_POST['title']),
        'description' => trim($_POST['description']),
        'start_time' => $_POST['start_time'],
        'end_time' => $_POST['end_time'],
        'type' => $_POST['contest_type'],
        'allowed_tab_switches' => isset($_POST['allowed_tab_switches']) ? intval($_POST['allowed_tab_switches']) : 0,
        'prevent_copy_paste' => isset($_POST['prevent_copy_paste']) ? 1 : 0,
        'selected_problems' => isset($_POST['selected_problems']) ? $_POST['selected_problems'] : []
    ];

    // Validation
    if (empty($form_data['title'])) $errors[] = "Title is required";
    if (empty($form_data['description'])) $errors[] = "Description is required";
    if (empty($form_data['start_time'])) $errors[] = "Start time is required";
    if (empty($form_data['end_time'])) $errors[] = "End time is required";
    if (empty($form_data['selected_problems'])) $errors[] = "At least one problem is required";

    // Validate enrollment file for private contests
    if ($form_data['type'] === 'private') {
        if (!isset($_FILES['enrollment_file']) || $_FILES['enrollment_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Enrollment file is required";
        } else {
            $file_extension = strtolower(pathinfo($_FILES['enrollment_file']['name'], PATHINFO_EXTENSION));
            if ($file_extension !== 'xlsx' && $file_extension !== 'csv' && $file_extension !== 'txt') {
                $errors[] = "Only CSV (.csv), Excel (.xlsx) or text (.txt) files are allowed";
            }
        }
    }

    // Validate dates
    $start = new DateTime($form_data['start_time']);
    $end = new DateTime($form_data['end_time']);
    $now = new DateTime();

    if ($start < $now) {
        $errors[] = "Start time must be in the future";
    }
    if ($end <= $start) {
        $errors[] = "End time must be after start time";
    }

    if (empty($errors)) {
        // Begin transaction
        $conn->begin_transaction();

        try {
            // Insert contest
            $stmt = $conn->prepare("INSERT INTO contests (
                title, description, start_time, end_time, type, created_by, 
                allowed_tab_switches, prevent_copy_paste
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("sssssiii", 
                $form_data['title'],
                $form_data['description'],
                $form_data['start_time'],
                $form_data['end_time'],
                $form_data['type'],
                $_SESSION['user_id'],
                $form_data['allowed_tab_switches'],
                $form_data['prevent_copy_paste']
            );
            $stmt->execute();
            $contest_id = $conn->insert_id;

            // Process enrollment file for private contests
            if ($form_data['type'] === 'private') {
                if (isset($_FILES['enrollment_file']) && $_FILES['enrollment_file']['error'] === UPLOAD_ERR_OK) {
                    $file_extension = strtolower(pathinfo($_FILES['enrollment_file']['name'], PATHINFO_EXTENSION));
                    
                    // Process CSV file directly without external library
                    if ($file_extension === 'csv') {
                        $enrollments = [];
                        if (($handle = fopen($_FILES['enrollment_file']['tmp_name'], "r")) !== FALSE) {
                            // Skip header row if exists
                            $header = fgetcsv($handle);
                            
                            // Read enrollments from CSV
                            while (($data = fgetcsv($handle)) !== FALSE) {
                                if (!empty($data[0])) {
                                    $enrollments[] = trim($data[0]);
                                }
                            }
                            fclose($handle);
                        }
                    } 
                    // Process Excel file as CSV (ask user to save as CSV instead)
                    else {
                        // Fallback for Excel files - simply read as text and split by lines
                        $content = file_get_contents($_FILES['enrollment_file']['tmp_name']);
                        $lines = explode("\n", $content);
                        $enrollments = [];
                        
                        // Skip the first line (header)
                        for ($i = 1; $i < count($lines); $i++) {
                            $line = trim($lines[$i]);
                            if (!empty($line)) {
                                // Try to extract the first column
                                $parts = preg_split('/[\t,;]/', $line);
                                if (!empty($parts[0])) {
                                    $enrollments[] = trim($parts[0]);
                                }
                            }
                        }
                    }
                    
                    // Prepare statement for inserting enrollments
                    if (!empty($enrollments)) {
                        $stmt = $conn->prepare("INSERT INTO contest_enrollments (contest_id, enrollment_number) VALUES (?, ?)");
                        
                        foreach ($enrollments as $enrollment) {
                            if (!empty($enrollment)) {
                                $stmt->bind_param("is", $contest_id, $enrollment);
                                $stmt->execute();
                            }
                        }
                    }
                }
            }

            // Associate selected problems with the contest
            if (!empty($form_data['selected_problems'])) {
                $stmt = $conn->prepare("UPDATE problems SET contest_id = ? WHERE id = ?");
                
                foreach ($form_data['selected_problems'] as $problem_id) {
                    $stmt->bind_param("ii", $contest_id, $problem_id);
                    $stmt->execute();
                }
            }

            // Commit transaction
            $conn->commit();
            header("Location: manage_contests.php?created=1");
            exit();

        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            error_log("Error creating contest: " . $e->getMessage());
            $errors[] = "Error creating contest: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Contest - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        .tab-pane {
            padding: 20px 0;
        }
        .problem-card {
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .problem-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .problem-card.selected {
            border: 2px solid #198754;
            background-color: rgba(25, 135, 84, 0.1);
        }
        .problem-points {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #198754;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        .nav-tabs .nav-link {
            font-weight: 500;
            color: #495057;
        }
        .nav-tabs .nav-link.active {
            color: #198754;
            border-color: #198754 #dee2e6 #fff;
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
                        <a class="nav-link" href="index.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="create_contest.php">Create Contest</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_contests.php">Manage Contests</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_problems.php">Manage Problems</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-dark text-white">
                        <h3 class="mb-0">Create New Contest</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="" enctype="multipart/form-data">
                            <!-- Tabs Navigation -->
                            <ul class="nav nav-tabs" id="contestTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab" aria-controls="details" aria-selected="true">1. Contest Details</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="challenges-tab" data-bs-toggle="tab" data-bs-target="#challenges" type="button" role="tab" aria-controls="challenges" aria-selected="false">2. Challenges</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab" aria-controls="settings" aria-selected="false">3. Advanced Settings</button>
                                </li>
                            </ul>

                            <!-- Tab Content -->
                            <div class="tab-content" id="contestTabsContent">
                                <!-- Tab 1: Details -->
                                <div class="tab-pane fade show active" id="details" role="tabpanel" aria-labelledby="details-tab">
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="title" class="form-label">Contest Title</label>
                                                <input type="text" class="form-control" id="title" name="title" required value="<?php echo htmlspecialchars($form_data['title']); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="contest_type" class="form-label">Contest Type</label>
                                                <select class="form-select" id="contest_type" name="contest_type">
                                                    <option value="public" <?php echo $form_data['type'] === 'public' ? 'selected' : ''; ?>>Public (Open to all)</option>
                                                    <option value="private" <?php echo $form_data['type'] === 'private' ? 'selected' : ''; ?>>Private (By enrollment only)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="description" class="form-label">Contest Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="4" required><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="start_time" class="form-label">Start Time</label>
                                                <input type="datetime-local" class="form-control" id="start_time" name="start_time" required value="<?php echo htmlspecialchars($form_data['start_time']); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="end_time" class="form-label">End Time</label>
                                                <input type="datetime-local" class="form-control" id="end_time" name="end_time" required value="<?php echo htmlspecialchars($form_data['end_time']); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div id="enrollment_file_section" class="mb-3 <?php echo $form_data['type'] === 'private' ? '' : 'd-none'; ?>">
                                        <label for="enrollment_file" class="form-label">Enrollment File (.csv, .xlsx, .txt)</label>
                                        <input type="file" class="form-control" id="enrollment_file" name="enrollment_file">
                                        <div class="form-text">Upload a file containing enrollment numbers (one per line).</div>
                                    </div>

                                    <div class="mb-3 text-end">
                                        <button type="button" class="btn btn-primary next-tab" data-next="challenges-tab">Next: Select Challenges</button>
                                    </div>
                                </div>

                                <!-- Tab 2: Challenges -->
                                <div class="tab-pane fade" id="challenges" role="tabpanel" aria-labelledby="challenges-tab">
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <h4>Select Problems</h4>
                                            <p>Choose problems from the existing problem bank to include in this contest.</p>
                                            
                                            <?php if (empty($all_problems)): ?>
                                                <div class="alert alert-warning">
                                                    <p>No problems found in the database. Please create problems first.</p>
                                                    <a href="manage_problems.php" class="btn btn-sm btn-warning">Create Problems</a>
                                                </div>
                                            <?php else: ?>
                                                <div class="row problem-container">
                                                    <?php foreach ($all_problems as $problem): ?>
                                                        <div class="col-md-6">
                                                            <div class="card problem-card" data-problem-id="<?php echo $problem['id']; ?>">
                                                                <div class="card-body">
                                                                    <h5 class="card-title"><?php echo htmlspecialchars($problem['title']); ?></h5>
                                                                    <p class="card-text"><?php echo substr(htmlspecialchars($problem['description']), 0, 100) . '...'; ?></p>
                                                                    <span class="problem-points"><?php echo $problem['points']; ?> pts</span>
                                                                    <input type="checkbox" name="selected_problems[]" value="<?php echo $problem['id']; ?>" class="d-none problem-checkbox">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="mb-3 text-end">
                                        <button type="button" class="btn btn-secondary prev-tab" data-prev="details-tab">Previous: Contest Details</button>
                                        <button type="button" class="btn btn-primary next-tab" data-next="settings-tab">Next: Advanced Settings</button>
                                    </div>
                                </div>

                                <!-- Tab 3: Advanced Settings -->
                                <div class="tab-pane fade" id="settings" role="tabpanel" aria-labelledby="settings-tab">
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <h4>Contest Settings</h4>
                                            <div class="card mb-4">
                                                <div class="card-header">
                                                    <h5 class="mb-0">Anti-Cheating Features</h5>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-3">
                                                        <label for="allowed_tab_switches" class="form-label">Number of Allowed Tab Switches</label>
                                                        <input type="number" class="form-control" id="allowed_tab_switches" name="allowed_tab_switches" min="0" value="<?php echo $form_data['allowed_tab_switches']; ?>">
                                                        <div class="form-text">Set to 0 to completely prevent tab switching. Set a higher number to allow students a limited number of tab switches.</div>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="prevent_copy_paste" name="prevent_copy_paste" <?php echo $form_data['prevent_copy_paste'] ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="prevent_copy_paste">
                                                            Prevent Copy-Paste
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3 text-end">
                                        <button type="button" class="btn btn-secondary prev-tab" data-prev="challenges-tab">Previous: Select Challenges</button>
                                        <button type="submit" name="submit_contest" class="btn btn-success">Create Contest</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle enrollment file section visibility
            const contestTypeSelect = document.getElementById('contest_type');
            const enrollmentFileSection = document.getElementById('enrollment_file_section');
            
            contestTypeSelect.addEventListener('change', function() {
                if (this.value === 'private') {
                    enrollmentFileSection.classList.remove('d-none');
                } else {
                    enrollmentFileSection.classList.add('d-none');
                }
            });

            // Problem selection
            const problemCards = document.querySelectorAll('.problem-card');
            problemCards.forEach(card => {
                card.addEventListener('click', function() {
                    const checkbox = this.querySelector('.problem-checkbox');
                    this.classList.toggle('selected');
                    checkbox.checked = !checkbox.checked;
                });
            });

            // Tab navigation buttons
            const nextButtons = document.querySelectorAll('.next-tab');
            nextButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const nextTabId = this.getAttribute('data-next');
                    const nextTab = document.getElementById(nextTabId);
                    bootstrap.Tab.getOrCreateInstance(nextTab).show();
                });
            });

            const prevButtons = document.querySelectorAll('.prev-tab');
            prevButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const prevTabId = this.getAttribute('data-prev');
                    const prevTab = document.getElementById(prevTabId);
                    bootstrap.Tab.getOrCreateInstance(prevTab).show();
                });
            });
        });
    </script>
</body>
</html> 