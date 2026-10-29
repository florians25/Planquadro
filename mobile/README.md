# Planquadro App (iOS, Android, PWA)

Mobile App für Mitarbeitende: eigener Plan, Einsatzdetails, Teamübersicht, Abwesenheiten,
Mitteilungen – mit Offline-Lesemodus, Push und optionaler biometrischer Entsperrung.

Eine Codebasis (Vite + TypeScript, kein UI-Framework) erzeugt die **PWA** für den Browser
und die **iOS-/Android-Apps** über **Capacitor 8** (Entscheidung siehe
`docs/ARCHITECTURE.md`, Abschnitt B.8). Die App spricht ausschließlich die **API v1**
(`docs/API.md`, verbindlicher Vertrag).

## Schnellstart im Browser mit Demo-Daten

```bash
cd mobile
npm ci
npm run dev            # http://localhost:5173/?mock=1
```

- `?mock=1` oder auf dem Einrichtungsbildschirm **„Demo-Modus starten (ohne Server)“**
  schaltet auf einen eingebauten Fake-Server (`src/api/mock.ts` + `src/api/fixtures/*.json`).
  Der echte API-Client (Header, Fehlerformat, Token-Refresh) läuft dabei vollständig mit.
- Anmeldung: beliebiger Benutzername, Passwort `demo`.
  Fehlerfälle: Benutzer `gesperrt` (account_locked), `neu` (password_change_required),
  `wartung` (maintenance), falsches Passwort (invalid_credentials).
- Das Access-Token lebt im Demo-Modus nur 120 s, damit der automatische Refresh sichtbar wird.
- Unter **Mehr → „Demo: Planänderung simulieren“** wird ein Sync mit geänderten Tagen ausgelöst.
- Offline-Modus testen: DevTools → Network → „Offline“; die zuletzt geladenen Daten werden
  mit Hinweis „Offline – Stand: …“ angezeigt.

Gegen einen echten Server: beim ersten Start die Server-Adresse eingeben (z. B.
`https://planquadro.firma.de`; `/api/v1/` wird ergänzt) und bei SaaS mit zentralem Host
optional das Mandanten-Kürzel (Header `X-PQ-Tenant`).

## Befehle

| Befehl | Zweck |
|---|---|
| `npm run dev` | Entwicklungsserver |
| `npm run build` | `tsc --noEmit` (strict) + Produktions-Build nach `dist/` inkl. Service Worker |
| `npm run test` | Vitest-Unit-Tests |
| `npm run preview` | gebaute PWA lokal ausliefern |
| `npm run icons` | Platzhalter-Icons (PWA, Android, iOS) neu erzeugen |
| `npm run cap:sync` | Build + Web-Assets/Plugins in die nativen Projekte kopieren |
| `npm run android` / `npm run ios` | Build, Sync und Android Studio / Xcode öffnen |

## Architektur

