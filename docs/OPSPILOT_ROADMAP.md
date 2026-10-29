# OpsPilot – Roadmap

Stand: 29.10.2026 · Ausgangsbasis: Einsatzplanung v6.9.0

OpsPilot entwickelt die bestehende Einsatzplanung zu einer mandantenfähigen Field- &
Onsite-Service-Plattform weiter. **Grundsatz: Keine Neuentwicklung.** Jede Phase liefert
lauffähige, getestete Zwischenstände; bestehende Daten, Rechte und Abläufe bleiben
erhalten.

Verwandte Dokumente: [`ARCHITECTURE.md`](ARCHITECTURE.md) ·
[`MIGRATION_PLAN.md`](MIGRATION_PLAN.md) · [`PERMISSIONS.md`](PERMISSIONS.md)

## Verbindliche Regeln

1. Keine bestehenden Funktionen entfernen. 2. Keine Daten oder Rechte verlieren.
3. Abläufe kompatibel halten. 4. Keine produktive DB-Änderung ohne Migration und
Wiederherstellungsweg. 5. Kleine, nachvollziehbare Commits. 6. Eine Codebasis für SaaS
und Self-Hosting. 7. Jeder Schritt getestet und dokumentiert. 8. Kein produktives oder
öffentliches Deployment ohne ausdrückliche Freigabe.

---

## Phase 1 – Bestandsaufnahme ✅

Ergebnis: dieses Dokument, `ARCHITECTURE.md`, `PERMISSIONS.md`, `MIGRATION_PLAN.md`.

### Funktionsinventar (bleibt vollständig erhalten)

Dashboard · Mein Plan · Gesamtplan · Monats- und Jahresplanung · Einsatzcodes
(Bereiche, Kunden/allgemein, Farben) · Dauereinsätze (Serienplanung nach Wochentagen) ·
Bemerkungen öffentlich/intern · interne Einträge · Mitarbeiter, Teams (bis zu 4
Vorgesetzte, transitiv), Rollen, eigene Rollen, Benutzer-Overrides · Urlaub, Krankheit,
Gleitzeit, Sperrtage, „offen“ · Feiertage und Schulferien inkl. Import · Vertretungen ·
Änderungsanträge mit Genehmigung · Planungsassistent mit gespeicherten Planungen und
Profilen · Entwürfe, Änderungsmarkierung, Veröffentlichung (eigene/alle/Jahr) ·
Live-Präsenz und Zeilensperren · WOTs, Stunden, Erl.-Spalte · iCal-Abo ·
Benachrichtigungen · Meldungen · geplante Wartung, Wartungsmodus · In-App-Backup ·
Changelog · Datenschutzseite · Login-Sperre, Captcha, Passwortrichtlinie.

### Befunde

Schweregrad: **K** kritisch · **H** hoch · **M** mittel · **N** niedrig.
Status: ✅ behoben · 🔧 in Arbeit (Phase 2) · ⏳ geplant (Phase) · ❓ Entscheidung nötig.

#### Sicherheit und Berechtigungen

