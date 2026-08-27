<?php
/**
 * Trainer Model — gym trainers (personal trainers, class instructors, coaches).
 *
 * Mirrors the Package + Member conventions. Self-creates its own table AND the
 * `assigned_trainer_id` FK columns on members_men/members_women so an existing
 * install can pick up the trainer feature without a manual migration when the
 * add-on is activated — same self-healing approach setup.php already uses for
 * system_license and Package.php uses for packages.
 */

class Trainer {
    private $conn;
    private $table = 'trainers';

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        try {
            $this->conn->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    trainer_code VARCHAR(50) UNIQUE NOT NULL,
                    name VARCHAR(200) NOT NULL,
                    phone VARCHAR(20) UNIQUE NOT NULL,
                    cnic VARCHAR(20) UNIQUE NULL,
                    dob DATE NULL,
                    hire_date DATE NOT NULL,
                    section ENUM('men', 'women', 'both') NOT NULL DEFAULT 'both',
                    monthly_salary DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                    commission_pct DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
                    address VARCHAR(255) NULL,
                    emergency_contact VARCHAR(20) NULL,
                    profile_image VARCHAR(255) NULL,
                    notes TEXT NULL,
                    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                    created_by INT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_trainer_code (trainer_code),
                    INDEX idx_phone (phone),
                    INDEX idx_status (status),
                    INDEX idx_section (section)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Exception $e) {
            error_log('Trainer::ensureSchema (trainers): ' . $e->getMessage());
        }

