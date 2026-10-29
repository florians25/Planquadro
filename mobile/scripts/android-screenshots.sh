#!/usr/bin/env bash
# Erstellt Screenshots der laufenden nativen Android-App im Emulator (CI: .github/workflows/apps.yml).
#
#   bash scripts/android-screenshots.sh <screenshot-demo.apk> <ausgabeordner>
#
# Voraussetzung: laufender Emulator (adb), APK aus einem Web-Build mit VITE_SCREENSHOT_DEMO=1
# (Demo-Daten ohne Backend, automatische Anmeldung, Deep Links mit ?theme=). Kein UI-Testframework:
# Bildschirme werden per Deep Link (am start -d planquadro://screen/…) geöffnet.
set -euo pipefail

APK="${1:?APK fehlt}"
OUT="${2:?Ausgabeordner fehlt}"
PKG="de.planquadro.app"
FIRST_WAIT="${FIRST_WAIT:-20}"
WAIT="${WAIT:-5}"
mkdir -p "$OUT"

fail() {
  echo "::error::$*"
  adb logcat -d -t 2000 > "$OUT/logcat.txt" 2>/dev/null || true
  adb exec-out screencap -p > "$OUT/fehler-letzter-bildschirm.png" 2>/dev/null || true
  exit 1
}

echo "Warte auf Emulator …"
adb wait-for-device
for _ in $(seq 1 90); do
  [ "$(adb shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')" = "1" ] && break
  sleep 2
done
[ "$(adb shell getprop sys.boot_completed | tr -d '\r')" = "1" ] || fail "Emulator ist nicht hochgefahren."
adb shell input keyevent 82 || true   # Sperrbildschirm weg

echo "Installiere $APK"
# -g: Laufzeitberechtigungen vorab erteilen (keine Dialoge über den Screenshots)
adb install -r -g "$APK" >/dev/null || fail "APK konnte nicht installiert werden."

# Aufgeräumte Statusleiste (System-UI-Demo-Modus): 9:41, volles Netz/Akku, keine Symbole
adb shell settings put global sysui_demo_allowed 1 || true
demo() { adb shell am broadcast -a com.android.systemui.demo -e command "$@" >/dev/null || true; }
demo enter
demo clock -e hhmm 0941
demo network -e wifi show -e level 4 -e fully true
demo network -e mobile show -e datatype none -e level 4
demo battery -e level 100 -e plugged false
demo notifications -e visible false

resumed() {
  adb shell dumpsys activity activities 2>/dev/null | grep -E "mResumedActivity|topResumedActivity|ResumedActivity" | grep -q "$PKG"
}

check_png() {
  local f="$1"
  [ -s "$f" ] || fail "Screenshot $f ist leer."
  local size
  size=$(wc -c < "$f")
  [ "$size" -gt 20000 ] || fail "Screenshot $f ist verdächtig klein ($size Bytes)."
  head -c 8 "$f" | od -An -tx1 | tr -d ' \n' | grep -q '^89504e470d0a1a0a' || fail "$f ist kein PNG."
}

shot() { # name deep-link [wartezeit]
  local name="$1" url="$2" wait="${3:-$WAIT}"
  adb shell "am start -W -a android.intent.action.VIEW -d '$url' $PKG" >/dev/null || fail "Deep Link $url konnte nicht geöffnet werden."
  sleep "$wait"
  resumed || fail "App ist nach $url nicht im Vordergrund."
  adb exec-out screencap -p > "$OUT/$name.png"
  check_png "$OUT/$name.png"
  echo "  ✓ $name.png"
}

for theme in light dark; do
  echo "Farbschema: $theme"
  if [ "$theme" = dark ]; then adb shell cmd uimode night yes >/dev/null; else adb shell cmd uimode night no >/dev/null; fi
  adb shell am force-stop "$PKG"
  # Kaltstart: Demo-Anmeldung, erster Sync, Widget-Daten werden geschrieben
  shot "android-plan-$theme" "planquadro://screen/plan?theme=$theme" "$FIRST_WAIT"
  shot "android-tag-$theme" "planquadro://screen/tag?theme=$theme"
  shot "android-team-$theme" "planquadro://screen/team?theme=$theme"
  shot "android-abwesenheiten-$theme" "planquadro://screen/abwesenheiten?theme=$theme"
  shot "android-mitteilungen-$theme" "planquadro://screen/mitteilungen?theme=$theme"
  shot "android-mehr-$theme" "planquadro://screen/mehr?theme=$theme"

  # Home-Screen-Widget: dieselben RemoteViews in der Debug-Vorschau (siehe WidgetPreviewActivity.kt)
  adb shell am start -W -n "$PKG/.widget.WidgetPreviewActivity" >/dev/null || fail "Widget-Vorschau nicht startbar."
  sleep 3
  adb exec-out screencap -p > "$OUT/android-widget-$theme.png"
  check_png "$OUT/android-widget-$theme.png"
  echo "  ✓ android-widget-$theme.png"
done

demo exit
adb shell cmd uimode night no >/dev/null || true
ls -la "$OUT"
