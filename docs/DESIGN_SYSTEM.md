# Vireo-Designsystem

Ziel: das bewährte Erscheinungsbild der Einsatzplanung behutsam vereinheitlichen –
**ohne** etablierte Abläufe zu ändern. Grundlage bleibt `public/assets/css/style.css`;
`public/assets/css/vireo-theme.css` ergänzt Tokens, Dark Mode und Barrierefreiheit.

## Schichten

1. `style.css` – Komponenten (unverändert übernommen)
2. `vireo-theme.css` – Tokens, Dark Mode, Fokus, Bewegung, Touch
3. Inline-Variablen aus der TenantConfig (`opsBrandCss()`): Markenfarben des Mandanten

## Tokens

| Token | Zweck | Hell | Dunkel |
|---|---|---|---|
| `--bg` | Seitenhintergrund | `#f3f4f6` | `#0b1120` |
| `--card` | Karten, Tabellen | `#ffffff` | `#111827` |
| `--s` | abgesetzte Flächen | `#f8fafc` | `#0f172a` |
| `--tx` / `--tx2` / `--mu` | Text / sekundär / gedämpft | `#111827` / `#374151` / `#5b6472` | `#e5e7eb` / `#cbd5e1` / `#9ca3af` |
| `--bd` / `--bd2` | Rahmen | `#e5e7eb` / `#d1d5db` | `#263042` / `#334155` |
| `--p` | Markenfarbe (Mandant) | TenantConfig | TenantConfig |
| `--p-on` | Schrift auf Markenfarbe (automatisch kontrastreich) | berechnet | berechnet |
| `--p-text` | Markenfarbe als Text/Link (≥ 4.5:1 auf Weiß) | berechnet | aufgehellt |
| `--acc` / `--acc-on` | Akzentfarbe | TenantConfig | TenantConfig |
| `--ok`, `--wn`, `--err`, `--inf` (+ `…bg`) | Status | wie bisher | abgedunkelte Hintergründe |
| `--focus` | Fokusrahmen | `--p-text` | `#93c5fd` |
| `--space-1…6`, `--font-xs…lg`, `--touch` | Abstände, Schriftgrößen, Mindest-Trefferfläche (44 px) | | |

Einsatzcode-Farben (`plan_areas.color/fg_color`) und Abwesenheitsfarben bleiben in beiden
Modi gleich – sie sind fachliche Kennzeichen, die Planer wiedererkennen müssen.

## Dark Mode

- Umschalten im Benutzermenü: **System → Hell → Dunkel** (je Browser gespeichert, kein
  Aufblitzen beim Laden).
- Die dunklen Varianten der fest verdrahteten hellen Farben aus `style.css` werden mit
  `python3 tools/gen-dark-css.py` erzeugt (Bereich zwischen den Markern in
  `vireo-theme.css`). Nach Änderungen an `style.css` Generator erneut ausführen.
- Druck erfolgt immer hell.

## Barrierefreiheit (WCAG 2.2 AA)

- „Zum Inhalt springen“-Link, `aria-current` und `aria-label` in der Navigation
  (auch wenn nur Symbole sichtbar sind).
- Sichtbarer Fokusrahmen (`:focus-visible`) auf allen Bedienelementen.
- Dialoge: `role="dialog"`, `aria-modal`, Fokus in den Dialog, Fokusfalle mit Tab,
  Rückgabe des Fokus beim Schließen, Esc schließt.
- Meldungen (Toasts) als `aria-live="polite"`.
- **Planungsmatrix per Tastatur:** Tab in die Matrix, Pfeiltasten bewegen, Pos1/Ende,
  Eingabe/Leertaste wendet das gewählte Werkzeug an, Entf leert die Zelle. Jede Zelle hat
  eine sprechende Beschriftung („Muster, Max, 03.11.2026: K1, Entwurf von …“). Es gelten
  dieselben Prüfungen wie bei der Maus (Feiertag, Wochenende, gesperrte Zeile).
- `prefers-reduced-motion` wird respektiert; größere Trefferflächen bei Touch-Bedienung.
- Kontraste: gedämpfte Schrift auf 4.5:1 angehoben; Markenfarben werden automatisch
  geprüft und für Text/Schrift angepasst (Vorschau auf der Seite „Organisation“).

## Leistung

- Monatsplanung: Urlaubskonten und WOTs werden je Monat einmal für alle Mitarbeiter
  geladen statt je Zeile. Gemessen mit 88 Mitarbeitern: **856 → 68 Datenbankabfragen,
  293 ms → 54 ms** Serverzeit.

## Filter

- Monatsplanung: neuer Filter **„Nur Änderungen“** – zeigt nur Mitarbeiter mit
  unveröffentlichten Änderungen (eigene oder fremde Entwürfe). Bestehende Filter (Name,
  Rolle, Bereich) bleiben und lassen sich kombinieren.

## Drag-and-drop

Die Planung kennt bereits das „Ziehen“ über mehrere Tage einer Zeile mit gewähltem
Werkzeug. Das bleibt unverändert; ein zusätzliches Verschieben von Einträgen zwischen
Personen wurde bewusst nicht eingeführt, weil es den eingespielten Ablauf
(Werkzeug wählen → Zellen markieren) verändern und Fehlbedienungen begünstigen würde.

## Mobil

- Bestehende Mobilansicht (Dashboard, Mein Plan, Profil, Anträge) unverändert.
- Desktop-Seiten zeigen weiterhin den Hinweis, bieten jetzt aber **„Trotzdem anzeigen“**
  (horizontal scrollbar), z. B. für einen kurzen Blick auf den Gesamtplan.
- Für die mobile Nutzung ist die App (`mobile/`) vorgesehen.
