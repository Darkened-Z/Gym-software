<?php
/**
 * Admin Override Controls API
 * Allows admins to manually control gate access and member status
 * All actions are logged with mandatory reasons
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Check authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$requestData = $_POST;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $rawInput = file_get_contents('php://input');
    if (is_string($rawInput) && trim($rawInput) !== '') {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            $requestData = array_merge($requestData, $jsonData);
        }
    }
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    /**
     * Log admin action to database
     */
    function logAdminAction($db, $adminId, $adminUsername, $action, $targetType, $targetId, $reason, $details = null) {
        $query = "INSERT INTO admin_action_log 
                  (admin_id, admin_username, action, target_type, target_id, reason, details, ip_address)
                  VALUES 
                  (:admin_id, :admin_username, :action, :target_type, :target_id, :reason, :details, :ip)";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':admin_id', $adminId, PDO::PARAM_INT);
        $stmt->bindValue(':admin_username', $adminUsername, PDO::PARAM_STR);
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':target_type', $targetType, PDO::PARAM_STR);
        $stmt->bindValue(':target_id', $targetId, PDO::PARAM_INT);
        $stmt->bindValue(':reason', $reason, PDO::PARAM_STR);
        $stmt->bindValue(':details', $details ? json_encode($details) : null, PDO::PARAM_STR);
        $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? 'unknown', PDO::PARAM_STR);
        $stmt->execute();
    }
    
    switch ($action) {
        
        // ====================================================================
        // FORCE CHECK-IN
        // ====================================================================
        case 'force_checkin':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $memberId = (int)($requestData['member_id'] ?? 0);
            $gender = $requestData['gender'] ?? '';
            $reason = trim($requestData['reason'] ?? '');
            
            // Validate inputs
            if (!$memberId || !in_array($gender, ['men', 'women'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid member ID or gender']);
                exit;
            }
            
            // Validate reason
            $minReasonLength = (int)env('ADMIN_OVERRIDE_REASON_MIN_LENGTH', 10);
            if (strlen($reason) < $minReasonLength) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => "Reason required (minimum {$minReasonLength} characters)"
                ]);
                exit;
            }
            
            $membersTable = "members_{$gender}";
            $attendanceTable = "attendance_{$gender}";
            
            $db->beginTransaction();
            
            try {
                // Get member details
                $query = "SELECT id, name, member_code FROM {$membersTable} WHERE id = :id FOR UPDATE";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id', $memberId, PDO::PARAM_INT);
                $stmt->execute();
                $member = $stmt->fetch();
                
                if (!$member) {
                    throw new Exception('Member not found');
                }
                
                // Create attendance record
                $query = "INSERT INTO {$attendanceTable} 
                          (member_id, check_in, is_first_entry_today, entry_gate_id) 
                          VALUES 
                          (:member_id, NOW(), 1, 'ADMIN_OVERRIDE')";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
                $stmt->execute();
                
                // Update member status
                $query = "UPDATE {$membersTable} SET is_checked_in = 1 WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id', $memberId, PDO::PARAM_INT);
                $stmt->execute();
                
                // Log admin action
                logAdminAction(
                    $db,
                    $_SESSION['user_id'],
                    $_SESSION['username'] ?? 'admin',
                    'force_checkin',
                    'member',
                    $memberId,
                    $reason,
                    [
                        'member_code' => $member['member_code'],
                        'member_name' => $member['name'],
                        'gender' => $gender
                    ]
                );
                
                $db->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => "Force check-in successful for {$member['name']}",
                    'member' => [
                        'name' => $member['name'],
                        'member_code' => $member['member_code']
                    ],
                    'gate_open_duration' => 3000 // Trigger gate open
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
        
        // ====================================================================
        // FORCE CHECK-OUT
        // ====================================================================
        case 'force_checkout':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $memberId = (int)($requestData['member_id'] ?? 0);
            $gender = $requestData['gender'] ?? '';
            $reason = trim($requestData['reason'] ?? '');
            
            // Validate
            if (!$memberId || !in_array($gender, ['men', 'women'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid inputs']);
                exit;
            }
            
            $minReasonLength = (int)env('ADMIN_OVERRIDE_REASON_MIN_LENGTH', 10);
            if (strlen($reason) < $minReasonLength) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => "Reason required (minimum {$minReasonLength} characters)"
                ]);
                exit;
            }
            
            $membersTable = "members_{$gender}";
            $attendanceTable = "attendance_{$gender}";
            
            $db->beginTransaction();
            
            try {
                // Get member
                $query = "SELECT id, name, member_code FROM {$membersTable} WHERE id = :id FOR UPDATE";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id', $memberId, PDO::PARAM_INT);
                $stmt->execute();
                $member = $stmt->fetch();
                
                if (!$member) {
                    throw new Exception('Member not found');
                }
                
                // Find active session
                $query = "SELECT id, check_in FROM {$attendanceTable} 
                          WHERE member_id = :member_id AND check_out IS NULL
                          ORDER BY check_in DESC LIMIT 1";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
                $stmt->execute();
                $attendance = $stmt->fetch();
                
                if ($attendance) {
                    // Calculate duration
                    $checkIn = new DateTime($attendance['check_in']);
                    $now = new DateTime();
                    $interval = $checkIn->diff($now);
                    $durationMinutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
                    
                    // Update attendance
                    $query = "UPDATE {$attendanceTable} 
                              SET check_out = NOW(), 
                                  duration_minutes = :duration,
                                  exit_gate_id = 'ADMIN_OVERRIDE'
                              WHERE id = :id";
                    $stmt = $db->prepare($query);
                    $stmt->bindValue(':duration', $durationMinutes, PDO::PARAM_INT);
                    $stmt->bindValue(':id', $attendance['id'], PDO::PARAM_INT);
                    $stmt->execute();
                }
                
                // Update member status
                $query = "UPDATE {$membersTable} SET is_checked_in = 0 WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id', $memberId, PDO::PARAM_INT);
                $stmt->execute();
                
                // Log action
                logAdminAction(
                    $db,
                    $_SESSION['user_id'],
                    $_SESSION['username'] ?? 'admin',
                    'force_checkout',
                    'member',
                    $memberId,
                    $reason,
                    [
                        'member_code' => $member['member_code'],
                        'member_name' => $member['name'],
                        'gender' => $gender,
                        'had_active_session' => $attendance ? true : false
                    ]
                );
                
                $db->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => "Force check-out successful for {$member['name']}",
                    'member' => [
                        'name' => $member['name'],
                        'member_code' => $member['member_code']
                    ],
                    'gate_open_duration' => 3000 // Trigger gate open
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
        
        // ====================================================================
        // TEMPORARY GATE UNLOCK (30 seconds - no validation)
        // ====================================================================
        case 'temp_unlock_gate':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $gateId = $requestData['gate_id'] ?? '';
            $reason = trim($requestData['reason'] ?? '');
            
            $minReasonLength = (int)env('ADMIN_OVERRIDE_REASON_MIN_LENGTH', 10);
            if (strlen($reason) < $minReasonLength) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => "Reason required (minimum {$minReasonLength} characters)"
                ]);
                exit;
            }
            
            // Create temporary unlock token (cached for 30 seconds)
            require_once __DIR__ . '/../app/helpers/Cache.php';
            
            $unlockToken = bin2hex(random_bytes(16));
            Cache::set("gate_unlock_{$gateId}", $unlockToken, 30);
            
            // Log action
            logAdminAction(
                $db,
                $_SESSION['user_id'],
                $_SESSION['username'] ?? 'admin',
                'temp_unlock_gate',
                'gate',
                0,
                $reason,
                ['gate_id' => $gateId, 'duration' => 30]
            );
            
            echo json_encode([
                'success' => true,
                'message' => "Gate {$gateId} unlocked for 30 seconds",
                'unlock_token' => $unlockToken,
                'expires_in' => 30
            ]);
            break;
        
        // ====================================================================
        // GET ADMIN ACTION LOG
        // ====================================================================
        case 'get_log':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $limit = min((int)($_GET['limit'] ?? 50), 100);
            $offset = (int)($_GET['offset'] ?? 0);
            
            $query = "SELECT * FROM admin_action_log 
                      ORDER BY created_at DESC 
                      LIMIT :limit OFFSET :offset";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'count' => count($logs)
            ]);
            break;
        
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => DEBUG_MODE ? $e->getMessage() : 'Server error'
    ]);
}
