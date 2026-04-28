<?php
/**
 * Download Data API - Export to Excel/CSV
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Payment.php';
require_once __DIR__ . '/../app/models/Expense.php';

// Load Composer autoloader for PHPSpreadsheet
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    http_response_code(500);
    die('Composer dependencies not installed');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

// Check authentication
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    die('Unauthorized');
}

$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'excel';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    switch ($type) {
        case 'members':
            $gender = $_GET['gender'] ?? 'all';
            exportMembers($db, $sheet, $gender);
            $filename = 'members_' . ($gender === 'all' ? 'all' : $gender) . '_' . date('Y-m-d');
            break;
            
        case 'expenses':
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            exportExpenses($db, $sheet, $startDate, $endDate);
            $filename = 'expenses_' . $startDate . '_to_' . $endDate;
            break;
            
        case 'payments':
            $gender = $_GET['gender'] ?? 'all';
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            exportPayments($db, $sheet, $gender, $startDate, $endDate);
            $filename = 'payments_' . ($gender === 'all' ? 'all' : $gender) . '_' . $startDate . '_to_' . $endDate;
            break;
            
        default:
            http_response_code(400);
            die('Invalid download type');
    }
    
    // Set headers for download
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $writer = new Csv($spreadsheet);
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->setLineEnding("\r\n");
        $writer->save('php://output');
    } else {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    die('Error generating file: ' . $e->getMessage());
}

function exportMembers($db, $sheet, $gender) {
    $headers = ['Member Code', 'Name', 'Email', 'Phone', 'Address', 'Membership Type', 'Join Date', 'Admission Fee', 'Monthly Fee', 'Locker Fee', 'Next Fee Due Date', 'Total Due Amount', 'Status'];
    $sheet->fromArray([$headers], null, 'A1');
    
    $row = 2;
    
    if ($gender === 'all' || $gender === 'men') {
        $query = "SELECT member_code, name, email, phone, address, membership_type, join_date, admission_fee, monthly_fee, locker_fee, next_fee_due_date, total_due_amount, status FROM members_men ORDER BY created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $members = $stmt->fetchAll();
        
        foreach ($members as $member) {
            $sheet->fromArray([
                $member['member_code'],
                $member['name'],
                $member['email'] ?? '',
                $member['phone'],
                $member['address'] ?? '',
                $member['membership_type'],
                $member['join_date'],
                $member['admission_fee'],
                $member['monthly_fee'],
                $member['locker_fee'],
                $member['next_fee_due_date'] ?? '',
                $member['total_due_amount'],
                $member['status']
            ], null, 'A' . $row);
            $row++;
        }
    }
    
    if ($gender === 'all' || $gender === 'women') {
        $query = "SELECT member_code, name, email, phone, address, membership_type, join_date, admission_fee, monthly_fee, locker_fee, next_fee_due_date, total_due_amount, status FROM members_women ORDER BY created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $members = $stmt->fetchAll();
        
        foreach ($members as $member) {
            $sheet->fromArray([
                $member['member_code'],
                $member['name'],
                $member['email'] ?? '',
                $member['phone'],
                $member['address'] ?? '',
                $member['membership_type'],
                $member['join_date'],
                $member['admission_fee'],
                $member['monthly_fee'],
                $member['locker_fee'],
                $member['next_fee_due_date'] ?? '',
                $member['total_due_amount'],
                $member['status']
            ], null, 'A' . $row);
            $row++;
        }
    }
    
    // Auto-size columns
    foreach (range('A', 'M') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

function exportExpenses($db, $sheet, $startDate, $endDate) {
    $headers = ['ID', 'Expense Type', 'Description', 'Amount', 'Expense Date', 'Category', 'Notes', 'Created At'];
    $sheet->fromArray([$headers], null, 'A1');
    
    $query = "SELECT id, expense_type, description, amount, expense_date, category, notes, created_at 
              FROM expenses 
              WHERE expense_date >= :start_date AND expense_date <= :end_date 
              ORDER BY expense_date DESC";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
    $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
    $stmt->execute();
    $expenses = $stmt->fetchAll();
    
    $row = 2;
    foreach ($expenses as $expense) {
        $sheet->fromArray([
            $expense['id'],
            $expense['expense_type'],
            $expense['description'] ?? '',
            $expense['amount'],
            $expense['expense_date'],
            $expense['category'] ?? '',
            $expense['notes'] ?? '',
            $expense['created_at']
        ], null, 'A' . $row);
        $row++;
    }
    
    // Auto-size columns
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

function exportPayments($db, $sheet, $gender, $startDate, $endDate) {
    $headers = ['Member Code', 'Member Name', 'Amount Paid', 'Remaining Due', 'Total Due Amount', 'Payment Date', 'Due Date', 'Invoice Number', 'Status'];
    $sheet->fromArray([$headers], null, 'A1');
    
    $row = 2;
    
    if ($gender === 'all' || $gender === 'men') {
        $query = "SELECT p.*, m.member_code, m.name 
                  FROM payments_men p
                  JOIN members_men m ON p.member_id = m.id
                  WHERE p.payment_date >= :start_date AND p.payment_date <= :end_date
                  ORDER BY p.payment_date DESC";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
        $stmt->execute();
        $payments = $stmt->fetchAll();
        
        foreach ($payments as $payment) {
            $sheet->fromArray([
                $payment['member_code'],
                $payment['name'],
                $payment['amount'],
                $payment['remaining_amount'],
                $payment['total_due_amount'] ?? '',
                $payment['payment_date'],
                $payment['due_date'] ?? '',
                $payment['invoice_number'] ?? '',
                $payment['status']
            ], null, 'A' . $row);
            $row++;
        }
    }
    
    if ($gender === 'all' || $gender === 'women') {
        $query = "SELECT p.*, m.member_code, m.name 
                  FROM payments_women p
                  JOIN members_women m ON p.member_id = m.id
                  WHERE p.payment_date >= :start_date AND p.payment_date <= :end_date
                  ORDER BY p.payment_date DESC";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
        $stmt->execute();
        $payments = $stmt->fetchAll();
        
        foreach ($payments as $payment) {
            $sheet->fromArray([
                $payment['member_code'],
                $payment['name'],
                $payment['amount'],
                $payment['remaining_amount'],
                $payment['total_due_amount'] ?? '',
                $payment['payment_date'],
                $payment['due_date'] ?? '',
                $payment['invoice_number'] ?? '',
                $payment['status']
            ], null, 'A' . $row);
            $row++;
        }
    }
    
    // Auto-size columns
    foreach (range('A', 'I') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

