# Update

Grundsatz: **Backup → neues Image → Migrationen → Healthcheck → bei Problemen zurück.**
Migrationen sind additiv (expand → migrate → contract); ein älteres Image läuft daher in
der Regel auch mit dem neueren Schema. Sicher ist der Rückweg aber nur mit dem Backup von
unmittelbar vor dem Update.

## Docker Compose

```bash
cd /opt/opspilot

# 0. Release-Hinweise lesen (CHANGELOG.md), aktuelle Version notieren
docker compose images app

# 1. Backup jetzt – und prüfen, dass es erfolgreich war
docker compose exec -T backup php bin/backup.php run --kind=manual
docker compose exec -T backup php bin/backup.php status | grep -m1 '"healthy"'
#    optional, mit privatem Schlüssel: Archiv wirklich lesbar?
docker compose exec -T backup php bin/backup.php verify <archiv> --key=- < backup_private.key

# 2. Neues Image holen (Release) – oder Code aktualisieren und bauen
sed -i 's/^OPSPILOT_IMAGE=.*/OPSPILOT_IMAGE=ghcr.io\/<owner>\/opspilot:1.1.0/' .env
docker compose pull app backup
#    lokal gebaut:  git pull && docker compose build app

# 3. Neu starten – der App-Container führt die Migrationen unter Sperre aus
docker compose up -d
docker compose logs -f app          # "Datenbank ist aktuell." bzw. "angewendet: …", dann Apache

# 4. Healthcheck
curl -fsS http://127.0.0.1:8080/api/readyz     # {"status":"ready","checks":{"database":"ok","migrations":"ok"}}
docker compose ps                              # app und backup "healthy"
#    Stichprobe in der Oberfläche: Login, Dashboard, Monatsplanung, Einstellungen → Backup
```

Schlägt eine Migration fehl, startet Apache **nicht** (Container wird neu gestartet und
schlägt erneut fehl); die Fehlermeldung steht in `docker compose logs app`. Jede neue
Migration ist eine Datei in `migrations/opspilot/`; bereits angewendete werden nie erneut
ausgeführt (`schema_migrations`).

## Rollback

**Fall A – Migrationen waren erfolgreich, die neue Version hat einen Fehler:**
vorheriges Image wieder eintragen und starten. Da Migrationen additiv sind, läuft die
ältere Version mit dem neueren Schema.

```bash
sed -i 's/^OPSPILOT_IMAGE=.*/OPSPILOT_IMAGE=ghcr.io\/<owner>\/opspilot:1.0.0/' .env
docker compose up -d
```

**Fall B – Migration fehlgeschlagen oder Daten fehlerhaft:** App stoppen, Datenbank aus dem
Backup von Schritt 1 wiederherstellen, altes Image starten.

```bash
docker compose stop app backup
docker compose run --rm -T --no-deps backup php bin/backup.php restore <archiv-aus-schritt-1> \
    --replace --yes --key=- < backup_private.key
sed -i 's/^OPSPILOT_IMAGE=.*/OPSPILOT_IMAGE=ghcr.io\/<owner>\/opspilot:1.0.0/' .env
docker compose up -d
curl -fsS http://127.0.0.1:8080/api/readyz
```

`--replace` legt vorher ein Sicherheits-Backup des (defekten) Stands an und behält die
ersetzten Tabellen in `opspilot_rt_old_…`, bis Sie sie löschen.

## Ohne Docker

```bash
cd /var/www/opspilot
set -a; . /etc/opspilot/opspilot.env; set +a
sudo -E -u www-data php bin/backup.php run --kind=manual            # 1. Backup
git fetch --tags && git checkout v1.1.0                              # 2. Code
sudo -E -u www-data php bin/migrate.php status                       #    offene Migrationen ansehen
sudo -E -u www-data php bin/migrate.php up                           # 3. Migrationen
sudo systemctl reload apache2                                        #    OPcache leeren
curl -fsS https://plan.example.de/api/readyz                         # 4. Healthcheck
```

Rollback: `git checkout v1.0.0` (Fall A) bzw. zusätzlich `php bin/backup.php restore …
--replace --yes` (Fall B).

## SaaS

Vor dem Rollout `php bin/migrate.php up --platform` und `--all-tenants` als eigenen Job
ausführen (bricht beim ersten fehlerhaften Mandanten ab; die übrigen bleiben auf dem
alten Stand und können mit dem alten Image weiterlaufen). Der App-Container erledigt
beides beim Start ebenfalls, mit serverweiter Sperre.

## Erstmaliger Umstieg von Einsatzplanung ≤ v6.9

Siehe [MIGRATION_PLAN.md](MIGRATION_PLAN.md) und [INSTALLATION.md](INSTALLATION.md#bestehende-einsatzplanung-übernehmen)
(`OPSPILOT_AUTO_BASELINE`). Alte Cronjobs auf `api/backup?action=cron_backup` entfernen –
Backups übernimmt jetzt der Dienst `backup` bzw. der systemd-Timer.
