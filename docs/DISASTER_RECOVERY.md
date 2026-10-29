# Disaster Recovery

Handbuch für den Ernstfall. Grundlagen (Schlüssel, Ziele, Befehle) stehen in
[BACKUP.md](BACKUP.md). Dieses Dokument ausgedruckt und offline (zusammen mit dem
Ablageort des privaten Schlüssels) verfügbar halten.

## Ziele

| Kennzahl | Ziel (Self-Hosting, Standardkonfiguration) | Bestimmt durch |
|---|---|---|
| **RPO** – maximaler Datenverlust | **24 h** (tägliches Backup 02:30); mit externem Ziel auch bei Totalverlust des Servers | `VIREO_BACKUP_TIME`; kürzer: zusätzliche Läufe per cron/Timer (z. B. 12:30), jeder Lauf ist ein vollständiges, konsistentes Backup |
| **RTO** – Zeit bis zum Wiederanlauf | **4 h** bei Serververlust (neuer Server + Restore), **1 h** bei Datenbankfehler auf intaktem Server | Vorbereitung dieses Handbuchs, Erreichbarkeit des Schlüssels, Restore-Tests |
| Erkennung | Alarm spätestens **26 h** nach dem letzten erfolgreichen Backup; fehlgeschlagene Läufe sofort | `VIREO_BACKUP_MAX_AGE_HOURS`, `VIREO_ALERT_*` |
| Nachweis | wöchentlicher automatischer Restore-Test, mindestens monatlich manuell | `restore-test` |

Wann ist der Plan erfüllt? Nur wenn (1) ein externes Ziel konfiguriert ist, (2) der private
Schlüssel an zwei Orten offline liegt und (3) der letzte Restore-Test erfolgreich und
höchstens 8 Tage alt ist. Alle drei Punkte zeigt *Einstellungen → Backup*.

## Rollen

| Rolle | Aufgabe | Wer (ausfüllen) |
|---|---|---|
| Incident-Verantwortliche/r | entscheidet über Restore, Wartungsmodus, Kommunikation; gibt `--replace` frei | |
| Technik (Betrieb) | führt die Schritte unten aus, dokumentiert Zeiten und Befehle | |
| Schlüsselverwahrer/in (2 Personen) | stellt den privaten Backup-Schlüssel bereit, nimmt ihn danach wieder an sich | |
| Fachliche Prüfung | prüft nach dem Restore Stichproben (aktueller Monatsplan, Abwesenheiten, Benutzer) | |
| Kommunikation | informiert Mitarbeitende/Kunden (Ausfallzeit, ggf. nachzutragende Änderungen) | |

Kontaktliste (Hoster, Speicheranbieter, Verantwortliche) separat offline führen.

## Allgemeiner Ablauf

1. **Feststellen und sichern:** Was ist betroffen (Server, DB, einzelner Mandant)? Seit
   wann? Keine voreiligen Löschungen; Logs sichern (`docker compose logs > incident.log`).
2. **Schreibzugriffe stoppen:** `docker compose stop app` (Backup-Dienst ggf. auch, damit
   ein defekter Stand nicht die Aufbewahrung „verdrängt“: `docker compose stop backup`).
3. **Wiederherstellungspunkt wählen:** `php bin/backup.php list` – letztes Archiv *vor*
   dem Schaden. Archiv mit `verify` prüfen.
4. **Wiederherstellen** in eine neue Datenbank, prüfen, dann tauschen (`--replace --yes`).
5. **Prüfen:** `/api/readyz`, Login, fachliche Stichprobe, `plan_change_log` für
   Änderungen nach dem Backup-Zeitpunkt (müssen ggf. nachgetragen werden).
6. **Wieder öffnen**, Ursache beheben, Bericht schreiben (Zeitpunkte → tatsächliches RPO/RTO).
7. **Nachsorge:** neues Backup, Restore-Test, privaten Schlüssel wieder offline verwahren.

## Szenario A – Server verloren (Hardware, Hoster, gelöscht)

Voraussetzung: externes Ziel (S3/rclone) und privater Schlüssel.

```bash
# 1. Neuen Server bereitstellen (INSTALLATION.md, Voraussetzungen), Repository/Version wie vorher
git clone https://github.com/<owner>/vireo.git /opt/vireo && cd /opt/vireo
git checkout <zuletzt eingesetzte Version>

# 2. Konfiguration wiederherstellen: .env (aus Doku/Passwortmanager) mit denselben
#    S3-/rclone-Einstellungen; Secrets neu erzeugen ist in Ordnung (DB wird neu angelegt),
#    den ÖFFENTLICHEN Backup-Schlüssel aber wieder einsetzen (oder neues Paar, dann
#    alten privaten Schlüssel für die alten Archive behalten)
sh docker/init-secrets.sh
cp /sicher/backup_public.key secrets/backup_public.key

# 3. Nur die Datenbank starten, dann App einmal starten (legt leeres Schema an)
docker compose up -d db
docker compose up -d app && docker compose stop app

# 4. Archive vom externen Ziel ansehen und das jüngste wiederherstellen
docker compose run --rm -T --no-deps backup php bin/backup.php list
docker compose run --rm -T --no-deps backup php bin/backup.php restore \
    vireo_default_<zeit>Z_scheduled.opbak --replace --yes --files --key=- < backup_private.key
#    (das Archiv wird automatisch vom externen Ziel geladen)

# 5. Starten und prüfen
docker compose --profile proxy up -d
curl -fsS https://plan.example.de/api/readyz
```

