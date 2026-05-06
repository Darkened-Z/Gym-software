<?php
/**
 * Production-Hardened Dual-Gate RFID System API
 * Version: 2.0 - Production Ready
 * 
 * Features:
 * - Cooldown window prevention
 * - Database transactions
 * - Rate limiting
 * - Session timeout recovery
 * - Comprehensive error handling
 * - Admin override support
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/helpers/Cache.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';
require_once __DIR__ . '/../app/services/AttendanceWriteService.php';

header('Content-Type: application/json');

// Maintenance mode check
if (env_bool('MAINTENANCE_MODE', false)) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'action' => 'deny',
        'message' => env('MAINTENANCE_MESSAGE', 'System under maintenance')
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$type = $_GET['type'] ?? '';
$rfidUid = trim($_GET['rfid_uid'] ?? '');
$gateId = trim($_GET['gate_id'] ?? ($_GET['gate'] ?? ''));

try {
    $database = new Database();
    $db = $database->getConnection();
    $adminLogger = new AdminLogger($db);
    
    // ========================================================================
    // PRODUCTION RATE LIMITING
    // ========================================================================
    
    /**
     * Check and enforce rate limiting for gates
     */
    function checkRateLimit($gateId) {
        $cacheKey = 'gate_rate_limit_' . $gateId;
        $requests = Cache::increment($cacheKey, RATE_LIMIT_GATE_WINDOW);
        
        if ($requests > RATE_LIMIT_GATE_MAX) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'action' => 'deny',
                'message' => 'Rate limit exceeded. Please wait.',
                'gate_open_duration' => 0
            ]);
            exit;
        }
    }
    
    // ========================================================================
    // COOLDOWN WINDOW CHECK
    // ========================================================================
    
    /**
     * Check cooldown window to prevent duplicate scans
     * Returns true if within cooldown, false if scan allowed
     */
    function checkCooldown($db, $gateId, $rfidUid, $cooldownSeconds) {
        $query = "SELECT last_scan
                  FROM gate_cooldown
                  WHERE gate_id = :gate_id AND rfid_uid = :rfid_uid
                  AND TIMESTAMPDIFF(SECOND, last_scan, NOW()) < :cooldown";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':gate_id', $gateId, PDO::PARAM_STR);
        $stmt->bindValue(':rfid_uid', $rfidUid, PDO::PARAM_STR);
        $stmt->bindValue(':cooldown', $cooldownSeconds, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Update cooldown timestamp
     */
    function updateCooldown($db, $gateId, $rfidUid) {
        $query = "INSERT INTO gate_cooldown (gate_id, rfid_uid, last_scan)
                  VALUES (:gate_id, :rfid_uid, NOW())
                  ON DUPLICATE KEY UPDATE last_scan = NOW()";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':gate_id', $gateId, PDO::PARAM_STR);
        $stmt->bindValue(':rfid_uid', $rfidUid, PDO::PARAM_STR);
        $stmt->execute();
    }
    
    // ========================================================================
    // HELPER FUNCTIONS
    // ========================================================================
    
    /**
     * Find member by RFID UID (searches both genders)
     */
    function findMemberByRFID($db, $rfidUid) {
        $memberModel = new Member($db, 'men');
        $member = $memberModel->getByRfidUid($rfidUid);
        
        if ($member) {
            return ['member' => $member, 'gender' => 'men'];
        }
        
        $memberModel = new Member($db, 'women');
        $member = $memberModel->getByRfidUid($rfidUid);
        
        if ($member) {
            return ['member' => $member, 'gender' => 'women'];
        }
        
        return null;
    }
    
    /**
     * Log gate activity with comprehensive details
     */
    function logGateActivity($db, $data) {
        $query = "INSERT INTO gate_activity_log 
                  (gate_type, gate_id, rfid_uid, member_id, gender, member_name, 
                   action, status, reason, is_fee_defaulter) 
                  VALUES 
                  (:gate_type, :gate_id, :rfid_uid, :member_id, :gender, :member_name,
                   :action, :status, :reason, :is_fee_defaulter)";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':gate_type', $data['gate_type'], PDO::PARAM_STR);
        $stmt->bindValue(':gate_id', $data['gate_id'], PDO::PARAM_STR);
        $stmt->bindValue(':rfid_uid', $data['rfid_uid'], PDO::PARAM_STR);
        $stmt->bindValue(':member_id', $data['member_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':gender', $data['gender'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':member_name', $data['member_name'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':action', $data['action'], PDO::PARAM_STR);
        $stmt->bindValue(':status', $data['status'], PDO::PARAM_STR);
        $stmt->bindValue(':reason', $data['reason'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':is_fee_defaulter', $data['is_fee_defaulter'] ?? 0, PDO::PARAM_INT);
        $stmt->execute();
    }
    
    // ========================================================================
    // ROUTE HANDLING
    // ========================================================================
    
    switch ($type) {
        
        // ====================================================================
        // ENTRY GATE - Production Hardened
        // ====================================================================
        case 'entry':
            checkRateLimit($gateId);
            
            // Validate inputs
            if (empty($rfidUid)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'RFID UID required',
                    'gate_open_duration' => 0
                ]);
                exit;
            }
            
            // Check cooldown window
            if (checkCooldown($db, $gateId, $rfidUid, GATE_ENTRY_COOLDOWN)) {
                logGateActivity($db, [
                    'gate_type' => 'entry',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'action' => 'scan',
                    'status' => 'denied',
                    'reason' => 'Cooldown window - duplicate scan within ' . GATE_ENTRY_COOLDOWN . ' seconds'
                ]);
                
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'Please wait a few seconds before scanning again',
                    'gate_open_duration' => 0
                ]);
                exit;
            }
            
            // Find member by RFID
            $result = findMemberByRFID($db, $rfidUid);
            
            if (!$result) {
                logGateActivity($db, [
                    'gate_type' => 'entry',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'action' => 'check-in_attempt',
                    'status' => 'denied',
                    'reason' => 'RFID not registered in system'
                ]);
                
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'RFID card not registered. Please contact reception.',
                    'gate_open_duration' => 0
                ]);
                exit;
            }
            
            $member = $result['member'];
            $gender = $result['gender'];
            $membersTable = "members_{$gender}";
            $attendanceService = new AttendanceWriteService($db, $gender);
            
            // Check member status
            $effectiveStatus = $member['calculated_status'] ?? $member['status'] ?? 'inactive';
            if ($effectiveStatus !== 'active') {
                logGateActivity($db, [
                    'gate_type' => 'entry',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'member_id' => $member['id'],
                    'gender' => $gender,
                    'member_name' => $member['name'],
                    'action' => 'check-in_attempt',
                    'status' => 'denied',
                    'reason' => 'Membership inactive'
                ]);
                
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'Your membership is inactive. Please renew at reception.',
                    'gate_open_duration' => 0
                ]);
                exit;
            }
            
            // Fee defaulter check - CRITICAL FOR PRODUCTION
            $isDefaulter = floatval($member['total_due_amount'] ?? 0) > 0;
            
            if ($isDefaulter) {
                logGateActivity($db, [
                    'gate_type' => 'entry',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'member_id' => $member['id'],
                    'gender' => $gender,
                    'member_name' => $member['name'],
                    'action' => 'check-in_attempt',
                    'status' => 'denied',
                    'reason' => 'Fee payment pending: Rs. ' . number_format($member['total_due_amount'], 2),
                    'is_fee_defaulter' => 1
                ]);
                
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'Fee payment pending: Rs. ' . number_format($member['total_due_amount'], 2) . '. Please pay at reception.',
                    'due_amount' => $member['total_due_amount'],
                    'gate_open_duration' => 0
                ]);
                exit;
            }
            
            // START TRANSACTION - Critical for data integrity
            $db->beginTransaction();
            
            try {
                // Lock member row to prevent race conditions
                $query = "SELECT id, is_checked_in FROM {$membersTable} WHERE id = :id FOR UPDATE";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id', $member['id'], PDO::PARAM_INT);
                $stmt->execute();
                $lockedMember = $stmt->fetch();
                
                // Check if already checked in (re-entry handling)
                if ($lockedMember['is_checked_in'] == 1) {
                    // Re-entry allowed but logged separately
                    logGateActivity($db, [
                        'gate_type' => 'entry',
                        'gate_id' => $gateId,
                        'rfid_uid' => $rfidUid,
                        'member_id' => $member['id'],
                        'gender' => $gender,
                        'member_name' => $member['name'],
                        'action' => 're-entry',
                        'status' => 'success',
                        'reason' => 'Re-entry allowed (already checked in)'
                    ]);
                    
                    $db->commit();
                    updateCooldown($db, $gateId, $rfidUid);
                    
                    echo json_encode([
                        'success' => true,
                        'action' => 'open',
                        'message' => 'Re-entry allowed. Welcome back, ' . $member['name'] . '!',
                        'member' => [
                            'name' => $member['name'],
                            'member_code' => $member['member_code'],
                            'is_re_entry' => true
                        ],
                        'gate_open_duration' => GATE_OPEN_DURATION
                    ]);
                    exit;
                }
                
                $attendanceResult = $attendanceService->recordCheckIn((int)$member['id'], [
                    'source' => 'gate-entry',
                    'gate_id' => $gateId,
                ]);

                if (empty($attendanceResult['success'])) {
                    $db->rollBack();
                    logGateActivity($db, [
                        'gate_type' => 'entry',
                        'gate_id' => $gateId,
                        'rfid_uid' => $rfidUid,
                        'member_id' => $member['id'],
                        'gender' => $gender,
                        'member_name' => $member['name'],
                        'action' => 'check-in_attempt',
                        'status' => 'error',
                        'reason' => $attendanceResult['message'] ?? 'Attendance write failed'
                    ]);

                    echo json_encode([
                        'success' => false,
                        'action' => 'deny',
                        'message' => 'System error. Please contact reception.',
                        'gate_open_duration' => 0
                    ]);
                    exit;
                }

                $isFirstEntry = (int)($attendanceResult['attendance']['is_first_entry_today'] ?? 0) === 1;
                
                // Log successful entry
                logGateActivity($db, [
                    'gate_type' => 'entry',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'member_id' => $member['id'],
                    'gender' => $gender,
                    'member_name' => $member['name'],
                    'action' => 'check-in',
                    'status' => 'success',
                    'reason' => $isFirstEntry ? 'First entry of the day' : 'Re-entry after previous check-out'
                ]);
                
                $db->commit();
                updateCooldown($db, $gateId, $rfidUid);
                
                $greeting = $isFirstEntry 
                    ? "Welcome to the gym, {$member['name']}! Have a great workout!" 
                    : "Welcome back, {$member['name']}!";
                
                echo json_encode([
                    'success' => true,
                    'action' => 'open',
                    'message' => $greeting,
                    'member' => [
                        'name' => $member['name'],
                        'member_code' => $member['member_code'],
                        'is_first_entry_today' => $isFirstEntry
                    ],
                    'gate_open_duration' => GATE_OPEN_DURATION
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                
                logGateActivity($db, [
                    'gate_type' => 'entry',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'member_id' => $member['id'],
                    'gender' => $gender,
                    'member_name' => $member['name'],
                    'action' => 'check-in_attempt',
                    'status' => 'error',
                    'reason' => 'Database error: ' . $e->getMessage()
                ]);
                
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'System error. Please contact reception.',
                    'gate_open_duration' => 0
                ]);
            }
            break;
        
        // ====================================================================
        // EXIT GATE - Production Hardened
        // ====================================================================
        case 'exit':
            checkRateLimit($gateId);
            
            //Validate inputs
            if (empty($rfidUid)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'RFID UID required',
                    'gate_open_duration' => 0
                ]);
                exit;
            }
            
            // Check cooldown window
            if (checkCooldown($db, $gateId, $rfidUid, GATE_EXIT_COOLDOWN)) {
                logGateActivity($db, [
                    'gate_type' => 'exit',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'action' => 'scan',
                    'status' => 'denied',
                    'reason' => 'Cooldown window - duplicate scan within ' . GATE_EXIT_COOLDOWN . ' seconds'
                ]);
                
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'Please wait a few seconds before scanning again',
                    'gate_open_duration' => 0
                ]);
                exit;
            }
            
            // Find member by RFID
            $result = findMemberByRFID($db, $rfidUid);
            
            if (!$result) {
                logGateActivity($db, [
                    'gate_type' => 'exit',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'action' => 'check-out_attempt',
                    'status' => 'denied',
                    'reason' => 'RFID not registered in system'
                ]);
                
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'RFID card not registered. Please contact reception.',
                    'gate_open_duration' => 0
                ]);
                exit;
            }
            
            $member = $result['member'];
            $gender = $result['gender'];
            $membersTable = "members_{$gender}";
            
            // Check if member is checked in
            if ($member['is_checked_in'] != 1) {
                logGateActivity($db, [
                    'gate_type' => 'exit',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'member_id' => $member['id'],
                    'gender' => $gender,
                    'member_name' => $member['name'],
                    'action' => 'check-out_attempt',
                    'status' => 'denied',
                    'reason' => 'Not checked in - must use entry gate first'
                ]);
                
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'You are not checked in. Please use the entry gate first.',
                    'gate_open_duration' => 0
                ]);
                exit;
            }
            
            // START TRANSACTION
            $db->beginTransaction();
            
            try {
                // Lock member row
                $query = "SELECT id, is_checked_in FROM {$membersTable} WHERE id = :id FOR UPDATE";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id', $member['id'], PDO::PARAM_INT);
                $stmt->execute();
                $lockedMember = $stmt->fetch();
                
                // Double-check still checked in (preventing race conditions)
                if ($lockedMember['is_checked_in'] != 1) {
                    $db->rollBack();
                    
                    logGateActivity($db, [
                        'gate_type' => 'exit',
                        'gate_id' => $gateId,
                        'rfid_uid' => $rfidUid,
                        'member_id' => $member['id'],
                        'gender' => $gender,
                        'member_name' => $member['name'],
                        'action' => 'check-out_attempt',
                        'status' => 'denied',
                        'reason' => 'Check-in status changed during processing'
                    ]);
                    
                    echo json_encode([
                        'success' => false,
                        'action' => 'deny',
                        'message' => 'System error. Please try again.',
                        'gate_open_duration' => 0
                    ]);
                    exit;
                }
                
                $attendanceResult = $attendanceService->recordCheckoutByMemberId((int)$member['id'], [
                    'source' => 'gate-exit',
                    'gate_id' => $gateId,
                ]);

                if (empty($attendanceResult['success'])) {
                    $db->rollBack();
                    logGateActivity($db, [
                        'gate_type' => 'exit',
                        'gate_id' => $gateId,
                        'rfid_uid' => $rfidUid,
                        'member_id' => $member['id'],
                        'gender' => $gender,
                        'member_name' => $member['name'],
                        'action' => 'check-out_attempt',
                        'status' => 'error',
                        'reason' => $attendanceResult['message'] ?? 'Attendance write failed'
                    ]);

                    echo json_encode([
                        'success' => false,
                        'action' => 'deny',
                        'message' => $attendanceResult['message'] ?? 'System error. Please contact reception.',
                        'gate_open_duration' => 0
                    ]);
                    exit;
                }

                $attendance = $attendanceResult['attendance'] ?? [];
                $durationMinutes = (int)($attendanceResult['duration_minutes'] ?? $attendance['duration_minutes'] ?? 0);
                
                // Log successful exit
                logGateActivity($db, [
                    'gate_type' => 'exit',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'member_id' => $member['id'],
                    'gender' => $gender,
                    'member_name' => $member['name'],
                    'action' => 'check-out',
                    'status' => 'success',
                    'reason' => 'Workout duration: ' . floor($durationMinutes / 60) . 'h ' . ($durationMinutes % 60) . 'm'
                ]);
                
                $db->commit();
                updateCooldown($db, $gateId, $rfidUid);
                
                // Format duration  message
                $hours = intdiv($durationMinutes, 60);
                $minutes = $durationMinutes % 60;
                $durationText = '';
                if ($hours > 0) {
                    $durationText .= $hours . ' hour' . ($hours > 1 ? 's' : '');
                }
                if ($minutes > 0 || $durationText === '') {
                    if ($hours > 0) $durationText .= ' ';
                    $durationText .= $minutes . ' minute' . ($minutes > 1 ? 's' : '');
                }

                echo json_encode([
                    'success' => true,
                    'action' => 'open',
                    'message' => "Goodbye, {$member['name']}! You worked out for {$durationText}. Great job!",
                    'member' => [
                        'name' => $member['name'],
                        'member_code' => $member['member_code'],
                        'check_in_time' => $attendance['check_in'] ?? null,
                        'duration' => $durationText,
                        'duration_minutes' => $durationMinutes
                    ],
                    'gate_open_duration' => GATE_OPEN_DURATION
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                
                logGateActivity($db, [
                    'gate_type' => 'exit',
                    'gate_id' => $gateId,
                    'rfid_uid' => $rfidUid,
                    'member_id' => $member['id'],
                    'gender' => $gender,
                    'member_name' => $member['name'],
                    'action' => 'check-out_attempt',
                    'status' => 'error',
                    'reason' => 'Database error: ' . $e->getMessage()
                ]);
                
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'action' => 'deny',
                    'message' => 'System error. Please contact reception.',
                    'gate_open_duration' => 0
                ]);
            }
            break;
        
        // ====================================================================
        // ADMIN FORCE OPEN
        // ====================================================================
        case 'force_open':
            AuthHelper::requireAdmin();

            $resolvedGateId = $gateId !== '' ? $gateId : 'manual_override';
            $adminLogger->log('gate_force_open', 'gate', null, null, [
                'gate_id' => $resolvedGateId,
                'source' => 'dashboard_override'
            ]);
            $resolvedGateType = stripos($resolvedGateId, 'exit') !== false ? 'exit' : 'entry';
            logGateActivity($db, [
                'gate_type' => $resolvedGateType,
                'gate_id' => $resolvedGateId,
                'rfid_uid' => 'ADMIN_OVERRIDE',
                'action' => 'force_open',
                'status' => 'success',
                'reason' => 'Admin override by ' . ($_SESSION['username'] ?? 'admin'),
                'is_fee_defaulter' => 0
            ]);

            echo json_encode([
                'success' => true,
                'action' => 'open',
                'message' => 'Admin override - Gate opened',
                'gate_open_duration' => GATE_OPEN_DURATION
            ]);
            break;
        
        // ====================================================================
        // HEALTH CHECK (No rate limiting)
        // ====================================================================
        case 'health':
            echo json_encode([
                'status' => 'ok',
                'timestamp' => date('Y-m-d H:i:s'),
                'gate_system' => 'online',
                'version' => '2.0'
            ]);
            break;
        
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'action' => 'deny',
                'message' => 'Invalid request type',
                'gate_open_duration' => 0
            ]);
    }
    
} catch (Exception $e) {
    // Log critical errors
    error_log('[GATE API] Critical Error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'action' => 'deny',
        'message' => DEBUG_MODE ? $e->getMessage() : 'System error. Gate denied for safety.',
        'gate_open_duration' => 0
    ]);
}
