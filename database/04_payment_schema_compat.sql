-- ============================================
-- PAYMENT SCHEMA COMPATIBILITY MIGRATION
-- ============================================
-- Purpose:
-- Bring older deployments in line with the current payment sync/runtime code.
-- Safe to run multiple times on MariaDB/MySQL variants that support IF NOT EXISTS.
--
-- Usage:
--   mariadb gym_management < database/04_payment_schema_compat.sql
--   -- or run the statements manually in phpMyAdmin on the live database
-- ============================================

USE gym_management;

ALTER TABLE payments_men
    ADD COLUMN IF NOT EXISTS remaining_amount DECIMAL(10, 2) DEFAULT 0.00 AFTER amount,
    ADD COLUMN IF NOT EXISTS total_due_amount DECIMAL(10, 2) DEFAULT NULL AFTER remaining_amount,
    ADD COLUMN IF NOT EXISTS due_date DATE NULL AFTER payment_date,
    ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(100) NULL AFTER due_date,
    ADD COLUMN IF NOT EXISTS payment_type VARCHAR(50) NULL AFTER invoice_number,
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) NULL AFTER payment_type,
    ADD COLUMN IF NOT EXISTS received_by VARCHAR(100) NULL AFTER payment_method,
    ADD COLUMN IF NOT EXISTS status ENUM('pending', 'completed') DEFAULT 'completed' AFTER received_by;

ALTER TABLE payments_women
    ADD COLUMN IF NOT EXISTS remaining_amount DECIMAL(10, 2) DEFAULT 0.00 AFTER amount,
    ADD COLUMN IF NOT EXISTS total_due_amount DECIMAL(10, 2) DEFAULT NULL AFTER remaining_amount,
    ADD COLUMN IF NOT EXISTS due_date DATE NULL AFTER payment_date,
    ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(100) NULL AFTER due_date,
    ADD COLUMN IF NOT EXISTS payment_type VARCHAR(50) NULL AFTER invoice_number,
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) NULL AFTER payment_type,
    ADD COLUMN IF NOT EXISTS received_by VARCHAR(100) NULL AFTER payment_method,
    ADD COLUMN IF NOT EXISTS status ENUM('pending', 'completed') DEFAULT 'completed' AFTER received_by;

-- Best-effort index creation for payment lookups/sync matching.
-- Comment these out if the target database already has conflicting duplicate index names.
CREATE INDEX IF NOT EXISTS idx_payment_date_men ON payments_men (payment_date);
CREATE INDEX IF NOT EXISTS idx_invoice_number_men ON payments_men (invoice_number);
CREATE INDEX IF NOT EXISTS idx_status_men ON payments_men (status);
CREATE INDEX IF NOT EXISTS idx_payment_type_men ON payments_men (payment_type);

CREATE INDEX IF NOT EXISTS idx_payment_date_women ON payments_women (payment_date);
CREATE INDEX IF NOT EXISTS idx_invoice_number_women ON payments_women (invoice_number);
CREATE INDEX IF NOT EXISTS idx_status_women ON payments_women (status);
CREATE INDEX IF NOT EXISTS idx_payment_type_women ON payments_women (payment_type);

SELECT 'Payment schema compatibility migration completed.' AS result;
