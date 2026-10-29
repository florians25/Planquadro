#!/bin/sh
# Vireo – Container-Entrypoint (POSIX sh)
# ---------------------------------------------------------------------------
# 1. Reverse-Proxy-Vertrauen für Apache schreiben (VIREO_TRUSTED_PROXIES)
# 2. auf die Datenbank warten
# 3. Migrationen unter Sperre (nur Web-Container; VIREO_RUN_MIGRATIONS)
# 4. Befehl starten (Standard: apache2-foreground)
#
# VIREO_RUN_MIGRATIONS  1 = migrieren (Standard für apache2-foreground), 0 = nie
# VIREO_WAIT_FOR_DB     1 = auf DB warten (Standard), 0 = sofort starten
# VIREO_AUTO_BASELINE   nur für bestehende Altinstallationen, z. B. v6_9_0
set -eu

# Alte Variablennamen aus der Entwicklungsphase (OPSPILOT_*) gelten weiter,
# solange die neue VIREO_*-Variable nicht gesetzt ist.
for __v in $(env | sed -n 's/^OPSPILOT_\([A-Za-z0-9_]*\)=.*/\1/p'); do
    eval "[ -n \"\${VIREO_$__v+x}\" ] || export VIREO_$__v=\"\${OPSPILOT_$__v}\""
done
unset __v

APP=/var/www/html
cd "$APP"

log() { printf '[vireo] %s\n' "$*"; }

# --- 1. Vertrauenswürdige Proxys: HTTPS=on nur, wenn die Anfrage von einem
#        dieser Proxys kommt und X-Forwarded-Proto: https trägt.
RUNTIME_CONF=/etc/apache2/conf-enabled/zz-vireo-runtime.conf
if [ -w "$RUNTIME_CONF" ]; then
    proxies="${VIREO_TRUSTED_PROXIES:-}"
    if [ -n "$proxies" ]; then
        expr=""
        old_ifs=$IFS; IFS=','
        for p in $proxies; do
            p=$(printf '%s' "$p" | tr -d ' ')
            [ -z "$p" ] && continue
            case "$p" in
                *[!0-9A-Fa-f:./]*) log "WARNUNG: ungültiger Eintrag in VIREO_TRUSTED_PROXIES ignoriert: $p"; continue ;;
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

# --- VIREO_APP_KEY_FILE: die Anwendung liest VIREO_APP_KEY direkt per
#     getenv(); alle übrigen Variablen unterstützen *_FILE selbst (opsEnv()).
if [ -z "${VIREO_APP_KEY:-}" ] && [ -n "${VIREO_APP_KEY_FILE:-}" ]; then
    if [ -r "$VIREO_APP_KEY_FILE" ]; then
        VIREO_APP_KEY=$(cat "$VIREO_APP_KEY_FILE")
        export VIREO_APP_KEY
    else
        log "WARNUNG: VIREO_APP_KEY_FILE ist nicht lesbar."
    fi
fi

# --- Hinweise zur Konfiguration (keine Werte ausgeben)
if [ -z "${VIREO_DB_PASS:-}" ] && [ -z "${VIREO_DB_PASS_FILE:-}" ]; then
    log "WARNUNG: weder VIREO_DB_PASS noch VIREO_DB_PASS_FILE gesetzt."
fi
if [ -z "${VIREO_BACKUP_PUBLIC_KEY:-}" ] && [ -z "${VIREO_BACKUP_PUBLIC_KEY_FILE:-}" ]; then
    log "WARNUNG: kein öffentlicher Backup-Schlüssel (VIREO_BACKUP_PUBLIC_KEY_FILE) – Backups sind deaktiviert."
fi
if [ -n "${VIREO_BACKUP_PRIVATE_KEY_FILE:-}${VIREO_BACKUP_PRIVATE_KEY:-}" ]; then
    log "Hinweis: privater Backup-Schlüssel ist in diesem Container verfügbar (nur für Restore-Tests nötig, siehe docs/BACKUP.md)."
fi

mkdir -p /data/storage /data/backups /data/sessions 2>/dev/null || true

# --- 2./3. Datenbank und Migrationen
first="${1:-}"
default_migrate=0
[ "$first" = "apache2-foreground" ] && default_migrate=1

if [ "${VIREO_WAIT_FOR_DB:-1}" = "1" ]; then
    php docker/bootstrap.php wait-db
fi
if [ "${VIREO_RUN_MIGRATIONS:-$default_migrate}" = "1" ]; then
    php docker/bootstrap.php migrate
fi

# --- 4. Start
exec "$@"
