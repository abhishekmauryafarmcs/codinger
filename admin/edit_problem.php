<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$errors = [];
$success = '';

// Check if columns exist in problems table
function columnExists($conn, $table, $column) {
    $sql = "SHOW COLUMNS FROM {$table} LIKE '{$column}'";
    $result = $conn->query($sql);
    return $result->num_rows > 0;
}

$has_created_at = columnExists($conn, 'problems', 'created_at');
$has_category = columnExists($conn, 'problems', 'category');
$has_difficulty = columnExists($conn, 'problems', 'difficulty');
$has_problem_type = columnExists($conn, 'problems', 'problem_type');
$has_time_limit = columnExists($conn, 'problems', 'time_limit');
$has_memory_limit = columnExists($conn, 'problems', 'memory_limit');

// Check if test_cases table exists
function tableExists($conn, $table) {
    $sql = "SHOW TABLES LIKE '{$table}'";
    $result = $conn->query($sql);
    return $result->num_rows > 0;
}

$has_test_cases_table = tableExists($conn, 'test_cases');

// Fetch categories for dropdown
$categories = [];
if ($has_category) {
    try {
        $stmt = $conn->prepare("SELECT DISTINCT category FROM problems WHERE category IS NOT NULL");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row['category'];
        }
    } catch (Exception $e) {
        // Silently handle the error
    }
}

// Get problem ID
if (!isset($_GET['id'])) {
    header("Location: manage_problems.php");
    exit();
}

$problem_id = (int)$_GET['id'];

