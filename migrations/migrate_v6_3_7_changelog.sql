-- v6.3.7 – In-App-Changelog „Was gibt es Neues?" vervollständigen
-- Baut die gesamte 6.3.x-Historie sauber neu auf (idempotent: kann gefahrlos
-- erneut ausgeführt werden, ohne Duplikate zu erzeugen).

CREATE TABLE IF NOT EXISTS `changelog` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `version` VARCHAR(20) NOT NULL,
  `release_date` DATE DEFAULT NULL,
  `content` TEXT NOT NULL,
  `entry_type` VARCHAR(20) DEFAULT 'feature',
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DELETE FROM `changelog` WHERE `version` IN ('6.3.1','6.3.2','6.3.3','6.3.4','6.3.5','6.3.6','6.3.7');

INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.3.1','2026-06-26',
'Benutzerverwaltung & Berechtigungen\n• Neue Benutzer werden über einen übersichtlichen Schritt-für-Schritt-Assistenten angelegt.\n• Die SNOW-ID wurde aus den Mitarbeiterdaten entfernt.\n• Benutzer- und Teamverwaltung sind nur noch für Manager, Verwaltung (Service Administration) und Administrator zugänglich.',
'feature'),

('6.3.2','2026-06-26',
'Planungstabelle auf kleinen Bildschirmen\n• Die fixierten Spalten (Name, Info, Resturlaub) passen sich kleineren Monitoren an – mehr Tage bleiben sichtbar.\n• Beim seitlichen Scrollen hebt sich der fixierte Bereich durch einen dezenten Schatten ab.\n• Sanfteres Touch-Scrolling und ein schlankerer Scrollbalken.',
'improvement'),

('6.3.3','2026-06-26',
'Tabelle, Druck & Nutzerverwaltung\n• Klar sichtbare Trennlinien in allen Plan-Zellen.\n• Druck: größerer Seitenrand und vollständige Namen – passt weiterhin auf eine DIN-A4-Seite.\n• Nutzerverwaltung mit fixierter Namensspalte, Zebra-Streifen, Leerzustand und einem Aktionen-Menü, das nicht mehr abgeschnitten wird.',
'improvement'),

('6.3.4','2026-06-26',
'Mehrfachplanung & Konfliktbearbeitung\n• Mehrere Mitarbeiter gleichzeitig planen – ideal für einen Onsite-Einsatz beim Kunden über mehrere Tage.\n• Konflikte werden vor dem Speichern gesammelt geprüft und nach Mitarbeiter gruppiert angezeigt.\n• Pro Mitarbeiter oder pro Tag wählen, was überschrieben wird – einzelne Mitarbeiter lassen sich mit einem Klick aus der Überschreibung herausnehmen.\n• Geschützte Urlaubs-/Kranktage werden nur mit Berechtigung zur Überschreibung angeboten.',
'feature'),

('6.3.5','2026-06-26',
'Gesamtplan, Sichtbarkeit & Ausnahmen\n• Im Gesamtplan sehen jetzt alle Rollen (auch Teamleiter) alle Mitarbeiter.\n• Neuer Schnellfilter „Meine Mitarbeiter" für Teamleiter und Manager.\n• Urlaub und Krankheit bleiben den jeweiligen Vorgesetzten vorbehalten – sonst erscheint nur ein rotes „A".\n• Verwaltung kann alle Mitarbeiter sehen und planen.\n• Ausnahmeregel: einzelne Teamleiter dürfen für alle Mitarbeiter planen.\n• Deutlich bessere Performance bei großen Datenbanken.',
'feature'),

('6.3.6','2026-06-26',
'Ferien-Darstellung & „Mein Plan" auf dem Handy\n• Ferien werden nicht mehr eingefärbt – die Markierung erfolgt nur noch im Spaltenkopf, da Mitarbeiter auch in den Ferien geplant werden können.\n• „Mein Plan" in der Handyansicht komplett neu als übersichtliche Agenda: Monats-Navigation, Kurzübersicht (Einsätze/Abwesend/Frei), Gruppierung nach Kalenderwoche, farbige Einsatz-Pillen, Bemerkungen und ein klar markierter „Heute"-Tag.',
'improvement'),

('6.3.7','2026-06-26',
'Feinschliff Darstellung\n• Die Ferien- und Feiertagsbeschriftung im Spaltenkopf steht jetzt bei allen Tagen sauber auf einer Linie (die Kalenderwochen-Zeile wird in jeder Spalte gleich hoch reserviert).',
'improvement');
