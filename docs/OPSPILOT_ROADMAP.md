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
Status: ✅ behoben · ⏳ geplant (Phase) · ❓ Entscheidung nötig.

#### Sicherheit und Berechtigungen

| ID | Sev | Befund | Ort | Status |
|---|---|---|---|---|
| S-01 | K | Produktive DB-Zugangsdaten im Quellcode (Fallback-Werte) | `app/config/database.php` | ✅ beim Import entfernt – **Passwort rotieren!** |
| S-02 | K | Benutzerverwaltung: Manager/Verwaltung können beliebige Konten (auch eigenes) zu `admin` machen, Admin-Passwörter setzen/zurücksetzen | `pages/mitarbeiter.php` save/reset_pw/toggle | ✅ |
| S-03 | K | `install.php` öffentlich erreichbar, legt bei fehlendem `admin` ein Konto mit bekanntem Passwort an | `public/install.php` | ✅ |
| S-04 | K | Passwort-Hashes, Klartext-Einmalpasswörter und Kalender-Tokens aller Benutzer im HTML der Teamseite | `pages/mitarbeiter.php` (`json_encode($u)`) | ✅ |
| S-05 | K | Stored XSS: `esc()` im JS maskiert keine Anführungszeichen → Attribut-Ausbruch über Antragsbemerkungen, Namen, WOT-Links | `assets/js/app.js`, `pages/genehmigungen.php` | ✅ |
| S-06 | H | CSRF-Schutz fehlt auf allen APIs und Seiten-Aktionen außer Rollen (gemildert durch `SameSite=Strict`) | `api/*`, `pages/*` | ✅ zentral |
| S-07 | H | Session Fixation: keine neue Session-ID nach Login | `index.php` | ✅ |
| S-08 | H | Einmalpasswörter im Klartext gespeichert (`users.initial_password`) | `pages/mitarbeiter.php` | ✅ (Spalte wird geleert, nicht mehr befüllt) |
| S-09 | H | Fehlende Team-Scope-Prüfung: WOTs, Stunden/Erl., Wochenkontext | `api/save_wots`, `api/save_month_data`, `api/change_requests` | ✅ |
| S-10 | H | Genehmigen ohne `approve_requests` möglich | `api/change_requests` | ✅ |
| S-11 | H | Vertrauliche Abwesenheitsart im Feld `key` der Abwesenheitsliste | `pages/abwesenheiten.php` | ✅ |
| S-12 | H | XSS über Bereichsfarben und WOT-Farben/-Links (`javascript:`) | `planbereiche`, `save_wots`, Ausgaben | ✅ Validierung + Escaping |
| S-13 | M | Login-Sperre per `X-Forwarded-For` umgehbar (Header ungeprüft übernommen) | `index.php`, `security.php` | ✅ nur hinter vertrauenswürdigem Proxy |
| S-14 | M | Profil- und Rollen-AJAX ohne `requireLogin()` (erzwungener Passwortwechsel umgehbar) | `pages/profil.php`, `pages/rollen.php` | ✅ |
| S-15 | M | „Niemand außer …“ löscht Overrides aller Benutzer statt nur der Rolle | `pages/rollen.php` | ✅ |
| S-16 | M | Admin kann sich `manage_roles` entziehen und sperrt damit alle aus | `pages/rollen.php` | ✅ Admin-Rolle schreibgeschützt |
| S-17 | M | Planungsassistent: Manager planen immer teamübergreifend, auch ohne `view_all_employees` | `pages/planungsassistent.php` | ✅ entschieden (E-1): bleibt wie bisher |
| S-18 | M | `view_all_employees` (Planungs-Scope) öffnet zugleich Gesundheitsdaten aller | `canSeeAbsenceDetails()` | ✅ (E-2): nur Vorgesetzten-Beziehung, neues Recht `view_absence_details_all` |
| S-19 | M | Meldungen (`announcements active`) ohne Login abrufbar | `api/announcements` | ✅ Login erforderlich |
| S-20 | M | Fehlermeldungen geben Datenbankdetails an den Client (`$e->getMessage()`) | diverse APIs | ✅ für geänderte Endpunkte, ⏳ Rest in Phase 3 (Kernel) |
| S-21 | N | `onclick='…<?php echo e(...)?>…'` – `&#039;` wird im Handler zu `'` | `monatsplanung`, `rollen` | ⏳ Phase 5 (Inline-Handler ersetzen) |
| S-22 | N | CSP mit `unsafe-inline` | `.htaccess` | ⏳ Phase 5 |
| S-23 | N | Passwortrichtlinie Standard 6 Zeichen, 30-Tage-Zwangswechsel (entspricht nicht NIST/BSI) | Einstellungen | ✅ entschieden (E-4): bleibt (6 Zeichen, 30 Tage), weiter einstellbar |

