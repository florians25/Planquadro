# Datenbank-Migrationen

Dieser Ordner enthält alle inkrementellen DB-Änderungen (`migrate_*.sql`).

## Einspielen
1. Zuerst die Basis `../database.sql` importieren (nur bei Neuinstallation).
2. Danach die `migrate_*.sql` **aufsteigend nach Versionsnummer** der Reihe nach
   über phpMyAdmin (oder `mysql < datei.sql`) einspielen.
3. Alle Skripte sind idempotent gehalten – ein erneutes Einspielen schadet nicht.

## Wichtig für die aktuelle Version
- `migrate_v6_6_0.sql` – fügt den Abwesenheits-Status **„?" (offen / noch zu
  klären)** zur Datenbank hinzu. Ohne diese Migration lässt sich ein „?" zwar als
  Entwurf setzen, aber nicht veröffentlichen.

`changelog_inserts.sql` enthält Sammel-Einträge für das In-App-Changelog.
