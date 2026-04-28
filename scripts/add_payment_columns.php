<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Connected to database: " . $db->query('select database()')->fetchColumn() . "\n";

    $tables = ['payments_men', 'payments_women'];
    
    foreach ($tables as $table) {
        // Check if columns exist
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE 'received_by'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            echo "Adding received_by column to $table...\n";
            $db->exec("ALTER TABLE `$table` ADD COLUMN `received_by` VARCHAR(50) DEFAULT NULL AFTER `status`");
        } else {
            echo "received_by column already exists in $table.\n";
        }

        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE 'payment_method'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            echo "Adding payment_method column to $table...\n";
            $db->exec("ALTER TABLE `$table` ADD COLUMN `payment_method` VARCHAR(20) DEFAULT 'Cash' AFTER `received_by`");
        } else {
            echo "payment_method column already exists in $table.\n";
        }
    }
    
    echo "Database schema updated successfully!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