#### Datenintegrität und Mehrbenutzerbetrieb

| ID | Sev | Befund | Status |
|---|---|---|---|
| D-01 | H | **Lost Update:** Zweiter Planer überschreibt den Entwurf des ersten an derselben Zelle ohne Hinweis (UNIQUE `user_id,plan_date`, Upsert ohne Versionsprüfung) | ✅ Revisionen + HTTP 409 |
| D-02 | H | `publish` liest Entwürfe ohne Sperre; ein parallel gespeicherter Entwurf wird beim Löschen des alten mitgelöscht | ✅ `FOR UPDATE` |
| D-03 | H | Abwesenheitsmodul überschreibt genehmigten Urlaub/Krankheit und Einsätze der Vertretung ohne Prüfung; `delete` ohne Transaktion, ohne Datumsvalidierung | ✅ (Prüfung, Transaktion, Validierung) |
| D-04 | H | Löschen eines Einsatzbereichs löscht per `ON DELETE CASCADE` die gesamte Einsatzhistorie | ✅ Löschen bei vorhandenen Einsätzen verweigert |
| D-05 | M | „Rückgängig“ im Assistenten löscht spätere manuelle Entwürfe anderer (`planning_run_id` bleibt beim Überschreiben stehen) | ✅ `planning_run_id` wird beim manuellen Speichern zurückgesetzt |
| D-06 | M | Kein Änderungsprotokoll je Zelle (wer hat wann was geändert/veröffentlicht) | ✅ `plan_change_log` |
| D-07 | M | Alt-Migrationen nicht wiederholbar (setzen Rechte zurück, verschieben Daten) – entgegen README | ✅ Migrations-Runner mit Versionstabelle und Baseline |
| D-08 | M | Schema-Änderungen zur Laufzeit (`ALTER TABLE` in Requests) | ✅ in Migration überführt, Laufzeit-DDL entfernt |
| D-09 | M | Persistente PDO-Verbindungen können offene Transaktionen/Sperren weiterreichen | ✅ beim Import abgeschaltet |
| D-10 | N | Unvalidierte Datumswerte erzeugen Einträge für 1970-01-01 | ✅ für geänderte Endpunkte |

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
| X-01 | `public/einstellungen.php` ist eine veraltete, nicht lauffähige Kopie von `pages/einstellungen.php` | ✅ entfernt (Aufruf leitet wie bisher auf `/einstellungen`) |
| X-02 | `genehmigungen.php` sendet `Location` nach HTML-Ausgabe (Umleitung wirkungslos) | ✅ |
| X-03 | Rechte `manage_absences`, `use_planning_assistant` nicht in der Rechte-Oberfläche pflegbar | ✅ |

#### Sicherheitsreview der neuen Plattformteile (Phase 7)

Unabhängiges Review von Mandanten, TenantConfig, OIDC, API v1 und Push. Alle Befunde
behoben und mit Regressionstests abgesichert (`tests/Integration/SecurityReviewTest.php`,
`tests/Unit/OidcSsrfTest.php`, `TenancyTest`).

