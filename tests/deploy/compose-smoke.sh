#!/bin/sh
# OpsPilot – Deploymenttest mit Docker Compose (CI und lokal)
# ---------------------------------------------------------------------------
#   OPSPILOT_IMAGE=opspilot/app:ci sh tests/deploy/compose-smoke.sh
#
# Erwartet: gebautes Image (OPSPILOT_IMAGE), .env und secrets/ (docker/init-secrets.sh),
# inkl. secrets/backup_private.key für den Restore-Test.
# Ablauf: compose up → /api/readyz → Installation → Erstlogin mit Passwortwechsel
# (curl) → Backup über die Web-API → Backup + Restore-Test + Restore im Container
# → Neustart (Migrationen idempotent) → Abbau (nur mit KEEP=0, Standard).
set -eu

cd "$(dirname "$0")/../.."
PORT="${OPSPILOT_HTTP_PORT:-8080}"
BASE="http://127.0.0.1:$PORT"
DC="docker compose"
WORK=$(mktemp -d)
JAR="$WORK/cookies"
fail() { echo "FEHLER: $*" >&2; $DC ps >&2 || true; $DC logs --tail 80 >&2 || true; exit 1; }
step() { printf '\n== %s\n' "$*"; }
cleanup() {
    rm -rf "$WORK"
    if [ "${KEEP:-0}" != "1" ]; then $DC down -v --remove-orphans >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT
c() { curl -sS --noproxy '*' -c "$JAR" -b "$JAR" "$@"; }

[ -s secrets/backup_private.key ] || fail "secrets/backup_private.key fehlt (sh docker/init-secrets.sh)"

step "compose up"
$DC up -d --no-build

step "warte auf /api/readyz"
i=0
until [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$BASE/api/readyz")" = "200" ]; do
    i=$((i + 1)); [ $i -gt 90 ] && fail "readyz nicht bereit"
    sleep 2
done
curl -sS --noproxy '*' "$BASE/api/readyz"; echo
curl -sS --noproxy '*' "$BASE/api/healthz" | grep -q '"ok"' || fail "healthz"

step "Installation (erster Administrator)"
out=$($DC exec -T app php public/install.php)
echo "$out" | sed 's/Einmalpasswort: .*/Einmalpasswort: ***/'
otp=$(printf '%s\n' "$out" | sed -n 's/.*Einmalpasswort: \([^ ]*\).*/\1/p')
[ -n "$otp" ] || fail "kein Einmalpasswort"
$DC exec -T app php public/install.php >/dev/null 2>&1 && fail "zweite Installation darf nichts tun"

step "Erstlogin mit Passwortwechsel (curl)"
token=$(c "$BASE/" | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)
c -o "$WORK/login.html" --data-urlencode "username=admin" --data-urlencode "password=$otp" --data-urlencode "_token=$token" "$BASE/"
grep -q 'name="change_pw"' "$WORK/login.html" || fail "Passwortwechsel-Formular erwartet"
token=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$WORK/login.html" | head -1)
newpw="Ci-$(date +%s)-Passwort!"
loc=$(c -o /dev/null -w '%{redirect_url}' --data-urlencode "change_pw=1" --data-urlencode "new_password=$newpw" \
      --data-urlencode "new_password2=$newpw" --data-urlencode "_token=$token" "$BASE/")
case "$loc" in */dashboard) ;; *) fail "nach Passwortwechsel keine Weiterleitung zum Dashboard ($loc)";; esac
[ "$(c -o "$WORK/dash.html" -w '%{http_code}' "$BASE/dashboard")" = "200" ] || fail "Dashboard nicht erreichbar"
grep -q '<title>OpsPilot – Dashboard</title>' "$WORK/dash.html" || fail "Dashboard-Inhalt"

step "Backup über die Web-API (Einstellungen → Backup)"
csrf=$(c "$BASE/einstellungen" | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' | head -1)
c -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d '{"action":"create"}' "$BASE/api/backup" > "$WORK/create.json"
cat "$WORK/create.json"; echo
grep -q '"success":true' "$WORK/create.json" || fail "Web-Backup"
[ "$(c -o /dev/null -w '%{http_code}' "$BASE/api/backup?action=cron_backup&key=x")" != "200" ] || fail "Cron-Endpunkt muss entfernt sein"

step "Backup, Restore-Test und Restore im Container"
$DC exec -T backup php bin/backup.php run
$DC exec -T backup php bin/backup.php list
$DC exec -T backup php bin/backup.php restore-test --key=- < secrets/backup_private.key
latest=$($DC exec -T backup sh -c 'ls -1 /data/backups/default | grep -v prerestore | tail -1' | tr -d '\r')
$DC exec -T backup php bin/backup.php verify "$latest" --key=- < secrets/backup_private.key
$DC exec -T backup php bin/backup.php restore "$latest" --target-db=opspilot_ci_restore --files --key=- < secrets/backup_private.key > "$WORK/restore.json"
grep -q '"ok": true' "$WORK/restore.json" || fail "Restore: Zeilenzahlen"
if $DC exec -T backup php bin/backup.php restore "$latest" --target-db="${OPSPILOT_DB_NAME:-opspilot}" --key=- < secrets/backup_private.key >/dev/null 2>&1; then
    fail "Restore in nicht leere Produktiv-DB ohne --replace muss abgelehnt werden"
fi
$DC exec -T backup php bin/backup.php status --check > "$WORK/status.json" || fail "Backup-Status nicht gesund: $(cat "$WORK/status.json")"
$DC exec -T backup php bin/scheduler.php health || fail "Scheduler-Heartbeat"

step "Neustart: Migrationen idempotent, App wieder bereit"
$DC restart app
i=0
until [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$BASE/api/readyz")" = "200" ]; do
    i=$((i + 1)); [ $i -gt 60 ] && fail "readyz nach Neustart"
    sleep 2
done
[ "$(c -o /dev/null -w '%{http_code}' "$BASE/dashboard")" = "200" ] || fail "Session nach Neustart verloren (Sessions in /data)"

step "OK – Deploymenttest erfolgreich"