```
mobile/
├── index.html                 CSP, Manifest, lang="de"
├── capacitor.config.ts        App-ID de.planquadro.app, Name „Planquadro“
├── vite.config.ts             Build, Vitest-Konfiguration, Service-Worker-Generator
├── public/                    manifest.webmanifest, Icons
│                           (Icons/Splash: `npm run icons` → tools/brand/render_png.js)
├── src/
│   ├── main.ts                Start: Session laden, OIDC-Rücksprung (Web), App starten, SW registrieren
│   ├── api/
│   │   ├── types.ts           Typen 1:1 nach docs/API.md
│   │   ├── client.ts          ApiClient: Bearer, Tenant-Header, Single-Flight-Refresh, alle Endpunkte
│   │   ├── errors.ts          ApiError, Abbildung {error:{code,message}} → deutsche Meldungen
│   │   ├── mock.ts            Fake-Server für den Demo-Modus
│   │   └── fixtures/          Beispieldaten (JSON)
│   ├── store/
│   │   ├── session.ts         Server-Einrichtung, Login/OIDC, Sperre, Logout, Löschen, Push, Sync
│   │   ├── secure.ts          Sicherer Speicher (Interface + Web-Fallback)
│   │   ├── crypto.ts          AES-GCM (WebCrypto)
│   │   ├── cache.ts           verschlüsselter Offline-Cache je Server/Mandant/Benutzer
│   │   ├── sync.ts            GET sync → betroffene Monate neu laden, full_resync
│   │   ├── data.ts            Laden mit Offline-Fallback; Schreiben nur online
│   │   ├── kv.ts              IndexedDB-Wrapper
│   │   └── prefs.ts           nicht geheime Einstellungen (Capacitor Preferences)
│   ├── platform/
│   │   ├── native.ts          Capacitor-Plugins (Push, Biometrie, Netzwerk, Browser, Statusleiste …)
│   │   ├── secure-native.ts   Keychain/Keystore (@aparajita/capacitor-secure-storage)
│   │   ├── widget.ts          Widget-Zusammenfassung (nicht vertraulich) + Brücke PlanquadroWidget
│   │   ├── deeplink.ts        planquadro://… → Hash-Route
│   │   ├── back.ts            Android-Zurück-Taste
│   │   ├── share.ts, calendar.ts, reminders.ts   Teilen, Gerätekalender, lokale Erinnerungen
│   │   └── screenshot.ts      Schalter VITE_SCREENSHOT_DEMO (nur CI)
│   ├── screens/               ein Modul je Bildschirm (plan, details, team, absences,
│   │                          notifications, more, setup, login, lock)
│   ├── ui/                    App-Shell/Router, Tabs, Theme, Komponenten, Pull-to-Refresh, Icons
│   ├── lib/                   Datum/KW, Farben/Kontrast, URL, PKCE, HTML-Escaping
│   ├── sw/sw.js               Service-Worker-Vorlage (App-Shell-Cache)
│   └── styles/app.css         Design-Tokens Hell/Dunkel, Komponenten
├── tests/                     Vitest
├── android/                   natives Android-Projekt (Capacitor)
└── ios/                       natives iOS-Projekt (Capacitor, Swift Package Manager)
```

Prinzipien:

- **Kein Framework:** Bildschirme sind Funktionen `(ctx) => Promise<void>`, die mit dem
  escapenden Template-Tag `html\`…\`` rendern (`src/lib/html.ts`). Interpolationen werden
  immer escaped; Farben aus der API werden vor Verwendung in `style` validiert.
- **Hash-Routing:** `#/plan/2026-11`, `#/tag/2026-11-02[/user_id]`, `#/team/2026-11`,
  `#/abwesenheiten/2026`, `#/mitteilungen`, `#/mehr` – funktioniert identisch in PWA und App.
- **Tabs nach Rechten:** Plan · Team (nur `permissions.plan_view`) · Abwesenheiten (nur Modul
  `absences`) · Mitteilungen (mit Ungelesen-Badge) · Mehr. Antragsformular nur bei Modul `approvals`.
- **Branding:** `tenant.branding.primary/accent` werden je Theme kontrastgeprüft
  (`src/lib/color.ts`): Füllfarbe ≥ 3:1 zur Fläche, Text/Link ≥ 4,5:1, Schrift auf der
  Markenfarbe wird automatisch Schwarz/Weiß gewählt. Gleiches gilt für Code-Badges, wenn
  das gelieferte `fg` nicht lesbar ist.
- **Dunkelmodus:** folgt `prefers-color-scheme`, manuell übersteuerbar (Mehr → Darstellung).
- **Barrierefreiheit:** semantisches HTML, Labels, sichtbare Fokusrahmen, Skip-Link,
  Touch-Ziele ≥ 44 px, `aria-current`, Live-Regionen, `prefers-reduced-motion`, `lang="de"`.
- **Kein Tracking, keine Analytics**, keine externen Schriften/CDNs.

## Native Funktionen

Die Apps sind keine reine Web-Hülle: Folgende Funktionen nutzen native Schnittstellen
(Plugins bzw. eigener Kotlin-Code). Alle respektieren dieselben Rechte und dieselbe
Vertraulichkeit wie die Web-Oberfläche; die TS-Logik ist in `tests/native.test.ts` getestet.

