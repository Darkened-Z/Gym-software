<?php
/**
 * Payment Model (Gender-Aware)
 */

class Payment {
    private $conn;
    private $gender;
    private $table;
    private $availableColumns;

    public function __construct($db, $gender = 'men') {
        $this->conn = $db;
        $this->gender = in_array($gender, ['men', 'women']) ? $gender : 'men';
        $this->table = 'payments_' . $this->gender;
        $this->availableColumns = $this->resolveAvailableColumns();
    }

    private function resolveAvailableColumns(): array {
        static $cache = [];

        if (isset($cache[$this->table])) {
            return $cache[$this->table];
        }

        $stmt = $this->conn->prepare(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
        );
        $stmt->bindValue(':table_name', $this->table, PDO::PARAM_STR);
        $stmt->execute();

        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return $cache[$this->table] = array_fill_keys($columns, true);
    }

    private function hasColumn(string $column): bool {
        return isset($this->availableColumns[$column]);
    }

    private function filterDataForSchema(array $data): array {
        $filtered = [];
        $defaults = [
            'remaining_amount' => 0.00,
            'total_due_amount' => null,
            'due_date' => null,
            'invoice_number' => null,
            'payment_type' => null,
            'payment_method' => 'Cash',
            'received_by' => null,
            'status' => 'completed',
        ];

        foreach ($defaults as $column => $defaultValue) {
            if ($this->hasColumn($column)) {
                $filtered[$column] = array_key_exists($column, $data) ? $data[$column] : $defaultValue;
            }
        }

        foreach (['member_id', 'amount', 'payment_date'] as $requiredColumn) {
            $filtered[$requiredColumn] = $data[$requiredColumn];
        }

        return $filtered;
    }

    private function bindPaymentValue(PDOStatement $stmt, string $placeholder, string $column, $value): void {
        if (in_array($column, ['member_id'], true)) {
            $stmt->bindValue($placeholder, (int)$value, PDO::PARAM_INT);
            return;
        }

        if ($value === null) {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
    }

    public function getByMemberId($memberId, $limit = null, $offset = 0) {
        $query = "SELECT * FROM " . $this->table . " WHERE member_id = :member_id ORDER BY payment_date DESC";
        
        if ($limit !== null) {
            $query .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
        
        if ($limit !== null) {
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAll($page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        $memberTable = 'members_' . $this->gender;
        
        $query = "SELECT p.*, m.member_code, m.name 
                  FROM " . $this->table . " p
                  JOIN " . $memberTable . " m ON p.member_id = m.id
                  ORDER BY p.payment_date DESC
                  LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $countQuery = "SELECT COUNT(*) as total FROM " . $this->table;
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->execute();
        $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = intval($totalResult['total'] ?? 0);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }

    public function create($data) {
        $filteredData = $this->filterDataForSchema($data);
        $columns = array_keys($filteredData);
        $placeholders = array_map(static fn($column) => ':' . $column, $columns);

        $query = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->conn->prepare($query);

        foreach ($filteredData as $column => $value) {
            $this->bindPaymentValue($stmt, ':' . $column, $column, $value);
        }

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function update($id, $data) {
        $filteredData = $this->filterDataForSchema($data);
        $assignments = [];

        foreach (array_keys($filteredData) as $column) {
            $assignments[] = "{$column} = :{$column}";
        }

        $query = "UPDATE {$this->table} SET " . implode(', ', $assignments) . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);

        foreach ($filteredData as $column => $value) {
            $this->bindPaymentValue($stmt, ':' . $column, $column, $value);
        }

        return $stmt->execute();
    }
}
