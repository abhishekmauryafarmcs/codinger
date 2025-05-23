<?php
require_once 'config/session.php';
require_once 'config/db.php';  // Add database configuration

// If user is already logged in, redirect to appropriate dashboard
if (isStudentSessionValid()) {
    header("Location: student/dashboard.php");
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
        case 'session_expired':
            $error = 'Your session has expired. Please log in again.';
            break;
        case 'another_login':
            $error = 'You have been logged out because your account was accessed from another location.';
            break;
        default:
            $error = 'An error occurred. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enrollment = isset($_POST['enrollment']) ? trim($_POST['enrollment']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    // Check if enrollment number equals password
    if ($enrollment !== $password) {
        $error = "Your password must be the same as your enrollment number";
        error_log("Student Login Failed - Password doesn't match enrollment number");
    } else {
    try {
        // Check if connection exists
        if (!isset($conn) || $conn->connect_error) {
            throw new Exception("Database connection failed");
        }

            // First check if this enrollment number is in any contest enrollments
            $stmt = $conn->prepare("SELECT contest_id FROM contest_enrollments WHERE enrollment_number = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception("Query preparation failed: " . $conn->error);
            }

            $stmt->bind_param("s", $enrollment);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                // The student is not enrolled in any contest
                $error = "You are not part of any contest. Please contact your administrator.";
                error_log("Student Login Failed - Enrollment not in any contest: $enrollment");
                $stmt->close();
            } else {
                $stmt->close();
                
                // Now check if the user exists
        $stmt = $conn->prepare("SELECT id, password, full_name, enrollment_number FROM users WHERE enrollment_number = ?");
        if (!$stmt) {
            throw new Exception("Query preparation failed: " . $conn->error);
        }

        $stmt->bind_param("s", $enrollment);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
                if ($user) {
                    // Check if the password already matches their enrollment number
                    if (password_verify($enrollment, $user['password'])) {
                        // Password matches enrollment number, login directly
            $_SESSION['student']['user_id'] = $user['id'];
            $_SESSION['student']['role'] = 'student';
            $_SESSION['student']['full_name'] = $user['full_name'];
            $_SESSION['student']['enrollment_number'] = $user['enrollment_number'];

            // Regenerate session ID for security
            session_regenerate_id(true);
            
            // Register this session as the only valid one for this user
            registerUserSession($user['id'], 'student');

            // Log successful login
            error_log("Student Login Success - User ID: {$user['id']}");

            // Redirect to student dashboard
            header("Location: student/dashboard.php");
            exit();
        } else {
                        // Update the user's password to match their enrollment number
                        $hashed_password = password_hash($enrollment, PASSWORD_DEFAULT);
                        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $update_stmt->bind_param("si", $hashed_password, $user['id']);
                        
                        if ($update_stmt->execute()) {
                            // Password updated successfully, now log them in
                            $_SESSION['student']['user_id'] = $user['id'];
                            $_SESSION['student']['role'] = 'student';
                            $_SESSION['student']['full_name'] = $user['full_name'];
                            $_SESSION['student']['enrollment_number'] = $user['enrollment_number'];

                            // Regenerate session ID for security
                            session_regenerate_id(true);
                            
                            // Register this session as the only valid one for this user
                            registerUserSession($user['id'], 'student');

                            // Log successful login with password update
                            error_log("Student Login Success with Password Update - User ID: {$user['id']}");

                            // Redirect to student dashboard
                            header("Location: student/dashboard.php");
                            exit();
                        } else {
                            $error = "Failed to update your account password. Please try again.";
                            error_log("Student Password Update Failed - User ID: {$user['id']}");
                        }
                        $update_stmt->close();
                    }
                } else {
                    // User doesn't exist but is in enrollments - create a new user with the enrollment number
                    $hashed_password = password_hash($enrollment, PASSWORD_DEFAULT);
                    $full_name = "Student " . $enrollment; // Default name
                    $college_name = "Auto Enrolled"; // Default college name
                    $mobile_number = "0000000000"; // Default mobile number
                    $email = $enrollment . "@codinger.student"; // Default email based on enrollment
                    
                    $stmt = $conn->prepare("INSERT INTO users (enrollment_number, full_name, password, college_name, mobile_number, email) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssss", $enrollment, $full_name, $hashed_password, $college_name, $mobile_number, $email);
                    
                    if ($stmt->execute()) {
                        $user_id = $conn->insert_id;
                        
                        // Set session variables
                        $_SESSION['student']['user_id'] = $user_id;
                        $_SESSION['student']['role'] = 'student';
                        $_SESSION['student']['full_name'] = $full_name;
                        $_SESSION['student']['enrollment_number'] = $enrollment;

                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Register this session as the only valid one for this user
                        registerUserSession($user_id, 'student');

                        // Log successful login with new account
                        error_log("New Student Account Created and Login Success - User ID: $user_id");

                        // Redirect to student dashboard
                        header("Location: student/dashboard.php");
                        exit();
                    } else {
                        $error = "Failed to create your account. Please try again.";
                        error_log("Student Account Creation Failed - Enrollment: $enrollment");
                    }
        }
        $stmt->close();
            }
    } catch (Exception $e) {
        error_log("Login Error: " . $e->getMessage());
        $error = 'An error occurred. Please try again.';
        }
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="css/auth_style.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        /* Add navbar styles */
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
        .session-expired {
            animation: fadeInOut 0.5s ease-in-out;
        }
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(-20px); }
            100% { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="auth-page">
    <nav class="navbar navbar-expand-lg navbar-dark auth-navbar">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="images/LNCT-Logo.png" alt="LNCT Logo">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="login.php">Student Login</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container auth-container">
        <div class="card auth-card">
            <div class="auth-header">
                <i class="bi bi-box-arrow-in-right icon"></i>
                <h2>Student Login</h2>
            </div>
            
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
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="enrollment" name="enrollment" placeholder="Enrollment Number" required value="<?php echo isset($_POST['enrollment']) ? htmlspecialchars($_POST['enrollment']) : ''; ?>">
                    <label for="enrollment">Enrollment Number</label>
                    <span class="form-control-icon"><i class="bi bi-person-badge"></i></span>
                </div>

                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                    <label for="password">Password</label>
                    <span class="form-control-icon"><i class="bi bi-lock"></i></span>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">Login</button>
            </form>

            <!-- Registration removed as per requirements -->
        </div>
    </div>

    <footer class="text-center py-4 bg-white mt-4 border-top">
        <img src="images/lnct-logo-footer-300x106-1.png" alt="LNCT Footer Logo" style="height:40px;">
        <div class="mt-2 text-muted" style="font-size:0.95rem;">&copy; <?php echo date('Y'); ?> LNCT Group of Colleges. All rights reserved.</div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 