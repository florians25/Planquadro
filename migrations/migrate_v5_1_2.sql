-- Migration v5.1.2
UPDATE `app_settings` SET `setting_value` = '5.1.2' WHERE `setting_key` = 'app_version';

-- Initial changelog entry for v5.1.2
INSERT INTO `changelog` (`version`, `release_date`, `content`) VALUES
('5.1.2', CURDATE(), 'Profilseite, Dauereinsatz mit Rollenfilter, Mitarbeiter-Statusfilter, DB-Bereinigung, Jahresplan ohne ausgetretene MA, Bemerkung-Werkzeug Fix, Aktualisiert-Badge Position, Anträge Aktuell/Archiv Tabs');
