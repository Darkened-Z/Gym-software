<?php
// scripts/debug_payment_load.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Payment.php';

echo "Starting Debug Script...\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    echo "Database connected.\n";

    $code = '5678';
    echo "Looking up member code: $code\n";

    $memberMen = new Member($db, 'men');
    $member = $memberMen->getByCode($code);
    $gender = 'men';

    if (!$member) {
        echo "Not found in Men. Checking Women...\n";
        $memberWomen = new Member($db, 'women');
        $member = $memberWomen->getByCode($code);
        $gender = 'women';
    }

    if ($member) {
        echo "Found Member: " . $member['name'] . " (ID: " . $member['id'] . ", Gender: $gender)\n";
        
        echo "Fetching payments...\n";
        $payment = new Payment($db, $gender);
        $start = microtime(true);
        $payments = $payment->getByMemberId($member['id']);
        $end = microtime(true);
        
        echo "Payments found: " . count($payments) . "\n";
        echo "Time taken: " . number_format($end - $start, 4) . " seconds\n";
        
        if (count($payments) > 0) {
            echo "Latest Payment: " . $payments[0]['payment_date'] . " - " . $payments[0]['amount'] . "\n";
        }
    } else {
        echo "Member not found!\n";
    }

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

echo "Done.\n";
