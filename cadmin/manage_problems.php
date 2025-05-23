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

// Fetch ALL problems, not just unassigned ones
$stmt = $conn->prepare("SELECT * FROM problems " . $order_by);
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
    if ($points <= 0) $errors[] = "Points must be greater than 0";
    if ($has_time_limit && $time_limit <= 0) $errors[] = "Time limit must be greater than 0";
    if ($has_memory_limit && $memory_limit <= 0) $errors[] = "Memory limit must be greater than 0";

    if (empty($errors)) {
        // Check if category already exists, if not add it
        if ($has_category && !empty($category) && !in_array($category, $categories)) {
            $categories[] = $category;
        }

        // Prepare SQL based on which columns exist
        $columns = "title, description, input_format, output_format, constraints, points";
        $placeholders = "?, ?, ?, ?, ?, ?";
        $types = "sssssi";
        $params = [
            $title, $description, $input_format, $output_format, 
            $constraints, $points
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
        $params[] = $_SESSION['admin']['user_id'];

        $sql = "INSERT INTO problems ($columns) VALUES ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $problem_id = $conn->insert_id;
            $success = "Problem added successfully";
            
            // If test_cases table exists, store test cases
            if ($has_test_cases_table) {
                // Add test cases if provided
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
            $stmt = $conn->prepare("SELECT * FROM problems " . $order_by);
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
$filter_problem_type = '';
$filter_difficulty = '';

// Initialize problems array
$stmt = $conn->prepare("SELECT * FROM problems " . $order_by);
$stmt->execute();
$problems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle filtering
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Debug information
    $debug = [];
    $debug[] = "Request Method: " . $_SERVER['REQUEST_METHOD'];
    $debug[] = "GET parameters: " . print_r($_GET, true);
    
    // Only proceed with filtering if at least one filter parameter is set
    if (isset($_GET['search_term']) || isset($_GET['filter_problem_type']) || isset($_GET['filter_difficulty'])) {
        $debug[] = "Filter parameters detected";
        
        $search_term = trim($_GET['search_term'] ?? '');
        $filter_problem_type = $has_problem_type ? trim($_GET['filter_problem_type'] ?? '') : '';
        $filter_difficulty = $has_difficulty ? trim($_GET['filter_difficulty'] ?? '') : '';
        
        $debug[] = "search_term: " . $search_term;
        $debug[] = "filter_problem_type: " . $filter_problem_type;
        $debug[] = "filter_difficulty: " . $filter_difficulty;
        $debug[] = "has_problem_type: " . ($has_problem_type ? 'true' : 'false');
        $debug[] = "has_difficulty: " . ($has_difficulty ? 'true' : 'false');
        
        // Only apply filters if at least one filter has a value
        if (!empty($search_term) || !empty($filter_problem_type) || !empty($filter_difficulty)) {
            $debug[] = "At least one filter has a value";
            
            // Build query with filters
            $query = "SELECT * FROM problems WHERE 1=1";
            $params = [];
            $types = "";
            
            if (!empty($search_term)) {
                $query .= " AND (title LIKE ? OR description LIKE ?)";
                $search_param = "%" . $search_term . "%";
                $params[] = $search_param;
                $params[] = $search_param;
                $types .= "ss";
            }
            
            if ($has_problem_type && !empty($filter_problem_type)) {
                $query .= " AND problem_type = ?";
                $params[] = $filter_problem_type;
                $types .= "s";
            }
            
            if ($has_difficulty && !empty($filter_difficulty)) {
                $query .= " AND difficulty = ?";
                $params[] = $filter_difficulty;
                $types .= "s";
            }
            
            $query .= " " . $order_by;
            $debug[] = "Query: " . $query;
            $debug[] = "Parameters: " . print_r($params, true);
            
            try {
                $stmt = $conn->prepare($query);
                if (!empty($params)) {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $results = $stmt->get_result();
                $num_rows = $results->num_rows;
                $debug[] = "Query executed successfully. Results: " . $num_rows;
                
                $problems = $results->fetch_all(MYSQLI_ASSOC);
            } catch (Exception $e) {
                $errors[] = "Error applying filters: " . $e->getMessage();
                $debug[] = "Exception: " . $e->getMessage();
                // Log the error
                error_log("Filter error: " . $e->getMessage());
            }
        } else {
            $debug[] = "No filter values provided, showing all problems";
        }
    } else {
        $debug[] = "No filter parameters set";
    }
    
    // Save debug info to session for troubleshooting
    $_SESSION['filter_debug'] = $debug;
}

// Handle problem deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_problem'])) {
    $problem_id = (int)$_POST['delete_problem'];
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Delete test cases first (if test_cases table exists)
        if ($has_test_cases_table) {
            $stmt = $conn->prepare("DELETE FROM test_cases WHERE problem_id = ?");
            $stmt->bind_param("i", $problem_id);
            $stmt->execute();
        }
        
        // Delete the problem
        $stmt = $conn->prepare("DELETE FROM problems WHERE id = ?");
        $stmt->bind_param("i", $problem_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $conn->commit();
            echo "success";
            exit;
        } else {
            throw new Exception("Problem not found");
        }
    } catch (Exception $e) {
        $conn->rollback();
        echo "error: " . $e->getMessage();
        exit;
    }
}

// Count total problems in bank
$total_problems = count($problems);

// Set success message if problem was deleted
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $success = "Problem deleted successfully";
}

