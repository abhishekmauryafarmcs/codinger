<?php
session_start();
require_once '../config/session.php';
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    header("Location: login.php?error=invalid_credentials");
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
    'type' => 'private', // Always set to private
    'allowed_tab_switches' => isset($_POST['allowed_tab_switches']) ? $_POST['allowed_tab_switches'] : 0,
    'prevent_copy_paste' => isset($_POST['prevent_copy_paste']) ? $_POST['prevent_copy_paste'] : 0,
    'prevent_right_click' => isset($_POST['prevent_right_click']) ? $_POST['prevent_right_click'] : 0,
    'max_submissions' => isset($_POST['max_submissions']) ? $_POST['max_submissions'] : 0,
    'problems' => isset($_POST['problems']) ? $_POST['problems'] : []
];

// Get all existing problems from the database
$all_problems = [];
$stmt = $conn->prepare("SELECT id, title, description, input_format, output_format, constraints, points FROM problems");
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
        'type' => 'private', // Always set to private
        'allowed_tab_switches' => isset($_POST['allowed_tab_switches']) ? intval($_POST['allowed_tab_switches']) : 0,
        'prevent_copy_paste' => isset($_POST['prevent_copy_paste']) ? 1 : 0,
        'prevent_right_click' => isset($_POST['prevent_right_click']) ? 1 : 0,
        'max_submissions' => isset($_POST['max_submissions']) ? intval($_POST['max_submissions']) : 0,
        'selected_problems' => isset($_POST['selected_problems']) ? $_POST['selected_problems'] : []
    ];

    // Enhanced Validation
    if (empty($form_data['title']) || strlen($form_data['title']) < 5) {
        $errors[] = "Title must be at least 5 characters long";
    }
    
    if (empty($form_data['description']) || strlen($form_data['description']) < 20) {
        $errors[] = "Description must be at least 20 characters long";
    }
    
    if (empty($form_data['start_time'])) {
        $errors[] = "Start time is required";
    }
    
    if (empty($form_data['end_time'])) {
        $errors[] = "End time is required";
    }
    
    if (empty($form_data['type'])) {
        $errors[] = "Contest type is required";
    }

    // Validate enrollment file (always required since contests are always private)
        if (!isset($_FILES['enrollment_file']) || $_FILES['enrollment_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Enrollment file with student enrollment numbers is required";
        } else {
            $file_extension = strtolower(pathinfo($_FILES['enrollment_file']['name'], PATHINFO_EXTENSION));
            if ($file_extension !== 'xlsx' && $file_extension !== 'csv' && $file_extension !== 'txt') {
                $errors[] = "Only CSV (.csv), Excel (.xlsx) or text (.txt) files are allowed";
        }
    }

    // Validate numeric fields
    if ($form_data['allowed_tab_switches'] < 0 || $form_data['allowed_tab_switches'] > 100) {
        $errors[] = "Number of allowed tab switches must be between 0 and 100";
    }

    if ($form_data['max_submissions'] < 0 || $form_data['max_submissions'] > 100) {
        $errors[] = "Maximum submissions must be between 0 and 100";
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
                allowed_tab_switches, prevent_copy_paste, prevent_right_click, max_submissions
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("sssssiiiii", 
                $form_data['title'],
                $form_data['description'],
                $form_data['start_time'],
                $form_data['end_time'],
                $form_data['type'],
                $_SESSION['admin']['user_id'],
                $form_data['allowed_tab_switches'],
                $form_data['prevent_copy_paste'],
                $form_data['prevent_right_click'],
                $form_data['max_submissions']
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
                $stmt = $conn->prepare("INSERT INTO contest_problems (contest_id, problem_id) VALUES (?, ?)");
                
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
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
                                                <label for="title" class="form-label">Contest Title <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="title" name="title" required 
                                                    value="<?php echo htmlspecialchars($form_data['title']); ?>"
                                                    minlength="5" maxlength="200">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="contest_type" class="form-label">Contest Type <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="contest_type_display" value="Private (By enrollment only)" disabled>
                                                <input type="hidden" name="contest_type" value="private">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="description" class="form-label">Contest Description <span class="text-danger">*</span></label>
                                        <textarea class="form-control" id="description" name="description" rows="4" required 
                                            minlength="20"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="start_time" class="form-label">Start Time <span class="text-danger">*</span></label>
                                                <input type="datetime-local" class="form-control" id="start_time" name="start_time" required 
                                                    value="<?php echo htmlspecialchars($form_data['start_time']); ?>">
                                                <div class="invalid-feedback">
                                                    Please select a valid future start time
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="end_time" class="form-label">End Time <span class="text-danger">*</span></label>
                                                <input type="datetime-local" class="form-control" id="end_time" name="end_time" required 
                                                    value="<?php echo htmlspecialchars($form_data['end_time']); ?>">
                                                <div class="invalid-feedback">
                                                    End time must be after start time
                                                </div>
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
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <p class="mb-0">Choose problems from the existing problem bank to include in this contest.</p>
                                                <a href="manage_problems.php?action=add" class="btn btn-primary btn-sm">
                                                    <i class="bi bi-plus-circle"></i> Create New Problem
                                                </a>
                                            </div>
                                            
                                            <div class="alert alert-info mb-4">
                                                <h6><i class="bi bi-info-circle"></i> Problem Selection Guide</h6>
                                                <ul class="mb-0">
                                                    <li><strong>Available Problems:</strong> Problems with a white background can be selected for this contest.</li>
                                                    <li><strong>Selected Problems:</strong> Problems with a green background are selected for this contest.</li>
                                                </ul>
                                            </div>
                                            
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
                                                    <div class="mb-3">
                                                        <label for="max_submissions" class="form-label">Maximum Submissions per Problem</label>
                                                        <input type="number" class="form-control" id="max_submissions" name="max_submissions" min="0" value="<?php echo $form_data['max_submissions']; ?>">
                                                        <div class="form-text">Set to 0 for unlimited submissions. Set a specific number to limit how many times a student can submit each problem.</div>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="prevent_copy_paste" name="prevent_copy_paste" <?php echo $form_data['prevent_copy_paste'] ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="prevent_copy_paste">
                                                            Prevent Copy-Paste
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="prevent_right_click" name="prevent_right_click" <?php echo $form_data['prevent_right_click'] ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="prevent_right_click">
                                                            Prevent Right Click
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
            const form = document.querySelector('form');
            const title = document.getElementById('title');
            const description = document.getElementById('description');
            const startTime = document.getElementById('start_time');
            const endTime = document.getElementById('end_time');
            // Contest type is now fixed as private
            const contestType = document.querySelector('input[name="contest_type"]');

            // Function to remove error alerts
            function removeErrorAlerts() {
                const existingAlerts = document.querySelectorAll('.alert-danger');
                existingAlerts.forEach(alert => alert.remove());
            }

            // Function to check if there are any validation errors
            function checkForErrors() {
                const invalidInputs = form.querySelectorAll('.is-invalid');
                if (invalidInputs.length === 0) {
                    removeErrorAlerts();
                }
            }

            // Add input event listeners to all form fields
            form.querySelectorAll('input, textarea, select').forEach(input => {
                input.addEventListener('input', checkForErrors);
                input.addEventListener('change', checkForErrors);
            });

            // Function to validate form before submission
            function validateForm(event) {
                let hasErrors = false;
                const errors = [];

                // Reset previous error states
                const inputs = form.querySelectorAll('.form-control, .form-select');
                inputs.forEach(input => input.classList.remove('is-invalid'));

                // Validate title
                if (!title.value || title.value.length < 5) {
                    title.classList.add('is-invalid');
                    errors.push('Title must be at least 5 characters long');
                    hasErrors = true;
                }

                // Validate description
                if (!description.value || description.value.length < 20) {
                    description.classList.add('is-invalid');
                    errors.push('Description must be at least 20 characters long');
                    hasErrors = true;
                }

                // Validate dates
                const now = new Date();
                let start = null;
                let end = null;

                // Validate start time
                if (!startTime.value) {
                    startTime.classList.add('is-invalid');
                    errors.push('Start time is required');
                    hasErrors = true;
                } else {
                    start = new Date(startTime.value);
                    if (start < now) {
                        startTime.classList.add('is-invalid');
                        errors.push('Start time must be in the future');
                        hasErrors = true;
                    }
                }

                // Validate end time
                if (!endTime.value) {
                    endTime.classList.add('is-invalid');
                    errors.push('End time is required');
                    hasErrors = true;
                } else {
                    end = new Date(endTime.value);
                    if (start && end <= start) {
                        endTime.classList.add('is-invalid');
                        errors.push('End time must be after start time');
                        hasErrors = true;
                    }
                }

                // Validate contest type
                if (!contestType.value) {
                    contestType.classList.add('is-invalid');
                    errors.push('Please select a contest type');
                    hasErrors = true;
                }

                // Validate enrollment file (always required since contests are always private)
                    const enrollmentFile = document.querySelector('input[name="enrollment_file"]');
                    if (!enrollmentFile.files.length) {
                        enrollmentFile.classList.add('is-invalid');
                    errors.push('Please upload an enrollment file with student enrollment numbers');
                        hasErrors = true;
                }

                if (hasErrors) {
                    event.preventDefault();
                    // Show errors in the alert box
                    alert('Please fix the following errors:\n\n' + errors.join('\n'));
                    
                    // Remove any existing error alerts
                    removeErrorAlerts();
                    
                    // Create new error alert
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger';
                    errorDiv.innerHTML = '<ul class="mb-0">' + 
                        errors.map(error => '<li>' + error + '</li>').join('') + 
                        '</ul>';
                    
                    // Insert the new error alert at the top of the form
                    form.insertBefore(errorDiv, form.firstChild);
                    
                    // Switch to the first tab if we're not on it
                    if (!document.getElementById('details').classList.contains('show')) {
                        bootstrap.Tab.getOrCreateInstance(document.getElementById('details-tab')).show();
                    }
                } else {
                    removeErrorAlerts();
                }
            }

            // Add validation to form submission
            form.addEventListener('submit', validateForm);

            // Add validation to "Next" buttons
            document.querySelectorAll('.next-tab').forEach(button => {
                button.addEventListener('click', function(event) {
                    // Validate the current tab before proceeding
                    validateForm(event);
                    if (!event.defaultPrevented) {
                        const nextTabId = this.getAttribute('data-next');
                        const nextTab = document.getElementById(nextTabId);
                        bootstrap.Tab.getOrCreateInstance(nextTab).show();
                    }
                });
            });

            // Real-time validation for dates
            function validateDates() {
                const now = new Date();
                const start = startTime.value ? new Date(startTime.value) : null;
                const end = endTime.value ? new Date(endTime.value) : null;

                if (!startTime.value) {
                    startTime.classList.add('is-invalid');
                } else if (start < now) {
                    startTime.classList.add('is-invalid');
                } else {
                    startTime.classList.remove('is-invalid');
                }

                if (!endTime.value) {
                    endTime.classList.add('is-invalid');
                } else if (start && end <= start) {
                    endTime.classList.add('is-invalid');
                } else {
                    endTime.classList.remove('is-invalid');
                }

                // Check for any remaining errors
                checkForErrors();
            }

            startTime.addEventListener('change', validateDates);
            endTime.addEventListener('change', validateDates);
            
            // Initial validation on page load
            validateDates();

            // Always show enrollment file input since contest is always private
                const enrollmentFileDiv = document.getElementById('enrollment_file_section');
                    enrollmentFileDiv.classList.remove('d-none');

            // Problem selection
            const problemCards = document.querySelectorAll('.problem-card');
            problemCards.forEach(card => {
                card.addEventListener('click', function() {
                    const checkbox = this.querySelector('.problem-checkbox');
                    this.classList.toggle('selected');
                    checkbox.checked = !checkbox.checked;
                });
            });
        });
    </script>
</body>
</html> 