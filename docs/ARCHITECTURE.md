# Planquadro – Architektur

Stand: 29.10.2026 · Ausgangsbasis: Einsatzplanung v6.9.0 (Import-Commit `969f2a3`)

Dieses Dokument beschreibt **(A)** die bestehende Anwendung, wie sie heute läuft, und
**(B)** die Zielarchitektur von Planquadro. Die Zielarchitektur wird schrittweise aus (A)
heraus entwickelt; es gibt keine Neuentwicklung von Grund auf
(siehe [`ROADMAP.md`](ROADMAP.md) und [`MIGRATION_PLAN.md`](MIGRATION_PLAN.md)).

---

## A. Ist-Zustand (Einsatzplanung v6.9.0)

### A.1 Technik-Stack

| Ebene | Technik |
|---|---|
| Sprache | PHP ≥ 8.1, prozedural, ohne Framework und ohne Composer-Abhängigkeiten |
| Datenbank | MySQL/MariaDB (InnoDB, `utf8mb4`), Zugriff per PDO, durchgängig Prepared Statements |
| Webserver | Apache mit `mod_rewrite` (`.htaccess`), ursprünglich Shared Hosting |
| Frontend | Serverseitig gerenderte Seiten, Vanilla-JS (`public/assets/js/app.js`, ~1 500 Zeilen) und seitenlokale Skripte, eine CSS-Datei (~2 000 Zeilen) |
| Echtzeit | Polling: `api/live_status` (`ping` alle paar Sekunden, Daten-Hash, Präsenz) |
| Sessions | PHP-Dateisessions; Cookie `HttpOnly`, `SameSite=Strict`, `Secure` unter HTTPS |
| Hintergrundjobs | keine; tägliches Backup über eine per Cron aufgerufene URL mit Schlüssel |

### A.2 Verzeichnisstruktur

```
app/                    nicht öffentlich
  config/database.php   DB-Konfiguration (seit Import: nur Umgebung/secrets.php)
  backups/              In-App-Backups (*.epbak) + Schlüsseldatei
public/                 Document Root
  index.php             Login + Passwortwechsel
  install.php           legt Admin-Benutzer an (siehe Befund S-03)
  logout.php
  includes/             auth.php, functions.php, security.php, header/footer
  pages/                eine Datei pro Seite; AJAX-Handler am Dateianfang
  api/                  JSON-Endpunkte
  assets/               app.js, style.css
database.sql            Basisschema (v3.1)
migrations/             40 inkrementelle Skripte migrate_v3_2 … migrate_v6_9_0
```

Routing (`public/.htaccess`): `/name` → `pages/name.php`, `/api/name` → `api/name.php`.
Jede Seite bindet `includes/header.php` ein, das `requireLogin()` und den Wartungsmodus
durchsetzt. AJAX-Handler stehen **vor** dem Header-Include und müssen Login und Rechte
selbst prüfen.

### A.3 Funktionsmodule

| Modul | Datei(en) | Kern |
|---|---|---|
| Login, Passwortwechsel, Sperre, Captcha | `index.php` | `login_attempts`, `audit_log` |
| Dashboard | `pages/dashboard.php` | heutige Abwesenheiten, Kennzahlen, Meldungen |
| Mein Plan | `pages/mein_plan.php` | persönliche Einsätze, eigene Anträge |
| Gesamtplan | `pages/gesamtplan.php` | veröffentlichter Monatsplan aller Mitarbeiter (Abwesenheitsgründe maskiert) |
| Monatsplanung | `pages/monatsplanung.php`, `api/save_bulk`, `api/save_remark`, `api/publish` | Bearbeitung über Entwürfe, Veröffentlichung |
| Jahresplan | `pages/jahresuebersicht.php` | Jahresansicht, Jahresveröffentlichung |
| Urlaub | `pages/urlaubsplanung.php` | Urlaubs-/Kranktage je Mitarbeiter |
| Abwesenheiten | `pages/abwesenheiten.php` | Zeiträume erfassen, Vertretungsvorschläge |
| Vertretung | `pages/vertretung.php` | befristete Übertragung des Team-Scopes (`deputations`) |
| Anträge | `pages/genehmigungen.php`, `api/change_requests` | Änderungsanträge von Mitarbeitern (optional) |
| Planungsassistent | `pages/planungsassistent.php` (3 800 Zeilen) | Bedarfsplanung, Automatik, gespeicherte Planungen (`planning_runs`, `planning_profiles`) |
| Team | `pages/mitarbeiter.php` | Benutzer, Rollen, Vorgesetzte (bis zu 4), Einmalpasswörter |
| Rollen & Rechte | `pages/rollen.php` | Rollenrechte, Benutzer-Overrides, eigene Rollen |
| Bereiche (Einsatzcodes) | `pages/planbereiche.php` | `plan_areas`: Kürzel, Farben, Typ kunde/allgemein |
| Feiertage/Ferien | `pages/feiertage.php`, `api/feiertage` | inkl. Import aus externer API |
| Einstellungen | `pages/einstellungen.php` | Sicherheit, Datenschutz, Backup, Wartung, Meldungen |
| iCal-Abo | `api/ical` | persönlicher Kalender-Feed per Token |
| WOTs, Stunden, Erl. | `api/save_wots`, `api/save_month_data` | Zusatzdaten je Mitarbeiter und Monat |

