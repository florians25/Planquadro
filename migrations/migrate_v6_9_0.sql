-- =============================================================================
-- v6.9.0 – Gespeicherte Entwurfsplanungen und Modul „Abwesenheiten“
-- Copyright (c) 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten.
-- -----------------------------------------------------------------------------
-- Setzt migrate_v6_8_0.sql voraus. Mehrfach ausfuehrbar.
-- =============================================================================

-- --------------------------------------------------------------------------
-- 1. Gespeicherte Planungen
--    planning_runs traegt bereits config_json und result_json. Ergaenzt werden
--    ein sprechender Name, ein Aenderungsdatum und die Freigabe fuer Kollegen.
-- --------------------------------------------------------------------------
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `planning_runs` ADD COLUMN `is_saved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planning_runs' AND COLUMN_NAME = 'is_saved');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `planning_runs` ADD COLUMN `shared` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_saved`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planning_runs' AND COLUMN_NAME = 'shared');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `planning_runs` ADD COLUMN `updated_at` DATETIME NULL DEFAULT NULL AFTER `created_at`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planning_runs' AND COLUMN_NAME = 'updated_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `planning_runs` ADD INDEX `idx_saved` (`is_saved`, `created_by`)',
  'SELECT 1') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planning_runs' AND INDEX_NAME = 'idx_saved');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- --------------------------------------------------------------------------
-- 2. Vertretung bei Abwesenheiten
--    NULL bedeutet ersatzlos – das ist der Regelfall und bleibt Standard.
-- --------------------------------------------------------------------------
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `absences` ADD COLUMN `substitute_user_id` INT(11) NULL DEFAULT NULL AFTER `is_internal`',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'absences' AND COLUMN_NAME = 'substitute_user_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `absences` ADD INDEX `idx_substitute` (`substitute_user_id`, `absence_date`)',
  'SELECT 1') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'absences' AND INDEX_NAME = 'idx_substitute');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- --------------------------------------------------------------------------
-- 3. Berechtigung fuer das Modul „Abwesenheiten“
--    Wer bereits Abwesenheiten pflegen darf, bekommt sie automatisch.
-- --------------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role, permission, granted)
SELECT DISTINCT role, 'manage_absences', granted FROM role_permissions WHERE permission = 'plan_edit';

INSERT IGNORE INTO role_permissions (role, permission, granted) VALUES
('admin','manage_absences',1),
('manager','manage_absences',1),
('teamleiter','manage_absences',1),
('verwaltung','manage_absences',1),
('lager','manage_absences',0),
('mitarbeiter','manage_absences',0),
('azubi_1','manage_absences',0),
('azubi_2','manage_absences',0),
('azubi_3','manage_absences',0);

UPDATE role_permissions SET granted = 1
 WHERE permission = 'manage_absences' AND role IN ('admin','manager','teamleiter');

-- --------------------------------------------------------------------------
-- 4. Changelog
-- --------------------------------------------------------------------------
DELETE FROM `changelog` WHERE `version` = '6.9.0';

INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.9.0', CURDATE(),
'Planungsassistent und neues Modul Abwesenheiten\n• Entwurfsplanungen lassen sich speichern und später weiterbearbeiten. Der Assistent startet jetzt mit einer Übersicht der gespeicherten Planungen; von dort aus wird eine bestehende geöffnet oder eine neue angelegt.\n• Gespeicherte Planungen können für andere Planende freigegeben werden und zeigen Kunde, Zeitraum, Abdeckung und offene Punkte auf einen Blick.\n• Die Automatik plant in mehreren Durchgängen und füllt Restlücken gezielt nach – dadurch deutlich weniger Handarbeit.\n• Neues Modul „Abwesenheiten“ für Manager und Teamleitung: Urlaub, Krankheit, Gleitzeit und Sperrtage über einen Zeitraum erfassen, mit Wochenendausschluss und Feiertagsprüfung.\n• Zu jeder Abwesenheit kann eine Vertretung hinterlegt werden. Ohne Angabe bleibt es ersatzlos – das ist der Standard. Eine gewählte Vertretung wird direkt in die Planung übernommen.',
'feature');
