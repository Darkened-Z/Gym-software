<?php
/**
 * ZKTeco F22 device API (transport-agnostic).
 *
 * A connector (recommended: a Raspberry-Pi pyzk bridge on the gym LAN; or an
 * ADMS handler later) calls these over HTTPS. The cloud is the "brain": it maps
 * a device PIN to a member, decides paid/unpaid with the SAME rule as the RFID
 * gate (AccessDecision), records the check-in, and hands the connector the
 * allow/block roster it enforces on the device.
 *
 *   GET  ?action=sync_list   (device key) -> roster [{pin, allowed, valid_until,...}]
 *   POST ?action=report_scan (device key) -> record a scan, return paid/unpaid
 *   POST ?action=heartbeat   (device key) -> mark the device online
 *   GET  ?action=monitor     (admin)      -> recent F22 scans + device status
 *
 * Device identity: we assign the F22 user PIN ourselves so it is stable and
 * collision-free across the gender-split member tables:
 *   men   member id N -> PIN 10000000 + N
 *   women member id N -> PIN 20000000 + N
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Setting.php';
require_once __DIR__ . '/../app/services/AttendanceWriteService.php';
require_once __DIR__ . '/../app/services/AccessDecision.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

header('Content-Type: application/json');

const F22_MEN_OFFSET = 10000000;
const F22_WOMEN_OFFSET = 20000000;

function memberToPin(string $gender, $id): int {
    return ($gender === 'women' ? F22_WOMEN_OFFSET : F22_MEN_OFFSET) + (int)$id;
}
/** @return array{0:?string,1:int} [gender, member_id] */
function pinToMember($pin): array {
    $pin = (int)$pin;
    if ($pin >= F22_WOMEN_OFFSET) return ['women', $pin - F22_WOMEN_OFFSET];
    if ($pin >= F22_MEN_OFFSET) return ['men', $pin - F22_MEN_OFFSET];
    return [null, 0];
}

/** Device endpoints require the per-install shared secret (fails closed if unset). */
function requireDeviceKey(): void {
    $expected = (string)env('F22_DEVICE_KEY', '');
    $got = (string)($_SERVER['HTTP_X_DEVICE_KEY'] ?? '');
    if ($expected === '' || $got === '' || !hash_equals($expected, $got)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized device']);
        exit;
    }
}

/** Best-effort log into the shared gate_activity_log so F22 scans show up in the
 *  same monitor/reports as the RFID gate. Never breaks the scan response. */
