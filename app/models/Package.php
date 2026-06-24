<?php
/**
 * Package Model — membership packages (monthly + other plans).
 *
 * Mirrors the Expense model conventions. Self-creates its table on installs
 * that were provisioned before this feature existed (same create-if-missing
 * approach setup.php uses for system_license), so no manual migration is
 * needed on already-deployed gyms.
 */

class Package {
    private $conn;
    private $table = 'packages';

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        try {
            $this->conn->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    duration_months INT NOT NULL DEFAULT 1,
                    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                    admission_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                    description TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_by INT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Exception $e) {
            // Table may already exist or the DB user lacks DDL rights — non-fatal.
            error_log('Package::ensureSchema: ' . $e->getMessage());
        }
    }

    private function limitString($value, int $max): string {
        $value = trim((string)$value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function money($value): string {
        return number_format((float)$value, 2, '.', '');
    }

    public function getAll($page = 1, $limit = 50, $filters = []) {
        $page = max(1, (int)$page);
        $limit = min(max(1, (int)$limit), 200);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 'is_active = :is_active';
            $params[':is_active'] = (int)$filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[] = 'name LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "SELECT * FROM {$this->table} {$whereClause} ORDER BY is_active DESC, duration_months ASC, price ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->conn->prepare($query);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM {$this->table} {$whereClause}");
        foreach ($params as $k => $v) { $countStmt->bindValue($k, $v); }
        $countStmt->execute();
        $total = (int)($countStmt->fetch()['total'] ?? 0);

        return ['data' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public function getById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function create($data) {
        $query = "INSERT INTO {$this->table}
            (name, duration_months, price, admission_fee, description, is_active, created_by)
            VALUES (:name, :duration_months, :price, :admission_fee, :description, :is_active, :created_by)";
        $stmt = $this->conn->prepare($query);
        $this->bindCommon($stmt, $data);
        $stmt->bindValue(':created_by', $data['created_by'] ?? null, isset($data['created_by']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        return $stmt->execute() ? $this->conn->lastInsertId() : false;
    }

    public function update($id, $data) {
        $query = "UPDATE {$this->table} SET
            name = :name, duration_months = :duration_months, price = :price,
            admission_fee = :admission_fee, description = :description, is_active = :is_active
            WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $this->bindCommon($stmt, $data);
        return $stmt->execute();
    }

    private function bindCommon(PDOStatement $stmt, array $data): void {
        $stmt->bindValue(':name', $this->limitString($data['name'] ?? '', 100), PDO::PARAM_STR);
        $stmt->bindValue(':duration_months', max(1, (int)($data['duration_months'] ?? 1)), PDO::PARAM_INT);
        $stmt->bindValue(':price', $this->money($data['price'] ?? 0), PDO::PARAM_STR);
        $stmt->bindValue(':admission_fee', $this->money($data['admission_fee'] ?? 0), PDO::PARAM_STR);
        $desc = $this->limitString($data['description'] ?? '', 1000);
        $stmt->bindValue(':description', $desc !== '' ? $desc : null, $desc !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':is_active', !empty($data['is_active']) ? 1 : 0, PDO::PARAM_INT);
    }

    public function delete($id) {
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE id = :id");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
