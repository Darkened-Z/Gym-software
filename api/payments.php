<?php
/**
 * Payments API (Gender-Aware)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Payment.php';
require_once __DIR__ . '/../app/models/MessageTemplate.php';
require_once __DIR__ . '/../app/models/MessageQueue.php';
require_once __DIR__ . '/../app/models/MemberConsent.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';
require_once __DIR__ . '/../app/models/Member.php';

header('Content-Type: application/json');

AuthHelper::requireAdminOrStaff();

// Validate CSRF token for all state-mutating requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    AuthHelper::validateCSRF();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$gender = $_GET['gender'] ?? 'men';
$gender = in_array($gender, ['men', 'women'], true) ? $gender : 'men';

try {
    $database = new Database();
    $db = $database->getConnection();
    $adminLogger = new AdminLogger($db);
    $memberModel = new Member($db, $gender);

    switch ($action) {
        case 'list':
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
            $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 20;
            $month = filter_var($_GET['month'] ?? date('m'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int)date('m');
            $year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int)date('Y');
            $search = $_GET['search'] ?? '';
            $defaulters = filter_var($_GET['defaulters'] ?? false, FILTER_VALIDATE_BOOLEAN);  // Show defaulters
            $status = $_GET['status'] ?? null; // 'active', 'inactive', or null for default behavior
            $status = in_array($status, ['active', 'inactive'], true) ? $status : null;

            $memberModel->syncAllActivityStatuses();

            // Get payments for current month
            $memberTable = 'members_' . $gender;
            $joinDateColumn = resolve_member_date_column($db, $memberTable);
            $statusExpr = Member::getStatusCaseExpression($joinDateColumn, 'attendance_' . $gender, 'm.id');
            $offset = ($page - 1) * $limit;
            
            if ($defaulters) {
                // Show defaulters: members who haven't paid in the last month
                $oneMonthAgo = date('Y-m-d', strtotime('-1 month'));
                $searchParam = '%' . $search . '%';
                
                // Build WHERE clause for base filtering
                $whereClause = "WHERE m.{$joinDateColumn} <= :one_month_ago AND ({$statusExpr}) = 'active'";
                
                // STATUS FILTERING
                if ($status) {
                    $whereClause .= " AND ({$statusExpr}) = :status";
                }
                
                if (!empty($search)) {
                    $whereClause .= " AND (m.member_code LIKE :search_code OR m.name LIKE :search_name)";
                }
                
                $query = "SELECT m.id as member_id, m.member_code, m.name, m.{$joinDateColumn} AS join_date, 
                                 m.monthly_fee, m.total_due_amount, m.status,
                                 MAX(p.payment_date) as last_payment_date,
                                 DATEDIFF(CURDATE(), COALESCE(MAX(p.payment_date), m.{$joinDateColumn})) as days_since_payment
                          FROM {$memberTable} m
                          LEFT JOIN payments_{$gender} p ON p.member_id = m.id
                          {$whereClause}
                          GROUP BY m.id, m.member_code, m.name, m.{$joinDateColumn}, m.monthly_fee, m.total_due_amount, m.status
                          HAVING DATEDIFF(CURDATE(), COALESCE(MAX(p.payment_date), m.{$joinDateColumn})) >= 30
                          ORDER BY days_since_payment DESC, m.created_at DESC
                          LIMIT :limit OFFSET :offset";
            } else {
                $whereClause = "WHERE MONTH(p.payment_date) = :month AND YEAR(p.payment_date) = :year";
                $searchParam = '%' . $search . '%';
                
                if (!empty($search)) {
                    $whereClause .= " AND (m.member_code LIKE :search_code OR m.name LIKE :search_name OR p.invoice_number LIKE :search_invoice)";
                }
                
                if ($status) {
                     $whereClause .= " AND ({$statusExpr}) = :status";
                }
                
                $query = "SELECT p.*, m.member_code, m.name 
                          FROM payments_{$gender} p
                          JOIN {$memberTable} m ON p.member_id = m.id
                          {$whereClause}
                          ORDER BY p.payment_date DESC, p.created_at DESC
                          LIMIT :limit OFFSET :offset";
            }
            
            $stmt = $db->prepare($query);
            
            if ($defaulters) {
                $stmt->bindValue(':one_month_ago', $oneMonthAgo, PDO::PARAM_STR);
                if (!empty($search)) {
                    $stmt->bindValue(':search_code', $searchParam, PDO::PARAM_STR);
                    $stmt->bindValue(':search_name', $searchParam, PDO::PARAM_STR);
                }
                if ($status) {
                    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
                }
            } else {
                $stmt->bindValue(':month', (int)$month, PDO::PARAM_INT);
                $stmt->bindValue(':year', (int)$year, PDO::PARAM_INT);
                if (!empty($search)) {
                    $stmt->bindValue(':search_code', $searchParam, PDO::PARAM_STR);
                    $stmt->bindValue(':search_name', $searchParam, PDO::PARAM_STR);
                    $stmt->bindValue(':search_invoice', $searchParam, PDO::PARAM_STR);
                }
                if ($status) {
                    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
                }
            }
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            
            // Count query
            if ($defaulters) {
                // Use subquery to count defaulters properly
                $countQuery = "SELECT COUNT(*) as total FROM (
                                  SELECT m.id, m.{$joinDateColumn} AS join_date
                                  FROM {$memberTable} m
                                  LEFT JOIN payments_{$gender} p ON p.member_id = m.id
                                  {$whereClause}
                                  GROUP BY m.id, m.{$joinDateColumn}
                                  HAVING DATEDIFF(CURDATE(), COALESCE(MAX(p.payment_date), m.{$joinDateColumn})) >= 30
                              ) as defaulter_count";
            } else {
                $countQuery = "SELECT COUNT(*) as total 
                              FROM payments_{$gender} p
                              JOIN {$memberTable} m ON p.member_id = m.id
                              {$whereClause}";
            }
            $countStmt = $db->prepare($countQuery);
            
            if ($defaulters) {
                $countStmt->bindValue(':one_month_ago', $oneMonthAgo, PDO::PARAM_STR);
                if (!empty($search)) {
                    $countStmt->bindValue(':search_code', $searchParam, PDO::PARAM_STR);
                    $countStmt->bindValue(':search_name', $searchParam, PDO::PARAM_STR);
                }
                if ($status) {
                    $countStmt->bindValue(':status', $status, PDO::PARAM_STR);
                }
            } else {
                $countStmt->bindValue(':month', (int)$month, PDO::PARAM_INT);
                $countStmt->bindValue(':year', (int)$year, PDO::PARAM_INT);
                if (!empty($search)) {
                    $countStmt->bindValue(':search_code', $searchParam, PDO::PARAM_STR);
                    $countStmt->bindValue(':search_name', $searchParam, PDO::PARAM_STR);
                    $countStmt->bindValue(':search_invoice', $searchParam, PDO::PARAM_STR);
                }
                if ($status) {
                    $countStmt->bindValue(':status', $status, PDO::PARAM_STR);
                }
            }
            $countStmt->execute();
            $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $total = intval($totalResult['total'] ?? 0);
            
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ],
                'month' => $defaulters ? null : $month,
                'year' => $defaulters ? null : $year,
                'defaulters' => $defaulters
            ]);
            break;

        case 'create':
            AuthHelper::ensureAdminAction('Only admin can create payments');
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                if (!is_array($data)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
                    exit;
                }

                $memberId = filter_var($data['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $amount = isset($data['amount']) && is_numeric($data['amount']) ? round((float)$data['amount'], 2) : null;
                $hasRemainingAmount = array_key_exists('remaining_amount', $data) && $data['remaining_amount'] !== '' && $data['remaining_amount'] !== null && is_numeric($data['remaining_amount']);
                $remainingAmount = $hasRemainingAmount ? max(0, round((float)$data['remaining_amount'], 2)) : null;
                $totalDueAmount = isset($data['total_due_amount']) && $data['total_due_amount'] !== '' && $data['total_due_amount'] !== null
                    ? max(0, round((float)$data['total_due_amount'], 2))
                    : null;
                $paymentDate = trim((string)($data['payment_date'] ?? ''));
                $parsedPaymentDate = DateTime::createFromFormat('Y-m-d', $paymentDate);

                if (!$memberId || $amount === null || $paymentDate === '') {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'member_id, amount, and payment_date are required']);
                    exit;
                }

                if ($amount <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
                    exit;
                }

                if (!$parsedPaymentDate || $parsedPaymentDate->format('Y-m-d') !== $paymentDate) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'payment_date must be a valid date in YYYY-MM-DD format']);
                    exit;
                }

                $memberTable = 'members_' . $gender;
                $memberStmt = $db->prepare("SELECT id, name, phone, monthly_fee, total_due_amount, status FROM {$memberTable} WHERE id = :id LIMIT 1");
                $memberStmt->bindValue(':id', $memberId, PDO::PARAM_INT);
                $memberStmt->execute();
                $memberRow = $memberStmt->fetch(PDO::FETCH_ASSOC);

                if (!$memberRow) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Member not found']);
                    exit;
                }
                
                $payment = new Payment($db, $gender);
                
                $paymentData = [
                    'member_id' => $memberId,
                    'amount' => $amount,
                    'total_due_amount' => $totalDueAmount,
                    'payment_date' => $paymentDate,
                    'due_date' => $data['due_date'] ?? null,
                    'invoice_number' => $data['invoice_number'] ?? null,
                    'received_by' => $data['received_by'] ?? null,
                    'payment_method' => $data['payment_method'] ?? 'Cash',
                    'status' => $data['status'] ?? 'completed'
                ];
                if ($hasRemainingAmount) {
                    $paymentData['remaining_amount'] = $remainingAmount;
                }

                $id = $payment->create($paymentData);
                if ($id) {
                    $currentDue = round((float)($memberRow['total_due_amount'] ?? 0), 2);
                    $monthlyFee = round((float)($memberRow['monthly_fee'] ?? 0), 2);
                    $paymentStatus = $paymentData['status'] ?? 'completed';
                    if ($hasRemainingAmount) {
                        $newDue = $remainingAmount;
                    } elseif ($paymentStatus === 'completed') {
                        $newDue = max(0, round(($currentDue + $monthlyFee) - $amount, 2));
                    } else {
                        $newDue = max(0, round($currentDue, 2));
                    }

                    $updateDueStmt = $db->prepare("UPDATE {$memberTable} SET total_due_amount = :due WHERE id = :id");
                    $updateDueStmt->bindValue(':due', $newDue, PDO::PARAM_STR);
                    $updateDueStmt->bindValue(':id', $memberId, PDO::PARAM_INT);
                    $updateDueStmt->execute();

                    $memberModel->syncActivityStatus((int)$memberId);

                    $consentModel = new MemberConsent($db);
                    $templateModel = new MessageTemplate($db);
                    $queueModel = new MessageQueue($db);
                    $template = $templateModel->getByKey('payment_confirmation_basic', 'whatsapp');

                    if ($template && $consentModel->hasGrantedConsent($memberTable, (int)$memberRow['id']) && !empty($memberRow['phone'])) {
                        $queueModel->create([
                            'member_table' => $memberTable,
                            'member_id' => (int)$memberRow['id'],
                            'template_id' => $template['id'],
                            'channel' => 'whatsapp',
                            'recipient' => $memberRow['phone'],
                            'message_purpose' => 'payment_confirmation',
                            'payload_json' => json_encode([
                                'member_name' => $memberRow['name'],
                                'amount' => $amount,
                                'payment_date' => $paymentDate,
                                'gym_name' => $data['gym_name'] ?? 'Your Gym'
                            ], JSON_UNESCAPED_UNICODE),
                            'scheduled_for' => date('Y-m-d H:i:s'),
                            'status' => 'pending'
                        ]);
                    }

                    $adminLogger->log('payment_recorded', 'payment_' . $gender, $id, null, [
                        'member_id' => $memberId,
                        'member_name' => $memberRow['name'] ?? null,
                        'amount' => $amount,
                        'payment_date' => $paymentDate,
                        'payment_method' => $paymentData['payment_method']
                    ]);
                    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Payment recorded successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to record payment']);
                }
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Payments API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}
