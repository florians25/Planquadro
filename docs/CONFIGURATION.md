# Konfiguration

Planquadro wird ausschließlich über **Umgebungsvariablen** konfiguriert (Docker/Compose,
systemd `EnvironmentFile`, `SetEnv` im Apache-VirtualHost oder das Hosting-Panel).
Für Geheimnisse gibt es zu jeder mit „✱“ markierten Variable die Variante `<NAME>_FILE`:
Sie enthält den Pfad zu einer Datei, deren Inhalt (ohne abschließenden Zeilenumbruch)
als Wert gilt – geeignet für Docker-Secrets (`/run/secrets/…`).

Ohne Umgebungsvariablen liest Planquadro die Datenbank-Zugangsdaten aus
`app/config/secrets.php` (Vorlage `secrets.sample.php`) und zuletzt aus den
Legacy-Variablen `CCKOE_DB_*` der Vorversion.

In Docker Compose stehen die Werte in `.env` (Vorlage: [`.env.example`](../.env.example)),
Secrets als Dateien in `./secrets/` (erzeugt von `sh docker/init-secrets.sh`).

## Datenbank

| Variable | Standard | Bedeutung |
|---|---|---|
| `PQ_DB_HOST` | `localhost` | Host der Datenbank (Compose: `db`) |
| `PQ_DB_PORT` | `3306` | Port |
| `PQ_DB_SSL` | `0` | `1` = Backup-/Restore-Programme (`mariadb-dump`, `mariadb`) nutzen ihre TLS-Voreinstellung. Standard: ohne TLS wie die Anwendungsverbindung – MariaDB-Clients ab 11.4 würden sonst TLS erzwingen und an Servern ohne TLS scheitern |
| `PQ_DB_NAME` | `planquadro` | Datenbank (Self-Hosting); SaaS: Fallback, die Mandanten-DB kommt aus `tenants.db_name` |
| `PQ_DB_USER` | – | Benutzer |
| `PQ_DB_PASS` ✱ | – | Passwort (Compose: `PQ_DB_PASS_FILE=/run/secrets/db_password`) |

Der Benutzer braucht alle Rechte auf seiner Datenbank. Für Restore, Restore-Test und den
SaaS-Betrieb zusätzlich das Recht, Datenbanken mit dem Präfix `planquadro_` anzulegen
(`GRANT ALL ON \`planquadro\_%\`.* TO …`; in Compose automatisch über `docker/db-init/`).

## Betrieb und Mandanten

| Variable | Standard | Bedeutung |
|---|---|---|
| `PQ_MODE` | `selfhosted` | `selfhosted` = genau ein Mandant; `saas` = viele Mandanten, Auflösung per Hostname |
| `PQ_PLATFORM_DB_NAME` | `planquadro_platform` | SaaS: Plattform-Datenbank (Mandanten, Domains, `backup_runs`) |
| `PQ_PLATFORM_DB_HOST`, `_PORT`, `_USER` | wie `PQ_DB_*` | SaaS: abweichender Server/Benutzer der Plattform-DB |
| `PQ_PLATFORM_DB_PASS` ✱ | wie `PQ_DB_PASS` | SaaS: Passwort der Plattform-DB |
| `PQ_PLATFORM_HOST` | – | SaaS: zentraler API-Host; dort wählt der Header `X-PQ-Tenant` den Mandanten (nur `/api/v1`) |
| `PQ_TENANT_DB_PREFIX` | `planquadro_t_` | SaaS: Präfix neuer Mandanten-Datenbanken (`bin/tenant.php create`) |
| `PQ_TENANT` | – | nur CLI im SaaS-Modus: Mandant für `bin/migrate.php` u. a. (alternativ `--tenant=`) |
| `PQ_STORAGE_DIR` | `app/storage` (Docker: `/data/storage`) | Dateiablage; je Mandant ein Unterordner `<slug>` (Self-Hosting: `default`), Backup-Status unter `_backup/` |
| `PQ_TIMEZONE` | `Europe/Berlin` (Image) | Zeitzone von PHP im Container (`date.timezone`); außerhalb von Docker gilt `php.ini` |
| `TZ` | `Europe/Berlin` (Image) | Zeitzone des Betriebssystems im Container |

## Sicherheit und Web