DNS auf den neuen Server umstellen (TTL vorab niedrig halten). Danach sofort ein neues
Backup (`run`) und `restore-test`.

## Szenario B – Datenbank beschädigt oder Daten versehentlich gelöscht

Server und Volumes sind intakt.

```bash
docker compose stop app
docker compose exec -T backup php bin/backup.php list
# erst in eine Prüf-Datenbank …
docker compose exec -T backup php bin/backup.php restore <archiv> --target-db=vireo_check --key=- < backup_private.key
#    … prüfen (z. B. mariadb-Client: SELECT COUNT(*) …), dann ersetzen:
docker compose exec -T backup php bin/backup.php restore <archiv> --replace --yes --key=- < backup_private.key
docker compose start app
```

Nur einzelne Datensätze zurückholen: Prüf-Datenbank wie oben, betroffene Zeilen gezielt
zurückkopieren (`INSERT … SELECT` von `vireo_check` nach `vireo`), Produktion nicht
tauschen. Änderungen seit dem Backup zeigt `plan_change_log`.

InnoDB startet nicht mehr (MariaDB-Logs): Volume `db-data` nicht löschen, sondern Container
stoppen, Volume sichern (`docker run --rm -v vireo_db-data:/v -v $PWD:/b alpine tar czf /b/db-data.tgz /v`),
neues Volume anlegen und wie Szenario A ab Schritt 3 vorgehen.

## Szenario C – Ransomware / kompromittierter Server

Annahme: Angreifer hatte Root auf dem Server. Er kennt dann den öffentlichen Schlüssel
und die Zugangsdaten der externen Ziele, aber **nicht** den privaten Schlüssel – er kann
Archive nicht lesen, wohl aber löschen oder neue (manipulierte) hochladen.

1. Server vom Netz nehmen, **nicht** wiederverwenden. Forensik-Kopie, falls nötig.
2. Zugangsdaten sperren: S3-Schlüssel/rclone-Passwörter rotieren, DB-Passwörter,
   OIDC-Secrets, API-Tokens (Tabelle der App-Tokens nach dem Restore widerrufen).
3. Sauberen Wiederherstellungspunkt bestimmen: Archiv deutlich *vor* dem ersten Hinweis
   auf den Angriff. Mit Object Lock/Versionierung im Bucket sind gelöschte Archive
   wiederherstellbar – deshalb empfohlen.
4. Neuer Server wie Szenario A; vor dem Restore jedes Kandidaten-Archiv mit `verify`
   prüfen (Authentisierung schlägt bei manipulierten Archiven fehl; ein Angreifer mit dem
   öffentlichen Schlüssel kann aber *gültige* Archive mit falschem Inhalt erzeugen –
   daher Zeitpunkt und Herkunft prüfen und fachliche Stichprobe machen).
5. Neues Backup-Schlüsselpaar, neue Secrets, alle Benutzer zum Passwortwechsel zwingen.
6. Meldepflichten prüfen (DSGVO Art. 33: 72 Stunden).

## Szenario D – Privater Schlüssel verloren

Vorhandene Archive sind **nicht** mehr lesbar; es gibt keine Hintertür.

1. Sofort neues Schlüsselpaar erzeugen (`php bin/backup.php keygen`), öffentlichen
   Schlüssel auf dem Server austauschen, Backup-Dienst neu starten.
2. Sofort `run` und `restore-test` mit dem neuen Schlüssel.
3. Die alten Archive sind wertlos – bis genügend neue Archive vorhanden sind, ist die
   Historie verloren (RPO für Fehler in der Vergangenheit: bis zum neuen Schlüssel).
4. Ursache klären, Verwahrung verbessern (zwei Orte, zwei Personen).

Wurde der private Schlüssel **offengelegt** (statt verloren): ebenfalls neues Paar; alte
Archive auf den externen Zielen nach Ablauf der Aufbewahrung verfallen lassen oder löschen.

## Szenario E – Einzelner Mandant (SaaS)

Ein Mandant meldet fehlerhafte Daten; andere Mandanten sind nicht betroffen. Siehe
[BACKUP.md – Einzelnen Mandanten wiederherstellen](BACKUP.md#einzelnen-mandanten-wiederherstellen-saas):
`restore <archiv> --tenant=<slug> --target-db=…` zur Prüfung, danach `--replace --yes`.
Mandanten vorher sperren (`php bin/tenant.php suspend <slug>`), danach `activate`.

## Szenario F – Fehlgeschlagenes Update

Siehe [UPDATE.md – Rollback](UPDATE.md#rollback).

## Übungen

- **Wöchentlich (automatisch):** Restore-Test (Ergebnis in den Einstellungen).
- **Quartalsweise:** Szenario B auf einem Testsystem komplett durchspielen, Zeiten messen.
- **Jährlich:** Szenario A mit einem frischen Server, Schlüssel aus der Offline-Verwahrung,
  nur mit diesem Handbuch. Abweichungen hier eintragen.

| Datum | Übung | Dauer | Ergebnis / Abweichungen | Durchgeführt von |
|---|---|---|---|---|
| | | | | |
