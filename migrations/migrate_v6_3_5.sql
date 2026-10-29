-- v6.3.5 Migration
-- 1) Performance: Index auf user_supervisors(supervisor_id) (sicher, nur falls fehlend)
-- 2) Changelog-Einträge für die In-App-Anzeige „Was gibt es Neues?"

-- Index sicher hinzufügen (MySQL kennt kein ADD INDEX IF NOT EXISTS)
DROP PROCEDURE IF EXISTS ep_add_idx_v635;
DELIMITER //
CREATE PROCEDURE ep_add_idx_v635()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'user_supervisors'
      AND index_name = 'idx_supervisor'
  ) THEN
    ALTER TABLE `user_supervisors` ADD INDEX `idx_supervisor` (`supervisor_id`);
  END IF;
END //
DELIMITER ;
CALL ep_add_idx_v635();
DROP PROCEDURE IF EXISTS ep_add_idx_v635;

-- Changelog
INSERT IGNORE INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.3.5', CURDATE(),
'Gesamtplan & Sichtbarkeit:\n• Im Gesamtplan sehen jetzt ALLE Rollen (auch Teamleiter) alle Mitarbeiter.\n• Neuer Schnellfilter „Meine Mitarbeiter" für Teamleiter und Manager.\n• Heikle Details (Urlaub/Krankheit) bleiben den jeweiligen Vorgesetzten vorbehalten; sonst erscheint nur ein rotes „A".\n• Verwaltung kann alle Mitarbeiter sehen und planen – Abwesenheiten weiterhin nur als „A".',
'feature'),
('6.3.5', CURDATE(),
'Planung & Darstellung:\n• Ausnahmeregel: einzelne Teamleiter können das Recht erhalten, für ALLE Mitarbeiter zu planen (Schalter im Mitarbeiter-Assistenten).\n• „Mein Plan" in der Handyansicht komplett überarbeitet – der Kalender ist jetzt eine gut lesbare Agenda-Liste.\n• Bessere Darstellung von Ferien (blau) und Feiertagen (rot) im Plan.\n• Abwesenheits-„A" ist jetzt durchgängig rot.',
'improvement'),
('6.3.5', CURDATE(),
'Performance:\n• Konfliktprüfung und Speichern bei Mehrfachplanung deutlich beschleunigt (Sammelabfragen statt vieler Einzelabfragen) – spürbar bei großen Datenbanken.\n• Zusätzlicher Datenbank-Index für die Vorgesetzten-Zuordnung.',
'improvement');
