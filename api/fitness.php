<?php
/**
 * Fitness & AI Coach API
 *
 * Powers both the dedicated Member Fit portal (member-fit.html)
 * and the AI Coach widgets embedded in member profiles.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Attendance.php';
require_once __DIR__ . '/../app/models/Trainer.php';
require_once __DIR__ . '/../app/models/MemberFitness.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'get';
$code = trim((string)($_GET['code'] ?? ''));

try {
    $db = (new Database())->getConnection();

    // 1. Resolve Member
    $memberMen = new Member($db, 'men');
    $memberWomen = new Member($db, 'women');

    $member = null;
    $gender = null;

    if ($code !== '') {
        $member = $memberMen->getByCode($code);
        $gender = 'men';
        if (!$member) {
            $member = $memberWomen->getByCode($code);
            $gender = 'women';
        }
        // Fallback: search by phone number if not found by exact code
        if (!$member && strlen(preg_replace('/[^0-9]/', '', $code)) >= 4) {
            $cleanPhone = '%' . substr(preg_replace('/[^0-9]/', '', $code), -10);
            $pStmt = $db->prepare("SELECT * FROM members_men WHERE REPLACE(REPLACE(phone, '-', ''), ' ', '') LIKE ? LIMIT 1");
            $pStmt->execute([$cleanPhone]);
            $member = $pStmt->fetch(PDO::FETCH_ASSOC);
            $gender = 'men';
            if (!$member) {
                $pStmt = $db->prepare("SELECT * FROM members_women WHERE REPLACE(REPLACE(phone, '-', ''), ' ', '') LIKE ? LIMIT 1");
                $pStmt->execute([$cleanPhone]);
                $member = $pStmt->fetch(PDO::FETCH_ASSOC);
                $gender = 'women';
            }
        }
    } elseif (!empty($_SESSION['member_code'])) {
        $gender = ($_SESSION['member_gender'] ?? 'men') === 'women' ? 'women' : 'men';
        $model = $gender === 'women' ? $memberWomen : $memberMen;
        $member = $model->getByCode($_SESSION['member_code']);
    }

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found. Please provide a valid member code.']);
        exit;
    }

    $fitnessModel = new MemberFitness($db);
    $memberId = (int)$member['id'];

    switch ($action) {
        case 'get': {
            $fit = $fitnessModel->getByMember($gender, $memberId);

            // Fetch recent attendance sessions (last 30 days) to feed into the AI stats
            $attStmt = $db->prepare(
                "SELECT DATE(check_in) as `date`,
                        TIMESTAMPDIFF(MINUTE, check_in, COALESCE(check_out, DATE_ADD(check_in, INTERVAL 60 MINUTE))) as minutes
                 FROM attendance_{$gender}
                 WHERE member_id = :mid AND check_in >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                 ORDER BY check_in ASC"
            );
            $attStmt->execute([':mid' => $memberId]);
            $rawAtt = $attStmt->fetchAll(PDO::FETCH_ASSOC);

            $sessions = [];
            foreach ($rawAtt as $a) {
                $sessions[] = [
                    'date' => $a['date'],
                    'minutes' => max(30, min(120, (int)$a['minutes'])),
                    'rpe' => 8,
                    'type' => 'full-body'
                ];
            }

            // Fetch trainer name if assigned
            $trainerName = null;
            if (!empty($member['assigned_trainer_id'])) {
                try {
                    $tStmt = $db->prepare("SELECT name FROM trainers WHERE id = :tid LIMIT 1");
                    $tStmt->execute([':tid' => $member['assigned_trainer_id']]);
                    $trainerName = $tStmt->fetchColumn() ?: null;
                } catch (Exception $e) {}
            }

            // Build AI-compatible member payload
            $payload = [
                'id' => $memberId,
                'code' => $member['member_code'],
                'name' => $member['name'],
                'firstName' => explode(' ', trim($member['name']))[0] ?? $member['name'],
                'gender' => $gender === 'women' ? 'female' : 'male',
                'section' => $gender,
                'phone' => $member['phone'] ?? '',
                'joinDate' => $member['join_date'] ?? date('Y-m-d'),
                'height' => (float)($fit['height'] ?: 175.0),
                'goal' => $fit['goal'] ?: 'maintain',
                'targetWeight' => $fit['target_weight'] !== null ? (float)$fit['target_weight'] : null,
                'goalNotes' => $fit['goal_notes'] ?: '',
                'vegetarian' => (bool)$fit['vegetarian'],
                'allergies' => array_filter(array_map('trim', explode(',', $fit['allergies'] ?? ''))),
                'injuries' => array_filter(array_map('trim', explode(',', $fit['injuries'] ?? ''))),
                'experience' => $fit['experience'] ?: 'intermediate',
                'pkg' => $member['membership_type'] ?? 'Standard',
                'monthlyFee' => (float)($member['monthly_fee'] ?? 0),
                'ptf' => (float)($member['ptf_fee'] ?? 0),
                'due' => (float)($member['total_due_amount'] ?? 0),
                'nextDue' => $member['next_fee_due_date'] ?? null,
                'trainer' => $trainerName,
                'progress' => $fit['progress'] ?: [
                    // Provide at least a sensible baseline if no progress has been logged yet
                    [
                        'date' => $member['join_date'] ?? date('Y-m-d'),
                        'weight' => $fit['target_weight'] ? ($fit['goal'] === 'lose' ? $fit['target_weight'] + 5 : ($fit['goal'] === 'gain' ? $fit['target_weight'] - 4 : $fit['target_weight'])) : 72.0,
                        'chest' => 96,
                        'waist' => 82,
                        'arms' => 32,
                        'thighs' => 54,
                        'notes' => 'Baseline'
                    ]
                ],
                'sessions' => $sessions,
                'sessionLogs' => $fit['session_logs'] ?: [],
                'mealLogs' => $fit['meal_logs'] ?: []
            ];

            echo json_encode([
                'success' => true,
                'data' => $payload,
                'today' => date('Y-m-d')
            ]);
            break;
        }

        case 'save_profile': {
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
                exit;
            }

            if (is_array($data['allergies'] ?? null)) {
                $data['allergies'] = implode(', ', $data['allergies']);
            }
            if (is_array($data['injuries'] ?? null)) {
                $data['injuries'] = implode(', ', $data['injuries']);
            }

            $ok = $fitnessModel->save($gender, $memberId, $data);
            echo json_encode(['success' => $ok]);
            break;
        }

        case 'log_progress': {
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }
            $entry = json_decode(file_get_contents('php://input'), true);
            if (!is_array($entry) || empty($entry['weight'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Weight is required']);
                exit;
            }
            $ok = $fitnessModel->addProgress($gender, $memberId, $entry);
            echo json_encode(['success' => $ok]);
            break;
        }

        case 'log_session': {
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }
            $log = json_decode(file_get_contents('php://input'), true);
            if (!is_array($log)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid payload']);
                exit;
            }
            $ok = $fitnessModel->addSessionLog($gender, $memberId, $log);
            echo json_encode(['success' => $ok]);
            break;
        }

        case 'log_meal': {
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }
            $meal = json_decode(file_get_contents('php://input'), true);
            if (!is_array($meal)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid payload']);
                exit;
            }
            $ok = $fitnessModel->addMealLog($gender, $memberId, $meal);
            echo json_encode(['success' => $ok]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('fitness.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
