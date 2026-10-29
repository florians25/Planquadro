# Konfiguration

OpsPilot wird ausschließlich über **Umgebungsvariablen** konfiguriert (Docker/Compose,
systemd `EnvironmentFile`, `SetEnv` im Apache-VirtualHost oder das Hosting-Panel).
Für Geheimnisse gibt es zu jeder mit „✱“ markierten Variable die Variante `<NAME>_FILE`:
Sie enthält den Pfad zu einer Datei, deren Inhalt (ohne abschließenden Zeilenumbruch)
als Wert gilt – geeignet für Docker-Secrets (`/run/secrets/…`).

Ohne Umgebungsvariablen liest OpsPilot die Datenbank-Zugangsdaten aus
`app/config/secrets.php` (Vorlage `secrets.sample.php`) und zuletzt aus den
Legacy-Variablen `CCKOE_DB_*` der Vorversion.

In Docker Compose stehen die Werte in `.env` (Vorlage: [`.env.example`](../.env.example)),
Secrets als Dateien in `./secrets/` (erzeugt von `sh docker/init-secrets.sh`).

## Datenbank

| Variable | Standard | Bedeutung |
|---|---|---|
| `OPSPILOT_DB_HOST` | `localhost` | Host der Datenbank (Compose: `db`) |
| `OPSPILOT_DB_PORT` | `3306` | Port |
| `OPSPILOT_DB_NAME` | `opspilot` | Datenbank (Self-Hosting); SaaS: Fallback, die Mandanten-DB kommt aus `tenants.db_name` |
| `OPSPILOT_DB_USER` | – | Benutzer |
| `OPSPILOT_DB_PASS` ✱ | – | Passwort (Compose: `OPSPILOT_DB_PASS_FILE=/run/secrets/db_password`) |

Der Benutzer braucht alle Rechte auf seiner Datenbank. Für Restore, Restore-Test und den
SaaS-Betrieb zusätzlich das Recht, Datenbanken mit dem Präfix `opspilot_` anzulegen
(`GRANT ALL ON \`opspilot\_%\`.* TO …`; in Compose automatisch über `docker/db-init/`).

## Betrieb und Mandanten

| Variable | Standard | Bedeutung |
|---|---|---|
| `OPSPILOT_MODE` | `selfhosted` | `selfhosted` = genau ein Mandant; `saas` = viele Mandanten, Auflösung per Hostname |
| `OPSPILOT_PLATFORM_DB_NAME` | `opspilot_platform` | SaaS: Plattform-Datenbank (Mandanten, Domains, `backup_runs`) |
| `OPSPILOT_PLATFORM_DB_HOST`, `_PORT`, `_USER` | wie `OPSPILOT_DB_*` | SaaS: abweichender Server/Benutzer der Plattform-DB |
| `OPSPILOT_PLATFORM_DB_PASS` ✱ | wie `OPSPILOT_DB_PASS` | SaaS: Passwort der Plattform-DB |
| `OPSPILOT_PLATFORM_HOST` | – | SaaS: zentraler API-Host; dort wählt der Header `X-OpsPilot-Tenant` den Mandanten (nur `/api/v1`) |
| `OPSPILOT_TENANT_DB_PREFIX` | `opspilot_t_` | SaaS: Präfix neuer Mandanten-Datenbanken (`bin/tenant.php create`) |
| `OPSPILOT_TENANT` | – | nur CLI im SaaS-Modus: Mandant für `bin/migrate.php` u. a. (alternativ `--tenant=`) |
| `OPSPILOT_STORAGE_DIR` | `app/storage` (Docker: `/data/storage`) | Dateiablage; je Mandant ein Unterordner `<slug>` (Self-Hosting: `default`), Backup-Status unter `_backup/` |
| `OPSPILOT_TIMEZONE` | `Europe/Berlin` (Image) | Zeitzone von PHP im Container (`date.timezone`); außerhalb von Docker gilt `php.ini` |
| `TZ` | `Europe/Berlin` (Image) | Zeitzone des Betriebssystems im Container |

## Sicherheit und Web

