<?php
/**
 * Quick Fix: Update Admin Password
 * 
 * Access this file via browser to fix the admin password
 * URL: https://yourdomain.com/fix-admin-password.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/helpers/LicenseHelper.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Admin Password</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Fix Admin Password</h1>
        
        <?php
        $password = 'admin123';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Check system activation
            $licenseHelper = new LicenseHelper($db);
            if (!$licenseHelper->isSystemActivated()) {
                echo '<div class="error">';
                echo '<strong>✗ System Not Activated</strong><br>';
                echo 'This system requires activation before admin accounts can be created.<br><br>';
                echo '<strong>Please run <code>setup.php</code> first to activate the system.</strong><br>';
                echo '<a href="setup.php" style="display: inline-block; margin-top: 10px; padding: 10px 20px; background: #4a90e2; color: white; text-decoration: none; border-radius: 5px;">Go to Setup Page</a>';
                echo '</div>';
                exit;
            }
            
            // Check if admin user exists
            $checkQuery = "SELECT id, username FROM users WHERE username = 'admin'";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute();
            $userExists = $checkStmt->rowCount() > 0;
            
            if (!$userExists) {
                // Create admin user if it doesn't exist (only if system is activated)
                $insertQuery = "INSERT INTO users (username, password, role, name) 
                               VALUES ('admin', :password, 'admin', 'Administrator')";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindValue(':password', $hash, PDO::PARAM_STR);
                
                if ($insertStmt->execute()) {
                    echo '<div class="success">';
                    echo '<strong>✓ Success!</strong><br>';
                    echo 'Admin user created and password set successfully!<br><br>';
                    echo 'You can now login with:<br>';
                    echo '<strong>Username:</strong> <code>admin</code><br>';
                    echo '<strong>Password:</strong> <code>admin123</code>';
                    echo '</div>';
                } else {
                    echo '<div class="error">';
                    echo '<strong>✗ Error:</strong> Failed to create admin user.';
                    echo '</div>';
                }
            } else {
                // Update existing admin password
                $updateQuery = "UPDATE users SET password = :password WHERE username = 'admin'";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindValue(':password', $hash, PDO::PARAM_STR);
                
                if ($updateStmt->execute()) {
                    echo '<div class="success">';
                    echo '<strong>✓ Success!</strong><br>';
                    echo 'Admin password updated successfully!<br><br>';
                    echo 'You can now login with:<br>';
                    echo '<strong>Username:</strong> <code>admin</code><br>';
                    echo '<strong>Password:</strong> <code>admin123</code>';
                    echo '</div>';
                } else {
                    echo '<div class="error">';
                    echo '<strong>✗ Error:</strong> Failed to update password.';
                    echo '</div>';
                }
            }
            
            // Test the password
            $testQuery = "SELECT password FROM users WHERE username = 'admin'";
            $testStmt = $db->prepare($testQuery);
            $testStmt->execute();
            $user = $testStmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                echo '<div class="info">';
                echo '<strong>✓ Verification:</strong> Password hash verified successfully!';
                echo '</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="error">';
            echo '<strong>✗ Database Error:</strong><br>';
            echo htmlspecialchars($e->getMessage());
            echo '<br><br>';
            echo '<strong>Please check:</strong><br>';
            echo '1. Database credentials in <code>config/database.php</code> are correct<br>';
            echo '2. Database <code>u124112239_gym</code> exists<br>';
            echo '3. Database schema has been imported<br>';
            echo '4. Database user has proper permissions';
            echo '</div>';
        }
        ?>
        
        <div class="info" style="margin-top: 30px;">
            <strong>⚠️ Security Note:</strong><br>
            After confirming login works, you should:<br>
            1. Delete this file (<code>fix-admin-password.php</code>) for security<br>
            2. Change the admin password from the default <code>admin123</code>
        </div>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="index.html" style="display: inline-block; padding: 10px 20px; background: #4a90e2; color: white; text-decoration: none; border-radius: 5px;">
                Go to Login Page
            </a>
        </div>
    </div>
</body>
</html>

