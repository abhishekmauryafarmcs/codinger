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

$order_by = $has_created_at ? "ORDER BY created_at DESC" : "ORDER BY id DESC";

// Fetch problems that are not assigned to any contest (problem bank)
$stmt = $conn->prepare("SELECT * FROM problems WHERE contest_id IS NULL " . $order_by);
$stmt->execute();
$problems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
        // Silently handle the error - if category doesn't exist, we'll just have an empty list
    }
}

// Check if test_cases table exists
function tableExists($conn, $table) {
    $sql = "SHOW TABLES LIKE '{$table}'";
    $result = $conn->query($sql);
    return $result->num_rows > 0;
}

$has_test_cases_table = tableExists($conn, 'test_cases');

// Add a new problem
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_problem'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $input_format = trim($_POST['input_format']);
    $output_format = trim($_POST['output_format']);
    $constraints = trim($_POST['constraints']);
    $sample_input = trim($_POST['sample_input']);
    $sample_output = trim($_POST['sample_output']);
    $points = (int)$_POST['points'];
    
    // Collect additional test cases if they exist
    $test_case_inputs = isset($_POST['test_case_input']) ? $_POST['test_case_input'] : [];
    $test_case_outputs = isset($_POST['test_case_output']) ? $_POST['test_case_output'] : [];
    $test_case_visible = isset($_POST['test_case_visible']) ? $_POST['test_case_visible'] : [];
    
    // Only set these fields if columns exist
    $difficulty = $has_difficulty ? trim($_POST['difficulty']) : '';
    $category = $has_category ? trim($_POST['category']) : '';
    $problem_type = $has_problem_type ? trim($_POST['problem_type']) : '';
    $time_limit = $has_time_limit ? (int)$_POST['time_limit'] : 1;
    $memory_limit = $has_memory_limit ? (int)$_POST['memory_limit'] : 256;

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
        // Check if category already exists, if not add it
        if ($has_category && !empty($category) && !in_array($category, $categories)) {
            $categories[] = $category;
        }

        // Prepare SQL based on which columns exist
        $columns = "title, description, input_format, output_format, constraints, sample_input, sample_output, points";
        $placeholders = "?, ?, ?, ?, ?, ?, ?, ?";
        $types = "sssssssi";
        $params = [
            $title, $description, $input_format, $output_format, 
            $constraints, $sample_input, $sample_output, $points
        ];

        if ($has_difficulty) {
            $columns .= ", difficulty";
            $placeholders .= ", ?";
            $types .= "s";
            $params[] = $difficulty;
        }

        if ($has_category) {
            $columns .= ", category";
            $placeholders .= ", ?";
            $types .= "s";
            $params[] = $category;
        }

        if ($has_problem_type) {
            $columns .= ", problem_type";
            $placeholders .= ", ?";
            $types .= "s";
            $params[] = $problem_type;
        }

        if ($has_time_limit) {
            $columns .= ", time_limit";
            $placeholders .= ", ?";
            $types .= "i";
            $params[] = $time_limit;
        }

        if ($has_memory_limit) {
            $columns .= ", memory_limit";
            $placeholders .= ", ?";
            $types .= "i";
            $params[] = $memory_limit;
        }

        $columns .= ", created_by";
        $placeholders .= ", ?";
        $types .= "i";
        $params[] = $_SESSION['user_id'];

        $sql = "INSERT INTO problems ($columns) VALUES ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $problem_id = $conn->insert_id;
            $success = "Problem added successfully";
            
            // If test_cases table exists, store test cases
            if ($has_test_cases_table) {
                // Add sample test case (always visible)
                $stmt = $conn->prepare("INSERT INTO test_cases (problem_id, input, expected_output, is_visible) VALUES (?, ?, ?, 1)");
                $stmt->bind_param("iss", $problem_id, $sample_input, $sample_output);
                $stmt->execute();
                
                // Add additional test cases if provided
                if (!empty($test_case_inputs) && !empty($test_case_outputs)) {
                    $stmt = $conn->prepare("INSERT INTO test_cases (problem_id, input, expected_output, is_visible) VALUES (?, ?, ?, ?)");
                    
                    foreach ($test_case_inputs as $key => $input) {
                        if (isset($test_case_outputs[$key]) && !empty($input) && !empty($test_case_outputs[$key])) {
                            $output = $test_case_outputs[$key];
                            $is_visible = in_array($key + 1, $test_case_visible) ? 1 : 0; // Check if test case should be visible
                            
                            $stmt->bind_param("issi", $problem_id, $input, $output, $is_visible);
                            $stmt->execute();
                        }
                    }
                }
            }
            
            // Reload problems
            $stmt = $conn->prepare("SELECT * FROM problems WHERE contest_id IS NULL " . $order_by);
            $stmt->execute();
            $problems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // First-time run - let's create a redirect to run database update
            if (!$has_category || !$has_difficulty || !$has_problem_type || !$has_time_limit || !$has_memory_limit) {
                header("Location: ../update_database.php?from=manage_problems");
                exit();
            } else if (!$has_test_cases_table) {
                // If we need to update test cases
                header("Location: ../update_test_cases.php?from=manage_problems");
                exit();
            }
        } else {
            $errors[] = "Error adding problem: " . $conn->error;
        }
    }
}