| ID | Sev | Befund | Ort | Status |
|---|---|---|---|---|
| S-01 | K | Produktive DB-Zugangsdaten im Quellcode (Fallback-Werte) | `app/config/database.php` | ✅ beim Import entfernt – **Passwort rotieren!** |
| S-02 | K | Benutzerverwaltung: Manager/Verwaltung können beliebige Konten (auch eigenes) zu `admin` machen, Admin-Passwörter setzen/zurücksetzen | `pages/mitarbeiter.php` save/reset_pw/toggle | 🔧 Phase 2 |
| S-03 | K | `install.php` öffentlich erreichbar, legt bei fehlendem `admin` ein Konto mit bekanntem Passwort an | `public/install.php` | 🔧 Phase 2 |
| S-04 | K | Passwort-Hashes, Klartext-Einmalpasswörter und Kalender-Tokens aller Benutzer im HTML der Teamseite | `pages/mitarbeiter.php` (`json_encode($u)`) | 🔧 Phase 2 |
| S-05 | K | Stored XSS: `esc()` im JS maskiert keine Anführungszeichen → Attribut-Ausbruch über Antragsbemerkungen, Namen, WOT-Links | `assets/js/app.js`, `pages/genehmigungen.php` | 🔧 Phase 2 |
| S-06 | H | CSRF-Schutz fehlt auf allen APIs und Seiten-Aktionen außer Rollen (gemildert durch `SameSite=Strict`) | `api/*`, `pages/*` | 🔧 Phase 2 zentral |
| S-07 | H | Session Fixation: keine neue Session-ID nach Login | `index.php` | 🔧 Phase 2 |
| S-08 | H | Einmalpasswörter im Klartext gespeichert (`users.initial_password`) | `pages/mitarbeiter.php` | 🔧 Phase 2 (Spalte wird geleert, nicht mehr befüllt) |
| S-09 | H | Fehlende Team-Scope-Prüfung: WOTs, Stunden/Erl., Wochenkontext | `api/save_wots`, `api/save_month_data`, `api/change_requests` | 🔧 Phase 2 |
| S-10 | H | Genehmigen ohne `approve_requests` möglich | `api/change_requests` | 🔧 Phase 2 |
| S-11 | H | Vertrauliche Abwesenheitsart im Feld `key` der Abwesenheitsliste | `pages/abwesenheiten.php` | 🔧 Phase 2 |
| S-12 | H | XSS über Bereichsfarben und WOT-Farben/-Links (`javascript:`) | `planbereiche`, `save_wots`, Ausgaben | 🔧 Phase 2 Validierung + Escaping |
| S-13 | M | Login-Sperre per `X-Forwarded-For` umgehbar (Header ungeprüft übernommen) | `index.php`, `security.php` | 🔧 Phase 2 nur hinter vertrauenswürdigem Proxy |
| S-14 | M | Profil- und Rollen-AJAX ohne `requireLogin()` (erzwungener Passwortwechsel umgehbar) | `pages/profil.php`, `pages/rollen.php` | 🔧 Phase 2 |
| S-15 | M | „Niemand außer …“ löscht Overrides aller Benutzer statt nur der Rolle | `pages/rollen.php` | 🔧 Phase 2 |
| S-16 | M | Admin kann sich `manage_roles` entziehen und sperrt damit alle aus | `pages/rollen.php` | 🔧 Phase 2 Admin-Rolle schreibgeschützt |
| S-17 | M | Planungsassistent: Manager planen immer teamübergreifend, auch ohne `view_all_employees` | `pages/planungsassistent.php` | ❓ fachliche Entscheidung |
| S-18 | M | `view_all_employees` (Planungs-Scope) öffnet zugleich Gesundheitsdaten aller | `canSeeAbsenceDetails()` | ❓ fachliche Entscheidung, ⏳ Phase 3 |
| S-19 | M | Meldungen (`announcements active`) ohne Login abrufbar | `api/announcements` | 🔧 Phase 2 Login erforderlich |
| S-20 | M | Fehlermeldungen geben Datenbankdetails an den Client (`$e->getMessage()`) | diverse APIs | 🔧 Phase 2 für geänderte Endpunkte, ⏳ Rest in Phase 3 (Kernel) |
| S-21 | N | `onclick='…<?php echo e(...)?>…'` – `&#039;` wird im Handler zu `'` | `monatsplanung`, `rollen` | ⏳ Phase 5 (Inline-Handler ersetzen) |
| S-22 | N | CSP mit `unsafe-inline` | `.htaccess` | ⏳ Phase 5 |
| S-23 | N | Passwortrichtlinie Standard 6 Zeichen, 30-Tage-Zwangswechsel (entspricht nicht NIST/BSI) | Einstellungen | ⏳ Phase 3 (TenantConfig, Standard 12 Zeichen, kein Zwangsablauf) |

#### Datenintegrität und Mehrbenutzerbetrieb

| ID | Sev | Befund | Status |
|---|---|---|---|
| D-01 | H | **Lost Update:** Zweiter Planer überschreibt den Entwurf des ersten an derselben Zelle ohne Hinweis (UNIQUE `user_id,plan_date`, Upsert ohne Versionsprüfung) | 🔧 Phase 2 Revisionen + HTTP 409 |
| D-02 | H | `publish` liest Entwürfe ohne Sperre; ein parallel gespeicherter Entwurf wird beim Löschen des alten mitgelöscht | 🔧 Phase 2 `FOR UPDATE` |
| D-03 | H | Abwesenheitsmodul überschreibt genehmigten Urlaub/Krankheit und Einsätze der Vertretung ohne Prüfung; `delete` ohne Transaktion, ohne Datumsvalidierung | 🔧 Phase 2 (Prüfung, Transaktion, Validierung) |
| D-04 | H | Löschen eines Einsatzbereichs löscht per `ON DELETE CASCADE` die gesamte Einsatzhistorie | 🔧 Phase 2 Löschen bei vorhandenen Einsätzen verweigert |
| D-05 | M | „Rückgängig“ im Assistenten löscht spätere manuelle Entwürfe anderer (`planning_run_id` bleibt beim Überschreiben stehen) | 🔧 Phase 2 `planning_run_id` wird beim manuellen Speichern zurückgesetzt |
| D-06 | M | Kein Änderungsprotokoll je Zelle (wer hat wann was geändert/veröffentlicht) | 🔧 Phase 2 `plan_change_log` |
| D-07 | M | Alt-Migrationen nicht wiederholbar (setzen Rechte zurück, verschieben Daten) – entgegen README | ✅ Migrations-Runner mit Versionstabelle und Baseline |
| D-08 | M | Schema-Änderungen zur Laufzeit (`ALTER TABLE` in Requests) | 🔧 Phase 2 in Migration überführt, Laufzeit-DDL entfernt |
| D-09 | M | Persistente PDO-Verbindungen können offene Transaktionen/Sperren weiterreichen | ✅ beim Import abgeschaltet |
| D-10 | N | Unvalidierte Datumswerte erzeugen Einträge für 1970-01-01 | 🔧 Phase 2 für geänderte Endpunkte |

