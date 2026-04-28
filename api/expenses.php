<?php
/**
 * Expenses API
 */

// Start output buffering
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Expense.php';
require_once __DIR__ . '/../app/helpers/AdminLogger.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

// Clear any output
ob_clean();

header('Content-Type: application/json');

AuthHelper::requireAdminOrStaff();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    $expense = new Expense($db);
    $adminLogger = new AdminLogger($db);

    switch ($action) {
        case 'list':
            $page = intval($_GET['page'] ?? 1);
            $limit = intval($_GET['limit'] ?? 20);
            $filters = [
                'start_date' => $_GET['start_date'] ?? '',
                'end_date' => $_GET['end_date'] ?? '',
                'category' => $_GET['category'] ?? '',
                'expense_type' => $_GET['expense_type'] ?? ''
            ];
            
            $result = $expense->getAll($page, $limit, $filters);
            echo json_encode([
                'success' => true,
                'data' => $result['data'],
                'pagination' => [
                    'page' => $result['page'],
                    'limit' => $result['limit'],
                    'total' => $result['total'],
                    'total_pages' => ceil($result['total'] / $result['limit'])
                ]
            ]);
            break;

        case 'create':
            AuthHelper::ensureAdminAction('Only admin can create expenses');
            if ($method === 'POST') {
                $input = file_get_contents('php://input');
                if (empty($input)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Request body is empty']);
                    exit;
                }
                
                $data = json_decode($input, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
                    exit;
                }

                if (empty($data['expense_type']) || empty($data['amount'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Expense type and amount are required']);
                    exit;
                }

                $expenseData = [
                    'expense_type' => $data['expense_type'],
                    'description' => $data['description'] ?? null,
                    'amount' => floatval($data['amount']),
                    'expense_date' => $data['expense_date'] ?? date('Y-m-d'),
                    'category' => $data['category'] ?? null,
                    'created_by' => $_SESSION['user_id'] ?? null,
                    'notes' => $data['notes'] ?? null
                ];

                $id = $expense->create($expenseData);
                if ($id) {
                    $adminLogger->log('expense_created', 'expense', $id, null, [
                        'expense_type' => $expenseData['expense_type'],
                        'amount' => $expenseData['amount'],
                        'expense_date' => $expenseData['expense_date'],
                        'category' => $expenseData['category']
                    ]);
                    echo json_encode([
                        'success' => true,
                        'id' => $id,
                        'message' => 'Expense added successfully'
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to add expense']);
                }
            }
            break;

        case 'update':
            AuthHelper::ensureAdminAction('Only admin can update expenses');
            if ($method === 'POST' || $method === 'PUT') {
                $input = file_get_contents('php://input');
                if (empty($input)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Request body is empty']);
                    exit;
                }
                
                $data = json_decode($input, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
                    exit;
                }

                $id = $data['id'] ?? $_GET['id'] ?? null;
                if (!$id) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Expense ID is required']);
                    exit;
                }

                $expenseData = [
                    'expense_type' => $data['expense_type'] ?? '',
                    'description' => $data['description'] ?? null,
                    'amount' => floatval($data['amount'] ?? 0),
                    'expense_date' => $data['expense_date'] ?? date('Y-m-d'),
                    'category' => $data['category'] ?? null,
                    'notes' => $data['notes'] ?? null
                ];

                if ($expense->update($id, $expenseData)) {
                    $adminLogger->log('expense_updated', 'expense', $id, null, [
                        'expense_type' => $expenseData['expense_type'],
                        'amount' => $expenseData['amount'],
                        'expense_date' => $expenseData['expense_date'],
                        'category' => $expenseData['category']
                    ]);
                    echo json_encode([
                        'success' => true,
                        'message' => 'Expense updated successfully'
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to update expense']);
                }
            }
            break;

        case 'delete':
            AuthHelper::ensureAdminAction('Only admin can delete expenses');
            if ($method === 'DELETE' || $method === 'POST') {
                $id = $_GET['id'] ?? null;
                if ($method === 'POST') {
                    $input = file_get_contents('php://input');
                    $data = json_decode($input, true);
                    $id = $data['id'] ?? $id;
                }

                if (!$id) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Expense ID is required']);
                    exit;
                }

                if ($expense->delete($id)) {
                    $adminLogger->log('expense_deleted', 'expense', $id);
                    echo json_encode([
                        'success' => true,
                        'message' => 'Expense deleted successfully'
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to delete expense']);
                }
            }
            break;

        case 'get':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Expense ID is required']);
                exit;
            }

            $expenseData = $expense->getById($id);
            if ($expenseData) {
                echo json_encode([
                    'success' => true,
                    'data' => $expenseData
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Expense not found']);
            }
            break;

        case 'stats':
            $startDate = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
            $endDate = $_GET['end_date'] ?? date('Y-m-t'); // Last day of current month
            
            $totalExpenses = $expense->getTotalByPeriod($startDate, $endDate);
            $categories = $expense->getCategories();
            $expenseTypes = $expense->getExpenseTypes();
            
            // Get category breakdown
            $categoryQuery = "SELECT category, SUM(amount) as total 
                            FROM expenses 
                            WHERE expense_date >= :start_date AND expense_date <= :end_date 
                            AND category IS NOT NULL AND category != ''
                            GROUP BY category";
            $catStmt = $db->prepare($categoryQuery);
            $catStmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
            $catStmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
            $catStmt->execute();
            $categoryBreakdown = [];
            while ($row = $catStmt->fetch()) {
                $categoryBreakdown[$row['category']] = floatval($row['total']);
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'total_expenses' => $totalExpenses,
                    'categories' => $categories,
                    'expense_types' => $expenseTypes,
                    'category_breakdown' => $categoryBreakdown,
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate
                    ]
                ]
            ]);
            break;

        default:
            // If no action specified, return list by default
            if (empty($action)) {
                $page = intval($_GET['page'] ?? 1);
                $limit = intval($_GET['limit'] ?? 20);
                $filters = [
                    'start_date' => $_GET['start_date'] ?? '',
                    'end_date' => $_GET['end_date'] ?? '',
                    'category' => $_GET['category'] ?? '',
                    'expense_type' => $_GET['expense_type'] ?? ''
                ];
                
                $result = $expense->getAll($page, $limit, $filters);
                echo json_encode([
                    'success' => true,
                    'data' => $result['data'],
                    'pagination' => [
                        'page' => $result['page'],
                        'limit' => $result['limit'],
                        'total' => $result['total'],
                        'total_pages' => ceil($result['total'] / $result['limit'])
                    ]
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fatal error: ' . $e->getMessage()
    ]);
}