| Funktion | Android | iOS (Capacitor) | Umsetzung |
|---|---|---|---|
| Push (FCM/APNs), Biometrie, Keychain/Keystore, Offline-Cache | ✓ | ✓ | siehe unten (bestehend) |
| **Home-Screen-Widget „Diese Woche“** | ✓ | – ¹ | `src/platform/widget.ts` → Plugin `PlanquadroWidget` → `android/app/src/main/java/de/planquadro/app/widget/` (Kotlin, RemoteViews) |
| **App-Schnellaktionen** „Mein Plan“, „Urlaub beantragen“ | ✓ | – ¹ | `android/app/src/main/res/xml/shortcuts.xml` (öffnen Deep Links) |
| **Deep Links** `planquadro://…` | ✓ | ✓ | `src/platform/deeplink.ts`, Intent-Filter bzw. `CFBundleURLTypes` |
| **Zurück-Taste**: Navigation statt App schließen | ✓ | – | `src/platform/back.ts` |
| **Teilen** eines Einsatztags (Share-Sheet) | ✓ | ✓ | `src/platform/share.ts` (`@capacitor/share`) |
| **Kalender**: Tag per Termin-Dialog, nächste 14 Tage ohne Doppelungen | ✓ | ✓ | `src/platform/calendar.ts` (`@ebarooni/capacitor-calendar`) |
| **Lokale Erinnerung** am Vorabend (Rückfall ohne Push, Standard aus) | ✓ | ✓ | `src/platform/reminders.ts` (`@capacitor/local-notifications`) |
| **Haptik** (Tabs, Pull-to-Refresh, Erfolg/Fehler) | ✓ | ✓ | `haptic()` in `src/platform/native.ts` |
| **Tastatur** (Tab-Leiste aus, Feld sichtbar), Statusleiste, Safe Area | ✓ | ✓ | `initKeyboard()`, `--safe-area-inset-*` (Capacitor 8 SystemBars) |

¹ Für Apple entsteht eine eigene, voll native SwiftUI-App (Ordner `apple/`); Widget und
Schnellaktionen für iOS kommen dort. Das Capacitor-iOS-Projekt baut weiter und hat alle
übrigen Funktionen.

### Widget-Daten (nicht vertraulich)

Nach jedem Sync (und nach der Anmeldung) lädt die App `GET plan/my` für heute + 7 Tage und
schreibt eine kleine JSON-Zusammenfassung in die privaten SharedPreferences
`planquadro_widget` (`buildWidgetSummary`): je Tag Datum, Wochentag, Bereichskürzel,
Bereichsname, geprüfte Farben, Feiertagsname. **Nicht enthalten:** Abwesenheitsart
(auch eigene → nur „Abwesend“, einheitliche Farbe), Bemerkungen, Kunden, Kontakte,
Kolleginnen/Kollegen, Daten anderer Personen. Die Kotlin-Seite prüft das Format erneut
(`WidgetSummary.parse`). Beim Abmelden wird der Inhalt gelöscht. Tippen auf einen Tag →
`planquadro://tag/<datum>`, auf den Rahmen → „Mein Plan“.

### Deep Links

| Link | Ziel |
|---|---|
| `planquadro://auth?code=…&state=…` | OIDC-Rücksprung (unverändert) |
| `planquadro://plan[/JJJJ-MM]` | Mein Plan |
| `planquadro://tag/JJJJ-MM-TT` | Einsatzdetails (ohne Datum: nächster Werktag) |
| `planquadro://team[/JJJJ-MM]` | Team (nur mit Recht `plan_view`, sonst Plan) |
| `planquadro://abwesenheiten[/JJJJ]` | Abwesenheiten |
| `planquadro://antrag` (`urlaub`) | Abwesenheiten mit Antragsformular |
| `planquadro://mitteilungen`, `planquadro://mehr` | Tabs |
| `planquadro://screen/<ziel>[?theme=light\|dark]` | Alias für CI-Screenshots; `theme` wirkt nur im Screenshot-Build |

Unbekannte oder fehlerhafte Links werden ignoriert. Kommt ein Link vor der Anmeldung bzw.
Entsperrung, wird er danach geöffnet.

