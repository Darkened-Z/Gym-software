<?php
/**
 * RFID Assignment API
 * Handles temporary storage of scanned RFID UIDs for assignment to members.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/helpers/Cache.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

header('Content-Type: application/json');

// Allow CORS if needed/configured (same as other APIs)
// header("Access-Control-Allow-Origin: *"); 

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'scan':
            // Called by ESP32
            $uid = $_GET['uid'] ?? '';
            
            if (empty($uid)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'UID required']);
                exit;
            }

            // Cache the UID for 30 seconds
            Cache::set('latest_rfid_scan', [
                'uid' => $uid,
                'timestamp' => time()
            ], 30);

            echo json_encode([
                'success' => true, 
                'message' => 'UID received',
                'uid' => $uid
            ]);
            break;

        case 'get_latest':
            // Called by Admin/Staff Frontend
            AuthHelper::requireAdminOrStaff();
            $cached = Cache::get('latest_rfid_scan');
            
            if ($cached) {
                echo json_encode([
                    'success' => true,
                    'found' => true,
                    'uid' => $cached['uid'],
                    'timestamp' => $cached['timestamp']
                ]);
            } else {
                echo json_encode([
                    'success' => true, // Request succeeded, just no data
                    'found' => false
                ]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