### A.4 Datenmodell (Kern)

```
users ──< user_supervisors >── users          (Vorgesetzte, max. 4, transitiv ausgewertet)
users ──< assignments  (user_id, plan_date) UNIQUE ── plan_areas
users ──< absences     (user_id, absence_date) UNIQUE
users ──< plan_drafts  (user_id, plan_date) UNIQUE   ← EIN Entwurf je Zelle, egal von wem
publish_log (year, month, published_by, published_at)
role_permissions (role, permission, granted) · permission_user_overrides (permission, user_id, granted)
custom_roles · deputations · change_requests · notifications · editing_status
planning_runs · planning_profiles · user_wots · user_month_data · holidays
app_settings (key/value, global) · audit_log · login_attempts · announcements · changelog
```

**Zelle = (Mitarbeiter, Tag).** Pro Zelle gibt es höchstens einen veröffentlichten Einsatz
**oder** eine veröffentlichte Abwesenheit, plus höchstens einen Entwurf. Die „effektive“
Sicht für Planer ist *veröffentlicht + darübergelegter Entwurf*
(`assignments($y,$m,false)` / `absences($y,$m,false)` in `includes/functions.php`).
`is_published=0` in `assignments`/`absences` ist seit v5.2.7 nicht mehr in Gebrauch.

### A.5 Planungs-Workflow (Entwurf → Veröffentlichung)

1. Planer bearbeitet Zellen in der Monatsplanung → `api/save_bulk` schreibt **Upserts in
   `plan_drafts`** (`action` = `set`/`clear`, `change_type` = `assignment`/`absence`/`locked`).
2. Die Seite fragt per `api/live_status` (`ping`) einen Daten-Hash ab; ändert er sich, lädt
   sie die Zellen nach (`cells`) und markiert eigene/fremde Entwürfe.
3. `api/publish` wendet Entwürfe an: je Zelle `DELETE` in `assignments`/`absences`, `INSERT`
   des neuen Stands, Entwurf löschen, Eintrag in `publish_log`.
   Varianten: *eigene Änderungen* (`plan_publish_own`), *alle* (`plan_publish_all`),
   *ganzes Jahr* (`plan_publish_year`). Interne Entwürfe (`is_internal=1`) werden nie
   veröffentlicht.
4. Mitarbeiter sehen ausschließlich veröffentlichte Daten.

### A.6 Berechtigungsmodell

Ausführlich in [`PERMISSIONS.md`](PERMISSIONS.md). Kurzfassung: feste Rollen
(`admin, manager, teamleiter, verwaltung, lager, mitarbeiter, azubi_1..3`) plus eigene
Rollen; 17 Rechte in `role_permissions`, übersteuerbar je Benutzer; zusätzlich ein
**Team-Scope** (Vorgesetzte sehen und bearbeiten nur ihr Team, transitiv, plus
Vertretungen) und eine **Vertraulichkeitsregel** für Krankheit/Urlaub. Einige Prüfungen
sind fest an Rollen gebunden (`canManageUsers`, `canManageConfig`, Wartung, Bereinigung).

### A.7 Schwachstellen der Ist-Architektur

Die vollständige, priorisierte Befundliste steht in der Roadmap (Abschnitt „Befunde“).
Architektonisch relevant:

