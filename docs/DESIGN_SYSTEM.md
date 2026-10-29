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
- Interaktionen verwenden keine Inline-Handler, sondern `data-action` (siehe
  „Interaktion und CSP“).

## Interaktion und CSP

Die Oberfläche läuft unter einer strikten Content-Security-Policy ohne `unsafe-inline` für
Skripte. Knöpfe, Felder und per JS erzeugtes Markup binden Aktionen deklarativ:

```html
<button class="btn btn-primary" data-action="saveSettings">Speichern</button>
<button class="mx" data-action="cMod" data-args='["pwMod"]'>&times;</button>
<select data-action-change="applyFilters">…</select>
<button<?php echo opsActionAttr('deleteUser', [(int)$u['id'], $name]); ?>>Entfernen</button>
```

Regeln, Platzhalter und Hilfsfunktionen: `docs/DEVELOPMENT.md` („Oberfläche: keine
Inline-Handler“). Inline-`style` bleibt erlaubt (dynamische Raster- und Markenfarben).

## Planungshilfen (`planning-plus.js`, `planning-plus.css`)

Ergänzungen der Planungsmatrix – das Raster (Zeilen = Mitarbeitende, Spalten = Tage,
farbige Kürzel-Zellen) und alle bisherigen Bedienwege bleiben unverändert. Alles liegt
in eigenen Dateien; Einhängepunkte in bestehenden Dateien sind einzelne Zeilen
(`header.php`/`footer.php` laden die Dateien, `includes/planning_plus.php` liefert die
Konfiguration als `data-cfg` ohne Inline-Skript, `app.js` meldet `pq:bulk-saved` und
ruft `window.pqTipExtra` im Zell-Tooltip auf). Keine Inline-Handler.

