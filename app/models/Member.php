<?php
/**
 * Member Model (Gender-Aware)
 */

require_once __DIR__ . '/../../config/config.php';

class Member {
    private $conn;
    private $gender;
    private $table;
    private $dateColumn;
    private $attendanceTable;
    private $hasStatusForceActive;
    private $hasPtfFee;

    public static function getStatusCaseExpression(string $joinDateExpression = 'join_date', string $attendanceTable = '', string $memberIdExpression = 'id', string $statusExpression = 'status', string $forceActiveExpression = 'status_force_active'): string {
        $attendanceRule = '';
        if ($attendanceTable !== '') {
            $attendanceRule = "\n            WHEN COALESCE((\n                SELECT MAX(att.check_in)\n                FROM {$attendanceTable} att\n                WHERE att.member_id = {$memberIdExpression}\n            ), {$joinDateExpression}) <= DATE_SUB(CURDATE(), INTERVAL 60 DAY)\n            THEN 'inactive'";
        }

        return "CASE
            WHEN COALESCE({$forceActiveExpression}, 0) = 1 THEN 'active'
            WHEN COALESCE({$statusExpression}, 'active') = 'inactive' THEN 'inactive'
            WHEN COALESCE(total_due_amount, 0) <= 0 THEN 'active'
            WHEN COALESCE(monthly_fee, 0) > 0
                 AND COALESCE(total_due_amount, 0) >= (COALESCE(monthly_fee, 0) * 2) - 0.01
            THEN 'inactive'
            WHEN COALESCE(next_fee_due_date, {$joinDateExpression}) <= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
            THEN 'inactive'
            {$attendanceRule}
            ELSE 'active'
        END";
    }

    public function __construct($db, $gender = 'men') {
        $this->conn = $db;
        $this->gender = in_array($gender, ['men', 'women'], true) ? $gender : 'men';
        $this->table = 'members_' . $this->gender;
        $this->dateColumn = resolve_member_date_column($this->conn, $this->table);
        $this->attendanceTable = 'attendance_' . $this->gender;
        $this->hasStatusForceActive = table_has_column($this->conn, $this->table, 'status_force_active');
        $this->hasPtfFee = table_has_column($this->conn, $this->table, 'ptf_fee');
    }

    private function statusForceActiveExpression(): string {
        return $this->hasStatusForceActive ? 'status_force_active' : '0';
    }

    private function statusCaseExpression(string $joinDateExpression = 'join_date', string $attendanceTable = '', string $memberIdExpression = 'id'): string {
        return self::getStatusCaseExpression(
            $joinDateExpression,
            $attendanceTable,
            $memberIdExpression,
            'status',
            $this->statusForceActiveExpression()
        );
    }

    private function normalizePositiveInt($value, int $default, int $max = 500): int {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: $default;
        return min($value, $max);
    }

    private function normalizeSortBy(?string $sortBy): string {
        $allowed = [
            'created_at' => 'm.created_at',
            'name' => 'm.name',
            'member_code' => 'm.member_code',
            'next_fee_due_date' => 'm.next_fee_due_date',
            'join_date' => 'm.' . $this->dateColumn,
            'status' => 'm.status'
        ];

        return $allowed[$sortBy] ?? 'm.created_at';
    }

    private function normalizeSortDirection(?string $sortDir): string {
        return strtoupper((string)$sortDir) === 'ASC' ? 'ASC' : 'DESC';
    }

