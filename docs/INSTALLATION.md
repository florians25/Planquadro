# Installation

Drei Wege: **Docker Compose** (empfohlen), klassisch mit **Apache + PHP** auf einem
Linux-Server oder **Shared Hosting**. Alle Einstellungen: [CONFIGURATION.md](CONFIGURATION.md).
Updates: [UPDATE.md](UPDATE.md). Backups: [BACKUP.md](BACKUP.md).

## Voraussetzungen

| | Docker | ohne Docker |
|---|---|---|
| Server | Linux (x86_64 oder arm64), 1 vCPU, 1 GB RAM, 10 GB Platte | dito |
| Software | Docker Engine ≥ 24 mit Compose-Plugin | Apache 2.4 (`mod_rewrite`, `mod_headers`, `mod_expires`), PHP ≥ 8.1 mit `pdo_mysql`, `mbstring`, `openssl`, `curl`, `intl`, `calendar`, `zip`, `sodium`, `zlib`; MariaDB ≥ 10.3 oder MySQL ≥ 8.0; empfohlen `mariadb-client` (für `mariadb-dump`) |
| Netz | Ports 80/443 für HTTPS (Caddy) oder eigener Reverse Proxy | dito |

## 1. Docker Compose (Schnellstart)

```bash
git clone https://github.com/<owner>/planquadro.git /opt/planquadro && cd /opt/planquadro
cp .env.example .env && chmod 600 .env
$EDITOR .env                           # mindestens PQ_DOMAIN, PQ_BASE_URL
sh docker/init-secrets.sh              # Passwörter, App-Schlüssel, Backup-Schlüsselpaar
```