| Variable | Standard | Bedeutung |
|---|---|---|
| `OPSPILOT_APP_KEY` ✱ | einmalig in `app_settings` erzeugt | Schlüssel für Zell-Revisionen (≥ 32 Zeichen). Im Container liest der Entrypoint `OPSPILOT_APP_KEY_FILE`. |
| `OPSPILOT_TRUSTED_PROXIES` | – (Compose: `172.31.250.10`) | Kommagetrennte IPs/CIDR vertrauenswürdiger Reverse Proxys. Nur von dort werden `X-Forwarded-For` (Client-IP, Login-Sperren) und – im Container – `X-Forwarded-Proto: https` ausgewertet. |
| `OPSPILOT_FORCE_SECURE_COOKIES` | `0` | `1` = Session-Cookie immer `Secure` (hinter TLS-Proxy empfohlen). **Nicht** setzen, wenn ohne HTTPS getestet wird – sonst funktioniert der Login nicht. |
| `OPSPILOT_BASE_URL` | aus der Anfrage | Öffentliche Adresse, z. B. `https://plan.example.de` (OIDC-Redirect-URI, Absender in Alarmen) |
| `OPSPILOT_ALLOW_INSTALL` | – | `1` = `install.php` im Browser freigeben (nur für die Ersteinrichtung ohne Shell; danach entfernen) |
| `OPSPILOT_OIDC_CLIENT_SECRET` ✱ | – | Client-Secret für OIDC/SSO statt Speicherung in der TenantConfig |
| `OPSPILOT_OIDC_ALLOW_HTTP` | – | `1` = Identity-Provider auch ohne HTTPS (nur Tests) |

## Container-Start (Entrypoint)

| Variable | Standard | Bedeutung |
|---|---|---|
| `OPSPILOT_RUN_MIGRATIONS` | `1` für `apache2-foreground`, sonst `0` | Migrationen beim Start (Sperre über `GET_LOCK`, mehrere Container gleichzeitig sind sicher) |
| `OPSPILOT_WAIT_FOR_DB` | `1` | vor dem Start auf die Datenbank warten |
| `OPSPILOT_DB_WAIT_SECONDS` | `120` | maximale Wartezeit |
| `OPSPILOT_MIGRATE_LOCK_SECONDS` | `600` | maximale Wartezeit auf die Migrationssperre |
| `OPSPILOT_AUTO_BASELINE` | – | Nur für die Übernahme einer Einsatzplanung ≤ v6.9.0 ohne Versionstabelle: z. B. `v6_9_0`. Ohne diese Variable verweigert der Container den Start (die Alt-Migrationen sind nicht wiederholbar). Siehe [MIGRATION_PLAN.md](MIGRATION_PLAN.md). |
| `APACHE_LISTEN_PORT` | `8080` | Port von Apache im Container |

## Backup ([BACKUP.md](BACKUP.md))

| Variable | Standard | Bedeutung |
|---|---|---|
| `OPSPILOT_BACKUP_PUBLIC_KEY` ✱ | – | Öffentlicher Schlüssel (`opspilot-backup-pk-v1:…`, `php bin/backup.php keygen`). Ohne ihn werden **keine** Backups erstellt. |
| `OPSPILOT_BACKUP_PRIVATE_KEY` ✱ | – | Privater Schlüssel – nur für `verify`, `restore`, `restore-test`, `decrypt`. Gehört nicht auf den Produktivserver (Ausnahme: bewusst gewählter automatischer Restore-Test). |
| `OPSPILOT_BACKUP_DIR` | `/data/backups`, ohne `/data`: `app/backups` | lokales Ziel; je Bereich ein Unterordner |
| `OPSPILOT_BACKUP_TMP_DIR` | System-Temp | Arbeitsordner für Dump/Entpacken (Platz für eine Klartext-Kopie der Datenbank nötig) |
| `OPSPILOT_BACKUP_DUMPER` | `auto` | `auto` = `mariadb-dump`/`mysqldump`, falls vorhanden, sonst PHP; `php` = immer PHP-Dumper und -Loader; `native` = Client-Programme erzwingen |
| `OPSPILOT_BACKUP_KEEP_DAILY` | `7` | Aufbewahrung: Anzahl Tage mit je einem Backup |
| `OPSPILOT_BACKUP_KEEP_WEEKLY` | `4` | … Wochen |
| `OPSPILOT_BACKUP_KEEP_MONTHLY` | `12` | … Monate |
| `OPSPILOT_BACKUP_KEEP_LAST` | `3` | die jüngsten n Archive immer behalten |
| `OPSPILOT_BACKUP_KEEP_PRERESTORE_DAYS` | `30` | Sicherheits-Backups vor einem Restore mindestens so lange behalten |
| `OPSPILOT_BACKUP_MAX_AGE_HOURS` | `26` | ab diesem Alter gilt das letzte erfolgreiche Backup als veraltet (Alarm, Dashboard-Hinweis) |
| `OPSPILOT_BACKUP_TEMP_DB_PREFIX` | `opspilot_rt_` | Präfix für Staging-/Wegwerf-Datenbanken bei Restore und Restore-Test |
| `OPSPILOT_BACKUP_BANNER` | `1` | `0` = kein Backup-Hinweis auf dem Dashboard |
| `OPSPILOT_BACKUP_ENABLED` | `1` | `0` = Scheduler erstellt keine Backups (Testsysteme) |
| `OPSPILOT_BACKUP_S3_ENDPOINT` | – | S3-kompatibler Endpunkt, z. B. `https://s3.eu-central-1.amazonaws.com`, `https://fsn1.your-objectstorage.com` |
| `OPSPILOT_BACKUP_S3_BUCKET` | – | Bucket (beide Variablen gesetzt = Ziel aktiv) |
| `OPSPILOT_BACKUP_S3_REGION` | `us-east-1` | Region für die Signatur |
| `OPSPILOT_BACKUP_S3_PREFIX` | `opspilot` | Pfadpräfix im Bucket |
| `OPSPILOT_BACKUP_S3_ACCESS_KEY` ✱ | – | Zugangsschlüssel |
| `OPSPILOT_BACKUP_S3_SECRET_KEY` ✱ | – | geheimer Schlüssel |
| `OPSPILOT_BACKUP_S3_PATH_STYLE` | `auto` | `1` = `https://host/bucket/key` (MinIO, Hetzner), `0` = `https://bucket.host/key`; `auto` = Path-Style außer bei amazonaws.com |
| `OPSPILOT_BACKUP_RCLONE_REMOTE` | – | rclone-Ziel `name:pfad` (SFTP, WebDAV, …); Konfiguration über `RCLONE_CONFIG` bzw. `RCLONE_CONFIG_<NAME>_*` |
| `OPSPILOT_BACKUP_RCLONE_BIN` | `rclone` | Pfad zum rclone-Programm |
| `RCLONE_CONFIG` | – | Pfad der rclone-Konfiguration (z. B. `/data/rclone.conf`) |

