#!/bin/sh
# OpsPilot – Container-Entrypoint (POSIX sh)
# ---------------------------------------------------------------------------
# 1. Reverse-Proxy-Vertrauen für Apache schreiben (OPSPILOT_TRUSTED_PROXIES)
# 2. auf die Datenbank warten
# 3. Migrationen unter Sperre (nur Web-Container; OPSPILOT_RUN_MIGRATIONS)
# 4. Befehl starten (Standard: apache2-foreground)
#
# OPSPILOT_RUN_MIGRATIONS  1 = migrieren (Standard für apache2-foreground), 0 = nie
# OPSPILOT_WAIT_FOR_DB     1 = auf DB warten (Standard), 0 = sofort starten
# OPSPILOT_AUTO_BASELINE   nur für bestehende Altinstallationen, z. B. v6_9_0
set -eu

APP=/var/www/html
cd "$APP"

log() { printf '[opspilot] %s\n' "$*"; }

# --- 1. Vertrauenswürdige Proxys: HTTPS=on nur, wenn die Anfrage von einem
#        dieser Proxys kommt und X-Forwarded-Proto: https trägt.
RUNTIME_CONF=/etc/apache2/conf-enabled/zz-opspilot-runtime.conf
if [ -w "$RUNTIME_CONF" ]; then
    proxies="${OPSPILOT_TRUSTED_PROXIES:-}"
    if [ -n "$proxies" ]; then
        expr=""
        old_ifs=$IFS; IFS=','
        for p in $proxies; do
            p=$(printf '%s' "$p" | tr -d ' ')
            [ -z "$p" ] && continue
            case "$p" in
                *[!0-9A-Fa-f:./]*) log "WARNUNG: ungültiger Eintrag in OPSPILOT_TRUSTED_PROXIES ignoriert: $p"; continue ;;
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

# --- OPSPILOT_APP_KEY_FILE: die Anwendung liest OPSPILOT_APP_KEY direkt per
#     getenv(); alle übrigen Variablen unterstützen *_FILE selbst (opsEnv()).
if [ -z "${OPSPILOT_APP_KEY:-}" ] && [ -n "${OPSPILOT_APP_KEY_FILE:-}" ]; then
    if [ -r "$OPSPILOT_APP_KEY_FILE" ]; then
        OPSPILOT_APP_KEY=$(cat "$OPSPILOT_APP_KEY_FILE")
        export OPSPILOT_APP_KEY
    else
        log "WARNUNG: OPSPILOT_APP_KEY_FILE ist nicht lesbar."
    fi
fi

# --- Hinweise zur Konfiguration (keine Werte ausgeben)
if [ -z "${OPSPILOT_DB_PASS:-}" ] && [ -z "${OPSPILOT_DB_PASS_FILE:-}" ]; then
    log "WARNUNG: weder OPSPILOT_DB_PASS noch OPSPILOT_DB_PASS_FILE gesetzt."
fi
if [ -z "${OPSPILOT_BACKUP_PUBLIC_KEY:-}" ] && [ -z "${OPSPILOT_BACKUP_PUBLIC_KEY_FILE:-}" ]; then
    log "WARNUNG: kein öffentlicher Backup-Schlüssel (OPSPILOT_BACKUP_PUBLIC_KEY_FILE) – Backups sind deaktiviert."
fi
if [ -n "${OPSPILOT_BACKUP_PRIVATE_KEY_FILE:-}${OPSPILOT_BACKUP_PRIVATE_KEY:-}" ]; then
    log "Hinweis: privater Backup-Schlüssel ist in diesem Container verfügbar (nur für Restore-Tests nötig, siehe docs/BACKUP.md)."
fi

mkdir -p /data/storage /data/backups /data/sessions 2>/dev/null || true

# --- 2./3. Datenbank und Migrationen
first="${1:-}"
default_migrate=0
[ "$first" = "apache2-foreground" ] && default_migrate=1

if [ "${OPSPILOT_WAIT_FOR_DB:-1}" = "1" ]; then
    php docker/bootstrap.php wait-db
fi
if [ "${OPSPILOT_RUN_MIGRATIONS:-$default_migrate}" = "1" ]; then
    php docker/bootstrap.php migrate
fi

# --- 4. Start
exec "$@"
