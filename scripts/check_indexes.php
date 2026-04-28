<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$tables = [
    'members_men', 'members_women', 
    'payments_men', 'payments_women', 
    'attendance_men', 'attendance_women'
];

try {
    $database = new Database();
    $db = $database->getConnection();
    echo "Checking indexes...\n";

    foreach ($tables as $table) {
        echo "\nTable: $table\n";
        try {
            $stmt = $db->query("SHOW INDEX FROM $table");
            $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $seen = [];
            foreach ($indexes as $idx) {
                $name = $idx['Key_name'];
                if (!isset($seen[$name])) {
                    echo "  - $name (" . $idx['Column_name'] . ")\n";
                    $seen[$name] = true;
                }
            }
        } catch (Exception $e) {
            echo "  Error: " . $e->getMessage() . "\n";
        }
    }

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}