    private function limitString($value, int $max): string {
        $value = trim((string)$value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    private function bindNullableString(PDOStatement $stmt, string $placeholder, $value): void {
        if ($value === null || $value === '') {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }
        $stmt->bindValue($placeholder, (string)$value, PDO::PARAM_STR);
    }

    private function baseSelect(): string {
        return "SELECT m.*, m.{$this->dateColumn} AS join_date, " . $this->statusCaseExpression('m.' . $this->dateColumn, $this->attendanceTable, 'm.id') . " AS calculated_status FROM {$this->table} m";
    }

    public function getAll($page = 1, $limit = 20, $search = '', $status = null, array $filters = []) {
        $page = $this->normalizePositiveInt($page, 1, 100000);
        $limit = $this->normalizePositiveInt($limit, 20, 100);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        $search = trim((string)$search);
        if ($search !== '') {
            $where[] = '(m.member_code LIKE :search_code OR m.name LIKE :search_name OR m.phone LIKE :search_phone OR m.email LIKE :search_email OR m.rfid_uid LIKE :search_rfid)';
            $searchParam = '%' . $search . '%';
            $params[':search_code'] = $searchParam;
            $params[':search_name'] = $searchParam;
            $params[':search_phone'] = $searchParam;
            $params[':search_email'] = $searchParam;
            $params[':search_rfid'] = $searchParam;
        }

        if ($status !== null && in_array($status, ['active', 'inactive'], true)) {
            // The list endpoint syncs persisted member status before querying, so filter on the stored column.
            $where[] = 'm.status = :status';
            $params[':status'] = $status;
        }

        $dueStatus = $filters['due_status'] ?? null;
        if ($dueStatus === 'overdue') {
            $where[] = 'm.next_fee_due_date IS NOT NULL AND m.next_fee_due_date < CURDATE() AND COALESCE(m.total_due_amount, 0) > 0';
        } elseif ($dueStatus === 'today') {
            $where[] = 'm.next_fee_due_date = CURDATE()';
        } elseif ($dueStatus === 'upcoming') {
            $days = $this->normalizePositiveInt($filters['due_within_days'] ?? 7, 7, 60);
            $where[] = 'm.next_fee_due_date IS NOT NULL AND m.next_fee_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :due_within_days DAY)';
            $params[':due_within_days'] = $days;
        }

        if (array_key_exists('checked_in', $filters) && $filters['checked_in'] !== null && $filters['checked_in'] !== '') {
            $checkedIn = filter_var($filters['checked_in'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($checkedIn !== null) {
                $where[] = 'm.is_checked_in = :checked_in';
                $params[':checked_in'] = $checkedIn ? 1 : 0;
            }
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sortBy = $this->normalizeSortBy($filters['sort_by'] ?? null);
        $sortDir = $this->normalizeSortDirection($filters['sort_dir'] ?? null);

        $query = $this->baseSelect() . " {$whereClause} ORDER BY {$sortBy} {$sortDir}, m.id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countQuery = "SELECT COUNT(*) as total FROM {$this->table} m {$whereClause}";
        $countStmt = $this->conn->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
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

    public function getByCode($memberCode) {
        $query = $this->baseSelect() . ' WHERE m.member_code = :member_code LIMIT 1';
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':member_code', $this->limitString($memberCode, 50), PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $query = $this->baseSelect() . ' WHERE m.id = :id LIMIT 1';
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $phone = trim((string)($data['phone'] ?? ''));
        if ($phone === '') {
            throw new InvalidArgumentException('Phone number is required.');
        }

        $columns = [
            'member_code', 'name', 'email', 'phone', 'rfid_uid', 'address', 'profile_image', 'membership_type',
            $this->dateColumn, 'admission_fee', 'monthly_fee', 'locker_fee', 'next_fee_due_date', 'total_due_amount', 'status'
        ];
        $placeholders = [
            ':member_code', ':name', ':email', ':phone', ':rfid_uid', ':address', ':profile_image', ':membership_type',
            ':join_date', ':admission_fee', ':monthly_fee', ':locker_fee', ':next_fee_due_date', ':total_due_amount', ':status'
        ];
        if ($this->hasPtfFee) {
            $columns[] = 'ptf_fee';
            $placeholders[] = ':ptf_fee';
        }
        if ($this->hasStatusForceActive) {
            $columns[] = 'status_force_active';
            $placeholders[] = ':status_force_active';
        }
        $columns[] = 'is_checked_in';
        $placeholders[] = ':is_checked_in';

        $query = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':member_code', $this->limitString($data['member_code'] ?? '', 50), PDO::PARAM_STR);
        $stmt->bindValue(':name', $this->limitString($data['name'] ?? '', 200), PDO::PARAM_STR);
        $this->bindNullableString($stmt, ':email', $this->limitString($data['email'] ?? '', 255) ?: null);
        $stmt->bindValue(':phone', $this->limitString($phone, 20), PDO::PARAM_STR);
        $this->bindNullableString($stmt, ':rfid_uid', $this->limitString($data['rfid_uid'] ?? '', 20) ?: null);
        $this->bindNullableString($stmt, ':address', $this->limitString($data['address'] ?? '', 255) ?: null);
        $this->bindNullableString($stmt, ':profile_image', $this->limitString($data['profile_image'] ?? '', 255) ?: null);
        $stmt->bindValue(':membership_type', $this->limitString($data['membership_type'] ?? 'Basic', 50), PDO::PARAM_STR);
        $stmt->bindValue(':join_date', $data['join_date'] ?? date('Y-m-d'), PDO::PARAM_STR);
        $stmt->bindValue(':admission_fee', $data['admission_fee'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':monthly_fee', $data['monthly_fee'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':locker_fee', $data['locker_fee'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':next_fee_due_date', $data['next_fee_due_date'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':total_due_amount', $data['total_due_amount'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':status', $data['status'] ?? 'active', PDO::PARAM_STR);
        if ($this->hasPtfFee) {
            $stmt->bindValue(':ptf_fee', $data['ptf_fee'] ?? 0.00, PDO::PARAM_STR);
        }
        if ($this->hasStatusForceActive) {
            $stmt->bindValue(':status_force_active', (int)($data['status_force_active'] ?? 0), PDO::PARAM_INT);
        }
        $stmt->bindValue(':is_checked_in', (int)($data['is_checked_in'] ?? 0), PDO::PARAM_INT);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function update($id, $data) {
        $phone = trim((string)($data['phone'] ?? ''));
        if ($phone === '') {
            throw new InvalidArgumentException('Phone number is required.');
        }

        $setParts = [
            'member_code = :member_code',
            'name = :name',
            'email = :email',
            'phone = :phone',
            'rfid_uid = :rfid_uid',
            'address = :address',
            'profile_image = :profile_image',
            'membership_type = :membership_type',
            $this->dateColumn . ' = :join_date',
            'admission_fee = :admission_fee',
            'monthly_fee = :monthly_fee',
            'locker_fee = :locker_fee',
            'next_fee_due_date = :next_fee_due_date',
            'total_due_amount = :total_due_amount',
            'status = :status'
        ];
        if ($this->hasPtfFee) {
            $setParts[] = 'ptf_fee = :ptf_fee';
        }
        if ($this->hasStatusForceActive) {
            $setParts[] = 'status_force_active = :status_force_active';
        }
        $query = "UPDATE {$this->table} SET\n            " . implode(",\n            ", $setParts) . "\n            WHERE id = :id";

        $expectedUpdatedAt = trim((string)($data['expected_updated_at'] ?? ''));
        if ($expectedUpdatedAt !== '') {
            $query .= ' AND updated_at = :expected_updated_at';
        }

        $currentSelect = 'SELECT status';
        if ($this->hasStatusForceActive) {
            $currentSelect .= ', COALESCE(status_force_active, 0) AS status_force_active';
        }
        $currentSelect .= " FROM {$this->table} WHERE id = :id LIMIT 1";

        $currentStmt = $this->conn->prepare($currentSelect);
        $currentStmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $currentStmt->execute();
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$current) {
            return false;
        }

        $currentStatus = in_array($current['status'] ?? 'active', ['active', 'inactive'], true) ? $current['status'] : 'active';
        $incomingStatus = in_array($data['status'] ?? $currentStatus, ['active', 'inactive'], true) ? $data['status'] ?? $currentStatus : $currentStatus;
        $statusForceActive = isset($data['status_force_active']) ? (int)$data['status_force_active'] : (int)($current['status_force_active'] ?? 0);

        if ($incomingStatus === 'inactive') {
            $statusForceActive = 0;
        } elseif ($currentStatus === 'inactive' && $incomingStatus === 'active') {
            $statusForceActive = 1;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        if ($expectedUpdatedAt !== '') {
            $stmt->bindValue(':expected_updated_at', $expectedUpdatedAt, PDO::PARAM_STR);
        }
        $stmt->bindValue(':member_code', $this->limitString($data['member_code'] ?? '', 50), PDO::PARAM_STR);
        $stmt->bindValue(':name', $this->limitString($data['name'] ?? '', 200), PDO::PARAM_STR);
        $this->bindNullableString($stmt, ':email', $this->limitString($data['email'] ?? '', 255) ?: null);
        $stmt->bindValue(':phone', $this->limitString($phone, 20), PDO::PARAM_STR);
        $this->bindNullableString($stmt, ':rfid_uid', $this->limitString($data['rfid_uid'] ?? '', 20) ?: null);
        $this->bindNullableString($stmt, ':address', $this->limitString($data['address'] ?? '', 255) ?: null);
        $this->bindNullableString($stmt, ':profile_image', $this->limitString($data['profile_image'] ?? '', 255) ?: null);
        $stmt->bindValue(':membership_type', $this->limitString($data['membership_type'] ?? 'Basic', 50), PDO::PARAM_STR);
        $stmt->bindValue(':join_date', $data['join_date'] ?? date('Y-m-d'), PDO::PARAM_STR);
        $stmt->bindValue(':admission_fee', $data['admission_fee'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':monthly_fee', $data['monthly_fee'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':locker_fee', $data['locker_fee'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':next_fee_due_date', $data['next_fee_due_date'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':total_due_amount', $data['total_due_amount'] ?? 0.00, PDO::PARAM_STR);
        $stmt->bindValue(':status', $incomingStatus, PDO::PARAM_STR);
        if ($this->hasPtfFee) {
            $stmt->bindValue(':ptf_fee', $data['ptf_fee'] ?? 0.00, PDO::PARAM_STR);
        }
        if ($this->hasStatusForceActive) {
            $stmt->bindValue(':status_force_active', $statusForceActive, PDO::PARAM_INT);
        }

        if (!$stmt->execute()) {
            return false;
        }

        return $stmt->rowCount();
    }

    public function getByRfidUid($rfidUid) {
        $query = $this->baseSelect() . ' WHERE m.rfid_uid = :rfid_uid LIMIT 1';
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':rfid_uid', trim((string)$rfidUid), PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function updateFeeDueDate($id, $date) {
        $query = "UPDATE {$this->table} SET next_fee_due_date = :date WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':date', $date, PDO::PARAM_STR);
        return $stmt->execute();
    }

    public function syncActivityStatus($id): array {
        $query = "UPDATE {$this->table} m
                  SET status = " . $this->statusCaseExpression('m.' . $this->dateColumn, $this->attendanceTable, 'm.id') . "
                  WHERE m.id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->execute();

        $statusStmt = $this->conn->prepare("SELECT status, total_due_amount, next_fee_due_date, {$this->dateColumn} AS join_date FROM {$this->table} WHERE id = :id LIMIT 1");
        $statusStmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $statusStmt->execute();
        $row = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'status' => $row['status'] ?? null,
            'total_due_amount' => (float)($row['total_due_amount'] ?? 0),
            'next_fee_due_date' => $row['next_fee_due_date'] ?? null,
            'join_date' => $row['join_date'] ?? null,
        ];
    }

    public function syncAllActivityStatuses(): array {
        $query = "UPDATE {$this->table} m
                  SET status = " . $this->statusCaseExpression('m.' . $this->dateColumn, $this->attendanceTable, 'm.id');
        $updatedStmt = $this->conn->prepare($query);
        $updatedStmt->execute();

        $summaryStmt = $this->conn->prepare("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive
            FROM {$this->table}");
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'affected_rows' => $updatedStmt->rowCount(),
            'total' => (int)($summary['total'] ?? 0),
            'active' => (int)($summary['active'] ?? 0),
            'inactive' => (int)($summary['inactive'] ?? 0),
        ];
    }

    public function getRecent($limit = 10) {
        $limit = $this->normalizePositiveInt($limit, 10, 50);
        $query = $this->baseSelect() . ' ORDER BY m.created_at DESC LIMIT :limit';
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats() {
        $statusExpr = $this->statusCaseExpression($this->dateColumn, $this->attendanceTable, 'id');
        $statsQuery = "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN {$statusExpr} = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN {$statusExpr} = 'inactive' THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN is_checked_in = 1 THEN 1 ELSE 0 END) as checked_in_now,
                SUM(CASE WHEN next_fee_due_date = CURDATE() THEN 1 ELSE 0 END) as due_today,
                SUM(CASE WHEN next_fee_due_date < CURDATE() AND COALESCE(total_due_amount, 0) > 0 THEN 1 ELSE 0 END) as overdue,
                COALESCE(SUM(CASE WHEN {$statusExpr} = 'active' THEN total_due_amount ELSE 0 END), 0) as active_due_amount
            FROM {$this->table}";

        $stmt = $this->conn->prepare($statsQuery);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($result['total'] ?? 0),
            'active' => (int)($result['active'] ?? 0),
            'inactive' => (int)($result['inactive'] ?? 0),
            'checked_in_now' => (int)($result['checked_in_now'] ?? 0),
            'due_today' => (int)($result['due_today'] ?? 0),
            'overdue' => (int)($result['overdue'] ?? 0),
            'active_due_amount' => (float)($result['active_due_amount'] ?? 0)
        ];
    }

    public function getOperationalSnapshot(): array {
        $statusExpr = $this->statusCaseExpression($this->dateColumn, $this->attendanceTable, 'id');
        $query = "SELECT
                SUM(CASE WHEN {$statusExpr} = 'active' AND is_checked_in = 1 THEN 1 ELSE 0 END) as checked_in_now,
                SUM(CASE WHEN {$statusExpr} = 'active' AND next_fee_due_date = CURDATE() THEN 1 ELSE 0 END) as due_today,
                SUM(CASE WHEN {$statusExpr} = 'active' AND next_fee_due_date < CURDATE() AND COALESCE(total_due_amount, 0) > 0 THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN {$statusExpr} = 'active' AND {$this->dateColumn} >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) as new_this_month,
                COALESCE(SUM(CASE WHEN {$statusExpr} = 'active' THEN total_due_amount ELSE 0 END), 0) as total_active_due
            FROM {$this->table}";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'checked_in_now' => (int)($result['checked_in_now'] ?? 0),
            'due_today' => (int)($result['due_today'] ?? 0),
            'overdue' => (int)($result['overdue'] ?? 0),
            'new_this_month' => (int)($result['new_this_month'] ?? 0),
            'total_active_due' => (float)($result['total_active_due'] ?? 0)
        ];
    }
}
