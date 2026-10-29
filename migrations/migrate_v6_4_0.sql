-- v6.4.0 – In-App-Changelog „Was gibt es Neues?" (idempotent)
-- Keine Schemaänderung nötig: bis zu 4 Vorgesetzte werden im Code begrenzt,
-- die Tabelle user_supervisors unterstützt bereits mehrere Zuordnungen.

DELETE FROM `changelog` WHERE `version` = '6.4.0';

INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.4.0','2026-06-26',
'Mitarbeiter- & Vorgesetzten-Verwaltung\n• Bis zu 4 Vorgesetzte pro Mitarbeiter – sie sehen den Plan, Urlaub & Krankheit und dürfen für den Mitarbeiter eintragen.\n• Grafisch überarbeitete Zuweisung: zugewiesene Vorgesetzte als Chips (Initiale, Name, Rolle) mit Entfernen-Schaltfläche, darunter eine durchsuchbare Hinzufügen-Liste mit Zähler „x / 4".\n• Benutzer bearbeiten ohne Assistent: breiteres Fenster mit allen Details auf einen Blick (zweispaltig). Das Anlegen nutzt weiterhin den geführten Assistenten.\n• Neue Aktion „Zum Techniker befördern": abgeschlossene Auszubildende werden mit einem Klick zum Techniker.',
'feature');
