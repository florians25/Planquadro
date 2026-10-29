-- v6.3.4 Migration: Konfliktbearbeitung, Mehrfachplanung, Tabellen-Feinschliff,
-- Berechtigungen für Bereiche/Feiertage. (Reine Logik-/CSS-Änderungen – hier nur
-- die Changelog-Einträge für die In-App-Anzeige „Was gibt es Neues?".)

INSERT IGNORE INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.3.4', CURDATE(),
'Mehrere Mitarbeiter gleichzeitig planen (z. B. ein Onsite-Einsatz beim Kunden über mehrere Tage):\n• Das Erfassen-Fenster erlaubt jetzt die Mehrfachauswahl von Mitarbeitern mit Suche und „Alle/Keine".\n• Konflikte werden vor dem Speichern gesammelt geprüft und übersichtlich nach Mitarbeiter gruppiert angezeigt.\n• Pro Mitarbeiter bzw. pro Tag lässt sich gezielt entscheiden: bestehenden Eintrag überschreiben oder unverändert lassen – ganze Mitarbeiter können mit einem Klick aus der Überschreibung herausgenommen werden.\n• Geschützte Urlaubs-/Kranktage werden nur mit entsprechender Berechtigung zur Überschreibung angeboten.',
'feature'),
('6.3.4', CURDATE(),
'Planungstabelle & Berechtigungen:\n• Einheitliche Zellgrößen und durchgängig dünnere, ruhigere Trennlinien.\n• Der Gesamtplan ist für alle einsehbar; heikle Details wie Urlaub und Krankheit sehen weiterhin nur die jeweiligen Vorgesetzten.\n• Einsatzbereiche und Feiertage können nur noch von Manager, Verwaltung (Service Administration) und Administrator bearbeitet werden.',
'improvement');
