<?php
/**
 * Reminder Queue API
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/MessageTemplate.php';
require_once __DIR__ . '/../app/models/MessageQueue.php';
require_once __DIR__ . '/../app/models/MemberConsent.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$gender = $_GET['gender'] ?? 'men';
$gender = in_array($gender, ['men', 'women'], true) ? $gender : 'men';
$memberTable = 'members_' . $gender;

try {
    $database = new Database();
    $db = $database->getConnection();

    $templateModel = new MessageTemplate($db);
    $queueModel = new MessageQueue($db);
    $consentModel = new MemberConsent($db);

    switch ($action) {
        case 'templates':
            echo json_encode(['success' => true, 'data' => $templateModel->getAll('whatsapp', true)]);
            break;

        case 'queue-fee-reminders':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $purpose = $data['purpose'] ?? 'fee_due';
            $daysAhead = isset($data['days_ahead']) ? (int)$data['days_ahead'] : 0;
            $cooldownHours = isset($data['cooldown_hours']) ? (int)$data['cooldown_hours'] : ($purpose === 'fee_overdue' ? 48 : 72);
            $gymName = trim((string)($data['gym_name'] ?? 'Your Gym'));
            $templateKey = $purpose === 'fee_overdue' ? 'fee_overdue_basic' : 'fee_due_basic';
            $template = $templateModel->getByKey($templateKey, 'whatsapp');

            if (!$template) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Template not found']);
                exit;
            }

            $dateExpr = $purpose === 'fee_overdue'
                ? 'm.next_fee_due_date < CURDATE()'
                : 'm.next_fee_due_date <= DATE_ADD(CURDATE(), INTERVAL :days_ahead DAY)';

            $query = "SELECT m.id, m.name, m.phone, m.total_due_amount, m.next_fee_due_date
                      FROM {$memberTable} m
                      WHERE m.status = 'active'
                        AND m.phone IS NOT NULL
                        AND m.phone != ''
                        AND m.total_due_amount > 0
                        AND {$dateExpr}";

            $stmt = $db->prepare($query);
            if ($purpose !== 'fee_overdue') {
                $stmt->bindValue(':days_ahead', $daysAhead, PDO::PARAM_INT);
            }
            $stmt->execute();
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $queued = 0;
            $skipped = 0;
            $suppressed = 0;
            foreach ($members as $member) {
                if (!$consentModel->hasGrantedConsent($memberTable, (int)$member['id'])) {
                    $skipped++;
                    continue;
                }

                if ($queueModel->hasRecentSimilarMessage($memberTable, (int)$member['id'], $purpose, $cooldownHours)) {
                    $suppressed++;
                    continue;
                }

                $payload = [
                    'member_name' => $member['name'],
                    'amount' => $member['total_due_amount'],
                    'due_date' => $member['next_fee_due_date'],
                    'gym_name' => $gymName
                ];

                $queueId = $queueModel->create([
                    'member_table' => $memberTable,
                    'member_id' => (int)$member['id'],
                    'template_id' => $template['id'],
                    'channel' => 'whatsapp',
                    'recipient' => $member['phone'],
                    'message_purpose' => $purpose,
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'scheduled_for' => date('Y-m-d H:i:s'),
                    'status' => 'pending'
                ]);

                if ($queueId) {
                    $queued++;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Reminder queue generation completed',
                'queued' => $queued,
                'skipped' => $skipped,
                'suppressed' => $suppressed,
                'purpose' => $purpose,
                'gender' => $gender,
                'cooldown_hours' => $cooldownHours
            ]);
            break;

        case 'stats':
            echo json_encode(['success' => true, 'data' => $queueModel->getStats()]);
            break;

        case 'pending':
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            echo json_encode(['success' => true, 'data' => $queueModel->getPending($limit)]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Reminders API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected server error occurred.']);
}
