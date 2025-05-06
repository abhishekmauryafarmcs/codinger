<?php
// Create default admin account script
echo "Creating default admin account...\n";

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'codinger_db';

// Default admin credentials
$admin_username = 'admin';
$admin_fullname = 'Administrator';
$admin_email = 'admin@codinger.com';
$admin_password = 'admin123'; // You should change this after first login

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}

echo "Connected to database successfully!\n";

// Check if admin already exists
$stmt = $conn->prepare("SELECT * FROM admins WHERE username = ? OR email = ?");
$stmt->bind_param("ss", $admin_username, $admin_email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "Admin account already exists. No changes made.\n";
} else {
    // Hash the password for security
    $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
    
    // Create the admin account
    $stmt = $conn->prepare("INSERT INTO admins (username, full_name, email, password) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $admin_username, $admin_fullname, $admin_email, $hashed_password);
    
    if ($stmt->execute()) {
        echo "Default admin account created successfully!\n";
        echo "Username: $admin_username\n";
        echo "Password: $admin_password\n";
        echo "Email: $admin_email\n";
        echo "IMPORTANT: Change this password after your first login for security reasons.\n";
    } else {
        echo "Error creating admin account: " . $stmt->error . "\n";
    }
}

// Close connection
$stmt->close();
$conn->close();

echo "\nDone!\n";
?> 