-- v6.5.0 – In-App-Changelog „Was gibt es Neues?" (idempotent, keine Schemaänderung)

DELETE FROM `changelog` WHERE `version` = '6.5.0';

INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.5.0','2026-06-30',
'Sicherheit, Druck, Mein Plan & Performance\n• Sicherheit: neue Ordnerstruktur mit public-Verzeichnis – die Datenbank-Zugangsdaten liegen jetzt außerhalb des öffentlich erreichbaren Bereichs (Details in README_DEPLOY.md).\n• Ausdrucke nutzen wieder die volle Seitenbreite.\n• Fällt „heute" auf ein Wochenende oder einen Feier-/Ferientag, bleibt der Spaltenkopf korrekt blau markiert.\n• „Mein Plan" auf dem Handy: neuer „Heute"-Knopf zum direkten Springen zum heutigen Tag.\n• Schnelleres Laden durch GZIP-Komprimierung und Browser-Caching.',
'improvement');
