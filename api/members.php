<?php
/**
 * Members API (Gender-Aware)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

header('Content-Type: application/json');

AuthHelper::requireAdminOrStaff();

// Validate CSRF token for all state-mutating requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    AuthHelper::validateCSRF();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$gender = $_GET['gender'] ?? 'men';
$gender = in_array($gender, ['men', 'women'], true) ? $gender : 'men';

try {
    $database = new Database();
    $db = $database->getConnection();
    $member = new Member($db, $gender);
    $adminLogger = new AdminLogger($db);

    switch ($action) {
        case 'list':
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 20;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? null;  // 'active' or 'inactive' or null for all
            $filters = [
                'due_status' => $_GET['due_status'] ?? null,
                'due_within_days' => $_GET['due_within_days'] ?? null,
                'checked_in' => $_GET['checked_in'] ?? null,
                'sort_by' => $_GET['sort_by'] ?? null,
                'sort_dir' => $_GET['sort_dir'] ?? null,
            ];

            // Throttle status sync to at most once every 5 minutes per gender
            $syncJobName = 'status_sync_' . $gender;
            $syncCheckStmt = $db->prepare("SELECT last_run FROM system_jobs WHERE job_name = :name LIMIT 1");
            $syncCheckStmt->bindValue(':name', $syncJobName, PDO::PARAM_STR);
            $syncCheckStmt->execute();
            $syncJob = $syncCheckStmt->fetch(PDO::FETCH_ASSOC);
            $shouldSync = !$syncJob || !$syncJob['last_run'] || (time() - strtotime($syncJob['last_run'])) > 300;
                if ($shouldSync) {
                    $member->syncAllActivityStatuses();
                    $db->prepare("INSERT INTO system_jobs (job_name, last_run, status) VALUES (:name, NOW(), 'completed')
                              ON DUPLICATE KEY UPDATE last_run = NOW(), status = 'completed'")->execute([':name' => $syncJobName]);
                }
            $result = $member->getAll($page, $limit, $search, $status, $filters);
            echo json_encode([
                'success' => true,
                'data' => $result['data'],
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'limit' => $result['limit'],
                    'pages' => ceil($result['total'] / $result['limit'])
                ]
            ]);
            break;

        case 'get':
            $id = $_GET['id'] ?? null;
            if ($id) {
                $member->syncActivityStatus($id);
                $data = $member->getById($id);
                if ($data) {
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Member not found']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing member ID']);
            }
            break;

        case 'getByCode':
            $code = $_GET['code'] ?? null;
            if ($code) {
                $data = $member->getByCode($code);
                if ($data && isset($data['id'])) {
                    $member->syncActivityStatus((int)$data['id']);
                    $data = $member->getById((int)$data['id']);
                }
                if ($data) {
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Member not found']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing member code']);
            }
            break;

        case 'getByRfid':
            $rfidUid = $_GET['rfid_uid'] ?? null;
            if ($rfidUid) {
                $data = $member->getByRfidUid($rfidUid);
                if ($data && isset($data['id'])) {
                    $member->syncActivityStatus((int)$data['id']);
                    $data = $member->getById((int)$data['id']);
                }
                if ($data) {
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Member not found']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing RFID UID']);
            }
            break;

        case 'create':
            AuthHelper::ensureAdminAction('Only admin can create members');
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                if (!is_array($data)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
                    exit;
                }

                // Validate required fields
                $required = ['member_code', 'name', 'phone', 'join_date'];
                foreach ($required as $field) {
                    if (empty($data[$field])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                        exit;
                    }
                }

                // Validate phone number format
                $phone = preg_replace('/\D/', '', $data['phone']);
                if (strlen($phone) < 10 || strlen($phone) > 11) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid phone number format']);
                    exit;
                }

                // Check for duplicate member_code or phone
                $existing = $member->getByCode($data['member_code']);
                if ($existing) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Member code already exists']);
                    exit;
                }

                $phoneCheck = $db->prepare("SELECT id FROM members_{$gender} WHERE phone = :phone LIMIT 1");
                $phoneCheck->bindValue(':phone', trim($data['phone']), PDO::PARAM_STR);
                $phoneCheck->execute();
                if ($phoneCheck->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Phone number already exists']);
                    exit;
                }

                // Validate RFID UID if provided
                if (!empty($data['rfid_uid'])) {
                    $rfidUid = trim($data['rfid_uid']);
                    // Check if RFID UID is already assigned
                    $query = "SELECT id FROM members_{$gender} WHERE rfid_uid = :rfid_uid AND rfid_uid IS NOT NULL LIMIT 1";
                    $stmt = $db->prepare($query);
                    $stmt->bindValue(':rfid_uid', $rfidUid, PDO::PARAM_STR);
                    $stmt->execute();
                    if ($stmt->rowCount() > 0) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'RFID UID already assigned to another member']);
                        exit;
                    }
                }

                $memberData = [
                    'member_code' => trim($data['member_code']),
                    'name' => trim($data['name']),
                    'email' => $data['email'] ?? null,
                    'phone' => trim($data['phone']),
                    'rfid_uid' => !empty($data['rfid_uid']) ? trim($data['rfid_uid']) : null,
                    'address' => $data['address'] ?? null,
                    'profile_image' => $data['profile_image'] ?? null,
                    'membership_type' => $data['membership_type'] ?? 'Basic',
                    'join_date' => $data['join_date'],
                    'admission_fee' => (float)($data['admission_fee'] ?? 0.00),
                    'monthly_fee' => (float)($data['monthly_fee'] ?? 0.00),
                    'locker_fee' => (float)($data['locker_fee'] ?? 0.00),
                    'next_fee_due_date' => $data['next_fee_due_date'] ?? null,
                    'total_due_amount' => max(0, (float)($data['total_due_amount'] ?? 0.00)),
                    'status' => $data['status'] ?? 'active',
                    'is_checked_in' => 0
                ];

                $id = $member->create($memberData);
                if ($id) {
                    $adminLogger->log('member_created', 'member_' . $gender, $id, null, [
                        'member_code' => $memberData['member_code'],
                        'name' => $memberData['name'],
                        'phone' => $memberData['phone']
                    ]);
                    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Member created successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to create member']);
                }
            }
            break;

        case 'update':
            AuthHelper::ensureAdminAction('Only admin can update members');
            if ($method === 'POST' || $method === 'PUT') {
                $data = json_decode(file_get_contents('php://input'), true);
                if (!is_array($data)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
                    exit;
                }

                $id = $data['id'] ?? $_GET['id'] ?? null;

                if (!$id) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Missing member ID']);
                    exit;
                }

                $required = ['member_code', 'name', 'phone', 'join_date'];
                foreach ($required as $field) {
                    if (empty($data[$field])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                        exit;
                    }
                }

                $phoneCheck = $db->prepare("SELECT id FROM members_{$gender} WHERE phone = :phone AND id != :id LIMIT 1");
                $phoneCheck->bindValue(':phone', trim($data['phone']), PDO::PARAM_STR);
                $phoneCheck->bindValue(':id', (int)$id, PDO::PARAM_INT);
                $phoneCheck->execute();
                if ($phoneCheck->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Phone number already exists']);
                    exit;
                }

                if (!empty($data['rfid_uid'])) {
                    $rfidCheck = $db->prepare("SELECT id FROM members_{$gender} WHERE rfid_uid = :rfid_uid AND id != :id LIMIT 1");
                    $rfidCheck->bindValue(':rfid_uid', trim($data['rfid_uid']), PDO::PARAM_STR);
                    $rfidCheck->bindValue(':id', (int)$id, PDO::PARAM_INT);
                    $rfidCheck->execute();
                    if ($rfidCheck->fetch()) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'RFID UID already assigned to another member']);
                        exit;
                    }
                }

                $memberData = [
                    'member_code' => trim($data['member_code']),
                    'name' => trim($data['name']),
                    'email' => $data['email'] ?? null,
                    'phone' => trim($data['phone']),
                    'rfid_uid' => !empty($data['rfid_uid']) ? trim($data['rfid_uid']) : null,
                    'address' => $data['address'] ?? null,
                    'profile_image' => $data['profile_image'] ?? null,
                    'membership_type' => $data['membership_type'] ?? 'Basic',
                    'join_date' => $data['join_date'],
                    'admission_fee' => (float)($data['admission_fee'] ?? 0.00),
                    'monthly_fee' => (float)($data['monthly_fee'] ?? 0.00),
                    'locker_fee' => (float)($data['locker_fee'] ?? 0.00),
                    'next_fee_due_date' => $data['next_fee_due_date'] ?? null,
                    'total_due_amount' => max(0, (float)($data['total_due_amount'] ?? 0.00)),
                    'status' => $data['status'] ?? 'active'
                ];

                if ($member->update($id, $memberData)) {
                    $adminLogger->log('member_updated', 'member_' . $gender, $id, null, [
                        'member_code' => $memberData['member_code'],
                        'name' => $memberData['name'],
                        'phone' => $memberData['phone'],
                        'status' => $memberData['status']
                    ]);
                    echo json_encode(['success' => true, 'message' => 'Member updated successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to update member']);
                }
            }
            break;

        case 'delete':
            AuthHelper::ensureAdminAction('Only admin can delete members');
            if ($method === 'DELETE' || $method === 'POST') {
                $id = $_GET['id'] ?? json_decode(file_get_contents('php://input'), true)['id'] ?? null;

                if (!$id) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Missing member ID']);
                    exit;
                }

                if ($member->delete($id)) {
                    $adminLogger->log('member_deleted', 'member_' . $gender, $id);
                    echo json_encode(['success' => true, 'message' => 'Member deleted successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to delete member']);
                }
            }
            break;

        case 'updateFeeDueDate':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $id = $data['id'] ?? null;
                $date = $data['date'] ?? null;

                if (!$id || !$date) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Missing member ID or date']);
                    exit;
                }

                if ($member->updateFeeDueDate($id, $date)) {
                    $adminLogger->log('member_due_date_updated', 'member_' . $gender, $id, null, [
                        'next_fee_due_date' => $date
                    ]);
                    echo json_encode(['success' => true, 'message' => 'Fee due date updated successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to update fee due date']);
                }
            }
            break;

        case 'stats':
            $stats = $member->getStats();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        case 'recent':
            $limit = $_GET['limit'] ?? 10;
            $data = $member->getRecent($limit);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'search_all':
            // Search across both genders — returns merged results with a 'gender' field
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode(['success' => true, 'data' => []]);
                break;
            }
            $param = '%' . $q . '%';
            $results = [];
            foreach (['men', 'women'] as $g) {
                $tbl = 'members_' . $g;
                $dc = resolve_member_date_column($db, $tbl);
                $stmt = $db->prepare("SELECT id, member_code, name, phone, status, total_due_amount, '{$g}' AS gender, {$dc} AS join_date
                                      FROM {$tbl}
                                      WHERE member_code LIKE :q1 OR name LIKE :q2 OR phone LIKE :q3
                                      ORDER BY name ASC LIMIT 30");
                $stmt->bindValue(':q1', $param, PDO::PARAM_STR);
                $stmt->bindValue(':q2', $param, PDO::PARAM_STR);
                $stmt->bindValue(':q3', $param, PDO::PARAM_STR);
                $stmt->execute();
                $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            // Sort merged results by name
            usort($results, fn($a, $b) => strcmp($a['name'], $b['name']));
            echo json_encode(['success' => true, 'data' => array_slice($results, 0, 50)]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Members API error: ' . $e->getMessage());
    $statusCode = $e instanceof InvalidArgumentException ? 400 : 500;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $statusCode === 400 ? $e->getMessage() : 'An unexpected server error occurred.'
    ]);
}

