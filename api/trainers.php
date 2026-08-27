<?php
/**
 * Trainers API — CRUD + a select-list endpoint used by the member form dropdown.
 *
 * Route: /api/trainers.php?action=<list|get|create|update|deactivate|activate|select>
 * Admin-only for state changes; list/get/select accessible to any signed-in
 * admin or staff. Mirrors the packages.php / staff.php conventions.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';
require_once __DIR__ . '/../app/models/Trainer.php';

header('Content-Type: application/json');

AuthHelper::requireAdminOrStaff();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

try {
    $db = (new Database())->getConnection();
    $adminLogger = new AdminLogger($db);
    $model = new Trainer($db);

    switch ($action) {
        case 'list': {
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
            $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 20;
            $search = trim((string)($_GET['search'] ?? ''));
            $section = trim((string)($_GET['section'] ?? ''));
            $status = trim((string)($_GET['status'] ?? ''));
            $result = $model->getAll($page, $limit, $search, $section, $status);
            echo json_encode(['success' => true] + $result);
            break;
        }

        case 'get': {
            $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
            $row = $model->getById($id);
            if (!$row) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Trainer not found']); exit; }
            echo json_encode(['success' => true, 'data' => $row]);
            break;
        }

        case 'select': {
            // Dropdown feed for the member add/edit form. Section optional.
            $section = trim((string)($_GET['section'] ?? ''));
            $rows = $model->getForSelect($section);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;
        }

        case 'create': {
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
            AuthHelper::ensureAdminAction('Only admin can add trainers');
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid payload']); exit; }
            $res = $model->create($data);
            if (!empty($res['success'])) {
                $adminLogger->log('trainer_created', 'trainer', $res['id'], null, [
                    'code' => $res['trainer_code'],
                    'name' => $data['name'] ?? '',
                ]);
            }
            echo json_encode($res);
            break;
        }

        case 'update': {
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
            AuthHelper::ensureAdminAction('Only admin can edit trainers');
            $data = json_decode(file_get_contents('php://input'), true);
            $id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
            $res = $model->update($id, $data);
            if (!empty($res['success'])) {
                $adminLogger->log('trainer_updated', 'trainer', $id, null, ['name' => $data['name'] ?? '']);
            }
            echo json_encode($res);
            break;
        }

        case 'deactivate': {
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
            AuthHelper::ensureAdminAction('Only admin can deactivate trainers');
            $data = json_decode(file_get_contents('php://input'), true);
            $id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
            $res = $model->deactivate($id);
            if (!empty($res['success'])) {
                $adminLogger->log('trainer_deactivated', 'trainer', $id);
            }
            echo json_encode($res);
            break;
        }

        case 'activate': {
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
            AuthHelper::ensureAdminAction('Only admin can activate trainers');
            $data = json_decode(file_get_contents('php://input'), true);
            $id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
            $res = $model->activate($id);
            if (!empty($res['success'])) {
                $adminLogger->log('trainer_activated', 'trainer', $id);
            }
            echo json_encode($res);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('trainers.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
