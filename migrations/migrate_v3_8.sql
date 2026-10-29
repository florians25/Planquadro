-- Einsatzplanung v3.8 Migration
-- Run AFTER v3.6 migration

-- 1. New absence types: krank_attest (green K), urlaub_genehmigt (green U), gesperrt (locked)
ALTER TABLE `absences` MODIFY COLUMN `absence_type`
  ENUM('krank_halb','krank_ganz','krank_attest','urlaub_halb','urlaub_ganz','urlaub_genehmigt','gleitzeit','gesperrt') NOT NULL;

-- 2. Remarks on assignments
ALTER TABLE `assignments` ADD COLUMN IF NOT EXISTS `remarks` TEXT DEFAULT NULL;

-- 3. Remarks on absences
ALTER TABLE `absences` ADD COLUMN IF NOT EXISTS `remarks` TEXT DEFAULT NULL;

-- 4. WOT visibility toggle
ALTER TABLE `user_wots` ADD COLUMN IF NOT EXISTS `visible_to_user` TINYINT(1) NOT NULL DEFAULT 1;

-- 5. Update app version
UPDATE `app_settings` SET `setting_value`='3.8' WHERE `setting_key`='app_version';
