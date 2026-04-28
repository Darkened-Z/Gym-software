<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$indexes = [
    'members_men' => [
        'idx_member_code' => 'member_code',
        'idx_phone' => 'phone',
        'idx_email' => 'email'
    ],
    'members_women' => [
        'idx_member_code' => 'member_code',
        'idx_phone' => 'phone',
        'idx_email' => 'email'
    ],
    'payments_men' => [
        'idx_member_id' => 'member_id',
        'idx_payment_date' => 'payment_date'
    ],
    'payments_women' => [
        'idx_member_id' => 'member_id',
        'idx_payment_date' => 'payment_date'
    ],
    'attendance_men' => [
        'idx_member_id' => 'member_id',
        'idx_check_in' => 'check_in'  // Corrected column name
    ],
    'attendance_women' => [
        'idx_member_id' => 'member_id',
        'idx_check_in' => 'check_in'  // Corrected column name
    ]
];

try {
    $database = new Database();
    $db = $database->getConnection();
    echo "Verifying indexes with correct columns...\n";

    foreach ($indexes as $table => $tableIndexes) {
        echo "Table: $table\n";
        
        $stmt = $db->query("SHOW INDEX FROM $table");
        $existing = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[$row['Key_name']] = true;
        }

        foreach ($tableIndexes as $idxName => $column) {
            if (isset($existing[$idxName])) {
                echo "  [OK] $idxName already exists\n";
            } else {
                echo "  [ADD] Adding $idxName on $column...\n";
                try {
                    $db->exec("ALTER TABLE `$table` ADD INDEX `$idxName` (`$column`)");
                    echo "       Success\n";
                } catch (Exception $e) {
                    echo "       Failed: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    echo "Done.\n";

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}
