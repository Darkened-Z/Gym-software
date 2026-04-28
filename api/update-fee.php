<?php
/**
 * Update Member Fee API
 */

// Start output buffering to prevent any accidental output
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Payment.php';
require_once __DIR__ . '/../app/helpers/SyncHelper.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';

// Clear any output that might have been generated
ob_clean();

header('Content-Type: application/json');

AuthHelper::requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $input = file_get_contents('php://input');
    if (empty($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Request body is empty']);
        exit;
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body: ' . json_last_error_msg()]);
        exit;
    }
    
    $memberId = filter_var($data['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $gender = in_array($data['gender'] ?? 'men', ['men', 'women'], true) ? $data['gender'] : 'men';
    $amount = isset($data['amount']) && is_numeric($data['amount']) ? round((float)$data['amount'], 2) : null;
    $isDefaulterUpdate = filter_var($data['is_defaulter_update'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $newDefaulterDate = $data['new_defaulter_date'] ?? null;
    $isPartialPayment = filter_var($data['is_partial_payment'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $dueAmount = isset($data['due_amount']) && is_numeric($data['due_amount']) ? round((float)$data['due_amount'], 2) : 0.00;
    
    if (!$memberId || $amount === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Member ID and amount are required']);
        exit;
    }

    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
        exit;
    }
    
    if ($isPartialPayment && $dueAmount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Due amount must be greater than 0 for partial payment']);
        exit;
    }

    if ($isDefaulterUpdate) {
        $parsedDefaulterDate = DateTime::createFromFormat('Y-m-d', (string)$newDefaulterDate);
        if (!$parsedDefaulterDate || $parsedDefaulterDate->format('Y-m-d') !== $newDefaulterDate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A valid new defaulter date is required']);
            exit;
        }
    }
    
    $database = new Database();
    $db = $database->getConnection();
    $member = new Member($db, $gender);
    $adminLogger = new AdminLogger($db);
    
    // Get member details
    $memberData = $member->getById($memberId);
    if (!$memberData) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found']);
        exit;
    }
    
    // Calculate next fee due date
    if ($isDefaulterUpdate && $newDefaulterDate) {
        $nextFeeDueDate = $newDefaulterDate;
    } else {
        $joinDate = new DateTime($memberData['join_date']);
        $today = new DateTime();
        $monthsSinceJoin = ($today->format('Y') - $joinDate->format('Y')) * 12 + (($today->format('m') - $joinDate->format('m')));
        $nextFeeDueDateObj = clone $joinDate;
        $nextFeeDueDateObj->modify('+' . ($monthsSinceJoin + 1) . ' months');
        $nextFeeDueDate = $nextFeeDueDateObj->format('Y-m-d');
    }
    
    $currentDueQuery = "SELECT total_due_amount, monthly_fee FROM members_{$gender} WHERE id = :id";
    $currentDueStmt = $db->prepare($currentDueQuery);
    $currentDueStmt->bindValue(':id', $memberId, PDO::PARAM_INT);
    $currentDueStmt->execute();
    $currentMemberData = $currentDueStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentMemberData) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member data not found']);
        exit;
    }
    
    $currentDueAmount = round((float)($currentMemberData['total_due_amount'] ?? 0.00), 2);
    $monthlyFee = round((float)($currentMemberData['monthly_fee'] ?? 0.00), 2);
    $totalOwed = round($currentDueAmount + $monthlyFee, 2);
    $isFullPayment = ($amount >= ($totalOwed - 0.01));
    
    if ($isPartialPayment) {
        $newTotalDue = max(0, $dueAmount);
        $remainingAmount = $newTotalDue;
        $paymentStatus = 'pending';
        $actualPaymentAmount = $amount;
    } elseif ($isFullPayment) {
        $actualPaymentAmount = $amount;
        $newTotalDue = 0.00;
        $remainingAmount = 0.00;
        $paymentStatus = 'completed';
    } else {
        $actualPaymentAmount = $amount;
        $newTotalDue = max(0, round($totalOwed - $amount, 2));
        $remainingAmount = $newTotalDue;
        $paymentStatus = 'pending';
    }

    $payment = new Payment($db, $gender);
    $totalDueAmount = $totalOwed;
    
    $invoiceNumber = '';
    $maxAttempts = 10;
    $attempt = 0;
    do {
        $timestamp = date('YmdHis');
        $random = rand(1000, 9999);
        $invoiceNumber = 'INV-' . $timestamp . '-' . str_pad($memberId, 4, '0', STR_PAD_LEFT) . '-' . $random;
        
        $checkInvoiceQuery = "SELECT COUNT(*) as count FROM payments_{$gender} WHERE invoice_number = :invoice";
        $checkInvoiceStmt = $db->prepare($checkInvoiceQuery);
        $checkInvoiceStmt->bindValue(':invoice', $invoiceNumber, PDO::PARAM_STR);
        $checkInvoiceStmt->execute();
        $exists = ((int)($checkInvoiceStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0)) > 0;
        
        $attempt++;
    } while ($exists && $attempt < $maxAttempts);
    
    if ($exists) {
        $invoiceNumber = 'INV-' . date('YmdHis') . '-' . str_pad($memberId, 4, '0', STR_PAD_LEFT) . '-' . substr(str_replace('.', '', microtime(true)), -6);
    }
    
    $receivedBy = $data['received_by'] ?? null;
    $paymentMethod = $data['payment_method'] ?? 'Cash';
    
    $paymentData = [
        'member_id' => $memberId,
        'amount' => $actualPaymentAmount,
        'remaining_amount' => $remainingAmount,
        'total_due_amount' => $totalDueAmount,
        'payment_date' => date('Y-m-d'),
        'due_date' => $nextFeeDueDate,
        'invoice_number' => $invoiceNumber,
        'status' => $paymentStatus,
        'received_by' => $receivedBy,
        'payment_method' => $paymentMethod
    ];

    $db->beginTransaction();

    try {
        $paymentId = $payment->create($paymentData);

        if (!$paymentId) {
            throw new Exception('Failed to create payment record. Please check database connection.');
        }

        if (!$member->updateFeeDueDate($memberId, $nextFeeDueDate)) {
            throw new Exception('Failed to update next fee due date');
        }

        $updateDueQuery = "UPDATE members_{$gender} SET total_due_amount = :total_due WHERE id = :id";
        $updateDueStmt = $db->prepare($updateDueQuery);
        $updateDueStmt->bindValue(':total_due', $newTotalDue, PDO::PARAM_STR);
        $updateDueStmt->bindValue(':id', $memberId, PDO::PARAM_INT);

        if (!$updateDueStmt->execute()) {
            throw new Exception('Failed to update member due amount');
        }

        $statusSync = $member->syncActivityStatus($memberId);

        $verifyQuery = "SELECT total_due_amount, next_fee_due_date, status FROM members_{$gender} WHERE id = :id";
        $verifyStmt = $db->prepare($verifyQuery);
        $verifyStmt->bindValue(':id', $memberId, PDO::PARAM_INT);
        $verifyStmt->execute();
        $verified = $verifyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $newTotalDue = round((float)($verified['total_due_amount'] ?? $newTotalDue), 2);
        $nextFeeDueDate = $verified['next_fee_due_date'] ?? $nextFeeDueDate;
        $memberStatus = $verified['status'] ?? ($statusSync['status'] ?? $memberData['status'] ?? 'active');

        $db->commit();

        SyncHelper::markRecordForSync($db, "members_{$gender}", (int)$memberId);
        SyncHelper::markRecordForSync($db, "payments_{$gender}", (int)$paymentId);

        $adminLogger->log('member_fee_updated', 'member_' . $gender, $memberId, null, [
            'payment_id' => $paymentId,
            'previous_due' => $currentDueAmount,
            'monthly_fee' => $monthlyFee,
            'total_owed' => $totalOwed,
            'payment_amount' => $actualPaymentAmount,
            'remaining_amount' => $newTotalDue,
            'next_fee_due_date' => $nextFeeDueDate,
            'member_status' => $memberStatus,
            'is_partial_payment' => $isPartialPayment,
            'is_defaulter_update' => $isDefaulterUpdate,
        ]);

        $message = '';
        if ($isPartialPayment) {
            $message = 'Partial payment recorded. Amount due: ' . number_format($newTotalDue, 2);
        } elseif ($isFullPayment) {
            $message = 'Full payment recorded. Previous due (' . number_format($currentDueAmount, 2) . ') + Monthly fee (' . number_format($monthlyFee, 2) . ') = ' . number_format($totalOwed, 2) . ' paid in full.';
        } else {
            $message = 'Payment recorded. Remaining due: ' . number_format($newTotalDue, 2);
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'payment_id' => $paymentId,
            'payment_date' => $paymentData['payment_date'],
            'next_fee_due_date' => $nextFeeDueDate,
            'remaining_amount' => $remainingAmount,
            'new_total_due' => $newTotalDue,
            'previous_due' => $currentDueAmount,
            'monthly_fee' => $monthlyFee,
            'payment_amount' => $actualPaymentAmount,
            'total_owed' => $totalOwed,
            'member_status' => $memberStatus,
            'calculation' => [
                'current_due' => $currentDueAmount,
                'monthly_fee_added' => $monthlyFee,
                'total_owed' => $totalOwed,
                'payment_made' => $actualPaymentAmount,
                'new_due' => $newTotalDue,
                'is_full_payment' => $isFullPayment
            ],
            'refresh_required' => true
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    
} catch (Exception $e) {
    // Clear any output that might have been generated
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    // Handle fatal errors (PHP 7+)
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fatal error: ' . $e->getMessage()
    ]);
}

