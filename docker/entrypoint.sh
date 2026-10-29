#!/bin/sh
# Planquadro – Container-Entrypoint (POSIX sh)
# ---------------------------------------------------------------------------
# 1. Reverse-Proxy-Vertrauen für Apache schreiben (PQ_TRUSTED_PROXIES)
# 2. auf die Datenbank warten
# 3. Migrationen unter Sperre (nur Web-Container; PQ_RUN_MIGRATIONS)
# 4. Befehl starten (Standard: apache2-foreground)
#
# PQ_RUN_MIGRATIONS  1 = migrieren (Standard für apache2-foreground), 0 = nie
# PQ_WAIT_FOR_DB     1 = auf DB warten (Standard), 0 = sofort starten
# PQ_AUTO_BASELINE   nur für bestehende Altinstallationen, z. B. v6_9_0
set -eu


APP=/var/www/html
cd "$APP"

log() { printf '[planquadro] %s\n' "$*"; }

# --- 1. Vertrauenswürdige Proxys: HTTPS=on nur, wenn die Anfrage von einem
#        dieser Proxys kommt und X-Forwarded-Proto: https trägt.
RUNTIME_CONF=/etc/apache2/conf-enabled/zz-planquadro-runtime.conf
if [ -w "$RUNTIME_CONF" ]; then
    proxies="${PQ_TRUSTED_PROXIES:-}"
    if [ -n "$proxies" ]; then
        expr=""
        old_ifs=$IFS; IFS=','
        for p in $proxies; do
            p=$(printf '%s' "$p" | tr -d ' ')
            [ -z "$p" ] && continue
            case "$p" in
                *[!0-9A-Fa-f:./]*) log "WARNUNG: ungültiger Eintrag in PQ_TRUSTED_PROXIES ignoriert: $p"; continue ;;
            esac
            if [ -n "$expr" ]; then expr="$expr || "; fi
            expr="$expr-R '$p'"
        done
        IFS=$old_ifs
        if [ -n "$expr" ]; then
            printf '<If "%%{HTTP:X-Forwarded-Proto} == '"'"'https'"'"' && (%s)">\n    SetEnv HTTPS on\n</If>\n' "$expr" > "$RUNTIME_CONF"
        else
            : > "$RUNTIME_CONF"
        fi
    else
        : > "$RUNTIME_CONF"
    fi
fi

# --- PQ_APP_KEY_FILE: die Anwendung liest PQ_APP_KEY direkt per
#     getenv(); alle übrigen Variablen unterstützen *_FILE selbst (opsEnv()).
if [ -z "${PQ_APP_KEY:-}" ] && [ -n "${PQ_APP_KEY_FILE:-}" ]; then
    if [ -r "$PQ_APP_KEY_FILE" ]; then
        PQ_APP_KEY=$(cat "$PQ_APP_KEY_FILE")
        export PQ_APP_KEY
    else
        log "WARNUNG: PQ_APP_KEY_FILE ist nicht lesbar."
    fi
fi

# --- Hinweise zur Konfiguration (keine Werte ausgeben)
if [ -z "${PQ_DB_PASS:-}" ] && [ -z "${PQ_DB_PASS_FILE:-}" ]; then
    log "WARNUNG: weder PQ_DB_PASS noch PQ_DB_PASS_FILE gesetzt."
fi
if [ -z "${PQ_BACKUP_PUBLIC_KEY:-}" ] && [ -z "${PQ_BACKUP_PUBLIC_KEY_FILE:-}" ]; then
    log "WARNUNG: kein öffentlicher Backup-Schlüssel (PQ_BACKUP_PUBLIC_KEY_FILE) – Backups sind deaktiviert."
fi
if [ -n "${PQ_BACKUP_PRIVATE_KEY_FILE:-}${PQ_BACKUP_PRIVATE_KEY:-}" ]; then
    log "Hinweis: privater Backup-Schlüssel ist in diesem Container verfügbar (nur für Restore-Tests nötig, siehe docs/BACKUP.md)."
fi

if [ -n "${PQ_ALERT_EMAIL:-}" ] && [ -z "${PQ_SMTP_HOST:-}" ] && [ ! -x /usr/sbin/sendmail ]; then
    log "WARNUNG: PQ_ALERT_EMAIL gesetzt, aber kein PQ_SMTP_HOST (und kein sendmail im Image) – Mail-Alarme werden übersprungen. Test: php bin/backup.php alert-test"
fi
if [ -n "${PQ_SMTP_HOST:-}" ] && [ "${PQ_SMTP_TLS:-starttls}" = "none" ] && [ -n "${PQ_SMTP_USER:-}" ]; then
    log "WARNUNG: PQ_SMTP_TLS=none mit PQ_SMTP_USER – Zugangsdaten gehen unverschlüsselt über das Netz."
fi
case "${PQ_SESSION_STORE:-files}" in
    files|db) ;;
    *) log "WARNUNG: unbekannter Wert PQ_SESSION_STORE=${PQ_SESSION_STORE} – es werden Dateisessions verwendet." ;;
esac

mkdir -p /data/storage /data/backups /data/sessions 2>/dev/null || true

# --- 2./3. Datenbank und Migrationen
first="${1:-}"
default_migrate=0
[ "$first" = "apache2-foreground" ] && default_migrate=1

if [ "${PQ_WAIT_FOR_DB:-1}" = "1" ]; then
    php docker/bootstrap.php wait-db
fi
if [ "${PQ_RUN_MIGRATIONS:-$default_migrate}" = "1" ]; then
    php docker/bootstrap.php migrate
fi

# --- 4. Start
exec "$@"
