-- v6.4.4 – In-App-Changelog „Was gibt es Neues?" (idempotent, keine Schemaänderung)

DELETE FROM `changelog` WHERE `version` = '6.4.4';

INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.4.4','2026-06-27',
'Planung – Verlassen-Hinweis\n• Beim Durchschalten der Monate in der Planung erscheint keine „Seite verlassen"-Meldung mehr.\n• Die Browser-Meldung wurde abgeschaltet. Ein Hinweis auf unveröffentlichte Änderungen kommt jetzt – wie zuvor – nur über den app-eigenen Dialog und nur, wenn in der aktuellen Sitzung wirklich unveröffentlichte Änderungen vorliegen.',
'bugfix');
