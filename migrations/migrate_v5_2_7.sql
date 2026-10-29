-- v5.2.7 Migration: Draft System for Planning
-- Changes are stored as drafts until published. Gesamtplan only shows published data.

CREATE TABLE IF NOT EXISTS `plan_drafts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `plan_date` DATE NOT NULL,
    `year` INT NOT NULL,
    `month` INT NOT NULL,
    `action` ENUM('set','clear') NOT NULL DEFAULT 'set',
    `change_type` VARCHAR(20) DEFAULT NULL COMMENT 'assignment, absence, locked',
    `change_value` VARCHAR(50) DEFAULT NULL COMMENT 'plan_area_id or absence_type',
    `remarks` TEXT DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_date` (`user_id`, `plan_date`),
    INDEX `idx_year_month` (`year`, `month`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate existing unpublished assignments to drafts
INSERT IGNORE INTO plan_drafts (user_id, plan_date, year, month, action, change_type, change_value, remarks, created_by, created_at)
SELECT user_id, plan_date, year, month, 'set', 'assignment', CAST(plan_area_id AS CHAR), remarks, created_by, updated_at
FROM assignments WHERE is_published = 0;

-- Migrate existing unpublished absences to drafts
INSERT IGNORE INTO plan_drafts (user_id, plan_date, year, month, action, change_type, change_value, remarks, created_by, created_at)
SELECT user_id, absence_date, YEAR(absence_date), MONTH(absence_date), 'set',
       CASE WHEN absence_type='gesperrt' THEN 'locked' ELSE 'absence' END,
       absence_type, remarks, created_by, updated_at
FROM absences WHERE is_published = 0;

-- Remove unpublished rows from real tables (now in drafts)
DELETE FROM assignments WHERE is_published = 0;
DELETE FROM absences WHERE is_published = 0;

-- Mark all remaining real data as published (should already be, but ensure consistency)
UPDATE assignments SET is_published = 1 WHERE is_published = 0;
UPDATE absences SET is_published = 1 WHERE is_published = 0;
