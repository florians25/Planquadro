-- Migration v4.6.8
UPDATE `app_settings` SET `setting_value` = '4.6.8' WHERE `setting_key` = 'app_version';

-- Add lock_expires column for server-side 30s enforcement
ALTER TABLE `editing_status` ADD COLUMN `lock_expires` TIMESTAMP DEFAULT NULL;
