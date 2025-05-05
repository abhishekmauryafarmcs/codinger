<?php
require_once '../config/db.php';

try {
    // First, verify the admins table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'admins'");
    if ($table_check->num_rows === 0) {
        // Create admins table if it doesn't exist
        $sql = "CREATE TABLE IF NOT EXISTS admins (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->query($sql);
        echo "Admins table created.<br>";
    }

    // Check if default admin exists
    $check = $conn->prepare("SELECT id, password FROM admins WHERE username = 'admin'");
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows === 0) {
        // Create default admin account
        $username = "admin";
        $password = "admin123";
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $full_name = "System Administrator";
        $email = "admin@codinger.com";
        
        // Debug information
        echo "Creating new admin account...<br>";
        echo "Username: " . $username . "<br>";
        echo "Password (before hashing): " . $password . "<br>";
        echo "Generated hash: " . $hashed_password . "<br>";
        
        $stmt = $conn->prepare("INSERT INTO admins (username, password, full_name, email) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $hashed_password, $full_name, $email);
        
        if ($stmt->execute()) {
            echo "<div style='color: green; margin: 10px 0;'>Default admin account created successfully!</div>";
            
            // Verify the account was created properly
            $verify = $conn->prepare("SELECT * FROM admins WHERE username = ?");
            $verify->bind_param("s", $username);
            $verify->execute();
            $admin = $verify->get_result()->fetch_assoc();
            
            if ($admin) {
                echo "<div style='background: #f0f0f0; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
                echo "<strong>Account Verification:</strong><br>";
                echo "ID: " . $admin['id'] . "<br>";
                echo "Username: " . $admin['username'] . "<br>";
                echo "Full Name: " . $admin['full_name'] . "<br>";
                echo "Email: " . $admin['email'] . "<br>";
                echo "Password Hash Length: " . strlen($admin['password']) . " characters<br>";
                echo "</div>";
                
                // Verify password works
                if (password_verify($password, $admin['password'])) {
                    echo "<div style='color: green;'>Password verification successful!</div>";
                } else {
                    echo "<div style='color: red;'>Warning: Password verification failed!</div>";
                }
            }
            
            echo "<div style='margin-top: 20px;'>";
            echo "<strong>Login Credentials:</strong><br>";
            echo "Username: <span style='font-family: monospace;'>admin</span><br>";
            echo "Password: <span style='font-family: monospace;'>admin123</span>";
            echo "</div>";
            
            echo "<div style='margin-top: 20px;'>";
            echo "<a href='login.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Login Page</a>";
            echo "</div>";
        } else {
            echo "<div style='color: red;'>Error creating admin account: " . $stmt->error . "</div>";
        }
        $stmt->close();
    } else {
        $admin = $result->fetch_assoc();
        echo "<div style='background: #f0f0f0; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "Default admin account already exists.<br>";
        echo "Account ID: " . $admin['id'] . "<br>";
        echo "Password Hash Length: " . strlen($admin['password']) . " characters<br>";
        echo "</div>";
        
        echo "<div style='margin-top: 20px;'>";
        echo "<strong>Login Credentials:</strong><br>";
        echo "Username: <span style='font-family: monospace;'>admin</span><br>";
        echo "Password: <span style='font-family: monospace;'>admin123</span>";
        echo "</div>";
        
        echo "<div style='margin-top: 20px;'>";
        echo "<a href='login.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Login Page</a>";
        echo "</div>";
    }
    $check->close();
} catch (Exception $e) {
    echo "<div style='color: red;'>Error: " . $e->getMessage() . "</div>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Default Admin</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .monospace {
            font-family: monospace;
            background: #f5f5f5;
            padding: 2px 5px;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Content will be inserted here by PHP -->
    </div>
</body>
</html> 