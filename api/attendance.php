<?php
/**
 * Attendance API (Gender-Aware)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Attendance.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

header('Content-Type: application/json');

AuthHelper::requireAdminOrStaff();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$gender = $_GET['gender'] ?? 'men';

try {
    $database = new Database();
    $db = $database->getConnection();
    $attendance = new Attendance($db, $gender);

    switch ($action) {
        case 'list':
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 20;
            $filters = [
                'search' => $_GET['search'] ?? '',
                'start_date' => $_GET['start_date'] ?? '',
                'end_date' => $_GET['end_date'] ?? '',
                'active_only' => $_GET['active_only'] ?? false,
            ];

            $result = $attendance->getAll($page, $limit, $filters);
            echo json_encode([
                'success' => true,
                'data' => $result['data'],
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'limit' => $result['limit'],
                    'pages' => ceil($result['total'] / $result['limit'])
                ],
                'filters' => $filters
            ]);
            break;

        case 'today-summary':
            echo json_encode([
                'success' => true,
                'data' => $attendance->getTodaySummary()
            ]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Attendance API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}

