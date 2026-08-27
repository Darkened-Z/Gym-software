-- ============================================
-- TRAINERS ADD-ON MIGRATION
-- ============================================
-- Purpose:
-- Add the trainers roster + member↔trainer assignment for gyms that have
-- purchased the Trainers add-on. Safe to run multiple times.
--
-- Also self-healed at runtime by app/models/Trainer.php on first hit to
-- api/trainers.php — this file is here for gyms where an admin prefers to
-- run migrations manually.
--
-- Usage:
--   mysql gym_<slug> < database/06_trainers.sql
-- ============================================

CREATE TABLE IF NOT EXISTS trainers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL doesn't universally support ADD COLUMN IF NOT EXISTS. The runtime
-- guard in Trainer.php probes information_schema first — for a manual
-- migration these two ALTERs will error harmlessly if the column exists.
ALTER TABLE members_men
    ADD COLUMN assigned_trainer_id INT NULL,
    ADD INDEX idx_assigned_trainer_id (assigned_trainer_id);

ALTER TABLE members_women
    ADD COLUMN assigned_trainer_id INT NULL,
    ADD INDEX idx_assigned_trainer_id (assigned_trainer_id);