- **Kein zentraler Request-Lebenszyklus.** Jeder Endpunkt prüft Login, Rechte, CSRF und
  Eingaben selbst – mit Lücken (CSRF fehlt fast überall, Scope-Prüfung in mehreren
  Endpunkten).
- **Lost Updates.** `plan_drafts` erlaubt genau einen Entwurf je Zelle; ein zweiter Planer
  überschreibt den Entwurf des ersten ohne Rückfrage. `publish` liest Entwürfe ohne Sperre.
- **Schema-Änderungen zur Laufzeit** (`ALTER TABLE` in `api/ical.php`, `pages/profil.php`,
  `api/live_status.php`, `api/changelog.php`).
- **Migrationen ohne Versionsführung** und entgegen der README nicht wiederholbar.
- **Globale Einstellungen** (`app_settings`) und feste Rollen-Enums – kein Mandantenbezug.
- **In-App-Backup** nicht konsistent, nicht authentifiziert verschlüsselt, Schlüssel liegt
  neben den Backups, alles auf demselben Server.

---

## B. Zielarchitektur Planquadro

### B.1 Leitlinien

1. **Eine Codebasis** für SaaS und Self-Hosting. Unterschiede nur über Konfiguration
   (`PQ_MODE=selfhosted|saas`).
2. **Evolution statt Rewrite.** Bestehende Seiten, URLs, Tabellen und Abläufe bleiben; neue
   Bausteine werden darunter geschoben (Request-Kernel, Services, API v1).
3. **Server entscheidet.** Jede Aktion wird serverseitig autorisiert (Recht + Team-Scope +
   Mandant). Die Oberfläche blendet nur aus.
4. **Nichts geht unbemerkt verloren.** Optimistic Concurrency auf Zellebene, Transaktionen
   mit Zeilensperren, lückenloses Änderungsprotokoll.
5. **Betreibbar ohne Cloud.** Alle Pflichtkomponenten laufen auf einem Linux-Server; externe
   Dienste (S3, FCM/APNs, OIDC) sind optional.

### B.2 Schichten

```
┌──────────────────────────────────────────────────────────────────────┐
│ Clients: Web (bestehende Seiten)  ·  PWA/Capacitor-App  ·  iCal       │
└───────────────┬───────────────────────────────┬──────────────────────┘
                │ Session-Cookie + CSRF         │ Bearer-Token (kurzlebig) + Refresh
┌───────────────▼───────────────────────────────▼──────────────────────┐
│ public/  Seiten + Legacy-API (api/*.php)   │  public/api/v1/ (REST)    │
├────────────────────────────────────────────┴─────────────────────────┤
│ app/Kernel  Bootstrap · TenantResolver · Auth · CSRF · RateLimit ·     │
│             Fehlerbehandlung · Audit · Request-ID                      │
├──────────────────────────────────────────────────────────────────────┤
│ app/Domain  PlanService (Zellen, Revisionen, Entwürfe, Veröffentlichung)│
│             PermissionService · TenantConfig · AbsenceService · …      │
├──────────────────────────────────────────────────────────────────────┤
│ app/Infra   PDO (tenant-gebunden) · EventBus (DB-Outbox) · Mail/Push · │
│             Storage (lokal/S3) · Backup-Hooks                          │
└───────────────┬──────────────────────────────────────────────────────┘
                │
     MariaDB/MySQL (InnoDB)   ·   optional Redis (Sessions, Rate-Limits, Pub/Sub)
```

Die bestehenden Dateien unter `public/` bleiben Einstiegspunkte. Sie rufen nach und nach
Funktionen aus `app/` auf, statt SQL selbst zu formulieren. Phase 2 beginnt damit für
den Kernpfad *Zelle speichern / veröffentlichen* (`app/lib/PlanCells.php`,
`app/lib/ChangeLog.php`).

**Request-Kernel (umgesetzt, `app/lib/Kernel.php`).** `includes/auth.php` ruft
`opsKernelBoot()` auf; damit gilt für alle Seiten und `api/*.php`:

- Request-ID je Request (`opsRequestId()`): Antwort-Header `X-Request-ID`, Präfix
  `[req <id>]` in jeder Log-Zeile (`opsLog()`, `opsLogThrowable()`). Eine vom Proxy gesetzte
  `X-Request-ID` wird übernommen, wenn sie nur `[A-Za-z0-9._:-]` (8–64 Zeichen) enthält.
