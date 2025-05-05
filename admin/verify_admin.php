<?php
require_once '../config/db.php';
require_once '../config/session.php';

// Check if user is logged in and is an admin
if (!isAdminSessionValid()) {
    header("Location: login.php?error=session_expired");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Admin Data - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container { margin-top: 50px; }
        .data-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .code {
            font-family: monospace;
            background: #e9ecef;
            padding: 2px 4px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Admin Data Verification</h2>
        
        <div class="data-box">
            <h4>Current Admin Records:</h4>
            <?php
            $stmt = $conn->prepare("SELECT * FROM admins");
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                echo "<table class='table'>";
                echo "<thead><tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Password Hash</th></tr></thead>";
                echo "<tbody>";
                while ($row = $result->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                    echo "<td><span class='code'>" . substr(htmlspecialchars($row['password']), 0, 20) . "...</span></td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            } else {
                echo "<div class='alert alert-warning'>No admin records found!</div>";
            }
            ?>
        </div>

        <div class="data-box">
            <h4>Create Default Admin Account:</h4>
            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
                $username = "admin";
                $password = "admin123"; // Default password
                $full_name = "System Administrator";
                $email = "admin@codinger.com";
                
                // Check if admin already exists
                $check = $conn->prepare("SELECT id FROM admins WHERE username = ? OR email = ?");
                $check->bind_param("ss", $username, $email);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    echo "<div class='alert alert-warning'>Admin account already exists!</div>";
                } else {
                    // Create new admin account
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $insert = $conn->prepare("INSERT INTO admins (username, full_name, email, password) VALUES (?, ?, ?, ?)");
                    $insert->bind_param("ssss", $username, $full_name, $email, $hashed_password);
                    
                    if ($insert->execute()) {
                        echo "<div class='alert alert-success'>";
                        echo "Default admin account created successfully!<br><br>";
                        echo "<strong>Login Credentials:</strong><br>";
                        echo "Username: <span class='code'>admin</span><br>";
                        echo "Password: <span class='code'>admin123</span>";
                        echo "</div>";
                    } else {
                        echo "<div class='alert alert-danger'>Failed to create admin account!</div>";
                    }
                }
            }
            ?>
            <form method="POST">
                <button type="submit" name="create_admin" class="btn btn-primary">Create Default Admin Account</button>
            </form>
        </div>

        <div class="mt-3">
            <a href="login.php" class="btn btn-secondary">Back to Admin Login</a>
        </div>
    </div>
</body>
</html> 