-- Migration v4.6

-- Add remarks column to change_requests for user comments on change requests
ALTER TABLE `change_requests` ADD COLUMN `remarks` TEXT DEFAULT NULL AFTER `old_value`;

-- Add target_uid tracking for row-level live locks (ensure column exists)
-- ALTER TABLE `editing_status` ADD COLUMN `target_uid` INT DEFAULT NULL;

-- Update version
UPDATE `app_settings` SET `setting_value` = '4.6' WHERE `setting_key` = 'app_version';
