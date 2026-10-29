# Planquadro für iPhone und iPad (native App)

Native Apple-App in **Swift/SwiftUI** mit **Liquid Glass** ab iOS 26 und sauberem Rückfall
auf iOS 17/18. Sie ersetzt auf iOS die Capacitor-Hülle; die Capacitor-App in `mobile/`
bleibt für Android, die PWA und als Rückfall bestehen (Begründung: `docs/ARCHITECTURE.md`,
Abschnitt B.8). Die App spricht ausschließlich die **API v1** (`docs/API.md`).

| | |
|---|---|
| Plattformen | iPhone und iPad, **ab iOS 17** (Liquid Glass ab iOS 26) |
| Sprache | Deutsch (String Catalog `App/Resources/Localizable.xcstrings`) |
| Bundle-ID | `de.planquadro.app` (Nachfolger der Capacitor-App im App Store), Widgets `de.planquadro.app.widgets` |
| App Group | `group.de.planquadro.app` (Widgets, Live-Aktivität, Siri) |
| URL-Schema | `planquadro://` (OIDC-Rücksprung `planquadro://auth`, Deep Links) |
| Projekt | **XcodeGen** (`project.yml`), kein eingecheckter `.xcodeproj` |

## Aufbau

```
apple/
├── project.yml                 XcodeGen: App, Widgets, Unit- und UI-Tests, Schemes
├── Config/                     xcconfig (Bundle-ID, App Group, Push an/aus), Info.plist, Entitlements
├── PlanquadroKit/              Swift Package – plattformneutrale Logik, läuft auch unter Linux
│   ├── Sources/PlanquadroKit/
│   │   ├── Models/             API-Typen 1:1 nach docs/API.md (explizite CodingKeys)
│   │   ├── API/                APIClient (actor, async/await, Single-Flight-Refresh), Fehler, Transport
│   │   ├── Auth/               PKCE (S256, state, nonce), Server-Adresse, SHA-256
│   │   ├── Storage/            Keychain/SecureStore, verschlüsselter Offline-Cache (AES-GCM)
│   │   ├── Sync/               GET sync → betroffene Monate neu laden; Live-Ereignisse auswerten
│   │   ├── Live/               SSE-Parser und Live-Verbindung (Einmal-Ticket, Backoff, last_event_id)
│   │   ├── Domain/             Datum/KW, Formatierung, Markenfarben/Kontrast, Vertraulichkeit,
│   │   │                       Widget-Auszug (PlanSnapshot), Deep Links
│   │   ├── Session/            SessionController (@Observable): kompletter App-Ablauf
│   │   ├── Demo/               DemoServer (Fake-API mit Fixtures aus mobile/src/api/fixtures)
│   │   └── Resources/Fixtures/ Beispieldaten (erfundene Namen/Firmen)
│   └── Tests/                  XCTest (66 Tests)
├── App/                        App-Target (SwiftUI)
│   ├── Sources/
│   │   ├── PlanquadroApp.swift Szene, Deep Links, Handoff, Spotlight, Tastaturkurzbefehle
│   │   ├── AppModel.swift      Sitzung + Navigation + Plattformdienste
│   │   ├── Design/             Liquid Glass mit Rückfall, Theme (Mandanten-Branding), Komponenten
│   │   ├── Platform/           Face ID, Keychain-Rückfall, Netzwerk, Push (APNs), EventKit,
│   │   │                       Spotlight, Live-Aktivität
│   │   ├── Screens/            Einrichtung/Login/Sperre, Plan, Einsatzdetails, Team, Abwesenheiten,
│   │   │                       Mitteilungen, Mehr, Suche
│   │   └── Intents/            App Intents, App Shortcuts (Siri, deutsche Sätze)
│   ├── Assets.xcassets         App-Icon (hell/dunkel/getönt), Farben, Launch-Bildmarke
│   └── Resources/              Localizable.xcstrings, PrivacyInfo.xcprivacy
├── Shared/                     Code für App *und* Widgets (Design, Widget-Ansichten, App Group, Live-Aktivität)
├── Widgets/                    WidgetKit-Erweiterung: Heute, Diese Woche, Sperrbildschirm, Live-Aktivität
├── Tests/AppTests, UITests     Unit-Tests der App, UI-Tests im Demo-Modus
└── scripts/                    screenshots.sh, pick_simulator.py, gen_strings.py
```

