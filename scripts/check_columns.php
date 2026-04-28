<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    echo "Checking attendance table columns...\n";

    $tables = ['attendance_men', 'attendance_women'];
    foreach ($tables as $table) {
        echo "Table: $table\n";
        $stmt = $db->query("SHOW COLUMNS FROM $table");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo " - " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
