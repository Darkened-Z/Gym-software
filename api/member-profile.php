<?php
/**
 * Member Profile API (for member portal)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Attendance.php';
require_once __DIR__ . '/../app/models/Payment.php';

// Release session lock immediately to prevent blocking
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$memberCode = $_GET['code'] ?? '';
error_log("API Request: Action=$action, Code=$memberCode, ID=" . ($_GET['member_id'] ?? 'null'));


try {
    $database = new Database();
    $db = $database->getConnection();

    // For member lookup, check both genders by exact member code.
    $memberMen = new Member($db, 'men');
    $memberWomen = new Member($db, 'women');
    
    $member = null;
    $gender = null;
    $memberId = filter_var($_GET['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    
    // 1. Exact member-code lookup (public member portal flow)
    if (!empty($memberCode)) {
        $member = $memberMen->getByCode($memberCode);
        $gender = 'men';
        
        if (!$member) {
            $member = $memberWomen->getByCode($memberCode);
            $gender = 'women';
        }
    }
    // 2. Logged-in member session fallback
    elseif (isset($_SESSION['member_code'])) {
        $gender = $_SESSION['member_gender'] ?? 'men';
        $memberModel = $gender === 'women' ? $memberWomen : $memberMen;
        $member = $memberModel->getByCode($_SESSION['member_code']);
    }
    // 3. Admin/staff direct lookup by ID (internal use only)
    elseif ($memberId && in_array($_SESSION['role'] ?? null, ['admin', 'staff'], true)) {
        $member = $memberMen->getById($memberId);
        $gender = 'men';

        if (!$member) {
            $member = $memberWomen->getById($memberId);
            $gender = 'women';
        }
    }

    if ($member && isset($member['id']) && $gender) {
        try {
            $memberModel = $gender === 'women' ? $memberWomen : $memberMen;
            $memberModel->syncActivityStatus((int)$member['id']);
            $member = $memberModel->getById((int)$member['id']);
        } catch (Throwable $e) {
            error_log('Member profile status sync failed: ' . $e->getMessage());
        }
    }

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found']);
        exit;
    }

    switch ($action) {
        case 'profile':
            echo json_encode([
                'success' => true,
                'data' => $member,
                'gender' => $gender
            ]);
            break;

        case 'attendance':
            $year = $_GET['year'] ?? date('Y');
            $month = $_GET['month'] ?? date('m');
            
            $attendance = new Attendance($db, $gender);
            $calendarData = $attendance->getCalendarData($member['id'], $year, $month);
            $attendanceList = $attendance->getByMemberId($member['id']);
            
            echo json_encode([
                'success' => true,
                'calendar' => $calendarData,
                'list' => $attendanceList
            ]);
            break;

        case 'payments':
            $payment = new Payment($db, $gender);
            $payments = $payment->getByMemberId($member['id']);
            
            echo json_encode([
                'success' => true,
                'data' => $payments
            ]);
            break;

        default:
            // Return full profile with all data
            $attendance = new Attendance($db, $gender);
            $payment = new Payment($db, $gender);
            
            $year = date('Y');
            $month = date('m');
            $calendarData = $attendance->getCalendarData($member['id'], $year, $month);
            $payments = $payment->getByMemberId($member['id']);
            
            // Check if member is a defaulter (hasn't paid in last 30 days)
            $oneMonthAgo = date('Y-m-d', strtotime('-1 month'));
            $defaulterQuery = "SELECT MAX(payment_date) as last_payment_date 
                              FROM payments_{$gender} 
                              WHERE member_id = :member_id";
            $defaulterStmt = $db->prepare($defaulterQuery);
            $defaulterStmt->bindValue(':member_id', $member['id'], PDO::PARAM_INT);
            $defaulterStmt->execute();
            $lastPayment = $defaulterStmt->fetch();
            $memberTable = 'members_' . $gender;
            $joinDateColumn = resolve_member_date_column($db, $memberTable);
            $lastPaymentDate = $lastPayment['last_payment_date'] ?? ($member[$joinDateColumn] ?? date('Y-m-d'));
            $daysSincePayment = (strtotime(date('Y-m-d')) - strtotime($lastPaymentDate)) / (60 * 60 * 24);
            $isDefaulter = ($member['status'] === 'active' && $daysSincePayment >= 30);
            
            echo json_encode([
                'success' => true,
                'profile' => $member,
                'gender' => $gender,
                'is_defaulter' => $isDefaulter,
                'default_date' => $member['next_fee_due_date'] ?? null,
                'payments' => $payments,
                'attendance' => [
                    'calendar' => $calendarData,
                    'year' => $year,
                    'month' => $month
                ]
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

