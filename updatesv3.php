<?php
/**
 * updatesv3.php
 * Run in browser once to create/update database objects for staff users,
 * activity logs, expenses extras, RFID/member fields, and status-support updates.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

function out($text) {
    echo '<div style="font-family:Arial,sans-serif;margin:6px 0;">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
}

function runStatement(PDO $db, string $sql, string $label): void {
    $db->exec($sql);
    out('OK: ' . $label);
}

function addColumnIfMissing(PDO $db, string $table, string $column, string $definition): void {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        out("OK: added {$table}.{$column}");
    } else {
        out("SKIP: {$table}.{$column} already exists");
    }
}

function addIndexIfMissing(PDO $db, string $table, string $indexName, string $indexSql): void {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name");
    $stmt->execute([
        ':table_name' => $table,
        ':index_name' => $indexName,
    ]);

    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec($indexSql);
        out("OK: added index {$indexName} on {$table}");
    } else {
        out("SKIP: index {$indexName} on {$table} already exists");
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();

    out('Connected to database successfully.');

    runStatement($db,
        "CREATE DATABASE IF NOT EXISTS gym_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'database gym_management ensured'
    );

    runStatement($db,
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'admin',
            name VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'users table ensured'
    );

    runStatement($db,
        "CREATE TABLE IF NOT EXISTS admin_action_log (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'admin_action_log table ensured'
    );

    runStatement($db,
        "CREATE TABLE IF NOT EXISTS system_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(100) NOT NULL UNIQUE,
            last_run DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'system_jobs table ensured'
    );

    $memberTables = ['members_men', 'members_women'];
    foreach ($memberTables as $table) {
        addColumnIfMissing($db, $table, 'next_fee_due_date', 'DATE NULL');
        addColumnIfMissing($db, $table, 'total_due_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        addColumnIfMissing($db, $table, 'status', "VARCHAR(20) NOT NULL DEFAULT 'active'");
        addColumnIfMissing($db, $table, 'rfid_uid', 'VARCHAR(50) NULL');
        addColumnIfMissing($db, $table, 'is_checked_in', 'TINYINT(1) NOT NULL DEFAULT 0');
        addIndexIfMissing($db, $table, 'idx_next_fee_due_date', "CREATE INDEX idx_next_fee_due_date ON {$table} (next_fee_due_date)");
        addIndexIfMissing($db, $table, 'idx_status', "CREATE INDEX idx_status ON {$table} (status)");
        addIndexIfMissing($db, $table, 'idx_rfid_uid', "CREATE INDEX idx_rfid_uid ON {$table} (rfid_uid)");
    }

    addColumnIfMissing($db, 'expenses', 'created_by', 'INT NULL');
    addColumnIfMissing($db, 'expenses', 'notes', 'TEXT NULL');
    addIndexIfMissing($db, 'expenses', 'idx_created_by', 'CREATE INDEX idx_created_by ON expenses (created_by)');

    $defaultPasswordPlain = 'admin123';
    $defaultPasswordHash = password_hash($defaultPasswordPlain, PASSWORD_DEFAULT);
    $legacyBrokenHashes = [
        '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy',
    ];
    $defaultUsers = [
        ['admin', 'admin', 'Administrator'],
        ['staff1', 'staff', 'Staff User 1'],
        ['staff2', 'staff', 'Staff User 2'],
    ];

    $findUserStmt = $db->prepare("SELECT id, password, role, name FROM users WHERE username = :username LIMIT 1");
    $insertUserStmt = $db->prepare("INSERT INTO users (username, password, role, name) VALUES (:username, :password, :role, :name)");
    $repairUserStmt = $db->prepare("UPDATE users SET password = :password, role = :role, name = :name WHERE username = :username");

    foreach ($defaultUsers as [$username, $role, $name]) {
        $findUserStmt->execute([':username' => $username]);
        $existingUser = $findUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingUser) {
            $insertUserStmt->execute([
                ':username' => $username,
                ':password' => $defaultPasswordHash,
                ':role' => $role,
                ':name' => $name,
            ]);
            out("OK: created default user {$username} (password: {$defaultPasswordPlain})");
            continue;
        }

        if (in_array((string)($existingUser['password'] ?? ''), $legacyBrokenHashes, true)) {
            $repairUserStmt->execute([
                ':username' => $username,
                ':password' => $defaultPasswordHash,
                ':role' => $role,
                ':name' => $name,
            ]);
            out("OK: repaired legacy password hash for {$username} (password: {$defaultPasswordPlain})");
            continue;
        }

        out("SKIP: user {$username} already exists with a custom password");
    }

    foreach ($memberTables as $table) {
        $dateColumn = $table === 'members_men' || $table === 'members_women' ? 'join_date' : 'created_at';
        $statusCase = "CASE
            WHEN COALESCE(total_due_amount, 0) <= 0 THEN 'active'
            WHEN COALESCE(monthly_fee, 0) > 0
                 AND COALESCE(total_due_amount, 0) >= (COALESCE(monthly_fee, 0) * 2) - 0.01
            THEN 'inactive'
            WHEN COALESCE(next_fee_due_date, {$dateColumn}) <= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
            THEN 'inactive'
            ELSE 'active'
        END";

        $db->exec("UPDATE {$table} SET status = {$statusCase}");
        out("OK: synced member statuses for {$table}");
    }

    $authHelperPath = __DIR__ . '/app/helpers/AuthHelper.php';
    if (!file_exists($authHelperPath)) {
        file_put_contents($authHelperPath, <<<'PHP'
<?php
class AuthHelper {
    public static function currentRole(): ?string {
        return $_SESSION['role'] ?? null;
    }
    public static function isAuthenticated(): bool {
        return self::currentRole() !== null;
    }
    public static function requireRoles(array $roles): void {
        $currentRole = self::currentRole();
        if (!$currentRole || !in_array($currentRole, $roles, true)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }
    public static function requireAdmin(): void {
        self::requireRoles(['admin']);
    }
    public static function requireAdminOrStaff(): void {
        self::requireRoles(['admin', 'staff']);
    }
    public static function ensureAdminAction(string $message = 'Only admin can perform this action'): void {
        if (self::currentRole() !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    }
}
PHP
);
        out('OK: created app/helpers/AuthHelper.php');
    } else {
        out('SKIP: app/helpers/AuthHelper.php already exists');
    }

    out('Database update completed successfully.');
    out('You can now continue safely with latest staff/activity/status changes.');

    echo '<hr><h3 style="font-family:Arial,sans-serif;">Ready items</h3>';
    echo '<ul style="font-family:Arial,sans-serif;">';
    echo '<li>users / staff accounts ensured</li>';
    echo '<li>admin_action_log ensured</li>';
    echo '<li>member due/status/rfid/check-in columns ensured</li>';
    echo '<li>expenses created_by/notes ensured</li>';
    echo '<li>existing member statuses synced using latest logic</li>';
    echo '</ul>';
} catch (Throwable $e) {
    http_response_code(500);
    out('Error: ' . $e->getMessage());
}
