<?php
/**
 * Admin activity logger helper
 */

class AdminLogger {
    private PDO $conn;

    public function __construct(PDO $db) {
        $this->conn = $db;
    }

    public function log(string $action, ?string $targetType = null, $targetId = null, ?string $reason = null, $details = null): bool {
        if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
            return false;
        }

        $query = "INSERT INTO admin_action_log
            (admin_id, admin_username, action, target_type, target_id, reason, details, ip_address)
            VALUES
            (:admin_id, :admin_username, :action, :target_type, :target_id, :reason, :details, :ip_address)";

        $stmt = $this->conn->prepare($query);
        $jsonDetails = null;
        if ($details !== null) {
            $jsonDetails = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $stmt->bindValue(':admin_id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':admin_username', (string)$_SESSION['username'], PDO::PARAM_STR);
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':target_type', $targetType, PDO::PARAM_STR);
        $stmt->bindValue(':target_id', $targetId !== null ? (int)$targetId : null, $targetId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':reason', $reason, $reason !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':details', $jsonDetails, $jsonDetails !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':ip_address', $_SERVER['REMOTE_ADDR'] ?? null, isset($_SERVER['REMOTE_ADDR']) ? PDO::PARAM_STR : PDO::PARAM_NULL);

        return $stmt->execute();
    }

    private function buildWhere(array $filters, array &$params): string {
        $where = [];

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }
        if (!empty($filters['admin_username'])) {
            $where[] = 'admin_username LIKE :admin_username';
            $params[':admin_username'] = '%' . $filters['admin_username'] . '%';
        }
        if (!empty($filters['target_type'])) {
            $where[] = 'target_type = :target_type';
            $params[':target_type'] = $filters['target_type'];
        }
        if (!empty($filters['start_date'])) {
            $where[] = 'DATE(created_at) >= :start_date';
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $where[] = 'DATE(created_at) <= :end_date';
            $params[':end_date'] = $filters['end_date'];
        }

        return $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    }

    public function list(int $page = 1, int $limit = 50, array $filters = []): array {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $params = [];
        $whereClause = $this->buildWhere($filters, $params);

        $query = "SELECT * FROM admin_action_log {$whereClause} ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            if (!empty($row['details'])) {
                $decoded = json_decode($row['details'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row['details'] = $decoded;
                }
            }
        }
        unset($row);

        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM admin_action_log {$whereClause}");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        return [
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function getAnalytics(array $filters = []): array {
        $params = [];
        $whereClause = $this->buildWhere($filters, $params);

        $summaryStmt = $this->conn->prepare("SELECT COUNT(*) AS total_logs, COUNT(DISTINCT admin_username) AS staff_count FROM admin_action_log {$whereClause}");
        foreach ($params as $key => $value) {
            $summaryStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $staffStmt = $this->conn->prepare("SELECT admin_username, COUNT(*) AS total FROM admin_action_log {$whereClause} GROUP BY admin_username ORDER BY total DESC, admin_username ASC");
        foreach ($params as $key => $value) {
            $staffStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $staffStmt->execute();
        $staff = $staffStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $actionStmt = $this->conn->prepare("SELECT action, COUNT(*) AS total FROM admin_action_log {$whereClause} GROUP BY action ORDER BY total DESC, action ASC");
        foreach ($params as $key => $value) {
            $actionStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $actionStmt->execute();
        $actions = $actionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $dailyStmt = $this->conn->prepare("SELECT DATE(created_at) AS label, COUNT(*) AS total FROM admin_action_log {$whereClause} GROUP BY DATE(created_at) ORDER BY DATE(created_at) ASC");
        foreach ($params as $key => $value) {
            $dailyStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $dailyStmt->execute();
        $daily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $weeklyStmt = $this->conn->prepare("SELECT YEAR(created_at) AS year_num, WEEK(created_at, 1) AS week_num, CONCAT(YEAR(created_at), '-W', LPAD(WEEK(created_at, 1), 2, '0')) AS label, COUNT(*) AS total FROM admin_action_log {$whereClause} GROUP BY YEAR(created_at), WEEK(created_at, 1) ORDER BY YEAR(created_at) ASC, WEEK(created_at, 1) ASC");
        foreach ($params as $key => $value) {
            $weeklyStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $weeklyStmt->execute();
        $weekly = $weeklyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $monthlyStmt = $this->conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COUNT(*) AS total FROM admin_action_log {$whereClause} GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY DATE_FORMAT(created_at, '%Y-%m') ASC");
        foreach ($params as $key => $value) {
            $monthlyStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $monthlyStmt->execute();
        $monthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'summary' => [
                'total_logs' => (int)($summary['total_logs'] ?? 0),
                'staff_count' => (int)($summary['staff_count'] ?? 0),
            ],
            'staff' => array_map(static function ($row) {
                return [
                    'label' => $row['admin_username'] ?: 'Unknown',
                    'total' => (int)($row['total'] ?? 0),
                ];
            }, $staff),
            'actions' => array_map(static function ($row) {
                return [
                    'label' => $row['action'] ?: 'unknown',
                    'total' => (int)($row['total'] ?? 0),
                ];
            }, $actions),
            'daily' => array_map(static function ($row) {
                return [
                    'label' => $row['label'],
                    'total' => (int)($row['total'] ?? 0),
                ];
            }, $daily),
            'weekly' => array_map(static function ($row) {
                return [
                    'label' => $row['label'],
                    'total' => (int)($row['total'] ?? 0),
                ];
            }, $weekly),
            'monthly' => array_map(static function ($row) {
                return [
                    'label' => $row['label'],
                    'total' => (int)($row['total'] ?? 0),
                ];
            }, $monthly),
        ];
    }
}
