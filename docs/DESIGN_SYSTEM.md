# Planquadro-Designsystem

Ziel: das bewährte Erscheinungsbild der Einsatzplanung behutsam vereinheitlichen –
**ohne** etablierte Abläufe zu ändern. Grundlage bleibt `public/assets/css/style.css`;
`public/assets/css/planquadro-theme.css` ergänzt Tokens, Dark Mode und Barrierefreiheit.

## Schichten

1. `style.css` – Komponenten (unverändert übernommen)
2. `planquadro-theme.css` – Tokens, Dark Mode, Fokus, Bewegung, Touch
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
  `planquadro-theme.css`). Nach Änderungen an `style.css` Generator erneut ausführen.
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
  Strg+X/Strg+C/Strg+V verschieben bzw. kopieren Einträge (siehe Drag-and-drop).
- `prefers-reduced-motion` wird respektiert; größere Trefferflächen bei Touch-Bedienung.
- Kontraste: gedämpfte Schrift auf 4.5:1 angehoben; Markenfarben werden automatisch
  geprüft und für Text/Schrift angepasst (Vorschau auf der Seite „Organisation“).

## Leistung

- Monatsplanung: Urlaubskonten und WOTs werden je Monat einmal für alle Mitarbeiter
  geladen statt je Zeile. Gemessen mit 88 Mitarbeitern: **856 → 68 Datenbankabfragen,
  293 ms → 54 ms** Serverzeit.
- Große Pläne im Browser (Chromium, November 2026, je Median aus 3 Läufen):

  | Mitarbeitende | Seite | Zellen | DOM-Knoten | HTML | Laden bis `load` | längste Hauptthread-Blockade | Scrollen |
  |---|---|---|---|---|---|---|---|
  | 300 | Monatsplanung | 9.000 | 17.400 | 2,2 MB | 0,75–1,0 s | 0,2–0,27 s | 60 fps |
  | 300 | Gesamtplan | 9.000 | 10.900 | 1,3 MB | 0,5 s | 0,13 s | 60 fps |
  | 600 | Monatsplanung | 18.000 | 34.000 | 4,3 MB | 1,45 s | 0,52 s | 60 fps |
  | 600 | Gesamtplan | 18.000 | 21.100 | 2,5 MB | 1,16 s | 0,28 s | 60 fps |

  Teuer ist nur das einmalige Layout beim Laden und beim Ein-/Ausblenden vieler Zeilen
  (Filter aufheben: 0,15–0,2 s bei 300, 0,29 s bei 600 Zeilen). Scrollen bleibt flüssig.
- **Kein virtuelles Scrollen – bewusst.** Druckansicht, Browsersuche (Strg+F),
  Tastaturnavigation, Mehrfachauswahl per Ziehen, Sortierung, Live-Aktualisierung und
  Revisionsabgleich arbeiten auf allen Zeilen im DOM. Eine Virtualisierung müsste all das
  umbauen, für einen Gewinn, der erst weit über 600 Zeilen spürbar würde.
  `content-visibility` wirkt auf Tabellenzeilen und -zellen nicht (CSS-Containment gilt
  nicht für interne Tabellenelemente) und brachte gemessen keinen Unterschied.
- Stattdessen: Suche reagiert verzögert (120 ms), statt bei jedem Tastendruck alle Zeilen
  neu zu prüfen; Filter fassen nur Zeilen an, deren Sichtbarkeit sich ändert, und
  ermitteln leere Gruppen in einem Durchlauf.
- Schwelle für einen erneuten Blick: ab etwa 1.000 Mitarbeitenden in einem Monatsplan
  (Hochrechnung: > 2 s Laden, ~0,9 s Blockade) – dann zuerst serverseitig nach Team
  vorfiltern oder Gruppen einklappbar machen, nicht virtualisieren.

## Filter

Monats- und Gesamtplan haben dieselbe Filterleiste, gleich angeordnet und gleich bedienbar:

