<?php
/**
 * Sync API Endpoint (for online server)
 * Receives data from local server and inserts into online database
 */

set_time_limit(1800);
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', 1800);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database-online.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Payment.php';
require_once __DIR__ . '/../app/models/Expense.php';
require_once __DIR__ . '/../app/models/Attendance.php';

header('Content-Type: application/json');

$apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
$expectedApiKey = (string)env('SYNC_API_KEY', '');

if ($expectedApiKey === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'SYNC_API_KEY is not configured on the server']);
    exit;
}

if (!hash_equals($expectedApiKey, (string)$apiKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Invalid API key']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

function syncRecordSuccess(array &$recordResults, ?int $recordId): void
{
    $recordResults[] = [
        'record_id' => $recordId,
        'status' => 'synced'
    ];
}

function syncRecordFailure(array &$recordResults, array &$errors, ?int $recordId, string $message): void
{
    $errors[] = $message;
    $recordResults[] = [
        'record_id' => $recordId,
        'status' => 'failed',
        'error' => $message
    ];
}

function syncValidateDate(?string $value, string $format = 'Y-m-d'): bool
{
    if ($value === null || $value === '') {
        return false;
    }

    $parsed = DateTime::createFromFormat($format, $value);
    return $parsed && $parsed->format($format) === $value;
}

function syncNormalizeDecimal($value, bool $allowNull = false): ?string
{
    if ($value === null || $value === '') {
        return $allowNull ? null : '0.00';
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Numeric value expected');
    }

    return number_format((float)$value, 2, '.', '');
}

function syncNormalizeRecord(string $tableType, array $record): array
{
    switch ($tableType) {
        case 'members_men':
        case 'members_women':
            if (empty($record['member_code']) || empty($record['name']) || empty($record['phone'])) {
                throw new InvalidArgumentException('Member record requires member_code, name, and phone');
            }

            $joinDate = $record['join_date'] ?? $record['admission_date'] ?? null;
            if (!syncValidateDate($joinDate)) {
                throw new InvalidArgumentException('Member join_date/admission_date must be a valid YYYY-MM-DD date');
            }

            $record['member_code'] = trim((string)$record['member_code']);
            $record['name'] = trim((string)$record['name']);
            $record['phone'] = trim((string)$record['phone']);
            $record['email'] = isset($record['email']) ? trim((string)$record['email']) : null;
            $record['join_date'] = $joinDate;
            $record['admission_date'] = $joinDate;
            $record['monthly_fee'] = syncNormalizeDecimal($record['monthly_fee'] ?? 0);
            $record['admission_fee'] = syncNormalizeDecimal($record['admission_fee'] ?? 0);
            $record['locker_fee'] = syncNormalizeDecimal($record['locker_fee'] ?? 0);
            $record['total_due_amount'] = syncNormalizeDecimal($record['total_due_amount'] ?? 0);

            if (!empty($record['next_fee_due_date']) && !syncValidateDate((string)$record['next_fee_due_date'])) {
                throw new InvalidArgumentException('next_fee_due_date must be a valid YYYY-MM-DD date');
            }

            $record['status'] = in_array(($record['status'] ?? 'active'), ['active', 'inactive'], true) ? $record['status'] : 'active';
            return $record;

        case 'payments_men':
        case 'payments_women':
            if (!isset($record['amount']) || !is_numeric($record['amount']) || (float)$record['amount'] <= 0) {
                throw new InvalidArgumentException('Payment amount must be greater than zero');
            }
            if (!syncValidateDate($record['payment_date'] ?? null)) {
                throw new InvalidArgumentException('payment_date must be a valid YYYY-MM-DD date');
            }

            $record['amount'] = syncNormalizeDecimal($record['amount']);
            $record['remaining_amount'] = syncNormalizeDecimal($record['remaining_amount'] ?? 0);
            $record['total_due_amount'] = syncNormalizeDecimal($record['total_due_amount'] ?? null, true);
            $record['invoice_number'] = isset($record['invoice_number']) ? trim((string)$record['invoice_number']) : null;
            $record['member_code'] = isset($record['member_code']) ? trim((string)$record['member_code']) : null;

            if (!empty($record['due_date']) && !syncValidateDate((string)$record['due_date'])) {
                throw new InvalidArgumentException('due_date must be a valid YYYY-MM-DD date');
            }

            return $record;

        case 'expenses':
            if (empty($record['expense_type'])) {
                throw new InvalidArgumentException('Expense type is required');
            }
            if (!isset($record['amount']) || !is_numeric($record['amount']) || (float)$record['amount'] < 0) {
                throw new InvalidArgumentException('Expense amount must be zero or greater');
            }
            if (!syncValidateDate($record['expense_date'] ?? null)) {
                throw new InvalidArgumentException('expense_date must be a valid YYYY-MM-DD date');
            }

            $record['expense_type'] = trim((string)$record['expense_type']);
            $record['amount'] = syncNormalizeDecimal($record['amount']);
            return $record;

        case 'attendance_men':
        case 'attendance_women':
            if (empty($record['check_in'])) {
                throw new InvalidArgumentException('Attendance record requires check_in');
            }

            $checkIn = strtotime((string)$record['check_in']);
            if ($checkIn === false) {
                throw new InvalidArgumentException('check_in must be a valid datetime');
            }

            if (!empty($record['check_out'])) {
                $checkOut = strtotime((string)$record['check_out']);
                if ($checkOut === false) {
                    throw new InvalidArgumentException('check_out must be a valid datetime');
                }
                if ($checkOut < $checkIn) {
                    throw new InvalidArgumentException('check_out cannot be earlier than check_in');
                }
            }

            $record['member_code'] = isset($record['member_code']) ? trim((string)$record['member_code']) : null;
            return $record;
    }

    throw new InvalidArgumentException('Unsupported table type');
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        exit;
    }

    $tableType = (string)($data['table_type'] ?? '');
    $records = $data['records'] ?? [];
    $recordCount = is_array($records) ? count($records) : 0;
    $allowedTableTypes = ['members_men', 'members_women', 'payments_men', 'payments_women', 'expenses', 'attendance_men', 'attendance_women'];

    error_log("Online server received sync request: table_type={$tableType}, record_count={$recordCount}");

    if (!in_array($tableType, $allowedTableTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid table type']);
        exit;
    }

    if (empty($records) || !is_array($records)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No records provided or invalid format']);
        exit;
    }

    if ($recordCount > 1000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Too many records in one request. Maximum 1000 allowed.']);
        exit;
    }

    $database = new DatabaseOnline();
    $db = $database->getConnection();

    $synced = 0;
    $failed = 0;
    $errors = [];
    $recordResults = [];

    switch ($tableType) {
        case 'members_men':
        case 'members_women':
            $gender = str_replace('members_', '', $tableType);
            $member = new Member($db, $gender);

            foreach ($records as $record) {
                $sourceRecordId = isset($record['id']) ? (int)$record['id'] : null;

                try {
                    $record = syncNormalizeRecord($tableType, $record);
                    $existing = $member->getByCode($record['member_code']);
                    $db->beginTransaction();

                    if ($existing) {
                        $updateData = $record;
                        if (!isset($updateData['total_due_amount']) || $updateData['total_due_amount'] === null) {
                            $updateData['total_due_amount'] = $existing['total_due_amount'] ?? 0.00;
                        }
                        $member->update($existing['id'], $updateData);
                        $member->syncActivityStatus((int)$existing['id']);
                    } else {
                        if (!isset($record['total_due_amount']) || $record['total_due_amount'] === null) {
                            $record['total_due_amount'] = 0.00;
                        }
                        $createdId = $member->create($record);
                        if (!$createdId) {
                            throw new RuntimeException('Failed to create member');
                        }
                        $member->syncActivityStatus((int)$createdId);
                    }

                    $db->commit();
                    $synced++;
                    syncRecordSuccess($recordResults, $sourceRecordId);
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $failed++;
                    syncRecordFailure($recordResults, $errors, $sourceRecordId, 'Member ' . ($record['member_code'] ?? 'N/A') . ': ' . $e->getMessage());
                }
            }
            break;

        case 'payments_men':
        case 'payments_women':
            $gender = str_replace('payments_', '', $tableType);
            $payment = new Payment($db, $gender);
            $member = new Member($db, $gender);

            foreach ($records as $record) {
                $sourceRecordId = isset($record['id']) ? (int)$record['id'] : null;

                try {
                    $record = syncNormalizeRecord($tableType, $record);
                    $memberId = isset($record['member_id']) ? (int)$record['member_id'] : null;
                    $memberCode = $record['member_code'] ?? null;

                    if (!empty($memberCode)) {
                        $memberByCode = $member->getByCode($memberCode);
                        if ($memberByCode) {
                            $memberId = (int)$memberByCode['id'];
                        } elseif ($memberId) {
                            $memberCheck = $member->getById($memberId);
                            if (!$memberCheck) {
                                throw new RuntimeException("Payment sync failed: Member with code '{$memberCode}' and ID '{$memberId}' not found in online database");
                            }
                        } else {
                            throw new RuntimeException("Payment sync failed: Member with code '{$memberCode}' not found in online database and no member_id provided");
                        }
                    } elseif ($memberId) {
                        $memberCheck = $member->getById($memberId);
                        if (!$memberCheck) {
                            throw new RuntimeException("Payment sync failed: Member with ID '{$memberId}' not found in online database. Please sync members first.");
                        }
                    } else {
                        throw new RuntimeException('Payment sync failed: Missing both member_id and member_code');
                    }

                    if (!$memberId) {
                        throw new RuntimeException('Payment sync failed: Could not resolve member_id');
                    }

                    $record['member_id'] = $memberId;
                    $existing = null;

                    if (!empty($record['invoice_number'])) {
                        $checkQuery = "SELECT id FROM payments_{$gender} WHERE invoice_number = :invoice_number LIMIT 1";
                        $checkStmt = $db->prepare($checkQuery);
                        $checkStmt->bindValue(':invoice_number', $record['invoice_number'], PDO::PARAM_STR);
                        $checkStmt->execute();
                        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }

                    if (!$existing) {
                        $checkQuery = "SELECT id FROM payments_{$gender} WHERE member_id = :member_id AND amount = :amount AND payment_date = :payment_date LIMIT 1";
                        $checkStmt = $db->prepare($checkQuery);
                        $checkStmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
                        $checkStmt->bindValue(':amount', $record['amount'], PDO::PARAM_STR);
                        $checkStmt->bindValue(':payment_date', $record['payment_date'], PDO::PARAM_STR);
                        $checkStmt->execute();
                        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }

                    $db->beginTransaction();
                    if ($existing) {
                        $payment->update($existing['id'], $record);
                    } else {
                        $createdId = $payment->create($record);
                        if (!$createdId) {
                            throw new RuntimeException('Failed to create payment');
                        }
                    }
                    $member->syncActivityStatus($memberId);
                    $db->commit();

                    $synced++;
                    syncRecordSuccess($recordResults, $sourceRecordId);
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $failed++;
                    syncRecordFailure($recordResults, $errors, $sourceRecordId, 'Payment for member ' . ($record['member_code'] ?? $record['member_id'] ?? 'N/A') . ': ' . $e->getMessage());
                }
            }
            break;

        case 'expenses':
            $expense = new Expense($db);

            foreach ($records as $record) {
                $sourceRecordId = isset($record['id']) ? (int)$record['id'] : null;

                try {
                    $record = syncNormalizeRecord($tableType, $record);
                    $db->beginTransaction();

                    if (isset($record['id'])) {
                        $existing = $expense->getById($record['id']);
                        if ($existing) {
                            $expense->update($record['id'], $record);
                        } else {
                            unset($record['id']);
                            $expense->create($record);
                        }
                    } else {
                        $expense->create($record);
                    }

                    $db->commit();
                    $synced++;
                    syncRecordSuccess($recordResults, $sourceRecordId);
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $failed++;
                    syncRecordFailure($recordResults, $errors, $sourceRecordId, 'Expense: ' . $e->getMessage());
                }
            }
            break;

        case 'attendance_men':
        case 'attendance_women':
            $gender = str_replace('attendance_', '', $tableType);
            $attendance = new Attendance($db, $gender);
            $member = new Member($db, $gender);

            foreach ($records as $record) {
                $sourceRecordId = isset($record['id']) ? (int)$record['id'] : null;

                try {
                    $record = syncNormalizeRecord($tableType, $record);
                    $memberId = isset($record['member_id']) ? (int)$record['member_id'] : null;
                    $memberCode = $record['member_code'] ?? null;

                    if (!empty($memberCode)) {
                        $memberByCode = $member->getByCode($memberCode);
                        if (!$memberByCode) {
                            throw new RuntimeException("Attendance sync failed: Member with code '{$memberCode}' not found in online database");
                        }
                        $memberId = (int)$memberByCode['id'];
                    }

                    if (!$memberId) {
                        throw new RuntimeException('Attendance sync failed: Could not resolve member_id');
                    }

                    $record['member_id'] = $memberId;

                    $checkQuery = "SELECT id FROM attendance_{$gender} WHERE member_id = :member_id AND check_in = :check_in LIMIT 1";
                    $checkStmt = $db->prepare($checkQuery);
                    $checkStmt->bindValue(':member_id', $record['member_id'], PDO::PARAM_INT);
                    $checkStmt->bindValue(':check_in', $record['check_in'], PDO::PARAM_STR);
                    $checkStmt->execute();
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                    $db->beginTransaction();
                    if ($existing) {
                        $attendance->update($existing['id'], [
                            'member_id' => $record['member_id'],
                            'check_in' => $record['check_in'],
                            'check_out' => $record['check_out'] ?? null,
                            'duration_minutes' => $record['duration_minutes'] ?? null,
                            'is_first_entry_today' => $record['is_first_entry_today'] ?? 1,
                            'entry_gate_id' => $record['entry_gate_id'] ?? null,
                            'exit_gate_id' => $record['exit_gate_id'] ?? null,
                        ]);
                    } else {
                        $attendance->create($record);
                    }
                    $db->commit();

                    $synced++;
                    syncRecordSuccess($recordResults, $sourceRecordId);
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $failed++;
                    syncRecordFailure($recordResults, $errors, $sourceRecordId, 'Attendance for member ' . ($record['member_code'] ?? $record['member_id'] ?? 'N/A') . ': ' . $e->getMessage());
                }
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid table type']);
            exit;
    }

    error_log("Online server sync completed for {$tableType}: {$synced} synced, {$failed} failed out of {$recordCount} received");

    echo json_encode([
        'success' => true,
        'synced' => $synced,
        'failed' => $failed,
        'errors' => $errors,
        'record_results' => $recordResults,
        'message' => "Synced {$synced} records, {$failed} failed"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
