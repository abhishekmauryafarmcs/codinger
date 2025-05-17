<?php
// Direct login page without .htaccess restrictions
require_once '../config/db.php';
require_once '../config/session.php';

// Clear any existing admin session data
$_SESSION['admin'] = array();

// Process login attempt
$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST["username"] ?? "";
    $password = $_POST["password"] ?? "";
    
    try {
        if (!isset($conn) || $conn->connect_error) {
            throw new Exception("Database connection failed");
        }

        $stmt = $conn->prepare("SELECT * FROM admins WHERE username = ?");
        if (!$stmt) {
            throw new Exception("Query preparation failed: " . $conn->error);
        }

        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin = $result->fetch_assoc();
        
        if ($admin && password_verify($password, $admin['password'])) {
            // Set session variables
            $_SESSION['admin']['user_id'] = $admin['id'];
            $_SESSION['admin']['role'] = 'admin';
            $_SESSION['admin']['full_name'] = $admin['full_name'];
            
            // Regenerate session ID
            session_regenerate_id(true);
            
            // Register this session in the database
            registerUserSession($admin['id'], 'admin');
            
            // Redirect to admin dashboard
            header("Location: index.php");
            exit();
        } else {
            $error = "Invalid username or password";
        }
        $stmt->close();
    } catch (Exception $e) {
        $error = "Login Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Admin Login - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            background-color: #f5f5f5;
            padding-top: 40px;
        }
        .login-container {
            max-width: 400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .alert { margin-bottom: 20px; }
        h1 { margin-bottom: 24px; color: #333; }
        .form-label { font-weight: 500; }
        .debug-info {
            margin-top: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <h1 class="text-center">Emergency Admin Login</h1>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>
            </form>
            
            <div class="text-center mt-3">
                <small class="text-muted">Default credentials: admin / admin123</small>
            </div>
            
            <div class="debug-info">
                <strong>Session Debugging Info:</strong><br>
                Session ID: <?php echo session_id(); ?><br>
                PHP Version: <?php echo PHP_VERSION; ?><br>
                <a href="session_debug.php" target="_blank">View Full Session Debug</a>
            </div>
        </div>
    </div>
</body>
</html> 