- Fehlerformat `{"success":false,"error":"…","request_id":"…"}` (`opsJsonError()`,
  `opsApiFail()`); bestehende Felder (`success`, `error`, `msg`) und HTTP-Codes bleiben.
- Globaler Exception-/Fatal-Handler: technische Fehler → HTTP 500 mit generischer Meldung
  und Referenz, Details nur im Log; `display_errors` ist für Requests aus. Fachmeldungen
  (`InvalidArgumentException`, `OpsUserException`) → HTTP 400 mit Text.
- API v1 behält ihren eigenen Fehlerrahmen (`{"error":{"code","message"}}`) und bekommt
  nur die Request-ID.

### B.3 Multi-Tenancy (umgesetzt)

**Modell: eine Datenbank je Mandant** mit identischem Schema, dazu eine zentrale
**Plattform-Datenbank** (`tenants`, `tenant_domains`, `platform_admins`, `platform_audit`,
`backup_runs`). Umgesetzt in `app/lib/Tenancy.php`.

> Entscheidung geändert gegenüber dem ersten Entwurf (gemeinsames Schema mit `tenant_id`):
> Die bestehende Anwendung enthält mehrere hundert SQL-Abfragen in Seiten und APIs. Eine
> `tenant_id`-Spalte hätte jede davon berühren müssen – mit hohem Regressionsrisiko und
> der Gefahr, dass eine vergessene Bedingung Daten zwischen Mandanten öffnet. Mit einer
> Datenbank je Mandant ist die Isolation **strukturell**: `getDB()` verbindet ausschließlich
> mit der Datenbank des aufgelösten Mandanten; es gibt keinen Codepfad zu fremden Daten.
> Zusätzlich werden Backup, Restore, Export und Löschung eines einzelnen Mandanten trivial.
> Nachteil: Schema-Migrationen laufen je Mandant (`bin/migrate.php up --all-tenants`).

| | Self-Hosting (`PQ_MODE=selfhosted`, Standard) | SaaS (`PQ_MODE=saas`) |
|---|---|---|
| Mandanten | genau einer, Datenbank `PQ_DB_NAME` | beliebig viele, je eine Datenbank `planquadro_t_<slug>` |
| Auflösung | fest | Host → `tenant_domains`; nur für `/api/v1` auf dem Plattform-Host zusätzlich Header `X-PQ-Tenant` |
| Plattform-DB | nicht nötig | `PQ_PLATFORM_DB_NAME` |
| Verwaltung | – | `bin/tenant.php` (CLI) und `/platform/` (Web) |

Durchsetzung:
1. Mandant wird pro Request genau einmal bestimmt; unbekannt → 404, gesperrt → 503.
2. Sessions: eigener Cookie-Name je Mandant **und** Bindung an den Mandanten (eine fremde
   Session wird verworfen). API-Tokens enthalten den Mandanten und gelten nur dort.
3. Plattform-Administration hat eigene Konten, eigene Session und sieht Fachdaten nur
   als Zahlen.
4. Integrationstests (`tests/Integration/TenancyTest.php`): getrennte Zugangsdaten, Daten,
   Sessions, Tokens, gesperrte/unbekannte Mandanten, Header-Regel, Plattform-Zugang.

### B.4 TenantConfig (umgesetzt)

Zentrale Konfiguration je Mandant in `app/lib/TenantConfig.php`, gespeichert als JSON in
`app_settings('tenant_config')` der Mandanten-Datenbank. Verwaltung über die Seite
**Organisation** (Recht `manage_settings`).

| Bereich | Inhalt |
|---|---|
| `branding` | Name, Markenfarbe, Akzentfarbe, Logo (PNG/JPEG/WebP) – als CSS-Variablen mit automatischer Kontrastprüfung |
| `modules` | assistant, absences, approvals, vacation, deputation, annual, wots, ical, masterdata, mobile – deaktiviert = aus Navigation entfernt **und** per Direktaufruf/API gesperrt |
| `navigation` | Reihenfolge und ausgeblendete Einträge |
| `dashboard` | aktive Widgets |
| `planning` | gleichzeitiges Bearbeiten, teamübergreifende Planung, Anzeige „heute abwesend“ |
| `security.oidc` | SSO-Konfiguration (Issuer, Client, Claim, Passwort-Login erlaubt, Admin per SSO erlaubt) – änderbar **nur durch Administratoren** |