| Element | Wirkung |
|---|---|
| **Suchen** | Name; mehrere Wörter in beliebiger Reihenfolge („max mü“ findet „Müller, Max“). Taste **/** springt in die Suche, **Esc** leert sie, **Eingabe** filtert sofort. |
| **Rolle** | Techniker, Teamleiter, Verwaltung, Lager, Azubis (alle oder je Lehrjahr) |
| **Bereich** | Mitarbeitende mit mindestens einem Einsatz im gewählten Bereich |
| **Sortierung** | Standard (Manager, Teamleiter, übrige) oder A–Z – wird gemerkt |
| **Meine Mitarbeiter** | nur für Manager/Teamleiter: eigenes Team einschließlich Vertretungen (jetzt auch in der Monatsplanung) |
| **Nur Änderungen** | nur Monatsplanung: Zeilen mit unveröffentlichten Entwürfen |
| **✕ Zurücksetzen** | erscheint, sobald ein Filter aktiv ist, und hebt alle auf – auch die Schalter |
| **Trefferzahl** | „12 von 300 Mitarbeitenden“, wird Screenreadern angesagt |

Suche, Rolle und Bereich bleiben beim Monatswechsel und zwischen beiden Seiten erhalten.

## Drag-and-drop

Ergänzung zum Werkzeug, kein Ersatz. Die bisherige Bedienung (Werkzeug wählen → Zellen
klicken oder über Tage einer Zeile ziehen, Tastatur, Bemerkungen, Konfliktdialog) ist
unverändert.

- **Nur ohne gewähltes Werkzeug** (Anzeige „Werkzeug wählen“, ggf. „✕ Ablegen“): Einen
  Eintrag (Einsatz oder Abwesenheit, auch Entwurf) mit der Maus auf eine andere Zelle
  ziehen – auch in eine andere Zeile – **verschiebt** ihn. Mit **Strg** oder **Alt**
  (Mac: ⌘/⌥) gedrückt wird **kopiert**; die Vorschau am Mauszeiger zeigt „Verschieben“
  bzw. „+ Kopieren“ und wechselt beim Drücken/Loslassen der Taste. **Esc** bricht ab.
  Am Rand des Plans wird automatisch gescrollt.
- Gültige Ziele werden grün umrandet, gesperrte rot gestrichelt mit Begründung:
  Feiertag, außerhalb der Beschäftigung, Wochenende ohne Freigabe, Zeile wird gerade von
  anderen bearbeitet, genehmigter Urlaub, Krankmeldung, vertrauliche Abwesenheit („A“).
  Genehmigter Urlaub und Krankmeldungen lassen sich nur kopieren, nicht verschieben
  (erst entsperren, wie bisher über das Kontextmenü).
- Gespeichert wird über denselben Weg wie das Werkzeug (`api/save_bulk`, ein Aufruf für
  Ziel + Quelle) mit Zell-Revisionen: Hat jemand die Zelle inzwischen geändert, erscheint
  der bekannte Konfliktdialog („Trotzdem überschreiben?“). Enthalten Ziel oder Quelle
  Entwürfe anderer, wird wie beim Werkzeug vorher nachgefragt. Der Server prüft Rechte,
  Team-Zuständigkeit und Sperren unabhängig davon.
- Die Einstellung „Nicht veröffentlichen“ des Eintrags wandert mit; Bemerkungen werden
  nicht mitverschoben (wie beim Überschreiben mit dem Werkzeug).
- **Tastatur** (in der Rastersteuerung): **Strg+X** schneidet die fokussierte Zelle aus
  bzw. **Strg+C** kopiert sie (gestrichelter Rahmen), mit den Pfeiltasten zum Ziel,
  **Strg+V** fügt ein. Nach dem Kopieren kann mehrfach eingefügt werden. **Esc** hebt
  die Markierung auf. Jede Aktion wird angesagt („K1 von Muster, Max (03.11.2026) nach
  Beispiel, Eva (04.11.2026) verschoben.“); die Tastenkürzel stehen im
  Hilfetext jeder Zelle.
- **Touch**: Antippen verhält sich wie bisher (kein Ziehen, kein versehentliches
  Verschieben beim Scrollen). Verschieben per Langdruck ist nicht umgesetzt.
- Der Gesamtplan bleibt eine Leseansicht (dort kein Drag-and-drop).
- Neue Interaktionen verwenden keine Inline-Handler (Vorbereitung für eine CSP ohne
  `unsafe-inline`).

## Mobil

- Bestehende Mobilansicht (Dashboard, Mein Plan, Profil, Anträge) unverändert.
- Desktop-Seiten zeigen weiterhin den Hinweis, bieten jetzt aber **„Trotzdem anzeigen“**
  (horizontal scrollbar), z. B. für einen kurzen Blick auf den Gesamtplan.
- Für die mobile Nutzung ist die App (`mobile/`) vorgesehen.