| Element | Beschreibung | Abschaltbar |
|---|---|---|
| **Planleiste** | Raster aus drei Bereichen: Monatsnavigation zentral oben, Filter links, Aktionen rechts; bricht unter 1280 px in drei Zeilen um. Seltene Aktionen (Erfassen, Dauereinsatz, Woche kopieren, Drucken) liegen im Menü **Mehr**. Geprüft bei 768/1024/1280/1440/1920 px: nichts ragt hinaus, kein waagerechtes Scrollen der Seite. | – (reine Darstellung) |
| **Ansicht** (Menü in der Planleiste) | Zeilenhöhe Kompakt (22 px) / Standard (30 px) / Komfort (38 px), Abdeckungszeile, Aufschlüsselung je Bereich, Wochentrenner (Linie vor Montag), Fadenkreuz (Zeile + Tag), Details beim Überfahren, gruppierte Werkzeugleiste, Werkzeug beim Monatswechsel behalten. Je Browser gespeichert (`localStorage` `pq-plus-view`), „Standard wiederherstellen“. | Benutzer |
| **Werkzeugleiste** | Gruppen *Einsatz · Abwesenheit · Bearbeiten · Sichtbarkeit*, darunter eine Statuszeile: **„Aktiv: ‹Werkzeug› · Esc beendet“**, „Werkzeug ablegen“, Rückgängig/Wiederholen, Tastenkürzel. Einsatzbereich über ein Suchfeld (Kürzel oder Name, Pfeiltasten, Eingabe; ARIA-Combobox) statt der langen Auswahlliste – die bisherige `select#toolArea` bleibt als Datenquelle erhalten. **Zuletzt genutzt**: bis zu fünf Bereiche als Farbchips (Tasten 1–5). Aktive Werkzeuge tragen `aria-pressed`. | Benutzer („Werkzeugleiste gruppiert“) |
| **Tastenkürzel** | Ohne Strg/Alt, nicht in Eingabefeldern: K/Umschalt+K/H Krank Attest/ohne/½, U/Umschalt+U/Umschalt+H Urlaub gen./beantr./½, G Gleitzeit, O Offen, S Sperren, L Löschen, M Bemerkung, I Int. Bemerkung, V Veröffentlichen-Schalter, B Bereich suchen, 1–5 zuletzt genutzt, Esc Werkzeug ablegen, ? Übersicht. Bestehende Kürzel (/, Pfeile, Eingabe, Entf, Strg+X/C/V) unverändert. | – |
| **Abdeckungszeile** | `tfoot` unter dem Raster: eingeplante Personen je Tag (alle Zeilen des Plans), am unteren Rand fixiert. ▸ klappt die Zeilen je Bereich auf (`n` bzw. `n/Soll`). Unterbesetzung: Fläche `--pq-under-bg`, Schrift `--pq-under-tx`, Unterstrich (nicht nur Farbe). Tooltip nennt Tag, Anzahl und fehlende Bereiche. Wird bei jeder Zelländerung (auch live) neu berechnet. Druck: ohne Bedienknöpfe. | Mandant (`planning.coverage_row`) + Benutzer |
| **Soll-Besetzung** | Knopf „Soll“ in der Abdeckungszeile (Recht *Bereiche/Feiertage/Stammdaten pflegen*): Tabelle Bereiche × Mo–So. Tabelle `plan_area_targets` (Migration 0009). | leer = keine Vorgabe |
| **Zell-Details** | Im bekannten Tooltip zusätzlich: Kürzel, voller Bereichsname, Kunde/Standort (Stammdaten); in der Monatsplanung „Entwurf (…) von … seit …“ und die letzten drei Änderungen aus `plan_change_log` (`api/planning_plus` `cell_info`, nach Revision zwischengespeichert). Abwesenheitsarten ohne Detailrecht als „Abwesend“, interne Einträge nur mit „Interne Einträge sehen“. | Mandant (`planning.cell_history`) + Benutzer |
| **Legende als Schnellfilter** | Einsatzbereiche in der Legende sind Schaltflächen (`role=button`, `aria-pressed`, Tastatur): andere Zellen werden auf 22 % abgeblendet, Treffer mit Rahmen `--pq-hl-ring`; erneut klicken oder Esc hebt auf. Ansage der Anzahl. | – |
| **Rückgängig / Wiederholen** | Strg+Z / Strg+Y (Umschalt+Strg+Z) bzw. Knöpfe: macht die eigenen Speichervorgänge dieser Sitzung (Werkzeug, Drag-and-drop, Strg+V, Erfassen, Dauereinsatz, Woche kopieren) rückgängig. Gespeichert wird über `saveBulk()` mit der Revision nach dem eigenen Speichern – hat jemand die Zelle inzwischen geändert, erscheint der bekannte Konfliktdialog. Stapel je Browser-Tab (`sessionStorage`, 30 Schritte), überlebt das Neuladen. In Texteingaben bleibt das Rückgängig des Browsers. | Mandant (`planning.undo`) |
| **Woche kopieren** | Menü *Mehr*: Quellwoche (Mo–So) → Zielzeitraum, wochentagsgenau („Vorwoche → diese Woche“, „Woche → Rest des Monats“). Für die aktuell gefilterten Zeilen; Einsätze, optional Gleitzeit/Gesperrt/Offen, optional leere Werktage leeren; nie Urlaub/Krankheit/„A“; Wochenende nur bei Freigabe, Feiertage und Ein-/Austritt beachtet. Vorschau, dann die bekannte Konfliktprüfung (`planWithConflictCheck`). Höchstens 500 Einträge je Vorgang. | Mandant (`planning.period_copy`) |
| **Dashboard** | Reihenfolge nach Rolle über CSS-`order` (keine Inhalte verschoben): Planer *Meldungen → „Heute zu tun“ → Meine Woche → Anträge → Kennzahlen …*, Mitarbeitende *Meldungen → Meine Woche → Anträge → WOTs → Kennzahlen …*. Im Kopf „Heute: ‹Einsatz›“. „Heute zu tun“ (`.pq-task`): offene Anträge → *Prüfen*, Entwurf ‹Monat› unveröffentlicht → *Ansehen/Veröffentlichen*, Unterbesetzung (Soll), unverplante Arbeitstage (14 Tage), nächster Monat ab dem 15. noch nicht veröffentlicht; sonst „Alles erledigt“. | Widget `planning` (Organisation → Dashboard) |
| **Druck** | Name der Organisation im Druckkopf. | – |

Tokens (hell/dunkel): `--pq-week-line`, `--pq-cross`, `--pq-hl-ring`, `--pq-under-bg`,
`--pq-under-tx`, `--pq-cov-bg`, `--pq-pop-sh`. Getestet: `tests/js/planning-plus.test.mjs`
(reine Logik), `tests/Integration/PlanningPlusTest.php` (Rechte, Vertraulichkeit, Regeln).

## Mobil

- Bestehende Mobilansicht (Dashboard, Mein Plan, Profil, Anträge) unverändert.
- Desktop-Seiten zeigen weiterhin den Hinweis, bieten jetzt aber **„Trotzdem anzeigen“**
  (horizontal scrollbar), z. B. für einen kurzen Blick auf den Gesamtplan.
- Für die mobile Nutzung ist die App (`mobile/`) vorgesehen.

## Planungsassistent – Bausteine (`pqa-`)

Eigenes Stylesheet `public/assets/css/assistant.css`, nur Tokens aus `style.css` und
`planquadro-theme.css`; hell und dunkel, Druck immer hell. Logik ohne DOM in
`public/assets/js/assistant-core.js` (getestet in `tests/js/assistant.test.mjs`),
Oberfläche in `assistant.js`. CSP: kein Inline-Skript außer dem JSON-Datenblock
(`<script type="application/json" nonce>`), keine Inline-Handler; Einsatzfarben setzt das
Skript per CSSOM (`data-css` → `element.style.cssText`), nicht als `style`-Attribut im Markup.