Bereits vorhandene Einstellungen (`company_name`, `approval_system_enabled`, `epa_cross_team`,
`allow_concurrent_edit`, `dashboard_show_absent`) bleiben die **einzige** Quelle ihrer
Werte; die TenantConfig liest und schreibt sie direkt. Mandantendaten wie Einsatzcodes,
Kunden, Standorte und Qualifikationen sind keine Konfiguration, sondern Stammdaten
(Seite **Stammdaten**, Migration `0004`).

### B.5 Berechtigungen (Ziel)

- **Rechte als Code-Konstanten** mit Beschreibung, Gruppe und Standardzuordnung
  (`app/Domain/Permissions.php`), inkl. der heute „verwaisten“ Rechte
  `manage_absences`, `use_planning_assistant`.
- **Rollen je Mandant** (`roles(tenant_id, key, label, …)`, `role_permissions(tenant_id, role, permission)`),
  Benutzer-Overrides bleiben.
- **Scopes** als zweite Dimension: `self`, `team` (transitiv + Vertretung), `all`. Ein Recht
  wird immer mit Scope geprüft: `can('plan.edit', $targetUserId)`.
- **Rollengebundene Prüfungen** (`canManageUsers`, `canManageConfig`, Wartung) werden in
  Rechte überführt, mit Migration, die exakt den heutigen Zustand abbildet.
- **Keine Privilegienausweitung:** Wer Benutzer/Rollen verwaltet, kann höchstens die Rechte
  vergeben, die er selbst besitzt; die Admin-Rolle kann nur von Admins vergeben werden.
- **Migrationssicherheit:** Vor und nach jeder Rechte-Migration wird die *effektive*
  Rechtematrix (Benutzer × Recht × Scope) exportiert und automatisch verglichen
  (siehe Migrationsplan, Abschnitt 4).

### B.6 Gleichzeitiges Arbeiten

| Baustein | Umsetzung |
|---|---|
| Versionsprüfung | Jede Zelle hat eine **Revision** (HMAC über den aktuellen Stand aus Veröffentlichung + Entwurf). Clients senden beim Speichern die Revision, die sie gesehen haben. |
| Konflikterkennung | `save_bulk` sperrt die betroffenen Zeilen (`SELECT … FOR UPDATE`) und vergleicht Revisionen. Abweichung → HTTP 409 mit Liste (Zelle, aktueller Stand, wer, wann); nichts wird geschrieben. Überschreiben nur nach ausdrücklicher Bestätigung, protokolliert. |
| Transaktionen | Alle Mehrschritt-Schreibvorgänge in Transaktionen; Deadlocks werden einmal automatisch wiederholt. `publish` sperrt die Entwürfe, die es anwendet. |
| Änderungsprotokoll | `plan_change_log`: Zelle, Aktion (`draft_set`, `draft_clear`, `publish`, `overwrite`, …), alter/neuer Stand, Benutzer, Zeitpunkt, Quelle. |
| Live-Aktualisierung | Umgesetzt: **Server-Sent Events** aus einer Ereignis-Tabelle (Outbox `live_events`, siehe unten) – `/api/events` für die Weboberfläche, `/api/v1/events` für die App; Fallback: bestehendes Polling (`live_status`) bzw. `GET sync` und Push. Funktioniert hinter jedem Reverse Proxy, ohne zusätzlichen Dienst, und mit beliebig vielen App-Instanzen, weil die Outbox in der Datenbank liegt. WebSockets sind nicht nötig, weil Schreibzugriffe ohnehin per HTTP laufen. |
| Sichtbarkeit | Eigene/fremde Entwürfe bleiben markiert; Veröffentlichungsvorschau zeigt, was wessen Änderung ist. |

#### Live-Ereignisse (SSE)

`app/lib/Events.php`, Migration `0007_events`.

