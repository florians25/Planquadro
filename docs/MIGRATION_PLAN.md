# Planquadro – Migrationsplan

Stand: 29.10.2026

Wie eine bestehende Einsatzplanung-Installation (v6.x, Apache/Shared Hosting, MySQL) auf
Planquadro gebracht wird – **ohne Datenverlust und ohne ungewollte Rechteänderung**. Jeder
Schritt ist einzeln umkehrbar.

## 1. Grundsätze

- **Erst sichern, dann ändern.** Vor jedem Schritt ein vollständiges, *extern abgelegtes*
  Backup (DB-Dump mit `--single-transaction` + Dateien) und ein Test-Restore.
- **Erst Staging, dann Produktion.** Jeder Schritt läuft zuerst gegen eine Kopie der
  Produktivdatenbank.
- **Nur additive Schemaänderungen pro Release.** Spalten/Tabellen werden ergänzt,
  nicht umbenannt oder gelöscht. Entfernen erst in einem späteren Release, wenn kein Code
  mehr darauf zugreift („expand → migrate → contract“).
- **Kein Release ohne Rückweg:** Für jede Migration ist dokumentiert, wie man zurückkommt
  (Code-Rollback genügt bei additiven Änderungen; sonst Restore).
- **Rechte werden verglichen, nicht vermutet:** Effektive Rechtematrix vor und nach jeder
  Migration exportieren und diffen (Abschnitt 4).

## 2. Schritt 0 – Vorbereitung (einmalig, vor dem ersten Planquadro-Deployment)

1. **DB-Passwort rotieren.** Das bisherige Passwort stand im Quellcode und im
   weitergegebenen Archiv. Neues Passwort im Hosting-Panel setzen.
2. **Zugangsdaten auslagern.** `app/config/database.php` enthält keine Fallback-Werte
   mehr. Vor dem Hochladen eine der beiden Varianten einrichten:
   - Umgebungsvariablen `PQ_DB_HOST`, `PQ_DB_NAME`, `PQ_DB_USER`,
     `PQ_DB_PASS` (z. B. `SetEnv` in einer nicht ausgelieferten Konfiguration), **oder**
   - `app/config/secrets.php` nach Vorlage `secrets.sample.php`.
   Die bisherigen Variablen `CCKOE_DB_*` werden weiterhin gelesen.
