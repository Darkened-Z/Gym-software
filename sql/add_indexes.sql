-- Indexes for Members tables (Men)
ALTER TABLE `members_men` ADD INDEX `idx_member_code` (`member_code`);
ALTER TABLE `members_men` ADD INDEX `idx_phone` (`phone`);
ALTER TABLE `members_men` ADD INDEX `idx_email` (`email`);

-- Indexes for Members tables (Women)
ALTER TABLE `members_women` ADD INDEX `idx_member_code` (`member_code`);
ALTER TABLE `members_women` ADD INDEX `idx_phone` (`phone`);
ALTER TABLE `members_women` ADD INDEX `idx_email` (`email`);

-- Indexes for Payments tables (Men)
ALTER TABLE `payments_men` ADD INDEX `idx_member_id` (`member_id`);
ALTER TABLE `payments_men` ADD INDEX `idx_payment_date` (`payment_date`);

-- Indexes for Payments tables (Women)
ALTER TABLE `payments_women` ADD INDEX `idx_member_id` (`member_id`);
ALTER TABLE `payments_women` ADD INDEX `idx_payment_date` (`payment_date`);

-- Indexes for Attendance tables (Men)
ALTER TABLE `attendance_men` ADD INDEX `idx_member_id` (`member_id`);
ALTER TABLE `attendance_men` ADD INDEX `idx_date` (`date`);

-- Indexes for Attendance tables (Women)
ALTER TABLE `attendance_women` ADD INDEX `idx_member_id` (`member_id`);
ALTER TABLE `attendance_women` ADD INDEX `idx_date` (`date`);
