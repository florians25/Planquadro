-- Migration v5.2.0
UPDATE `app_settings` SET `setting_value` = '5.2.0' WHERE `setting_key` = 'app_version';

-- Add source column to holidays if not exists
ALTER TABLE `holidays` ADD COLUMN IF NOT EXISTS `source` VARCHAR(50) DEFAULT 'manual' AFTER `holiday_type`;