3. **Bestehende Backups sichern.** `app/backups/*.epbak` und `.backup_key` vom Server
   herunterladen und getrennt voneinander aufbewahren (Schlüssel z. B. im
   Passwortmanager). Ab Phase 4 lesbar mit `php bin/backup.php legacy-decrypt`; die
   alte Cron-URL `api/backup?action=cron_backup` entfällt – den Cronjob löschen und die
   neue Zeitsteuerung einrichten ([BACKUP.md](BACKUP.md)). Im Docker-Betrieb startet der
   Container eine übernommene Datenbank ohne Versionstabelle erst mit
   `PQ_AUTO_BASELINE=v6_9_0` ([INSTALLATION.md](INSTALLATION.md#bestehende-einsatzplanung-übernehmen)).
4. **`install.php` prüfen.** Ab Planquadro ist es ohne gesetzte Umgebungsvariable
   `PQ_ALLOW_INSTALL=1` gesperrt. Auf dem bisherigen Server die Datei sofort löschen.

## 3. Schritt 1 – Versionsführung der Datenbank (Phase 2)

Bisher gibt es keine Aufzeichnung, welche `migrate_v*.sql` eingespielt wurden. Die
Alt-Skripte dürfen **nicht** erneut laufen: `migrate_v6_3_1` und `migrate_v6_9_0`
überschreiben Rollenrechte, `migrate_v5_2_7` verschiebt Datensätze.

```bash
# Backup!
php bin/migrate.php status                 # erkennt "bestehende Installation ohne Versionstabelle"
php bin/migrate.php baseline v6_9_0        # letzte tatsächlich eingespielte Alt-Migration
php bin/migrate.php up                     # spielt nur neue Planquadro-Migrationen ein
```

`baseline` schreibt ausschließlich in die neue Tabelle `schema_migrations`; Fachdaten
bleiben unberührt. Rückweg: `DROP TABLE schema_migrations`.

Der Runner erkennt MySQL und übersetzt die MariaDB-Syntax der Alt-Migrationen
(`ADD COLUMN IF NOT EXISTS`); getestet in der CI mit MariaDB 10.11 und MySQL 8.0.

Ohne Shell-Zugang (Shared Hosting) werden die Dateien unter `migrations/app/` wie
bisher in Versionsreihenfolge per phpMyAdmin eingespielt; sie sind so geschrieben, dass sie
mehrfach ausgeführt werden können (`IF NOT EXISTS`, `INSERT IGNORE`).

### Planquadro-Migrationen

| Datei | Inhalt | Art | Rückweg |
|---|---|---|---|
| `0001_runtime_ddl.sql` | `users.cal_token`, `editing_status.target_uid` (bisher zur Laufzeit angelegt), `changelog.entry_type` | additiv | nicht nötig |
| `0002_plan_change_log.sql` | Tabelle `plan_change_log` (Änderungsprotokoll je Zelle) | additiv | `DROP TABLE plan_change_log` |
| `0004_master_data.sql` | Tabellen `customers`, `locations`, `qualifications`, `user_qualifications`; Spalten `plan_areas.customer_id/location_id` | additiv | Tabellen/Spalten können bleiben |
| `0005_api_push.sql` | `api_refresh_tokens`, `push_devices`, `push_queue` | additiv | Tabellen löschen |
| `0006_role_bound_permissions.sql` | neue Rechte `manage_team`, `manage_config`, `bypass_maintenance`, `manage_maintenance`, `delete_changelog`, `manage_deputations` in `role_permissions` – exakt die bisherigen Rollenbindungen, eigene Rollen `0` | additiv (nur `INSERT IGNORE`, idempotent) | Code-Rollback genügt; Bereinigung siehe Abschnitt 4 |
| `0003_clear_initial_passwords.sql` | leert `users.initial_password` (Klartext-Einmalpasswörter). Das Einmalpasswort bleibt über den Hash gültig; der Wechselzwang bleibt bestehen | Datenbereinigung | nicht umkehrbar – gewollt; Werte wurden nirgends angezeigt |

### Rechteneutralität von Phase 2

Phase 2 ändert keine Zeile in `role_permissions` oder `permission_user_overrides`.
Die Rechte `manage_absences` und `use_planning_assistant` erscheinen jetzt auf der Seite
„Rollen & Rechte“ mit ihrem gespeicherten Wert. Verhaltensänderungen ergeben sich
ausschließlich aus den Fehlerbehebungen P-1 … P-7 in `PERMISSIONS.md`; ein Test stellt
sicher, dass jedes Recht auf der Rollen-Seite pflegbar ist (sonst würde „Speichern“ nicht
angezeigte Rechte auf 0 setzen).

## 4. Schritt 2 – Rechte-Migration ohne Privilegienausweitung (Phase 3)

Ziel: Rollen und Rechte werden mandantenfähig, rollengebundene Prüfungen
(`canManageUsers`, `canManageConfig`, …) werden echte Rechte.

Umgesetzt mit Migration `0006_role_bound_permissions.sql` (Planquadro 7.0.0).

1. **Backup**, dann **Snapshot vorher** – noch mit dem bisherigen Code-Stand:
   `php bin/permissions-snapshot.php > before.json` (im SaaS-Betrieb je Mandant mit
   `--tenant=<slug>`). Berechnet für jeden aktiven Benutzer die effektiven Rechte
   (Override > Rolle > Standard), die rollengebundenen Prüfungen, den Team-Scope
   (inkl. Vertretungen), die unterstellten Mitarbeiter und für wen Krankheits-/Urlaubsdetails
   sichtbar sind. Benutzer werden über den Benutzernamen verglichen.
2. **Code ausrollen, dann `php bin/migrate.php up`.** 0006 legt die neuen Rechte so an,
   dass sie exakt die bisherigen Rollenbindungen abbilden:

   | Recht | ersetzt | Standard |
   |---|---|---|
   | `manage_team` | `canManageUsers()` (Seite Team) | admin, manager, verwaltung |
   | `manage_config` | `canManageConfig()` (Bereiche, Feiertage, Stammdaten) | admin, manager, verwaltung |
   | `bypass_maintenance` | Wartungsmodus-Ausnahme (Web und API v1) | admin, manager |
   | `manage_maintenance` | `api/maintenance`, `api/cleanup` | admin, manager |
   | `delete_changelog` | `api/changelog` delete | admin, manager |
   | `manage_deputations` | Seite Vertretung | admin, manager, teamleiter |

   Eigene Rollen erhalten `0` – auch wenn sie z. B. auf Basis „Manager“ angelegt wurden,
   denn die frühere Rollenprüfung galt nur für den exakten Rollenschlüssel. Bestehende
   Zeilen in `role_permissions` und alle Overrides bleiben unverändert (nur `INSERT IGNORE`).
   Die Reihenfolge Code → Migration ist gefahrlos: Fehlt eine Zeile, wendet der Code für
   diese sechs Rechte den Katalog-Standard an (identisch zur alten Rollenprüfung).
3. **Snapshot nachher** und `php bin/permissions-snapshot.php --diff=before.json`.
   **Jede Abweichung bricht das Deployment ab** (Exit 1). Rechte, die nur im neuen Snapshot
   vorkommen, sind Ergänzungen (Hinweis); ihre Wirkung wird über die Prüfungen
   (`checks.can_manage_users` usw.) verglichen.
4. Automatisiert: `tests/Integration/PermissionSnapshotTest.php` vergleicht für alle
   Test-Benutzer, zwei eigene Rollen, Overrides und eine Vertretung die Rechtematrix und die
   Zugriffe auf alle Seiten, Seiten-Aktionen, APIs und API v1 (auch im Wartungsmodus) mit
   der Referenz `tests/fixtures/permissions_snapshot.json`, die auf dem Code-Stand **vor**
   der Migration erzeugt wurde – nach der Migration, ohne 0006 (Rückweg) und nach
   wiederholter Ausführung. Für eine anonymisierte Kopie der Produktivdaten: Schritte 1–3
   gegen die Kopie.

**Rückweg / Recovery**
- *Fehler im neuen Code:* vorherigen Code-Stand ausrollen. Der ältere Code wertet die
  neuen Zeilen nicht aus; das Schema ist unverändert.
- *Neue Rechte ganz entfernen* (nach Code-Rollback, optional):
  ```sql
  DELETE FROM role_permissions WHERE permission IN ('manage_team','manage_config',
    'bypass_maintenance','manage_maintenance','delete_changelog','manage_deputations');
  DELETE FROM permission_user_overrides WHERE permission IN ('manage_team','manage_config',
    'bypass_maintenance','manage_maintenance','delete_changelog','manage_deputations');
  DELETE FROM schema_migrations WHERE version = 'pq_0006_role_bound_permissions';
  ```
  Auch mit dem neuen Code ist das wirkungsneutral (Katalog-Standard greift; im Test
  abgesichert). Danach ist `migrate up` wieder möglich.
- *Rechte nach der Migration versehentlich geändert:* `--diff=before.json` zeigt jede
  Abweichung je Benutzer; zurücksetzen über „Rollen & Rechte“ oder Restore.

## 5. Schritt 3 – Mandantenfähigkeit (umgesetzt)

Gewähltes Modell: **eine Datenbank je Mandant** (Begründung in `ARCHITECTURE.md` B.3).
Dadurch ist **keine Datenmigration** nötig:

- **Self-Hosting:** unverändert genau ein Mandant (`PQ_MODE=selfhosted`, Standard).
  Die bestehende Datenbank bleibt, wie sie ist.
- **Bestehende Installation in den SaaS-Betrieb übernehmen:**
  1. Plattform-Datenbank anlegen und `php bin/migrate.php up --platform`.
  2. Bestehende Datenbank als Mandant registrieren:
     `php bin/tenant.php adopt <slug> "<Name>" <bestehende_datenbank> --domain=<host>`
     (prüft, dass die Datenbank eine Planquadro-Datenbank ist, und übernimmt sie unverändert).
  3. `PQ_MODE=saas` setzen, `php bin/migrate.php up --all-tenants`.
  Rückweg: `PQ_MODE=selfhosted` – die Mandanten-Datenbank ist eine normale
  Einzel-Datenbank.
- **Neue Mandanten:** `php bin/tenant.php create` oder `/platform/`.

### Rechte in Phase 3

Rollen und Rechte liegen weiterhin in `role_permissions` und `custom_roles` je
Mandanten-Datenbank – damit sind sie automatisch mandantenspezifisch („Rollen je Mandant“),
ohne Datenmigration. Neu ist das zusätzliche, standardmäßig nirgends vergebene Recht
`view_absence_details_all` (Entscheidung E-2). Die rollengebundenen Prüfungen
`canManageUsers`, `canManageConfig` u. a. sind seit 0006 Rechte (Abschnitt 4); weil
`manage_users` in Bestandsinstallationen geändert worden sein kann, wurde es **nicht**
wiederverwendet, sondern das neue Recht `manage_team` angelegt.

## 6. Schritt 4 – Umzug auf Docker/Linux (Phase 4, optional)

1. Neues System per `docker compose up -d` mit leerer Datenbank starten (Healthcheck grün).
2. Wartungsmodus auf dem Altsystem aktivieren.
3. Konsistenten Dump ziehen (`mysqldump --single-transaction --routines --triggers`),
   ins neue System einspielen, `php bin/migrate.php baseline v6_9_0 && php bin/migrate.php up`.
4. Rechte-Snapshot alt/neu vergleichen, Stichprobe der Pläne (Zähler je Monat und Tabelle).
5. DNS umstellen, HTTPS über den Reverse Proxy. Altsystem 14 Tage schreibgeschützt behalten.

## 7. Datenkompatibilität

- Keine Tabelle und keine Spalte wird in Phase 2 entfernt oder umbenannt.
- `assignments`, `absences`, `plan_drafts` behalten ihr Format; Revisionen werden aus den
  vorhandenen Spalten berechnet und **nicht** gespeichert – ältere Clients funktionieren
  weiter (ohne Revision greift das bisherige Verhalten).
- Neue Spalten haben Standardwerte, sodass der Alt-Code (v6.9.0) gegen die migrierte
  Datenbank weiterläuft – Voraussetzung für einen schnellen Code-Rollback.

## 8. Rollback-Übersicht

| Situation | Vorgehen |
|---|---|
| Fehler im neuen Code, Schema nur additiv geändert | vorherigen Code-Stand ausrollen |
| Migration fehlgeschlagen | `schema_migrations` zeigt den Stand; Fehler beheben, `up` erneut (Runner bricht beim ersten Fehler ab, bereits angewendete Dateien sind vermerkt) |
| Daten beschädigt | Wartungsmodus, Restore des letzten konsistenten Backups, Änderungen seitdem aus `plan_change_log` nachvollziehen |
