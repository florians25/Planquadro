-- Sammelchangelog 6.3.7 → 6.4.3 für die In-App-Anzeige „Was gibt es Neues?".
-- Fasst die Einzelversionen 6.3.7, 6.4.0, 6.4.1, 6.4.2, 6.4.3 zu EINEM
-- übersichtlichen Eintrag zusammen. Idempotent (kann erneut laufen).

DELETE FROM `changelog` WHERE `version` IN ('6.3.7','6.4.0','6.4.1','6.4.2','6.4.3');

INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.4.3','2026-06-27',
'Sammelübersicht – Neuerungen seit Version 6.3.7\n\nMitarbeiter- & Team-Verwaltung\n• Bis zu 4 Vorgesetzte pro Mitarbeiter – sie sehen Plan, Urlaub & Krankheit und dürfen für den Mitarbeiter eintragen.\n• Vorgesetzte werden als Chips (Avatar, Name, Rolle) zugewiesen, inkl. Zähler „x / 4".\n• Benutzer bearbeiten ohne Assistent: breites Fenster mit allen Details auf einen Blick und übersichtlichem Kopfbereich (Avatar, Name, Rolle, Benutzername).\n• Neuer grafischer Team-Builder: Mitarbeiter per Klick zwischen „Verfügbar" und „Im Team" verschieben – mit Suche und Live-Zähler.\n• Azubi-Beförderung: abgeschlossene Auszubildende werden mit einem Klick zum Techniker.\n\nDesign\n• Durchgängig Markenrot statt bunter Farbverläufe.\n• Korrigierte, bündige Abstände in den Dialogen; „Verfügbar" und „Im Team" starten auf gleicher Höhe.\n\nPlan-Darstellung\n• Ferien- und Feiertagsbeschriftung im Spaltenkopf steht bei allen Tagen sauber auf einer Linie.',
'feature');