// Handle search/filter
$search_term = '';
$filter_category = '';
$filter_difficulty = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $search_term = trim($_GET['search_term'] ?? '');
    $filter_category = $has_category ? trim($_GET['filter_category'] ?? '') : '';
    $filter_difficulty = $has_difficulty ? trim($_GET['filter_difficulty'] ?? '') : '';
    
    // Build query with filters
    $query = "SELECT * FROM problems WHERE contest_id IS NULL";
    $params = [];
    $types = "";
    
    if (!empty($search_term)) {
        $query .= " AND (title LIKE ? OR description LIKE ?)";
        $search_param = "%" . $search_term . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ss";
    }
    
    if ($has_category && !empty($filter_category)) {
        $query .= " AND category = ?";
        $params[] = $filter_category;
        $types .= "s";
    }
    
    if ($has_difficulty && !empty($filter_difficulty)) {
        $query .= " AND difficulty = ?";
        $params[] = $filter_difficulty;
        $types .= "s";
    }
    
    $query .= " " . $order_by;
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $problems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Delete a problem
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $problem_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM problems WHERE id = ? AND contest_id IS NULL");
    $stmt->bind_param("i", $problem_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $success = "Problem deleted successfully";
        // Reload problems
        $stmt = $conn->prepare("SELECT * FROM problems WHERE contest_id IS NULL " . $order_by);
        $stmt->execute();
        $problems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $errors[] = "Error deleting problem or problem is already assigned to a contest";
    }
}

