<?php
/**
 * Attendance Check-in/Check-out API
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Attendance.php';

header('Content-Type: application/json');

// Allow attendance check-in without authentication for member profile lookup
// This enables attendance to be marked when members enter their code on the lookup page
// Security: We validate that the member exists in the database before allowing check-in

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();

    switch ($action) {
        case 'checkin':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $memberId = $data['member_id'] ?? null;
                $gender = $data['gender'] ?? 'men';
                
                // Log for debugging
                error_log("Attendance check-in attempt: member_id={$memberId}, gender={$gender}");
                
                if (!$memberId) {
                    error_log("Attendance check-in failed: Member ID missing");
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Member ID required']);
                    exit;
                }
                
                // Verify member exists FIRST (no status validation - attendance for ALL members)
                // This is our security check - if member doesn't exist, reject the request
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
                    exit;
                }
                
                // NO VALIDATION: Attendance is recorded for ALL members regardless of:
                // - Status (active/inactive)
                // - Outstanding fees (defaulters)
                // - Overdue fees
                // This ensures NO attendance is left behind when a member enters their code
                
                $attendance = new Attendance($db, $gender);
                $checkInTime = date('Y-m-d H:i:s');
                
                // Check if member already checked in today
                $today = date('Y-m-d');
                $existingQuery = "SELECT id, check_in, check_out FROM attendance_{$gender} 
                                 WHERE member_id = :member_id 
                                 AND DATE(check_in) = :date 
                                 AND check_out IS NULL 
                                 ORDER BY check_in DESC LIMIT 1";
                $existingStmt = $db->prepare($existingQuery);
                $existingStmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
                $existingStmt->bindValue(':date', $today, PDO::PARAM_STR);
                $existingStmt->execute();
                $existing = $existingStmt->fetch();
                
                if ($existing) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Member already checked in today. Please check out first.',
                        'attendance_id' => $existing['id']
                    ]);
                    exit;
                }
                
                // Insert check-in
                $query = "INSERT INTO attendance_{$gender} (member_id, check_in) VALUES (:member_id, :check_in)";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
                $stmt->bindValue(':check_in', $checkInTime, PDO::PARAM_STR);
                
                if ($stmt->execute()) {
                    $attendanceId = $db->lastInsertId();
                    
                    // Update member status to checked in
                    $updateStatus = "UPDATE {$memberTable} SET is_checked_in = 1 WHERE id = :id";
                    $statusStmt = $db->prepare($updateStatus);
                    $statusStmt->bindValue(':id', $memberId, PDO::PARAM_INT);
                    $statusStmt->execute();
                    
                    error_log("Attendance check-in successful: member_id={$memberId}, gender={$gender}, attendance_id={$attendanceId}");
                    echo json_encode([
                        'success' => true,
                        'message' => 'Check-in recorded successfully',
                        'attendance_id' => $attendanceId,
                        'check_in' => $checkInTime
                    ]);
                } else {
                    $errorInfo = $stmt->errorInfo();
                    error_log("Attendance check-in failed: SQL error - " . print_r($errorInfo, true));
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to record check-in: Database error']);
                }
            }
            break;
            
        case 'checkout':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $attendanceId = $data['attendance_id'] ?? null;
                $gender = $data['gender'] ?? 'men';
                
                if (!$attendanceId) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Attendance ID required']);
                    exit;
                }
                
                $checkOutTime = date('Y-m-d H:i:s');
                
                // Get check-in time and member_id
                $getQuery = "SELECT member_id, check_in FROM attendance_{$gender} WHERE id = :id";
                $getStmt = $db->prepare($getQuery);
                $getStmt->bindValue(':id', $attendanceId, PDO::PARAM_INT);
                $getStmt->execute();
                $attendance = $getStmt->fetch();
                
                if (!$attendance) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Attendance record not found']);
                    exit;
                }
                
                // Calculate duration
                $checkIn = new DateTime($attendance['check_in']);
                $checkOut = new DateTime($checkOutTime);
                $duration = $checkIn->diff($checkOut);
                $durationMinutes = ($duration->h * 60) + $duration->i;
                
                // Update check-out
                $query = "UPDATE attendance_{$gender} 
                         SET check_out = :check_out, duration_minutes = :duration 
                         WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':check_out', $checkOutTime, PDO::PARAM_STR);
                $stmt->bindValue(':duration', $durationMinutes, PDO::PARAM_INT);
                $stmt->bindValue(':id', $attendanceId, PDO::PARAM_INT);
                
                if ($stmt->execute()) {
                    // Update member status to checked out
                    $memberTable = 'members_' . $gender;
                    $updateStatus = "UPDATE {$memberTable} SET is_checked_in = 0 WHERE id = :id";
                    $statusStmt = $db->prepare($updateStatus);
                    $statusStmt->bindValue(':id', $attendance['member_id'], PDO::PARAM_INT); // Need member_id from select earlier
                    $statusStmt->execute();

                    echo json_encode([
                        'success' => true,
                        'message' => 'Check-out recorded successfully',
                        'check_out' => $checkOutTime,
                        'duration_minutes' => $durationMinutes
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to record check-out']);
                }
            }
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

