<?php
/**
 * Get Due Fees API - Get all members with due fees
 */

ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

ob_clean();

header('Content-Type: application/json');

AuthHelper::requireAdminOrStaff();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $gender = $_GET['gender'] ?? 'all';
    $gender = in_array($gender, ['all', 'men', 'women'], true) ? $gender : 'all';
    $search = trim((string)($_GET['search'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $database = new Database();
    $db = $database->getConnection();

    foreach (['men', 'women'] as $syncGender) {
        try {
            (new Member($db, $syncGender))->syncAllActivityStatuses();
        } catch (Throwable $e) {
            error_log('Due fees status sync failed for ' . $syncGender . ': ' . $e->getMessage());
        }
    }

    $tables = [];
    if ($gender === 'all' || $gender === 'men') {
        $tables[] = ['table' => 'members_men', 'gender' => 'men'];
    }
    if ($gender === 'all' || $gender === 'women') {
        $tables[] = ['table' => 'members_women', 'gender' => 'women'];
    }

    $unionParts = [];
    foreach ($tables as $entry) {
        $tableName = $entry['table'];
        $resolvedDateColumn = resolve_member_date_column($db, $tableName);
        $unionParts[] = "SELECT id, member_code, name, phone, total_due_amount, next_fee_due_date, status, '{$entry['gender']}' AS gender, {$resolvedDateColumn} AS join_date, created_at
                         FROM {$tableName}
                         WHERE COALESCE(total_due_amount, 0) > 0 AND status = 'active'";
    }

    if (empty($unionParts)) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'pagination' => ['page' => 1, 'limit' => $limit, 'total' => 0, 'total_pages' => 0],
            'summary' => ['total_members_with_due' => 0, 'total_due_amount' => 0, 'overdue_members' => 0, 'due_today' => 0]
        ]);
        exit;
    }

    $baseQuery = implode(' UNION ALL ', $unionParts);
    $filters = [];
    $params = [];

    if ($search !== '') {
        $filters[] = '(member_code LIKE :search_code OR name LIKE :search_name OR phone LIKE :search_phone)';
        $searchParam = '%' . $search . '%';
        $params[':search_code'] = $searchParam;
        $params[':search_name'] = $searchParam;
        $params[':search_phone'] = $searchParam;
    }

    $whereClause = !empty($filters) ? 'WHERE ' . implode(' AND ', $filters) : '';

    $pagedQuery = "SELECT *
                   FROM ({$baseQuery}) due_members
                   {$whereClause}
                   ORDER BY total_due_amount DESC, next_fee_due_date IS NULL ASC, next_fee_due_date ASC, created_at DESC
                   LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($pagedQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summaryQuery = "SELECT
                        COUNT(*) as total_members_with_due,
                        COALESCE(SUM(total_due_amount), 0) as total_due_amount,
                        SUM(CASE WHEN next_fee_due_date IS NOT NULL AND next_fee_due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_members,
                        SUM(CASE WHEN next_fee_due_date = CURDATE() THEN 1 ELSE 0 END) as due_today
                     FROM ({$baseQuery}) due_members
                     {$whereClause}";
    $summaryStmt = $db->prepare($summaryQuery);
    foreach ($params as $key => $value) {
        $summaryStmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $summaryStmt->execute();
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($summary['total_members_with_due'] ?? 0);

    echo json_encode([
        'success' => true,
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int)ceil($total / $limit)
        ],
        'summary' => [
            'total_members_with_due' => $total,
            'total_due_amount' => (float)($summary['total_due_amount'] ?? 0),
            'overdue_members' => (int)($summary['overdue_members'] ?? 0),
            'due_today' => (int)($summary['due_today'] ?? 0)
        ]
    ]);
} catch (Throwable $e) {
    ob_clean();
    error_log('Get Due Fees API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}
