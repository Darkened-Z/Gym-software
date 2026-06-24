<?php
/**
 * Packages API — membership packages (monthly + other plans).
 * Mirrors api/expenses.php: admin/staff can read; only admin can change.
 */

ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Package.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

ob_clean();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

AuthHelper::requireAdminOrStaff();

$action = $_GET['action'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    $package = new Package($db);
    $adminLogger = new AdminLogger($db);

    switch ($action) {
        case 'list':
            $page = intval($_GET['page'] ?? 1);
            $limit = intval($_GET['limit'] ?? 50);
            $filters = [
                'is_active' => $_GET['is_active'] ?? '',
                'search' => $_GET['search'] ?? ''
            ];
            $result = $package->getAll($page, $limit, $filters);
            echo json_encode([
                'success' => true,
                'data' => $result['data'],
                'pagination' => [
                    'page' => $result['page'],
                    'limit' => $result['limit'],
                    'total' => $result['total'],
                    'total_pages' => (int)ceil($result['total'] / max(1, $result['limit']))
                ]
            ]);
            break;

        case 'get':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Package ID is required']);
                break;
            }
            $row = $package->getById($id);
            if ($row) {
                echo json_encode(['success' => true, 'data' => $row]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Package not found']);
            }
            break;

        case 'create':
            AuthHelper::ensureAdminAction('Only admin can create packages');
            if ($method === 'POST') {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
                    break;
                }
                if (empty($data['name'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Package name is required']);
                    break;
                }
                $packageData = [
                    'name' => $data['name'],
                    'duration_months' => $data['duration_months'] ?? 1,
                    'price' => $data['price'] ?? 0,
                    'admission_fee' => $data['admission_fee'] ?? 0,
                    'description' => $data['description'] ?? null,
                    'is_active' => array_key_exists('is_active', $data) ? (int)!!$data['is_active'] : 1,
                    'created_by' => $_SESSION['user_id'] ?? null
                ];
                $id = $package->create($packageData);
                if ($id) {
                    $adminLogger->log('package_created', 'package', $id, null, [
                        'name' => $packageData['name'],
                        'price' => $packageData['price'],
                        'duration_months' => $packageData['duration_months']
                    ]);
                    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Package added successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to add package']);
                }
            }
            break;

        case 'update':
            AuthHelper::ensureAdminAction('Only admin can update packages');
            if ($method === 'POST' || $method === 'PUT') {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
                    break;
                }
                $id = $data['id'] ?? $_GET['id'] ?? null;
                if (!$id) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Package ID is required']);
                    break;
                }
                if (empty($data['name'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Package name is required']);
                    break;
                }
                $packageData = [
                    'name' => $data['name'],
                    'duration_months' => $data['duration_months'] ?? 1,
                    'price' => $data['price'] ?? 0,
                    'admission_fee' => $data['admission_fee'] ?? 0,
                    'description' => $data['description'] ?? null,
                    'is_active' => array_key_exists('is_active', $data) ? (int)!!$data['is_active'] : 1
                ];
                if ($package->update($id, $packageData)) {
                    $adminLogger->log('package_updated', 'package', $id, null, [
                        'name' => $packageData['name'],
                        'price' => $packageData['price']
                    ]);
                    echo json_encode(['success' => true, 'message' => 'Package updated successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to update package']);
                }
            }
            break;

        case 'delete':
            AuthHelper::ensureAdminAction('Only admin can delete packages');
            if ($method === 'DELETE' || $method === 'POST') {
                $id = $_GET['id'] ?? null;
                if ($method === 'POST') {
                    $input = file_get_contents('php://input');
                    $data = json_decode($input, true);
                    $id = $data['id'] ?? $id;
                }
                if (!$id) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Package ID is required']);
                    break;
                }
                if ($package->delete($id)) {
                    $adminLogger->log('package_deleted', 'package', $id);
                    echo json_encode(['success' => true, 'message' => 'Package deleted successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to delete package']);
                }
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
