-- Migration v4.6.2

-- Password expiry tracking
ALTER TABLE `users` ADD COLUMN `password_changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `must_change_password`;

-- Set current users' password_changed_at to now
UPDATE `users` SET `password_changed_at` = NOW() WHERE `password_changed_at` IS NULL;

-- Feiertage API import support: add source column
ALTER TABLE `holidays` ADD COLUMN `source` VARCHAR(50) DEFAULT 'manual' AFTER `holiday_type`;

-- Update version
UPDATE `app_settings` SET `setting_value` = '4.6.2' WHERE `setting_key` = 'app_version';

-- Password expiry days setting
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('password_expiry_days', '30')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
