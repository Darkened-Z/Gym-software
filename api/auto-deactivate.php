<?php
/**
 * Auto-Deactivate Members API
 * Syncs member active/inactive status based on unpaid dues older than 2 months
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $updated = 0;
    $errors = [];
    $summary = [];
    
    foreach (['men', 'women'] as $gender) {
        try {
            $member = new Member($db, $gender);
            $result = $member->syncAllActivityStatuses();
            $updated += (int)($result['affected_rows'] ?? 0);
            $summary[$gender] = $result;
        } catch (Throwable $e) {
            $errors[] = "Error syncing {$gender} members: " . $e->getMessage();
        }
    }
    
    echo json_encode([
        'success' => true,
        'updated' => $updated,
        'summary' => $summary,
        'errors' => $errors,
        'message' => "Member activity status sync completed. {$updated} rows refreshed."
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

