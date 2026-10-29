#!/usr/bin/env bash
# Screenshots der Apple-App im Demo-Modus (ohne Backend), hell und dunkel.
#
#   apple/scripts/screenshots.sh <Planquadro.app> <Simulator-UDID> <Präfix> <Zielordner>
#
# Startet die App je Bildschirm mit -PQDemo YES -PQScreen <name> (nur Debug-Build),
# säubert die Statusleiste (9:41, volles WLAN/Akku) und speichert PNGs als
# <Präfix>-<bildschirm>-<hell|dunkel>.png. Die Widgets erscheinen über die
# Widget-Vorschau der App (Home-Bildschirm lässt sich im CI nicht bestücken).
set -euo pipefail

APP="$1"
UDID="$2"
PREFIX="$3"
OUT="$4"
TODAY="${PQ_SCREENSHOT_TODAY:-2026-11-04}"
SCREENS="${PQ_SCREENS:-week plan detail team absences request notifications more widgets search login setup}"
WAIT="${PQ_SCREENSHOT_WAIT:-7}"

mkdir -p "$OUT"
BUNDLE_ID=$(/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$APP/Info.plist")

xcrun simctl boot "$UDID" 2>/dev/null || true
xcrun simctl bootstatus "$UDID" -b
# Statusleiste wie in Apple-Marketingbildern
xcrun simctl status_bar "$UDID" override --time "9:41" --dataNetwork wifi --wifiMode active --wifiBars 3 \
  --batteryState charged --batteryLevel 100 --cellularMode active --cellularBars 4 2>/dev/null \
  || xcrun simctl status_bar "$UDID" override --time "9:41" --batteryState charged --batteryLevel 100 || true
xcrun simctl install "$UDID" "$APP"
# Benachrichtigungen vorab erlauben (falls doch eine Abfrage käme)
xcrun simctl privacy "$UDID" grant notifications "$BUNDLE_ID" 2>/dev/null || true

for appearance in light dark; do
  xcrun simctl ui "$UDID" appearance "$appearance"
  label=$([ "$appearance" = light ] && echo hell || echo dunkel)
  for screen in $SCREENS; do
    xcrun simctl terminate "$UDID" "$BUNDLE_ID" 2>/dev/null || true
    if [ "$screen" = setup ]; then
      args=(-PQReset YES -PQScreen setup -PQNoAnimations YES)
    else
      args=(-PQDemo YES -PQReset YES -PQScreen "$screen" -PQToday "$TODAY" -PQNoAnimations YES)
    fi
    xcrun simctl launch "$UDID" "$BUNDLE_ID" "${args[@]}" >/dev/null
    sleep "$WAIT"
    file="$OUT/${PREFIX}-${screen}-${label}.png"
    xcrun simctl io "$UDID" screenshot --type=png "$file" >/dev/null 2>&1
    echo "Screenshot: $file"
  done
done
xcrun simctl terminate "$UDID" "$BUNDLE_ID" 2>/dev/null || true
xcrun simctl status_bar "$UDID" clear || true