### Kalender

Ein iCal-Abo-Link ist in der API v1 nicht enthalten (der Feed `public/api/ical.php` nutzt ein
persönliches Token aus dem Profil der Web-Oberfläche). Die App überträgt deshalb Termine
direkt: **einzeln** über den System-Termin-Dialog (keine Berechtigung nötig, der Nutzer
speichert selbst) oder **die nächsten 14 Tage** als ganztägige Termine (Kalenderberechtigung;
die Termin-IDs werden gemerkt und beim nächsten Übernehmen ersetzt).

### Screenshot-/Demo-Modus für native Builds

`VITE_SCREENSHOT_DEMO=1 npx vite build` erzeugt einen Web-Build, der in der nativen App
ohne Backend mit den Demo-Daten startet, sich automatisch anmeldet, keine
Berechtigungsdialoge zeigt und `?theme=` in Deep Links auswertet
(`src/platform/screenshot.ts`). **Nur für CI-Screenshots** – `vite.config.ts` bricht ab,
wenn zusätzlich `PQ_RELEASE_BUILD=1` gesetzt ist; der Testbuild-APK im Release wird ohne
die Variable gebaut.

Lokal mit Emulator:

```bash
VITE_SCREENSHOT_DEMO=1 npx vite build && npx cap copy android
(cd android && ./gradlew assembleDebug)
bash scripts/android-screenshots.sh android/app/build/outputs/apk/debug/app-debug.apk /tmp/shots
```

**Fixtures (wiederverwendbar, z. B. für die SwiftUI-App):** `src/api/fixtures/*.json`
(`config`, `me`, `team`, `areas`, `absences`, `holidays`, `notifications`) – nur erfundene
Personen und Firmen, Telefonnummern `000000`/`111111`, E-Mail-Domains `.example`. Die Tagespläne
erzeugt `src/api/mock.ts` deterministisch aus diesen Dateien (`entryFor`).

### Native Builds und Screenshots in GitHub Actions

Workflow `.github/workflows/apps.yml` (manuell, bei Pushes auf `mobile/**`, bei Tags `v*`
über `release.yml`):

| Job | Inhalt | Artefakte |
|---|---|---|
| `android` | Node 22, Java 21, `npm ci`, Typecheck/Tests/Build, `cap sync android`, `assembleDebug` (Testbuild) und Screenshot-APK; Emulator API 34 (x86_64, KVM) – Bildschirme per `adb shell am start -d planquadro://screen/…`, `screencap` hell und dunkel (`cmd uimode night`), aufgeräumte Statusleiste, Widget über die Debug-Vorschau `WidgetPreviewActivity` (dieselben RemoteViews) | `android-apk`, `android-screenshots` |
| `ios` | `macos-15`, neuestes Xcode, `cap sync ios`, `xcodebuild … -sdk iphonesimulator CODE_SIGNING_ALLOWED=NO`, Start-Test im Simulator | `ios-simulator-app` (ZIP), `ios-build-log` |
| `publish-screenshots` | nur `main`, Tags, manueller Start: Android-Bilder in den Orphan-Branch `app-screenshots` (andere Dateien dort bleiben) | – |

Einbinden: `https://raw.githubusercontent.com/florians25/Planquadro/app-screenshots/android-plan-light.png`
(Dateien: `android-{plan,tag,team,abwesenheiten,mitteilungen,mehr,widget}-{light,dark}.png`).
Das Release (`release.yml`) hängt `planquadro-<tag>-android-test.apk` (debug-signiert) und
`planquadro-<tag>-ios-simulator.zip` an.

## Sicherheits- und Offline-Modell

