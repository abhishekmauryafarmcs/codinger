<?php
require_once 'config/session.php';
require_once 'config/db.php';  // Add database configuration

// If user is already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/index.php");
    } else {
        header("Location: student/dashboard.php");
    }
    exit();
}

$error = '';
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'invalid_credentials':
            $error = 'Invalid enrollment number or password.';
            break;
        case 'not_found':
            $error = 'Student not found.';
            break;
        default:
            $error = 'An error occurred. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enrollment = trim($_POST['enrollment']);
    $password = $_POST['password'];

    try {
        // Check if connection exists
        if (!isset($conn) || $conn->connect_error) {
            throw new Exception("Database connection failed");
        }

        $stmt = $conn->prepare("SELECT id, password, full_name, enrollment_number FROM users WHERE enrollment_number = ?");
        if (!$stmt) {
            throw new Exception("Query preparation failed: " . $conn->error);
        }

        $stmt->bind_param("s", $enrollment);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user && password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = 'student';
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['enrollment_number'] = $user['enrollment_number'];

            // Regenerate session ID for security
            session_regenerate_id(true);

            // Log successful login
            error_log("Student Login Success - User ID: {$user['id']}");

            // Redirect to student dashboard
            header("Location: student/dashboard.php");
            exit();
        } else {
            $error = "Invalid enrollment number or password";
            error_log("Student Login Failed - Enrollment: $enrollment");
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Login Error: " . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        .session-expired {
            animation: fadeInOut 0.5s ease-in-out;
        }
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(-20px); }
            100% { opacity: 1; transform: translateY(0); }
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
                        <a class="nav-link active" href="login.php">Student Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="admin/login.php">Admin Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="register.php">Register</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="form-container">
            <h2>Student Login</h2>
            
            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">
                    Registration successful! Please login with your credentials.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger session-expired" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="mb-3">
                    <label for="enrollment" class="form-label">Enrollment Number</label>
                    <input type="text" class="form-control" id="enrollment" name="enrollment" required>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>

            <div class="text-center mt-3">
                Don't have an account? <a href="register.php">Register here</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 