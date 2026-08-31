-- ============================================
-- ACCESS ALERTS — real-time unpaid-entry feed
-- ============================================
-- Records one row when an overdue/unpaid member scans in at the gate, so the
-- dashboard can show a live notification (front desk + owner's phone). Written
-- by iclock.php / gate paths; read by api/alerts.php.
--
-- Must be created by a user with CREATE rights (the per-gym app user only has
-- SELECT/INSERT/UPDATE/DELETE, so it cannot self-create this — run this file as
-- root/admin once per gym database).
--
-- Usage:
--   mysql gym_<slug> < database/07_access_alerts.sql
-- ============================================

CREATE TABLE IF NOT EXISTS access_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    gender ENUM('men', 'women') NOT NULL,
    member_code VARCHAR(50) NULL,
    name VARCHAR(200) NULL,
    phone VARCHAR(20) NULL,
    due_date DATE NULL,
    days_overdue INT NULL,
    due_amount DECIMAL(10, 2) DEFAULT 0.00,
    entered_at DATETIME NOT NULL,
    seen TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seen (seen),
    INDEX idx_entered (entered_at),
    INDEX idx_member_day (member_id, gender, entered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