- **Schreiben:** `save_bulk`, `save_remark`, `publish` (Monat/Jahr), Anträge (einreichen,
  genehmigen, ablehnen), Abwesenheitsmodul (erfassen, löschen), Planungsassistent
  (übernehmen, zurücknehmen) und Präsenz (`live_status`: Betreten, Verlassen, Zeilensperre)
  schreiben mit `pqEventEmit()` **in derselben Transaktion** eine Zeile in `live_events`:
  Typ (`cells_changed`, `published`, `request_changed`, `presence`), betrifft
  veröffentlichten Plan ja/nein, intern ja/nein, Monate, Zellen `[user_id, Datum]`, Auslöser.
  Keine Inhalte, keine Abwesenheitsarten, keine Bemerkungen. Rollt die Transaktion zurück,
  verschwindet auch das Ereignis. Fehler beim Schreiben (z. B. Migration fehlt) brechen den
  Vorgang nicht ab; Deadlocks werden weitergereicht (Wiederholung durch `opsTransaction`).
- **Lesen:** Der Endpunkt fragt die Outbox im Abstand `PQ_SSE_POLL_MS` ab ID `Last-Event-ID`
  ab und beendet die Verbindung nach `PQ_SSE_MAX_SECONDS`; der Client verbindet sich neu.
  Heartbeat-Kommentare halten Proxys offen; übersprungene (nicht sichtbare) Ereignisse
  rücken den Cursor per `id:`-Zeile ohne Daten vor. Lücken in der ID-Folge (noch nicht
  bestätigte Transaktionen) werden bis zu 3 s abgewartet, bevor sie übersprungen werden.
  Kennt der Server den Cursor nicht mehr (aufgeräumt/unbekannt), sendet er `reset`.
- **Vertraulichkeit:** `pqEventForViewer()` filtert je Empfänger: Zellen anderer nur im
  Team-Scope (`visibleUserIds`), Entwürfe und Präsenz nur für Planer (`plan_edit`), interne
  Entwürfe nur mit `plan_view_internal`, Anträge für Antragsteller und Genehmiger im Scope,
  App-Kanal nur veröffentlichte Stände. Inhalte lädt der Client über die bestehenden,
  rechtegeprüften Endpunkte (`live_status`/`cells`, `plan/my`, `plan/team`, `sync`).
- **Authentisierung:** Web per Session (Sperre wird vor dem Streamen mit
  `session_write_close()` freigegeben). App: EventSource kann keinen Authorization-Header
  senden, das Access-Token gehört nie in eine URL – deshalb `POST /api/v1/events/ticket`
  (Bearer) → Einmal-Ticket (256 Bit, nur als SHA-256 gespeichert, 30 s gültig, genau einmal
  einlösbar, gebunden an Benutzer, Token-Familie und Mandant). Clients, die Header setzen
  können, dürfen `GET events` auch mit Bearer-Header aufrufen.
- **Aufräumen:** gelegentlich beim Verbindungsaufbau; Ereignisse älter als
  `PQ_EVENTS_RETENTION_HOURS` (der neueste bleibt stehen), abgelaufene Tickets.

Isolationsstufe: Die Konfliktprüfung hängt nicht an REPEATABLE READ. Revisionen
werden innerhalb der Transaktion nach `SELECT … FOR UPDATE` gelesen (Sperrlesen
liefert immer den neuesten bestätigten Stand), deshalb funktioniert sie unter
REPEATABLE READ (MariaDB/MySQL-Standard) und READ COMMITTED gleichermaßen.

#### SSO-Sicherheit (OIDC)

- Konfiguration nur durch Administratoren; Administratorkonten melden sich standardmäßig
  nur per Passwort an (Notfallzugang), SSO für Admins ist eine ausdrückliche Option.
- `state`, `nonce` und PKCE liegen während des Logins in einem eigenen, per HMAC
  gesicherten Cookie (`SameSite=Lax`, Pfad `/oidc`, 10 Minuten). Das Sitzungs-Cookie
  bleibt `SameSite=Strict`; nach dem Rücksprung leitet eine HTML-Seite weiter, damit
  der Browser die neue Sitzung mitsendet.
- `PQ_OIDC_CLIENT_SECRET` gilt im SaaS-Modus nur zusammen mit
  `PQ_OIDC_ISSUER` und nur für genau diesen Issuer. Im Self-Hosting gilt es für
  jeden Issuer, sofern `PQ_OIDC_ISSUER` nicht gesetzt ist.
- Im SaaS-Modus werden Identity-Provider in internen Netzen (Loopback, private und
  Link-Local-Adressen, Cloud-Metadaten) abgelehnt; die geprüfte IP wird für die
  Verbindung festgehalten (kein DNS-Rebinding). Ausnahme für eigene Betreiber-IdPs:
  `PQ_OIDC_ALLOW_PRIVATE=1`.

