-- ============================================
-- GYM MANAGEMENT SYSTEM - SEED DATA
-- ============================================
-- Run this AFTER 01_schema.sql
-- ============================================

USE gym_management;

-- 1. Default Admin User
-- Password: admin123 (BCrypt encoded)
INSERT INTO users (username, password, role, name) 
VALUES ('admin', '$2y$10$7OVayNVrfaz.zWT/fZESPOSfayaFlUGqeh6j7e6IMQ4pEJvgnrA/m', 'admin', 'Administrator')
ON DUPLICATE KEY UPDATE name=name;

-- 2. Default Gate Configuration
INSERT INTO gate_configuration (gate_id, gate_type, gate_name, location, open_duration_ms) VALUES
('ENTRY_01', 'entry', 'Main Entry Gate', 'Front Entrance', 3000),
('EXIT_01', 'exit', 'Main Exit Gate', 'Front Exit', 3000)
ON DUPLICATE KEY UPDATE gate_name=gate_name;

-- 3. Default System Jobs
INSERT INTO system_jobs (job_name, status) VALUES
('cleanup_orphaned_sessions', 'pending'),
('cleanup_old_logs', 'pending'),
('cleanup_old_gate_activity', 'pending')
ON DUPLICATE KEY UPDATE job_name=job_name;

SELECT 'Success: Seed data inserted.' as result;