#### Backup

| ID | Sev | Befund | Status |
|---|---|---|---|
| B-01 | H | Backups liegen auf demselben Server wie die Datenbank, Schlüssel daneben | ⏳ Phase 4 |
| B-02 | H | Dump nicht konsistent (kein Snapshot/`--single-transaction`) | ⏳ Phase 4 |
| B-03 | M | AES-256-CBC ohne Authentisierung (keine Integritätsprüfung) | ⏳ Phase 4 |
| B-04 | M | Restore verschluckt Fehler einzelner Statements und meldet trotzdem Erfolg; nicht atomar | ⏳ Phase 4 |
| B-05 | M | Cron-Schlüssel in der URL (Logs, Proxy) | ⏳ Phase 4 |

#### Sonstiges

| ID | Befund | Status |
|---|---|---|
| X-01 | `public/einstellungen.php` ist eine veraltete, nicht lauffähige Kopie von `pages/einstellungen.php` | 🔧 Phase 2 entfernt (Aufruf leitet wie bisher auf `/einstellungen`) |
| X-02 | `genehmigungen.php` sendet `Location` nach HTML-Ausgabe (Umleitung wirkungslos) | 🔧 Phase 2 |
| X-03 | Rechte `manage_absences`, `use_planning_assistant` nicht in der Rechte-Oberfläche pflegbar | 🔧 Phase 2 |

---

## Phase 2 – Stabilisierung (aktuell, Branch `claude/opspilot-codebase-analysis-41d0wu`)

Ziel: Fehler beheben, Mehrbenutzerbetrieb absichern, Testnetz aufspannen – **ohne**
sichtbare Änderung etablierter Abläufe.

- [x] Bereinigter Import, Secrets aus dem Code
- [x] Migrations-Runner `bin/migrate.php` (Versionstabelle, Baseline, Sperre)
- [ ] Test-Infrastruktur: PHPUnit, Integrationstests über den eingebauten PHP-Server gegen
      echte MariaDB, Rewrite-Router, Testdaten für alle Rollen
- [ ] CI (GitHub Actions): Syntax-Lint, Tests gegen MariaDB, Secret-Scan
- [ ] Sicherheitsbefunde S-02 … S-16, S-19, S-20 (teilweise)
- [ ] Optimistic Concurrency (D-01/D-02), Änderungsprotokoll (D-06)
- [ ] Integritätsbefunde D-03 … D-05, D-08, D-10
- [ ] Offene Entscheidungen S-17, S-18 klären

## Phase 3 – Plattform

- Request-Kernel (`app/Kernel`): einheitlich Login, CSRF, Fehlerformat, Audit, Request-ID.
- Mandanten: `tenants`, `tenant_domains`, `tenant_id` in allen Fachtabellen, Mandant 1 für
  Bestand, mandantenbezogene Unique-Keys, TenantResolver, Isolationstests.
- `TenantConfig` (Branding, Module, Navigation, Dashboard-Widgets, Planungsregeln,
  Sicherheit) mit Kompatibilitätsschicht für `getSetting()`.
- Rechte als Code-Katalog, Rollen je Mandant, Scopes; rollengebundene Prüfungen in Rechte
  überführen; Rechte-Snapshot-Vergleich vor/nach Migration.
- Stammdaten: Standorte, Kunden, Qualifikationen (neue Tabellen, optional verknüpft mit
  Einsatzcodes und Mitarbeitern).
