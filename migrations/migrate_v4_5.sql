-- Migration v4.5.2

-- Eintrittsdatum
ALTER TABLE `users` ADD COLUMN `entry_date` DATE DEFAULT NULL AFTER `exit_date`;

-- Benachrichtigungen (falls noch nicht vorhanden)
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT,
  `link` VARCHAR(500) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_read` (`user_id`, `is_read`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB;

-- Vorgesetzte (falls noch nicht vorhanden)
CREATE TABLE IF NOT EXISTS `user_supervisors` (
  `user_id` INT NOT NULL,
  `supervisor_id` INT NOT NULL,
  PRIMARY KEY (`user_id`, `supervisor_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`supervisor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Änderungsanträge (falls noch nicht vorhanden)
CREATE TABLE IF NOT EXISTS `change_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `plan_date` DATE NOT NULL,
  `request_type` ENUM('assignment','absence') NOT NULL,
  `plan_area_id` INT DEFAULT NULL,
  `absence_type` VARCHAR(50) DEFAULT NULL,
  `old_type` VARCHAR(20) DEFAULT NULL,
  `old_value` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` TEXT DEFAULT NULL,
  `requested_by` INT NOT NULL,
  `approved_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`plan_area_id`) REFERENCES `plan_areas`(`id`) ON DELETE SET NULL,
  INDEX `idx_status` (`status`),
  INDEX `idx_user_date` (`user_id`, `plan_date`)
) ENGINE=InnoDB;

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('approval_system_enabled', '0')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

UPDATE `app_settings` SET `setting_value` = '4.5.2' WHERE `setting_key` = 'app_version';

-- Row-level lock column
ALTER TABLE `editing_status` ADD COLUMN `target_uid` INT DEFAULT NULL;
