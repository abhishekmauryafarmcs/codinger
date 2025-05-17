<?php
require_once 'config/session.php';
require_once 'config/db.php';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $enrollment_number = trim($_POST['enrollment_number']);
    $full_name = trim($_POST['full_name']);
    $college_name = trim($_POST['college_name']);
    $mobile_number = trim($_POST['mobile_number']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validation
    if (empty($enrollment_number)) $errors[] = "Enrollment number is required";
    if (empty($full_name)) $errors[] = "Full name is required";
    if (empty($college_name)) $errors[] = "College name is required";
    if (empty($mobile_number)) $errors[] = "Mobile number is required";
    if (empty($email)) $errors[] = "Email is required";
    if (empty($password)) $errors[] = "Password is required";
    if ($password !== $confirm_password) $errors[] = "Passwords do not match";

    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    // Check if email or enrollment number already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR enrollment_number = ?");
    $stmt->bind_param("ss", $email, $enrollment_number);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $errors[] = "Email or enrollment number already exists";
    }
    $stmt->close();

    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert user
        $stmt = $conn->prepare("INSERT INTO users (enrollment_number, full_name, college_name, mobile_number, email, password) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $enrollment_number, $full_name, $college_name, $mobile_number, $email, $hashed_password);
        
        if ($stmt->execute()) {
            $success = true;
            $user_id = $conn->insert_id;
            
            // Set session variables to log the user in automatically
            $_SESSION['student']['user_id'] = $user_id;
            $_SESSION['student']['role'] = 'student';
            $_SESSION['student']['full_name'] = $full_name;
            $_SESSION['student']['enrollment_number'] = $enrollment_number;
            
            // Regenerate session ID for security
            session_regenerate_id(true);
            
            // Register this session as the only valid one for this user
            registerUserSession($user_id, 'student');
            
            // Redirect to dashboard instead of login page
            header("Location: student/dashboard.php");
            exit();
        } else {
            $errors[] = "Registration failed. Please try again.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="css/auth_style.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body class="auth-page">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark auth-navbar">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="bi bi-code-slash"></i> Codinger</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="login.php">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="register.php">Register</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container auth-container">
        <div class="card auth-card">
            <div class="auth-header">
                <i class="bi bi-person-plus-fill icon"></i>
                <h2>Create Account</h2>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error_item): ?> 
                            <li><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error_item); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php">
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="enrollment_number" name="enrollment_number" placeholder="Enrollment Number" required value="<?php echo isset($_POST['enrollment_number']) ? htmlspecialchars($_POST['enrollment_number']) : ''; ?>">
                    <label for="enrollment_number">Enrollment Number</label>
                    <span class="form-control-icon"><i class="bi bi-person-badge"></i></span>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Full Name" required value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                    <label for="full_name">Full Name</label>
                    <span class="form-control-icon"><i class="bi bi-person-fill"></i></span>
                </div>

                <div class="form-floating mb-3">
                    <select class="form-select" id="college_name" name="college_name" required>
                        <option value="" disabled <?php echo !isset($_POST['college_name']) ? 'selected' : ''; ?>>Select your college</option>
                        <option value="LNCT" <?php echo (isset($_POST['college_name']) && $_POST['college_name'] == 'LNCT') ? 'selected' : ''; ?>>LNCT</option>
                        <option value="LNCTS" <?php echo (isset($_POST['college_name']) && $_POST['college_name'] == 'LNCTS') ? 'selected' : ''; ?>>LNCTS</option>
                        <option value="LNCTE" <?php echo (isset($_POST['college_name']) && $_POST['college_name'] == 'LNCTE') ? 'selected' : ''; ?>>LNCTE</option>
                        <option value="LNCTU" <?php echo (isset($_POST['college_name']) && $_POST['college_name'] == 'LNCTU') ? 'selected' : ''; ?>>LNCTU</option>
                        <option value="JNCT" <?php echo (isset($_POST['college_name']) && $_POST['college_name'] == 'JNCT') ? 'selected' : ''; ?>>JNCT</option>
                    </select>
                    <label for="college_name">College Name</label>
                    <span class="form-control-icon"><i class="bi bi-building"></i></span>
                </div>

                <div class="form-floating mb-3">
                    <input type="tel" class="form-control" id="mobile_number" name="mobile_number" placeholder="Mobile Number" required value="<?php echo isset($_POST['mobile_number']) ? htmlspecialchars($_POST['mobile_number']) : ''; ?>">
                    <label for="mobile_number">Mobile Number</label>
                    <span class="form-control-icon"><i class="bi bi-telephone-fill"></i></span>
                </div>

                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="email" name="email" placeholder="Email Address" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    <label for="email">Email Address</label>
                    <span class="form-control-icon"><i class="bi bi-envelope-fill"></i></span>
                </div>

                <div class="form-floating mb-3">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                    <label for="password">Password</label>
                    <span class="form-control-icon"><i class="bi bi-lock-fill"></i></span>
                </div>

                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                    <label for="confirm_password">Confirm Password</label>
                    <span class="form-control-icon"><i class="bi bi-check-shield-fill"></i></span>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">Register</button>
            </form>

            <div class="text-center mt-3">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </div>
    </div>

    <footer class="auth-footer">
        <p>&copy; <?php echo date("Y"); ?> Codinger. All Rights Reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 