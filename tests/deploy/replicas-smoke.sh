#!/bin/sh
# Planquadro – Deploymenttest: zwei App-Replikate hinter Caddy mit DB-Sessions
# ---------------------------------------------------------------------------
#   PQ_IMAGE=planquadro/app:ci sh tests/deploy/replicas-smoke.sh
#
# Erwartet wie compose-smoke.sh: gebautes Image, .env und secrets/.
# Ablauf: frischer Stack mit docker-compose.replicas.yml (2 Replikate,
# PQ_SESSION_STORE=db, Caddy Round Robin) → Installation → Login mit
# Passwortwechsel über den Proxy → 20 Requests: alle angemeldet, beide
# Replikate haben geantwortet → Session-Tabelle enthält nur Hashes → Logout
# gilt in beiden Replikaten → Abbau (nur mit KEEP=0, Standard).
set -eu

cd "$(dirname "$0")/../.."
DC="docker compose -f docker-compose.yml -f docker-compose.replicas.yml --profile proxy"
BASE="https://localhost"
WORK=$(mktemp -d)
JAR="$WORK/cookies"
fail() { echo "FEHLER: $*" >&2; $DC ps >&2 || true; $DC logs --tail 80 >&2 || true; exit 1; }
step() { printf '\n== %s\n' "$*"; }
cleanup() {
    rm -rf "$WORK"
    if [ "${KEEP:-0}" != "1" ]; then $DC down -v --remove-orphans >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT
c() { curl -sSk --noproxy '*' -c "$JAR" -b "$JAR" "$@"; }

step "frischer Stack mit 2 App-Replikaten"
$DC down -v --remove-orphans >/dev/null 2>&1 || true
$DC up -d --no-build
i=0
until [ "$(curl -sk --noproxy '*' -o /dev/null -w '%{http_code}' "$BASE/api/readyz")" = "200" ]; do
    i=$((i + 1)); [ $i -gt 90 ] && fail "readyz über den Proxy nicht bereit"
    sleep 2
done
n=$($DC ps -q app | wc -l | tr -d ' ')
[ "$n" = "2" ] || fail "erwartet 2 App-Replikate, gefunden: $n"

step "Installation und Login über den Load Balancer"
out=$($DC exec -T --index 1 app php public/install.php)
otp=$(printf '%s\n' "$out" | sed -n 's/.*Einmalpasswort: \([^ ]*\).*/\1/p')
[ -n "$otp" ] || fail "kein Einmalpasswort"
token=$(c "$BASE/" | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)
c -o "$WORK/login.html" --data-urlencode "username=admin" --data-urlencode "password=$otp" --data-urlencode "_token=$token" "$BASE/"
grep -q 'name="change_pw"' "$WORK/login.html" || fail "Passwortwechsel-Formular erwartet (CSRF/Sitzung über Replikate?)"
token=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$WORK/login.html" | head -1)
newpw="Ci-$(date +%s)-Passwort!"
loc=$(c -o /dev/null -w '%{redirect_url}' --data-urlencode "change_pw=1" --data-urlencode "new_password=$newpw" \
      --data-urlencode "new_password2=$newpw" --data-urlencode "_token=$token" "$BASE/")
case "$loc" in */dashboard) ;; *) fail "nach Passwortwechsel keine Weiterleitung zum Dashboard ($loc)";; esac

step "20 Requests: Sitzung gilt in jedem Replikat"
: > "$WORK/upstreams"
k=0
while [ $k -lt 20 ]; do
    k=$((k + 1))
    code=$(c -D "$WORK/h" -o "$WORK/dash.html" -w '%{http_code}' "$BASE/dashboard")
    [ "$code" = "200" ] || fail "Request $k: HTTP $code (Sitzung im anderen Replikat unbekannt?)"
    grep -q '<title>Planquadro – Dashboard</title>' "$WORK/dash.html" || fail "Request $k: kein Dashboard"
    sed -n 's/^[Xx]-[Pp][Qq]-[Uu]pstream: *\([^[:space:]]*\).*/\1/p' "$WORK/h" >> "$WORK/upstreams"
done
distinct=$(sort -u "$WORK/upstreams" | wc -l | tr -d ' ')
sort "$WORK/upstreams" | uniq -c
[ "$distinct" -ge 2 ] || fail "nur $distinct Replikat(e) haben geantwortet"

step "Session-Tabelle: nur SHA-256-Hashes"
sid=$(awk '$6 == "PHPSESSID" { print $7 }' "$JAR" | tail -1)
[ -n "$sid" ] || fail "kein Session-Cookie"
rows=$($DC exec -T db sh -c 'mariadb -uroot -p"$(cat /run/secrets/db_root_password)" -N -e "SELECT id_hash FROM sessions" "${MARIADB_DATABASE}"')
printf '%s\n' "$rows" | grep -qv '^[0-9a-f]\{64\}$' && fail "Session-Tabelle enthält etwas anderes als Hashes"
printf '%s\n' "$rows" | grep -q "$sid" && fail "Klartext-Session-ID in der Tabelle"
hash=$(printf '%s' "$sid" | sha256sum | cut -d' ' -f1)
printf '%s\n' "$rows" | grep -q "^$hash\$" || fail "Hash der Sitzung fehlt in der Tabelle"

step "Logout gilt in allen Replikaten"
[ "$(c -o /dev/null -w '%{http_code}' "$BASE/logout")" = "302" ] || fail "Logout"
k=0
while [ $k -lt 6 ]; do
    k=$((k + 1))
    [ "$(curl -sSk --noproxy '*' -b "PHPSESSID=$sid" -o /dev/null -w '%{http_code}' "$BASE/dashboard")" = "302" ] || fail "abgemeldete Sitzung gilt noch (Request $k)"
done

step "OK – Replikat-Test erfolgreich"
