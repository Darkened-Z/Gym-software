<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "Starting DB Fix 2.0...\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    // Verify ACTUAL connected DB name
    $stmt = $db->query("SELECT DATABASE()");
    $actualDb = $stmt->fetchColumn();
    echo "Connected to ACTUAL DB: " . $actualDb . "\n";

    $tables = ['members_men', 'members_women'];

    foreach ($tables as $table) {
        echo "Checking $table...\n";
        
        // Check column
        $stmt = $db->prepare("SHOW COLUMNS FROM $table LIKE 'is_checked_in'");
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            echo " - Column 'is_checked_in' EXISTS.\n";
        } else {
            echo " - Column 'is_checked_in' MISSING. Adding...\n";
            try {
                $db->exec("ALTER TABLE $table ADD COLUMN is_checked_in TINYINT(1) DEFAULT 0 AFTER status");
                $db->exec("ALTER TABLE $table ADD INDEX idx_is_checked_in (is_checked_in)");
                echo " - FIXED. Added column and index.\n";
            } catch (Exception $e) {
                echo " - ERROR: " . $e->getMessage() . "\n";
            }
        }
    }
} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
}
echo "Done.\n";
