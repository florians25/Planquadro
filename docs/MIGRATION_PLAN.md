# OpsPilot – Migrationsplan

Stand: 29.10.2026

Wie eine bestehende Einsatzplanung-Installation (v6.x, Apache/Shared Hosting, MySQL) auf
OpsPilot gebracht wird – **ohne Datenverlust und ohne ungewollte Rechteänderung**. Jeder
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

## 2. Schritt 0 – Vorbereitung (einmalig, vor dem ersten OpsPilot-Deployment)

1. **DB-Passwort rotieren.** Das bisherige Passwort stand im Quellcode und im
   weitergegebenen Archiv. Neues Passwort im Hosting-Panel setzen.
2. **Zugangsdaten auslagern.** `app/config/database.php` enthält keine Fallback-Werte
   mehr. Vor dem Hochladen eine der beiden Varianten einrichten:
   - Umgebungsvariablen `OPSPILOT_DB_HOST`, `OPSPILOT_DB_NAME`, `OPSPILOT_DB_USER`,
     `OPSPILOT_DB_PASS` (z. B. `SetEnv` in einer nicht ausgelieferten Konfiguration), **oder**
   - `app/config/secrets.php` nach Vorlage `secrets.sample.php`.
   Die bisherigen Variablen `CCKOE_DB_*` werden weiterhin gelesen.
3. **Bestehende Backups sichern.** `app/backups/*.epbak` und `.backup_key` vom Server
   herunterladen und getrennt voneinander aufbewahren (Schlüssel z. B. im
   Passwortmanager).
4. **`install.php` prüfen.** Ab OpsPilot ist es ohne gesetzte Umgebungsvariable
   `OPSPILOT_ALLOW_INSTALL=1` gesperrt. Auf dem bisherigen Server die Datei sofort löschen.

## 3. Schritt 1 – Versionsführung der Datenbank (Phase 2)

Bisher gibt es keine Aufzeichnung, welche `migrate_v*.sql` eingespielt wurden. Die
Alt-Skripte dürfen **nicht** erneut laufen: `migrate_v6_3_1` und `migrate_v6_9_0`
überschreiben Rollenrechte, `migrate_v5_2_7` verschiebt Datensätze.

```bash
# Backup!
php bin/migrate.php status                 # erkennt "bestehende Installation ohne Versionstabelle"
php bin/migrate.php baseline v6_9_0        # letzte tatsächlich eingespielte Alt-Migration
php bin/migrate.php up                     # spielt nur neue OpsPilot-Migrationen ein
```

`baseline` schreibt ausschließlich in die neue Tabelle `schema_migrations`; Fachdaten
bleiben unberührt. Rückweg: `DROP TABLE schema_migrations`.

Ohne Shell-Zugang (Shared Hosting) werden die Dateien unter `migrations/opspilot/` wie
bisher in Versionsreihenfolge per phpMyAdmin eingespielt; sie sind so geschrieben, dass sie
mehrfach ausgeführt werden können (`IF NOT EXISTS`, `INSERT IGNORE`).

### Migrationen in Phase 2

| Datei | Inhalt | Art | Rückweg |
|---|---|---|---|
| `0001_runtime_ddl.sql` | `users.cal_token`, `editing_status.target_uid` (bisher zur Laufzeit angelegt), `changelog.entry_type` | additiv | nicht nötig |
| `0002_plan_change_log.sql` | Tabelle `plan_change_log` (Änderungsprotokoll je Zelle) | additiv | `DROP TABLE plan_change_log` |
| `0003_clear_initial_passwords.sql` | leert `users.initial_password` (Klartext-Einmalpasswörter). Das Einmalpasswort bleibt über den Hash gültig; der Wechselzwang bleibt bestehen | Datenbereinigung | nicht umkehrbar – gewollt; Werte wurden nirgends angezeigt |
| `0004_permission_catalog.sql` | ergänzt fehlende `role_permissions`-Zeilen für `manage_absences`/`use_planning_assistant` **nur dort, wo keine existieren** und mit dem bisher wirksamen Wert | additiv, rechteneutral | Zeilen löschen |

## 4. Schritt 2 – Rechte-Migration ohne Privilegienausweitung (Phase 3)

Ziel: Rollen und Rechte werden mandantenfähig, rollengebundene Prüfungen
(`canManageUsers`, `canManageConfig`, …) werden echte Rechte.

1. **Snapshot vorher:** `php bin/permissions-snapshot.php > before.json` – berechnet für
   jeden aktiven Benutzer die effektiven Rechte (inkl. rollengebundener Prüfungen,
   Overrides, Vertretungen) und den Team-Scope (Menge sichtbarer Benutzer-IDs).
2. **Migration** legt die neuen Rechte (`users.manage`, `config.manage`, …) so an, dass sie
   exakt die heutigen Rollenbindungen abbilden (z. B. `config.manage` für admin, manager,
   verwaltung). Bestehende Overrides werden 1:1 übernommen.
3. **Snapshot nachher** und `php bin/permissions-snapshot.php --diff before.json`.
   **Jede Abweichung bricht das Deployment ab**, außer sie steht in der Liste der
   gewollten Fehlerbehebungen (`PERMISSIONS.md` Abschnitt 6, P-1 … P-7).
4. Der Vergleich läuft zusätzlich als automatisierter Test gegen eine anonymisierte Kopie.

## 5. Schritt 3 – Mandantenfähigkeit (Phase 3)

Reihenfolge (jeweils eigenes Release, expand → migrate → contract):

1. **Expand:** Tabellen `tenants`, `tenant_domains`, `tenant_settings`; Mandant `1` aus
   `app_settings.company_name` anlegen. Spalte `tenant_id INT NULL` in allen Fachtabellen.
2. **Backfill:** `UPDATE … SET tenant_id = 1` tabellenweise in Blöcken (keine langen
   Sperren), danach `NOT NULL DEFAULT 1` und Fremdschlüssel auf `tenants`.
3. **Code:** Alle Abfragen über den tenant-gebundenen Zugriff; neue Unique-Keys
   (`tenant_id, username` …) zusätzlich zu den alten anlegen.
4. **Contract:** alte, mandantenlose Unique-Keys entfernen, sobald die Isolationstests
   grün sind. `app_settings`-Werte in `tenant_settings` übernehmen (alte Zeilen bleiben
   lesbar).
5. Self-Hosting bleibt bei genau einem Mandanten; für Benutzer ändert sich nichts.

Rückweg bis einschließlich 3: Code-Rollback (Spalten stören den alten Code nicht).
Nach 4: Restore aus dem Backup vor Schritt 4.

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