| Daten | Ort | Schutz |
|---|---|---|
| Access-Token (15 min) | nur Arbeitsspeicher | – |
| Refresh-Token (30 Tage, rotierend) | nativ: Keychain (`whenUnlockedThisDeviceOnly`, kein iCloud-Sync) / Android Keystore; Web: IndexedDB | Web: AES-GCM mit nicht exportierbarem WebCrypto-Schlüssel |
| Offline-Cache (Plan, Team, Abwesenheiten, Details, Mitteilungen, `me`) | IndexedDB | AES-GCM-256; Schlüssel nativ als Zufallsmaterial im Keychain/Keystore, Web als nicht exportierbarer `CryptoKey`; Datensatz-ID als AAD (keine Vertauschung) |
| Einstellungen (Server, Theme, Biometrie an/aus, Push-ID) | Capacitor Preferences / localStorage | nicht geheim |

- **Token-Refresh:** proaktiv 30 s vor Ablauf und reaktiv bei `401 token_expired`. Ein
  Single-Flight-Lock sorgt dafür, dass parallele Requests genau **einen** Refresh auslösen –
  wichtig, weil die Wiederverwendung eines Refresh-Tokens serverseitig die ganze
  Token-Familie sperrt. Das neue Refresh-Token wird **vor** der Verwendung persistiert.
  `401 invalid_token` bzw. fehlgeschlagener Refresh → lokale Tokens weg, neu anmelden.
  Netzwerkfehler beim Refresh behalten das Refresh-Token.
- **Biometrie (optional, Standard aus):** Ist sie aktiv, gibt die App das Refresh-Token erst
  nach erfolgreicher Face ID / Touch ID / Fingerabdruck-Prüfung frei – beim Start und nach
  > 5 min im Hintergrund (dabei wird das Access-Token verworfen). Ohne verfügbare Biometrie
  wird die Option deaktiviert und auf die Passwort-Anmeldung zurückgefallen.
  Hinweis: Die Sperre wird in der App durchgesetzt; das verwendete Secure-Storage-Plugin
  bindet den Keychain-Eintrag nicht hardwareseitig an `biometryCurrentSet`.
- **Abmelden:** `DELETE devices/{id}`, `POST auth/logout` (mit Refresh-Token), danach werden
  Offline-Cache, sicherer Speicher, Schlüssel und Benutzerzuordnung gelöscht – auch wenn der
  Server nicht erreichbar ist. **„Lokale Daten löschen“** setzt zusätzlich alle Einstellungen
  inkl. Server zurück. Ein Server- oder Benutzerwechsel verwirft den alten Cache.
- **Offline:** Lesen zeigt die zuletzt geladenen Daten mit Banner „Offline – Stand: …“
  (auch bei `503 maintenance`). **Schreibzugriffe werden nie in eine Warteschlange gestellt**:
  ohne Verbindung erscheint eine Meldung (Anträge, Gelesen-Markierungen).
- **Sync:** beim Start, beim Zurückkehren in die App, nach Wiederverbindung, bei Pull-to-Refresh
  und bei Push `plan_changed`: `GET sync?since=<cursor>`; geänderte Tage → betroffene, bereits
  gecachte Monate (Plan, Team, Abwesenheitsjahr) werden neu geladen, Detail-Einträge der Tage
  verworfen. `full_resync: true` → Cache des Benutzers leeren. Der Cursor wird erst nach
  erfolgreichem Nachladen gespeichert.
- **Transport:** nur HTTPS. Klartext-HTTP ausschließlich für `localhost`, `127.0.0.1`,
  `10.0.2.2` (Android: `res/xml/network_security_config.xml`; iOS: ATS mit
  `NSAllowsLocalNetworking`; Eingabeprüfung in `src/lib/url.ts`). Android lässt nur
  System-CAs zu. App-Backups (Android) sind deaktiviert.
- **CSP** in `index.html`: keine Inline-Skripte, keine fremden Skriptquellen.

## OIDC („Mit Firmenkonto anmelden“)

Erscheint, wenn `GET config` → `auth.oidc.enabled` und `authorize_url` gesetzt sind.
Ablauf: Authorization Code + PKCE (S256) im **System-Browser** (`@capacitor/browser`),
Rücksprung über `planquadro://auth?code=…&state=…` (Android Intent-Filter, iOS
`CFBundleURLTypes`), `state` wird geprüft, dann `POST auth/oidc/token` mit `code`,
`code_verifier`, `redirect_uri`. `response_type`, `scope` (Standard `openid profile`),
`redirect_uri`, `code_challenge(_method)` und `state` ergänzt die App; `client_id` muss in
`authorize_url` enthalten sein. In der PWA ist die Redirect-URI die Seiten-URL der App.

