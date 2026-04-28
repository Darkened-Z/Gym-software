<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
}

set_time_limit(120);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function hasValidFixToken(): bool {
    $configured = trim((string) env('DB_FIXER_TOKEN', ''));
    if ($configured === '') {
        return false;
    }

    $provided = $_GET['token'] ?? $_POST['token'] ?? '';
    return is_string($provided) && hash_equals($configured, $provided);
}

function isAuthorizedForDbFixes(): bool {
    if (PHP_SAPI === 'cli') {
        return true;
    }

    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        return true;
    }

    return hasValidFixToken();
}

function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
    $stmt->bindValue(':table_name', $table, PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
    $stmt->bindValue(':table_name', $table, PDO::PARAM_STR);
    $stmt->bindValue(':column_name', $column, PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn() > 0;
}

function indexOnColumnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
    $stmt->bindValue(':table_name', $table, PDO::PARAM_STR);
    $stmt->bindValue(':column_name', $column, PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn() > 0;
}

function constraintExists(PDO $db, string $table, string $constraint): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = :constraint_name");
    $stmt->bindValue(':table_name', $table, PDO::PARAM_STR);
    $stmt->bindValue(':constraint_name', $constraint, PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn() > 0;
}

function runStatement(PDO $db, string $sql): void {
    $db->exec($sql);
}

function addResult(array &$results, string $status, string $title, string $detail): void {
    $results[] = [
        'status' => $status,
        'title' => $title,
        'detail' => $detail,
    ];
}

function ensureColumn(PDO $db, string $table, string $column, string $definition, array &$results): void {
    if (!tableExists($db, $table)) {
        addResult($results, 'error', "$table.$column", "Table $table does not exist, so column $column was not added.");
        return;
    }

    if (columnExists($db, $table, $column)) {
        addResult($results, 'skipped', "$table.$column", 'Already exists.');
        return;
    }

    runStatement($db, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    addResult($results, 'added', "$table.$column", 'Column added successfully.');
}

function ensureSingleColumnIndex(PDO $db, string $table, string $indexName, string $column, array &$results): void {
    if (!tableExists($db, $table)) {
        addResult($results, 'error', "$table.$indexName", "Table $table does not exist, so index $indexName was not created.");
        return;
    }

    if (indexOnColumnExists($db, $table, $column)) {
        addResult($results, 'skipped', "$table.$column", 'An index already exists on this column.');
        return;
    }

    runStatement($db, "CREATE INDEX `$indexName` ON `$table` (`$column`)");
    addResult($results, 'added', "$table.$indexName", 'Index created successfully.');
}

function ensureTable(PDO $db, string $table, string $sql, array &$results): void {
    if (tableExists($db, $table)) {
        addResult($results, 'skipped', $table, 'Table already exists.');
        return;
    }

    runStatement($db, $sql);
    addResult($results, 'added', $table, 'Table created successfully.');
}

function ensureForeignKey(PDO $db, string $table, string $constraint, string $sql, array &$results): void {
    if (!tableExists($db, $table)) {
        addResult($results, 'error', "$table.$constraint", "Table $table does not exist, so foreign key $constraint was not added.");
        return;
    }

    if (constraintExists($db, $table, $constraint)) {
        addResult($results, 'skipped', "$table.$constraint", 'Foreign key already exists.');
        return;
    }

    runStatement($db, $sql);
    addResult($results, 'added', "$table.$constraint", 'Foreign key added successfully.');
}

function ensureTemplate(PDO $db, string $templateKey, string $templateName, string $body, string $jsonVariables, array &$results): void {
    if (!tableExists($db, 'message_templates')) {
        addResult($results, 'error', $templateKey, 'message_templates table does not exist, so template could not be inserted.');
        return;
    }

    $sql = "INSERT INTO message_templates (template_key, template_name, channel, language_code, body, variables_json)
            VALUES (:template_key, :template_name, 'whatsapp', 'en', :body, :variables_json)
            ON DUPLICATE KEY UPDATE
                template_name = VALUES(template_name),
                body = VALUES(body),
                variables_json = VALUES(variables_json),
                is_active = 1";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':template_key', $templateKey, PDO::PARAM_STR);
    $stmt->bindValue(':template_name', $templateName, PDO::PARAM_STR);
    $stmt->bindValue(':body', $body, PDO::PARAM_STR);
    $stmt->bindValue(':variables_json', $jsonVariables, PDO::PARAM_STR);
    $stmt->execute();

    addResult($results, 'added', $templateKey, 'Template inserted or refreshed successfully.');
}

$results = [];
$errors = [];
$executed = false;
$databaseName = '';
$tokenValue = $_GET['token'] ?? $_POST['token'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    $databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1><pre>' . h($e->getMessage()) . '</pre>';
    exit;
}

if (!isAuthorizedForDbFixes()) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DB Fix Access Denied</title>
        <style>
            body { font-family: Arial, sans-serif; background:#0f172a; color:#e2e8f0; padding:40px; }
            .card { max-width: 780px; margin: 0 auto; background:#111827; border:1px solid #334155; border-radius:12px; padding:24px; }
            code { background:#1e293b; padding:2px 6px; border-radius:6px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Access denied</h1>
            <p>You must be logged in as <strong>admin</strong> to run this database fixer in the browser.</p>
            <p>Optional advanced access: set <code>DB_FIXER_TOKEN</code> in <code>.env</code> and open this file with <code>?token=YOUR_TOKEN</code>.</p>
            <p>Current database: <strong><?php echo h($databaseName); ?></strong></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_fixes') {
    $executed = true;

    try {
        $db->beginTransaction();

        $paymentColumns = [
            'remaining_amount' => "DECIMAL(10,2) DEFAULT 0.00 AFTER `amount`",
            'total_due_amount' => "DECIMAL(10,2) DEFAULT NULL AFTER `remaining_amount`",
            'due_date' => "DATE NULL AFTER `payment_date`",
            'invoice_number' => "VARCHAR(100) NULL AFTER `due_date`",
            'payment_type' => "VARCHAR(50) NULL AFTER `invoice_number`",
            'payment_method' => "VARCHAR(50) NULL AFTER `payment_type`",
            'received_by' => "VARCHAR(100) NULL AFTER `payment_method`",
            'status' => "ENUM('pending','completed') DEFAULT 'completed' AFTER `received_by`",
        ];

        foreach (['payments_men', 'payments_women'] as $table) {
            foreach ($paymentColumns as $column => $definition) {
                ensureColumn($db, $table, $column, $definition, $results);
            }
        }

        ensureSingleColumnIndex($db, 'payments_men', 'idx_payment_date_men', 'payment_date', $results);
        ensureSingleColumnIndex($db, 'payments_men', 'idx_invoice_number_men', 'invoice_number', $results);
        ensureSingleColumnIndex($db, 'payments_men', 'idx_status_men', 'status', $results);
        ensureSingleColumnIndex($db, 'payments_men', 'idx_payment_type_men', 'payment_type', $results);

        ensureSingleColumnIndex($db, 'payments_women', 'idx_payment_date_women', 'payment_date', $results);
        ensureSingleColumnIndex($db, 'payments_women', 'idx_invoice_number_women', 'invoice_number', $results);
        ensureSingleColumnIndex($db, 'payments_women', 'idx_status_women', 'status', $results);
        ensureSingleColumnIndex($db, 'payments_women', 'idx_payment_type_women', 'payment_type', $results);

        ensureTable($db, 'message_templates', "CREATE TABLE `message_templates` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `template_key` VARCHAR(100) NOT NULL UNIQUE,
            `template_name` VARCHAR(150) NOT NULL,
            `channel` ENUM('whatsapp','sms','email') NOT NULL DEFAULT 'whatsapp',
            `language_code` VARCHAR(10) NOT NULL DEFAULT 'en',
            `subject` VARCHAR(255) NULL,
            `body` TEXT NOT NULL,
            `variables_json` JSON NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_template_channel` (`channel`),
            INDEX `idx_template_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'member_consent', "CREATE TABLE `member_consent` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `member_table` ENUM('members_men','members_women') NOT NULL,
            `member_id` INT NOT NULL,
            `whatsapp_number` VARCHAR(20) NOT NULL,
            `consent_status` ENUM('granted','revoked','pending') NOT NULL DEFAULT 'granted',
            `consent_source` VARCHAR(100) NULL,
            `consent_notes` TEXT NULL,
            `granted_at` DATETIME NULL,
            `revoked_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_member_consent` (`member_table`, `member_id`),
            INDEX `idx_consent_status` (`consent_status`),
            INDEX `idx_whatsapp_number` (`whatsapp_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'message_queue', "CREATE TABLE `message_queue` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `member_table` ENUM('members_men','members_women') NOT NULL,
            `member_id` INT NOT NULL,
            `template_id` INT NULL,
            `channel` ENUM('whatsapp','sms','email') NOT NULL DEFAULT 'whatsapp',
            `recipient` VARCHAR(20) NOT NULL,
            `message_purpose` ENUM('fee_due','fee_overdue','renewal','payment_confirmation','general') NOT NULL,
            `payload_json` JSON NULL,
            `scheduled_for` DATETIME NOT NULL,
            `status` ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
            `attempt_count` INT NOT NULL DEFAULT 0,
            `last_attempt_at` DATETIME NULL,
            `sent_at` DATETIME NULL,
            `failure_reason` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_queue_status_schedule` (`status`, `scheduled_for`),
            INDEX `idx_queue_member` (`member_table`, `member_id`),
            INDEX `idx_queue_purpose` (`message_purpose`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureTable($db, 'message_logs', "CREATE TABLE `message_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `queue_id` INT NULL,
            `member_table` ENUM('members_men','members_women') NOT NULL,
            `member_id` INT NOT NULL,
            `channel` ENUM('whatsapp','sms','email') NOT NULL DEFAULT 'whatsapp',
            `recipient` VARCHAR(20) NOT NULL,
            `message_purpose` ENUM('fee_due','fee_overdue','renewal','payment_confirmation','general') NOT NULL,
            `rendered_message` TEXT NOT NULL,
            `provider_message_id` VARCHAR(191) NULL,
            `delivery_status` ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
            `provider_response` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `sent_at` DATETIME NULL,
            `delivered_at` DATETIME NULL,
            INDEX `idx_logs_member` (`member_table`, `member_id`),
            INDEX `idx_logs_status` (`delivery_status`),
            INDEX `idx_logs_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $results);

        ensureForeignKey($db, 'message_queue', 'fk_message_queue_template',
            "ALTER TABLE `message_queue` ADD CONSTRAINT `fk_message_queue_template` FOREIGN KEY (`template_id`) REFERENCES `message_templates`(`id`) ON DELETE SET NULL",
            $results
        );

        ensureForeignKey($db, 'message_logs', 'fk_message_logs_queue',
            "ALTER TABLE `message_logs` ADD CONSTRAINT `fk_message_logs_queue` FOREIGN KEY (`queue_id`) REFERENCES `message_queue`(`id`) ON DELETE SET NULL",
            $results
        );

        ensureTemplate(
            $db,
            'fee_due_basic',
            'Fee Due Reminder',
            'Assalam o Alaikum {{member_name}}, your gym fee of PKR {{amount}} is due on {{due_date}}. Please pay on time. Thanks - {{gym_name}}',
            json_encode(['member_name', 'amount', 'due_date', 'gym_name']),
            $results
        );

        ensureTemplate(
            $db,
            'fee_overdue_basic',
            'Fee Overdue Reminder',
            'Assalam o Alaikum {{member_name}}, your gym fee of PKR {{amount}} is overdue since {{due_date}}. Kindly clear your dues to continue gym access. Thanks - {{gym_name}}',
            json_encode(['member_name', 'amount', 'due_date', 'gym_name']),
            $results
        );

        ensureTemplate(
            $db,
            'payment_confirmation_basic',
            'Payment Confirmation',
            'Assalam o Alaikum {{member_name}}, we have received your payment of PKR {{amount}} on {{payment_date}}. Thank you - {{gym_name}}',
            json_encode(['member_name', 'amount', 'payment_date', 'gym_name']),
            $results
        );

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

$addedCount = count(array_filter($results, fn($row) => $row['status'] === 'added'));
$skippedCount = count(array_filter($results, fn($row) => $row['status'] === 'skipped'));
$errorCount = count($errors) + count(array_filter($results, fn($row) => $row['status'] === 'error'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym CRM Database Fixer</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 24px; }
        .container { max-width: 980px; margin: 0 auto; }
        .card { background: #111827; border: 1px solid #334155; border-radius: 14px; padding: 24px; margin-bottom: 20px; }
        h1, h2 { margin-top: 0; }
        .muted { color: #94a3b8; }
        .btn { background: #2563eb; color: white; border: 0; border-radius: 10px; padding: 12px 18px; font-size: 16px; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
        .summary { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .pill { padding: 10px 14px; border-radius: 999px; font-weight: bold; }
        .added { background: rgba(34,197,94,0.15); color: #86efac; }
        .skipped { background: rgba(250,204,21,0.15); color: #fde68a; }
        .error { background: rgba(239,68,68,0.15); color: #fca5a5; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #334155; vertical-align: top; }
        th { color: #cbd5e1; }
        code { background: #1e293b; padding: 2px 6px; border-radius: 6px; }
        .status-added { color: #86efac; font-weight: bold; }
        .status-skipped { color: #fde68a; font-weight: bold; }
        .status-error { color: #fca5a5; font-weight: bold; }
        .warning { background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.4); border-radius: 12px; padding: 16px; color: #fde68a; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>Gym CRM Database Fixer</h1>
            <p class="muted">File: <code>fixesindb.php</code></p>
            <p>This page only adds the newer database updates. It does <strong>not</strong> drop old tables or delete existing data.</p>
            <p><strong>Current database:</strong> <?php echo h($databaseName); ?></p>
            <div class="warning">
                Run this only on the correct gym CRM database. Best practice: take a backup in phpMyAdmin first.
            </div>
            <form method="post" style="margin-top:16px;">
                <input type="hidden" name="action" value="run_fixes">
                <?php if ($tokenValue !== ''): ?>
                    <input type="hidden" name="token" value="<?php echo h($tokenValue); ?>">
                <?php endif; ?>
                <button class="btn" type="submit">Run Database Fixes</button>
            </form>
            <?php if ($executed): ?>
                <div class="summary">
                    <div class="pill added">Added / updated: <?php echo (int) $addedCount; ?></div>
                    <div class="pill skipped">Already existed: <?php echo (int) $skippedCount; ?></div>
                    <div class="pill error">Errors: <?php echo (int) $errorCount; ?></div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="card">
                <h2>Fatal errors</h2>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li class="status-error"><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($results)): ?>
            <div class="card">
                <h2>Execution results</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Item</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td class="status-<?php echo h($row['status']); ?>"><?php echo strtoupper(h($row['status'])); ?></td>
                            <td><?php echo h($row['title']); ?></td>
                            <td><?php echo h($row['detail']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>What this file adds</h2>
            <ul>
                <li>Missing payment compatibility columns in <code>payments_men</code> and <code>payments_women</code></li>
                <li>Missing payment indexes if the column is still not indexed</li>
                <li>Reminder module tables: <code>message_templates</code>, <code>member_consent</code>, <code>message_queue</code>, <code>message_logs</code></li>
                <li>Default reminder templates</li>
            </ul>
            <p class="muted">No member or attendance tables are altered here. Legacy <code>join_date</code> / <code>admission_date</code> compatibility was handled in code.</p>
        </div>
    </div>
</body>
</html>
