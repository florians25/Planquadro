-- Einsatzplanung v3.9 Migration
-- Run AFTER v3.8 migration

-- 1. Department field for grouping (Technik, Verwaltung, Lager)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `department` VARCHAR(50) NOT NULL DEFAULT 'technik';

-- 2. Admin role support (already in ENUM, just add if not present)
-- MySQL doesn't support ALTER ENUM easily, so we do it with modify
ALTER TABLE `users` MODIFY COLUMN `role`
  ENUM('admin','manager','teamleiter','verwaltung','mitarbeiter','lager','azubi_1','azubi_2','azubi_3') NOT NULL DEFAULT 'mitarbeiter';

-- 3. Planned maintenance table
CREATE TABLE IF NOT EXISTS `planned_maintenance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT,
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME DEFAULT NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Live editing status table
CREATE TABLE IF NOT EXISTS `editing_status` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `page` VARCHAR(100) NOT NULL,
  `year` INT NOT NULL,
  `month` INT NOT NULL,
  `last_ping` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_page` (`user_id`,`page`,`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Update app version
UPDATE `app_settings` SET `setting_value`='3.9' WHERE `setting_key`='app_version';
