<?php
/**
 * Update Due Fee API - Direct due amount management
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
    
    $memberId = $data['member_id'] ?? null;
    $gender = $data['gender'] ?? 'men';
    $dueAmount = $data['due_amount'] ?? null;
    $action = $data['action'] ?? 'update'; // 'update', 'clear', 'add'
    
    if (!$memberId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Member ID is required']);
        exit;
    }
    
    if ($action === 'update' && $dueAmount === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Due amount is required for update action']);
        exit;
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
    
    $currentDueAmount = floatval($memberData['total_due_amount'] ?? 0.00);
    $newDueAmount = 0.00;
    $message = '';
    $paymentAmount = 0.00; // Amount paid (if any)
    $shouldCreatePayment = false; // Whether to create a payment record
    
    switch ($action) {
        case 'clear':
            // Clear all due amount - this is a payment
            $newDueAmount = 0.00;
            $paymentAmount = $currentDueAmount; // Full payment of due amount
            $shouldCreatePayment = ($currentDueAmount > 0);
            $message = 'Due amount cleared successfully';
            break;
            
        case 'add':
            // Add to existing due amount - no payment, just adding debt
            $addAmount = floatval($dueAmount);
            $newDueAmount = $currentDueAmount + $addAmount;
            $shouldCreatePayment = false; // No payment, just adding to due
            $message = 'Amount added to due fees. New total: ' . number_format($newDueAmount, 2);
            break;
            
        case 'update':
        default:
            // Set to specific amount
            $newDueAmount = floatval($dueAmount);
            // If new amount is less than current, it means payment was made
            if ($newDueAmount < $currentDueAmount) {
                $paymentAmount = $currentDueAmount - $newDueAmount;
                $shouldCreatePayment = true;
                $message = 'Due amount updated to ' . number_format($newDueAmount, 2) . '. Payment of ' . number_format($paymentAmount, 2) . ' recorded.';
            } else {
                $shouldCreatePayment = false;
                $message = 'Due amount updated to ' . number_format($newDueAmount, 2);
            }
            break;
    }
    
    // Check if column exists first
    $checkColumnQuery = "SELECT COUNT(*) as col_count FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'members_{$gender}' 
                        AND COLUMN_NAME = 'total_due_amount'";
    $checkStmt = $db->prepare($checkColumnQuery);
    $checkStmt->execute();
    $columnExists = $checkStmt->fetch()['col_count'] > 0;
    
    if (!$columnExists) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database column "total_due_amount" does not exist. Please run the migration script: database/add_due_amount_simple.sql in phpMyAdmin first.',
            'error_code' => 'MISSING_COLUMN'
        ]);
        exit;
    }
    
    // Start transaction to ensure both due update and payment record are created together
    $db->beginTransaction();
    
    try {
        // Update member's total due amount
        $updateQuery = "UPDATE members_{$gender} SET total_due_amount = :total_due WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindValue(':total_due', $newDueAmount, PDO::PARAM_STR);
        $updateStmt->bindValue(':id', $memberId, PDO::PARAM_INT);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update due amount');
        }
        
        $paymentId = null;
        
        // If payment was made, create a payment record
        if ($shouldCreatePayment && $paymentAmount > 0) {
            $payment = new Payment($db, $gender);
            
            // Generate unique invoice number
            $invoiceNumber = '';
            $maxAttempts = 10;
            $attempt = 0;
            do {
                $timestamp = date('YmdHis');
                $random = rand(1000, 9999);
                $invoiceNumber = 'INV-DUE-' . $timestamp . '-' . str_pad($memberId, 4, '0', STR_PAD_LEFT) . '-' . $random;
                
                // Check if invoice number already exists
                $checkInvoiceQuery = "SELECT COUNT(*) as count FROM payments_{$gender} WHERE invoice_number = :invoice";
                $checkInvoiceStmt = $db->prepare($checkInvoiceQuery);
                $checkInvoiceStmt->bindValue(':invoice', $invoiceNumber, PDO::PARAM_STR);
                $checkInvoiceStmt->execute();
                $exists = $checkInvoiceStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                
                $attempt++;
            } while ($exists && $attempt < $maxAttempts);
            
            // If still duplicate after max attempts, add microtime
            if ($exists) {
                $invoiceNumber = 'INV-DUE-' . date('YmdHis') . '-' . str_pad($memberId, 4, '0', STR_PAD_LEFT) . '-' . substr(str_replace('.', '', microtime(true)), -6);
            }
            
            // Calculate next fee due date (30 days from now)
            $nextFeeDueDate = date('Y-m-d', strtotime('+30 days'));
            
            // Create payment record
            $paymentData = [
                'member_id' => $memberId,
                'amount' => $paymentAmount, // Amount paid towards due fees
                'remaining_amount' => $newDueAmount, // Remaining due after payment
                'total_due_amount' => $currentDueAmount, // Total that was due before payment
                'payment_date' => date('Y-m-d'),
                'due_date' => $nextFeeDueDate,
                'invoice_number' => $invoiceNumber,
                'status' => ($newDueAmount == 0) ? 'completed' : 'pending' // Completed if fully paid, pending if partial
            ];
            
            $paymentId = $payment->create($paymentData);
            
            if (!$paymentId) {
                throw new Exception('Failed to create payment record');
            }
        }
        
        $statusSync = $member->syncActivityStatus($memberId);

        // Commit transaction
        $db->commit();
        
        // Verify the update was successful
        $verifyQuery = "SELECT total_due_amount, status, next_fee_due_date FROM members_{$gender} WHERE id = :id";
        $verifyStmt = $db->prepare($verifyQuery);
        $verifyStmt->bindValue(':id', $memberId, PDO::PARAM_INT);
        $verifyStmt->execute();
        $updated = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        $actualNewAmount = floatval($updated['total_due_amount'] ?? 0.00);

        // Mark this member record for re-sync so cloud database gets the new due amount
        SyncHelper::markRecordForSync($db, "members_{$gender}", (int)$memberId);
        if ($paymentId) {
            SyncHelper::markRecordForSync($db, "payments_{$gender}", (int)$paymentId);
        }

        $adminLogger->log('member_due_updated', 'member_' . $gender, $memberId, null, [
            'due_action' => $action,
            'previous_due_amount' => $currentDueAmount,
            'new_due_amount' => $actualNewAmount,
            'payment_recorded' => $shouldCreatePayment && $paymentId !== null,
            'payment_id' => $paymentId,
            'payment_amount' => $paymentAmount,
            'member_status' => $updated['status'] ?? ($statusSync['status'] ?? $memberData['status'] ?? 'active'),
            'next_fee_due_date' => $updated['next_fee_due_date'] ?? ($memberData['next_fee_due_date'] ?? null),
        ]);

        $response = [
            'success' => true,
            'message' => $message,
            'previous_due_amount' => $currentDueAmount,
            'new_due_amount' => $actualNewAmount,
            'payment_recorded' => $shouldCreatePayment && $paymentId !== null,
            'payment_id' => $paymentId,
            'payment_amount' => $paymentAmount,
            'member_status' => $updated['status'] ?? ($statusSync['status'] ?? $memberData['status'] ?? 'active'),
            'next_fee_due_date' => $updated['next_fee_due_date'] ?? ($memberData['next_fee_due_date'] ?? null)
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
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

