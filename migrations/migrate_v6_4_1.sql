-- v6.4.1 – In-App-Changelog „Was gibt es Neues?" (idempotent, keine Schemaänderung)

DELETE FROM `changelog` WHERE `version` = '6.4.1';

INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.4.1','2026-06-27',
'Überarbeitete Dialoge in der Mitarbeiterverwaltung\n• Benutzer bearbeiten: aufgeräumteres, breiteres Fenster mit Kopfbereich (Avatar, Name, Rolle, Benutzername) und ausgewogener Anordnung – persönliche Daten links, Zugang und Einsatz rechts, Vorgesetzte über die volle Breite. Jede Sektion als eigene Karte.\n• Team zusammenstellen: neuer grafischer Team-Builder mit zwei Spalten „Verfügbar" und „Im Team". Mitarbeiter per Klick verschieben, mit farbigen Rollen-Avataren, Suche und Live-Zähler.',
'improvement');