## Android bauen (Android Studio)

Voraussetzungen: Node 22+, JDK 21, Android Studio (SDK 36).

```bash
npm ci
npm run android        # build + cap sync android + Android Studio öffnen
```

In Android Studio: Gerät/Emulator wählen → Run. Gegen einen lokalen Server im Emulator
`http://10.0.2.2:<port>` verwenden.

Release-Build: *Build → Generate Signed App Bundle* (AAB). Keystore und Passwörter **nicht**
committen (`*.jks`, `*.keystore`, `keystore.properties` sind in `.gitignore`). Für CI z. B.
`keystore.properties` aus Secrets erzeugen und in `android/app/build.gradle` als
`signingConfigs.release` einbinden. Play App Signing aktivieren.

## iOS bauen (Xcode auf macOS)

Voraussetzungen: macOS mit Xcode 26+, Apple-Developer-Konto. Das Projekt nutzt den
**Swift Package Manager** (keine CocoaPods nötig).

```bash
npm ci
npm run ios            # build + cap sync ios + Xcode öffnen
```

In Xcode: Target *App* → *Signing & Capabilities* → Team wählen; Capabilities
**Push Notifications** und **Background Modes → Remote notifications** sind über
`App/App.entitlements` bzw. `Info.plist` vorbereitet. Archivieren: *Product → Archive*
→ App Store Connect.

## Push einrichten

Push ist nur in den nativen Apps aktiv. **In der PWA wird Push bewusst übersprungen**
(Web Push auf iOS nur für installierte PWAs und vertraglich nicht vorgesehen); der Plan wird
dort beim Öffnen/Fokussieren und per Pull-to-Refresh synchronisiert.

Ablauf: Berechtigung anfragen → Token von FCM (Android) bzw. APNs (iOS) →
`POST devices {platform, provider: "fcm"|"apns", token, app_version}` → ID wird für
`DELETE devices/{id}` beim Abmelden gespeichert. Nachricht mit `data.type = "plan_changed"`
→ `GET sync`; Tippen auf die Nachricht öffnet „Mein Plan“.

**Android (FCM):** In der Firebase-Konsole ein Projekt anlegen, Android-App mit Paketname
`de.planquadro.app` registrieren, `google-services.json` herunterladen und nach
`android/app/google-services.json` legen. Das Gradle-Plugin wird nur angewandt, wenn die
Datei existiert. Serverseitig wird ein FCM-Dienstkonto (HTTP v1 API) benötigt.

**iOS (APNs):** Im Apple-Developer-Portal einen **APNs-Auth-Key (.p8)** erzeugen; Key-ID,
Team-ID und Bundle-ID `de.planquadro.app` erhält das Backend. Die App liefert das rohe
APNs-Geräte-Token (`provider: "apns"`).

> **`google-services.json`, `GoogleService-Info.plist`, `.p8`-Schlüssel, Keystores und
> Provisioning-Profile werden NICHT committet** (siehe `.gitignore`). Sie gehören in den
> Secret-Speicher der Build-Pipeline bzw. auf den Server.

## Store-Release-Checkliste

- [ ] Version in `package.json`, Android `versionCode`/`versionName`
      (`android/app/build.gradle`) und iOS `MARKETING_VERSION`/`CURRENT_PROJECT_VERSION` erhöhen
- [x] App-Icons und Splash-Screens im Planquadro-Design (`npm run icons`, siehe docs/BRAND.md)
- [ ] `npm run test` und `npm run build` grün; `npx cap sync`
- [ ] Push mit Produktions-FCM/APNs getestet (`aps-environment` = production beim Archivieren)
- [ ] OIDC-Rücksprung `planquadro://auth` beim Identity Provider registriert
- [ ] Datenschutzerklärung-URL, Datenschutz-Angaben (App Store „App Privacy“, Play „Data safety“):
      keine Tracking-/Analysedaten; verarbeitet werden Name, Dienstplan, Abwesenheiten (auf Gerät verschlüsselt)
