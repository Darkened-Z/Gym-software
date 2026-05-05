-- ============================================================================
-- WhatsApp automation layer for the gym CRM
-- Adds queueing, inbound dedupe, and campaign run tracking
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS whatsapp_campaign_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_key VARCHAR(191) NOT NULL UNIQUE,
    run_type VARCHAR(80) NOT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    stats_json JSON NULL,
    error_text TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_run_type (run_type),
    INDEX idx_run_status (status),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_outbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_key VARCHAR(80) NOT NULL,
    dedupe_key VARCHAR(191) NOT NULL UNIQUE,
    member_table VARCHAR(64) NOT NULL,
    member_id INT NOT NULL,
    recipient_phone VARCHAR(30) NOT NULL,
    message_type VARCHAR(80) NOT NULL,
    payload_json JSON NULL,
    rendered_message TEXT NULL,
    scheduled_for DATETIME NOT NULL,
    status ENUM('pending','processing','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    attempt_count INT NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    provider_message_id VARCHAR(191) NULL,
    failure_reason TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_outbox_status_schedule (status, scheduled_for),
    INDEX idx_outbox_campaign (campaign_key),
    INDEX idx_outbox_member (member_table, member_id),
    INDEX idx_outbox_phone (recipient_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_inbound_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    whatsapp_message_id VARCHAR(191) NOT NULL UNIQUE,
    sender_phone VARCHAR(30) NOT NULL,
    member_table VARCHAR(64) NULL,
    member_id INT NULL,
    keyword VARCHAR(32) NULL,
    message_text TEXT NOT NULL,
    reply_text TEXT NULL,
    handled_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inbound_sender (sender_phone),
    INDEX idx_inbound_keyword (keyword),
    INDEX idx_inbound_member (member_table, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
