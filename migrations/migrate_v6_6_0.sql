-- v6.6.0 – Neue Eintragungsmöglichkeit "?" (Offen / noch zu klären)
-- Erweitert das absence_type-ENUM um den Wert 'offen'. Idempotent (setzt das
-- ENUM auf den vollständigen Wertebereich; mehrfaches Ausführen ist gefahrlos).

ALTER TABLE `absences` MODIFY COLUMN `absence_type`
  ENUM('krank_halb','krank_ganz','krank_attest','urlaub_halb','urlaub_ganz','urlaub_genehmigt','gleitzeit','gesperrt','offen') NOT NULL;

-- In-App-Changelog
DELETE FROM `changelog` WHERE `version` = '6.6.0';
INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.6.0','2026-06-30',
'Planung – mehr Übersicht & Komfort\n• Neue Eintragung „?" (Offen / noch zu klären) – ideal, wenn ein Tag noch unklar ist.\n• Sortierung wählbar: „Standard" (Manager, dann Teamleiter, dann alphabetisch) oder „A–Z" – jeweils innerhalb der Gruppen.\n• Filter- und Sortierauswahl werden gespeichert und beim nächsten Besuch automatisch angewendet.\n• Gruppen-Überschriften (Techniker, Azubis …) und die Info-Spalte mit mehreren Inhalten klarer dargestellt.\n• Mitarbeiterauswahl beim Erfassen eines Einsatzes übersichtlicher.',
'feature');