### B.7 Betriebsmodelle

| | Self-Hosted | Cloud / SaaS |
|---|---|---|
| Auslieferung | Docker-Image `planquadro/app` (PHP-FPM + Nginx oder Apache) + `docker-compose.yml` mit MariaDB, optional Caddy für HTTPS | gleiches Image, horizontal skaliert hinter Load Balancer |
| Mandanten | genau einer (`PQ_MODE=selfhosted`) | viele, Auflösung über Host/Subdomain |
| Sessions | Datei oder DB (`PQ_SESSION_STORE`, umgesetzt) | Redis/DB (zustandslose App-Container) |
| Dateien | Volume `/data` | S3-kompatibler Speicher |
| Migrationen | `php bin/migrate.php up` beim Container-Start (mit Sperre) | eigener Job vor dem Rollout |
| Backups | `bin/backup` (siehe Roadmap Phase 4), lokal + extern | zentral, mandantengenaue Exporte |
| Ohne Cloud | vollständig (Push optional über Web-Push/VAPID oder ntfy) | – |

### B.8 Mobile Apps – Technologieentscheidung

Geprüft wurden PWA, Capacitor und React Native/Expo gegen den vorhandenen Stack
(PHP, serverseitiges HTML, Vanilla-JS, kein Build-Prozess, kein React).

| Kriterium | PWA | Capacitor | React Native / Expo |
|---|---|---|---|
| Passt zum Stack | ja (Web) | ja (Web-Code in nativer Hülle) | nein – zweiter Stack (React, Native-Komponenten) |
| App-Store-Präsenz (iOS/Android) | nein / eingeschränkt | ja | ja |
| Push iOS | nur installierte PWA, eingeschränkt | APNs/FCM nativ | nativ |
| Biometrie | WebAuthn/Passkeys | nativ (Face ID/Touch ID/Fingerprint) + Keychain/Keystore | nativ |
| Offline-Lesemodus | Service Worker + IndexedDB | wie PWA + verschlüsselter nativer Speicher | nativ |
| Code-Wiederverwendung mit Web | maximal | maximal | gering |
| Wartungsaufwand | niedrig | niedrig–mittel | hoch |

**Entscheidung: Capacitor auf Basis einer Mobile-Web-App, die zugleich als PWA ausgeliefert
wird** (umgesetzt in `mobile/`, siehe `mobile/README.md`). Ein Codepfad (TypeScript, ohne schweres Framework) erzeugt die PWA für Browser und
die iOS-/Android-Apps; native Plugins liefern Push, Biometrie und sicheren Tokenspeicher.
React Native würde einen zweiten UI-Stack einführen, ohne dass die Anforderungen (Lesen,
Abwesenheiten, Benachrichtigungen) das rechtfertigen. Die App spricht ausschließlich die
neue **API v1** (Token-Auth, mandantengebunden, dieselben Rechte- und Scope-Prüfungen wie
die Weboberfläche).

### B.9 Sicherheit (Querschnitt)

- Zentrale CSRF-Prüfung für alle zustandsändernden Requests (Header `X-CSRF-Token`, von
  einem globalen `fetch`-Wrapper gesetzt; Formulare mit verstecktem Feld).
- Session: `session_regenerate_id()` bei Login/Rechtewechsel, Leerlauf- und Absolut-Timeout,
  Bindung an Mandant; optional Redis-Sessions.
- Passwörter: `password_hash` (bcrypt/argon2id), konfigurierbare Richtlinie, keine
  Klartext-Einmalpasswörter in der Datenbank.
- Secrets nur aus Umgebung/Secret-Dateien (`*_FILE`-Variablen für Docker Secrets).
- Ausgabe-Escaping: `e()` serverseitig; im JS ein `esc()`, das auch Anführungszeichen
  maskiert; CSP ohne `unsafe-inline` als Ziel (Phase 5, wenn Inline-Handler ersetzt sind).
- Audit-Log für sicherheitsrelevante Aktionen; IP-Adressen gekürzt oder gehasht nach
  konfigurierbarer Frist; keine Gesundheitsdaten in Logs.
- Optional OIDC/SSO (Authorization Code + PKCE), Zuordnung von Gruppen zu Rollen je Mandant.
