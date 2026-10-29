-- v6.3.0 Migration: Vorgesetzten-Scope, Datenschutz, Bemerkungs-Fix
-- Idempotent (mehrfach ausführbar).

-- 1. Neue Berechtigung: "Alle Mitarbeiter sehen".
--    Standard: alle Rollen = 1, AUSSER manager/teamleiter = 0.
--    Dadurch sehen Manager/Teamleiter nur noch ihr eigenes Team.
INSERT IGNORE INTO role_permissions (role, permission, granted)
SELECT DISTINCT role, 'view_all_employees',
       CASE WHEN role IN ('manager','teamleiter') THEN 0 ELSE 1 END
FROM role_permissions;

-- Basis-Rollen explizit absichern (falls Tabelle unvollständig)
INSERT IGNORE INTO role_permissions (role, permission, granted) VALUES
('admin','view_all_employees',1),
('manager','view_all_employees',0),
('teamleiter','view_all_employees',0),
('verwaltung','view_all_employees',1),
('lager','view_all_employees',1),
('mitarbeiter','view_all_employees',1),
('azubi_1','view_all_employees',1),
('azubi_2','view_all_employees',1),
('azubi_3','view_all_employees',1);

-- 2. Datenschutz-/Compliance-Angaben (werden auf der Datenschutz-Seite angezeigt)
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
('dp_controller',''),
('dp_officer',''),
('dp_works_agreement',''),
('dp_retention','');

-- 3. Changelog-Eintrag (falls Tabelle vorhanden)
INSERT IGNORE INTO `changelog` (`version`, `release_date`, `content`, `entry_type`) VALUES
('6.3.0', CURDATE(), 'Sichtbarkeit & Datenschutz\n- Manager/Teamleiter sehen in der Planung nur noch ihre eigenen Mitarbeiter (Vorgesetzten-Zuordnung)\n- Vorgesetzte lassen sich jetzt direkt in der Mitarbeiterliste per Schnellbearbeitung ändern\n- Neue Datenschutz-Seite (DSGVO/BetrVG) inkl. Footer-Link\n- Serverseitige Absicherung: Bearbeitung nur im eigenen Team\n\nBugfix\n- Interne und öffentliche Bemerkungen können jetzt gleichzeitig bestehen, ohne sich gegenseitig zu überschreiben', 'feature');
