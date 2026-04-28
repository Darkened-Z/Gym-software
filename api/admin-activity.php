<?php
/**
 * Admin Activity API
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    $database = new Database();
    $db = $database->getConnection();
    $adminLogger = new AdminLogger($db);

    switch ($action) {
        case 'list':
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
            $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 20;
            $filters = [
                'action' => $_GET['log_action'] ?? '',
                'admin_username' => $_GET['admin_username'] ?? '',
                'target_type' => $_GET['target_type'] ?? '',
                'start_date' => $_GET['start_date'] ?? '',
                'end_date' => $_GET['end_date'] ?? '',
            ];

            $result = $adminLogger->list($page, $limit, $filters);
            echo json_encode([
                'success' => true,
                'data' => $result['data'],
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'limit' => $result['limit'],
                    'pages' => (int)ceil($result['total'] / max(1, $result['limit']))
                ]
            ]);
            break;

        case 'analytics':
            $filters = [
                'action' => $_GET['log_action'] ?? '',
                'admin_username' => $_GET['admin_username'] ?? '',
                'target_type' => $_GET['target_type'] ?? '',
                'start_date' => $_GET['start_date'] ?? '',
                'end_date' => $_GET['end_date'] ?? '',
            ];

            echo json_encode([
                'success' => true,
                'data' => $adminLogger->getAnalytics($filters)
            ]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Admin activity API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}
