-- v3.6 Migration
-- Austrittsdatum
ALTER TABLE `users` ADD COLUMN `exit_date` DATE DEFAULT NULL AFTER `must_change_password`;

-- Systemeinstellungen
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`) VALUES
('maintenance_mode','0'),
('maintenance_message','Das System wird gewartet. Bitte versuchen Sie es später erneut.'),
('company_name','OpsPilot'),
('app_version','3.6');

-- v3.5 columns (if not yet applied)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `initial_password` VARCHAR(100) DEFAULT NULL;
