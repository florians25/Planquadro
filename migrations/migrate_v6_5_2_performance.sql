-- v6.5.2 – Performance: zusätzliche Indizes für häufige Abfragen.
-- Idempotent: jeder Index wird nur angelegt, wenn er noch nicht existiert.

-- assignments: schnelle Last je Mitarbeiter im Jahr/Monat
SET @s := (SELECT COUNT(1) FROM information_schema.statistics
           WHERE table_schema=DATABASE() AND table_name='assignments' AND index_name='idx_user_year_month');
SET @q := IF(@s=0,'ALTER TABLE `assignments` ADD INDEX `idx_user_year_month` (`user_id`,`year`,`month`)','DO 0');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- plan_drafts: schnelle Last je Mitarbeiter im Jahr/Monat
SET @s := (SELECT COUNT(1) FROM information_schema.statistics
           WHERE table_schema=DATABASE() AND table_name='plan_drafts' AND index_name='idx_user_year_month');
SET @q := IF(@s=0,'ALTER TABLE `plan_drafts` ADD INDEX `idx_user_year_month` (`user_id`,`year`,`month`)','DO 0');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- deputations: schnelle Prüfung aktiver Vertretungen je Vertretung/Zeitraum
SET @t := (SELECT COUNT(1) FROM information_schema.tables
           WHERE table_schema=DATABASE() AND table_name='deputations');
SET @s := (SELECT COUNT(1) FROM information_schema.statistics
           WHERE table_schema=DATABASE() AND table_name='deputations' AND index_name='idx_deputy_active');
SET @q := IF(@t=1 AND @s=0,'ALTER TABLE `deputations` ADD INDEX `idx_deputy_active` (`deputy_id`,`start_date`,`end_date`)','DO 0');
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

-- In-App-Changelog
DELETE FROM `changelog` WHERE `version` = '6.5.2';
INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.5.2','2026-06-30',
'Druck, Vertretung, Sicherheit & Performance\n• Ausdrucke nutzen jetzt zuverlässig die volle Seitenbreite (Tagesspalten dehnen sich über das ganze Blatt).\n• Vertretungsseite: mehr Abstand, Texte kleben nicht mehr am Rand.\n• Sicherheit: DB-Zugangsdaten können nun komplett über Umgebungsvariablen gesetzt werden – kein Klartext im Projekt nötig; Zugangsdaten liegen weiterhin außerhalb von public.\n• Performance: dauerhafte Datenbankverbindungen, zusätzliche Indizes und schnelleres Laden der Oberfläche.',
'improvement');
