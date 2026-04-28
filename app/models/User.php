<?php
/**
 * User Model
 */

class User {
    private $conn;
    private $table = 'users';

    private function normalizePositiveInt($value, int $default, int $max = 100): int {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: $default;
        return min($value, $max);
    }

    private function limitString($value, int $max): string {
        $value = trim((string)$value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    public function __construct($db) {
        $this->conn = $db;
    }

    public function authenticate($username, $password) {
        $query = "SELECT id, username, password, role, name FROM " . $this->table . " WHERE username = :username LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch();
            if (password_verify($password, $user['password'])) {
                return [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'name' => $user['name']
                ];
            }
        }
        return false;
    }

    public function getById($id) {
        $query = "SELECT id, username, role, name FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function getAll($page = 1, $limit = 20, $search = ''): array {
        $page = $this->normalizePositiveInt($page, 1, 100000);
        $limit = $this->normalizePositiveInt($limit, 20, 100);
        $offset = ($page - 1) * $limit;
        $search = trim((string)$search);

        $where = '';
        if ($search !== '') {
            $where = 'WHERE username LIKE :search_username OR name LIKE :search_name OR role LIKE :search_role';
        }

        $query = "SELECT id, username, role, name, created_at FROM {$this->table} {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->conn->prepare($query);
        if ($search !== '') {
            $stmt->bindValue(':search_username', '%' . $search . '%', PDO::PARAM_STR);
            $stmt->bindValue(':search_name', '%' . $search . '%', PDO::PARAM_STR);
            $stmt->bindValue(':search_role', '%' . $search . '%', PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countQuery = "SELECT COUNT(*) AS total FROM {$this->table} {$where}";
        $countStmt = $this->conn->prepare($countQuery);
        if ($search !== '') {
            $countStmt->bindValue(':search_username', '%' . $search . '%', PDO::PARAM_STR);
            $countStmt->bindValue(':search_name', '%' . $search . '%', PDO::PARAM_STR);
            $countStmt->bindValue(':search_role', '%' . $search . '%', PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int)ceil($total / max(1, $limit))
            ]
        ];
    }

    public function create(array $data) {
        $query = "INSERT INTO {$this->table} (username, password, role, name) VALUES (:username, :password, :role, :name)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':username', $this->limitString($data['username'] ?? '', 50), PDO::PARAM_STR);
        $stmt->bindValue(':password', password_hash((string)$data['password'], PASSWORD_DEFAULT), PDO::PARAM_STR);
        $stmt->bindValue(':role', $this->limitString($data['role'] ?? 'staff', 50), PDO::PARAM_STR);
        $stmt->bindValue(':name', $this->limitString($data['name'] ?? '', 100), PDO::PARAM_STR);
        $stmt->execute();
        return $this->conn->lastInsertId();
    }

    public function update($id, array $data): bool {
        $fields = [
            'username = :username',
            'role = :role',
            'name = :name'
        ];
        $query = "UPDATE {$this->table} SET " . implode(', ', $fields);

        if (!empty($data['password'])) {
            $query .= ', password = :password';
        }

        $query .= ' WHERE id = :id';
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':username', $this->limitString($data['username'] ?? '', 50), PDO::PARAM_STR);
        $stmt->bindValue(':role', $this->limitString($data['role'] ?? 'staff', 50), PDO::PARAM_STR);
        $stmt->bindValue(':name', $this->limitString($data['name'] ?? '', 100), PDO::PARAM_STR);
        if (!empty($data['password'])) {
            $stmt->bindValue(':password', password_hash((string)$data['password'], PASSWORD_DEFAULT), PDO::PARAM_STR);
        }
        return $stmt->execute();
    }

    public function delete($id): bool {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function verifyPassword(int $id, string $password): bool {
        $stmt = $this->conn->prepare("SELECT password FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && password_verify($password, $row['password']);
    }

    public function updatePassword(int $id, string $newPassword): bool {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET password = :password WHERE id = :id");
        $stmt->bindValue(':password', password_hash($newPassword, PASSWORD_DEFAULT), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }
}

