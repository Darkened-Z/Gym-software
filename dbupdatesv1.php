<?php
/**
 * dbupdatesv1.php
 * Guarded, idempotent database migration runner for the live CRM.
 *
 * Safety controls:
 * - Browser run requires admin session or DB_FIXER_TOKEN.
 * - Re-runs are skipped once the migration is marked completed.
 * - Only creates missing tables / columns / indexes / seed rows.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
}

set_time_limit(180);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function out(string $message): void {
    echo '<div style="font-family:Arial,sans-serif;margin:6px 0;">' . h($message) . '</div>';
}

function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
    $stmt->execute([':table_name' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $db, string $table, string $indexName): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name");
    $stmt->execute([':table_name' => $table, ':index_name' => $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

function constraintExists(PDO $db, string $table, string $constraintName): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = :constraint_name");
    $stmt->execute([':table_name' => $table, ':constraint_name' => $constraintName]);
    return (int)$stmt->fetchColumn() > 0;
}

function addResult(array &$results, string $status, string $item, string $detail): void {
    $results[] = compact('status', 'item', 'detail');
}

function ensureTable(PDO $db, string $table, string $sql, array &$results): void {
    if (tableExists($db, $table)) {
        addResult($results, 'skipped', $table, 'Table already exists.');
        return;
    }

    $db->exec($sql);
    addResult($results, 'added', $table, 'Table created.');
}

function ensureColumn(PDO $db, string $table, string $column, string $definition, array &$results): void {
    if (!tableExists($db, $table)) {
        addResult($results, 'error', "$table.$column", 'Table missing, column not added.');
        return;
    }

    if (columnExists($db, $table, $column)) {
        addResult($results, 'skipped', "$table.$column", 'Column already exists.');
        return;
    }

    $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    addResult($results, 'added', "$table.$column", 'Column added.');
}

function ensureIndex(PDO $db, string $table, string $indexName, string $createSql, array &$results): void {
    if (!tableExists($db, $table)) {
        addResult($results, 'error', "$table.$indexName", 'Table missing, index not added.');
        return;
    }

    if (indexExists($db, $table, $indexName)) {
        addResult($results, 'skipped', "$table.$indexName", 'Index already exists.');
        return;
    }

    $db->exec($createSql);
    addResult($results, 'added', "$table.$indexName", 'Index added.');
}

function ensureForeignKey(PDO $db, string $table, string $constraintName, string $sql, array &$results): void {
    if (!tableExists($db, $table)) {
        addResult($results, 'error', "$table.$constraintName", 'Table missing, foreign key not added.');
        return;
    }

    if (constraintExists($db, $table, $constraintName)) {
        addResult($results, 'skipped', "$table.$constraintName", 'Foreign key already exists.');
        return;
    }

    $db->exec($sql);
    addResult($results, 'added', "$table.$constraintName", 'Foreign key added.');
}

function upsertRow(PDO $db, string $table, array $row, string $uniqueColumn, array &$results, string $label = ''): void {
    if (!tableExists($db, $table)) {
        addResult($results, 'error', $label ?: $table, 'Table missing, row not inserted.');
        return;
    }

    $check = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE `$uniqueColumn` = :value LIMIT 1");
    $check->execute([':value' => $row[$uniqueColumn]]);
    $exists = (int)$check->fetchColumn() > 0;

    if ($exists) {
        addResult($results, 'skipped', $label ?: $table, 'Row already exists.');
        return;
    }

    $cols = array_keys($row);
    $placeholders = array_map(fn($c) => ':' . $c, $cols);
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $db->prepare($sql);
    foreach ($row as $col => $val) {
        $stmt->bindValue(':' . $col, $val);
    }
    $stmt->execute();
    addResult($results, 'added', $label ?: $table, 'Row inserted.');
}

function tokenMatches(): bool {
    $configured = trim((string)env('DB_FIXER_TOKEN', ''));
    if ($configured === '') {
        return false;
    }

    $provided = $_GET['token'] ?? $_POST['token'] ?? '';
    return is_string($provided) && hash_equals($configured, $provided);
}

function canRunMigration(): bool {
    if (PHP_SAPI === 'cli') {
        return true;
    }

    if (($_SESSION['role'] ?? null) === 'admin') {
        return true;
    }

    return tokenMatches();
}

$results = [];
$errors = [];
$ran = false;
$databaseName = '';
$migrationKey = 'dbupdatesv1';
$force = isset($_GET['force']) || isset($_POST['force']);

try {
    $db = (new Database())->getConnection();
    $databaseName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1><pre>' . h($e->getMessage()) . '</pre>';
    exit;
}

if (!canRunMigration()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Access denied</title></head><body style="font-family:Arial,sans-serif;padding:40px;background:#0f172a;color:#e2e8f0;">';
    echo '<h1>Access denied</h1>';
    echo '<p>Run this as an admin session or with <code>DB_FIXER_TOKEN</code>.</p>';
    echo '<p>Database: <strong>' . h($databaseName) . '</strong></p>';
    echo '</body></html>';
    exit;
}

ensureTable($db, 'db_migration_runs', "CREATE TABLE IF NOT EXISTS db_migration_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_key VARCHAR(80) NOT NULL UNIQUE,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    summary_json LONGTEXT NULL,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

$existingRun = null;
if (tableExists($db, 'db_migration_runs')) {
    $stmt = $db->prepare('SELECT status, summary_json, finished_at FROM db_migration_runs WHERE run_key = :run_key LIMIT 1');
    $stmt->execute([':run_key' => $migrationKey]);
    $existingRun = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$force && $existingRun && ($existingRun['status'] ?? '') === 'completed') {
    $ran = false;
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (($_POST['action'] ?? '') === 'run' || PHP_SAPI === 'cli' || $force)) {
    $ran = true;

    if (tableExists($db, 'db_migration_runs')) {
        $stmt = $db->prepare("INSERT INTO db_migration_runs (run_key, status, started_at) VALUES (:run_key, 'running', NOW()) ON DUPLICATE KEY UPDATE status = 'running', started_at = NOW(), finished_at = NULL, summary_json = NULL");
        $stmt->execute([':run_key' => $migrationKey]);
    }

    try {
        // Core tables used by the current codebase.
        ensureTable($db, 'users', "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'admin',
            name VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'system_license', "CREATE TABLE IF NOT EXISTS system_license (
            id INT AUTO_INCREMENT PRIMARY KEY,
            license_key VARCHAR(255) UNIQUE NOT NULL,
            server_fingerprint VARCHAR(255) NOT NULL,
            activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            INDEX idx_license_key (license_key),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'expenses', "CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_type VARCHAR(100) NOT NULL,
            description TEXT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            expense_date DATE NOT NULL,
            category VARCHAR(50) NULL,
            created_by INT NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_expense_date (expense_date),
            INDEX idx_category (category),
            INDEX idx_expense_type (expense_type),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'reports', "CREATE TABLE IF NOT EXISTS reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_name VARCHAR(255) NOT NULL,
            generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            data_json JSON NULL,
            report_type VARCHAR(50) NULL,
            INDEX idx_generated_at (generated_at),
            INDEX idx_report_type (report_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'gate_configuration', "CREATE TABLE IF NOT EXISTS gate_configuration (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gate_id VARCHAR(20) UNIQUE NOT NULL,
            gate_type ENUM('entry', 'exit') NOT NULL,
            gate_name VARCHAR(100) NOT NULL,
            location VARCHAR(255) NULL,
            esp32_ip VARCHAR(15) NULL,
            is_active TINYINT(1) DEFAULT 1,
            open_duration_ms INT DEFAULT 3000,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_type (gate_type),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'gate_activity_log', "CREATE TABLE IF NOT EXISTS gate_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gate_type ENUM('entry', 'exit') NOT NULL,
            gate_id VARCHAR(20) NOT NULL,
            rfid_uid VARCHAR(20) NOT NULL,
            member_id INT NULL,
            gender ENUM('men', 'women') NULL,
            member_name VARCHAR(255) NULL,
            action VARCHAR(50) NOT NULL,
            status ENUM('success', 'denied', 'error') NOT NULL,
            reason VARCHAR(255) NULL,
            is_fee_defaulter TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rfid (rfid_uid),
            INDEX idx_member (member_id, gender),
            INDEX idx_gate (gate_type, gate_id),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'gate_cooldown', "CREATE TABLE IF NOT EXISTS gate_cooldown (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gate_id VARCHAR(20) NOT NULL,
            rfid_uid VARCHAR(20) NOT NULL,
            last_scan TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_gate_rfid (gate_id, rfid_uid),
            INDEX idx_last_scan (last_scan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'admin_action_log', "CREATE TABLE IF NOT EXISTS admin_action_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            admin_username VARCHAR(100) NOT NULL,
            action VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) NULL,
            target_id INT NULL,
            reason TEXT NULL,
            details JSON NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_id (admin_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'system_jobs', "CREATE TABLE IF NOT EXISTS system_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(100) NOT NULL,
            last_run TIMESTAMP NULL,
            next_run TIMESTAMP NULL,
            status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
            result TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_job_name (job_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'sync_log', "CREATE TABLE IF NOT EXISTS sync_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(50) NOT NULL,
            record_id INT NOT NULL,
            record_type VARCHAR(20) NOT NULL,
            action VARCHAR(20) NOT NULL,
            synced_at DATETIME DEFAULT NULL,
            sync_status ENUM('pending', 'synced', 'failed') DEFAULT 'pending',
            sync_attempts INT DEFAULT 0,
            last_error TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_table_record (table_name, record_id),
            INDEX idx_sync_status (sync_status),
            INDEX idx_synced_at (synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'sync_sessions', "CREATE TABLE IF NOT EXISTS sync_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_type VARCHAR(20) NOT NULL,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            status ENUM('running', 'completed', 'failed') DEFAULT 'running',
            records_synced INT DEFAULT 0,
            records_failed INT DEFAULT 0,
            error_message TEXT,
            INDEX idx_status (status),
            INDEX idx_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'message_templates', "CREATE TABLE IF NOT EXISTS message_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(100) NOT NULL UNIQUE,
            template_name VARCHAR(150) NOT NULL,
            channel ENUM('whatsapp','sms','email') NOT NULL DEFAULT 'whatsapp',
            language_code VARCHAR(10) NOT NULL DEFAULT 'en',
            subject VARCHAR(255) NULL,
            body TEXT NOT NULL,
            variables_json JSON NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_template_channel (channel),
            INDEX idx_template_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'member_consent', "CREATE TABLE IF NOT EXISTS member_consent (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_table ENUM('members_men','members_women') NOT NULL,
            member_id INT NOT NULL,
            whatsapp_number VARCHAR(20) NOT NULL,
            consent_status ENUM('granted','revoked','pending') NOT NULL DEFAULT 'granted',
            consent_source VARCHAR(100) NULL,
            consent_notes TEXT NULL,
            granted_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_member_consent (member_table, member_id),
            INDEX idx_consent_status (consent_status),
            INDEX idx_whatsapp_number (whatsapp_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'message_queue', "CREATE TABLE IF NOT EXISTS message_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_table ENUM('members_men','members_women') NOT NULL,
            member_id INT NOT NULL,
            template_id INT NULL,
            channel ENUM('whatsapp','sms','email') NOT NULL DEFAULT 'whatsapp',
            recipient VARCHAR(20) NOT NULL,
            message_purpose ENUM('fee_due','fee_overdue','renewal','payment_confirmation','general') NOT NULL,
            payload_json JSON NULL,
            scheduled_for DATETIME NOT NULL,
            status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
            attempt_count INT NOT NULL DEFAULT 0,
            last_attempt_at DATETIME NULL,
            sent_at DATETIME NULL,
            failure_reason TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_queue_status_schedule (status, scheduled_for),
            INDEX idx_queue_member (member_table, member_id),
            INDEX idx_queue_purpose (message_purpose)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'message_logs', "CREATE TABLE IF NOT EXISTS message_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            queue_id INT NULL,
            member_table ENUM('members_men','members_women') NOT NULL,
            member_id INT NOT NULL,
            channel ENUM('whatsapp','sms','email') NOT NULL DEFAULT 'whatsapp',
            recipient VARCHAR(20) NOT NULL,
            message_purpose ENUM('fee_due','fee_overdue','renewal','payment_confirmation','general') NOT NULL,
            rendered_message TEXT NOT NULL,
            provider_message_id VARCHAR(191) NULL,
            delivery_status ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
            provider_response TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            delivered_at DATETIME NULL,
            INDEX idx_logs_member (member_table, member_id),
            INDEX idx_logs_status (delivery_status),
            INDEX idx_logs_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        // Member tables.
        foreach (['members_men', 'members_women'] as $table) {
            ensureTable($db, $table, "CREATE TABLE IF NOT EXISTS `$table` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_code VARCHAR(50) UNIQUE NOT NULL,
                name VARCHAR(200) NOT NULL,
                email VARCHAR(255) UNIQUE NULL,
                phone VARCHAR(20) UNIQUE NOT NULL,
                address VARCHAR(255) NULL,
                profile_image VARCHAR(255) NULL,
                membership_type VARCHAR(50) DEFAULT 'Basic',
                join_date DATE NOT NULL,
                admission_fee DECIMAL(10, 2) DEFAULT 0.00,
                monthly_fee DECIMAL(10, 2) DEFAULT 0.00,
                locker_fee DECIMAL(10, 2) DEFAULT 0.00,
                next_fee_due_date DATE NULL,
                total_due_amount DECIMAL(10, 2) DEFAULT 0.00,
                nfc_uid VARCHAR(20) UNIQUE NULL,
                rfid_uid VARCHAR(20) UNIQUE NULL,
                rfid_assigned_date DATETIME NULL,
                status ENUM('active', 'inactive') DEFAULT 'active',
                status_force_active TINYINT(1) DEFAULT 0,
                is_checked_in TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_member_code (member_code),
                INDEX idx_phone (phone),
                INDEX idx_status (status),
                INDEX idx_next_fee_due_date (next_fee_due_date),
                INDEX idx_nfc_uid (nfc_uid),
                INDEX idx_rfid_uid (rfid_uid),
                INDEX idx_email (email),
                INDEX idx_join_date (join_date),
                INDEX idx_is_checked_in (is_checked_in),
                CONSTRAINT chk_{$table}_due_non_negative CHECK (total_due_amount >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

            ensureColumn($db, $table, 'admission_fee', 'DECIMAL(10, 2) DEFAULT 0.00', $results);
            ensureColumn($db, $table, 'monthly_fee', 'DECIMAL(10, 2) DEFAULT 0.00', $results);
            ensureColumn($db, $table, 'locker_fee', 'DECIMAL(10, 2) DEFAULT 0.00', $results);
            ensureColumn($db, $table, 'next_fee_due_date', 'DATE NULL', $results);
            ensureColumn($db, $table, 'total_due_amount', 'DECIMAL(10, 2) DEFAULT 0.00', $results);
            ensureColumn($db, $table, 'nfc_uid', 'VARCHAR(20) NULL', $results);
            ensureColumn($db, $table, 'rfid_uid', 'VARCHAR(20) NULL', $results);
            ensureColumn($db, $table, 'rfid_assigned_date', 'DATETIME NULL', $results);
            ensureColumn($db, $table, 'status', "ENUM('active', 'inactive') DEFAULT 'active'", $results);
            ensureColumn($db, $table, 'status_force_active', 'TINYINT(1) DEFAULT 0', $results);
            ensureColumn($db, $table, 'is_checked_in', 'TINYINT(1) DEFAULT 0', $results);
            ensureColumn($db, $table, 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $results);
            ensureColumn($db, $table, 'updated_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $results);

            ensureIndex($db, $table, 'idx_member_code', "CREATE INDEX idx_member_code ON `$table` (member_code)", $results);
            ensureIndex($db, $table, 'idx_phone', "CREATE INDEX idx_phone ON `$table` (phone)", $results);
            ensureIndex($db, $table, 'idx_status', "CREATE INDEX idx_status ON `$table` (status)", $results);
            ensureIndex($db, $table, 'idx_next_fee_due_date', "CREATE INDEX idx_next_fee_due_date ON `$table` (next_fee_due_date)", $results);
            ensureIndex($db, $table, 'idx_nfc_uid', "CREATE INDEX idx_nfc_uid ON `$table` (nfc_uid)", $results);
            ensureIndex($db, $table, 'idx_rfid_uid', "CREATE INDEX idx_rfid_uid ON `$table` (rfid_uid)", $results);
            ensureIndex($db, $table, 'idx_email', "CREATE INDEX idx_email ON `$table` (email)", $results);
            ensureIndex($db, $table, 'idx_join_date', "CREATE INDEX idx_join_date ON `$table` (join_date)", $results);
            ensureIndex($db, $table, 'idx_is_checked_in', "CREATE INDEX idx_is_checked_in ON `$table` (is_checked_in)", $results);
        }

        // Attendance tables.
        foreach (['men', 'women'] as $gender) {
            $table = "attendance_{$gender}";
            $memberTable = "members_{$gender}";
            ensureTable($db, $table, "CREATE TABLE IF NOT EXISTS `$table` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                check_in DATETIME NOT NULL,
                check_out DATETIME NULL,
                duration_minutes INT NULL,
                is_first_entry_today TINYINT(1) DEFAULT 1,
                entry_gate_id VARCHAR(20) NULL,
                exit_gate_id VARCHAR(20) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_{$table}_member FOREIGN KEY (member_id) REFERENCES `$memberTable`(id) ON DELETE CASCADE,
                INDEX idx_member_id (member_id),
                INDEX idx_check_in (check_in),
                INDEX idx_daily_attendance (member_id, check_in),
                INDEX idx_{$gender}_active_session (member_id, check_in),
                CONSTRAINT chk_{$gender}_duration_positive CHECK (duration_minutes IS NULL OR duration_minutes >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

            ensureColumn($db, $table, 'check_in', 'DATETIME NOT NULL', $results);
            ensureColumn($db, $table, 'check_out', 'DATETIME NULL', $results);
            ensureColumn($db, $table, 'duration_minutes', 'INT NULL', $results);
            ensureColumn($db, $table, 'is_first_entry_today', 'TINYINT(1) DEFAULT 1', $results);
            ensureColumn($db, $table, 'entry_gate_id', 'VARCHAR(20) NULL', $results);
            ensureColumn($db, $table, 'exit_gate_id', 'VARCHAR(20) NULL', $results);
            ensureColumn($db, $table, 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $results);

            ensureIndex($db, $table, 'idx_member_id', "CREATE INDEX idx_member_id ON `$table` (member_id)", $results);
            ensureIndex($db, $table, 'idx_check_in', "CREATE INDEX idx_check_in ON `$table` (check_in)", $results);
            ensureIndex($db, $table, 'idx_daily_attendance', "CREATE INDEX idx_daily_attendance ON `$table` (member_id, check_in)", $results);
            ensureIndex($db, $table, 'idx_' . $gender . '_active_session', "CREATE INDEX idx_{$gender}_active_session ON `$table` (member_id, check_in)", $results);
        }

        // Payments.
        foreach (['men', 'women'] as $gender) {
            $table = "payments_{$gender}";
            $memberTable = "members_{$gender}";
            ensureTable($db, $table, "CREATE TABLE IF NOT EXISTS `$table` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                amount DECIMAL(10, 2) NOT NULL,
                remaining_amount DECIMAL(10, 2) DEFAULT 0.00,
                total_due_amount DECIMAL(10, 2) DEFAULT NULL,
                payment_date DATE NOT NULL,
                due_date DATE NULL,
                invoice_number VARCHAR(100) UNIQUE NULL,
                payment_type VARCHAR(50) NULL,
                payment_method VARCHAR(50) NULL DEFAULT 'Cash',
                received_by VARCHAR(100) NULL,
                status ENUM('pending', 'completed') DEFAULT 'completed',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_{$table}_member FOREIGN KEY (member_id) REFERENCES `$memberTable`(id) ON DELETE CASCADE,
                INDEX idx_member_id (member_id),
                INDEX idx_payment_date (payment_date),
                INDEX idx_payment_type (payment_type),
                INDEX idx_status (status),
                INDEX idx_invoice_number (invoice_number),
                CONSTRAINT chk_{$gender}_payment_positive CHECK (amount > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

            ensureColumn($db, $table, 'remaining_amount', 'DECIMAL(10, 2) DEFAULT 0.00', $results);
            ensureColumn($db, $table, 'total_due_amount', 'DECIMAL(10, 2) DEFAULT NULL', $results);
            ensureColumn($db, $table, 'payment_date', 'DATE NOT NULL', $results);
            ensureColumn($db, $table, 'due_date', 'DATE NULL', $results);
            ensureColumn($db, $table, 'invoice_number', 'VARCHAR(100) NULL', $results);
            ensureColumn($db, $table, 'payment_type', 'VARCHAR(50) NULL', $results);
            ensureColumn($db, $table, 'payment_method', "VARCHAR(50) NULL DEFAULT 'Cash'", $results);
            ensureColumn($db, $table, 'received_by', 'VARCHAR(100) NULL', $results);
            ensureColumn($db, $table, 'status', "ENUM('pending', 'completed') DEFAULT 'completed'", $results);
            ensureColumn($db, $table, 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $results);

            ensureIndex($db, $table, 'idx_member_id', "CREATE INDEX idx_member_id ON `$table` (member_id)", $results);
            ensureIndex($db, $table, 'idx_payment_date', "CREATE INDEX idx_payment_date ON `$table` (payment_date)", $results);
            ensureIndex($db, $table, 'idx_payment_type', "CREATE INDEX idx_payment_type ON `$table` (payment_type)", $results);
            ensureIndex($db, $table, 'idx_status', "CREATE INDEX idx_status ON `$table` (status)", $results);
            ensureIndex($db, $table, 'idx_invoice_number', "CREATE INDEX idx_invoice_number ON `$table` (invoice_number)", $results);
        }

        // Safe seed rows.
        upsertRow($db, 'gate_configuration', [
            'gate_id' => 'ENTRY_01',
            'gate_type' => 'entry',
            'gate_name' => 'Main Entry Gate',
            'location' => 'Front Entrance',
            'esp32_ip' => null,
            'is_active' => 1,
            'open_duration_ms' => 3000,
        ], 'gate_id', $results, 'gate_configuration ENTRY_01');

        upsertRow($db, 'gate_configuration', [
            'gate_id' => 'EXIT_01',
            'gate_type' => 'exit',
            'gate_name' => 'Main Exit Gate',
            'location' => 'Front Exit',
            'esp32_ip' => null,
            'is_active' => 1,
            'open_duration_ms' => 3000,
        ], 'gate_id', $results, 'gate_configuration EXIT_01');

        foreach ([
            ['cleanup_orphaned_sessions', null, null, 'pending'],
            ['cleanup_old_logs', null, null, 'pending'],
            ['cleanup_old_gate_activity', null, null, 'pending'],
        ] as $job) {
            upsertRow($db, 'system_jobs', [
                'job_name' => $job[0],
                'last_run' => $job[1],
                'next_run' => $job[2],
                'status' => $job[3],
                'result' => null,
            ], 'job_name', $results, 'system_jobs ' . $job[0]);
        }

        foreach ([
            [
                'template_key' => 'fee_due_basic',
                'template_name' => 'Fee Due Reminder',
                'channel' => 'whatsapp',
                'language_code' => 'en',
                'subject' => null,
                'body' => 'Assalam o Alaikum {{member_name}}, your gym fee of PKR {{amount}} is due on {{due_date}}. Please pay on time. Thanks - {{gym_name}}',
                'variables_json' => json_encode(['member_name', 'amount', 'due_date', 'gym_name']),
                'is_active' => 1,
            ],
            [
                'template_key' => 'fee_overdue_basic',
                'template_name' => 'Fee Overdue Reminder',
                'channel' => 'whatsapp',
                'language_code' => 'en',
                'subject' => null,
                'body' => 'Assalam o Alaikum {{member_name}}, your gym fee of PKR {{amount}} is overdue since {{due_date}}. Kindly clear your dues to continue gym access. Thanks - {{gym_name}}',
                'variables_json' => json_encode(['member_name', 'amount', 'due_date', 'gym_name']),
                'is_active' => 1,
            ],
            [
                'template_key' => 'payment_confirmation_basic',
                'template_name' => 'Payment Confirmation',
                'channel' => 'whatsapp',
                'language_code' => 'en',
                'subject' => null,
                'body' => 'Assalam o Alaikum {{member_name}}, we have received your payment of PKR {{amount}} on {{payment_date}}. Thank you - {{gym_name}}',
                'variables_json' => json_encode(['member_name', 'amount', 'payment_date', 'gym_name']),
                'is_active' => 1,
            ],
        ] as $template) {
            upsertRow($db, 'message_templates', $template, 'template_key', $results, 'message_templates ' . $template['template_key']);
        }

        // Foreign keys for messaging tables, if they are present.
        ensureForeignKey($db, 'message_queue', 'fk_message_queue_template', "ALTER TABLE `message_queue` ADD CONSTRAINT `fk_message_queue_template` FOREIGN KEY (`template_id`) REFERENCES `message_templates`(`id`) ON DELETE SET NULL", $results);
        ensureForeignKey($db, 'message_logs', 'fk_message_logs_queue', "ALTER TABLE `message_logs` ADD CONSTRAINT `fk_message_logs_queue` FOREIGN KEY (`queue_id`) REFERENCES `message_queue`(`id`) ON DELETE SET NULL", $results);

        $summary = [
            'database' => $databaseName,
            'ran_at' => date('c'),
            'results' => $results,
        ];
        $summaryJson = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (tableExists($db, 'db_migration_runs')) {
            $stmt = $db->prepare("UPDATE db_migration_runs SET status = 'completed', summary_json = :summary_json, finished_at = NOW() WHERE run_key = :run_key");
            $stmt->execute([':summary_json' => $summaryJson, ':run_key' => $migrationKey]);
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        if (tableExists($db, 'db_migration_runs')) {
            $stmt = $db->prepare("UPDATE db_migration_runs SET status = 'failed', summary_json = :summary_json, finished_at = NOW() WHERE run_key = :run_key");
            $stmt->execute([':summary_json' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ':run_key' => $migrationKey]);
        }
    }
}

$statusCounts = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0];
foreach ($results as $row) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>dbupdatesv1</title>
    <style>
        body { font-family: Arial, sans-serif; background:#0f172a; color:#e2e8f0; margin:0; padding:24px; }
        .container { max-width: 1080px; margin: 0 auto; }
        .card { background:#111827; border:1px solid #334155; border-radius:14px; padding:22px; margin-bottom:18px; }
        .muted { color:#94a3b8; }
        code { background:#1e293b; padding:2px 6px; border-radius:6px; }
        .btn { display:inline-block; background:#2563eb; color:#fff; border:0; border-radius:10px; padding:12px 18px; text-decoration:none; cursor:pointer; }
        .btn:hover { background:#1d4ed8; }
        .pill { display:inline-block; margin-right:8px; margin-top:8px; padding:8px 12px; border-radius:999px; font-weight:bold; }
        .added { background:rgba(34,197,94,.15); color:#86efac; }
        .updated { background:rgba(59,130,246,.15); color:#93c5fd; }
        .skipped { background:rgba(250,204,21,.15); color:#fde68a; }
        .error { background:rgba(239,68,68,.15); color:#fca5a5; }
        table { width:100%; border-collapse:collapse; margin-top:16px; }
        th, td { text-align:left; padding:12px; border-bottom:1px solid #334155; vertical-align:top; }
        th { color:#cbd5e1; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>dbupdatesv1</h1>
        <p class="muted">Database: <strong><?php echo h($databaseName); ?></strong></p>
        <p>This runner only adds missing schema and safe seed rows. It does not drop tables or overwrite member/payment data.</p>
        <div>
            <span class="pill added">Added: <?php echo (int)$statusCounts['added']; ?></span>
            <span class="pill updated">Updated: <?php echo (int)$statusCounts['updated']; ?></span>
            <span class="pill skipped">Skipped: <?php echo (int)$statusCounts['skipped']; ?></span>
            <span class="pill error">Errors: <?php echo (int)$statusCounts['error']; ?></span>
        </div>
        <?php if ($existingRun && ($existingRun['status'] ?? '') === 'completed' && !$force): ?>
            <p class="muted">This migration is already marked completed. Use <code>?force=1</code> only if you intentionally need to rerun it.</p>
        <?php endif; ?>
        <?php if (!$ran && !($existingRun && ($existingRun['status'] ?? '') === 'completed' && !$force)): ?>
            <form method="post" style="margin-top:16px;">
                <input type="hidden" name="action" value="run">
                <?php if (tokenMatches()): ?>
                    <input type="hidden" name="token" value="<?php echo h($_GET['token'] ?? $_POST['token'] ?? ''); ?>">
                <?php endif; ?>
                <button class="btn" type="submit">Run database updates</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="card">
            <h2>Errors</h2>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li class="error"><?php echo h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($results): ?>
        <div class="card">
            <h2>Execution log</h2>
            <table>
                <thead><tr><th>Status</th><th>Item</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td class="<?php echo h($row['status']); ?>"><?php echo strtoupper(h($row['status'])); ?></td>
                        <td><?php echo h($row['item']); ?></td>
                        <td><?php echo h($row['detail']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Coverage</h2>
        <ul>
            <li>Core tables: users, system_license, expenses, reports, gate_* tables, admin_action_log, system_jobs</li>
            <li>Sync tables: sync_log, sync_sessions</li>
            <li>Reminder tables: message_templates, member_consent, message_queue, message_logs</li>
            <li>Member schema: status / RFID / due / check-in compatibility columns</li>
            <li>Attendance and payment schema compatibility columns and indexes</li>
        </ul>
    </div>
</div>
</body>
</html>
