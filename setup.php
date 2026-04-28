<?php
/**
 * SYSTEM ACTIVATION SCRIPT - CRITICAL SECURITY FILE
 * 
 * ⚠️ THIS FILE MUST BE RUN ONCE TO ACTIVATE THE SYSTEM ⚠️
 * 
 * This script:
 * 1. Generates a unique system license key based on server hardware
 * 2. Activates the system in the database
 * 3. Sets up the admin password
 * 
 * WITHOUT RUNNING THIS SCRIPT:
 * - No admin accounts can be created
 * - System will be locked and unusable
 * - This prevents unauthorized distribution
 * 
 * Usage: 
 *   Via browser: http://localhost/gym-management/setup.php
 *   Via CLI: php setup.php
 * 
 * SECURITY: This file acts as a system key/license mechanism
 */

// Check if running via CLI or browser
$isCLI = php_sapi_name() === 'cli';

if (!$isCLI) {
    // HTML output for browser
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>System Activation - Gym Management</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
            .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { color: #333; border-bottom: 3px solid #1e88e5; padding-bottom: 10px; }
            .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 15px 0; border: 1px solid #c3e6cb; }
            .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 15px 0; border: 1px solid #f5c6cb; }
            .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 15px 0; border: 1px solid #ffeaa7; }
            .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 15px 0; border: 1px solid #bee5eb; }
            code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
            .license-key { font-size: 18px; font-weight: bold; color: #1e88e5; letter-spacing: 2px; padding: 10px; background: #f0f0f0; border-radius: 5px; margin: 10px 0; }
            .step { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #1e88e5; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔐 System Activation</h1>
    <?php
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/helpers/LicenseHelper.php';

$output = [];
$success = false;

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if system_license table exists, if not create it
    try {
        $checkTable = $db->query("SHOW TABLES LIKE 'system_license'");
        if ($checkTable->rowCount() == 0) {
            // Create table if it doesn't exist
            $createTable = "CREATE TABLE system_license (
                id INT AUTO_INCREMENT PRIMARY KEY,
                license_key VARCHAR(255) UNIQUE NOT NULL,
                server_fingerprint VARCHAR(255) NOT NULL,
                activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_active TINYINT(1) DEFAULT 1,
                INDEX idx_license_key (license_key),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->exec($createTable);
            $output[] = "✓ Created system_license table";
        }
    } catch (Exception $e) {
        // Table might already exist or error creating it
    }
    
    // Get server fingerprint
    $serverFingerprint = LicenseHelper::getServerFingerprint();
    
    // Generate license key
    $licenseKey = LicenseHelper::generateLicenseKey($serverFingerprint);
    
    // Activate system
    $licenseHelper = new LicenseHelper($db);
    if ($licenseHelper->activateSystem($licenseKey, $serverFingerprint)) {
        $output[] = "✓ System activated successfully!";
        $output[] = "✓ License key generated and stored";
        $success = true;
    } else {
        $output[] = "✗ Failed to activate system";
    }
    
    // Set up admin password
    $password = 'admin123';
    $hash = password_hash($password, PASSWORD_BCRYPT);
    
    // Check if admin user exists
    $checkQuery = "SELECT id FROM users WHERE username = 'admin'";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() > 0) {
        // Update existing admin
        $query = "UPDATE users SET password = :password WHERE username = 'admin'";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':password', $hash, PDO::PARAM_STR);
        
        if ($stmt->execute()) {
            $output[] = "✓ Admin password updated successfully";
        } else {
            $output[] = "✗ Failed to update admin password";
        }
    } else {
        // Create admin user
        $query = "INSERT INTO users (username, password, role, name) VALUES ('admin', :password, 'admin', 'Administrator')";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':password', $hash, PDO::PARAM_STR);
        
        if ($stmt->execute()) {
            $output[] = "✓ Admin user created successfully";
        } else {
            $output[] = "✗ Failed to create admin user";
        }
    }
    
} catch (Exception $e) {
    $output[] = "✗ Error: " . $e->getMessage();
    $output[] = "";
    $output[] = "Make sure:";
    $output[] = "1. Database is created (u124112239_gym)";
    $output[] = "2. Schema is imported (database/setup_local_database.sql or setup_online_database.sql)";
    $output[] = "3. Database credentials in config/database.php are correct";
}

// Display output
if ($isCLI) {
    // CLI output
    foreach ($output as $line) {
        echo $line . "\n";
    }
    
    if ($success) {
        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "SYSTEM ACTIVATION COMPLETE\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "License Key: " . $licenseKey . "\n";
        echo "Server Fingerprint: " . $serverFingerprint . "\n";
        echo "\n";
        echo "You can now login with:\n";
        echo "  Username: admin\n";
        echo "  Password: admin123\n";
        echo "\n";
        echo "⚠️  IMPORTANT: Keep this license key secure!\n";
        echo "⚠️  Without running setup.php, no admin accounts can be created.\n";
    }
} else {
    // HTML output
    foreach ($output as $line) {
        if (strpos($line, '✓') !== false) {
            echo '<div class="success">' . htmlspecialchars($line) . '</div>';
        } elseif (strpos($line, '✗') !== false) {
            echo '<div class="error">' . htmlspecialchars($line) . '</div>';
        } else {
            echo '<div>' . htmlspecialchars($line) . '</div>';
        }
    }
    
    if ($success) {
        echo '<div class="info">';
        echo '<h2>✅ System Activation Complete!</h2>';
        echo '<div class="step">';
        echo '<strong>License Key:</strong><br>';
        echo '<div class="license-key">' . htmlspecialchars($licenseKey) . '</div>';
        echo '<small>Server Fingerprint: ' . htmlspecialchars($serverFingerprint) . '</small>';
        echo '</div>';
        echo '<div class="step">';
        echo '<strong>Login Credentials:</strong><br>';
        echo 'Username: <code>admin</code><br>';
        echo 'Password: <code>admin123</code>';
        echo '</div>';
        echo '<div class="warning">';
        echo '<strong>⚠️ Security Notice:</strong><br>';
        echo '• This license key is unique to this server<br>';
        echo '• Without running setup.php, no admin accounts can be created<br>';
        echo '• This prevents unauthorized distribution of the software<br>';
        echo '• Keep this license key secure and do not share it';
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div></body></html>';
}

