<?php
/**
 * Settings API — gym contact + social links.
 *   action=public     -> NO auth; returns whitelisted public keys (for footer)
 *   action=admin_get  -> admin/staff; returns the editable settings map
 *   action=save       -> admin only; upserts the whitelisted keys
 */

ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Setting.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

ob_clean();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    $setting = new Setting($db);

    // Public read — contact + socials only, no auth (drives the login footer).
    if ($action === 'public') {
        echo json_encode(['success' => true, 'data' => $setting->getPublicMap()]);
        exit;
    }

    // Everything below requires a logged-in admin/staff.
    AuthHelper::requireAdminOrStaff();

    switch ($action) {
        case 'admin_get':
            echo json_encode(['success' => true, 'data' => $setting->getMap(Setting::editableKeys())]);
            break;

        case 'save':
            AuthHelper::ensureAdminAction('Only admin can change gym details');
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
                    break;
                }
                $assoc = [];
                foreach (Setting::editableKeys() as $k) {
                    if (array_key_exists($k, $data)) {
                        $assoc[$k] = is_string($data[$k]) ? trim($data[$k]) : $data[$k];
                    }
                }
                $setting->setMany($assoc);
                $adminLogger = new AdminLogger($db);
                $adminLogger->log('settings_updated', 'settings', null, null, ['keys' => array_keys($assoc)]);
                echo json_encode(['success' => true, 'message' => 'Details saved']);
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