| ID | Sev | Befund | Status |
|---|---|---|---|
| R-01 | H | Recht „Einstellungen verwalten“ konnte SSO auf einen eigenen IdP umstellen und sich so als Administrator anmelden | ✅ SSO nur durch Admins; Admin-Konten standardmäßig nicht per SSO |
| R-02 | H | Konfliktvorschau (`save_bulk check_conflicts`) zeigte Urlaubs-/Krankheitsart ohne Detailrecht | ✅ nur „Abwesend“ |
| R-03 | H | SaaS: plattformweites `OPSPILOT_OIDC_CLIENT_SECRET` ging an den vom Mandanten gewählten Token-Endpunkt | ✅ nur für festgelegten `OPSPILOT_OIDC_ISSUER` |
| R-04 | M | SSRF über Issuer/Discovery-URL | ✅ SaaS: keine internen Ziele, IP-Pinning |
| R-05 | M | App-Sitzungen überlebten Passwortwechsel; Rotation verlängerte die Laufzeit | ✅ Widerruf, absolute Laufzeit, Prüfungen beim Refresh |
| R-06 | M | Mandanten-Header auch für Nicht-API-Pfade (`?x=/api/v1/`) | ✅ nur Pfad `/api/v1/…` |
| R-07 | N | Plattform-Admin ohne gesetzten `OPSPILOT_PLATFORM_HOST` auf jedem Host | ✅ verweigert |
| R-08 | N | Passwort-Orakel bei „nur SSO“; Laufzeitunterschied bei unbekannten Benutzern | ✅ einheitliche Antwort, Dummy-Hash |
| R-09 | M | SSO-Rücksprung in echten Browsern durch `SameSite=Strict` gebrochen | ✅ Zwischenstand in eigenem Lax-Cookie |
| R-10 | M | API-SSO ohne Pflicht für PKCE-Verifier/nonce | ✅ Pflicht, App sendet nonce |

---

## Phase 2 – Stabilisierung ✅

Ziel: Fehler beheben, Mehrbenutzerbetrieb absichern, Testnetz aufspannen – **ohne**
sichtbare Änderung etablierter Abläufe.

- [x] Bereinigter Import, Secrets aus dem Code
- [x] Migrations-Runner `bin/migrate.php` (Versionstabelle, Baseline, Sperre)
- [x] Test-Infrastruktur: PHPUnit, Integrationstests über den eingebauten PHP-Server gegen
      echte MariaDB, Rewrite-Router, Testdaten für alle Rollen
- [x] CI (GitHub Actions): Syntax-Lint, Tests gegen MariaDB 10.11 und MySQL 8.0 (PHP 8.1/8.3), Secret-Scan
- [x] Sicherheitsbefunde S-02 … S-16, S-19, S-20 (teilweise)
- [x] Optimistic Concurrency (D-01/D-02), Änderungsprotokoll (D-06)
- [x] Integritätsbefunde D-03 … D-05, D-08, D-10
- [x] Alt-Migrationen auch auf MySQL 8 lauffähig (MariaDB-Syntax wird übersetzt)
- [x] Entscheidungen S-17, S-18 (E-1, E-2) umgesetzt
- [x] Planungsassistent (`apply`/Rückgängig) sperrt, protokolliert, überschreibt keine fremden Entwürfe
- [x] Bemerkungen (`api/save_remark`) mit Revisionsprüfung
- [x] Session-Timeout wird durchgesetzt (Einstellung war bis v6.9.0 wirkungslos)
- [ ] Verbleibende Endpunkte ohne Fehlertext-Weitergabe (S-20) – mit dem Request-Kernel

## Phase 3 – Plattform ✅ (Kern) · ⏳ Rest

Umgesetzt:
- [x] Mandantenfähigkeit mit **einer Datenbank je Mandant** + Plattform-DB (Begründung:
      `ARCHITECTURE.md` B.3), Auflösung per Host, Sitzungsbindung je Mandant,
      `bin/tenant.php` (create/adopt/suspend/domain/platform-admin), `migrate --all-tenants`,
      Plattform-Administration `/platform/`, Isolationstests
- [x] TenantConfig mit Kompatibilitätsschicht (bisherige Einstellungen bleiben gültig):
      Branding (WCAG-Kontrast), Module, Navigation, Dashboard-Widgets, Planungsregeln
- [x] Stammdaten Kunden, Standorte, Qualifikationen (optional an Einsatzcodes)
- [x] API v1 (Token-Auth, Refresh-Rotation) und Push (FCM/APNs/ntfy) – `docs/API.md`
- [x] OIDC/SSO optional (PKCE, JWKS, keine Auto-Anlage)