// Fetch problem types for dropdown
$problem_types = [];
if ($has_problem_type) {
    try {
        $stmt = $conn->prepare("SELECT DISTINCT problem_type FROM problems WHERE problem_type IS NOT NULL");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $problem_types[] = $row['problem_type'];
        }
    } catch (Exception $e) {
        // Silently handle the error
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Problems - LNCT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
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
        .contest-badge {
            display: inline-block;
            margin-top: 8px;
            background-color: #5e17eb;
            color: white;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.8rem;
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

                        <?php if (isset($_SESSION['filter_debug']) && isset($_GET['debug'])): ?>
                        <div class="alert alert-secondary">
                            <h5>Filter Debug Information</h5>
                            <pre><?php echo htmlspecialchars(implode("\n", $_SESSION['filter_debug'])); ?></pre>
                        </div>
                        <?php endif; ?>

                        <!-- Show debugging information -->
                        <?php if (isset($_GET['debug'])): ?>
                        <div class="alert alert-secondary mt-3 mb-3">
                            <h5>Filter Debug Information</h5>
                            <p><strong>Current URL:</strong> <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?></p>
                            <?php if (isset($_SESSION['filter_debug'])): ?>
                                <pre style="max-height: 300px; overflow-y: auto;"><?php echo htmlspecialchars(implode("\n", $_SESSION['filter_debug'])); ?></pre>
                            <?php else: ?>
                                <p>No filter debug information available.</p>
                            <?php endif; ?>
                            
                            <h6 class="mt-3">Database Information:</h6>
                            <ul>
                                <li>Category column exists: <?php echo $has_category ? 'Yes' : 'No'; ?></li>
                                <li>Difficulty column exists: <?php echo $has_difficulty ? 'Yes' : 'No'; ?></li>
                                <li>Created at column exists: <?php echo $has_created_at ? 'Yes' : 'No'; ?></li>
                                <li>Problem type column exists: <?php echo $has_problem_type ? 'Yes' : 'No'; ?></li>
                                <li>Time limit column exists: <?php echo $has_time_limit ? 'Yes' : 'No'; ?></li>
                                <li>Memory limit column exists: <?php echo $has_memory_limit ? 'Yes' : 'No'; ?></li>
                                <li>Test cases table exists: <?php echo $has_test_cases_table ? 'Yes' : 'No'; ?></li>
                            </ul>
                            
                            <h6>Categories in database:</h6>
                            <?php if (!empty($categories)): ?>
                                <ul>
                                    <?php foreach ($categories as $cat): ?>
                                        <li><?php echo htmlspecialchars($cat); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p>No categories found.</p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Search & Filter Box -->
                        <div class="search-box mb-4">
                            <form method="get" id="filter-form">
                                <div class="row g-3">
                                    <div class="col-md-<?php echo ($has_problem_type || $has_difficulty) ? '5' : '10'; ?>">
                                        <label for="search_term" class="form-label">Search</label>
                                        <input type="text" class="form-control" id="search_term" name="search_term" 
                                               placeholder="Search by title or description" 
                                               value="<?php echo htmlspecialchars($search_term); ?>">
                                    </div>
                                    
                                    <?php if ($has_problem_type): ?>
                                    <div class="col-md-3">
                                        <label for="filter_problem_type" class="form-label">Problem Type</label>
                                        <select class="form-select" id="filter_problem_type" name="filter_problem_type">
                                            <option value="">All Problem Types</option>
                                            <option value="algorithm" <?php echo $filter_problem_type === 'algorithm' ? 'selected' : ''; ?>>Algorithm</option>
                                            <option value="data_structure" <?php echo $filter_problem_type === 'data_structure' ? 'selected' : ''; ?>>Data Structure</option>
                                            <option value="array" <?php echo $filter_problem_type === 'array' ? 'selected' : ''; ?>>Array</option>
                                            <option value="string" <?php echo $filter_problem_type === 'string' ? 'selected' : ''; ?>>String Manipulation</option>
                                            <option value="function" <?php echo $filter_problem_type === 'function' ? 'selected' : ''; ?>>Function</option>
                                            <option value="math" <?php echo $filter_problem_type === 'math' ? 'selected' : ''; ?>>Mathematical</option>
                                            <option value="logic_based" <?php echo $filter_problem_type === 'logic_based' ? 'selected' : ''; ?>>Logic Based</option>
                                            <option value="dynamic_programming" <?php echo $filter_problem_type === 'dynamic_programming' ? 'selected' : ''; ?>>Dynamic Programming</option>
                                            <option value="greedy" <?php echo $filter_problem_type === 'greedy' ? 'selected' : ''; ?>>Greedy</option>
                                            <option value="graph" <?php echo $filter_problem_type === 'graph' ? 'selected' : ''; ?>>Graph Theory</option>
                                            <option value="sorting" <?php echo $filter_problem_type === 'sorting' ? 'selected' : ''; ?>>Sorting & Searching</option>
                                            <option value="other" <?php echo $filter_problem_type === 'other' ? 'selected' : ''; ?>>Other</option>
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
                                    
                                    <div class="col-md-2 d-flex align-items-end">
                                        <div class="d-flex gap-2 w-100">
                                            <button type="submit" class="btn btn-primary flex-grow-1">
                                                <i class="bi bi-filter"></i> Filter
                                            </button>
                                            <?php if (!empty($search_term) || !empty($filter_problem_type) || !empty($filter_difficulty)): ?>
                                            <a href="manage_problems.php" class="btn btn-outline-secondary">
                                                <i class="bi bi-x-circle"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </form>
                            
                            <?php if (!empty($search_term) || !empty($filter_problem_type) || !empty($filter_difficulty)): ?>
                            <div class="mt-3">
                                <p class="mb-1"><strong>Active filters:</strong></p>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if (!empty($search_term)): ?>
                                        <span class="badge bg-info">Search: <?php echo htmlspecialchars($search_term); ?></span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_problem_type)): ?>
                                        <span class="badge bg-info">Problem Type: <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($filter_problem_type))); ?></span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_difficulty)): ?>
                                        <span class="badge bg-info">Difficulty: <?php echo htmlspecialchars($filter_difficulty); ?></span>
                                    <?php endif; ?>
                                    
                                    <span class="badge bg-secondary">Found: <?php echo count($problems); ?> problems</span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Display filter results or no results message -->
                        <?php if (empty($problems)): ?>
                        <div class="alert alert-info">
                            <p>No problems found matching your criteria.</p>
                            <?php if (!empty($search_term) || !empty($filter_problem_type) || !empty($filter_difficulty)): ?>
                                <a href="manage_problems.php" class="btn btn-sm btn-outline-primary">Clear Filters</a>
                            <?php endif; ?>
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
                                        
                                        <p class="card-text mt-3">
                                            <?php echo nl2br(htmlspecialchars(substr($problem['description'], 0, 150) . (strlen($problem['description']) > 150 ? '...' : ''))); ?>
                                        </p>
                                        
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

                                        <!-- Add contest information outside the problem-stats div -->
                                        <?php if ($problem['contest_id']): 
                                            $contest_stmt = $conn->prepare("SELECT title FROM contests WHERE id = ?");
                                            $contest_stmt->bind_param("i", $problem['contest_id']);
                                            $contest_stmt->execute();
                                            $contest_result = $contest_stmt->get_result();
                                            if ($contest_data = $contest_result->fetch_assoc()): ?>
                                                <div class="contest-badge mt-2">
                                                    <i class="bi bi-trophy"></i> Assigned to: <?php echo htmlspecialchars($contest_data['title']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="contest-badge mt-2" style="background-color: #28a745;">
                                                <i class="bi bi-check-circle"></i> Available
                                            </div>
                                        <?php endif; ?>

                                        <div class="mt-3">
                                            <a href="edit_problem.php?id=<?php echo $problem['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger delete-problem" data-id="<?php echo $problem['id']; ?>">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
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
                                                        <option value="array">Array</option>
                                                        <option value="string">String Manipulation</option>
                                                        <option value="function">Function</option>
                                                        <option value="math">Mathematical</option>
                                                        <option value="logic_based">Logic Based</option>
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
                                    <!-- Test cases will be added here dynamically -->
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
            let testCaseCounter = 0; // Start with 0 because we will increment before using
            
            // Add Test Case button functionality
            document.getElementById('add-test-case-btn')?.addEventListener('click', function() {
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

            // Handle problem deletion
            document.querySelectorAll('.delete-problem').forEach(button => {
                button.addEventListener('click', function() {
                    const problemId = this.dataset.id;
                    if (confirm('Are you sure you want to delete this problem? This action cannot be undone.')) {
                        // Create form data
                        const formData = new FormData();
                        formData.append('delete_problem', problemId);
                        
                        // Send AJAX request
                        fetch('manage_problems.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.text())
                        .then(result => {
                            if (result.includes('success')) {
                                // Remove the problem card from the UI
                                this.closest('.col-md-6').remove();
                                
                                // Show success message
                                const alert = document.createElement('div');
                                alert.className = 'alert alert-success alert-dismissible fade show';
                                alert.innerHTML = `
                                    Problem deleted successfully
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                `;
                                document.querySelector('.container').insertBefore(alert, document.querySelector('.search-box'));
                                
                                // If no problems left, show empty state
                                if (document.querySelectorAll('.problem-card').length === 0) {
                                    location.reload();
                                }
                            } else {
                                alert('Error deleting problem. Please try again.');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error deleting problem. Please try again.');
                        });
                    }
                });
            });
            
            // Handle filter form submission with validation
            const filterForm = document.getElementById('filter-form');
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    const searchTerm = document.getElementById('search_term').value.trim();
                    const filterProblemType = document.getElementById('filter_problem_type')?.value || '';
                    const filterDifficulty = document.getElementById('filter_difficulty')?.value || '';
                    
                    // Check if we have at least one filter value
                    if (searchTerm === '' && filterProblemType === '' && filterDifficulty === '') {
                        // If no filters, just show all problems
                        window.location.href = 'manage_problems.php';
                        e.preventDefault();
                    }
                    // Otherwise, let the form submit normally
                });
            }
        });
    </script>
</body>
</html> 