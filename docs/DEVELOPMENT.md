# Entwicklung, Tests und CI

## Voraussetzungen

- PHP ≥ 8.1 mit `pdo_mysql`, `mbstring`, `openssl`, `curl`, `calendar`
- Composer 2
- MariaDB ≥ 10.3 oder MySQL ≥ 8.0
- Node.js ≥ 20 (nur für die JS-Tests)

## Lokale Umgebung

```bash
composer install
```

Datenbank anlegen (Kollation wie in Produktion):

```sql
CREATE DATABASE planquadro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'planquadro'@'localhost' IDENTIFIED BY '…';
GRANT ALL ON planquadro.* TO 'planquadro'@'localhost';
```

```bash
export PQ_DB_HOST=127.0.0.1 PQ_DB_NAME=planquadro PQ_DB_USER=planquadro PQ_DB_PASS=…
php bin/migrate.php up          # Basisschema + alle Migrationen
php public/install.php          # ersten Administrator anlegen (Passwort wird angezeigt)
php -S 127.0.0.1:8080 -t public tests/router.php
```

`tests/router.php` bildet die Rewrite-Regeln aus `public/.htaccess` nach, sodass der
eingebaute PHP-Server dieselben URLs wie Apache bedient (`/dashboard`, `/api/save_bulk` …).

### Umgebungsvariablen

| Variable | Zweck |
|---|---|
| `PQ_DB_HOST`, `_PORT`, `_NAME`, `_USER`, `_PASS` | Datenbank (Alternative: `app/config/secrets.php`) |
| `PQ_APP_KEY` | Schlüssel für Zell-Revisionen (≥ 32 Zeichen). Ohne Angabe wird einmalig einer in `app_settings` erzeugt. |
| `PQ_TRUSTED_PROXIES` | Reverse-Proxy-Adressen/CIDR, deren `X-Forwarded-For` ausgewertet wird |
| `PQ_FORCE_SECURE_COOKIES` | `1` = Session-Cookie immer `Secure` (hinter TLS-terminierendem Proxy) |
| `PQ_ALLOW_INSTALL` | `1` = `install.php` im Browser freigeben (nur Ersteinrichtung) |

Vollständige Liste (Mandanten, Backup, Alarme, Docker): [CONFIGURATION.md](CONFIGURATION.md).
Lokal ohne Backup-Schlüssel zeigt das Dashboard Administratoren einen Backup-Hinweis;
abschalten mit `PQ_BACKUP_BANNER=0`.

## Tests

```bash
# Unit-Tests (ohne Datenbank)
vendor/bin/phpunit --testsuite unit

# Integrationstests: legen die Datenbank planquadro_test bei jedem Lauf NEU an
PQ_TEST_DB_USER=root PQ_TEST_DB_PASS=… vendor/bin/phpunit

# JS
node --test tests/js/*.test.mjs
```

Integrationstests starten den eingebauten PHP-Server mit 4 Workern, melden sich als
Testbenutzer jeder Rolle an (`tests/Support/TestEnv.php`) und rufen Seiten und APIs über
HTTP auf – inklusive CSRF-Token, Sessions und paralleler Requests. Der Name der
Testdatenbank muss „test“ enthalten (Schutz vor versehentlichem Löschen).

| Datei | Inhalt |
|---|---|
| `SmokeTest` | Login je Rolle, Zugang zu Seiten je Rolle |
| `SecurityTest` | Regressionstests zu den Sicherheitsbefunden (S-xx) |
| `KernelTest` (Integration + Unit) | Request-ID, Fehlerformat, provozierte DB-Fehler ohne SQL-Text an den Client (S-20), Fachmeldungen |
| `DataIntegrityTest` | Urlaubs-/Krankheitssperre, Bereichslöschung, Rollen-Seite |
| `ConcurrencyTest` | Revisionen, Konflikte, parallele Schreibzugriffe, Veröffentlichung, Protokoll |
| `BackupTest` | Backup → Restore-Round-Trip (PHP- und nativer Dumper), Manifest, Schutz nicht leerer Ziele, `--replace`, Restore-Test, Status, Alarme, Web-API, Dashboard-Hinweis, SaaS |
| `HealthTest` | `/api/healthz`, `/api/readyz` (auch SaaS, mandantenbezogen) |
| `Unit/BackupCryptoTest`, `Unit/BackupFormatTest` | Verschlüsselung inkl. Manipulation/Abschneiden, GFS-Aufbewahrung, tar, Zeilenzähler, S3-Signatur |

| `CspTest`, `Unit/NoInlineHandlersTest` | CSP-Header mit Nonce auf allen Seiten je Rolle; keine Inline-Handler, keine Skripte ohne Nonce, jede statische `data-action` definiert (S-21, S-22) |

Browser-Rundgang (Chromium/Playwright, verändert Daten – nur Wegwerf-DB mit
`php bin/demo.php`): `BASE=http://127.0.0.1:8080/ PQ_DEMO_PW=… node tests/browser/csp-tour.cjs`
besucht alle Seiten als admin, Manager, Teamleiter und Mitarbeiter, sammelt
`securitypolicyviolation`-Ereignisse und JS-Fehler und klickt die wichtigsten Abläufe.

Teilen sich mehrere Checkouts eine Datenbankinstanz, je Checkout einen eigenen
`PQ_TEST_DB_NAME` (z. B. `planquadro_test_feature`) verwenden.

### Deploymenttest (Docker)