function logF22Activity(PDO $db, array $d): void {
    try {
        $sql = "INSERT INTO gate_activity_log
                (gate_type, gate_id, rfid_uid, member_id, gender, member_name, action, status, reason, is_fee_defaulter)
                VALUES ('entry', 'f22', :rfid_uid, :member_id, :gender, :member_name, :action, :status, :reason, :is_fee_defaulter)";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':rfid_uid', (string)($d['pin'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue(':member_id', $d['member_id'] ?? null, $d['member_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':gender', $d['gender'] ?? null);
        $stmt->bindValue(':member_name', $d['member_name'] ?? null);
        $stmt->bindValue(':action', $d['action'] ?? 'check-in_attempt');
        $stmt->bindValue(':status', $d['status'] ?? 'denied');
        $stmt->bindValue(':reason', $d['reason'] ?? null);
        $stmt->bindValue(':is_fee_defaulter', (int)($d['is_fee_defaulter'] ?? 0), PDO::PARAM_INT);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[f22] gate log failed: ' . $e->getMessage());
    }
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = (new Database())->getConnection();

    switch ($action) {

        // ----- ROSTER the connector enforces on the device --------------------
        case 'sync_list':
            requireDeviceKey();
            $members = [];
            foreach (['men', 'women'] as $g) {
                $model = new Member($db, $g);
                $page = 1;
                do {
                    $res = $model->getAll($page, 500, '', null, []);
                    $rows = $res['data'] ?? [];
                    foreach ($rows as $m) {
                        $dec = AccessDecision::evaluate($m);
                        $members[] = [
                            'pin' => memberToPin($g, $m['id']),
                            'name' => $m['name'] ?? '',
                            'gender' => $g,
                            'member_code' => $m['member_code'] ?? null,
                            'card' => $m['rfid_uid'] ?? null,
                            'allowed' => $dec['allowed'],
                            'valid_until' => $m['next_fee_due_date'] ?? null,
                            'due_amount' => $dec['due_amount'],
                            'status' => $m['calculated_status'] ?? $m['status'] ?? null,
                        ];
                    }
                    $page++;
                } while (count($rows) === 500);
            }
            echo json_encode(['success' => true, 'count' => count($members), 'members' => $members]);
            break;

        // ----- A scan happened on the device ---------------------------------
        case 'report_scan':
            requireDeviceKey();
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'POST only']); break; }
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $pin = (int)($data['pin'] ?? $data['device_pin'] ?? $data['user_id'] ?? 0);
            [$gender, $id] = pinToMember($pin);

            if (!$gender || $id <= 0) {
                logF22Activity($db, ['pin' => $pin, 'action' => 'check-in_attempt', 'status' => 'denied', 'reason' => 'Unknown device PIN ' . $pin]);
                echo json_encode(['success' => true, 'access' => 'unknown', 'allowed' => false, 'reason' => 'This finger/card is not linked to a member yet.']);
                break;
            }

            $model = new Member($db, $gender);
            $member = $model->getById($id);
            if (!$member) {
                logF22Activity($db, ['pin' => $pin, 'gender' => $gender, 'action' => 'check-in_attempt', 'status' => 'denied', 'reason' => 'Member not found for PIN ' . $pin]);
                echo json_encode(['success' => true, 'access' => 'unknown', 'allowed' => false, 'reason' => 'Member record not found.']);
                break;
            }

            $dec = AccessDecision::evaluate($member);
            $doorOpened = false;
            if ($dec['allowed']) {
                try {
                    $svc = new AttendanceWriteService($db, $gender);
                    $r = $svc->recordCheckIn((int)$id, ['source' => 'gate-entry', 'gate_id' => 'f22']);
                    $doorOpened = !empty($r['success']);
                } catch (Throwable $e) {
                    error_log('[f22] attendance write failed: ' . $e->getMessage());
                }
            }

            logF22Activity($db, [
                'pin' => $pin, 'member_id' => $id, 'gender' => $gender, 'member_name' => $member['name'] ?? null,
                'action' => 'check-in',
                'status' => $dec['allowed'] ? 'success' : 'denied',
                'reason' => $dec['reason'],
                'is_fee_defaulter' => $dec['code'] === 'FEE_DUE' ? 1 : 0,
            ]);

            echo json_encode([
                'success' => true,
                'access' => $dec['allowed'] ? 'paid' : 'unpaid',
                'allowed' => $dec['allowed'],
                'door_opened' => $doorOpened,
                'member' => ['name' => $member['name'] ?? '', 'member_code' => $member['member_code'] ?? '', 'gender' => $gender],
                'due_amount' => $dec['due_amount'],
                'reason' => $dec['reason'],
            ]);
            break;

        // ----- Connector is alive --------------------------------------------
        case 'heartbeat':
            requireDeviceKey();
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            (new Setting($db))->setMany([
                'f22_last_seen' => gmdate('Y-m-d H:i:s') . ' UTC',
                'f22_device_sn' => substr((string)($data['device_sn'] ?? ''), 0, 64),
            ]);
            echo json_encode(['success' => true]);
            break;

        // ----- Admin front-desk monitor --------------------------------------
        case 'monitor':
            AuthHelper::requireAdminOrStaff();
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
            $stmt = $db->prepare("SELECT created_at, member_name, gender, action, status, reason, is_fee_defaulter
                                  FROM gate_activity_log WHERE gate_id = 'f22'
                                  ORDER BY id DESC LIMIT :lim");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $scans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $s = (new Setting($db))->getMap(['f22_last_seen', 'f22_device_sn']);
            echo json_encode(['success' => true, 'last_seen' => $s['f22_last_seen'] ?? null, 'device_sn' => $s['f22_device_sn'] ?? null, 'scans' => $scans]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('[f22.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => DEBUG_MODE ? $e->getMessage() : 'Server error']);
}
