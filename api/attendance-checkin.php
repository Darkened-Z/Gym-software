<?php
/**
 * Attendance Check-in/Check-out API
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/services/AttendanceWriteService.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();

    switch ($action) {
        case 'checkin':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                break;
            }

            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $memberId = (int)($data['member_id'] ?? 0);
            $gender = in_array(($data['gender'] ?? 'men'), ['men', 'women'], true) ? $data['gender'] : 'men';

            error_log("Attendance check-in attempt: member_id={$memberId}, gender={$gender}");

            if ($memberId <= 0) {
                error_log('Attendance check-in failed: Member ID missing');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Member ID required']);
                break;
            }

            $memberTable = 'members_' . $gender;
            $memberQuery = "SELECT id FROM {$memberTable} WHERE id = :member_id LIMIT 1";
            $memberStmt = $db->prepare($memberQuery);
            $memberStmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
            $memberStmt->execute();
            $member = $memberStmt->fetch();

            if (!$member) {
                error_log("Attendance check-in failed: Member not found in {$memberTable} with ID {$memberId}");
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Member not found']);
                break;
            }

            $service = new AttendanceWriteService($db, $gender);
            $result = $service->recordCheckIn($memberId, [
                'source' => 'member-profile'
            ]);

            if (!empty($result['success'])) {
                error_log("Attendance check-in successful: member_id={$memberId}, gender={$gender}, attendance_id=" . ($result['attendance_id'] ?? 'n/a'));
                echo json_encode([
                    'success' => true,
                    'message' => $result['message'] ?? 'Check-in recorded successfully',
                    'attendance_id' => $result['attendance_id'] ?? null,
                    'check_in' => $result['check_in'] ?? null,
                    'duplicate' => (bool)($result['duplicate'] ?? false)
                ]);
                break;
            }

            $statusCode = (int)($result['status_code'] ?? 500);
            http_response_code($statusCode);
            error_log('Attendance check-in failed: ' . ($result['message'] ?? 'Unknown error'));
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to record check-in'
            ]);
            break;

        case 'checkout':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                break;
            }

            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $attendanceId = (int)($data['attendance_id'] ?? 0);
            $gender = in_array(($data['gender'] ?? 'men'), ['men', 'women'], true) ? $data['gender'] : 'men';

            if ($attendanceId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Attendance ID required']);
                break;
            }

            $service = new AttendanceWriteService($db, $gender);
            $result = $service->recordCheckoutByAttendanceId($attendanceId, [
                'source' => 'member-profile'
            ]);

            if (!empty($result['success'])) {
                echo json_encode([
                    'success' => true,
                    'message' => $result['message'] ?? 'Check-out recorded successfully',
                    'check_out' => $result['check_out'] ?? null,
                    'duration_minutes' => $result['duration_minutes'] ?? null,
                    'duplicate' => (bool)($result['duplicate'] ?? false)
                ]);
                break;
            }

            $statusCode = (int)($result['status_code'] ?? 500);
            http_response_code($statusCode);
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to record check-out'
            ]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
