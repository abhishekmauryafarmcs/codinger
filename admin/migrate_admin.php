<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Migration - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        .migration-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .migration-result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
        }
        .migration-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        .migration-error {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="../index.php">Codinger</a>
        </div>
    </nav>

    <div class="container">
        <div class="migration-container">
            <h2 class="mb-4">Admin Data Migration</h2>
            <div class="migration-result">
            <?php
            require_once '../config/db.php';

            // Start transaction
            $conn->begin_transaction();

            try {
                // First, check if there are any admin users
                $check = $conn->prepare("SELECT COUNT(*) as admin_count FROM users WHERE role = 'admin'");
                $check->execute();
                $result = $check->get_result();
                $count = $result->fetch_assoc()['admin_count'];

                if ($count == 0) {
                    echo '<div class="migration-error">';
                    echo "<strong>No admin data found!</strong><br><br>";
                    echo "There are no admin users in the users table to migrate.";
                    echo '</div>';
                    exit();
                }

                // Get all admin users first
                $get_admins = $conn->prepare("SELECT id, full_name, email, password FROM users WHERE role = 'admin'");
                $get_admins->execute();
                $result = $get_admins->get_result();
                
                echo '<div class="migration-success">';
                echo "<strong>Found admin data:</strong><br><br>";
                
                $admin_count = 0;
                $admin_mappings = array(); // Store old ID to new ID mappings
                
                // First step: Insert all admins into new table
                while ($admin = $result->fetch_assoc()) {
                    // Generate username from email (part before @)
                    $username = strtolower(explode('@', $admin['email'])[0]);
                    
                    echo "Processing admin: " . htmlspecialchars($admin['full_name']) . "<br>";
                    echo "Email: " . htmlspecialchars($admin['email']) . "<br>";
                    echo "Generated username: " . htmlspecialchars($username) . "<br><br>";
                    
                    // Insert into admins table
                    $insert = $conn->prepare("INSERT INTO admins (username, full_name, email, password) VALUES (?, ?, ?, ?)");
                    $insert->bind_param("ssss", $username, $admin['full_name'], $admin['email'], $admin['password']);
                    $insert->execute();
                    
                    // Store the mapping of old user ID to new admin ID
                    $admin_mappings[$admin['id']] = $conn->insert_id;
                    $admin_count++;
                }

                // Second step: Add created_by columns without foreign key constraints
                $conn->query("ALTER TABLE contests DROP FOREIGN KEY IF EXISTS contests_ibfk_1");
                $conn->query("ALTER TABLE problems DROP FOREIGN KEY IF EXISTS problems_ibfk_2");
                
                $conn->query("ALTER TABLE contests ADD COLUMN IF NOT EXISTS created_by INT(11)");
                $conn->query("ALTER TABLE problems ADD COLUMN IF NOT EXISTS created_by INT(11)");

                // Third step: Update the created_by values
                foreach ($admin_mappings as $old_id => $new_id) {
                    // Update contests
                    $update_contests = $conn->prepare("UPDATE contests SET created_by = ? WHERE id IN (SELECT contest_id FROM problems WHERE user_id = ?)");
                    $update_contests->bind_param("ii", $new_id, $old_id);
                    $update_contests->execute();
                    
                    // Update problems
                    $update_problems = $conn->prepare("UPDATE problems SET created_by = ? WHERE user_id = ?");
                    $update_problems->bind_param("ii", $new_id, $old_id);
                    $update_problems->execute();
                }

                // Fourth step: Add foreign key constraints
                $conn->query("ALTER TABLE contests ADD CONSTRAINT fk_contest_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL");
                $conn->query("ALTER TABLE problems ADD CONSTRAINT fk_problem_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL");

                // Finally: Delete admin users from users table
                $delete_admins = $conn->prepare("DELETE FROM users WHERE role = 'admin'");
                $delete_admins->execute();
                
                // Commit transaction
                $conn->commit();
                
                echo "<strong>Migration completed successfully!</strong><br><br>";
                echo "✓ Migrated $admin_count admin(s) to the new table<br>";
                echo "✓ Added created_by columns to contests and problems tables<br>";
                echo "✓ Updated related contests and problems<br>";
                echo "✓ Added foreign key constraints<br>";
                echo "✓ Removed admin entries from users table<br><br>";
                
                echo "<strong>Admin Login Details:</strong><br>";
                echo "- Use your generated username (shown above)<br>";
                echo "- Use your existing password<br>";
                echo '</div>';
                
                echo '<div class="text-center mt-4">';
                echo '<a href="login.php" class="btn btn-primary">Go to Admin Login</a>';
                echo '</div>';
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                
                echo '<div class="migration-error">';
                echo "<strong>Error during migration:</strong><br>";
                echo htmlspecialchars($e->getMessage()) . "<br><br>";
                echo "No changes were made to the database.";
                echo '</div>';
                
                echo '<div class="text-center mt-4">';
                echo '<a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>';
                echo '</div>';
            }
            ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 