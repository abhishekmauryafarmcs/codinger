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

// Get contest ID
if (!isset($_GET['id'])) {
    header("Location: manage_contests.php");
    exit();
}

$contest_id = (int)$_GET['id'];

// Initialize variables
$title = '';
$description = '';
$start_time = '';
$end_time = '';
$allowed_tab_switches = 0;
$prevent_copy_paste = 0;
$prevent_right_click = 0;
$max_submissions = 0;
$problems = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $allowed_tab_switches = isset($_POST['allowed_tab_switches']) ? intval($_POST['allowed_tab_switches']) : 0;
    $prevent_copy_paste = isset($_POST['prevent_copy_paste']) ? 1 : 0;
    $prevent_right_click = isset($_POST['prevent_right_click']) ? 1 : 0;
    $max_submissions = isset($_POST['max_submissions']) ? intval($_POST['max_submissions']) : 0;
    $problems = isset($_POST['problems']) ? $_POST['problems'] : [];

    // Validation
    if (empty($title)) $errors[] = "Title is required";
    if (empty($description)) $errors[] = "Description is required";
    if (empty($start_time)) $errors[] = "Start time is required";
    if (empty($end_time)) $errors[] = "End time is required";
    if (empty($problems)) $errors[] = "At least one problem is required";

    // Validate dates
    $start = new DateTime($start_time);
    $end = new DateTime($end_time);
    if ($end <= $start) {
        $errors[] = "End time must be after start time";
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            // Update contest details
            $stmt = $conn->prepare("UPDATE contests SET 
                title = ?, 
                description = ?, 
                start_time = ?, 
                end_time = ?, 
                allowed_tab_switches = ?, 
                prevent_copy_paste = ?, 
                prevent_right_click = ?,
                max_submissions = ? 
                WHERE id = ?");
            $stmt->bind_param("ssssiiiis", 
                $title, 
                $description, 
                $start_time, 
                $end_time, 
                $allowed_tab_switches, 
                $prevent_copy_paste, 
                $prevent_right_click,
                $max_submissions, 
                $contest_id
            );
            $stmt->execute();

            // Instead of deleting problems, just remove their association with this contest
            $stmt = $conn->prepare("UPDATE problems SET contest_id = NULL WHERE contest_id = ?");
            $stmt->bind_param("i", $contest_id);
            $stmt->execute();

            // Insert updated problems
            foreach ($problems as $problem) {
                if (!empty($problem['title'])) {
                    $stmt = $conn->prepare("INSERT INTO problems (contest_id, title, description, input_format, output_format, constraints, sample_input, sample_output, points) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssssssi", 
                        $contest_id,
                        $problem['title'],
                        $problem['description'],
                        $problem['input_format'],
                        $problem['output_format'],
                        $problem['constraints'],
                        $problem['sample_input'],
                        $problem['sample_output'],
                        $problem['points']
                    );
                    $stmt->execute();
                }
            }

            $conn->commit();
            header("Location: manage_contests.php?updated=1");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error updating contest: " . $e->getMessage();
        }
    }
} else {
    // Get contest details from database
    $stmt = $conn->prepare("SELECT title, description, start_time, end_time, allowed_tab_switches, prevent_copy_paste, prevent_right_click, max_submissions FROM contests WHERE id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($contest = $result->fetch_assoc()) {
        $title = $contest['title'];
        $description = $contest['description'];
        $start_time = $contest['start_time'];
        $end_time = $contest['end_time'];
        $allowed_tab_switches = $contest['allowed_tab_switches'];
        $prevent_copy_paste = $contest['prevent_copy_paste'];
        $prevent_right_click = $contest['prevent_right_click'];
        $max_submissions = $contest['max_submissions'];
    }
    $stmt->close();

    // Get contest problems
    $stmt = $conn->prepare("SELECT id, title, description, input_format, output_format, constraints, sample_input, sample_output, points FROM problems WHERE contest_id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $problems[] = $row;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Contest - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
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
                        <a class="nav-link" href="create_contest.php">Create Contest</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_contests.php">Manage Contests</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <h1 class="mb-4">Edit Contest</h1>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="card mb-4">
                <div class="card-body">
                    <h3 class="card-title">Contest Details</h3>
                    
                    <div class="mb-3">
                        <label for="title" class="form-label">Contest Title</label>
                        <input type="text" class="form-control" id="title" name="title" required 
                               value="<?php echo htmlspecialchars($title); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" required><?php echo htmlspecialchars($description); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_time" class="form-label">Start Time</label>
                            <input type="datetime-local" class="form-control" id="start_time" name="start_time" required
                                   value="<?php echo date('Y-m-d\TH:i', strtotime($start_time)); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="end_time" class="form-label">End Time</label>
                            <input type="datetime-local" class="form-control" id="end_time" name="end_time" required
                                   value="<?php echo date('Y-m-d\TH:i', strtotime($end_time)); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <h3 class="card-title">Advanced Settings</h3>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="allowed_tab_switches" class="form-label">Number of Allowed Tab Switches</label>
                            <input type="number" class="form-control" id="allowed_tab_switches" name="allowed_tab_switches" min="0" 
                                   value="<?php echo htmlspecialchars($allowed_tab_switches); ?>">
                            <div class="form-text">Set to 0 to completely prevent tab switching. Set a higher number to allow students a limited number of tab switches.</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="max_submissions" class="form-label">Maximum Submissions per Problem</label>
                            <input type="number" class="form-control" id="max_submissions" name="max_submissions" min="0" 
                                   value="<?php echo htmlspecialchars($max_submissions); ?>">
                            <div class="form-text">Set to 0 for unlimited submissions. Set a specific number to limit how many times a student can submit each problem.</div>
                        </div>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="prevent_copy_paste" name="prevent_copy_paste" 
                               <?php echo $prevent_copy_paste ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="prevent_copy_paste">
                            Prevent Copy-Paste
                        </label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="prevent_right_click" name="prevent_right_click" 
                               <?php echo $prevent_right_click ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="prevent_right_click">
                            Prevent Right Click
                        </label>
                    </div>
                </div>
            </div>

            <div id="problemsContainer">
                <!-- Problems will be added here -->
            </div>

            <button type="button" class="btn btn-secondary mb-4" onclick="addProblem()">Add Problem</button>
            <button type="submit" class="btn btn-primary mb-4">Update Contest</button>
            <a href="manage_contests.php" class="btn btn-link mb-4">Cancel</a>
        </form>
    </div>

    <!-- Problem Template -->
    <template id="problemTemplate">
        <div class="card mb-4 problem-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="card-title">Problem #<span class="problem-number"></span></h3>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeProblem(this)">Remove</button>
                </div>

                <div class="mb-3">
                    <label class="form-label">Problem Title</label>
                    <input type="text" class="form-control" name="problems[][title]" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Problem Description</label>
                    <textarea class="form-control" name="problems[][description]" rows="3" required></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Input Format</label>
                    <textarea class="form-control" name="problems[][input_format]" rows="2" required></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Output Format</label>
                    <textarea class="form-control" name="problems[][output_format]" rows="2" required></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Constraints</label>
                    <textarea class="form-control" name="problems[][constraints]" rows="2" required></textarea>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Sample Input</label>
                        <textarea class="form-control" name="problems[][sample_input]" rows="2" required></textarea>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Sample Output</label>
                        <textarea class="form-control" name="problems[][sample_output]" rows="2" required></textarea>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Points</label>
                    <input type="number" class="form-control" name="problems[][points]" min="1" required>
                </div>
            </div>
        </div>
    </template>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Debug: Log the problems data
        console.log('Problems:', <?php echo json_encode($problems); ?>);
        
        function addProblem(problemData = null) {
            const container = document.getElementById('problemsContainer');
            const template = document.getElementById('problemTemplate');
            const clone = template.content.cloneNode(true);
            
            const problemNumber = container.children.length + 1;
            clone.querySelector('.problem-number').textContent = problemNumber;

            if (problemData) {
                console.log('Adding problem with data:', problemData);
                const fields = ['title', 'description', 'input_format', 'output_format', 'constraints', 'sample_input', 'sample_output', 'points'];
                fields.forEach(field => {
                    const input = clone.querySelector(`[name="problems[][${field}]"]`);
                    if (input) {
                        input.value = problemData[field] || '';
                    }
                });
            }

            container.appendChild(clone);
            updateProblemNumbers();
        }

        function removeProblem(button) {
            const problemCard = button.closest('.problem-card');
            problemCard.remove();
            updateProblemNumbers();
        }

        function updateProblemNumbers() {
            const problems = document.querySelectorAll('.problem-card');
            problems.forEach((problem, index) => {
                problem.querySelector('.problem-number').textContent = index + 1;
            });
        }

        // Load existing problems when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const problems = <?php echo json_encode($problems); ?>;
            console.log('Loading problems:', problems);
            
            if (problems && problems.length > 0) {
                problems.forEach(problem => {
                    console.log('Loading problem:', problem);
                    addProblem(problem);
                });
            }
        });
    </script>
</body>
</html> 