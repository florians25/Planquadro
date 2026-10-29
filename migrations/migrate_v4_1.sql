-- Einsatzplanung v4.1 Migration
-- Run AFTER v3.9 migration

-- 1. Concurrent editing setting
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES ('allow_concurrent_edit', '1')
  ON DUPLICATE KEY UPDATE `setting_key`=`setting_key`;

-- 2. Update app version
UPDATE `app_settings` SET `setting_value`='4.1' WHERE `setting_key`='app_version';
