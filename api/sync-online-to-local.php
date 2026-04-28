<?php
/**
 * Reverse Sync Script (Online to Local)
 * Reads data from online database and saves to local database
 * This runs on the online server
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Payment.php';
require_once __DIR__ . '/../app/models/Expense.php';
require_once __DIR__ . '/../app/models/Attendance.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Note: Reverse sync from online to local cannot directly connect to local database from online server
// This endpoint exports data that can be downloaded and imported locally
// For actual reverse sync, use a local script that connects to online database

try {
    // Connect to online database (current database)
    $onlineDatabase = new Database();
    $onlineDb = $onlineDatabase->getConnection();
    
    // Check if we're on local environment - if so, we can sync to local database
    $isLocal = $onlineDatabase->isLocal();
    
    // Allow optional local database connection parameters via POST/GET
    // This allows reverse sync even when running from online server if local DB is accessible
    $local_host = $_POST['local_host'] ?? $_GET['local_host'] ?? 'localhost';
    $local_db_name = $_POST['local_db_name'] ?? $_GET['local_db_name'] ?? 'u124112239_gym';
    $local_username = $_POST['local_username'] ?? $_GET['local_username'] ?? 'root';
    $local_password = $_POST['local_password'] ?? $_GET['local_password'] ?? '';
    
    // If not detected as local, use default local XAMPP settings
    if (!$isLocal) {
        // Try default local XAMPP settings
        $local_host = 'localhost';
        $local_db_name = 'gym_management';
        $local_username = 'root';
        $local_password = '';
    }
    
    $localDb = null;
    $connectionError = null;
    
    // Try to connect to local database
    try {
        $localDb = new PDO(
            "mysql:host={$local_host};dbname={$local_db_name};charset=utf8mb4",
            $local_username,
            $local_password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5 // 5 second timeout
            ]
        );
    } catch (PDOException $e) {
        $connectionError = $e->getMessage();
        // If we're not on local and connection fails, provide helpful error
        if (!$isLocal) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot connect to local database from online server.',
                'error' => $connectionError,
                'note' => 'Reverse sync requires access to your local database. This typically only works when:',
                'solutions' => [
                    '1. Run this script from your local environment (localhost)',
                    '2. Ensure your local database is accessible from the network (not recommended for security)',
                    '3. Use the export/download features in the Import/Export section to manually transfer data',
                    '4. If you have VPN access, ensure the local database connection details are correct'
                ],
                'local_db_config' => [
                    'host' => $local_host,
                    'database' => $local_db_name,
                    'username' => $local_username
                ]
            ]);
            exit;
        } else {
            throw new Exception('Cannot connect to local database. Make sure MySQL is running and the database exists. Error: ' . $connectionError);
        }
    }
    
    // Create sync session
    $sessionQuery = "INSERT INTO sync_sessions (session_type, status) VALUES (:type, 'running')";
    $sessionStmt = $onlineDb->prepare($sessionQuery);
    $sessionType = $_GET['type'] ?? 'manual';
    $sessionStmt->bindValue(':type', 'reverse_' . $sessionType, PDO::PARAM_STR);
    $sessionStmt->execute();
    $sessionId = $onlineDb->lastInsertId();
    
    $totalSynced = 0;
    $totalFailed = 0;
    $allErrors = [];
    
    // Sync Members (Men)
    $menSynced = syncTableReverse($onlineDb, $localDb, 'members_men', 'members_men');
    $totalSynced += $menSynced['synced'];
    $totalFailed += $menSynced['failed'];
    $allErrors = array_merge($allErrors, $menSynced['errors']);
    
    // Sync Members (Women)
    $womenSynced = syncTableReverse($onlineDb, $localDb, 'members_women', 'members_women');
    $totalSynced += $womenSynced['synced'];
    $totalFailed += $womenSynced['failed'];
    $allErrors = array_merge($allErrors, $womenSynced['errors']);
    
    // Sync Payments (Men)
    $paymentsMenSynced = syncTableReverse($onlineDb, $localDb, 'payments_men', 'payments_men');
    $totalSynced += $paymentsMenSynced['synced'];
    $totalFailed += $paymentsMenSynced['failed'];
    $allErrors = array_merge($allErrors, $paymentsMenSynced['errors']);
    
    // Sync Payments (Women)
    $paymentsWomenSynced = syncTableReverse($onlineDb, $localDb, 'payments_women', 'payments_women');
    $totalSynced += $paymentsWomenSynced['synced'];
    $totalFailed += $paymentsWomenSynced['failed'];
    $allErrors = array_merge($allErrors, $paymentsWomenSynced['errors']);
    
    // Sync Expenses
    $expensesSynced = syncTableReverse($onlineDb, $localDb, 'expenses', 'expenses');
    $totalSynced += $expensesSynced['synced'];
    $totalFailed += $expensesSynced['failed'];
    $allErrors = array_merge($allErrors, $expensesSynced['errors']);
    
    // Sync Attendance (Men)
    $attendanceMenSynced = syncTableReverse($onlineDb, $localDb, 'attendance_men', 'attendance_men');
    $totalSynced += $attendanceMenSynced['synced'];
    $totalFailed += $attendanceMenSynced['failed'];
    $allErrors = array_merge($allErrors, $attendanceMenSynced['errors']);
    
    // Sync Attendance (Women)
    $attendanceWomenSynced = syncTableReverse($onlineDb, $localDb, 'attendance_women', 'attendance_women');
    $totalSynced += $attendanceWomenSynced['synced'];
    $totalFailed += $attendanceWomenSynced['failed'];
    $allErrors = array_merge($allErrors, $attendanceWomenSynced['errors']);
    
    // Update sync session
    $updateSessionQuery = "UPDATE sync_sessions SET 
                          status = :status,
                          completed_at = NOW(),
                          records_synced = :synced,
                          records_failed = :failed
                          WHERE id = :id";
    $updateSessionStmt = $onlineDb->prepare($updateSessionQuery);
    $status = $totalFailed > 0 ? 'failed' : 'completed';
    $updateSessionStmt->bindValue(':status', $status, PDO::PARAM_STR);
    $updateSessionStmt->bindValue(':synced', $totalSynced, PDO::PARAM_INT);
    $updateSessionStmt->bindValue(':failed', $totalFailed, PDO::PARAM_INT);
    $updateSessionStmt->bindValue(':id', $sessionId, PDO::PARAM_INT);
    $updateSessionStmt->execute();
    
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'total_synced' => $totalSynced,
        'total_failed' => $totalFailed,
        'errors' => $allErrors,
        'message' => "Reverse sync completed: {$totalSynced} records synced to local, {$totalFailed} failed"
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Reverse sync error: ' . $e->getMessage()
    ]);
}

function syncTableReverse($onlineDb, $localDb, $tableName, $tableType) {
    $synced = 0;
    $failed = 0;
    $errors = [];
    
    try {
        // Get all records from online database
        $query = "SELECT * FROM {$tableName} ORDER BY id DESC LIMIT 1000";
        $stmt = $onlineDb->prepare($query);
        $stmt->execute();
        $records = $stmt->fetchAll();
        
        if (empty($records)) {
            return ['synced' => 0, 'failed' => 0, 'errors' => []];
        }
        
        foreach ($records as $record) {
            try {
                // Handle member_id references for payments and attendance
                if (strpos($tableName, 'payments_') === 0 || strpos($tableName, 'attendance_') === 0) {
                    // Get member_code from online database
                    $gender = str_replace(['payments_', 'attendance_'], '', $tableName);
                    $memberQuery = "SELECT member_code FROM members_{$gender} WHERE id = :member_id LIMIT 1";
                    $memberStmt = $onlineDb->prepare($memberQuery);
                    $memberStmt->bindValue(':member_id', $record['member_id'], PDO::PARAM_INT);
                    $memberStmt->execute();
                    $onlineMember = $memberStmt->fetch();
                    
                    if (!$onlineMember) {
                        $failed++;
                        $errors[] = "Member not found for {$tableName} record";
                        continue;
                    }
                    
                    // Find member in local database by member_code
                    $localMemberQuery = "SELECT id FROM members_{$gender} WHERE member_code = :member_code LIMIT 1";
                    $localMemberStmt = $localDb->prepare($localMemberQuery);
                    $localMemberStmt->bindValue(':member_code', $onlineMember['member_code'], PDO::PARAM_STR);
                    $localMemberStmt->execute();
                    $localMember = $localMemberStmt->fetch();
                    
                    if (!$localMember) {
                        $failed++;
                        $errors[] = "Local member not found for code: {$onlineMember['member_code']}";
                        continue;
                    }
                    
                    // Update member_id to local member id
                    $record['member_id'] = $localMember['id'];
                }
                
                // Remove id to allow auto-increment
                $recordData = $record;
                unset($recordData['id']);
                unset($recordData['created_at']);
                unset($recordData['updated_at']);
                
                // Check if record exists in local database
                $checkQuery = buildCheckQuery($tableName, $record);
                $checkStmt = $localDb->prepare($checkQuery);
                bindCheckParams($checkStmt, $tableName, $record);
                $checkStmt->execute();
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    // Update existing record
                    $updateQuery = buildUpdateQuery($tableName, $recordData);
                    $updateStmt = $localDb->prepare($updateQuery);
                    bindUpdateParams($updateStmt, $tableName, $recordData, $existing['id']);
                    $updateStmt->execute();
                } else {
                    // Insert new record
                    $insertQuery = buildInsertQuery($tableName, $recordData);
                    $insertStmt = $localDb->prepare($insertQuery);
                    bindInsertParams($insertStmt, $tableName, $recordData);
                    $insertStmt->execute();
                }
                
                $synced++;
            } catch (Exception $e) {
                $failed++;
                $errors[] = "Error syncing record from {$tableName}: " . $e->getMessage();
            }
        }
    } catch (Exception $e) {
        $errors[] = "Error reading from {$tableName}: " . $e->getMessage();
    }
    
    return [
        'synced' => $synced,
        'failed' => $failed,
        'errors' => $errors
    ];
}

function buildCheckQuery($tableName, $record) {
    if (strpos($tableName, 'members_') === 0) {
        return "SELECT id FROM {$tableName} WHERE member_code = :member_code LIMIT 1";
    } elseif (strpos($tableName, 'payments_') === 0) {
        return "SELECT id FROM {$tableName} WHERE member_id = :member_id AND amount = :amount AND payment_date = :payment_date LIMIT 1";
    } elseif ($tableName === 'expenses') {
        return "SELECT id FROM {$tableName} WHERE expense_type = :expense_type AND amount = :amount AND expense_date = :expense_date LIMIT 1";
    } elseif (strpos($tableName, 'attendance_') === 0) {
        return "SELECT id FROM {$tableName} WHERE member_id = :member_id AND DATE(check_in) = DATE(:check_in) LIMIT 1";
    }
    return "SELECT id FROM {$tableName} WHERE id = :id LIMIT 1";
}

function bindCheckParams($stmt, $tableName, $record) {
    if (strpos($tableName, 'members_') === 0) {
        $stmt->bindValue(':member_code', $record['member_code'], PDO::PARAM_STR);
    } elseif (strpos($tableName, 'payments_') === 0) {
        $stmt->bindValue(':member_id', $record['member_id'], PDO::PARAM_INT);
        $stmt->bindValue(':amount', $record['amount'], PDO::PARAM_STR);
        $stmt->bindValue(':payment_date', $record['payment_date'], PDO::PARAM_STR);
    } elseif ($tableName === 'expenses') {
        $stmt->bindValue(':expense_type', $record['expense_type'], PDO::PARAM_STR);
        $stmt->bindValue(':amount', $record['amount'], PDO::PARAM_STR);
        $stmt->bindValue(':expense_date', $record['expense_date'], PDO::PARAM_STR);
    } elseif (strpos($tableName, 'attendance_') === 0) {
        $stmt->bindValue(':member_id', $record['member_id'], PDO::PARAM_INT);
        $stmt->bindValue(':check_in', $record['check_in'], PDO::PARAM_STR);
    }
}

function buildUpdateQuery($tableName, $data) {
    $fields = array_keys($data);
    $setClause = implode(', ', array_map(function($field) {
        return "{$field} = :{$field}";
    }, $fields));
    
    $idField = 'id';
    if (strpos($tableName, 'members_') === 0) {
        // For members, we need to get the id from the check query result
    }
    
    return "UPDATE {$tableName} SET {$setClause} WHERE id = :id";
}

function bindUpdateParams($stmt, $tableName, $data, $id) {
    foreach ($data as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
}

function buildInsertQuery($tableName, $data) {
    $fields = array_keys($data);
    $placeholders = ':' . implode(', :', $fields);
    $fieldsList = implode(', ', $fields);
    
    return "INSERT INTO {$tableName} ({$fieldsList}) VALUES ({$placeholders})";
}

function bindInsertParams($stmt, $tableName, $data) {
    foreach ($data as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
}

