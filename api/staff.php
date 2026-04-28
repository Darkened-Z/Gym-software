<?php
/**
 * Staff management API
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';
require_once __DIR__ . '/../app/models/User.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

header('Content-Type: application/json');

AuthHelper::requireAdminOrStaff();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

try {
    $database = new Database();
    $db = $database->getConnection();
    $adminLogger = new AdminLogger($db);
    $userModel = new User($db);

    switch ($action) {
        case 'list':
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
            $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 20;
            $search = trim((string)($_GET['search'] ?? ''));
            $result = $userModel->getAll($page, $limit, $search);
            echo json_encode(['success' => true] + $result);
            break;

        case 'create':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }

            AuthHelper::ensureAdminAction('Only admin can create staff');

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
                exit;
            }

            $username = trim((string)($data['username'] ?? ''));
            $password = (string)($data['password'] ?? '');
            $name = trim((string)($data['name'] ?? ''));
            $role = trim((string)($data['role'] ?? 'staff'));

            if ($username === '' || $password === '' || $name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Name, username, and password are required']);
                exit;
            }

            if (!in_array($role, ['admin', 'staff'], true)) {
                $role = 'staff';
            }

            $id = $userModel->create([
                'username' => $username,
                'password' => $password,
                'name' => $name,
                'role' => $role
            ]);

            $adminLogger->log('staff_created', 'user', $id, null, [
                'username' => $username,
                'name' => $name,
                'role' => $role
            ]);

            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Staff user created successfully']);
            break;

        case 'update':
            if ($method !== 'POST' && $method !== 'PUT') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }

            AuthHelper::ensureAdminAction('Only admin can update staff');

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
                exit;
            }

            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid staff ID']);
                exit;
            }

            $updateData = [
                'name' => trim((string)($data['name'] ?? '')),
                'username' => trim((string)($data['username'] ?? '')),
                'role' => trim((string)($data['role'] ?? 'staff')),
                'password' => (string)($data['password'] ?? '')
            ];

            if ($updateData['name'] === '' || $updateData['username'] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Name and username are required']);
                exit;
            }

            if (!in_array($updateData['role'], ['admin', 'staff'], true)) {
                $updateData['role'] = 'staff';
            }

            $userModel->update($id, $updateData);
            $adminLogger->log('staff_updated', 'user', $id, null, [
                'username' => $updateData['username'],
                'name' => $updateData['name'],
                'role' => $updateData['role']
            ]);

            echo json_encode(['success' => true, 'message' => 'Staff user updated successfully']);
            break;

        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }

            AuthHelper::ensureAdminAction('Only admin can delete staff');

            $payload = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = filter_var($_GET['id'] ?? ($payload['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid staff ID']);
                exit;
            }

            if ((int)$id === (int)($_SESSION['user_id'] ?? 0)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'You cannot delete your own logged-in account']);
                exit;
            }

            $userModel->delete($id);
            $adminLogger->log('staff_deleted', 'user', $id);
            echo json_encode(['success' => true, 'message' => 'Staff user deleted successfully']);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Staff API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected server error occurred.']);
}
