<?php
/**
 * Expense Model
 */

class Expense {
    private $conn;
    private $table = 'expenses';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll($page = 1, $limit = 20, $filters = []) {
        $offset = ($page - 1) * $limit;
        $where = [];
        $params = [];

        if (!empty($filters['start_date'])) {
            $where[] = "expense_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = "expense_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        if (!empty($filters['category'])) {
            $where[] = "category = :category";
            $params[':category'] = $filters['category'];
        }

        if (!empty($filters['expense_type'])) {
            $where[] = "expense_type LIKE :expense_type";
            $params[':expense_type'] = '%' . $filters['expense_type'] . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "SELECT * FROM " . $this->table . " " . $whereClause . " ORDER BY expense_date DESC, created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $countQuery = "SELECT COUNT(*) as total FROM " . $this->table . " " . $whereClause;
        $countStmt = $this->conn->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = $countStmt->fetch()['total'];

        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }

    public function getById($id) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function create($data) {
        $query = "INSERT INTO " . $this->table . " 
            (expense_type, description, amount, expense_date, category, created_by, notes) 
            VALUES 
            (:expense_type, :description, :amount, :expense_date, :category, :created_by, :notes)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':expense_type', $data['expense_type'] ?? '', PDO::PARAM_STR);
        $stmt->bindValue(':description', $data['description'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':amount', $data['amount'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':expense_date', $data['expense_date'] ?? date('Y-m-d'), PDO::PARAM_STR);
        $stmt->bindValue(':category', $data['category'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':created_by', $data['created_by'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':notes', $data['notes'] ?? null, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function update($id, $data) {
        $query = "UPDATE " . $this->table . " SET 
            expense_type = :expense_type,
            description = :description,
            amount = :amount,
            expense_date = :expense_date,
            category = :category,
            notes = :notes
            WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':expense_type', $data['expense_type'] ?? '', PDO::PARAM_STR);
        $stmt->bindValue(':description', $data['description'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':amount', $data['amount'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':expense_date', $data['expense_date'] ?? date('Y-m-d'), PDO::PARAM_STR);
        $stmt->bindValue(':category', $data['category'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':notes', $data['notes'] ?? null, PDO::PARAM_STR);

        return $stmt->execute();
    }

    public function delete($id) {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function getTotalByPeriod($startDate = null, $endDate = null) {
        $where = [];
        $params = [];

        if ($startDate) {
            $where[] = "expense_date >= :start_date";
            $params[':start_date'] = $startDate;
        }

        if ($endDate) {
            $where[] = "expense_date <= :end_date";
            $params[':end_date'] = $endDate;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "SELECT SUM(amount) as total FROM " . $this->table . " " . $whereClause;
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        
        $stmt->execute();
        $result = $stmt->fetch();
        return floatval($result['total'] ?? 0.00);
    }

    public function getCategories() {
        $query = "SELECT DISTINCT category FROM " . $this->table . " WHERE category IS NOT NULL AND category != '' ORDER BY category";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getExpenseTypes() {
        $query = "SELECT DISTINCT expense_type FROM " . $this->table . " WHERE expense_type IS NOT NULL ORDER BY expense_type";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