Offen (bewusst verschoben, kein Funktionsverlust):

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
- OIDC/SSO optional. ✅
- Ursprünglich geplante `tenant_id`-Spalten entfallen durch die Datenbank je Mandant.

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

## Phase 5 – Oberfläche ✅ (ohne CSP-Umbau)

Umgesetzt: Designsystem mit Tokens und Dark Mode (`docs/DESIGN_SYSTEM.md`), Kontraste,
Fokus, Skip-Link, Dialog-ARIA, Tastaturbedienung der Matrix, Filter „Nur Änderungen“,
Leistung großer Pläne (Beispiel Monatsplan: 856 → 68 Abfragen, 293 → 54 ms).
Offen: Inline-Handler entfernen / CSP ohne `unsafe-inline` (S-21, S-22), virtuelles Scrollen,
Drag-and-Drop.


- OpsPilot-Designsystem (Design Tokens als CSS-Variablen, Light/Dark, Mandantenfarben).
- Bessere Kontraste (WCAG 2.2 AA), Fokus-Stile, Tastaturbedienung der Planungsmatrix,
  ARIA für Dialoge.
- Performance großer Pläne: serverseitige Vorberechnung, weniger DOM, virtuelles Scrollen
  ab n Zeilen, N+1-Abfragen entfernen (`vacLeftAtStart` je Zeile).
- Filter/Suche vereinheitlichen; Drag-and-Drop als Ergänzung zum bestehenden
  Werkzeug-Klick (nicht als Ersatz).
- Inline-Handler entfernen → CSP ohne `unsafe-inline`.

## Phase 6 – Mobile ✅ (Code, noch nicht in den Stores)

Umgesetzt in `mobile/` (Capacitor 8, TypeScript, Vite): alle unten genannten Funktionen,
108 Tests, End-to-End gegen das echte Backend geprüft. Store-Veröffentlichung braucht
Signierschlüssel, Apple-/Google-Konten und Ihre Freigabe (`mobile/README.md`).


- Mobile-Web-App (TypeScript) als PWA + Capacitor-Hülle für iOS/Android (Begründung:
  `ARCHITECTURE.md` B.8).
- Login (Passwort, optional OIDC), Token im Keychain/Keystore, optional Biometrie.
- Persönlicher Plan, Team-/Einsatzübersicht nach Rechten, Abwesenheiten, Einsatzdetails
  und Kundeninformationen, Push bei Planänderungen (FCM/APNs; Self-Hosting: Web-Push/ntfy),
  Offline-Lesemodus (verschlüsselter Cache, Sync per `since`-Cursor), Dark Mode, Branding
  aus der TenantConfig.

## Phase 7 – Qualitätssicherung ✅ (laufend)

Umgesetzt: Integrationstests über HTTP für Rechte, Scope, Mandanten, API, OIDC, Push und
Nebenläufigkeit; CI auf MariaDB 10.11 und MySQL 8.0 (PHP 8.1/8.3), Mobile-Tests,
Secret-Scan (gitleaks), Abhängigkeits-Audit und OWASP-ZAP-Baseline (`security.yml`),
Sicherheitsreview R-01 … R-10.


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
| E-1 | S-17: Sollen Manager im Assistenten ohne Freigabe teamübergreifend planen? | ✅ entschieden: ja, bleibt wie bisher |
| E-2 | S-18: Soll „darf alle planen“ auch Gesundheitsdaten aller freigeben? | ✅ entschieden: nein, umgesetzt |
| E-3 | Lizenz des Repositorys (derzeit „Alle Rechte vorbehalten“, Repository öffentlich) | Klären, ob die Rechte am Code bei Ihnen liegen; bis dahin Repository privat schalten |
| E-4 | Passwortrichtlinie: 30-Tage-Zwangswechsel beibehalten? | ✅ entschieden: bleibt (6 Zeichen, 30 Tage) |
| E-5 | Branch-Modell: `main` (geschützt) ← `develop` ← `feature/*`, Releases per Tag | ✅ freigegeben; `main` und `develop` angelegt (Branch-Schutz in den GitHub-Einstellungen aktivieren) |