// Initialize problem data
$problem = [];
$test_cases = [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_problem'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $input_format = trim($_POST['input_format']);
    $output_format = trim($_POST['output_format']);
    $constraints = trim($_POST['constraints']);
    $sample_input = trim($_POST['sample_input']);
    $sample_output = trim($_POST['sample_output']);
    $points = (int)$_POST['points'];
    
    // Optional fields based on schema
    $difficulty = $has_difficulty ? trim($_POST['difficulty'] ?? '') : '';
    $category = $has_category ? trim($_POST['category'] ?? '') : '';
    $problem_type = $has_problem_type ? trim($_POST['problem_type'] ?? '') : '';
    $time_limit = $has_time_limit ? (int)($_POST['time_limit'] ?? 1) : 1;
    $memory_limit = $has_memory_limit ? (int)($_POST['memory_limit'] ?? 256) : 256;
    
    // Collect test cases if they exist
    $test_case_ids = isset($_POST['test_case_id']) ? $_POST['test_case_id'] : [];
    $test_case_inputs = isset($_POST['test_case_input']) ? $_POST['test_case_input'] : [];
    $test_case_outputs = isset($_POST['test_case_output']) ? $_POST['test_case_output'] : [];
    $test_case_visible = isset($_POST['test_case_visible']) ? $_POST['test_case_visible'] : [];
    
    // Validation
    if (empty($title)) $errors[] = "Title is required";
    if (empty($description)) $errors[] = "Description is required";
    if (empty($input_format)) $errors[] = "Input format is required";
    if (empty($output_format)) $errors[] = "Output format is required";
    if (empty($constraints)) $errors[] = "Constraints are required";
    if (empty($sample_input)) $errors[] = "Sample input is required";
    if (empty($sample_output)) $errors[] = "Sample output is required";
    if ($points <= 0) $errors[] = "Points must be greater than 0";
    if ($has_time_limit && $time_limit <= 0) $errors[] = "Time limit must be greater than 0";
    if ($has_memory_limit && $memory_limit <= 0) $errors[] = "Memory limit must be greater than 0";

    if (empty($errors)) {
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Prepare SQL based on which columns exist
            $sql_parts = ["title = ?, description = ?, input_format = ?, output_format = ?, constraints = ?, sample_input = ?, sample_output = ?, points = ?"];
            $types = "sssssssi";
            $params = [
                $title, $description, $input_format, $output_format, 
                $constraints, $sample_input, $sample_output, $points
            ];

            if ($has_difficulty) {
                $sql_parts[] = "difficulty = ?";
                $types .= "s";
                $params[] = $difficulty;
            }

            if ($has_category) {
                $sql_parts[] = "category = ?";
                $types .= "s";
                $params[] = $category;
            }

            if ($has_problem_type) {
                $sql_parts[] = "problem_type = ?";
                $types .= "s";
                $params[] = $problem_type;
            }

            if ($has_time_limit) {
                $sql_parts[] = "time_limit = ?";
                $types .= "i";
                $params[] = $time_limit;
            }

            if ($has_memory_limit) {
                $sql_parts[] = "memory_limit = ?";
                $types .= "i";
                $params[] = $memory_limit;
            }

            $sql = "UPDATE problems SET " . implode(", ", $sql_parts) . " WHERE id = ?";
            $types .= "i";
            $params[] = $problem_id;

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                // Update test cases if table exists
                if ($has_test_cases_table) {
                    // Get current test case IDs to identify which ones to delete later
                    $existing_test_cases = [];
                    $tc_select_stmt = $conn->prepare("SELECT id FROM test_cases WHERE problem_id = ?");
                    $tc_select_stmt->bind_param("i", $problem_id);
                    $tc_select_stmt->execute();
                    $tc_result = $tc_select_stmt->get_result();
                    while ($tc_row = $tc_result->fetch_assoc()) {
                        $existing_test_cases[] = $tc_row['id'];
                    }
                    
                    // Track which test cases were updated
                    $updated_test_cases = [];
                    
                    // Update existing test cases and add new ones
                    if (!empty($test_case_inputs) && !empty($test_case_outputs)) {
                        foreach ($test_case_inputs as $index => $input) {
                            if (isset($test_case_outputs[$index]) && !empty($input) && !empty($test_case_outputs[$index])) {
                                $output = $test_case_outputs[$index];
                                $is_visible = in_array($index, $test_case_visible) ? 1 : 0;
                                
                                // Check if this is an existing test case or a new one
                                if (isset($test_case_ids[$index]) && $test_case_ids[$index] > 0) {
                                    // Update existing test case
                                    $tc_stmt = $conn->prepare("UPDATE test_cases SET input = ?, expected_output = ?, is_visible = ? WHERE id = ? AND problem_id = ?");
                                    $tc_stmt->bind_param("ssiii", $input, $output, $is_visible, $test_case_ids[$index], $problem_id);
                                    $tc_stmt->execute();
                                    
                                    // Add to the list of updated test cases
                                    $updated_test_cases[] = $test_case_ids[$index];
                                } else {
                                    // Insert new test case
                                    $tc_stmt = $conn->prepare("INSERT INTO test_cases (problem_id, input, expected_output, is_visible) VALUES (?, ?, ?, ?)");
                                    $tc_stmt->bind_param("issi", $problem_id, $input, $output, $is_visible);
                                    $tc_stmt->execute();
                                    
                                    // Add newly created test case to updated list
                                    if ($tc_stmt->insert_id) {
                                        $updated_test_cases[] = $tc_stmt->insert_id;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Delete test cases that weren't updated (removed by user)
                    $test_cases_to_delete = array_diff($existing_test_cases, $updated_test_cases);
                    if (!empty($test_cases_to_delete)) {
                        foreach ($test_cases_to_delete as $tc_id) {
                            $tc_delete_stmt = $conn->prepare("DELETE FROM test_cases WHERE id = ? AND problem_id = ?");
                            $tc_delete_stmt->bind_param("ii", $tc_id, $problem_id);
                            $tc_delete_stmt->execute();
                        }
                    }
                }
                
                $conn->commit();
                $success = "Problem updated successfully";
                
                // Reload problem data
                $stmt = $conn->prepare("SELECT * FROM problems WHERE id = ?");
                $stmt->bind_param("i", $problem_id);
                $stmt->execute();
                $problem = $stmt->get_result()->fetch_assoc();
                
                // Reload test cases
                if ($has_test_cases_table) {
                    $tc_stmt = $conn->prepare("SELECT * FROM test_cases WHERE problem_id = ?");
                    $tc_stmt->bind_param("i", $problem_id);
                    $tc_stmt->execute();
                    $test_cases = $tc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                }
            }
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error updating problem: " . $e->getMessage();
        }
    }
}

// Fetch problem data
$stmt = $conn->prepare("SELECT * FROM problems WHERE id = ?");
$stmt->bind_param("i", $problem_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Problem not found
    header("Location: manage_problems.php");
    exit();
}

$problem = $result->fetch_assoc();

// Fetch test cases if table exists
if ($has_test_cases_table) {
    $tc_stmt = $conn->prepare("SELECT * FROM test_cases WHERE problem_id = ?");
    $tc_stmt->bind_param("i", $problem_id);
    $tc_stmt->execute();
    $test_cases = $tc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Problem - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
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
                        <a class="nav-link" href="manage_contests.php">Manage Contests</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="manage_problems.php">Manage Problems</a>
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
            <h1>Edit Problem</h1>
            <a href="manage_problems.php" class="btn btn-secondary">Back to Problems</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <!-- Tab Navigation -->
            <ul class="nav nav-tabs mb-4" id="problemTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab" aria-controls="basic" aria-selected="true">Basic Info</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab" aria-controls="details" aria-selected="false">Problem Details</button>
                </li>
                <?php if ($has_test_cases_table): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="test-cases-tab" data-bs-toggle="tab" data-bs-target="#test-cases" type="button" role="tab" aria-controls="test-cases" aria-selected="false">Test Cases</button>
                </li>
                <?php endif; ?>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="problemTabsContent">
                <!-- Basic Info Tab -->
                <div class="tab-pane fade show active" id="basic" role="tabpanel" aria-labelledby="basic-tab">
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="title" class="form-label">Problem Title</label>
                                        <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($problem['title']); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Problem Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="6" required><?php echo htmlspecialchars($problem['description']); ?></textarea>
                                        <div class="form-text">Explain the problem clearly. You can use simple HTML formatting if needed.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="row">
                                        <?php if ($has_difficulty): ?>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="difficulty" class="form-label">Difficulty Level</label>
                                                <select class="form-select" id="difficulty" name="difficulty" required>
                                                    <option value="easy" <?php echo ($problem['difficulty'] === 'easy') ? 'selected' : ''; ?>>Easy</option>
                                                    <option value="medium" <?php echo ($problem['difficulty'] === 'medium') ? 'selected' : ''; ?>>Medium</option>
                                                    <option value="hard" <?php echo ($problem['difficulty'] === 'hard') ? 'selected' : ''; ?>>Hard</option>
                                                </select>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <div class="col-md-<?php echo $has_difficulty ? '6' : '12'; ?>">
                                            <div class="mb-3">
                                                <label for="points" class="form-label">Points</label>
                                                <input type="number" class="form-control" id="points" name="points" min="1" max="1000" value="<?php echo htmlspecialchars($problem['points']); ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($has_problem_type || $has_category): ?>
                                    <div class="row">
                                        <?php if ($has_problem_type): ?>
                                        <div class="col-md-<?php echo $has_category ? '6' : '12'; ?>">
                                            <div class="mb-3">
                                                <label for="problem_type" class="form-label">Problem Type</label>
                                                <select class="form-select" id="problem_type" name="problem_type">
                                                    <option value="algorithm" <?php echo ($problem['problem_type'] === 'algorithm') ? 'selected' : ''; ?>>Algorithm</option>
                                                    <option value="data_structure" <?php echo ($problem['problem_type'] === 'data_structure') ? 'selected' : ''; ?>>Data Structure</option>
                                                    <option value="string" <?php echo ($problem['problem_type'] === 'string') ? 'selected' : ''; ?>>String Manipulation</option>
                                                    <option value="math" <?php echo ($problem['problem_type'] === 'math') ? 'selected' : ''; ?>>Mathematical</option>
                                                    <option value="dynamic_programming" <?php echo ($problem['problem_type'] === 'dynamic_programming') ? 'selected' : ''; ?>>Dynamic Programming</option>
                                                    <option value="greedy" <?php echo ($problem['problem_type'] === 'greedy') ? 'selected' : ''; ?>>Greedy</option>
                                                    <option value="graph" <?php echo ($problem['problem_type'] === 'graph') ? 'selected' : ''; ?>>Graph Theory</option>
                                                    <option value="sorting" <?php echo ($problem['problem_type'] === 'sorting') ? 'selected' : ''; ?>>Sorting & Searching</option>
                                                    <option value="other" <?php echo ($problem['problem_type'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($has_category): ?>
                                        <div class="col-md-<?php echo $has_problem_type ? '6' : '12'; ?>">
                                            <div class="mb-3">
                                                <label for="category" class="form-label">Category</label>
                                                <input type="text" class="form-control" id="category" name="category" list="category-list" value="<?php echo htmlspecialchars($problem['category'] ?? ''); ?>">
                                                <datalist id="category-list">
                                                    <?php foreach ($categories as $cat): ?>
                                                        <option value="<?php echo htmlspecialchars($cat); ?>">
                                                    <?php endforeach; ?>
                                                </datalist>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($has_time_limit || $has_memory_limit): ?>
                                    <div class="row">
                                        <?php if ($has_time_limit): ?>
                                        <div class="col-md-<?php echo $has_memory_limit ? '6' : '12'; ?>">
                                            <div class="mb-3">
                                                <label for="time_limit" class="form-label">Time Limit (seconds)</label>
                                                <input type="number" class="form-control" id="time_limit" name="time_limit" min="1" max="10" value="<?php echo htmlspecialchars($problem['time_limit'] ?? 1); ?>" required>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($has_memory_limit): ?>
                                        <div class="col-md-<?php echo $has_time_limit ? '6' : '12'; ?>">
                                            <div class="mb-3">
                                                <label for="memory_limit" class="form-label">Memory Limit (MB)</label>
                                                <input type="number" class="form-control" id="memory_limit" name="memory_limit" min="16" max="512" value="<?php echo htmlspecialchars($problem['memory_limit'] ?? 256); ?>" required>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Problem Details Tab -->
                <div class="tab-pane fade" id="details" role="tabpanel" aria-labelledby="details-tab">
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="input_format" class="form-label">Input Format</label>
                                        <textarea class="form-control" id="input_format" name="input_format" rows="5" required><?php echo htmlspecialchars($problem['input_format']); ?></textarea>
                                        <div class="form-text">Describe the format of the input that contestants will receive.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="output_format" class="form-label">Output Format</label>
                                        <textarea class="form-control" id="output_format" name="output_format" rows="5" required><?php echo htmlspecialchars($problem['output_format']); ?></textarea>
                                        <div class="form-text">Describe the expected format of the output that contestants should produce.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="constraints" class="form-label">Constraints</label>
                                <textarea class="form-control" id="constraints" name="constraints" rows="3" required><?php echo htmlspecialchars($problem['constraints']); ?></textarea>
                                <div class="form-text">Specify the constraints on input values, time and space complexity, etc.</div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="sample_input" class="form-label">Sample Input</label>
                                        <textarea class="form-control" id="sample_input" name="sample_input" rows="5" required><?php echo htmlspecialchars($problem['sample_input']); ?></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="sample_output" class="form-label">Sample Output</label>
                                        <textarea class="form-control" id="sample_output" name="sample_output" rows="5" required><?php echo htmlspecialchars($problem['sample_output']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($has_test_cases_table): ?>
                <!-- Test Cases Tab -->
                <div class="tab-pane fade" id="test-cases" role="tabpanel" aria-labelledby="test-cases-tab">
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> The sample input/output is automatically added as a visible test case.
                                Add additional test cases below for more thorough testing.
                            </div>
                            
                            <div id="test-cases-container">
                                <?php foreach ($test_cases as $index => $test_case): ?>
                                <div class="test-case mb-4 pb-4 border-bottom">
                                    <input type="hidden" name="test_case_id[]" value="<?php echo $test_case['id']; ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5>Test Case #<?php echo $index + 1; ?></h5>
                                        <div class="d-flex align-items-center">
                                            <div class="form-check me-3">
                                                <input class="form-check-input" type="checkbox" name="test_case_visible[]" value="<?php echo $index; ?>" id="visible_<?php echo $index; ?>" <?php echo $test_case['is_visible'] ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="visible_<?php echo $index; ?>">
                                                    Visible to contestants
                                                </label>
                                            </div>
                                            <?php if ($index > 0 || count($test_cases) > 1): ?>
                                            <button type="button" class="btn btn-sm btn-danger remove-test-case">
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Input</label>
                                                <textarea class="form-control" name="test_case_input[]" rows="4" required><?php echo htmlspecialchars($test_case['input']); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Expected Output</label>
                                                <textarea class="form-control" name="test_case_output[]" rows="4" required><?php echo htmlspecialchars($test_case['expected_output']); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <button type="button" class="btn btn-secondary" id="add-test-case">
                                <i class="bi bi-plus-circle"></i> Add Test Case
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="d-flex justify-content-between mt-4">
                <a href="manage_problems.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" name="update_problem" class="btn btn-primary">Update Problem</button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($has_test_cases_table): ?>
    <script>
        // Test case counter for new test cases
        let testCaseCounter = <?php echo count($test_cases); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listeners to existing remove buttons
            attachRemoveEventListeners();
            
            // Add test case functionality
            document.getElementById('add-test-case').addEventListener('click', function() {
                const container = document.getElementById('test-cases-container');
                const newTestCase = document.createElement('div');
                newTestCase.className = 'test-case mb-4 pb-4 border-bottom';
                newTestCase.innerHTML = `
                    <input type="hidden" name="test_case_id[]" value="0">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5>Test Case #${testCaseCounter + 1}</h5>
                        <div class="d-flex align-items-center">
                            <div class="form-check me-3">
                                <input class="form-check-input" type="checkbox" name="test_case_visible[]" value="${testCaseCounter}" id="visible_${testCaseCounter}">
                                <label class="form-check-label" for="visible_${testCaseCounter}">
                                    Visible to contestants
                                </label>
                            </div>
                            <button type="button" class="btn btn-sm btn-danger remove-test-case">
                                <i class="bi bi-trash"></i> Remove
                            </button>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Input</label>
                                <textarea class="form-control" name="test_case_input[]" rows="4" required></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Expected Output</label>
                                <textarea class="form-control" name="test_case_output[]" rows="4" required></textarea>
                            </div>
                        </div>
                    </div>
                `;
                container.appendChild(newTestCase);
                testCaseCounter++;
                
                // Attach event listener to the new remove button
                attachRemoveEventListeners();
            });
        });
        
        // Function to attach event listeners to remove buttons
        function attachRemoveEventListeners() {
            const removeButtons = document.querySelectorAll('.remove-test-case');
            removeButtons.forEach(button => {
                button.removeEventListener('click', removeTestCase);
                button.addEventListener('click', removeTestCase);
            });
        }
        
        // Function to remove a test case
        function removeTestCase() {
            this.closest('.test-case').remove();
            
            // Update test case numbers
            const testCases = document.querySelectorAll('.test-case');
            testCases.forEach((testCase, index) => {
                testCase.querySelector('h5').textContent = `Test Case #${index + 1}`;
            });
        }
    </script>
    <?php endif; ?>
</body>
</html> 