### Scheduler (Compose-Dienst `backup`)

| Variable | Standard | Bedeutung |
|---|---|---|
| `OPSPILOT_BACKUP_TIME` | `02:30` | tägliches Backup (Ortszeit, `OPSPILOT_TIMEZONE`); verpasste Läufe werden nach dem Start nachgeholt |
| `OPSPILOT_RESTORE_TEST_DAY` | `7` | Wochentag des Restore-Tests (1 = Montag … 7 = Sonntag) |
| `OPSPILOT_RESTORE_TEST_TIME` | `04:30` | Uhrzeit des Restore-Tests (nur mit privatem Schlüssel) |
| `OPSPILOT_SCHEDULER_INTERVAL` | `60` | Prüfintervall in Sekunden |

### Alarme

| Variable | Standard | Bedeutung |
|---|---|---|
| `OPSPILOT_ALERT_WEBHOOK` | – | URL; JSON-POST mit `event`, `subject`, `message`, `text`, `details` (kompatibel mit Slack/Mattermost-Incoming-Webhooks über `text`) |
| `OPSPILOT_ALERT_EMAIL` | – | Empfänger; Versand über PHP `mail()` (im Container nur mit konfiguriertem `sendmail_path`, sonst Webhook nutzen) |
| `OPSPILOT_ALERT_FROM` | `opspilot@<hostname>` | Absender |
| `OPSPILOT_ALERT_REPEAT_HOURS` | `24` | gleichen Alarm frühestens nach so vielen Stunden wiederholen |

Alarm-Anlässe: fehlgeschlagenes Backup, fehlgeschlagenes externes Ziel, fehlgeschlagener
Restore-Test, letztes erfolgreiches Backup älter als `OPSPILOT_BACKUP_MAX_AGE_HOURS`.

## Nur Docker Compose (`.env`)

| Variable | Standard | Bedeutung |
|---|---|---|
| `OPSPILOT_IMAGE` | `opspilot/app:latest` | Image der Dienste `app` und `backup` |
| `OPSPILOT_PHP_BASE` | `docker.io/library/php:8.3-apache` | Basis-Image beim lokalen Bauen (Spiegel bei Docker-Hub-Limits: `mirror.gcr.io/library/php:8.3-apache`) |
| `OPSPILOT_DB_IMAGE` | `docker.io/library/mariadb:10.11` | Datenbank-Image |
| `OPSPILOT_CADDY_IMAGE` | `docker.io/library/caddy:2` | Proxy-Image |
| `OPSPILOT_HTTP_BIND` | `127.0.0.1` | Adresse, an der der App-Port veröffentlicht wird |
| `OPSPILOT_HTTP_PORT` | `8080` | Host-Port der App |
| `OPSPILOT_DOMAIN` | `localhost` | Hostname(s) für Caddy, kommagetrennt |
| `OPSPILOT_SUBNET` | `172.31.250.0/24` | internes Netz |
| `OPSPILOT_PROXY_IP` | `172.31.250.10` | feste Adresse des Caddy-Containers (= Standard von `OPSPILOT_TRUSTED_PROXIES`) |
| `OPSPILOT_DB_BUFFER_POOL` | `256M` | InnoDB-Pufferspeicher |

## Nur Tests

`OPSPILOT_TEST_DB_HOST`, `_PORT`, `_USER`, `_PASS`, `_NAME` – siehe
[DEVELOPMENT.md](DEVELOPMENT.md). Intern: `OPSPILOT_CSRF_EXEMPT` (Konstante, keine
Umgebungsvariable).
