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
CREATE DATABASE opspilot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'opspilot'@'localhost' IDENTIFIED BY '…';
GRANT ALL ON opspilot.* TO 'opspilot'@'localhost';
```

```bash
export OPSPILOT_DB_HOST=127.0.0.1 OPSPILOT_DB_NAME=opspilot OPSPILOT_DB_USER=opspilot OPSPILOT_DB_PASS=…
php bin/migrate.php up          # Basisschema + alle Migrationen
php public/install.php          # ersten Administrator anlegen (Passwort wird angezeigt)
php -S 127.0.0.1:8080 -t public tests/router.php
```

`tests/router.php` bildet die Rewrite-Regeln aus `public/.htaccess` nach, sodass der
eingebaute PHP-Server dieselben URLs wie Apache bedient (`/dashboard`, `/api/save_bulk` …).

### Umgebungsvariablen

| Variable | Zweck |
|---|---|
| `OPSPILOT_DB_HOST`, `_PORT`, `_NAME`, `_USER`, `_PASS` | Datenbank (Alternative: `app/config/secrets.php`) |
| `OPSPILOT_APP_KEY` | Schlüssel für Zell-Revisionen (≥ 32 Zeichen). Ohne Angabe wird einmalig einer in `app_settings` erzeugt. |
| `OPSPILOT_TRUSTED_PROXIES` | Reverse-Proxy-Adressen/CIDR, deren `X-Forwarded-For` ausgewertet wird |
| `OPSPILOT_FORCE_SECURE_COOKIES` | `1` = Session-Cookie immer `Secure` (hinter TLS-terminierendem Proxy) |
| `OPSPILOT_ALLOW_INSTALL` | `1` = `install.php` im Browser freigeben (nur Ersteinrichtung) |

## Tests

```bash
# Unit-Tests (ohne Datenbank)
vendor/bin/phpunit --testsuite unit

# Integrationstests: legen die Datenbank opspilot_test bei jedem Lauf NEU an
OPSPILOT_TEST_DB_USER=root OPSPILOT_TEST_DB_PASS=… vendor/bin/phpunit

# JS
node --test tests/js/escape.test.mjs
```

Integrationstests starten den eingebauten PHP-Server mit 4 Workern, melden sich als
Testbenutzer jeder Rolle an (`tests/Support/TestEnv.php`) und rufen Seiten und APIs über
HTTP auf – inklusive CSRF-Token, Sessions und paralleler Requests. Der Name der
Testdatenbank muss „test“ enthalten (Schutz vor versehentlichem Löschen).

| Datei | Inhalt |
|---|---|
| `SmokeTest` | Login je Rolle, Zugang zu Seiten je Rolle |
| `SecurityTest` | Regressionstests zu den Sicherheitsbefunden (S-xx) |
| `DataIntegrityTest` | Urlaubs-/Krankheitssperre, Bereichslöschung, Rollen-Seite |
| `ConcurrencyTest` | Revisionen, Konflikte, parallele Schreibzugriffe, Veröffentlichung, Protokoll |

Neue Fehlerbehebungen bekommen einen Test, der **vor** dem Fix fehlschlägt.

## Migrationen

- Neue Schemaänderungen nur als Datei `migrations/opspilot/NNNN_beschreibung.sql`.
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

Docker-Images und Releases folgen in Phase 4; ein Push in eine Registry erfolgt erst nach
ausdrücklicher Freigabe.
