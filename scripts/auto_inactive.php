<?php
/**
 * Auto-Archive Inactive Members Script
 * 
 * Logic:
 * - If a member has unpaid dues for 2 consecutive months, mark inactive.
 * - If dues are cleared or the overdue window is below 2 months, keep active.
 * 
 * Run this via cron or manually.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';

header('Content-Type: text/html');

echo "<h1>Auto-Archive Inactive Members</h1>";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<p>Criteria: members with unpaid dues older than <b>2 consecutive months</b> become inactive.</p>";
    
    foreach (['men', 'women'] as $gender) {
        echo "<h3>Checking $gender...</h3>";
        $member = new Member($db, $gender);
        $result = $member->syncAllActivityStatuses();

        echo "<ul>";
        echo "<li>Total members: <b>" . (int)($result['total'] ?? 0) . "</b></li>";
        echo "<li>Active members: <b>" . (int)($result['active'] ?? 0) . "</b></li>";
        echo "<li>Inactive members: <b>" . (int)($result['inactive'] ?? 0) . "</b></li>";
        echo "<li>Rows refreshed: <b>" . (int)($result['affected_rows'] ?? 0) . "</b></li>";
        echo "</ul>";
    }
    
    echo "<hr><p>✅ Process Completed.</p>";
    echo "<a href='../admin-dashboard.html'>Return to Dashboard</a>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>Error: " . $e->getMessage() . "</h2>";
}