```bash
docker build -t planquadro/app:ci .
cp .env.example .env    # PQ_IMAGE=planquadro/app:ci, PQ_FORCE_SECURE_COOKIES=0
sh docker/init-secrets.sh
sh tests/deploy/compose-smoke.sh     # baut den Stack am Ende wieder ab (KEEP=1 behält ihn)
```

Neue Fehlerbehebungen bekommen einen Test, der **vor** dem Fix fehlschlägt.

## Oberfläche: keine Inline-Handler, keine Inline-Skripte ohne Nonce

Die Content-Security-Policy (`opsSendCsp()` in `app/lib/Security.php`) erlaubt Skripte nur
aus eigenen Dateien oder mit der Nonce des Requests. Daher gilt:

- **Keine** `onclick=`, `onchange=`, `oninput=` … – weder in PHP-Templates noch in per JS
  erzeugtem HTML. Der Test `tests/Unit/NoInlineHandlersTest.php` schlägt sonst fehl.
- Stattdessen Ereignis-Delegation über `public/assets/js/actions.js` (im `<head>`
  geladen, vor allen anderen Skripten):

  | Attribut | Wirkung |
  |---|---|
  | `data-action="name"` | bei `click` |
  | `data-action-change="name"`, `data-action-input="name"` | bei `change` / `input` |
  | `data-args='[1,"text"]'` | Argumente (JSON-Array); Platzhalter `"$this"`, `"$event"`, `"$value"`, `"$checked"` |
  | `data-prevent` | `preventDefault()` vor dem Aufruf |
  | `data-return` | Rückgabe `false` verhindert die Standardaktion (wie `return f()`) |

  `name` ist eine globale Funktion (`saveSettings`, auch Pfad `ABW.save`) oder eine mit
  `pqAction('name', function (el, ev, …args) { … })` registrierte Aktion – für Einzeiler,
  die früher direkt im Attribut standen. Native Browserfunktionen sind nicht aufrufbar;
  dafür gibt es `print`, `reload`, `select`, `toggleClass`, `setValue`. Mehrere Aktionen
  auf dem Weg nach oben laufen wie beim Bubbling (innen zuerst, `stopPropagation()` beendet).
- Werte aus PHP immer über `opsActionAttr('name', [$id, $text])` ausgeben (JSON, HTML-escaped,
  IDs vorher `(int)`); Platzhalter dort mit `opsActionToken('this')`. In JS-Markup
  `pqActAttr('name', [id, text])` verwenden; rein numerische Werte dürfen direkt
  (`data-args="['+id+']"`) stehen.
- Inline-`<script>`-Blöcke tragen `nonce="<?php echo opsCspNonce(); ?>"`; Event-Handler als
  Eigenschaft (`el.onclick = …`) und `addEventListener` sind erlaubt, `javascript:`-URLs,
  `eval`/`new Function` und String-`setTimeout` nicht.
- Inline-`style`-Attribute bleiben erlaubt.
- `OPS_CSP_LEGACY_PAGES` (gelockerte `script-src` für Seiten im Umbau) ist leer; die Liste
  muss mit der Ausnahmeliste im Test übereinstimmen.
- JSON-Datenblöcke (`<script type="application/json">`) tragen ebenfalls die Nonce
  (Beispiel: Planungsassistent).

## Migrationen

- Neue Schemaänderungen nur als Datei `migrations/app/NNNN_beschreibung.sql`.
- Additiv (expand → migrate → contract), mehrfach ausführbar, MySQL- **und**
  MariaDB-kompatibel (kein `ADD COLUMN IF NOT EXISTS`; stattdessen `information_schema`
  + `PREPARE`, siehe `0001_runtime_ddl.sql`).
- Kein `ALTER`/`CREATE TABLE` in Request-Code (die CI prüft das).

## Branches und Commits

| Branch | Zweck |
|---|---|
| `main` | geschützt, nur über Pull Requests, immer releasefähig |
| `develop` | Integrationsstand der nächsten Version |
| `feature/<thema>`, `fix/<thema>` | einzelne Änderungen, PR gegen `develop` |
| `release/<version>` | Stabilisierung; Tag `vX.Y.Z` erzeugt Release-Artefakte |

Commits sind klein und erklären das *Warum*; Befund-IDs aus der Roadmap (`S-02`, `D-01` …)
werden genannt.

## CI (GitHub Actions)

`.github/workflows/ci.yml` – bei jedem Push und Pull Request:

1. **Lint:** PHP-Syntax aller Dateien, JS-Syntax, Verbot von Laufzeit-DDL.
2. **Tests:** PHPUnit gegen MariaDB 10.11 (PHP 8.1 und 8.3) und MySQL 8.0 (PHP 8.3), JS-Tests.
3. **Secret-Scan:** gitleaks über die gesamte Historie; Backups, Schlüssel und
   `secrets.php` dürfen nicht eingecheckt sein.

`.github/workflows/deploy-test.yml` – baut das Image, startet Compose, prüft Installation,
Login mit Passwortwechsel, Backup, Restore-Test, Restore und das Caddy-Profil
(`tests/deploy/compose-smoke.sh`). Veröffentlicht nichts.

`.github/workflows/release.yml` – nur bei einem gepushten Tag `v*` oder manuellem Start:
Multi-Arch-Image (amd64/arm64) nach GHCR, mit SBOM, Provenienz und cosign-Signatur.
Branch-Pushes veröffentlichen nie.
