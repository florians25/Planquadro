-- =============================================================================
-- v6.8.0 – Planungsassistent
-- Copyright (c) 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten.
-- -----------------------------------------------------------------------------
-- Legt die Tabellen für gespeicherte Anforderungs-Vorlagen und für die
-- Assistenten-Läufe an, ergänzt die Berechtigung und den In-App-Changelog.
-- Idempotent: Die Datei kann gefahrlos mehrfach ausgeführt werden.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Hilfsprozeduren (werden am Ende wieder entfernt)
-- -----------------------------------------------------------------------------
DELIMITER //

DROP PROCEDURE IF EXISTS `ep680_safe_col`//
CREATE PROCEDURE `ep680_safe_col`(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_def VARCHAR(500))
BEGIN
    DECLARE cnt INT DEFAULT 0;
    DECLARE tbl INT DEFAULT 0;
    SELECT COUNT(*) INTO tbl FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = p_tbl;
    IF tbl > 0 THEN
        SELECT COUNT(*) INTO cnt FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = p_tbl AND column_name = p_col;
        IF cnt = 0 THEN
            SET @sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN `', p_col, '` ', p_def);
            PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
    END IF;
END//

DELIMITER ;

-- -----------------------------------------------------------------------------
-- 1. Anforderungs-Vorlagen (wiederverwendbare Konfigurationen)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `planning_profiles` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `name`         VARCHAR(150) NOT NULL,
    `description`  VARCHAR(255) DEFAULT NULL,
    `plan_area_id` INT DEFAULT NULL COMMENT 'Standard-Einsatzbereich der Vorlage',
    `config_json`  MEDIUMTEXT NOT NULL COMMENT 'Vollstaendige Assistenten-Konfiguration',
    `is_shared`    TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = fuer alle Planenden sichtbar',
    `use_count`    INT NOT NULL DEFAULT 0,
    `created_by`   INT DEFAULT NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_pp_shared` (`is_shared`),
    INDEX `idx_pp_creator` (`created_by`),
    CONSTRAINT `fk_pp_area`    FOREIGN KEY (`plan_area_id`) REFERENCES `plan_areas`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_pp_creator` FOREIGN KEY (`created_by`)   REFERENCES `users`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 2. Assistenten-Laeufe (Historie + Grundlage fuer "Uebernahme rueckgaengig")
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `planning_runs` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `title`        VARCHAR(150) NOT NULL,
    `plan_area_id` INT DEFAULT NULL,
    `start_date`   DATE NOT NULL,
    `end_date`     DATE NOT NULL,
    `config_json`  MEDIUMTEXT NOT NULL,
    `result_json`  MEDIUMTEXT DEFAULT NULL,
    `status`       ENUM('entwurf','uebernommen','verworfen') NOT NULL DEFAULT 'entwurf',
    `slots_total`  INT NOT NULL DEFAULT 0,
    `slots_filled` INT NOT NULL DEFAULT 0,
    `conflicts`    INT NOT NULL DEFAULT 0,
    `created_by`   INT DEFAULT NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `applied_at`   DATETIME DEFAULT NULL,
    INDEX `idx_pr_status` (`status`, `created_at`),
    INDEX `idx_pr_range`  (`start_date`, `end_date`),
    INDEX `idx_pr_creator` (`created_by`),
    CONSTRAINT `fk_pr_area`    FOREIGN KEY (`plan_area_id`) REFERENCES `plan_areas`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_pr_creator` FOREIGN KEY (`created_by`)   REFERENCES `users`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 3. Herkunft eines Entwurfs kennzeichnen (ermoeglicht gezieltes Zuruecknehmen)
-- -----------------------------------------------------------------------------
CALL ep680_safe_col('plan_drafts', 'planning_run_id', 'INT DEFAULT NULL COMMENT ''Aus Planungsassistent uebernommen''');

SET @s := (SELECT COUNT(1) FROM information_schema.statistics
           WHERE table_schema=DATABASE() AND table_name='plan_drafts' AND index_name='idx_drafts_run');
SET @q := IF(@s=0,'ALTER TABLE `plan_drafts` ADD INDEX `idx_drafts_run` (`planning_run_id`)','DO 0');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- -----------------------------------------------------------------------------
-- 4. Berechtigung "Planungsassistent nutzen"
--    Standard: wer planen darf, darf auch den Assistenten nutzen.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role, permission, granted)
SELECT DISTINCT rp.role, 'use_planning_assistant',
       COALESCE((SELECT granted FROM role_permissions x
                 WHERE x.role = rp.role AND x.permission = 'plan_edit' LIMIT 1), 0)
FROM role_permissions rp;

INSERT IGNORE INTO role_permissions (role, permission, granted) VALUES
('admin','use_planning_assistant',1),
('manager','use_planning_assistant',1),
('teamleiter','use_planning_assistant',1),
('verwaltung','use_planning_assistant',1),
('lager','use_planning_assistant',0),
('mitarbeiter','use_planning_assistant',0),
('azubi_1','use_planning_assistant',0),
('azubi_2','use_planning_assistant',0),
('azubi_3','use_planning_assistant',0);

-- -----------------------------------------------------------------------------
-- 5. In-App-Changelog
-- -----------------------------------------------------------------------------
DELETE FROM `changelog` WHERE `version` = '6.8.0';

INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.8.0', CURDATE(),
'Planungsassistent (Vollbild)\n• Neuer Assistent für komplexe Einsätze und Kundenprojekte: Zeitraum, Bedarf pro Wochentag, Sondertage und Anforderungen an OS-Level, Ausbildungsberuf und Stammbereich werden vorgegeben – den Rest schlägt die App vor.\n• Azubi-Regeln: Lehrjahre auswählen, Höchstzahl und Anteil pro Tag begrenzen, Betreuungsschlüssel festlegen („nie allein\"), Berufsschultage und Wochenenden automatisch aussparen.\n• Automatische Planung mit Gewichtung: Kontinuität (möglichst dieselben Köpfe), Gleichverteilung, Stammbereich und OS-Level lassen sich einzeln gewichten. Zusätzlich Obergrenzen für Tage am Stück und Tage gesamt.\n• Entwurfsansicht: Vorschlag als Matrix Mitarbeiter × Tag, mit Abdeckungsquote, Kennzahlen und Live-Konfliktprüfung. Einzelne Tage lassen sich direkt umbesetzen, offene Bedarfe zeigen passende Alternativvorschläge.\n• Konfliktprüfung in drei Stufen: Blocker (Urlaub, Krankheit, Sperrtag), Warnungen (Unterdeckung, Azubi ohne Betreuung, zu viele Tage am Stück) und Hinweise – jeweils mit Sprung zur betroffenen Zelle.\n• Übernahme erfolgt als Entwurf in die bestehende Planung; veröffentlicht wird weiterhin bewusst in der Monatsplanung. Eine Übernahme kann komplett zurückgenommen werden.\n• Anforderungen lassen sich als Vorlage speichern und für den nächsten Kundeneinsatz wiederverwenden.',
'feature');

-- -----------------------------------------------------------------------------
-- 6. Aufräumen
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `ep680_safe_col`;
