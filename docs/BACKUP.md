# Backup und Wiederherstellung

Dieses Dokument beschreibt das Backup-System von Vireo (`bin/backup.php`,
`app/lib/Backup.php`, `bin/scheduler.php`). Notfall-Abläufe stehen in
[DISASTER_RECOVERY.md](DISASTER_RECOVERY.md), alle Variablen in
[CONFIGURATION.md](CONFIGURATION.md#backup-backupmd).

## Konzept

| Eigenschaft | Umsetzung |
|---|---|
| Konsistenz | `mariadb-dump`/`mysqldump --single-transaction --quick --routines --triggers --hex-blob`; ohne diese Programme ein PHP-Dumper innerhalb von `START TRANSACTION WITH CONSISTENT SNAPSHOT` (REPEATABLE READ). Kein Sperren der Anwendung während des Backups. |
| Umfang | je **Bereich** ein Archiv: Self-Hosting `default` (Datenbank + Dateien aus `VIREO_STORAGE_DIR/default`); SaaS jeder Mandant einzeln (eigene DB + Dateien) und `_platform` (Plattform-DB) |
| Verschlüsselung | Public-Key-Verfahren mit libsodium: Der Server kennt nur den **öffentlichen** Schlüssel. Jedes Archiv bekommt einen zufälligen Datenschlüssel, der per `crypto_box_seal` (X25519) für den öffentlichen Schlüssel versiegelt wird; der Inhalt ist mit `crypto_secretstream_xchacha20poly1305` in 1-MiB-Blöcken authentisiert verschlüsselt. Jede Veränderung, Kürzung oder ein falscher Schlüssel wird erkannt. |
| Prüfung | Manifest mit Zeilenzahlen je Tabelle, angewendeten Migrationen und SHA-256 aller Teile; Upload-Prüfung je Ziel (Größe, Hash bzw. ETag); wöchentlicher Restore-Test in eine Wegwerf-Datenbank |
| Ablage | lokal (`VIREO_BACKUP_DIR`) + optional S3-kompatibel und/oder jedes rclone-Ziel (SFTP, WebDAV, Nextcloud, zweiter Server …) |
| Aufbewahrung | GFS (Großvater-Vater-Sohn) je Ziel: 7 täglich, 4 wöchentlich, 12 monatlich, dazu immer die 3 jüngsten |
| Überwachung | Status je Lauf (SaaS: Tabelle `backup_runs` der Plattform-DB; Self-Hosting: `VIREO_STORAGE_DIR/_backup/status.json`), Anzeige unter *Einstellungen → Backup*, Dashboard-Hinweis, Alarme per Webhook/Mail |

### 3-2-1-Regel

Mindestens **3** Kopien der Daten, auf **2** verschiedenen Medien, davon **1** außer Haus.
Mit Vireo: Produktivdatenbank + lokales Archiv (`/data/backups`) + externes Ziel (S3 oder
rclone bei einem anderen Anbieter/Standort). Ohne externes Ziel meldet Vireo in jedem
Lauf, im Status und in den Einstellungen deutlich: *„Kein externes Backup-Ziel
konfiguriert (3-2-1-Regel nicht erfüllt)“*. Empfehlung zusätzlich: Objekt-Sperre
(S3 Object Lock / Versionierung) oder ein Ziel, auf das der Server nur schreiben, aber
nicht löschen darf – das schützt die externen Kopien bei Ransomware (siehe unten).

## Schlüssel

```bash
php bin/backup.php keygen                 # gibt beide Schlüssel aus
php bin/backup.php keygen --out=/sicher   # oder als Dateien (privat: 0600)
# Docker: wird von sh docker/init-secrets.sh erledigt (secrets/backup_public.key, secrets/backup_private.key)
```

- **Öffentlicher Schlüssel** (`vireo-backup-pk-v1:…`) → auf den Server:
  `VIREO_BACKUP_PUBLIC_KEY_FILE` (Compose: `secrets/backup_public.key`). Mit ihm lassen
  sich Backups nur *erstellen*, nicht lesen. Ohne ihn erstellt Vireo **keine**
  (auch keine unverschlüsselten) Backups.
- **Privater Schlüssel** (`vireo-backup-sk-v1:…`) → **offline**: Passwortmanager der
  Verantwortlichen, verschlüsselter USB-Stick im Tresor und/oder Papierausdruck, mindestens
  zwei getrennte Orte. Nicht auf dem Produktivserver, nicht im Git, nicht im Backup-Ziel.
  Danach `shred -u secrets/backup_private.key`.
- Fingerprint (16 Hex-Zeichen) wird bei `keygen` angezeigt und steht in jedem Manifest –
  damit lässt sich prüfen, ob ein Schlüssel zu einem Archiv passt.
- **Schlüsselwechsel:** neues Paar erzeugen, öffentlichen Schlüssel austauschen; alte
  Archive bleiben mit dem *alten* privaten Schlüssel lesbar – diesen so lange aufbewahren,
  bis das letzte damit verschlüsselte Archiv aus der Aufbewahrung gefallen ist (≥ 12 Monate).
- **Verlust des privaten Schlüssels:** vorhandene Archive sind unwiederbringlich
  unlesbar. Sofort neues Schlüsselpaar erzeugen und ein neues Backup erstellen
  (DISASTER_RECOVERY.md, Szenario D).

Der private Schlüssel wird an `bin/backup.php` übergeben als `--key=<datei>`, `--key=-`
(Standardeingabe) oder `VIREO_BACKUP_PRIVATE_KEY_FILE`. Mit Docker, ohne die Datei in den
Container zu kopieren:

```bash
docker compose exec -T backup php bin/backup.php verify <archiv> --key=- < backup_private.key
```

## Ziele

**Lokal:** `VIREO_BACKUP_DIR` (Docker: Volume `/data/backups`), Unterordner je Bereich.

**S3-kompatibel** (AWS S3, Hetzner Object Storage, Wasabi, MinIO, Ceph …), eigene
Implementierung von AWS Signature V4 über curl – kein SDK nötig:

```ini
VIREO_BACKUP_S3_ENDPOINT=https://fsn1.your-objectstorage.com
VIREO_BACKUP_S3_REGION=eu-central
VIREO_BACKUP_S3_BUCKET=vireo-backup
VIREO_BACKUP_S3_PREFIX=vireo
VIREO_BACKUP_S3_ACCESS_KEY=…
VIREO_BACKUP_S3_SECRET_KEY_FILE=/run/secrets/s3_secret   # oder _SECRET_KEY
```

Nach dem Upload werden ETag (MD5) und Größe geprüft. Benötigte Rechte: `PutObject`,
`GetObject`, `ListBucket`, `DeleteObject` (Letzteres nur für die Aufbewahrung – mit Object
Lock/Lifecycle-Regeln im Bucket kann man es entziehen; dann löscht Vireo dort nichts und
meldet eine Warnung bei der Aufbewahrung).

**rclone** für alles andere (SFTP, WebDAV, Nextcloud, Backblaze B2, Google Drive …):

```ini
VIREO_BACKUP_RCLONE_REMOTE=offsite:vireo
RCLONE_CONFIG=/data/rclone.conf        # mit "rclone config" erstellt
```

Das Docker-Image enthält rclone. Nach dem Upload wird die Größe am Ziel geprüft.

Schlägt ein externes Ziel fehl, ist der Lauf `warning` (lokal gesichert), es wird alarmiert.
Schlägt das lokale Ziel oder der Dump fehl, ist der Lauf `failed`.

## Aufbewahrung (GFS)

Je Ziel und Bereich werden behalten: die jüngsten `KEEP_LAST` (3) Archive, je Tag das
jüngste Archiv der letzten `KEEP_DAILY` (7) Tage mit Backup, je ISO-Woche das jüngste der
letzten `KEEP_WEEKLY` (4) Wochen und je Monat das jüngste der letzten `KEEP_MONTHLY` (12)
Monate. Sicherheits-Backups vor einem Restore (`prerestore`) bleiben mindestens
`KEEP_PRERESTORE_DAYS` (30) Tage. Alles andere wird nach jedem erfolgreichen Lauf gelöscht.
Nur aufräumen: `php bin/backup.php prune`.

## Zeitsteuerung

**Docker Compose:** Der Dienst `backup` (`php bin/scheduler.php`) erstellt täglich um
`VIREO_BACKUP_TIME` (02:30) ein Backup aller Bereiche, prüft stündlich auf veraltete
Backups und führt – falls ein privater Schlüssel konfiguriert ist – wöchentlich den
Restore-Test aus. Verpasste Läufe (Server war aus) werden nach dem Start nachgeholt.
Gesundheit: `docker compose ps` (Healthcheck = Heartbeat des Schedulers).

**Ohne Docker** (Linux mit systemd): Units in [`deploy/systemd/`](../deploy/systemd/).

```bash
sudo mkdir -p /etc/vireo
sudo install -m 0640 -o root -g www-data /dev/null /etc/vireo/vireo.env
# KEY=VALUE-Zeilen: VIREO_DB_*, VIREO_STORAGE_DIR, VIREO_BACKUP_*, VIREO_ALERT_*
sudo cp deploy/systemd/vireo-backup*.{service,timer} /etc/systemd/system/
# Pfad WorkingDirectory=/var/www/vireo und User=www-data bei Bedarf anpassen
sudo systemctl daemon-reload
sudo systemctl enable --now vireo-backup.timer vireo-backup-check.timer
systemctl list-timers 'vireo-*'
journalctl -u vireo-backup.service
```

Alternativ cron: [`deploy/cron/vireo`](../deploy/cron/vireo) nach `/etc/cron.d/`.
Shared Hosting ohne Shell: siehe [INSTALLATION.md](INSTALLATION.md#shared-hosting).

## Befehle

```bash
php bin/backup.php run [--tenant=<slug>] [--kind=manual]   # Backup jetzt
php bin/backup.php list [--tenant=<slug>] [--json]         # Archive je Ziel
php bin/backup.php status [--check] [--alert]              # JSON; --check: Exit 3 wenn nicht gesund
php bin/backup.php verify <archiv> --key=<datei>           # entschlüsseln + alle Prüfsummen
php bin/backup.php restore <archiv> --target-db=<neue_db> [--files] [--files-dir=<ordner>] --key=<datei>
php bin/backup.php restore <archiv> --replace --yes [--files] --key=<datei>   # Produktion ersetzen
php bin/backup.php restore-test [--tenant=<slug>] --key=<datei>
php bin/backup.php decrypt <archiv> --out=<datei.tar> --key=<datei>          # Klartext-tar (Notfall)
php bin/backup.php prune
php bin/backup.php legacy-decrypt <alt.epbak> <app/backups/.backup_key> --out=<datei.sql>
```

`<archiv>` ist ein Pfad oder nur der Archivname; dann wird es in den Zielen gesucht (lokal
zuerst, sonst von S3/rclone geladen). Im Docker-Betrieb jeweils mit
`docker compose exec -T backup …` voranstellen.

Über die Oberfläche (*Einstellungen → Backup*, Recht `manage_settings`): Status (letzter
Lauf, Alter, Ziele, Aufbewahrung, letzter Restore-Test, Warnungen), *Backup jetzt
erstellen* und Download der (verschlüsselten) Archive. Wiederherstellen ist bewusst nur
per Kommandozeile mit dem privaten Schlüssel möglich.

## Wiederherstellen

### In eine neue Datenbank (Standard, gefahrlos)

```bash
php bin/backup.php restore vireo_default_20261029-023005Z_scheduled.opbak \
    --target-db=vireo_restore --files --files-dir=/tmp/vireo-files --key=backup_private.key
```

- Ziel muss neu oder leer sein, sonst Abbruch. Ablauf: entschlüsseln → Prüfsummen →
  Dump auf unzulässige Anweisungen prüfen (`USE`, `CREATE/DROP DATABASE`) → einspielen →
  **beim ersten SQL-Fehler Abbruch** → Zeilenzahlen gegen das Manifest vergleichen.
- Danach z. B. einzelne Datensätze herauskopieren oder die Anwendung auf die neue
  Datenbank umstellen (`VIREO_DB_NAME`).

### Produktion ersetzen

```bash
php bin/backup.php restore <archiv> --replace --yes --files --key=backup_private.key
```

1. **Sicherheits-Backup** des aktuellen Stands (`…_prerestore.opbak`); schlägt es fehl,
   wird abgebrochen.
2. Einspielen in eine Staging-Datenbank (`vireo_rt_stage_…`) und Zeilenvergleich –
   bei Abweichung bleibt die Produktion unverändert.
3. **Atomarer Tausch** per `RENAME TABLE`: die bisherigen Tabellen wandern in
   `vireo_rt_old_…` (sofortiges Zurückrollen möglich), die wiederhergestellten in die
   Produktionsdatenbank.
4. Mit `--files`: bisheriger Dateiordner wird nach `<ordner>.pre-restore-<zeit>` verschoben,
   die Dateien aus dem Archiv werden entpackt.

Vorher Wartungsmodus bzw. App stoppen (`docker compose stop app`), danach starten und
prüfen; die alte Datenbank nach erfolgreicher Prüfung löschen (`DROP DATABASE …`).

### Einzelnen Mandanten wiederherstellen (SaaS)

Jeder Mandant hat eigene Archive (`<slug>/vireo_<slug>_…`). Andere Mandanten sind nicht
betroffen.

```bash
php bin/backup.php list --tenant=acme
php bin/backup.php restore <archiv> --tenant=acme --target-db=vireo_t_acme_check --key=…   # prüfen
php bin/backup.php restore <archiv> --tenant=acme --replace --yes --files --key=…            # ersetzen
```

`--tenant` stellt sicher, dass das Archiv wirklich zu diesem Mandanten gehört. Die
Plattform-DB (`_platform`) wird getrennt gesichert und wiederhergestellt.

### Rechte

Der Datenbankbenutzer muss neue Datenbanken mit Präfix `vireo_` anlegen dürfen. In
Docker Compose richtet `docker/db-init/10-vireo-grants.sh` das bei der Erstinitialisierung
ein. Für ein bestehendes Volume einmalig:

```bash
docker compose exec db sh -c 'mariadb -uroot -p"$(cat /run/secrets/db_root_password)" \
  -e "GRANT ALL PRIVILEGES ON \`vireo\\_%\`.* TO '\''vireo'\''@'\''%'\''; FLUSH PRIVILEGES"'
```

## Restore-Test

`php bin/backup.php restore-test` nimmt je Bereich das jüngste reguläre Archiv (lokal oder
extern), entschlüsselt es, spielt es in eine Wegwerf-Datenbank `vireo_rt_<zeit>` ein,
vergleicht Zeilenzahlen aller Tabellen und Migrationsstand mit dem Manifest, entpackt die
Dateien, löscht die Wegwerf-Datenbank und protokolliert das Ergebnis (sichtbar in den
Einstellungen und in `status`). Fehler lösen einen Alarm aus.

Der Restore-Test braucht den privaten Schlüssel. Zwei Betriebsarten:

1. **Empfohlen – separates Prüfsystem:** eine kleine VM/ein zweiter Server mit Vireo,
   eigener MariaDB, Zugriff (nur lesend) auf das externe Ziel und dem privaten Schlüssel;
   dort `vireo-restore-test.timer` (deploy/systemd) oder cron. Der Produktivserver
   kennt den privaten Schlüssel nie. Das Ergebnis steht im Status des Prüfsystems; die
   Alarme kommen über dieselben Webhook/Mail-Ziele.
2. **Auf dem Produktivserver:** `VIREO_BACKUP_PRIVATE_KEY_FILE` im Dienst `backup`
   setzen (eigenes Secret). Bequem, aber wer den Server übernimmt, kann dann auch alle
   Backups lesen – bewusst abwägen.

Ohne privaten Schlüssel überspringt der Scheduler den Test und meldet in den
Einstellungen *„Noch kein Restore-Test durchgeführt“* bzw. *„älter als 8 Tage“* – dann
mindestens monatlich manuell testen:

```bash
docker compose exec -T backup php bin/backup.php restore-test --key=- < backup_private.key
```

## Archivformat (Version 1)

Dateiname: `vireo_<bereich>_<JJJJMMTT-hhmmss>Z_<art>.opbak`, Art = `scheduled`,
`manual` oder `prerestore`; Bereich = Mandanten-Slug, `default` oder `_platform`.

```
Offset  Länge  Inhalt
0       8      Kennung "OPBAK\0\1\n"
8       8      Fingerprint des öffentlichen Schlüssels (SHA-256, erste 8 Byte)
16      80     crypto_box_seal(Datenschlüssel 32 Byte, öffentlicher Schlüssel)
96      24     Header von crypto_secretstream_xchacha20poly1305
120     …      Blöcke: uint32 big-endian Länge + Chiffrat (≤ 1 MiB Klartext + 17 Byte);
               der letzte Block trägt TAG_FINAL. Die Bytes 0–95 sind bei jedem Block
               als zusätzliche authentisierte Daten gebunden.
```

Klartext = tar-Archiv (GNU-Format) mit:

- `manifest.json` – `format`, `version`, `created_at`, `kind`, `scope`, `mode`, `tenant`,
  `host`, `database` (`name`, `platform`, `server_version`, `dump_method`, `tables`
  {Tabelle: Zeilen}, `schema_migrations` [Versionen]), `files` (`included`, `count`,
  `bytes`), `parts` [{`name`, `size`, `sha256`}], `encryption` (`scheme`, `key_fingerprint`)
- `db.sql.gz` – SQL-Dump (gzip)
- `files.tar.gz` – Dateien des Mandanten (falls vorhanden)

Im Notfall ohne Vireo-Code genügt `php bin/backup.php decrypt … --out=x.tar`, danach
`tar -xf x.tar`, `gunzip db.sql.gz`, `mariadb neue_db < db.sql`.

## Alt-Backups (Einsatzplanung ≤ v6.9, `.epbak`)

Die alte Backup-Funktion (AES-CBC ohne Integritätsschutz, Schlüssel neben den Backups,
Cron-URL mit Schlüssel) ist entfernt (Befunde B-01…B-05). Vorhandene Dateien in
`app/backups/` werden in den Einstellungen weiter angezeigt und können heruntergeladen
werden. Wiederherstellen:

```bash
php bin/backup.php legacy-decrypt app/backups/backup_auto_2026-01-31_02-00-00.epbak \
    app/backups/.backup_key --out=/tmp/alt.sql
mariadb -u … neue_datenbank < /tmp/alt.sql      # in eine NEUE Datenbank, dann prüfen
shred -u /tmp/alt.sql
```

Da das Alt-Format keine Integritätsprüfung hat, den Inhalt vor dem Einspielen prüfen.
Danach `.backup_key` offline sichern und vom Server entfernen; alte Cronjobs, die
`api/backup?action=cron_backup` aufrufen, löschen (der Endpunkt antwortet nicht mehr).
