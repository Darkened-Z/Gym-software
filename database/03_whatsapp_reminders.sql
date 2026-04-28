-- ============================================
-- GYM MANAGEMENT SYSTEM - WHATSAPP REMINDER MODULE
-- ============================================
-- Adds public-business reminder flows for active gym members
-- Version: 1.0.0

SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
