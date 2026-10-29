-- OpsPilot 0001 – bisher zur Laufzeit angelegte Spalten per Migration sicherstellen
-- (api/ical.php, pages/profil.php, api/live_status.php, api/changelog.php haben
-- ALTER TABLE in Requests ausgeführt; das entfällt ab jetzt).
-- Mehrfach ausführbar, MySQL 5.7/8 und MariaDB 10.3+.

CREATE TABLE IF NOT EXISTS `changelog` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `version` VARCHAR(20) NOT NULL,
  `release_date` DATE DEFAULT NULL,
  `content` TEXT NOT NULL,
  `entry_type` VARCHAR(20) DEFAULT 'feature',
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `users` ADD COLUMN `cal_token` VARCHAR(64) DEFAULT NULL UNIQUE', 'DO 0')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'cal_token');
PREPARE op_stmt FROM @s; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `editing_status` ADD COLUMN `target_uid` INT DEFAULT NULL', 'DO 0')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'editing_status' AND column_name = 'target_uid');
PREPARE op_stmt FROM @s; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `changelog` ADD COLUMN `entry_type` VARCHAR(20) DEFAULT ''feature'' AFTER `content`', 'DO 0')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'changelog' AND column_name = 'entry_type');
PREPARE op_stmt FROM @s; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