| Variable | Standard | Bedeutung |
|---|---|---|
| `PQ_APP_KEY` ✱ | einmalig in `app_settings` erzeugt | Schlüssel für Zell-Revisionen (≥ 32 Zeichen). Im Container liest der Entrypoint `PQ_APP_KEY_FILE`. |
| `PQ_TRUSTED_PROXIES` | – (Compose: `172.31.250.10`) | Kommagetrennte IPs/CIDR vertrauenswürdiger Reverse Proxys. Nur von dort werden `X-Forwarded-For` (Client-IP, Login-Sperren) und – im Container – `X-Forwarded-Proto: https` ausgewertet. |
| `PQ_FORCE_SECURE_COOKIES` | `0` | `1` = Session-Cookie immer `Secure` (hinter TLS-Proxy empfohlen). **Nicht** setzen, wenn ohne HTTPS getestet wird – sonst funktioniert der Login nicht. |
| `PQ_BASE_URL` | aus der Anfrage | Öffentliche Adresse, z. B. `https://plan.example.de` (OIDC-Redirect-URI, Absender in Alarmen) |
| `PQ_ALLOW_INSTALL` | – | `1` = `install.php` im Browser freigeben (nur für die Ersteinrichtung ohne Shell; danach entfernen) |
| `PQ_OIDC_CLIENT_SECRET` ✱ | – | Client-Secret für OIDC/SSO statt Speicherung in der TenantConfig |
| `PQ_OIDC_ALLOW_HTTP` | – | `1` = Identity-Provider auch ohne HTTPS (nur Tests) |

## Container-Start (Entrypoint)

| Variable | Standard | Bedeutung |
|---|---|---|
| `PQ_RUN_MIGRATIONS` | `1` für `apache2-foreground`, sonst `0` | Migrationen beim Start (Sperre über `GET_LOCK`, mehrere Container gleichzeitig sind sicher) |
| `PQ_WAIT_FOR_DB` | `1` | vor dem Start auf die Datenbank warten |
| `PQ_DB_WAIT_SECONDS` | `120` | maximale Wartezeit |
| `PQ_MIGRATE_LOCK_SECONDS` | `600` | maximale Wartezeit auf die Migrationssperre |
| `PQ_AUTO_BASELINE` | – | Nur für die Übernahme einer Einsatzplanung ≤ v6.9.0 ohne Versionstabelle: z. B. `v6_9_0`. Ohne diese Variable verweigert der Container den Start (die Alt-Migrationen sind nicht wiederholbar). Siehe [MIGRATION_PLAN.md](MIGRATION_PLAN.md). |
| `APACHE_LISTEN_PORT` | `8080` | Port von Apache im Container |

## Backup ([BACKUP.md](BACKUP.md))

| Variable | Standard | Bedeutung |
|---|---|---|
| `PQ_BACKUP_PUBLIC_KEY` ✱ | – | Öffentlicher Schlüssel (`planquadro-backup-pk-v1:…`, `php bin/backup.php keygen`). Ohne ihn werden **keine** Backups erstellt. |
| `PQ_BACKUP_PRIVATE_KEY` ✱ | – | Privater Schlüssel – nur für `verify`, `restore`, `restore-test`, `decrypt`. Gehört nicht auf den Produktivserver (Ausnahme: bewusst gewählter automatischer Restore-Test). |
| `PQ_BACKUP_DIR` | `/data/backups`, ohne `/data`: `app/backups` | lokales Ziel; je Bereich ein Unterordner |
| `PQ_BACKUP_TMP_DIR` | System-Temp | Arbeitsordner für Dump/Entpacken (Platz für eine Klartext-Kopie der Datenbank nötig) |
| `PQ_BACKUP_DUMPER` | `auto` | `auto` = `mariadb-dump`/`mysqldump`, falls vorhanden, sonst PHP; `php` = immer PHP-Dumper und -Loader; `native` = Client-Programme erzwingen |
| `PQ_BACKUP_KEEP_DAILY` | `7` | Aufbewahrung: Anzahl Tage mit je einem Backup |
| `PQ_BACKUP_KEEP_WEEKLY` | `4` | … Wochen |
| `PQ_BACKUP_KEEP_MONTHLY` | `12` | … Monate |
| `PQ_BACKUP_KEEP_LAST` | `3` | die jüngsten n Archive immer behalten |
| `PQ_BACKUP_KEEP_PRERESTORE_DAYS` | `30` | Sicherheits-Backups vor einem Restore mindestens so lange behalten |
| `PQ_BACKUP_MAX_AGE_HOURS` | `26` | ab diesem Alter gilt das letzte erfolgreiche Backup als veraltet (Alarm, Dashboard-Hinweis) |
| `PQ_BACKUP_TEMP_DB_PREFIX` | `planquadro_rt_` | Präfix für Staging-/Wegwerf-Datenbanken bei Restore und Restore-Test |
| `PQ_BACKUP_BANNER` | `1` | `0` = kein Backup-Hinweis auf dem Dashboard |
| `PQ_BACKUP_ENABLED` | `1` | `0` = Scheduler erstellt keine Backups (Testsysteme) |
| `PQ_BACKUP_S3_ENDPOINT` | – | S3-kompatibler Endpunkt, z. B. `https://s3.eu-central-1.amazonaws.com`, `https://fsn1.your-objectstorage.com` |
| `PQ_BACKUP_S3_BUCKET` | – | Bucket (beide Variablen gesetzt = Ziel aktiv) |
| `PQ_BACKUP_S3_REGION` | `us-east-1` | Region für die Signatur |
| `PQ_BACKUP_S3_PREFIX` | `planquadro` | Pfadpräfix im Bucket |
| `PQ_BACKUP_S3_ACCESS_KEY` ✱ | – | Zugangsschlüssel |
| `PQ_BACKUP_S3_SECRET_KEY` ✱ | – | geheimer Schlüssel |
| `PQ_BACKUP_S3_PATH_STYLE` | `auto` | `1` = `https://host/bucket/key` (MinIO, Hetzner), `0` = `https://bucket.host/key`; `auto` = Path-Style außer bei amazonaws.com |
| `PQ_BACKUP_RCLONE_REMOTE` | – | rclone-Ziel `name:pfad` (SFTP, WebDAV, …); Konfiguration über `RCLONE_CONFIG` bzw. `RCLONE_CONFIG_<NAME>_*` |
| `PQ_BACKUP_RCLONE_BIN` | `rclone` | Pfad zum rclone-Programm |
| `RCLONE_CONFIG` | – | Pfad der rclone-Konfiguration (z. B. `/data/rclone.conf`) |

