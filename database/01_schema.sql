-- ============================================
-- GYM MANAGEMENT SYSTEM - COMPLETE SCHEMA
-- ============================================
-- Version: 2.2.0
-- Generated: 2025-12-17
-- ============================================

-- Create and Select Database
CREATE DATABASE IF NOT EXISTS gym_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gym_management;

-- Disable Foreign Key Checks for Drop
SET FOREIGN_KEY_CHECKS = 0;

-- Drop Existing Tables
DROP TABLE IF EXISTS gate_cooldown;
DROP TABLE IF EXISTS admin_action_log;
DROP TABLE IF EXISTS system_jobs;
DROP TABLE IF EXISTS gate_activity_log;
DROP TABLE IF EXISTS gate_configuration;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS payments_women;
DROP TABLE IF EXISTS payments_men;
DROP TABLE IF EXISTS attendance_women;
DROP TABLE IF EXISTS attendance_men;
DROP TABLE IF EXISTS members_women;
DROP TABLE IF EXISTS members_men;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS sync_log;
DROP TABLE IF EXISTS sync_sessions;
DROP TABLE IF EXISTS system_license;

-- Re-enable Foreign Key Checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- 1. USERS TABLE (Admin)
-- ============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'admin',
    name VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. SYSTEM LICENSE TABLE
-- ============================================
CREATE TABLE system_license (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(255) UNIQUE NOT NULL,
    server_fingerprint VARCHAR(255) NOT NULL,
    activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_license_key (license_key),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. MEMBERS TABLE FOR MEN
-- ============================================
CREATE TABLE members_men (
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
    is_checked_in TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_member_code (member_code),
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_next_fee_due_date (next_fee_due_date),
    INDEX idx_nfc_uid (nfc_uid),
    INDEX idx_rfid_uid (rfid_uid),
    INDEX idx_email (email),
    INDEX idx_join_date (join_date),
    INDEX idx_is_checked_in (is_checked_in),
    
    -- Constraints
    CONSTRAINT chk_men_due_non_negative CHECK (total_due_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. MEMBERS TABLE FOR WOMEN
-- ============================================
CREATE TABLE members_women (
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
    is_checked_in TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_member_code (member_code),
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_next_fee_due_date (next_fee_due_date),
    INDEX idx_nfc_uid (nfc_uid),
    INDEX idx_rfid_uid (rfid_uid),
    INDEX idx_email (email),
    INDEX idx_join_date (join_date),
    INDEX idx_is_checked_in (is_checked_in),
    
    -- Constraints
    CONSTRAINT chk_women_due_non_negative CHECK (total_due_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. ATTENDANCE TABLE FOR MEN
-- ============================================
CREATE TABLE attendance_men (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    check_in DATETIME NOT NULL,
    check_out DATETIME NULL,
    duration_minutes INT NULL,
    is_first_entry_today TINYINT(1) DEFAULT 1,
    entry_gate_id VARCHAR(20) NULL,
    exit_gate_id VARCHAR(20) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Key
    CONSTRAINT fk_attendance_men_member 
        FOREIGN KEY (member_id) REFERENCES members_men(id) ON DELETE CASCADE,
        
    -- Indexes
    INDEX idx_member_id (member_id),
    INDEX idx_check_in (check_in),
    INDEX idx_daily_attendance (member_id, check_in),
    INDEX idx_men_active_session (member_id, check_in), 
    
    -- Constraints
    CONSTRAINT chk_men_duration_positive CHECK (duration_minutes IS NULL OR duration_minutes >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. ATTENDANCE TABLE FOR WOMEN
-- ============================================
CREATE TABLE attendance_women (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    check_in DATETIME NOT NULL,
    check_out DATETIME NULL,
    duration_minutes INT NULL,
    is_first_entry_today TINYINT(1) DEFAULT 1,
    entry_gate_id VARCHAR(20) NULL,
    exit_gate_id VARCHAR(20) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Key
    CONSTRAINT fk_attendance_women_member 
        FOREIGN KEY (member_id) REFERENCES members_women(id) ON DELETE CASCADE,
        
    -- Indexes
    INDEX idx_member_id (member_id),
    INDEX idx_check_in (check_in),
    INDEX idx_daily_attendance (member_id, check_in),
    INDEX idx_women_active_session (member_id, check_in),
    
    -- Constraints
    CONSTRAINT chk_women_duration_positive CHECK (duration_minutes IS NULL OR duration_minutes >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. PAYMENTS TABLE FOR MEN
-- ============================================
CREATE TABLE payments_men (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    remaining_amount DECIMAL(10, 2) DEFAULT 0.00,
    total_due_amount DECIMAL(10, 2) DEFAULT NULL,
    payment_date DATE NOT NULL,
    due_date DATE NULL,
    invoice_number VARCHAR(100) UNIQUE NULL,
    payment_type VARCHAR(50) NULL,
    payment_method VARCHAR(50) NULL,
    received_by VARCHAR(100) NULL,
    status ENUM('pending', 'completed') DEFAULT 'completed',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Key
    CONSTRAINT fk_payments_men_member 
        FOREIGN KEY (member_id) REFERENCES members_men(id) ON DELETE CASCADE,
        
    -- Indexes
    INDEX idx_member_id (member_id),
    INDEX idx_payment_date (payment_date),
    INDEX idx_payment_type (payment_type),
    INDEX idx_status (status),
    INDEX idx_invoice_number (invoice_number),
    
    -- Constraints
    CONSTRAINT chk_men_payment_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. PAYMENTS TABLE FOR WOMEN
-- ============================================
CREATE TABLE payments_women (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    remaining_amount DECIMAL(10, 2) DEFAULT 0.00,
    total_due_amount DECIMAL(10, 2) DEFAULT NULL,
    payment_date DATE NOT NULL,
    due_date DATE NULL,
    invoice_number VARCHAR(100) UNIQUE NULL,
    payment_type VARCHAR(50) NULL,
    payment_method VARCHAR(50) NULL,
    received_by VARCHAR(100) NULL,
    status ENUM('pending', 'completed') DEFAULT 'completed',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Key
    CONSTRAINT fk_payments_women_member 
        FOREIGN KEY (member_id) REFERENCES members_women(id) ON DELETE CASCADE,
        
    -- Indexes
    INDEX idx_member_id (member_id),
    INDEX idx_payment_date (payment_date),
    INDEX idx_payment_type (payment_type),
    INDEX idx_status (status),
    INDEX idx_invoice_number (invoice_number),
    
    -- Constraints
    CONSTRAINT chk_women_payment_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. EXPENSES & REPORTS
-- ============================================
CREATE TABLE expenses (
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
    INDEX idx_expense_type (expense_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(255) NOT NULL,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_json JSON,
    report_type VARCHAR(50) NULL,
    INDEX idx_generated_at (generated_at),
    INDEX idx_report_type (report_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. GATE & SYSTEM TABLES
-- ============================================

CREATE TABLE gate_configuration (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE gate_activity_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE gate_cooldown (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gate_id VARCHAR(20) NOT NULL,
    rfid_uid VARCHAR(20) NOT NULL,
    last_scan TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_gate_rfid (gate_id, rfid_uid),
    INDEX idx_last_scan (last_scan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_action_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE system_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    last_run TIMESTAMP NULL,
    next_run TIMESTAMP NULL,
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    result TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_job_name (job_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sync_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sync_sessions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
