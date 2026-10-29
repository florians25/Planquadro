-- v6.0.0 Migration: Rollen & Rechte, Interne Einträge, Kommentar-Metadaten
-- Alle ALTER TABLE Statements sind idempotent (können mehrfach ausgeführt werden)

-- Helper: Safe ADD COLUMN (prüft ob Spalte bereits existiert)
DELIMITER //
DROP PROCEDURE IF EXISTS `ep_safe_col`//
CREATE PROCEDURE `ep_safe_col`(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_def VARCHAR(500))
BEGIN
    DECLARE cnt INT DEFAULT 0;
    SELECT COUNT(*) INTO cnt FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_tbl AND column_name = p_col;
    IF cnt = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- 1. Berechtigungstabelle
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role` VARCHAR(30) NOT NULL,
    `permission` VARCHAR(50) NOT NULL,
    `granted` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_role_perm` (`role`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Standard-Berechtigungen
INSERT IGNORE INTO role_permissions (role, permission, granted) VALUES
('admin','plan_view',1),('admin','plan_edit',1),('admin','plan_publish_own',1),('admin','plan_publish_all',1),
('admin','plan_view_internal',1),('admin','view_erl_column',1),('admin','manage_users',1),('admin','delete_users',1),
('admin','manage_settings',1),('admin','manage_roles',1),('admin','view_gesamtplan_details',1),('admin','approve_requests',1),('admin','unlock_vacation',1),
('manager','plan_view',1),('manager','plan_edit',1),('manager','plan_publish_own',1),('manager','plan_publish_all',1),
('manager','plan_view_internal',1),('manager','view_erl_column',1),('manager','manage_users',1),('manager','delete_users',0),
('manager','manage_settings',1),('manager','manage_roles',0),('manager','view_gesamtplan_details',1),('manager','approve_requests',1),('manager','unlock_vacation',1),
('teamleiter','plan_view',1),('teamleiter','plan_edit',1),('teamleiter','plan_publish_own',1),('teamleiter','plan_publish_all',0),
('teamleiter','plan_view_internal',1),('teamleiter','view_erl_column',1),('teamleiter','manage_users',1),('teamleiter','delete_users',0),
('teamleiter','manage_settings',0),('teamleiter','manage_roles',0),('teamleiter','view_gesamtplan_details',1),('teamleiter','approve_requests',1),('teamleiter','unlock_vacation',0),
('verwaltung','plan_view',1),('verwaltung','plan_edit',1),('verwaltung','plan_publish_own',1),('verwaltung','plan_publish_all',0),
('verwaltung','plan_view_internal',0),('verwaltung','view_erl_column',1),('verwaltung','manage_users',0),('verwaltung','delete_users',0),
('verwaltung','manage_settings',0),('verwaltung','manage_roles',0),('verwaltung','view_gesamtplan_details',1),('verwaltung','approve_requests',0),('verwaltung','unlock_vacation',0),
('lager','plan_view',1),('lager','plan_edit',0),('lager','plan_publish_own',0),('lager','plan_publish_all',0),
('lager','plan_view_internal',0),('lager','view_erl_column',0),('lager','manage_users',0),('lager','delete_users',0),
('lager','manage_settings',0),('lager','manage_roles',0),('lager','view_gesamtplan_details',0),('lager','approve_requests',0),('lager','unlock_vacation',0),
('mitarbeiter','plan_view',1),('mitarbeiter','plan_edit',0),('mitarbeiter','plan_publish_own',0),('mitarbeiter','plan_publish_all',0),
('mitarbeiter','plan_view_internal',0),('mitarbeiter','view_erl_column',0),('mitarbeiter','manage_users',0),('mitarbeiter','delete_users',0),
('mitarbeiter','manage_settings',0),('mitarbeiter','manage_roles',0),('mitarbeiter','view_gesamtplan_details',0),('mitarbeiter','approve_requests',0),('mitarbeiter','unlock_vacation',0),
('azubi_1','plan_view',1),('azubi_1','plan_edit',0),('azubi_1','plan_publish_own',0),('azubi_1','plan_publish_all',0),
('azubi_1','plan_view_internal',0),('azubi_1','view_erl_column',0),('azubi_1','manage_users',0),('azubi_1','delete_users',0),
('azubi_1','manage_settings',0),('azubi_1','manage_roles',0),('azubi_1','view_gesamtplan_details',0),('azubi_1','approve_requests',0),('azubi_1','unlock_vacation',0),
('azubi_2','plan_view',1),('azubi_2','plan_edit',0),('azubi_2','plan_publish_own',0),('azubi_2','plan_publish_all',0),
('azubi_2','plan_view_internal',0),('azubi_2','view_erl_column',0),('azubi_2','manage_users',0),('azubi_2','delete_users',0),
('azubi_2','manage_settings',0),('azubi_2','manage_roles',0),('azubi_2','view_gesamtplan_details',0),('azubi_2','approve_requests',0),('azubi_2','unlock_vacation',0),
('azubi_3','plan_view',1),('azubi_3','plan_edit',0),('azubi_3','plan_publish_own',0),('azubi_3','plan_publish_all',0),
('azubi_3','plan_view_internal',0),('azubi_3','view_erl_column',0),('azubi_3','manage_users',0),('azubi_3','delete_users',0),
('azubi_3','manage_settings',0),('azubi_3','manage_roles',0),('azubi_3','view_gesamtplan_details',0),('azubi_3','approve_requests',0),('azubi_3','unlock_vacation',0);

-- 3. Interne Einträge & Kommentar-Metadaten in plan_drafts
CALL ep_safe_col('plan_drafts', 'is_internal', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `remarks`');
CALL ep_safe_col('plan_drafts', 'internal_remark', 'TEXT DEFAULT NULL AFTER `is_internal`');

-- 4. Interne Einträge & Metadaten in assignments
CALL ep_safe_col('assignments', 'is_internal', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ep_safe_col('assignments', 'internal_remark', 'TEXT DEFAULT NULL');
CALL ep_safe_col('assignments', 'remark_by', 'INT DEFAULT NULL');
CALL ep_safe_col('assignments', 'remark_at', 'DATETIME DEFAULT NULL');

-- 5. Interne Einträge & Metadaten in absences
CALL ep_safe_col('absences', 'is_internal', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ep_safe_col('absences', 'internal_remark', 'TEXT DEFAULT NULL');
CALL ep_safe_col('absences', 'remark_by', 'INT DEFAULT NULL');
CALL ep_safe_col('absences', 'remark_at', 'DATETIME DEFAULT NULL');

-- 6. Changelog entry_type
CALL ep_safe_col('changelog', 'entry_type', "VARCHAR(20) DEFAULT 'feature' AFTER `content`");

-- 7. plan_publish_year permission
INSERT IGNORE INTO role_permissions (role, permission, granted) VALUES
('admin','plan_publish_year',1),('manager','plan_publish_year',1),
('teamleiter','plan_publish_year',0),('verwaltung','plan_publish_year',0),
('lager','plan_publish_year',0),('mitarbeiter','plan_publish_year',0),
('azubi_1','plan_publish_year',0),('azubi_2','plan_publish_year',0),('azubi_3','plan_publish_year',0);

-- 8. Changelog entry
INSERT IGNORE INTO `changelog` (`version`, `release_date`, `content`) VALUES
('6.0.0', CURDATE(), 'Rollen & Rechte\n- Rolleneditor mit 13+ Berechtigungen pro Rolle\n- Benutzer-Überschreibungen für einzelne Personen\n\nInterne Einträge\n- Einsätze als nicht veröffentlichbar markieren\n- Interne Kommentare separat\n\nVeröffentlichung\n- Meine / Alle veröffentlichen\n- Verlassen-Warnung');

-- Cleanup helper
DROP PROCEDURE IF EXISTS `ep_safe_col`;
