-- =============================================================================
-- v6.8.5 – Planungsassistent: Verbesserungen
-- Copyright (c) 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten.
-- -----------------------------------------------------------------------------
-- Setzt migrate_v6_8_0.sql voraus (Tabellen und Berechtigung).
-- Diese Datei enthaelt keine Schemaaenderungen, sondern nur den Changelog-Eintrag
-- und eine Sicherheitsnetz-Vergabe der Berechtigung. Mehrfach ausfuehrbar.
-- =============================================================================

-- Falls 6.8.0 uebersprungen wurde: Berechtigung erneut sicherstellen.
INSERT IGNORE INTO role_permissions (role, permission, granted) VALUES
('admin','use_planning_assistant',1),
('manager','use_planning_assistant',1),
('teamleiter','use_planning_assistant',1),
('verwaltung','use_planning_assistant',1),
('lager','use_planning_assistant',0),
('mitarbeiter','use_planning_assistant',0),
('azubi_1','use_planning_assistant',0),
('azubi_2','use_planning_assistant',0),
('azubi_3','use_planning_assistant',0);

DELETE FROM `changelog` WHERE `version` = '6.8.5';

INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.8.5', CURDATE(),
'Planungsassistent (Beta) – Verbesserungen\n• Zusammenfassung als eigener Schritt vor dem Entwurf: alle Vorgaben auf einen Blick, unvollständige oder widersprüchliche Angaben werden markiert. Über „Ändern\" springt man direkt zum betroffenen Feld, das dort kurz hervorgehoben wird.\n• Vorschlag drucken: druckfertige Fassung im Querformat mit Kopfdaten, Matrix, Summenzeile und offenen Punkten. Im Druckdialog wahlweise drucken oder als PDF speichern.\n• Technik-Tage gelten als freie Kapazität und dürfen ersetzt werden (standardmäßig aktiv). Weitere Allgemein-Bereiche sind freischaltbar, Berufsschule bleibt geschützt.\n• Umplanung mit Augenmaß: Wer überwiegend bei einem Kunden ist, wird dort gelassen; wer ohnehin zwischen Kunden wechselt, wird bevorzugt umgeplant.\n• Bereits geplante Einsätze lassen sich per Klick einzeln ersetzen – mit Rückfrage im Klartext und ohne die globale Regel zu ändern.\n• Lesbarkeit: Auf hellen Bereichsfarben wird wieder die hinterlegte Schriftfarbe verwendet. Entwurfsansicht mit Kalenderwochen, Hervorhebung des heutigen Tages, Besetzungsbalken und Zählern an den Filtern.',
'feature');