        // Add assigned_trainer_id FK column on the members tables. MySQL has no
        // ADD COLUMN IF NOT EXISTS across every version, so probe first.
        foreach (['members_men', 'members_women'] as $memberTable) {
            try {
                $stmt = $this->conn->prepare(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = ?
                       AND column_name = 'assigned_trainer_id'"
                );
                $stmt->execute([$memberTable]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $this->conn->exec(
                        "ALTER TABLE `{$memberTable}`
                         ADD COLUMN assigned_trainer_id INT NULL,
                         ADD INDEX idx_assigned_trainer_id (assigned_trainer_id)"
                    );
                }
            } catch (Exception $e) {
                error_log("Trainer::ensureSchema ({$memberTable}): " . $e->getMessage());
            }
        }
    }

    private function limitString($value, int $max): string {
        $value = trim((string)$value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function money($value): string {
        return number_format((float)$value, 2, '.', '');
    }

    /** Normalise incoming data. Returns [normalised, errors[]]. */
    private function normalise(array $data, bool $isCreate): array {
        $errors = [];

        $code = $this->limitString($data['trainer_code'] ?? '', 50);
        $name = $this->limitString($data['name'] ?? '', 200);
        $phone = $this->limitString($data['phone'] ?? '', 20);

        if ($name === '') $errors[] = 'Name is required';
        if ($phone === '') $errors[] = 'Phone is required';
        if ($isCreate && $code === '') $code = $this->nextCode();

        $cnic = $data['cnic'] ?? null;
        $cnic = $cnic === '' ? null : ($cnic !== null ? $this->limitString($cnic, 20) : null);

        $dob = $data['dob'] ?? null;
        $dob = $dob === '' ? null : ($dob !== null ? $this->limitString($dob, 10) : null);

        $hireDate = $data['hire_date'] ?? '';
        $hireDate = $this->limitString($hireDate, 10);
        if ($hireDate === '') $hireDate = date('Y-m-d');

        $section = strtolower(trim((string)($data['section'] ?? 'both')));
        if (!in_array($section, ['men', 'women', 'both'], true)) $section = 'both';

        $salary = $this->money($data['monthly_salary'] ?? 0);
        $commission = $this->money($data['commission_pct'] ?? 0);
        if ((float)$commission < 0 || (float)$commission > 100) $errors[] = 'Commission % must be 0–100';

        $address = $data['address'] ?? null;
        $address = $address === '' ? null : ($address !== null ? $this->limitString($address, 255) : null);

        $emergency = $data['emergency_contact'] ?? null;
        $emergency = $emergency === '' ? null : ($emergency !== null ? $this->limitString($emergency, 20) : null);

        $profileImage = $data['profile_image'] ?? null;
        $profileImage = $profileImage === '' ? null : ($profileImage !== null ? $this->limitString($profileImage, 255) : null);

        $notes = $data['notes'] ?? null;
        if ($notes !== null && $notes !== '') {
            $notes = trim((string)$notes);
            if (function_exists('mb_substr')) $notes = mb_substr($notes, 0, 2000);
        } else {
            $notes = null;
        }

        $status = strtolower(trim((string)($data['status'] ?? 'active')));
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

        return [[
            'trainer_code' => $code,
            'name' => $name,
            'phone' => $phone,
            'cnic' => $cnic,
            'dob' => $dob,
            'hire_date' => $hireDate,
            'section' => $section,
            'monthly_salary' => $salary,
            'commission_pct' => $commission,
            'address' => $address,
            'emergency_contact' => $emergency,
            'profile_image' => $profileImage,
            'notes' => $notes,
            'status' => $status,
        ], $errors];
    }

    /** Auto-generate the next trainer code — TRN-0001, TRN-0002, … */
    private function nextCode(): string {
        try {
            $row = $this->conn->query(
                "SELECT MAX(CAST(SUBSTRING(trainer_code, 5) AS UNSIGNED)) AS m
                 FROM {$this->table}
                 WHERE trainer_code LIKE 'TRN-%'"
            )->fetch();
            $n = ((int)($row['m'] ?? 0)) + 1;
        } catch (Exception $e) {
            $n = 1;
        }
        return 'TRN-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * List trainers with member-count and optional search / section / status filters.
     * $page 1-indexed, $limit clamped 1..100 by the API layer.
     */
    public function getAll(int $page = 1, int $limit = 20, string $search = '', string $section = '', string $status = ''): array {
        $offset = max(0, ($page - 1) * $limit);
        $where = [];
        $bind = [];

        if ($search !== '') {
            $where[] = '(t.name LIKE :q OR t.trainer_code LIKE :q OR t.phone LIKE :q OR t.cnic LIKE :q)';
            $bind[':q'] = '%' . $search . '%';
        }
        if ($section === 'men' || $section === 'women' || $section === 'both') {
            $where[] = 't.section = :section';
            $bind[':section'] = $section;
        }
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 't.status = :status';
            $bind[':status'] = $status;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Assigned-members count uses two lookups (one per members table).
        $sql = "SELECT t.*,
                       COALESCE(mm.cnt, 0) + COALESCE(mw.cnt, 0) AS assigned_count
                FROM {$this->table} t
                LEFT JOIN (SELECT assigned_trainer_id, COUNT(*) cnt FROM members_men
                           WHERE assigned_trainer_id IS NOT NULL GROUP BY assigned_trainer_id) mm
                       ON mm.assigned_trainer_id = t.id
                LEFT JOIN (SELECT assigned_trainer_id, COUNT(*) cnt FROM members_women
                           WHERE assigned_trainer_id IS NOT NULL GROUP BY assigned_trainer_id) mw
                       ON mw.assigned_trainer_id = t.id
                {$whereSql}
                ORDER BY t.status = 'active' DESC, t.name ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} t {$whereSql}");
        foreach ($bind as $k => $v) $countStmt->bindValue($k, $v);
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        return [
            'data' => $rows,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int)ceil($total / max(1, $limit)),
        ];
    }

    public function getById(int $id): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Simplified list for dropdowns — id, name, section, status. */
    public function getForSelect(string $section = ''): array {
        $sql = "SELECT id, trainer_code, name, section, status
                FROM {$this->table}
                WHERE status = 'active'";
        $params = [];
        if ($section === 'men' || $section === 'women') {
            $sql .= " AND section IN ('both', :section)";
            $params[':section'] = $section;
        }
        $sql .= ' ORDER BY name ASC';
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data, ?int $createdBy = null): array {
        [$n, $errors] = $this->normalise($data, true);
        if ($errors) return ['success' => false, 'message' => implode('. ', $errors)];

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO {$this->table}
                    (trainer_code, name, phone, cnic, dob, hire_date, section,
                     monthly_salary, commission_pct, address, emergency_contact,
                     profile_image, notes, status, created_by)
                 VALUES
                    (:trainer_code, :name, :phone, :cnic, :dob, :hire_date, :section,
                     :monthly_salary, :commission_pct, :address, :emergency_contact,
                     :profile_image, :notes, :status, :created_by)"
            );
            $stmt->execute($n + [':created_by' => $createdBy]);
            return ['success' => true, 'id' => (int)$this->conn->lastInsertId(), 'trainer_code' => $n['trainer_code']];
        } catch (PDOException $e) {
            // Unique-constraint hit on code / phone / cnic
            if ($e->errorInfo[1] ?? 0 === 1062) {
                $msg = $e->getMessage();
                if (strpos($msg, 'trainer_code') !== false) return ['success' => false, 'message' => 'Trainer code already exists'];
                if (strpos($msg, 'phone') !== false) return ['success' => false, 'message' => 'A trainer with this phone already exists'];
                if (strpos($msg, 'cnic') !== false) return ['success' => false, 'message' => 'A trainer with this CNIC already exists'];
            }
            error_log('Trainer::create ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to save trainer'];
        }
    }

    public function update(int $id, array $data): array {
        if (!$this->getById($id)) return ['success' => false, 'message' => 'Trainer not found'];
        [$n, $errors] = $this->normalise($data, false);
        if ($errors) return ['success' => false, 'message' => implode('. ', $errors)];

        try {
            $stmt = $this->conn->prepare(
                "UPDATE {$this->table} SET
                    trainer_code = :trainer_code, name = :name, phone = :phone, cnic = :cnic,
                    dob = :dob, hire_date = :hire_date, section = :section,
                    monthly_salary = :monthly_salary, commission_pct = :commission_pct,
                    address = :address, emergency_contact = :emergency_contact,
                    profile_image = :profile_image, notes = :notes, status = :status
                 WHERE id = :id"
            );
            $stmt->execute($n + [':id' => $id]);
            return ['success' => true];
        } catch (PDOException $e) {
            if ($e->errorInfo[1] ?? 0 === 1062) {
                return ['success' => false, 'message' => 'Trainer code, phone, or CNIC already exists on another trainer'];
            }
            error_log('Trainer::update ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update trainer'];
        }
    }

    /** Soft-delete: status → inactive. Existing member assignments stay so history
     *  and revenue attribution isn't lost — the trainer just stops appearing in
     *  new-assignment dropdowns via getForSelect(). */
    public function deactivate(int $id): array {
        if (!$this->getById($id)) return ['success' => false, 'message' => 'Trainer not found'];
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        return ['success' => true];
    }

    public function activate(int $id): array {
        if (!$this->getById($id)) return ['success' => false, 'message' => 'Trainer not found'];
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET status = 'active' WHERE id = ?");
        $stmt->execute([$id]);
        return ['success' => true];
    }
}
