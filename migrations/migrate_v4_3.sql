-- Migration v4.3: Beantragungssystem (Change Request / Approval System)

-- Vorgesetzte/Genehmigende pro Mitarbeiter (m:n)
CREATE TABLE IF NOT EXISTS `user_supervisors` (
  `user_id` INT NOT NULL,
  `supervisor_id` INT NOT NULL,
  PRIMARY KEY (`user_id`, `supervisor_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`supervisor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Falls alte supervisor_id Spalte existiert: Daten migrieren
-- (Wenn supervisor_id-Spalte NICHT existiert, ignorieren die folgenden Befehle einfach)
INSERT IGNORE INTO `user_supervisors` (`user_id`, `supervisor_id`)
SELECT `id`, `supervisor_id` FROM `users` WHERE `supervisor_id` IS NOT NULL;

CREATE TABLE IF NOT EXISTS `change_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL COMMENT 'Betroffener Mitarbeiter',
  `plan_date` DATE NOT NULL,
  `request_type` ENUM('assignment','absence') NOT NULL,
  `plan_area_id` INT DEFAULT NULL COMMENT 'Nur bei assignment',
  `absence_type` VARCHAR(50) DEFAULT NULL COMMENT 'Nur bei absence',
  `old_type` VARCHAR(20) DEFAULT NULL COMMENT 'Was vorher da war: assignment/absence/empty',
  `old_value` VARCHAR(50) DEFAULT NULL COMMENT 'Alter Wert (area_id oder absence_type)',
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` TEXT DEFAULT NULL,
  `requested_by` INT NOT NULL COMMENT 'Wer hat beantragt',
  `approved_by` INT DEFAULT NULL COMMENT 'Wer hat genehmigt/abgelehnt',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`plan_area_id`) REFERENCES `plan_areas`(`id`) ON DELETE SET NULL,
  INDEX `idx_status` (`status`),
  INDEX `idx_user_date` (`user_id`, `plan_date`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB;

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('approval_system_enabled', '0')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

UPDATE `app_settings` SET `setting_value` = '4.3' WHERE `setting_key` = 'app_version';

-- Benachrichtigungssystem
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