### Scheduler (Compose-Dienst `backup`)

| Variable | Standard | Bedeutung |
|---|---|---|
| `PQ_BACKUP_TIME` | `02:30` | tägliches Backup (Ortszeit, `PQ_TIMEZONE`); verpasste Läufe werden nach dem Start nachgeholt |
| `PQ_RESTORE_TEST_DAY` | `7` | Wochentag des Restore-Tests (1 = Montag … 7 = Sonntag) |
| `PQ_RESTORE_TEST_TIME` | `04:30` | Uhrzeit des Restore-Tests (nur mit privatem Schlüssel) |
| `PQ_SCHEDULER_INTERVAL` | `60` | Prüfintervall in Sekunden |

### Alarme

| Variable | Standard | Bedeutung |
|---|---|---|
| `PQ_ALERT_WEBHOOK` | – | URL; JSON-POST mit `event`, `subject`, `message`, `text`, `details` (kompatibel mit Slack/Mattermost-Incoming-Webhooks über `text`) |
| `PQ_ALERT_EMAIL` | – | Empfänger; Versand über PHP `mail()` (im Container nur mit konfiguriertem `sendmail_path`, sonst Webhook nutzen) |
| `PQ_ALERT_FROM` | `planquadro@<hostname>` | Absender |
| `PQ_ALERT_REPEAT_HOURS` | `24` | gleichen Alarm frühestens nach so vielen Stunden wiederholen |

Alarm-Anlässe: fehlgeschlagenes Backup, fehlgeschlagenes externes Ziel, fehlgeschlagener
Restore-Test, letztes erfolgreiches Backup älter als `PQ_BACKUP_MAX_AGE_HOURS`.

## Nur Docker Compose (`.env`)

| Variable | Standard | Bedeutung |
|---|---|---|
| `PQ_IMAGE` | `planquadro/app:latest` | Image der Dienste `app` und `backup` |
| `PQ_PHP_BASE` | `docker.io/library/php:8.3-apache` | Basis-Image beim lokalen Bauen (Spiegel bei Docker-Hub-Limits: `mirror.gcr.io/library/php:8.3-apache`) |
| `PQ_DB_IMAGE` | `docker.io/library/mariadb:10.11` | Datenbank-Image |
| `PQ_CADDY_IMAGE` | `docker.io/library/caddy:2` | Proxy-Image |
| `PQ_HTTP_BIND` | `127.0.0.1` | Adresse, an der der App-Port veröffentlicht wird |
| `PQ_HTTP_PORT` | `8080` | Host-Port der App |
| `PQ_DOMAIN` | `localhost` | Hostname(s) für Caddy, kommagetrennt |
| `PQ_SUBNET` | `172.31.250.0/24` | internes Netz |
| `PQ_PROXY_IP` | `172.31.250.10` | feste Adresse des Caddy-Containers (= Standard von `PQ_TRUSTED_PROXIES`) |
| `PQ_DB_BUFFER_POOL` | `256M` | InnoDB-Pufferspeicher |

## Nur Tests

`PQ_TEST_DB_HOST`, `_PORT`, `_USER`, `_PASS`, `_NAME` – siehe
[DEVELOPMENT.md](DEVELOPMENT.md). Intern: `PQ_CSRF_EXEMPT` (Konstante, keine
Umgebungsvariable).
