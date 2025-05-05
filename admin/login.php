<?php
require_once '../config/session.php';
require_once '../config/db.php';

// If admin is already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    header("Location: index.php");
    exit();
}

$error = '';
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'invalid_credentials':
            $error = 'Invalid username or password.';
            break;
        default:
            $error = 'An error occurred. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    try {
        // Check if connection exists
        if (!isset($conn) || $conn->connect_error) {
            throw new Exception("Database connection failed");
        }

        // Debug log
        error_log("Admin Login Attempt - Username: " . $username);

        // First, let's check if the admin exists and get their details
        $stmt = $conn->prepare("SELECT * FROM admins WHERE username = ?");
        if (!$stmt) {
            throw new Exception("Query preparation failed: " . $conn->error);
        }

        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin = $result->fetch_assoc();
        
        // Debug log
        if ($admin) {
            error_log("Admin found with ID: " . $admin['id']);
            error_log("Stored password hash: " . $admin['password']);
            error_log("Attempting to verify password...");
            
            $verify_result = password_verify($password, $admin['password']);
            error_log("Password verification result: " . ($verify_result ? "true" : "false"));
        } else {
            error_log("No admin found with username: " . $username);
        }
        
        if ($admin && password_verify($password, $admin['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['full_name'] = $admin['full_name'];
            
            // Regenerate session ID for security
            session_regenerate_id(true);
            
            // Log successful login
            error_log("Admin Login Success - Admin ID: {$admin['id']}");
            
            header("Location: index.php");
            exit();
        } else {
            if ($admin) {
                error_log("Password verification failed for admin: " . $username);
                error_log("Provided password: " . substr($password, 0, 3) . "***");
            }
            $error = 'Invalid username or password';
            error_log("Admin Login Failed - Username: $username");
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Login Error: " . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

// Let's verify the admin table and account
try {
    $debug_stmt = $conn->prepare("SELECT COUNT(*) as count FROM admins");
    $debug_stmt->execute();
    $debug_result = $debug_stmt->get_result();
    $admin_count = $debug_result->fetch_assoc()['count'];
    error_log("Total admin accounts in database: " . $admin_count);
    $debug_stmt->close();
} catch (Exception $e) {
    error_log("Debug Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .error-message {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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
                        <a class="nav-link" href="../index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../login.php">Student Login</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="login-container">
            <h2 class="text-center mb-4">Admin Login</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-danger error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>

            <div class="text-center mt-3">
                <small class="text-muted">Default credentials: admin / admin123</small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 