-- ============================================================================
-- Gym CRM full database setup for phpMyAdmin
-- ============================================================================
-- Usage:
--   1) Open phpMyAdmin and select the existing target database.
--   2) Import this file once.
--   3) Default/shared-host flow: keep the database-create/use lines commented
--      out so the objects are created inside the database you selected.
--   4) Optional local/default flow: uncomment the lines below if you want this
--      script to create and use gym_management and your host allows it.
--
-- Notes:
--   - This file consolidates the repo's schema, seed, reminder, and compatibility
--     scripts into one import.
--   - Objects are created with IF NOT EXISTS where possible.
--   - Seed rows use ON DUPLICATE KEY UPDATE to keep re-imports safe.
-- ============================================================================

-- CREATE DATABASE IF NOT EXISTS gym_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE gym_management;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- Core auth / licensing
-- ============================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'admin',
    name VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_license (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(255) UNIQUE NOT NULL,
    server_fingerprint VARCHAR(255) NOT NULL,
    activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    expires_at DATETIME NULL,
    INDEX idx_license_key (license_key),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Members
-- ============================================================================
CREATE TABLE IF NOT EXISTS members_men (
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
    CONSTRAINT chk_men_due_non_negative CHECK (total_due_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members_women (
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
    CONSTRAINT chk_women_due_non_negative CHECK (total_due_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Attendance
-- ============================================================================
CREATE TABLE IF NOT EXISTS attendance_men (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    check_in DATETIME NOT NULL,
    check_out DATETIME NULL,
    duration_minutes INT NULL,
    is_first_entry_today TINYINT(1) DEFAULT 1,
    entry_gate_id VARCHAR(20) NULL,
    exit_gate_id VARCHAR(20) NULL,
    write_journal_id BIGINT NULL,
    write_source VARCHAR(50) NULL,
    write_signature VARCHAR(128) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_men_member FOREIGN KEY (member_id) REFERENCES members_men(id) ON DELETE CASCADE,
    INDEX idx_member_id (member_id),
    INDEX idx_check_in (check_in),
    INDEX idx_daily_attendance (member_id, check_in),
    INDEX idx_men_active_session (member_id, check_in),
    INDEX idx_write_journal_id (write_journal_id),
    INDEX idx_write_signature (write_signature),
    CONSTRAINT chk_men_duration_positive CHECK (duration_minutes IS NULL OR duration_minutes >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_women (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    check_in DATETIME NOT NULL,
    check_out DATETIME NULL,
    duration_minutes INT NULL,
    is_first_entry_today TINYINT(1) DEFAULT 1,
    entry_gate_id VARCHAR(20) NULL,
    exit_gate_id VARCHAR(20) NULL,
    write_journal_id BIGINT NULL,
    write_source VARCHAR(50) NULL,
    write_signature VARCHAR(128) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_women_member FOREIGN KEY (member_id) REFERENCES members_women(id) ON DELETE CASCADE,
    INDEX idx_member_id (member_id),
    INDEX idx_check_in (check_in),
    INDEX idx_daily_attendance (member_id, check_in),
    INDEX idx_women_active_session (member_id, check_in),
    INDEX idx_write_journal_id (write_journal_id),
    INDEX idx_write_signature (write_signature),
    CONSTRAINT chk_women_duration_positive CHECK (duration_minutes IS NULL OR duration_minutes >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_operation_journal (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    operation_type ENUM('checkin', 'checkout') NOT NULL,
    source_system VARCHAR(50) NOT NULL,
    gender ENUM('men', 'women') NOT NULL,
    member_id INT NOT NULL,
    attendance_id INT NULL,
    gate_id VARCHAR(20) NULL,
    request_signature VARCHAR(128) NULL,
    status ENUM('started', 'success', 'duplicate', 'failed') NOT NULL DEFAULT 'started',
    request_payload JSON NULL,
    response_payload JSON NULL,
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_member_operation (member_id, operation_type, created_at),
    INDEX idx_member_gate_operation (member_id, gate_id, operation_type, created_at),
    INDEX idx_attendance_id (attendance_id),
    INDEX idx_request_signature (request_signature),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Payments
-- ============================================================================
CREATE TABLE IF NOT EXISTS payments_men (
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
    CONSTRAINT fk_payments_men_member FOREIGN KEY (member_id) REFERENCES members_men(id) ON DELETE CASCADE,
    INDEX idx_member_id (member_id),
    INDEX idx_payment_date (payment_date),
    INDEX idx_payment_type (payment_type),
    INDEX idx_status (status),
    INDEX idx_invoice_number (invoice_number),
    CONSTRAINT chk_men_payment_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments_women (
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
    CONSTRAINT fk_payments_women_member FOREIGN KEY (member_id) REFERENCES members_women(id) ON DELETE CASCADE,
    INDEX idx_member_id (member_id),
    INDEX idx_payment_date (payment_date),
    INDEX idx_payment_type (payment_type),
    INDEX idx_status (status),
    INDEX idx_invoice_number (invoice_number),
    CONSTRAINT chk_women_payment_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Expenses / reports
-- ============================================================================
CREATE TABLE IF NOT EXISTS expenses (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    duration_months INT NOT NULL DEFAULT 1,
    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    admission_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(255) NOT NULL,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_json JSON NULL,
    report_type VARCHAR(50) NULL,
    INDEX idx_generated_at (generated_at),
    INDEX idx_report_type (report_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Gate / system tables
-- ============================================================================
CREATE TABLE IF NOT EXISTS gate_configuration (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gate_activity_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gate_cooldown (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gate_id VARCHAR(20) NOT NULL,
    rfid_uid VARCHAR(20) NOT NULL,
    last_scan TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_gate_rfid (gate_id, rfid_uid),
    INDEX idx_last_scan (last_scan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_action_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    last_run TIMESTAMP NULL,
    next_run TIMESTAMP NULL,
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    result TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_job_name (job_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_sessions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- WhatsApp reminder module
-- ============================================================================
CREATE TABLE IF NOT EXISTS message_templates (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_consent (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_queue (
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
    INDEX idx_queue_purpose (message_purpose),
    CONSTRAINT fk_message_queue_template FOREIGN KEY (template_id) REFERENCES message_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_logs (
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
    INDEX idx_logs_created (created_at),
    CONSTRAINT fk_message_logs_queue FOREIGN KEY (queue_id) REFERENCES message_queue(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Seed data
-- ============================================================================
INSERT INTO users (username, password, role, name)
VALUES ('admin', '$2y$10$7OVayNVrfaz.zWT/fZESPOSfayaFlUGqeh6j7e6IMQ4pEJvgnrA/m', 'admin', 'Administrator')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO gate_configuration (gate_id, gate_type, gate_name, location, open_duration_ms) VALUES
('ENTRY_01', 'entry', 'Main Entry Gate', 'Front Entrance', 3000),
('EXIT_01', 'exit', 'Main Exit Gate', 'Front Exit', 3000)
ON DUPLICATE KEY UPDATE gate_name = VALUES(gate_name), location = VALUES(location), open_duration_ms = VALUES(open_duration_ms);

INSERT INTO system_jobs (job_name, status) VALUES
('cleanup_orphaned_sessions', 'pending'),
('cleanup_old_logs', 'pending'),
('cleanup_old_gate_activity', 'pending')
ON DUPLICATE KEY UPDATE job_name = VALUES(job_name), status = VALUES(status);

INSERT INTO message_templates (template_key, template_name, channel, language_code, body, variables_json)
VALUES
('fee_due_basic', 'Fee Due Reminder', 'whatsapp', 'en', 'Assalam o Alaikum {{member_name}}, your gym fee of PKR {{amount}} is due on {{due_date}}. Please pay on time. Thanks - {{gym_name}}', JSON_ARRAY('member_name', 'amount', 'due_date', 'gym_name')),
('fee_overdue_basic', 'Fee Overdue Reminder', 'whatsapp', 'en', 'Assalam o Alaikum {{member_name}}, your gym fee of PKR {{amount}} is overdue since {{due_date}}. Kindly clear your dues to continue gym access. Thanks - {{gym_name}}', JSON_ARRAY('member_name', 'amount', 'due_date', 'gym_name')),
('payment_confirmation_basic', 'Payment Confirmation', 'whatsapp', 'en', 'Assalam o Alaikum {{member_name}}, we have received your payment of PKR {{amount}} on {{payment_date}}. Thank you - {{gym_name}}', JSON_ARRAY('member_name', 'amount', 'payment_date', 'gym_name'))
ON DUPLICATE KEY UPDATE
    template_name = VALUES(template_name),
    body = VALUES(body),
    variables_json = VALUES(variables_json),
    is_active = 1;
