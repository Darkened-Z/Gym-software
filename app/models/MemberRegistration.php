<?php
/**
 * Member self-registration queue.
 *
 * Public visitors submit a "create profile" request from the login page; it
 * lands here as a PENDING row. An admin reviews it and, after payment, approves
 * — which creates the real member in members_<gender> and records the first
 * payment (handled in api/registrations.php).
 *
 * The rest of the app is gender-split (members_men / members_women), but a
 * pending application is not member data yet, so a single queue table with a
 * `gender` column is simpler and self-heals on older installs.
 */

class MemberRegistration {
    private $conn;
    private $table = 'member_registrations';

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureSchema();
    }

    /** Self-heal: create the queue table on installs that predate this feature. */
    private function ensureSchema(): void {
        try {
            $this->conn->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    gender ENUM('men','women') NOT NULL DEFAULT 'men',
                    name VARCHAR(200) NOT NULL,
                    phone VARCHAR(20) NOT NULL,
                    address VARCHAR(255) NULL,
                    cnic VARCHAR(20) NULL,
                    dob DATE NULL,
                    emergency_name VARCHAR(120) NULL,
                    emergency_phone VARCHAR(20) NULL,
                    note VARCHAR(500) NULL,
                    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                    assigned_member_code VARCHAR(50) NULL,
                    member_id INT NULL,
                    rejection_reason VARCHAR(255) NULL,
                    reviewed_by INT NULL,
                    reviewed_at DATETIME NULL,
                    source_ip VARCHAR(45) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_status (status),
                    INDEX idx_gender (gender),
                    INDEX idx_phone (phone)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e) {
            error_log('MemberRegistration::ensureSchema: ' . $e->getMessage());
        }
    }

    /** Self-heal: retain the extra application fields on the real member record. */
    public function ensureMemberExtraColumns(string $gender): void {
        $gender = $this->normGender($gender);
        $table = 'members_' . $gender;
        $cols = [
            'cnic' => 'VARCHAR(20) NULL',
            'dob' => 'DATE NULL',
            'emergency_name' => 'VARCHAR(120) NULL',
            'emergency_phone' => 'VARCHAR(20) NULL',
        ];
        foreach ($cols as $col => $def) {
            try {
                $chk = $this->conn->query("SHOW COLUMNS FROM {$table} LIKE " . $this->conn->quote($col));
                if ($chk && $chk->rowCount() === 0) {
                    $this->conn->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
                }
            } catch (Throwable $e) {
                error_log("MemberRegistration::ensureMemberExtraColumns {$col}: " . $e->getMessage());
            }
        }
    }

    private function limitString($value, int $max): string {
        $value = trim((string)$value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function nullable($v) {
        $v = is_string($v) ? trim($v) : $v;
        return ($v === '' || $v === null) ? null : $v;
    }

    private function normGender($g): string {
        $g = strtolower(trim((string)$g));
        return in_array($g, ['men', 'women'], true) ? $g : 'men';
    }

    /** Spam throttle: how many requests this phone/IP made recently. */
    public function recentCount(string $phone, ?string $ip, int $minutes = 10): int {
        $minutes = max(1, min(1440, $minutes));
        try {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) AS c FROM {$this->table}
                 WHERE (phone = :phone OR (source_ip IS NOT NULL AND source_ip <> '' AND source_ip = :ip))
                   AND created_at >= (NOW() - INTERVAL {$minutes} MINUTE)"
            );
            $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
            $stmt->bindValue(':ip', (string)$ip, PDO::PARAM_STR);
            $stmt->execute();
            return (int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
        } catch (Throwable $e) {
            return 0; // fail open — never block a real signup over a throttle error
        }
    }

    public function create(array $data): string {
        $sql = "INSERT INTO {$this->table}
            (gender, name, phone, address, cnic, dob, emergency_name, emergency_phone, note, source_ip, status)
            VALUES (:gender, :name, :phone, :address, :cnic, :dob, :emergency_name, :emergency_phone, :note, :source_ip, 'pending')";
        $stmt = $this->conn->prepare($sql);
        $dob = trim((string)($data['dob'] ?? ''));
        $stmt->bindValue(':gender', $this->normGender($data['gender'] ?? 'men'), PDO::PARAM_STR);
        $stmt->bindValue(':name', $this->limitString($data['name'] ?? '', 200), PDO::PARAM_STR);
        $stmt->bindValue(':phone', $this->limitString($data['phone'] ?? '', 20), PDO::PARAM_STR);
        $stmt->bindValue(':address', $this->nullable($this->limitString($data['address'] ?? '', 255)));
        $stmt->bindValue(':cnic', $this->nullable($this->limitString($data['cnic'] ?? '', 20)));
        $stmt->bindValue(':dob', $dob !== '' ? $dob : null);
        $stmt->bindValue(':emergency_name', $this->nullable($this->limitString($data['emergency_name'] ?? '', 120)));
        $stmt->bindValue(':emergency_phone', $this->nullable($this->limitString($data['emergency_phone'] ?? '', 20)));
        $stmt->bindValue(':note', $this->nullable($this->limitString($data['note'] ?? '', 500)));
        $stmt->bindValue(':source_ip', $this->nullable($this->limitString($data['source_ip'] ?? '', 45)));
        $stmt->execute();
        return $this->conn->lastInsertId();
    }

    public function getById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param string      $status 'pending' | 'approved' | 'rejected' | 'all'
     * @param string|null $gender 'men' | 'women' | null (both)
     */
    public function getAll(string $status = 'pending', ?string $gender = null, int $page = 1, int $limit = 20, string $search = ''): array {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;
        $search = trim($search);

        $where = [];
        $params = [];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }
        if ($gender === 'men' || $gender === 'women') {
            $where[] = 'gender = :gender';
            $params[':gender'] = $gender;
        }
        if ($search !== '') {
            $where[] = '(name LIKE :s OR phone LIKE :s OR cnic LIKE :s OR assigned_member_code LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} {$whereSql} ORDER BY (status='pending') DESC, created_at DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM {$this->table} {$whereSql}");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        return [
            'data' => $rows,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int)ceil($total / max(1, $limit)),
            ],
        ];
    }

    public function pendingCount(?string $gender = null): int {
        $sql = "SELECT COUNT(*) AS c FROM {$this->table} WHERE status = 'pending'";
        if ($gender === 'men' || $gender === 'women') {
            $sql .= ' AND gender = ' . $this->conn->quote($gender);
        }
        $stmt = $this->conn->query($sql);
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    }

    public function markApproved(int $id, string $memberCode, int $memberId, int $reviewerId): bool {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET status = 'approved', assigned_member_code = :code, member_id = :mid,
                 reviewed_by = :rid, reviewed_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->bindValue(':code', $this->limitString($memberCode, 50), PDO::PARAM_STR);
        $stmt->bindValue(':mid', $memberId, PDO::PARAM_INT);
        $stmt->bindValue(':rid', $reviewerId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    public function markRejected(int $id, int $reviewerId, string $reason = ''): bool {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET status = 'rejected', rejection_reason = :reason, reviewed_by = :rid, reviewed_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->bindValue(':reason', $this->nullable($this->limitString($reason, 255)));
        $stmt->bindValue(':rid', $reviewerId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /** Suggest the next free member code by scanning both gender tables. */
    public function suggestNextMemberCode(): string {
        $max = 0;
        foreach (['men', 'women'] as $g) {
            try {
                $rows = $this->conn->query("SELECT member_code FROM members_{$g}")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($rows as $code) {
                    if (preg_match('/(\d+)/', (string)$code, $m)) {
                        $n = (int)$m[1];
                        if ($n > $max) {
                            $max = $n;
                        }
                    }
                }
            } catch (Throwable $e) {
                // table may not exist yet on a brand-new install — ignore
            }
        }
        // Also avoid colliding with codes already handed out to approved requests.
        try {
            $rows = $this->conn->query("SELECT assigned_member_code FROM {$this->table} WHERE assigned_member_code IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($rows as $code) {
                if (preg_match('/(\d+)/', (string)$code, $m)) {
                    $n = (int)$m[1];
                    if ($n > $max) {
                        $max = $n;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        return (string)($max + 1);
    }
}
