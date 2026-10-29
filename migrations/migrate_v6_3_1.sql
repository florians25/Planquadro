-- v6.3.1 Migration: Benutzerverwaltung nur für Manager, Verwaltung (Service
-- Administration) und Administrator. Teamleiter dürfen KEINE Benutzer/Teams mehr
-- verwalten. (SNOW-ID wird aus der Oberfläche entfernt – Spalte bleibt bestehen.)

-- Bestehende Werte aktualisieren (ON DUPLICATE KEY UPDATE, da Zeilen evtl. schon existieren)
INSERT INTO role_permissions (role, permission, granted) VALUES
('admin','manage_users',1),
('manager','manage_users',1),
('verwaltung','manage_users',1),
('teamleiter','manage_users',0)
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

-- Changelog
INSERT IGNORE INTO `changelog` (`version`, `release_date`, `content`, `entry_type`) VALUES
('6.3.1', CURDATE(), 'Benutzerverwaltung\n- Anlage neuer Benutzer als mehrstufiger Assistent (Stepper)\n- SNOW-ID aus den Mitarbeiterdaten entfernt\n- Benutzer- und Teamverwaltung nur noch für Manager, Verwaltung (Service Administration) und Administrator – Teamleiter haben keinen Zugriff mehr\n\nDruck\n- Planausdruck mit schmaleren Rändern, passt vollständig auf eine DIN-A4-Seite', 'feature');