| Baustein | Klasse | Verwendung |
|---|---|---|
| Schrittleiste | `.pqa-steps` / `.pqa-step` (`.is-active`, `.is-done`) | Geführter Ablauf; `<nav>` + `<ol>`, aktiver Schritt mit `aria-current="step"`, Kurzfassung je Schritt in `<small>` |
| Karte mit Abschnitt | `.card.pqa-sec`, `.pqa-h2`, `.pqa-h3` | Formulargruppen je Schritt |
| Einklappbereich | `details.pqa-more` + `.pqa-sum` | Progressive Offenlegung; die Kurzfassung der Einstellungen steht rechts in der Zeile |
| Umschalter | `.pqa-toggles` / `label.pqa-tg > input + span` | Mehrfachauswahl (Wochentage, Rollen, Lehrjahre, Bereiche); echte Checkboxen, Fokusrahmen am `span` |
| Segmentschalter | `.pqa-seg` (Radio) | Eine aus wenigen Optionen (Stammbereich, Personenkreis) |
| Auswahlkarten | `.pqa-radio-cards` | Optionen mit Erklärung (Umgang mit belegten Tagen) |
| Schalter | `label.pqa-switch > input[role=switch] + span` | An/aus (Azubis einbeziehen, Nur Verplante) |
| Zahlen-Stepper | `.pqa-stepper` | −/+ für zentrale Zahlen (Bedarf, Mindestbesetzung); Touch ≥ 44 px |
| Zahlenzeile | `.pqa-num-row` + `.pqa-num` | Beschriftung links mit Erklärung, Zahl rechts |
| Pille | `.pqa-pill` (`.is-on`, `aria-pressed`) | Schnellwahl Zeitraum, Filter der Prüfliste |
| Kennzahl | `.pqa-kpis` / `.pqa-kpi` (`.is-ok/.is-part/.is-bad`), `.pqa-bar` | Abdeckung, Plätze, Personen, Blocker, Warnungen; Blocker/Warnungen filtern per Klick |
| Raster | `table.pt.pqa-pt` (`.is-compact` für die Live-Vorschau), `.pqa-cell` | Grundlage ist die Planungstabelle der Monatsplanung; Zellen `pick`, `bad`, `foreign`, `fromfree`, `fromplan`, `abs`, `lock`, `skip`, `repl`, `school`; Spaltenkopf `.pqa-dh` als Schaltfläche; roving tabindex (eine Zelle per Tab erreichbar, Pfeiltasten über `PQACore.gridMove`) |
| Live-Vorschau | `.pqa-live` | Schritte 1–2: Kennzahlen + kompaktes Raster, rechnet 650 ms nach der letzten Eingabe neu; unter 1280 px unterhalb des Formulars |
| Prüfpunkt | `.pqa-cf` (`.is-block/.is-warn/.is-info`) + `.pqa-cf-help` | Befund, Erklärung „was tun“ (`PQACore.explain`), Sprung zur Zelle / passende Personen |
| Übernahme | `.pqa-apply`, `.pqa-facts`, `.pqa-skip`, `.pqa-done` | Zusammenfassung des Probelaufs („27 Einsätze für 9 Personen, 3 Konflikte übersprungen“), übersprungene Zellen nach Grund gruppiert |
| Rückgängig | `.pqa-btn-undo`, `.pqa-undo-pill` | Deutlich sichtbar in Schritt 4, im Kopf und auf der Startseite |
| Fußleiste | `.pqa-foot` | Klebt unten: Hinweis zum Schritt, Zurück, Weiter mit sprechender Beschriftung („Vorschlag prüfen“, „Als Entwurf übernehmen“ in Grün) |
| Menü | `.pqa-menu` / `.pqa-menu-list[role=menu]` | „Mehr“: seltene Aktionen; Pfeiltasten, Esc gibt den Fokus zurück |
| Bestätigung | Dialog `#ovAsk` (Bauweise `.modal-bg`) | Ersetzt `confirm()`: Ersetzen, Löschen, Rückgängig, Neu berechnen |

Barrierefreiheit: Fokus bei „Neue Planung“ im Pflichtfeld, Fehlermeldung am Feld
(`aria-invalid`, `aria-describedby`), Ansagen über eine `role=status`-Region, Ansicht-Reiter
als `role=tablist` mit Pfeiltasten, Kontextmenü per Kontextmenü-Taste oder Umschalt+F10,
jede Rasterzelle mit sprechender Beschriftung („Berger, Jonas – Mo 02.11.: geplant SWN …“).
Responsiv: zweispaltig ab 1280 px (Formular | Vorschau bzw. Raster | Prüfung), darunter
einspaltig; Schrittleiste unter 900 px ohne Kurzfassung.
