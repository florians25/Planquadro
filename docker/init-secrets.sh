#!/bin/sh
# OpsPilot – Secrets für docker-compose.yml erzeugen (einmalig, POSIX sh)
# ---------------------------------------------------------------------------
#   sh docker/init-secrets.sh            legt ./secrets/ an (vorhandene Dateien bleiben)
#
# Erzeugt:
#   secrets/db_password, secrets/db_root_password, secrets/app_key   Zufallswerte
#   secrets/backup_public.key     öffentlicher Backup-Schlüssel (bleibt auf dem Server)
#   secrets/backup_private.key    PRIVATER Schlüssel – sofort offline sichern und
#                                 danach vom Server löschen (siehe Ausgabe)
# Benötigt openssl sowie PHP mit sodium – oder Docker (dann wird das Image genutzt).
set -eu

cd "$(dirname "$0")/.."
DIR=secrets
umask 077
mkdir -p "$DIR"
chmod 0700 "$DIR"

rand() { openssl rand -base64 48 | tr -d '\n/+=' | cut -c1-40; }

for f in db_password db_root_password app_key; do
    if [ ! -s "$DIR/$f" ]; then
        if [ "$f" = app_key ]; then openssl rand -hex 32 > "$DIR/$f"; else rand > "$DIR/$f"; fi
        echo "erzeugt: $DIR/$f"
    fi
done

if [ ! -s "$DIR/backup_public.key" ]; then
    out=""
    if command -v php >/dev/null 2>&1 && php -r 'exit(function_exists("sodium_crypto_box_keypair") ? 0 : 1);' 2>/dev/null; then
        out=$(php bin/backup.php keygen)
    elif command -v docker >/dev/null 2>&1; then
        out=$(docker run --rm --entrypoint php "${OPSPILOT_IMAGE:-opspilot/app:latest}" bin/backup.php keygen)
    else
        echo "Weder PHP (mit sodium) noch Docker gefunden – Schlüssel bitte manuell erzeugen: php bin/backup.php keygen" >&2
        exit 1
    fi
    printf '%s\n' "$out" | grep '^opspilot-backup-pk-v1:' > "$DIR/backup_public.key"
    printf '%s\n' "$out" | grep '^opspilot-backup-sk-v1:' > "$DIR/backup_private.key"
    chmod 0600 "$DIR/backup_private.key"
    echo "erzeugt: $DIR/backup_public.key"
    echo
    echo "=========================================================================="
    echo " PRIVATER BACKUP-SCHLÜSSEL: $DIR/backup_private.key"
    echo " Ohne ihn lassen sich Backups NICHT wiederherstellen."
    echo " 1. Jetzt offline sichern (Passwortmanager, verschlüsselter USB-Stick, Ausdruck"
    echo "    im Tresor – mindestens zwei Orte, nicht auf diesem Server)."
    echo " 2. Danach vom Server löschen:  shred -u $DIR/backup_private.key"
    echo "=========================================================================="
fi

# Der Container läuft als www-data (uid 33) und liest die Dateien über Bind-Mounts.
# Das Verzeichnis bleibt 0700 (nur der Eigentümer auf dem Host), die Dateien selbst
# müssen im Container lesbar sein.
chmod 0644 "$DIR/db_password" "$DIR/db_root_password" "$DIR/app_key" "$DIR/backup_public.key"
echo "Fertig. Secrets in $DIR/ (nicht ins Git, nicht ins Backup-Ziel kopieren)."
