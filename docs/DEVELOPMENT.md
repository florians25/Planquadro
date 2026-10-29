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
CREATE DATABASE vireo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'vireo'@'localhost' IDENTIFIED BY '…';
GRANT ALL ON vireo.* TO 'vireo'@'localhost';
```

```bash
export VIREO_DB_HOST=127.0.0.1 VIREO_DB_NAME=vireo VIREO_DB_USER=vireo VIREO_DB_PASS=…
php bin/migrate.php up          # Basisschema + alle Migrationen
php public/install.php          # ersten Administrator anlegen (Passwort wird angezeigt)
php -S 127.0.0.1:8080 -t public tests/router.php
```

`tests/router.php` bildet die Rewrite-Regeln aus `public/.htaccess` nach, sodass der
eingebaute PHP-Server dieselben URLs wie Apache bedient (`/dashboard`, `/api/save_bulk` …).

### Umgebungsvariablen

| Variable | Zweck |
|---|---|
| `VIREO_DB_HOST`, `_PORT`, `_NAME`, `_USER`, `_PASS` | Datenbank (Alternative: `app/config/secrets.php`) |
| `VIREO_APP_KEY` | Schlüssel für Zell-Revisionen (≥ 32 Zeichen). Ohne Angabe wird einmalig einer in `app_settings` erzeugt. |
| `VIREO_TRUSTED_PROXIES` | Reverse-Proxy-Adressen/CIDR, deren `X-Forwarded-For` ausgewertet wird |
| `VIREO_FORCE_SECURE_COOKIES` | `1` = Session-Cookie immer `Secure` (hinter TLS-terminierendem Proxy) |
| `VIREO_ALLOW_INSTALL` | `1` = `install.php` im Browser freigeben (nur Ersteinrichtung) |

Vollständige Liste (Mandanten, Backup, Alarme, Docker): [CONFIGURATION.md](CONFIGURATION.md).
Lokal ohne Backup-Schlüssel zeigt das Dashboard Administratoren einen Backup-Hinweis;
abschalten mit `VIREO_BACKUP_BANNER=0`.

## Tests

```bash
# Unit-Tests (ohne Datenbank)
vendor/bin/phpunit --testsuite unit

# Integrationstests: legen die Datenbank vireo_test bei jedem Lauf NEU an
VIREO_TEST_DB_USER=root VIREO_TEST_DB_PASS=… vendor/bin/phpunit

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
| `BackupTest` | Backup → Restore-Round-Trip (PHP- und nativer Dumper), Manifest, Schutz nicht leerer Ziele, `--replace`, Restore-Test, Status, Alarme, Web-API, Dashboard-Hinweis, SaaS |
| `HealthTest` | `/api/healthz`, `/api/readyz` (auch SaaS, mandantenbezogen) |
| `Unit/BackupCryptoTest`, `Unit/BackupFormatTest` | Verschlüsselung inkl. Manipulation/Abschneiden, GFS-Aufbewahrung, tar, Zeilenzähler, S3-Signatur |

Teilen sich mehrere Checkouts eine Datenbankinstanz, je Checkout einen eigenen
`VIREO_TEST_DB_NAME` (z. B. `vireo_test_feature`) verwenden.

### Deploymenttest (Docker)

```bash
docker build -t vireo/app:ci .
cp .env.example .env    # VIREO_IMAGE=vireo/app:ci, VIREO_FORCE_SECURE_COOKIES=0
sh docker/init-secrets.sh
sh tests/deploy/compose-smoke.sh     # baut den Stack am Ende wieder ab (KEEP=1 behält ihn)
```

Neue Fehlerbehebungen bekommen einen Test, der **vor** dem Fix fehlschlägt.

## Migrationen

- Neue Schemaänderungen nur als Datei `migrations/vireo/NNNN_beschreibung.sql`.
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