`init-secrets.sh` legt `./secrets/` an (DB-Passwörter, `app_key`, `backup_public.key`) und
erzeugt den **privaten Backup-Schlüssel** `secrets/backup_private.key`. Diesen jetzt offline
sichern und danach vom Server löschen (`shred -u secrets/backup_private.key`) – siehe
[BACKUP.md](BACKUP.md#schlüssel).

Starten – mit HTTPS über Caddy (Let's Encrypt, DNS von `PQ_DOMAIN` muss auf den Server
zeigen, Ports 80 und 443 offen):

```bash
docker compose --profile proxy up -d --build
```

oder ohne Caddy (App lauscht auf `127.0.0.1:8080`, eigener Proxy davor, siehe unten):

```bash
docker compose up -d --build
```

Beim Start wartet der App-Container auf die Datenbank, spielt Schema und Migrationen ein
(mit Sperre) und startet Apache. Prüfen:

```bash
docker compose ps                                  # alle Dienste "healthy"
curl -s http://127.0.0.1:8080/api/readyz           # {"status":"ready",…}
docker compose logs -f app
```

### Ersten Administrator anlegen

```bash
docker compose exec app php public/install.php
```

Das Einmalpasswort wird **einmal** angezeigt; beim ersten Login muss es geändert werden.
Ein weiterer Aufruf ändert nichts, sobald Benutzer existieren.

### Image statt lokalem Build

Bei veröffentlichten Releases (GitHub Container Registry) in `.env` setzen und ohne
`--build` starten:

```ini
PQ_IMAGE=ghcr.io/<owner>/planquadro:1.0.0
```

```bash
docker compose pull && docker compose --profile proxy up -d
```

Wenn Docker Hub beim Bauen Limits meldet: `PQ_PHP_BASE=mirror.gcr.io/library/php:8.3-apache`,
`PQ_DB_IMAGE=mirror.gcr.io/library/mariadb:10.11`, `PQ_CADDY_IMAGE=mirror.gcr.io/library/caddy:2`.

### Was wo liegt

| Volume | Inhalt |
|---|---|
| `db-data` | MariaDB-Datenverzeichnis |
| `app-data` → `/data` | `storage/` (Mandantendateien), `backups/` (lokale Archive), `sessions/` (nur `PQ_SESSION_STORE=files`) |
| `caddy-data`, `caddy-config` | Zertifikate von Caddy |
| `./secrets/` (Host) | Passwörter und öffentlicher Backup-Schlüssel (Docker-Secrets, `*_FILE`) |

Der App-Container läuft ohne Root-Rechte (`www-data`, Port 8080); erreichbar ist nur
`public/` (DocumentRoot), `app/`, `bin/`, `migrations/` nicht.

## 2. Reverse Proxy und HTTPS

Die App spricht im Container nur HTTP. TLS übernimmt ein Reverse Proxy. Die App wertet
`X-Forwarded-For` und `X-Forwarded-Proto` **nur** von Adressen in `PQ_TRUSTED_PROXIES`
aus; für alle anderen werden diese Header ignoriert (keine IP-Fälschung, kein
vorgetäuschtes HTTPS). Hinter TLS zusätzlich `PQ_FORCE_SECURE_COOKIES=1`.

**Caddy (mitgeliefert):** Profil `proxy`, Konfiguration `docker/caddy/Caddyfile`. Caddy hat
die feste Adresse `172.31.250.10` im Compose-Netz, die als Standard in
`PQ_TRUSTED_PROXIES` steht. Mehrere Hostnamen: `PQ_DOMAIN=a.example.de, b.example.de`.

**Eigenes nginx auf dem Host** (App-Port bleibt auf `127.0.0.1:8080`):

```nginx
server {
    listen 443 ssl http2;
    server_name plan.example.de;
    ssl_certificate     /etc/letsencrypt/live/plan.example.de/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/plan.example.de/privkey.pem;
    client_max_body_size 50m;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;
        proxy_read_timeout 120s;
    }
}
server { listen 80; server_name plan.example.de; return 301 https://$host$request_uri; }
```

Dazu in `.env`: `PQ_TRUSTED_PROXIES=172.31.250.1` (Gateway des Compose-Netzes, über
das Docker die Verbindungen vom Host weiterreicht) und `PQ_FORCE_SECURE_COOKIES=1`.

**Traefik** (Labels am Dienst `app`, Traefik im selben Docker-Netz):

```yaml
services:
  app:
    labels:
      - traefik.enable=true
      - traefik.http.routers.planquadro.rule=Host(`plan.example.de`)
      - traefik.http.routers.planquadro.entrypoints=websecure
      - traefik.http.routers.planquadro.tls.certresolver=le
      - traefik.http.services.planquadro.loadbalancer.server.port=8080
    environment:
      PQ_TRUSTED_PROXIES: <IP oder Subnetz des Traefik-Containers>
      PQ_FORCE_SECURE_COOKIES: "1"
```

Healthchecks für Load Balancer: `GET /api/healthz` (Prozess lebt, ohne DB) und
`GET /api/readyz` (DB erreichbar, keine offenen Migrationen; im SaaS-Modus zusätzlich die
Mandanten-DB des angefragten Hosts). Beide liefern JSON ohne interne Details.

### Mail-Alarme (SMTP)

Das Image enthält kein sendmail. Backup-Alarme per Mail gehen deshalb über den
eingebauten SMTP-Client (ohne zusätzliche Programme; STARTTLS ist Pflicht):

```bash
printf '%s' 'smtp-passwort' > secrets/smtp_password && chmod 600 secrets/smtp_password
```

```yaml
# docker-compose.override.yml
services:
  backup:
    environment:
      PQ_ALERT_EMAIL: it@example.de
      PQ_SMTP_HOST: smtp.example.de
      PQ_SMTP_PORT: "587"
      PQ_SMTP_USER: planquadro@example.de
      PQ_SMTP_PASS_FILE: /run/secrets/smtp_password
      PQ_SMTP_FROM: planquadro@example.de
    secrets: [smtp_password]
secrets:
  smtp_password:
    file: ./secrets/smtp_password
```

Prüfen: `docker compose exec backup php bin/backup.php alert-test` (meldet je Kanal OK
oder FEHLER, Details im Log). Ohne `PQ_SMTP_HOST` wird die Mail mit Warnung übersprungen;
ein konfigurierter Webhook funktioniert unabhängig davon. Alle Variablen:
[CONFIGURATION.md](CONFIGURATION.md#smtp-versand).

### Mehrere App-Container (Load Balancer)

Standardmäßig liegen Sitzungen als Dateien im Volume eines Containers. Für mehrere
App-Container müssen sie gemeinsam sein: `PQ_SESSION_STORE=db` speichert sie in der
Datenbank (Self-Hosting in `PQ_DB_NAME`, SaaS in der jeweiligen Mandanten-DB, die
Plattform-Administration in der Plattform-DB). Sticky Sessions sind dann nicht nötig.

```bash
docker compose -f docker-compose.yml -f docker-compose.replicas.yml --profile proxy up -d
# Anzahl: PQ_APP_REPLICAS=3 in .env, oder
docker compose -f docker-compose.yml -f docker-compose.replicas.yml --profile proxy up -d --scale app=3
```

[`docker-compose.replicas.yml`](../docker-compose.replicas.yml) setzt `PQ_SESSION_STORE=db`,
entfernt den festen Host-Port der App und lässt Caddy per Round Robin auf alle Replikate
verteilen ([`Caddyfile.replicas`](../docker/caddy/Caddyfile.replicas), dynamische
Auflösung über den Docker-DNS). Kombinierbar mit `docker-compose.saas.yml`.

Hinweise zum Load Balancer:

- **Eigener Load Balancer** (nginx, HAProxy, Traefik, Cloud-LB): alle Replikate als
  Backend, `X-Forwarded-For` und `X-Forwarded-Proto` setzen, dessen Adresse in
  `PQ_TRUSTED_PROXIES`, `PQ_FORCE_SECURE_COOKIES=1`. Health-Check: `GET /api/readyz`.
- **Timeouts:** Lese-Timeout ≥ 120 s (Planungsassistent, Backup im Browser).
- **Sperren:** parallele Requests derselben Sitzung laufen wie bei Dateisessions
  nacheinander; Live-Polling wartet höchstens `PQ_SESSION_POLL_LOCK_TIMEOUT` (1 s) und
  läuft dann nur lesend weiter.
- **Dateien:** `/data/storage` muss für alle Replikate derselbe Speicher sein – auf einem
  Docker-Host das gemeinsame Volume `app-data`, über mehrere Hosts NFS o. ä. Die
  Sitzungsdateien unter `/data/sessions` werden mit `db` nicht mehr benutzt.
- **Migrationen:** jedes Replikat migriert beim Start unter einer DB-Sperre; nur eines
  führt sie aus. Updates wie gewohnt mit `docker compose … up -d` (Compose ersetzt alle
  Replikate; angemeldete Benutzer bleiben angemeldet, weil die Sitzungen in der DB liegen).
- **Datenbank-Cluster:** Die Sitzungssperre nutzt `GET_LOCK()`; Galera unterstützt das
  nicht clusterweit – Schreibzugriffe auf einen Knoten leiten.
- Abgemeldete oder abgelaufene Sitzungen löscht PHP selbst und stündlich der Dienst
  `backup` (`php bin/scheduler.php sessions-gc`).

## 3. Ohne Docker: Apache + PHP auf Linux

Beispiel Debian/Ubuntu:

```bash
sudo apt install apache2 libapache2-mod-php php-mysql php-mbstring php-curl php-intl php-zip \
                 php-xml mariadb-server mariadb-client rclone
sudo a2enmod rewrite headers expires
sudo git clone https://github.com/<owner>/planquadro.git /var/www/planquadro
sudo chown -R root:www-data /var/www/planquadro
sudo install -d -o www-data -g www-data -m 0750 /var/www/planquadro/app/storage /var/www/planquadro/app/backups
```

Datenbank:

```sql
CREATE DATABASE planquadro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'planquadro'@'localhost' IDENTIFIED BY '<starkes Passwort>';
GRANT ALL ON planquadro.* TO 'planquadro'@'localhost';
GRANT ALL ON `planquadro\_%`.* TO 'planquadro'@'localhost';   -- Restore/Restore-Test
```

Umgebung für Webserver **und** CLI an einer Stelle: `/etc/planquadro/planquadro.env`
(`root:www-data`, `0640`):

```ini
PQ_DB_HOST=localhost
PQ_DB_NAME=planquadro
PQ_DB_USER=planquadro
PQ_DB_PASS_FILE=/etc/planquadro/db_password
PQ_APP_KEY=<openssl rand -hex 32>
PQ_STORAGE_DIR=/var/www/planquadro/app/storage
PQ_BACKUP_DIR=/var/backups/planquadro
PQ_BACKUP_PUBLIC_KEY_FILE=/etc/planquadro/backup_public.key
PQ_FORCE_SECURE_COOKIES=1
```

VirtualHost (`/etc/apache2/sites-available/planquadro.conf`) – **DocumentRoot ist `public/`**:

```apache
<VirtualHost *:443>
    ServerName plan.example.de
    DocumentRoot /var/www/planquadro/public
    <Directory /var/www/planquadro>
        Require all denied
        AllowOverride None
    </Directory>
    <Directory /var/www/planquadro/public>
        Require all granted
        AllowOverride All
        Options -Indexes
    </Directory>
    # Umgebungsvariablen (alternativ: PassEnv mit systemd-Environment)
    SetEnv PQ_DB_HOST localhost
    SetEnv PQ_DB_NAME planquadro
    SetEnv PQ_DB_USER planquadro
    SetEnv PQ_DB_PASS_FILE /etc/planquadro/db_password
    SetEnv PQ_STORAGE_DIR /var/www/planquadro/app/storage
    SetEnv PQ_BACKUP_DIR /var/backups/planquadro
    SetEnv PQ_BACKUP_PUBLIC_KEY_FILE /etc/planquadro/backup_public.key
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/plan.example.de/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/plan.example.de/privkey.pem
</VirtualHost>
```

Hinweis: Mit `SetEnv` gesetzte Werte sieht PHP über `getenv()` (mod_php). Die Dateien in
`/etc/planquadro/` müssen für `www-data` lesbar sein (`chgrp www-data`, `chmod 0640`).

Schema, Administrator, Backup-Schlüssel, Zeitsteuerung:

```bash
cd /var/www/planquadro
set -a; . /etc/planquadro/planquadro.env; set +a
sudo -E -u www-data php bin/migrate.php up
sudo -E -u www-data php public/install.php
php bin/backup.php keygen --out=/root/planquadro-keys      # privat: offline sichern, dann löschen
sudo install -m 0640 -g www-data /root/planquadro-keys/backup_public.key /etc/planquadro/
sudo install -d -o www-data -g www-data -m 0750 /var/backups/planquadro
sudo cp deploy/systemd/planquadro-backup*.{service,timer} /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now planquadro-backup.timer planquadro-backup-check.timer
sudo systemctl reload apache2
```

## Shared Hosting

Für Webspace ohne Shell und ohne Docker (z. B. STRATO, IONOS) gilt weiterhin die
bisherige Anleitung ([EINBAU_v6_8_5.md](../EINBAU_v6_8_5.md)), mit diesen Punkten:

- **Zielverzeichnis der Domain auf `public/` stellen.** Geht das nicht, leitet die
  `.htaccess` im Wurzelverzeichnis in `public/` um und sperrt `app/`.
- Zugangsdaten in `app/config/secrets.php` (Vorlage `secrets.sample.php`), falls das Panel
  keine Umgebungsvariablen erlaubt.
- Ersteinrichtung ohne Shell: einmalig `PQ_ALLOW_INSTALL=1` (Panel oder `.htaccess`
  `SetEnv`), `/install` aufrufen, Variable wieder entfernen. Migrationen ohne Shell:
  einmalig per Cronjob-Funktion des Panels `php bin/migrate.php up` ausführen.
- **Backups:** `bin/backup.php run` über die Cronjob-Funktion des Hosters (täglich) mit
  den nötigen Variablen. Ohne Cronjobs bleibt „Backup jetzt erstellen“ in den
  Einstellungen; der Dashboard-Hinweis erinnert, wenn das letzte Backup älter als 26 h ist.
  Ein externes Ziel ist auf Shared Hosting besonders wichtig (S3-kompatibel braucht nur
  `curl`). Die alte Cron-URL `api/backup?action=cron_backup&key=…` gibt es nicht mehr.
- Ohne `mariadb-dump` nutzt Planquadro automatisch den PHP-Dumper (ebenfalls konsistent).
- Restore erfordert eine Shell (oder lokal: Archiv herunterladen, mit Planquadro auf dem
  eigenen Rechner wiederherstellen, SQL per phpMyAdmin in eine neue Datenbank laden).

## SaaS-Betrieb

```bash
docker compose -f docker-compose.yml -f docker-compose.saas.yml --profile proxy up -d --build
docker compose exec app php bin/tenant.php create acme "Acme GmbH" --domain=acme.example.de
```

Der Start migriert die Plattform-DB und alle Mandanten (`--platform`, `--all-tenants`). Neue
Mandanten-Hosts in `PQ_DOMAIN` ergänzen (Caddy) oder ein Wildcard-Zertifikat
(DNS-Challenge, eigener Caddy-Build mit DNS-Plugin) verwenden. Der Backup-Dienst sichert
jeden Mandanten einzeln (Status in `backup_runs`).

## Bestehende Einsatzplanung übernehmen

Siehe [MIGRATION_PLAN.md](MIGRATION_PLAN.md). Kurz: Backup der Altinstallation, Datenbank
in den neuen Server übernehmen, dann **einmalig** mit `PQ_AUTO_BASELINE=v6_9_0` starten
(oder `docker compose run --rm --no-deps app php bin/migrate.php baseline v6_9_0`). Ohne diese
Angabe verweigert der Container den Start, statt die nicht wiederholbaren Alt-Migrationen
erneut auszuführen.