// Count total problems in bank
$total_problems = count($problems);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Problems - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        .problem-card {
            margin-bottom: 20px;
            transition: all 0.2s;
            position: relative;
        }
        .problem-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .problem-points {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #198754;
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        .problem-difficulty {
            position: absolute;
            top: 15px;
            right: 80px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            color: white;
        }
        .difficulty-easy {
            background-color: #28a745;
        }
        .difficulty-medium {
            background-color: #ffc107;
            color: #212529;
        }
        .difficulty-hard {
            background-color: #dc3545;
        }
        .problem-category {
            position: absolute;
            top: 50px;
            right: 15px;
            background: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
        }
        pre {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .delete-btn {
            position: absolute;
            right: 10px;
            bottom: 10px;
        }
        .problem-stats {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            font-size: 0.85rem;
            color: #6c757d;
        }
        .tab-content {
            padding: 20px 0;
        }
        .nav-tabs .nav-link {
            font-weight: 500;
        }
        .search-box {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .empty-state {
            text-align: center;
            padding: 40px 0;
        }
        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        .alert-update {
            background-color: #fff3cd;
            border-left: 5px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
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
                        <a class="nav-link" href="create_contest.php">Create Contest</a>
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

    <div class="container mt-4">
        <?php if (!$has_category || !$has_difficulty || !$has_problem_type || !$has_time_limit || !$has_memory_limit): ?>
            <div class="alert-update">
                <h4><i class="bi bi-exclamation-triangle-fill"></i> Database Update Required</h4>
                <p>Some database features need to be updated for full functionality.</p>
                <a href="../update_database.php" class="btn btn-warning">Update Database Now</a>
            </div>
        <?php endif; ?>

        <?php if (!$has_test_cases_table): ?>
            <div class="alert-update" style="background-color: #e6f7ff; border-left: 5px solid #0d6efd;">
                <h4><i class="bi bi-database-fill-gear"></i> Test Cases Feature Available</h4>
                <p>Upgrade your problem bank with the new test cases feature! This allows you to create both visible and hidden test cases for each problem.</p>
                <a href="../update_test_cases.php" class="btn btn-primary">Install Test Cases Feature</a>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card shadow mb-4">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">Problem Bank</h3>
                        <div>
                            <span class="badge bg-primary me-2">Total Problems: <?php echo $total_problems; ?></span>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addProblemModal">
                                <i class="bi bi-plus-circle"></i> Add New Problem
                            </button>
                        </div>
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

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success">
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Search & Filter Box -->
                        <div class="search-box">
                            <form method="get" action="" class="row g-3">
                                <div class="col-md-<?php echo ($has_category || $has_difficulty) ? '5' : '11'; ?>">
                                    <label for="search_term" class="form-label">Search</label>
                                    <input type="text" class="form-control" id="search_term" name="search_term" placeholder="Search by title or description" value="<?php echo htmlspecialchars($search_term); ?>">
                                </div>
                                
                                <?php if ($has_category): ?>
                                <div class="col-md-3">
                                    <label for="filter_category" class="form-label">Category</label>
                                    <select class="form-select" id="filter_category" name="filter_category">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_category === $cat ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($has_difficulty): ?>
                                <div class="col-md-3">
                                    <label for="filter_difficulty" class="form-label">Difficulty</label>
                                    <select class="form-select" id="filter_difficulty" name="filter_difficulty">
                                        <option value="">All Difficulties</option>
                                        <option value="easy" <?php echo $filter_difficulty === 'easy' ? 'selected' : ''; ?>>Easy</option>
                                        <option value="medium" <?php echo $filter_difficulty === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="hard" <?php echo $filter_difficulty === 'hard' ? 'selected' : ''; ?>>Hard</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-md-1 d-flex align-items-end">
                                    <button type="submit" name="search" class="btn btn-primary w-100">Filter</button>
                                </div>
                            </form>
                        </div>

                        <?php if (empty($problems)): ?>
                            <div class="empty-state">
                                <i class="bi bi-file-earmark-code"></i>
                                <h4>No problems found</h4>
                                <p class="text-muted">Add problems to your question bank to include them in contests.</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProblemModal">
                                    <i class="bi bi-plus-circle"></i> Add Your First Problem
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($problems as $problem): ?>
                                    <div class="col-md-6">
                                        <div class="card problem-card">
                                            <div class="card-body">
                                                <h5 class="card-title"><?php echo htmlspecialchars($problem['title']); ?></h5>
                                                <span class="problem-points"><?php echo $problem['points']; ?> pts</span>
                                                
                                                <?php if ($has_difficulty && !empty($problem['difficulty'])): ?>
                                                    <span class="problem-difficulty difficulty-<?php echo strtolower(htmlspecialchars($problem['difficulty'])); ?>">
                                                        <?php echo ucfirst(htmlspecialchars($problem['difficulty'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($has_category && !empty($problem['category'])): ?>
                                                    <span class="problem-category">
                                                        <?php echo htmlspecialchars($problem['category']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <p class="card-text mt-3"><?php echo nl2br(htmlspecialchars(substr($problem['description'], 0, 150) . (strlen($problem['description']) > 150 ? '...' : ''))); ?></p>
                                                
                                                <div class="problem-stats">
                                                    <?php if ($has_time_limit && !empty($problem['time_limit'])): ?>
                                                        <span><i class="bi bi-clock"></i> <?php echo $problem['time_limit']; ?>s</span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($has_memory_limit && !empty($problem['memory_limit'])): ?>
                                                        <span><i class="bi bi-hdd"></i> <?php echo $problem['memory_limit']; ?>MB</span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($has_problem_type && !empty($problem['problem_type'])): ?>
                                                        <span><i class="bi bi-code-square"></i> <?php echo ucfirst(htmlspecialchars($problem['problem_type'])); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="mt-3">
                                                    <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#problem<?php echo $problem['id']; ?>">
                                                        View Details
                                                    </button>
                                                    
                                                    <a href="?delete=<?php echo $problem['id']; ?>" class="btn btn-sm btn-danger delete-btn" onclick="return confirm('Are you sure you want to delete this problem?');">
                                                        Delete
                                                    </a>
                                                </div>
                                                
                                                <div class="collapse mt-3" id="problem<?php echo $problem['id']; ?>">
                                                    <div class="card card-body">
                                                        <h6>Input Format:</h6>
                                                        <pre><?php echo htmlspecialchars($problem['input_format']); ?></pre>
                                                        
                                                        <h6>Output Format:</h6>
                                                        <pre><?php echo htmlspecialchars($problem['output_format']); ?></pre>
                                                        
                                                        <h6>Constraints:</h6>
                                                        <pre><?php echo htmlspecialchars($problem['constraints']); ?></pre>
                                                        
                                                        <h6>Sample Input:</h6>
                                                        <pre><?php echo htmlspecialchars($problem['sample_input']); ?></pre>
                                                        
                                                        <h6>Sample Output:</h6>
                                                        <pre><?php echo htmlspecialchars($problem['sample_output']); ?></pre>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Problem Modal -->
    <div class="modal fade" id="addProblemModal" tabindex="-1" aria-labelledby="addProblemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addProblemModalLabel">Add New Problem</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <!-- Tab Navigation -->
                        <ul class="nav nav-tabs" id="problemTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab" aria-controls="basic" aria-selected="true">Basic Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab" aria-controls="details" aria-selected="false">Problem Details</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="test-cases-tab" data-bs-toggle="tab" data-bs-target="#test-cases" type="button" role="tab" aria-controls="test-cases" aria-selected="false">Test Cases</button>
                            </li>
                        </ul>

                        <!-- Tab Content -->
                        <div class="tab-content" id="problemTabsContent">
                            <!-- Basic Info Tab -->
                            <div class="tab-pane fade show active" id="basic" role="tabpanel" aria-labelledby="basic-tab">
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="title" class="form-label">Problem Title</label>
                                            <input type="text" class="form-control" id="title" name="title" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="description" class="form-label">Problem Description</label>
                                            <textarea class="form-control" id="description" name="description" rows="6" required></textarea>
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
                                                        <option value="easy">Easy</option>
                                                        <option value="medium">Medium</option>
                                                        <option value="hard">Hard</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <div class="col-md-<?php echo $has_difficulty ? '6' : '12'; ?>">
                                                <div class="mb-3">
                                                    <label for="points" class="form-label">Points</label>
                                                    <input type="number" class="form-control" id="points" name="points" min="1" max="1000" value="100" required>
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
                                                        <option value="algorithm">Algorithm</option>
                                                        <option value="data_structure">Data Structure</option>
                                                        <option value="string">String Manipulation</option>
                                                        <option value="math">Mathematical</option>
                                                        <option value="dynamic_programming">Dynamic Programming</option>
                                                        <option value="greedy">Greedy</option>
                                                        <option value="graph">Graph Theory</option>
                                                        <option value="sorting">Sorting & Searching</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($has_category): ?>
                                            <div class="col-md-<?php echo $has_problem_type ? '6' : '12'; ?>">
                                                <div class="mb-3">
                                                    <label for="category" class="form-label">Category</label>
                                                    <input type="text" class="form-control" id="category" name="category" list="category-list">
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
                                                    <input type="number" class="form-control" id="time_limit" name="time_limit" min="1" max="10" value="1" required>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($has_memory_limit): ?>
                                            <div class="col-md-<?php echo $has_time_limit ? '6' : '12'; ?>">
                                                <div class="mb-3">
                                                    <label for="memory_limit" class="form-label">Memory Limit (MB)</label>
                                                    <input type="number" class="form-control" id="memory_limit" name="memory_limit" min="16" max="512" value="256" required>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-end mt-3">
                                    <button type="button" class="btn btn-primary" onclick="document.getElementById('details-tab').click()">Next: Problem Details</button>
                                </div>
                            </div>
                            
                            <!-- Problem Details Tab -->
                            <div class="tab-pane fade" id="details" role="tabpanel" aria-labelledby="details-tab">
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="input_format" class="form-label">Input Format</label>
                                            <textarea class="form-control" id="input_format" name="input_format" rows="5" required></textarea>
                                            <div class="form-text">Describe the format of the input that contestants will receive.</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="output_format" class="form-label">Output Format</label>
                                            <textarea class="form-control" id="output_format" name="output_format" rows="5" required></textarea>
                                            <div class="form-text">Describe the expected format of the output that contestants should produce.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="constraints" class="form-label">Constraints</label>
                                    <textarea class="form-control" id="constraints" name="constraints" rows="3" required></textarea>
                                    <div class="form-text">Specify the constraints on input values, time and space complexity, etc.</div>
                                </div>
                                
                                <div class="text-end mt-3">
                                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('basic-tab').click()">Previous: Basic Info</button>
                                    <button type="button" class="btn btn-primary" onclick="document.getElementById('test-cases-tab').click()">Next: Test Cases</button>
                                </div>
                            </div>
                            
                            <!-- Test Cases Tab -->
                            <div class="tab-pane fade" id="test-cases" role="tabpanel" aria-labelledby="test-cases-tab">
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle"></i> 
                                            Test cases are used to verify student solutions. You can create both visible and hidden test cases.
                                            <ul class="mb-0 mt-2">
                                                <li><strong>Visible test cases:</strong> Shown to students as examples.</li>
                                                <li><strong>Hidden test cases:</strong> Run during evaluation but not visible to students.</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <!-- Test Case Creation Interface -->
                                <div id="test-cases-container">
                                    <!-- Test Case 1 (Always visible - Sample) -->
                                    <div class="test-case-item card mb-3">
                                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Test Case #1 (Sample)</h5>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="test_case_visible_1" name="test_case_visible[]" value="1" checked>
                                                <label class="form-check-label" for="test_case_visible_1">Visible to students</label>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="sample_input" class="form-label">Input</label>
                                                        <textarea class="form-control" id="sample_input" name="sample_input" rows="6" required></textarea>
                                                        <div class="form-text">Input data for this test case.</div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="sample_output" class="form-label">Expected Output</label>
                                                        <textarea class="form-control" id="sample_output" name="sample_output" rows="6" required></textarea>
                                                        <div class="form-text">Expected output for the given input.</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Additional test cases will be added here dynamically -->
                                </div>

                                <div class="d-flex justify-content-center mb-3">
                                    <button type="button" class="btn btn-outline-primary" id="add-test-case-btn">
                                        <i class="bi bi-plus-circle"></i> Add Another Test Case
                                    </button>
                                </div>
                                
                                <div class="text-end mt-3">
                                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('details-tab').click()">Previous: Problem Details</button>
                                    <button type="submit" name="add_problem" class="btn btn-success">Add Problem</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add JavaScript for handling test cases dynamically
        document.addEventListener('DOMContentLoaded', function() {
            let testCaseCounter = 1; // Start with 1 because we already have Test Case #1
            
            // Add Test Case button functionality
            document.getElementById('add-test-case-btn').addEventListener('click', function() {
                testCaseCounter++;
                
                const testCaseHTML = `
                    <div class="test-case-item card mb-3">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Test Case #${testCaseCounter}</h5>
                            <div class="d-flex align-items-center">
                                <div class="form-check form-switch me-3">
                                    <input class="form-check-input" type="checkbox" id="test_case_visible_${testCaseCounter}" name="test_case_visible[]" value="${testCaseCounter}">
                                    <label class="form-check-label" for="test_case_visible_${testCaseCounter}">Visible to students</label>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger remove-test-case-btn">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_case_input_${testCaseCounter}" class="form-label">Input</label>
                                        <textarea class="form-control" id="test_case_input_${testCaseCounter}" name="test_case_input[]" rows="6" required></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_case_output_${testCaseCounter}" class="form-label">Expected Output</label>
                                        <textarea class="form-control" id="test_case_output_${testCaseCounter}" name="test_case_output[]" rows="6" required></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                const testCasesContainer = document.getElementById('test-cases-container');
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = testCaseHTML;
                testCasesContainer.appendChild(tempDiv.firstElementChild);
                
                // Add event listener to the new remove button
                const removeButtons = document.querySelectorAll('.remove-test-case-btn');
                removeButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        this.closest('.test-case-item').remove();
                    });
                });
            });
        });
    </script>
</body>
</html> 