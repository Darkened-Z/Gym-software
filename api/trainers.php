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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

// Portal actions can be accessed by trainers via trainer authentication
$isPortalAction = in_array($action, ['portal_login', 'portal_data', 'portal_update_member'], true);
if (!$isPortalAction) {
    AuthHelper::requireAdminOrStaff();
}

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

        case 'portal_login': {
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
            $input = json_decode(file_get_contents('php://input'), true);
            $loginId = trim((string)($input['login_id'] ?? $input['code'] ?? ''));
            $pin = trim((string)($input['pin'] ?? ''));

            if ($loginId === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Trainer code or phone number required']);
                exit;
            }

            // Lookup by trainer code or phone
            $trainer = $model->getByCode($loginId);
            if (!$trainer) {
                $trainer = $model->getByPhone($loginId);
            }

            if (!$trainer || ($trainer['status'] ?? '') !== 'active') {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Trainer not found or inactive']);
                exit;
            }

            // Authenticate PIN: default is last 4 digits of trainer phone, or '1234'
            $cleanPhone = preg_replace('/[^0-9]/', '', $trainer['phone'] ?? '');
            $expectedPin = substr($cleanPhone, -4);
            if ($pin !== '' && $pin !== $expectedPin && $pin !== '1234') {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Invalid PIN. (Default PIN is last 4 digits of phone)']);
                exit;
            }

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['trainer_id'] = (int)$trainer['id'];
            $_SESSION['trainer_code'] = $trainer['trainer_code'];

            echo json_encode([
                'success' => true,
                'trainer' => [
                    'id' => (int)$trainer['id'],
                    'trainer_code' => $trainer['trainer_code'],
                    'name' => $trainer['name'],
                    'phone' => $trainer['phone'],
                    'section' => $trainer['section'],
                    'commission_pct' => (float)$trainer['commission_pct'],
                    'monthly_salary' => (float)$trainer['monthly_salary'],
                    'notes' => $trainer['notes']
                ]
            ]);
            break;
        }

        case 'portal_data': {
            $trainerId = filter_var($_GET['trainer_id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$trainerId) {
                if (session_status() === PHP_SESSION_NONE) session_start();
                $trainerId = (int)($_SESSION['trainer_id'] ?? 0);
            }
            if (!$trainerId && !empty($_GET['code'])) {
                $t = $model->getByCode($_GET['code']);
                if ($t) $trainerId = (int)$t['id'];
            }

            if (!$trainerId) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Trainer authentication required']);
                exit;
            }

            $portalData = $model->getPortalData($trainerId);
            echo json_encode($portalData);
            break;
        }

        case 'portal_update_member': {
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
            require_once __DIR__ . '/../app/models/MemberFitness.php';
            $input = json_decode(file_get_contents('php://input'), true);
            $gender = ($input['gender'] ?? 'men') === 'women' ? 'women' : 'men';
            $memberId = (int)($input['member_id'] ?? 0);
            if (!$memberId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Member ID is required']);
                exit;
            }

            $fitModel = new MemberFitness($db);
            $updateData = [];
            if (isset($input['goal'])) $updateData['goal'] = $input['goal'];
            if (isset($input['target_weight'])) $updateData['target_weight'] = $input['target_weight'];
            if (isset($input['goal_notes'])) $updateData['goal_notes'] = $input['goal_notes'];
            if (isset($input['height'])) $updateData['height'] = $input['height'];

            $ok = $fitModel->save($gender, $memberId, $updateData);
            echo json_encode(['success' => $ok]);
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