- [ ] Exportkonformität: nur Standard-Kryptografie (`ITSAppUsesNonExemptEncryption = false`)
- [ ] Screenshots (Hell/Dunkel), Store-Texte auf Deutsch, Altersfreigabe
- [ ] Release signiert (Play App Signing / App Store Distribution), Test über TestFlight / interne Tests
- [ ] Demo-Zugang für die App-Review (z. B. Demo-Modus auf dem Einrichtungsbildschirm)
- [ ] **Android-Signierung:** Upload-Keystore anlegen, in CI als Secret (`keystore.properties`
      erzeugen), `signingConfigs.release` + `bundleRelease` (AAB) ergänzen; Play-Konsolen-Konto,
      Play App Signing; Data-safety-Angaben inkl. Kalender (nur Schreiben auf Nutzerwunsch)
- [ ] **Push-Schlüssel:** `google-services.json` (FCM) als CI-Secret, FCM-Dienstkonto auf dem
      Server; APNs-Auth-Key (.p8) mit Key-ID/Team-ID für das Backend
- [ ] **Apple:** Developer-Programm-Konto, Bundle-ID `de.planquadro.app`, Zertifikate/Profile
      (bzw. die SwiftUI-App in `apple/` als Store-App), App-Store-Connect-Eintrag
- [ ] Store-Screenshots aus dem Branch `app-screenshots` übernehmen (Android: Phone, ggf. Tablet nachziehen)

## Verwendete Pakete

| Paket | Version | Zweck |
|---|---|---|
| `@capacitor/core`, `cli`, `android`, `ios` | 8.6.0 | Laufzeit, native Projekte |
| `@capacitor/push-notifications` | 8.1.x | FCM/APNs |
| `@capacitor/preferences` | 8.0.x | nicht geheime Einstellungen |
| `@capacitor/app` | 8.1.x | Lebenszyklus, URL-Schema, Zurück-Taste |
| `@capacitor/network` | 8.0.x | Online-/Offline-Erkennung |
| `@capacitor/splash-screen`, `status-bar` | 8.0.x | Darstellung |
| `@capacitor/browser` | 8.0.x | System-Browser für OIDC und Kartenlinks |
| `@aparajita/capacitor-secure-storage` | 8.0.x | Keychain/Keystore |
| `@aparajita/capacitor-biometric-auth` | 10.0.x | Face ID / Touch ID / Fingerabdruck (Major 10 unterstützt Capacitor 8) |
| `@capacitor/haptics` | 8.0.2 | Haptisches Feedback |
| `@capacitor/share` | 8.0.3 | System-Teilen |
| `@capacitor/keyboard` | 8.0.6 | Tastatur-Ereignisse, Resize |
| `@capacitor/local-notifications` | 8.3.1 | Erinnerung am Vorabend |
| `@ebarooni/capacitor-calendar` | 8.10.0 | Gerätekalender (Android/iOS) |
| `vite` 8, `typescript` 7, `vitest` 5, `fake-indexeddb` | – | Build und Tests |

Die neuen Plugins sind in `package.json` auf exakte Versionen festgelegt. Android nutzt
Kotlin 2.2.20 (`android/variables.gradle`, eine Version für App und Plugins).

Statt `vite-plugin-pwa`/Workbox wird ein kleiner, selbst geschriebener Service Worker
verwendet (`src/sw/sw.js`, beim Build mit der Dateiliste befüllt). Er cacht nur die
App-Shell, niemals API-Antworten.

## Hinweise für das Backend

- **CORS:** Die App ruft die API per `fetch` aus der WebView auf. Erlaubte Origins:
  `https://localhost` (Android), `capacitor://localhost` (iOS) und der Ursprung der PWA.
  Benötigte Header: `Authorization`, `Content-Type`, `X-PQ-Tenant`; Preflight `OPTIONS`.
- `GET config`/`branding/logo` müssen ohne Token und mit CORS erreichbar sein.
- `POST devices` sollte je Token idempotent sein (die App registriert bei jedem Start).
