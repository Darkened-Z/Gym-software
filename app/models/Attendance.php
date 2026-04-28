<?php
/**
 * Attendance Model (Gender-Aware)
 */

class Attendance {
    private $conn;
    private $gender;
    private $table;

    public function __construct($db, $gender = 'men') {
        $this->conn = $db;
        $this->gender = in_array($gender, ['men', 'women'], true) ? $gender : 'men';
        $this->table = 'attendance_' . $this->gender;
    }

    private function normalizePositiveInt($value, int $default, int $max = 500): int {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: $default;
        return min($value, $max);
    }

    public function getByMemberId($memberId, $startDate = null, $endDate = null) {
        $where = 'WHERE member_id = :member_id';
        $params = [':member_id' => (int)$memberId];

        if ($startDate) {
            $where .= ' AND DATE(check_in) >= :start_date';
            $params[':start_date'] = $startDate;
        }
        if ($endDate) {
            $where .= ' AND DATE(check_in) <= :end_date';
            $params[':end_date'] = $endDate;
        }

        $query = "SELECT * FROM {$this->table} {$where} ORDER BY check_in DESC";
        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCalendarData($memberId, $year, $month) {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $query = "SELECT DATE(check_in) as date, COUNT(*) as count
                  FROM {$this->table}
                  WHERE member_id = :member_id
                    AND DATE(check_in) >= :start_date
                    AND DATE(check_in) <= :end_date
                  GROUP BY DATE(check_in)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':member_id', (int)$memberId, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
        $stmt->execute();

        $attendance = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attendance[$row['date']] = $row['count'];
        }

        return $attendance;
    }

    public function getAll($page = 1, $limit = 20, array $filters = []) {
        $page = $this->normalizePositiveInt($page, 1, 100000);
        $limit = $this->normalizePositiveInt($limit, 20, 100);
        $offset = ($page - 1) * $limit;
        $memberTable = 'members_' . $this->gender;

        $where = [];
        $params = [];

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(m.member_code LIKE :search_code OR m.name LIKE :search_name OR m.phone LIKE :search_phone OR a.entry_gate_id LIKE :search_entry_gate OR a.exit_gate_id LIKE :search_exit_gate)';
            $searchParam = '%' . $search . '%';
            $params[':search_code'] = $searchParam;
            $params[':search_name'] = $searchParam;
            $params[':search_phone'] = $searchParam;
            $params[':search_entry_gate'] = $searchParam;
            $params[':search_exit_gate'] = $searchParam;
        }

        $startDate = trim((string)($filters['start_date'] ?? ''));
        if ($startDate !== '') {
            $where[] = 'DATE(a.check_in) >= :start_date';
            $params[':start_date'] = $startDate;
        }

        $endDate = trim((string)($filters['end_date'] ?? ''));
        if ($endDate !== '') {
            $where[] = 'DATE(a.check_in) <= :end_date';
            $params[':end_date'] = $endDate;
        }

        if (!empty($filters['active_only'])) {
            $where[] = 'a.check_out IS NULL';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "SELECT a.*, m.member_code, m.name, m.phone, m.status as member_status
                  FROM {$this->table} a
                  JOIN {$memberTable} m ON a.member_id = m.id
                  {$whereClause}
                  ORDER BY a.check_in DESC, a.id DESC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countQuery = "SELECT COUNT(*) as total
                       FROM {$this->table} a
                       JOIN {$memberTable} m ON a.member_id = m.id
                       {$whereClause}";
        $countStmt = $this->conn->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int)($countStmt->fetch()['total'] ?? 0);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }

    public function create(array $data) {
        $query = "INSERT INTO {$this->table}
            (member_id, check_in, check_out, duration_minutes, is_first_entry_today, entry_gate_id, exit_gate_id)
            VALUES
            (:member_id, :check_in, :check_out, :duration_minutes, :is_first_entry_today, :entry_gate_id, :exit_gate_id)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':member_id', (int)$data['member_id'], PDO::PARAM_INT);
        $stmt->bindValue(':check_in', $data['check_in'], PDO::PARAM_STR);
        $stmt->bindValue(':check_out', $data['check_out'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':duration_minutes', $data['duration_minutes'] ?? null, isset($data['duration_minutes']) && $data['duration_minutes'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':is_first_entry_today', isset($data['is_first_entry_today']) ? (int)$data['is_first_entry_today'] : 1, PDO::PARAM_INT);
        $stmt->bindValue(':entry_gate_id', $data['entry_gate_id'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':exit_gate_id', $data['exit_gate_id'] ?? null, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function update($id, array $data): bool {
        $query = "UPDATE {$this->table} SET
                    member_id = :member_id,
                    check_in = :check_in,
                    check_out = :check_out,
                    duration_minutes = :duration_minutes,
                    is_first_entry_today = :is_first_entry_today,
                    entry_gate_id = :entry_gate_id,
                    exit_gate_id = :exit_gate_id
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':member_id', (int)$data['member_id'], PDO::PARAM_INT);
        $stmt->bindValue(':check_in', $data['check_in'], PDO::PARAM_STR);
        $stmt->bindValue(':check_out', $data['check_out'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':duration_minutes', $data['duration_minutes'] ?? null, isset($data['duration_minutes']) && $data['duration_minutes'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':is_first_entry_today', isset($data['is_first_entry_today']) ? (int)$data['is_first_entry_today'] : 1, PDO::PARAM_INT);
        $stmt->bindValue(':entry_gate_id', $data['entry_gate_id'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':exit_gate_id', $data['exit_gate_id'] ?? null, PDO::PARAM_STR);

        return $stmt->execute();
    }

    public function getTodaySummary(): array {
        $query = "SELECT
                    COUNT(*) as total_visits,
                    COUNT(DISTINCT member_id) as unique_members,
                    SUM(CASE WHEN check_out IS NULL THEN 1 ELSE 0 END) as active_sessions
                  FROM {$this->table}
                  WHERE DATE(check_in) = CURDATE()";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_visits' => (int)($result['total_visits'] ?? 0),
            'unique_members' => (int)($result['unique_members'] ?? 0),
            'active_sessions' => (int)($result['active_sessions'] ?? 0)
        ];
    }
}
