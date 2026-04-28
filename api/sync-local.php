<?php
/**
 * Local Sync Script
 * Reads unsynced records from local database and sends to online server
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Payment.php';
require_once __DIR__ . '/../app/models/Expense.php';
require_once __DIR__ . '/../app/models/Attendance.php';

header('Content-Type: application/json');

// Increase execution time and memory limit for large syncs
set_time_limit(1800); // 30 minutes for large datasets
ini_set('memory_limit', '1024M'); // 1GB for large datasets
ini_set('max_execution_time', 1800);

// Check authentication
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Sync target configuration
$onlineServerUrl = rtrim((string)env('ONLINE_SERVER_URL', ''), '/');
$apiKey = (string)env('SYNC_API_KEY', '');

if ($onlineServerUrl === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ONLINE_SERVER_URL is not configured']);
    exit;
}

if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'SYNC_API_KEY is not configured']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $action = $_GET['action'] ?? 'run';
    if ($action === 'failed_records') {
        $limit = max(1, min((int)($_GET['limit'] ?? 50), 200));
        echo json_encode([
            'success' => true,
            'data' => getFailedSyncRecords($db, $limit)
        ]);
        exit;
    }
    
    // Create sync session (if table exists)
    $sessionId = null;
    $retryFailedOnly = isset($_GET['retry_failed']) && $_GET['retry_failed'] == '1';
    $sessionType = $_GET['type'] ?? 'manual';
    if ($retryFailedOnly) {
        $sessionType .= '_retry_failed';
    }
    try {
        $sessionQuery = "INSERT INTO sync_sessions (session_type, status) VALUES (:type, 'running')";
        $sessionStmt = $db->prepare($sessionQuery);
        $sessionStmt->bindValue(':type', $sessionType, PDO::PARAM_STR);
        $sessionStmt->execute();
        $sessionId = $db->lastInsertId();
    } catch (PDOException $e) {
        // Table doesn't exist, continue without session tracking
        error_log("sync_sessions table not found, continuing without session tracking: " . $e->getMessage());
    }
    
    // Check if force sync is requested (ignores sync_log and syncs all records)
    $forceSyncAll = isset($_GET['force']) && $_GET['force'] == '1';
    if ($forceSyncAll) {
        error_log("FORCE SYNC MODE: Will sync ALL records, ignoring sync_log");
    }

    if ($retryFailedOnly) {
        error_log("RETRY FAILED MODE: Will sync only records marked as failed in sync_log");
    }
    
    $totalSynced = 0;
    $totalFailed = 0;
    $allErrors = [];
    
    // Sync profile images first (before syncing members)
    $imagesSynced = ['synced' => 0, 'failed' => 0, 'errors' => []];
    if (!$retryFailedOnly) {
        $imagesSynced = syncProfileImages($db, $onlineServerUrl, $apiKey);
        $totalSynced += $imagesSynced['synced'];
        $totalFailed += $imagesSynced['failed'];
        $allErrors = array_merge($allErrors, $imagesSynced['errors']);
    }
    
    // Sync Members (Men) - MUST sync first so payments can reference them
    $menSynced = syncTable($db, 'members_men', 'members_men', $onlineServerUrl, $apiKey, $forceSyncAll, $retryFailedOnly);
    $totalSynced += $menSynced['synced'];
    $totalFailed += $menSynced['failed'];
    $allErrors = array_merge($allErrors, $menSynced['errors']);
    
    // Sync Members (Women) - MUST sync first so payments can reference them
    $womenSynced = syncTable($db, 'members_women', 'members_women', $onlineServerUrl, $apiKey, $forceSyncAll, $retryFailedOnly);
    $totalSynced += $womenSynced['synced'];
    $totalFailed += $womenSynced['failed'];
    $allErrors = array_merge($allErrors, $womenSynced['errors']);
    
    // Sync Payments (Men) - After members are synced
    $paymentsMenSynced = syncTable($db, 'payments_men', 'payments_men', $onlineServerUrl, $apiKey, $forceSyncAll, $retryFailedOnly);
    $totalSynced += $paymentsMenSynced['synced'];
    $totalFailed += $paymentsMenSynced['failed'];
    $allErrors = array_merge($allErrors, $paymentsMenSynced['errors']);
    
    // Sync Payments (Women) - After members are synced
    $paymentsWomenSynced = syncTable($db, 'payments_women', 'payments_women', $onlineServerUrl, $apiKey, $forceSyncAll, $retryFailedOnly);
    $totalSynced += $paymentsWomenSynced['synced'];
    $totalFailed += $paymentsWomenSynced['failed'];
    $allErrors = array_merge($allErrors, $paymentsWomenSynced['errors']);
    
    // Sync Expenses
    $expensesSynced = syncTable($db, 'expenses', 'expenses', $onlineServerUrl, $apiKey, $forceSyncAll, $retryFailedOnly);
    $totalSynced += $expensesSynced['synced'];
    $totalFailed += $expensesSynced['failed'];
    $allErrors = array_merge($allErrors, $expensesSynced['errors']);
    
    // Sync Attendance (Men)
    $attendanceMenSynced = syncTable($db, 'attendance_men', 'attendance_men', $onlineServerUrl, $apiKey, $forceSyncAll, $retryFailedOnly);
    $totalSynced += $attendanceMenSynced['synced'];
    $totalFailed += $attendanceMenSynced['failed'];
    $allErrors = array_merge($allErrors, $attendanceMenSynced['errors']);
    
    // Sync Attendance (Women)
    $attendanceWomenSynced = syncTable($db, 'attendance_women', 'attendance_women', $onlineServerUrl, $apiKey, $forceSyncAll, $retryFailedOnly);
    $totalSynced += $attendanceWomenSynced['synced'];
    $totalFailed += $attendanceWomenSynced['failed'];
    $allErrors = array_merge($allErrors, $attendanceWomenSynced['errors']);
    
    // Update sync session (if table exists and session was created)
    if ($sessionId) {
        try {
            $updateSessionQuery = "UPDATE sync_sessions SET 
                              status = :status,
                              completed_at = NOW(),
                              records_synced = :synced,
                              records_failed = :failed,
                              error_message = :error_message
                              WHERE id = :id";
            $updateSessionStmt = $db->prepare($updateSessionQuery);
            $status = $totalFailed > 0 ? 'failed' : 'completed';
            $errorMessage = !empty($allErrors) ? implode("\n", array_slice($allErrors, 0, 10)) : null;
            $updateSessionStmt->bindValue(':status', $status, PDO::PARAM_STR);
            $updateSessionStmt->bindValue(':synced', $totalSynced, PDO::PARAM_INT);
            $updateSessionStmt->bindValue(':failed', $totalFailed, PDO::PARAM_INT);
            $updateSessionStmt->bindValue(':error_message', $errorMessage, PDO::PARAM_STR);
            $updateSessionStmt->bindValue(':id', $sessionId, PDO::PARAM_INT);
            $updateSessionStmt->execute();
        } catch (PDOException $e) {
            // Table doesn't exist, ignore
            error_log("sync_sessions table not found for update: " . $e->getMessage());
        }
    }
    
    // Build detailed summary
    $summary = [
        'members_men' => $menSynced['synced'] ?? 0,
        'members_women' => $womenSynced['synced'] ?? 0,
        'payments_men' => $paymentsMenSynced['synced'] ?? 0,
        'payments_women' => $paymentsWomenSynced['synced'] ?? 0,
        'expenses' => $expensesSynced['synced'] ?? 0,
        'attendance_men' => $attendanceMenSynced['synced'] ?? 0,
        'attendance_women' => $attendanceWomenSynced['synced'] ?? 0
    ];
    
    $message = "Sync completed: {$totalSynced} records synced, {$totalFailed} failed. " .
               "Members (Men: {$summary['members_men']}, Women: {$summary['members_women']}), " .
               "Payments (Men: {$summary['payments_men']}, Women: {$summary['payments_women']}), " .
               "Expenses: {$summary['expenses']}, " .
               "Attendance (Men: {$summary['attendance_men']}, Women: {$summary['attendance_women']})";
    
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'retry_failed_only' => $retryFailedOnly,
        'total_synced' => $totalSynced,
        'total_failed' => $totalFailed,
        'errors' => $allErrors,
        'summary' => $summary,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sync error: ' . $e->getMessage()
    ]);
}

function syncLocalValidateDate(?string $value, string $format = 'Y-m-d'): bool {
    if ($value === null || $value === '') {
        return false;
    }

    $parsed = DateTime::createFromFormat($format, $value);
    return $parsed && $parsed->format($format) === $value;
}

function syncPrepareRecordsForSync(PDO $db, string $tableName, array $records): array {
    $valid = [];
    $failed = [];
    $seen = [];

    foreach ($records as $record) {
        $recordId = isset($record['id']) ? (int)$record['id'] : 0;

        try {
            switch ($tableName) {
                case 'members_men':
                case 'members_women':
                    if (empty($record['member_code']) || empty($record['name']) || empty($record['phone'])) {
                        throw new InvalidArgumentException('Required member fields are missing');
                    }
                    $joinDate = $record['join_date'] ?? $record['admission_date'] ?? null;
                    if (!syncLocalValidateDate($joinDate)) {
                        throw new InvalidArgumentException('Invalid join/admission date');
                    }
                    $dedupeKey = 'member:' . trim((string)$record['member_code']);
                    break;

                case 'payments_men':
                case 'payments_women':
                    if (!isset($record['member_id']) || (int)$record['member_id'] <= 0) {
                        throw new InvalidArgumentException('Payment has invalid member_id');
                    }
                    if (!isset($record['amount']) || !is_numeric($record['amount']) || (float)$record['amount'] <= 0) {
                        throw new InvalidArgumentException('Payment amount must be greater than zero');
                    }
                    if (!syncLocalValidateDate($record['payment_date'] ?? null)) {
                        throw new InvalidArgumentException('Payment date is invalid');
                    }
                    $dedupeKey = 'payment:' . ($record['invoice_number'] ?? '') . ':' . (int)$record['member_id'] . ':' . $record['payment_date'] . ':' . number_format((float)$record['amount'], 2, '.', '');
                    break;

                case 'attendance_men':
                case 'attendance_women':
                    if (!isset($record['member_id']) || (int)$record['member_id'] <= 0) {
                        throw new InvalidArgumentException('Attendance has invalid member_id');
                    }
                    if (empty($record['check_in']) || strtotime((string)$record['check_in']) === false) {
                        throw new InvalidArgumentException('Attendance check_in is invalid');
                    }
                    if (!empty($record['check_out']) && strtotime((string)$record['check_out']) === false) {
                        throw new InvalidArgumentException('Attendance check_out is invalid');
                    }
                    $dedupeKey = 'attendance:' . (int)$record['member_id'] . ':' . (string)$record['check_in'];
                    break;

                case 'expenses':
                    if (empty($record['expense_type'])) {
                        throw new InvalidArgumentException('Expense type is required');
                    }
                    if (!isset($record['amount']) || !is_numeric($record['amount']) || (float)$record['amount'] < 0) {
                        throw new InvalidArgumentException('Expense amount is invalid');
                    }
                    if (!syncLocalValidateDate($record['expense_date'] ?? null)) {
                        throw new InvalidArgumentException('Expense date is invalid');
                    }
                    $dedupeKey = 'expense:' . trim((string)$record['expense_type']) . ':' . ($record['expense_date'] ?? '') . ':' . number_format((float)$record['amount'], 2, '.', '');
                    break;

                default:
                    $dedupeKey = 'id:' . $recordId;
                    break;
            }

            if (isset($seen[$dedupeKey])) {
                throw new InvalidArgumentException('Duplicate record detected in this sync batch');
            }

            $seen[$dedupeKey] = true;
            $valid[] = $record;
        } catch (Throwable $e) {
            $failed[] = [
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ];
        }
    }

    return [
        'valid' => $valid,
        'failed' => $failed,
    ];
}

function getFailedSyncRecords(PDO $db, int $limit = 50): array {
    try {
        $db->query("SELECT 1 FROM sync_log LIMIT 1");
    } catch (PDOException $e) {
        return [
            'summary' => [],
            'records' => [],
            'message' => 'sync_log table not available yet'
        ];
    }

    $summaryStmt = $db->query("SELECT table_name, COUNT(*) AS failed_count FROM sync_log WHERE sync_status = 'failed' GROUP BY table_name ORDER BY table_name ASC");
    $summaryRows = $summaryStmt ? $summaryStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $query = "SELECT id, table_name, record_id, sync_attempts, last_error, updated_at
              FROM sync_log
              WHERE sync_status = 'failed'
              ORDER BY updated_at DESC, id DESC
              LIMIT :limit";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['record_summary'] = syncBuildRecordSummary($db, (string)$row['table_name'], (int)$row['record_id']);
        $row['updated_at'] = $row['updated_at'] ? date('Y-m-d H:i:s', strtotime($row['updated_at'])) : null;
    }
    unset($row);

    return [
        'summary' => $summaryRows,
        'records' => $rows,
    ];
}

function syncBuildRecordSummary(PDO $db, string $tableName, int $recordId): string {
    try {
        switch ($tableName) {
            case 'members_men':
            case 'members_women':
                $stmt = $db->prepare("SELECT member_code, name, phone FROM {$tableName} WHERE id = :id LIMIT 1");
                $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return trim(($row['member_code'] ?? 'No code') . ' - ' . ($row['name'] ?? 'Unknown member') . ' - ' . ($row['phone'] ?? 'No phone'));
                }
                break;

            case 'payments_men':
            case 'payments_women':
                $gender = str_replace('payments_', '', $tableName);
                $stmt = $db->prepare("SELECT p.amount, p.payment_date, p.invoice_number, m.member_code, m.name
                                      FROM {$tableName} p
                                      LEFT JOIN members_{$gender} m ON m.id = p.member_id
                                      WHERE p.id = :id LIMIT 1");
                $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return trim(($row['member_code'] ?? 'No code') . ' - ' . ($row['name'] ?? 'Unknown member') . ' - ' . ($row['payment_date'] ?? 'No date') . ' - Rs ' . number_format((float)($row['amount'] ?? 0), 2) . (!empty($row['invoice_number']) ? ' - ' . $row['invoice_number'] : ''));
                }
                break;

            case 'attendance_men':
            case 'attendance_women':
                $gender = str_replace('attendance_', '', $tableName);
                $stmt = $db->prepare("SELECT a.check_in, a.check_out, m.member_code, m.name
                                      FROM {$tableName} a
                                      LEFT JOIN members_{$gender} m ON m.id = a.member_id
                                      WHERE a.id = :id LIMIT 1");
                $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return trim(($row['member_code'] ?? 'No code') . ' - ' . ($row['name'] ?? 'Unknown member') . ' - In: ' . ($row['check_in'] ?? 'N/A') . (!empty($row['check_out']) ? ' - Out: ' . $row['check_out'] : ''));
                }
                break;

            case 'expenses':
                $stmt = $db->prepare("SELECT expense_type, amount, expense_date, description FROM expenses WHERE id = :id LIMIT 1");
                $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return trim(($row['expense_type'] ?? 'Expense') . ' - ' . ($row['expense_date'] ?? 'No date') . ' - Rs ' . number_format((float)($row['amount'] ?? 0), 2) . (!empty($row['description']) ? ' - ' . $row['description'] : ''));
                }
                break;
        }
    } catch (Throwable $e) {
        return 'Record details unavailable';
    }

    return 'Record not found locally';
}

function syncFilterDependentRecords(PDO $db, string $tableName, array $records, int &$totalFailed, array &$allErrors): array {
    if (strpos($tableName, 'payments_') !== 0 && strpos($tableName, 'attendance_') !== 0) {
        return $records;
    }

    $gender = str_replace(['payments_', 'attendance_'], '', $tableName);
    $memberIds = [];
    foreach ($records as $record) {
        if (!empty($record['member_id'])) {
            $memberIds[] = (int)$record['member_id'];
        }
    }
    $memberIds = array_values(array_unique(array_filter($memberIds)));
    if (empty($memberIds)) {
        return $records;
    }

    $memberMap = [];
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $memberStmt = $db->prepare("SELECT id, member_code, name FROM members_{$gender} WHERE id IN ({$placeholders})");
    foreach ($memberIds as $index => $memberId) {
        $memberStmt->bindValue($index + 1, $memberId, PDO::PARAM_INT);
    }
    $memberStmt->execute();
    foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $memberRow) {
        $memberMap[(int)$memberRow['id']] = $memberRow;
    }

    $logMap = [];
    try {
        $logStmt = $db->prepare("SELECT table_name, record_id, sync_status, last_error FROM sync_log WHERE table_name = ? AND record_id IN ({$placeholders})");
        $logStmt->bindValue(1, 'members_' . $gender, PDO::PARAM_STR);
        foreach ($memberIds as $index => $memberId) {
            $logStmt->bindValue($index + 2, $memberId, PDO::PARAM_INT);
        }
        $logStmt->execute();
        foreach ($logStmt->fetchAll(PDO::FETCH_ASSOC) as $logRow) {
            $logMap[(int)$logRow['record_id']] = $logRow;
        }
    } catch (Throwable $e) {
        $logMap = [];
    }

    return array_values(array_filter($records, function ($record) use ($tableName, $memberMap, $logMap, &$totalFailed, &$allErrors, $db) {
        $recordId = isset($record['id']) ? (int)$record['id'] : 0;
        $memberId = isset($record['member_id']) ? (int)$record['member_id'] : 0;
        $memberRow = $memberMap[$memberId] ?? null;
        $logRow = $logMap[$memberId] ?? null;

        if (!$memberRow) {
            $message = 'Dependency check failed: local member record was not found';
        } elseif (empty($memberRow['member_code'])) {
            $message = 'Dependency check failed: member is missing member_code';
        } elseif ($logRow && in_array(($logRow['sync_status'] ?? ''), ['failed', 'pending'], true)) {
            $message = 'Dependency check failed: member sync is not complete yet. Sync member first.';
            if (!empty($logRow['last_error'])) {
                $message .= ' Last member error: ' . $logRow['last_error'];
            }
        } else {
            return true;
        }

        $totalFailed++;
        $allErrors[] = "{$tableName} record {$recordId}: {$message}";
        if ($recordId > 0) {
            updateSyncLog($db, $tableName, $recordId, 'failed', $message);
        }
        return false;
    }));
}

function syncTable($db, $tableName, $tableType, $onlineServerUrl, $apiKey, $forceSyncAll = false, $retryFailedOnly = false) {
    $totalSynced = 0;
    $totalFailed = 0;
    $allErrors = [];
    
    // Log sync start
    error_log("Starting sync for table: {$tableName} (type: {$tableType}), forceSyncAll=" . ($forceSyncAll ? 'true' : 'false'));
    
    // Get ALL unsynced records - fetch everything
    // Try to use sync_log if table exists, otherwise get all records
    // If forceSyncAll is true, ignore sync_log and sync everything
    $useSyncLog = false;
    if (!$forceSyncAll) {
        try {
            $testQuery = "SELECT 1 FROM sync_log LIMIT 1";
            $db->query($testQuery);
            $useSyncLog = true;
        } catch (PDOException $e) {
            // sync_log table doesn't exist, will sync all records
            $useSyncLog = false;
        }
    }
    
    if ($retryFailedOnly && !$useSyncLog && !$forceSyncAll) {
        error_log("Retry failed requested for {$tableName}, but sync_log is unavailable.");
        return [
            'synced' => 0,
            'failed' => 0,
            'errors' => ["{$tableName}: retry failed mode needs sync_log table"]
        ];
    }

    if ($useSyncLog && $retryFailedOnly && !$forceSyncAll) {
        $query = "SELECT t.* FROM {$tableName} t
                  INNER JOIN sync_log sl ON sl.table_name = :table_name AND sl.record_id = t.id
                  WHERE sl.sync_status = 'failed'
                  ORDER BY t.id ASC";
    } elseif ($useSyncLog && !$forceSyncAll) {
        // Get records that are NOT synced (either no sync_log entry, or status is pending/failed)
        // Use NOT EXISTS to exclude records that have a 'synced' status
        $query = "SELECT t.* FROM {$tableName} t
                  WHERE NOT EXISTS (
                      SELECT 1 FROM sync_log sl 
                      WHERE sl.table_name = :table_name 
                      AND sl.record_id = t.id 
                      AND sl.sync_status = 'synced'
                  )
                  ORDER BY t.id ASC";
    } else {
        // If sync_log doesn't exist, get ALL records (for first sync)
        // For payments, ensure we only get records with valid member_id
        if (strpos($tableName, 'payments_') === 0) {
            $query = "SELECT t.* FROM {$tableName} t
                      WHERE t.member_id IS NOT NULL AND t.member_id > 0
                      ORDER BY t.id ASC";
        } else {
            $query = "SELECT t.* FROM {$tableName} t
                      ORDER BY t.id ASC";
        }
    }
    
    $stmt = $db->prepare($query);
    if ($useSyncLog) {
        $stmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
    }
    $stmt->execute();
    $allRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalRecordCount = count($allRecords);
    
    // Always log total count in database for comparison
    $countQuery = "SELECT COUNT(*) as total FROM {$tableName}";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute();
    $totalInDb = $countStmt->fetch()['total'] ?? 0;
    
    if ($useSyncLog) {
        // Count how many are already marked as synced
        $syncedQuery = "SELECT COUNT(DISTINCT record_id) as synced_count FROM sync_log 
                       WHERE table_name = :table_name AND sync_status = 'synced'";
        $syncedStmt = $db->prepare($syncedQuery);
        $syncedStmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
        $syncedStmt->execute();
        $syncedCount = $syncedStmt->fetch()['synced_count'] ?? 0;
        
        error_log("Table {$tableName}: Total in DB={$totalInDb}, Already synced={$syncedCount}, Records to sync now={$totalRecordCount}");
    } else {
        error_log("Table {$tableName}: Total in DB={$totalInDb}, Records to sync={$totalRecordCount} (first sync, no sync_log)");
    }
    
    // Warn if there's a significant discrepancy
    if ($useSyncLog && $totalInDb > 0 && $totalRecordCount == 0) {
        error_log("WARNING: {$totalInDb} records in database but 0 records to sync. All may be marked as synced already.");
    } elseif ($totalInDb > $totalRecordCount + 100) {
        error_log("WARNING: {$totalInDb} records in database but only {$totalRecordCount} to sync. Difference: " . ($totalInDb - $totalRecordCount));
    }
    
    if (empty($allRecords)) {
        error_log("No records to sync for table: {$tableName}");
        return [
            'synced' => 0,
            'failed' => 0,
            'errors' => []
        ];
    }
    
    // Process records in chunks to avoid server limits (1000 records per chunk)
    // This ensures ALL records are sent, but in manageable chunks
    $chunkSize = 1000;
    $totalChunks = ceil($totalRecordCount / $chunkSize);
    error_log("Processing {$totalRecordCount} records in {$totalChunks} chunks of {$chunkSize} for table: {$tableName}");
    
    for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
        $offset = $chunkIndex * $chunkSize;
        $records = array_slice($allRecords, $offset, $chunkSize);
        $chunkNumber = $chunkIndex + 1;
        $chunkRecordCount = count($records);
        
        error_log("Processing chunk {$chunkNumber}/{$totalChunks} for {$tableName} (records " . ($offset + 1) . " - " . ($offset + $chunkRecordCount) . ")");

        $prepared = syncPrepareRecordsForSync($db, $tableName, $records);
        $records = $prepared['valid'];
        foreach ($prepared['failed'] as $failedRecord) {
            $recordId = (int)($failedRecord['record_id'] ?? 0);
            $errorMessage = (string)($failedRecord['error'] ?? 'Record failed validation before sync');
            $totalFailed++;
            $allErrors[] = "{$tableName} record {$recordId}: {$errorMessage}";
            if ($recordId > 0) {
                updateSyncLog($db, $tableName, $recordId, 'failed', $errorMessage);
            }
        }

        if (empty($records)) {
            error_log("Chunk {$chunkNumber} for {$tableName} skipped after validation; no valid records remained.");
            continue;
        }
        
        // For payments and attendance, add member_code to each record for reliable cross-database matching
        if (strpos($tableName, 'payments_') === 0 || strpos($tableName, 'attendance_') === 0) {
            $gender = str_replace(['payments_', 'attendance_'], '', $tableName);
            $memberTable = 'members_' . $gender;
            $recordsWithCode = 0;
            $recordsWithoutCode = 0;
            $recordLabel = strpos($tableName, 'payments_') === 0 ? 'payment' : 'attendance';
            
            foreach ($records as &$record) {
                if (isset($record['member_id']) && $record['member_id'] > 0) {
                    try {
                        $memberQuery = "SELECT member_code FROM {$memberTable} WHERE id = :member_id LIMIT 1";
                        $memberStmt = $db->prepare($memberQuery);
                        $memberStmt->bindValue(':member_id', $record['member_id'], PDO::PARAM_INT);
                        $memberStmt->execute();
                        $member = $memberStmt->fetch();
                        if ($member && isset($member['member_code']) && !empty($member['member_code'])) {
                            $record['member_code'] = $member['member_code'];
                            $recordsWithCode++;
                        } else {
                            error_log("Warning: Could not find member_code for {$recordLabel} ID " . ($record['id'] ?? 'N/A') . " with member_id: {$record['member_id']}");
                            $recordsWithoutCode++;
                        }
                    } catch (Exception $e) {
                        error_log("Error fetching member_code for {$recordLabel} ID " . ($record['id'] ?? 'N/A') . ": " . $e->getMessage());
                        $recordsWithoutCode++;
                    }
                } else {
                    error_log("Warning: {$recordLabel} record ID " . ($record['id'] ?? 'N/A') . " has invalid member_id: " . ($record['member_id'] ?? 'NULL'));
                    $recordsWithoutCode++;
                }
            }
            unset($record);
            error_log("Chunk {$chunkNumber}: " . ucfirst($recordLabel) . " member_code lookup - {$recordsWithCode} with code, {$recordsWithoutCode} without code");

            $records = array_values(array_filter($records, function ($record) use ($db, $tableName, &$totalFailed, &$allErrors) {
                if (!empty($record['member_code'])) {
                    return true;
                }

                $recordId = isset($record['id']) ? (int)$record['id'] : 0;
                $message = 'Could not resolve member_code for sync';
                $totalFailed++;
                $allErrors[] = "{$tableName} record {$recordId}: {$message}";
                if ($recordId > 0) {
                    updateSyncLog($db, $tableName, $recordId, 'failed', $message);
                }
                return false;
            }));

            if (empty($records)) {
                error_log("Chunk {$chunkNumber} for {$tableName} has no records left after member_code validation.");
                continue;
            }
        }

        $records = syncFilterDependentRecords($db, $tableName, $records, $totalFailed, $allErrors);
        if (empty($records)) {
            error_log("Chunk {$chunkNumber} for {$tableName} skipped after dependency checks.");
            continue;
        }
        
        // Prepare data for sync
        $chunkRecordCount = count($records);
        $data = [
            'action' => 'sync',
            'table_type' => $tableType,
            'records' => $records
        ];
        $jsonPayload = json_encode($data);
        if ($jsonPayload === false) {
            $payloadError = 'Failed to encode sync payload: ' . json_last_error_msg();
            foreach ($records as $record) {
                if (!empty($record['id'])) {
                    updateSyncLog($db, $tableName, (int)$record['id'], 'failed', $payloadError);
                }
            }
            $totalFailed += $chunkRecordCount;
            $allErrors[] = "Chunk {$chunkNumber} sync failed: {$payloadError}";
            continue;
        }
        
        // Send chunk to online server
        $ch = curl_init($onlineServerUrl . '/api/sync.php?api_key=' . urlencode($apiKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600); // 10 minutes per chunk
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            // Log error but continue with next chunk
            error_log("Chunk {$chunkNumber} cURL error: {$curlError}");
            try {
                $testQuery = "SELECT 1 FROM sync_log LIMIT 1";
                $db->query($testQuery);
                foreach ($records as $record) {
                    updateSyncLog($db, $tableName, $record['id'], 'failed', "cURL error: {$curlError}");
                }
            } catch (PDOException $e) {
                // sync_log table doesn't exist, skip logging
            }
            $totalFailed += $chunkRecordCount;
            $allErrors[] = "Chunk {$chunkNumber} sync error: {$curlError}";
            // Continue to next chunk instead of returning
            continue;
        }
        
        $result = json_decode($response, true);
        if (!is_array($result)) {
            $errorMsg = 'Invalid response from online server';
            error_log("Chunk {$chunkNumber} sync failed: {$errorMsg}. Raw response: " . substr((string)$response, 0, 500));
            foreach ($records as $record) {
                if (!empty($record['id'])) {
                    updateSyncLog($db, $tableName, (int)$record['id'], 'failed', $errorMsg);
                }
            }
            $totalFailed += $chunkRecordCount;
            $allErrors[] = "Chunk {$chunkNumber} sync failed: {$errorMsg}";
            continue;
        }
        
        if ($httpCode !== 200 || empty($result['success'])) {
            $errorMsg = $result['message'] ?? 'Unknown error';
            error_log("Chunk {$chunkNumber} sync failed (HTTP {$httpCode}): {$errorMsg}");
            // Log error but continue with next chunk
            try {
                $testQuery = "SELECT 1 FROM sync_log LIMIT 1";
                $db->query($testQuery);
                foreach ($records as $record) {
                    updateSyncLog($db, $tableName, $record['id'], 'failed', $errorMsg);
                }
            } catch (PDOException $e) {
                // sync_log table doesn't exist, skip logging
            }
            $totalFailed += $chunkRecordCount;
            $allErrors[] = "Chunk {$chunkNumber} sync failed: {$errorMsg}";
            // Continue to next chunk instead of returning
            continue;
        }
        
        // Process successful response
        $synced = $result['synced'] ?? 0;
        $failed = $result['failed'] ?? 0;
        $errors = $result['errors'] ?? [];
        $recordResults = is_array($result['record_results'] ?? null) ? $result['record_results'] : [];
        
        error_log("Chunk {$chunkNumber}/{$totalChunks} result: {$synced} synced, {$failed} failed");
        if (!empty($errors) && count($errors) > 0) {
            $errorSample = array_slice($errors, 0, 3);
            error_log("Chunk {$chunkNumber} sample errors: " . implode('; ', $errorSample));
        }
        
        $totalSynced += $synced;
        $totalFailed += $failed;
        $allErrors = array_merge($allErrors, $errors);
        
        // Update sync_log conservatively: only mark records as synced when the remote endpoint
        // explicitly confirms them, otherwise keep or mark them failed for retry.
        try {
            $testQuery = "SELECT 1 FROM sync_log LIMIT 1";
            $db->query($testQuery);

            $resultMap = [];
            foreach ($recordResults as $recordResult) {
                if (isset($recordResult['record_id'])) {
                    $resultMap[(string)$recordResult['record_id']] = $recordResult;
                }
            }

            foreach ($records as $record) {
                $localRecordId = isset($record['id']) ? (int)$record['id'] : 0;
                if ($localRecordId <= 0) {
                    continue;
                }

                $recordResult = $resultMap[(string)$localRecordId] ?? null;

                if ($recordResult !== null) {
                    $status = ($recordResult['status'] ?? '') === 'synced' ? 'synced' : 'failed';
                    $error = $status === 'failed' ? ($recordResult['error'] ?? 'Remote sync failed for this record') : null;
                    updateSyncLog($db, $tableName, $localRecordId, $status, $error);
                    continue;
                }

                if (empty($recordResults) && $failed === 0) {
                    updateSyncLog($db, $tableName, $localRecordId, 'synced', null);
                } else {
                    $fallbackError = $result['message'] ?? 'Remote sync did not confirm this record';
                    updateSyncLog($db, $tableName, $localRecordId, 'failed', $fallbackError);
                }
            }
        } catch (PDOException $e) {
            // sync_log table doesn't exist, skip logging
        }
    }
    
    // Log final summary
    error_log("Completed sync for {$tableName}: {$totalSynced} total synced, {$totalFailed} total failed out of {$totalRecordCount} records");
    
    return [
        'synced' => $totalSynced,
        'failed' => $totalFailed,
        'errors' => $allErrors
    ];
}

function syncProfileImages($db, $onlineServerUrl, $apiKey) {
    $synced = 0;
    $failed = 0;
    $errors = [];
    
    // Get all members with profile images that haven't been synced
    $query = "SELECT DISTINCT profile_image FROM members_men WHERE profile_image IS NOT NULL AND profile_image != ''
              UNION
              SELECT DISTINCT profile_image FROM members_women WHERE profile_image IS NOT NULL AND profile_image != ''";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $imagePaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($imagePaths)) {
        return ['synced' => 0, 'failed' => 0, 'errors' => []];
    }
    
    foreach ($imagePaths as $imagePath) {
        try {
            // Check if image file exists locally
            $localPath = __DIR__ . '/../' . $imagePath;
            
            if (!file_exists($localPath)) {
                $errors[] = "Image file not found: {$imagePath}";
                $failed++;
                continue;
            }
            
            // Upload image to online server
            $ch = curl_init($onlineServerUrl . '/api/sync-image.php?api_key=' . urlencode($apiKey));
            
            $postData = [
                'image' => new CURLFile($localPath, mime_content_type($localPath), basename($imagePath)),
                'image_path' => $imagePath
            ];
            
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                $errors[] = "Image upload error for {$imagePath}: {$curlError}";
                $failed++;
            } elseif ($httpCode !== 200) {
                $result = json_decode($response, true);
                $errorMsg = $result['message'] ?? 'Unknown error';
                $errors[] = "Image upload failed for {$imagePath}: {$errorMsg}";
                $failed++;
            } else {
                $synced++;
            }
        } catch (Exception $e) {
            $errors[] = "Error syncing image {$imagePath}: " . $e->getMessage();
            $failed++;
        }
    }
    
    return [
        'synced' => $synced,
        'failed' => $failed,
        'errors' => $errors
    ];
}

function updateSyncLog($db, $tableName, $recordId, $status, $error = null) {
    try {
        // Check if log exists
        $checkQuery = "SELECT id FROM sync_log WHERE table_name = :table_name AND record_id = :record_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
        $checkStmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $checkStmt->execute();
        $existing = $checkStmt->fetch();
    } catch (PDOException $e) {
        // Table doesn't exist, return early
        return;
    }
    
    if ($existing) {
        // Update existing
        $updateQuery = "UPDATE sync_log SET 
                       sync_status = :status,
                       synced_at = " . ($status === 'synced' ? 'NOW()' : 'NULL') . ",
                       sync_attempts = sync_attempts + 1,
                       last_error = :error,
                       updated_at = NOW()
                       WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindValue(':status', $status, PDO::PARAM_STR);
        $updateStmt->bindValue(':error', $error, PDO::PARAM_STR);
        $updateStmt->bindValue(':id', $existing['id'], PDO::PARAM_INT);
        $updateStmt->execute();
    } else {
        // Insert new
        $insertQuery = "INSERT INTO sync_log (table_name, record_id, record_type, action, sync_status, last_error) 
                       VALUES (:table_name, :record_id, :record_type, 'create', :status, :error)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
        $insertStmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $insertStmt->bindValue(':record_type', $tableName, PDO::PARAM_STR);
        $insertStmt->bindValue(':status', $status, PDO::PARAM_STR);
        $insertStmt->bindValue(':error', $error, PDO::PARAM_STR);
        $insertStmt->execute();
    }
}