- Module ein-/ausschaltbar; Navigation und Dashboard aus der TenantConfig.
- API v1 (REST, JSON, Token-Auth) als Grundlage für Mobile.
- Server-Sent Events für Live-Aktualisierung.
- OIDC/SSO optional.

## Phase 4 – Deployment, Backup, Recovery

- Docker-Image (PHP-FPM + Nginx), `docker-compose.yml` (App, MariaDB, optional Caddy für
  HTTPS, optional Redis), Healthchecks (`/healthz` live, `/readyz` DB + Migrationen),
  `restart: unless-stopped`, Volumes für DB und Dateien.
- Konfiguration ausschließlich über Umgebungsvariablen (+ `*_FILE` für Secrets),
  dokumentiert in `docs/CONFIGURATION.md`.
- Update-Prozess: Backup → `migrate up` → Healthcheck → Rollback-Pfad.
- **Backup-System:** `mariadb-dump --single-transaction` (konsistent), Dateien, Verschlüsselung
  mit `age`/`restic` (authentisiert), Ziele lokal + extern (S3/SFTP/zweiter Server),
  3-2-1 mit GFS-Aufbewahrung (täglich/wöchentlich/monatlich, konfigurierbar),
  mandantengenauer Export/Restore, wöchentlicher automatischer Restore-Test in einen
  Wegwerf-Container, Status und Fehler im Admin-Bereich und per Mail.
- Disaster-Recovery-Handbuch (`docs/DISASTER_RECOVERY.md`) mit RPO/RTO.
- Release-Workflow: Image-Build und -Signatur bei Tag (Push in Registry erst nach Freigabe).

## Phase 5 – Oberfläche

- OpsPilot-Designsystem (Design Tokens als CSS-Variablen, Light/Dark, Mandantenfarben).
- Bessere Kontraste (WCAG 2.2 AA), Fokus-Stile, Tastaturbedienung der Planungsmatrix,
  ARIA für Dialoge.
- Performance großer Pläne: serverseitige Vorberechnung, weniger DOM, virtuelles Scrollen
  ab n Zeilen, N+1-Abfragen entfernen (`vacLeftAtStart` je Zeile).
- Filter/Suche vereinheitlichen; Drag-and-Drop als Ergänzung zum bestehenden
  Werkzeug-Klick (nicht als Ersatz).
- Inline-Handler entfernen → CSP ohne `unsafe-inline`.

## Phase 6 – Mobile

- Mobile-Web-App (TypeScript) als PWA + Capacitor-Hülle für iOS/Android (Begründung:
  `ARCHITECTURE.md` B.8).
- Login (Passwort, optional OIDC), Token im Keychain/Keystore, optional Biometrie.
- Persönlicher Plan, Team-/Einsatzübersicht nach Rechten, Abwesenheiten, Einsatzdetails
  und Kundeninformationen, Push bei Planänderungen (FCM/APNs; Self-Hosting: Web-Push/ntfy),
  Offline-Lesemodus (verschlüsselter Cache, Sync per `since`-Cursor), Dark Mode, Branding
  aus der TenantConfig.

## Phase 7 – Qualitätssicherung

- Integrationstests aller Endpunkte (Rechte × Rollen × Scope × Mandant).
- Nebenläufigkeitstests (parallele Speicher-/Veröffentlichungsvorgänge).
- Sicherheitschecks: SAST, Abhängigkeitsprüfung, Secret-Scan, OWASP-ZAP-Baseline gegen
  Testumgebung.
- Backup-/Restore-Tests und Deploymenttests (Compose hochfahren, Migrationen, Healthcheck)
  in der CI.

---

## Offene Entscheidungen (Product Owner)

| # | Frage | Empfehlung |
|---|---|---|
| E-1 | S-17: Sollen Manager im Assistenten ohne Freigabe teamübergreifend planen? | Wie Teamleiter an die Einstellung koppeln |
| E-2 | S-18: Soll „darf alle planen“ auch Gesundheitsdaten aller freigeben? | Nein – Vertraulichkeit an Vorgesetzten-Beziehung binden |
| E-3 | Lizenz des Repositorys (derzeit „Alle Rechte vorbehalten“, Repository öffentlich) | Klären, ob die Rechte am Code bei Ihnen liegen; bis dahin Repository privat schalten |
| E-4 | Passwortrichtlinie: 30-Tage-Zwangswechsel beibehalten? | Abschaffen, Mindestlänge 12 (BSI/NIST) |
| E-5 | Branch-Modell: `main` (geschützt) ← `develop` ← `feature/*`, Releases per Tag | so einrichten, sobald `main` befüllt werden darf |