Grundsatz: **Alles, was ohne UI geht, liegt in `PlanquadroKit`** und wird unter Linux
getestet (`swift build && swift test`). Apple-Spezifisches im Paket (Keychain, CryptoKit,
Dateischutzklassen) steht hinter `#if canImport(Security)` bzw. `#if os(iOS)`.

## Bauen

Voraussetzungen: macOS mit **Xcode 26** (für Liquid Glass; Xcode 16 baut den Rückfall),
[XcodeGen](https://github.com/yonaskolb/XcodeGen).

```bash
brew install xcodegen
cd apple
xcodegen generate          # erzeugt Planquadro.xcodeproj
open Planquadro.xcodeproj  # Scheme „Planquadro“
```

Kommandozeile (Simulator, ohne Signierung – so baut auch das CI):

```bash
xcodebuild build-for-testing -project apple/Planquadro.xcodeproj -scheme Planquadro \
  -destination 'platform=iOS Simulator,name=iPhone 17 Pro' \
  CODE_SIGNING_ALLOWED=NO PQ_PUSH_ENABLED=NO
xcodebuild test-without-building -project apple/Planquadro.xcodeproj -scheme Planquadro \
  -destination 'platform=iOS Simulator,name=iPhone 17 Pro' CODE_SIGNING_ALLOWED=NO PQ_PUSH_ENABLED=NO
```

Nur die Logik (auch unter Linux):

```bash
cd apple/PlanquadroKit && swift build && swift test
```

String Catalog nach neuen Texten aktualisieren: `python3 apple/scripts/gen_strings.py`
(Xcode ergänzt Texte mit Platzhaltern beim Bauen selbst).

App-Icons neu erzeugen (aus `public/assets/brand/*.svg`):
`node tools/brand/render_png.js --apple` → `App/Assets.xcassets/AppIcon.appiconset`
(1024 px Standard ohne Alphakanal, Dunkel mit transparentem Grund, Getönt in Graustufen)
und die Bildmarke des Startbildschirms.

## Liquid Glass und Rückfall

| Stelle | iOS 26 (Xcode 26) | iOS 17/18 |
|---|---|---|
| Karten, Statuskapseln, Umschalter, Meldungen | `.glassEffect(.regular.tint(…).interactive(), in:)` in `GlassEffectContainer` | `.regularMaterial` mit feinem Rand |
| Buttons | `.buttonStyle(.glassProminent)` / `.glass` | `.borderedProminent` / `.bordered` |
| Tab-Leiste | neue Glas-Tab-Leiste, `tabBarMinimizeBehavior(.onScrollDown)`, Suche als `Tab(role: .search)` | iOS 18: `Tab`-API mit Suche; iOS 17: klassische `tabItem`-Leiste |
| Listen | `scrollEdgeEffectStyle(.soft)` unter Glas-Leisten | Standard |
| Widgets | `containerBackground(for: .widget)`; getönter/klarer Modus über `widgetRenderingMode` und `.widgetAccentable()` | gleich (ab iOS 17) |
| App-Icon | Varianten hell, dunkel, getönt im Asset-Katalog | hell |

Alle iOS-26-Aufrufe stehen in `App/Sources/Design/Glass.swift` hinter
`#if compiler(>=6.2)` **und** `if #available(iOS 26.0, *)`. Mit älterem Xcode übersetzt nur
der Rückfall.

## Funktionen

- **Einrichtung:** Server-Adresse (nur `https://`, `http://` nur für `localhost`), optionales
  Mandanten-Kürzel (`X-PQ-Tenant`), Prüfung über `GET config` (API-Version 1).
- **Anmeldung:** Passwort oder **Firmenkonto (OIDC)** über `ASWebAuthenticationSession`
  (SwiftUI `webAuthenticationSession`), Authorization Code + **PKCE S256**, `state`, `nonce`,
  Rücksprung `planquadro://auth`, 10 Minuten gültig. Fehlertexte je Code (gesperrt, Passwort
  ändern, Wartung …).
- **Tokens:** Access-Token nur im Speicher, Refresh-Token im **Keychain**
  (`WhenUnlockedThisDeviceOnly`, kein iCloud). Refresh proaktiv 30 s vor Ablauf und bei
  `401 token_expired`; **Single-Flight** (parallele Anfragen → genau ein Refresh, wichtig wegen
  der Familiensperre bei Wiederverwendung); neues Token wird vor der Verwendung gespeichert.
- **Face ID / Touch ID / Optic ID** (optional): Start und nach > 5 Minuten im Hintergrund
  gesperrt; das Refresh-Token wird erst nach erfolgreicher Prüfung gelesen. Im App-Umschalter
  wird der Inhalt abgedeckt.
- **Plan:** Woche (mit „Heute“-Karte) oder Monat nach KW gruppiert, Heute in Signal-Orange,
  Feiertage, Bemerkungen, veröffentlicht am …; **Einsatzdetails** mit Kunde, Kontakt,
  Adresse → Apple Karten, Anruf, E-Mail, Standort-Karte (MapKit), Kolleginnen und Kollegen,
  **Teilen**, **in den Kalender eintragen**, Handoff.
- **Team:** Raster wie die Planungsmatrix (Zeilen = Personen, Spalten = Tage, farbige
  Kürzel, Wochenende/Feiertag/Heute markiert, Suche ohne Akzente). Vertrauliche
  Abwesenheiten **nur als „A“**, ohne Link und ohne Grund.
- **Abwesenheiten:** Urlaubskonto (Ring + Kacheln), Krankheitstage, Anträge stellen (je
  Werktag, Wochenenden/Feiertage übersprungen) und verfolgen, Zeiträume.
- **Mitteilungen:** ungelesen markiert, Wischen = gelesen, „Alle gelesen“, Antippen öffnet
  das Ziel; App-Badge.
- **Mehr:** Profil, Server, Biometrie, Farbschema, **Kalender-Abo** (`webcal://`, Link
  kopieren; neuer Endpunkt `GET/POST calendar`), Einsätze in den Kalender übernehmen,
  Siri-Tipp und Kurzbefehle, Spotlight, Live-Aktivität, Widget-Vorschau, Über, Abmelden,
  lokale Daten löschen.
- **Suche** (iOS 18+ als Tab-Rolle, iPad in der Seitenleiste): eigene Einsätze nach
  Kürzel/Kunde/Datum, Personen.
- **Live-Updates:** Server-Sent Events mit **Einmal-Ticket** (`POST events/ticket`, nie ein
  Token in der URL), Neuaufbau nach `bye` mit `last_event_id`, `reset` → Sync, Backoff
  1–60 s, danach nur Sync. Nur im Vordergrund.
- **Offline-Lesemodus:** zuletzt geladener Stand aus dem **AES-GCM-verschlüsselten** Cache
  (Schlüssel im Keychain, Datensatz-ID als AAD, je Server/Mandant/Benutzer getrennt, nicht im
  Backup), Hinweis „Offline – Stand: …“ bzw. „Wartungsmodus – Stand: …“. Schreibzugriffe
  werden nie in eine Warteschlange gestellt.
- **Sync:** beim Start, beim Zurückkehren, nach Wiederverbindung, Pull-to-Refresh, Push.
- **Push (APNs):** Erlaubnis nach der Anmeldung, `POST devices` (provider `apns`),
  `DELETE devices/{id}` beim Abmelden; `plan_changed` → Sync (auch im Hintergrund);
  Antippen öffnet den betroffenen Monat.
- **Widgets:** „Heute“ (klein), „Diese Woche“ (mittel), Sperrbildschirm (rechteckig, rund,
  eine Zeile). Datenquelle ist ein **nicht vertraulicher Auszug** in der App Group: nur
  eigene Einsätze (Kürzel, Bezeichnung, Ortsname), **Abwesenheiten nur als „Abwesend“**,
  keine Bemerkungen, keine Kundendaten. Zeitleiste springt um Mitternacht weiter.
- **Live-Aktivität** (ActivityKit) für den heutigen Einsatz: Sperrbildschirm und Dynamic
  Island, manuell oder automatisch beim Öffnen (Einstellung), endet am Tagesende.
- **App Intents / Siri / Kurzbefehle:** „Wo bin ich morgen eingesetzt in Planquadro“ (auch
  heute/übermorgen, antwortet ohne die App zu öffnen), „Zeig meinen Plan in Planquadro“,
  „Urlaub beantragen in Planquadro“ (öffnet das Formular).
- **Spotlight** (optional, Standard aus): eigene Einsätze; beim Abmelden gelöscht.
- **EventKit:** Einsätze als ganztägige Termine – nach Rückfrage, nur „Schreiben“-Zugriff,
  keine Abwesenheiten, bereits übernommene Tage werden übersprungen; einzelner Termin über
  den System-Dialog (ohne Kalenderzugriff).
- **iPad:** `NavigationSplitView` mit Seitenleiste; Tastaturkurzbefehle ⌘1–⌘5 (Bereiche),
  ⌘N (Antrag), ⌘R (aktualisieren), ⌘F (Suche), ⌘← / ⌘→ (Zeitraum), ⌘T (heute).
- **Mandanten-Branding:** Name, Farben (kontrastgeprüft: Füllung ≥ 3:1, Text ≥ 4,5:1 je
  Hell/Dunkel), Logo (nur HTTPS) aus der API; ohne Angabe das Planquadro-Design.
- **Barrierefreiheit:** Dynamic Type (Raster wächst mit), VoiceOver-Beschriftungen für
  Tage, Zellen („Beispiel, Erika, 02.11.2026: abwesend“), Kopfzeilen, Ansagen für Meldungen,
  Kontraste nach WCAG, Dark Mode.

## Demo- und Screenshot-Modus

Nur im **Debug-Build** werten die Start-Argumente aus (`LaunchOptions.swift`):

| Argument | Wirkung |
|---|---|
| `-PQDemo YES` | eingebauter Demo-Server (`DemoServer`) mit Beispieldaten, automatische Anmeldung |
| `-PQScreen <name>` | `setup`, `login`, `plan`, `week`, `detail`, `team`, `absences`, `request`, `notifications`, `more`, `widgets`, `search` |
| `-PQToday 2026-11-04` | fester „Heute“-Tag (reproduzierbare Bilder) |
| `-PQReset YES` | lokale Daten vor dem Start löschen |
| `-PQNoAnimations YES` | Animationen aus (UI-Tests) |

In Xcode: Scheme → Run → Arguments (vorbereitet, abgehakt). Ohne Argumente gibt es den
Demo-Modus auch auf dem Einrichtungsbildschirm („Demo-Modus starten“, Passwort `demo`;
Testfälle `gesperrt`, `neu`, `wartung`) – geeignet als Zugang für die App-Prüfung.

`apple/scripts/screenshots.sh <App> <UDID> <Präfix> <Ordner>` erzeugt alle Bildschirme in
Hell und Dunkel mit sauberer Statusleiste (9:41).

## CI (`.github/workflows/apple.yml`)

1. **Linux:** `swift build && swift test` für PlanquadroKit im Container `swift:6.2-noble`.
2. **macOS (`macos-26`):** neuestes Xcode (bevorzugt 26) wählen, `xcodegen`, Paket-Tests auf
   macOS, `xcodebuild build-for-testing` für den Simulator **ohne Signierung**
   (`CODE_SIGNING_ALLOWED=NO`, `PQ_PUSH_ENABLED=NO`), Unit- und UI-Tests auf dem neuesten
   iPhone-Pro-Simulator, Screenshots iPhone (alle Bildschirme) und iPad (Auswahl), jeweils
   hell und dunkel, inkl. Widget-Vorschau. Artefakt `ios-screenshots`.
3. **Veröffentlichen** (nur `main`, Tags, manuell; `contents: write`): PNGs in den Branch
   **`app-screenshots`**, Unterordner **`ios/`** (Orphan-Branch nur mit Bildern). Ersetzt wird
   nur `ios/`; der Android-Job schreibt `android/`. Bei gleichzeitigem Push wird neu geklont
   und bis zu fünfmal erneut versucht.

Ist `macos-26` nicht verfügbar: Repository-Variable `APPLE_RUNNER=macos-15` setzen.

Was **nur im CI** (bzw. auf einem Mac) geprüft werden kann: Übersetzung der SwiftUI-,
WidgetKit-, ActivityKit-, AppIntents-, EventKit-, CoreSpotlight- und LocalAuthentication-Teile,
der Keychain- und CryptoKit-Pfade im Paket, UI-Tests, Screenshots. Unter Linux laufen alle
66 Paket-Tests (API-Client, Token-Rotation, Cache, Sync, SSE, Vertraulichkeit, Sitzung, Demo).

## Signierung

Keine Team-ID und keine Zertifikate im Repository.

1. `cp apple/Config/Signing.local.xcconfig.example apple/Config/Signing.local.xcconfig` und
   `DEVELOPMENT_TEAM` eintragen (wird nicht eingecheckt).
2. Im Apple-Developer-Portal für `de.planquadro.app` und `de.planquadro.app.widgets` die
   Capabilities **Push Notifications** (nur App) und **App Groups** (`group.de.planquadro.app`,
   beide) aktivieren. Bei automatischer Signierung legt Xcode das an.
3. Eigene Bundle-ID/App Group (White-Label): `PQ_BUNDLE_ID` und `PQ_APP_GROUP` in
   `Signing.local.xcconfig` überschreiben – Info.plist und Entitlements übernehmen sie.
4. Push abschalten (z. B. ohne Push-Capability): `PQ_PUSH_ENABLED = NO`.

`aps-environment` ist im Debug `development`, im Release `production` (`Config/*.xcconfig`).

## Push-Schlüssel (Server)

Im Apple-Developer-Portal unter *Keys* einen **APNs-Auth-Key (.p8)** erzeugen. Key-ID, Team-ID,
Bundle-ID `de.planquadro.app` und die `.p8`-Datei erhält das Backend (Secret-Speicher, nie ins
Repo). Die App meldet das rohe Geräte-Token als Hex (`provider: "apns"`). Nutzlast laut
`docs/API.md`: `data.type = "plan_changed"`, `data.dates`; für den Sync im Hintergrund
zusätzlich `aps.content-available = 1`.

## TestFlight und App Store

1. `MARKETING_VERSION`/`CURRENT_PROJECT_VERSION` in `project.yml` erhöhen (gleiche Version
   wie `app/config/version.php`).
2. Xcode: Scheme *Planquadro*, Ziel *Any iOS Device* → *Product → Archive* → *Distribute App*
   → *App Store Connect*. Alternativ `xcodebuild archive` + `-exportArchive` mit
   API-Schlüssel im CI (Secrets: App-Store-Connect-API-Key, Team-ID; Signierung „automatic“).
3. Da die Bundle-ID der Capacitor-App übernommen wird, erscheint die native App als
   **Update** im bestehenden Store-Eintrag (Build-Nummer höher als die letzte Capacitor-Version).
4. Prüfung: Zugang über den **Demo-Modus** im Einrichtungsbildschirm angeben.
5. Exportkonformität: nur Standard-Kryptografie (`ITSAppUsesNonExemptEncryption = NO` gesetzt).

## Datenschutzangaben (App Store „App Privacy“)

`App/Resources/PrivacyInfo.xcprivacy`: **kein Tracking**, keine Tracking-Domains. Erhoben und
mit der Person verknüpft, ausschließlich zur **App-Funktion**: Name, Benutzer-ID,
Geräte-ID (Push-Token), sonstige Nutzerinhalte (Dienstplan, Anträge). Verwendete API mit
Begründungspflicht: UserDefaults (`CA92.1`, eigene Einstellungen). Keine Analyse, keine
Werbung, keine Drittanbieter-SDKs. Die Daten gehen nur an den Server der eigenen
Organisation; Abwesenheitsgründe anderer erhält die App nicht.

## Bekannte Grenzen / offen

- Widgets im Home-Bildschirm lassen sich im CI nicht automatisch platzieren; die Screenshots
  zeigen die **Widget-Vorschau** der App (gleiche Ansichten).
- Die biometrische Sperre wird in der App durchgesetzt; der Keychain-Eintrag ist nicht
  hardwareseitig an `biometryCurrentSet` gebunden (das Refresh-Token rotiert alle 15 Minuten,
  jede Rotation würde sonst eine Abfrage auslösen).
- Bereits in den Kalender übernommene Tage merkt sich die App lokal; mit reinem
  Schreibzugriff kann sie geänderte Termine nicht nachträglich anpassen (dafür: Kalender-Abo).
- Das Kalender-Abo nutzt die Basis-URL des Servers (`PQ_BASE_URL`); bei SaaS über einen
  zentralen API-Host muss die Mandanten-Domain gesetzt sein.
