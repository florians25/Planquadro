# Planquadro – Markenhandbuch

Kurzfassung der Corporate Identity: Name, Logo, Farben, Schrift, Sprache. Die
technische Umsetzung in der Oberfläche beschreibt [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md).

## 1. Name

| | |
|---|---|
| Produktname | **Planquadro** (immer so geschrieben: großes P, ein Wort) |
| Kurzname | **PQ** – für App-Icon-Beschriftungen, Tabellenköpfe, technische Kürzel (`PQ_*`-Variablen) |
| Beschreibung | Einsatz- und Teamplanung für Service-Organisationen |
| Englisch | Field & Onsite Service Planning Platform |
| Claim | „Jeder Einsatz an seinem Platz.“ |

Der Name kommt vom *Planquadrat*: Herzstück ist die Planungsmatrix aus Mitarbeitenden ×
Tagen – jedes Feld ein Einsatz.

Nicht: „PlanQuadro“, „Plan Quadro“, „planquadro“ im Fließtext. Die Kleinschreibung gibt es
nur in der Wortmarke.

## 2. Logo

![Logo](../public/assets/brand/planquadro-logo.svg)

| Datei | Verwendung |
|---|---|
| `public/assets/brand/planquadro-logo.svg` | Standard auf hellem Grund |
| `public/assets/brand/planquadro-logo-dark.svg` | auf dunklem Grund (Tinte, Fotos) |
| `public/assets/brand/planquadro-mark.svg` | Bildmarke allein, hell |
| `public/assets/brand/planquadro-mark-white.svg` | Bildmarke allein, dunkel (z. B. Navigationsleiste) |
| `public/assets/brand/planquadro-app-icon.svg` | App-Icon (abgerundet) |
| `public/assets/brand/planquadro-app-icon-maskable.svg` | App-Icon randlos (Android adaptiv, iOS, PWA „maskable“) |
| `public/assets/brand/favicon.svg`, `favicon-32.png`, `apple-touch-icon.png`, `icon-192/512.png` | Browser, Lesezeichen, Startbildschirm |

**Bildmarke – das Planquadrat:** 3 × 3 Felder mit abgerundeten Ecken. Volle Felder = geplant,
helle Felder = frei, **ein** Feld in Signal-Orange = der Einsatz, um den es gerade geht. Die
Anordnung ist fest und wird nicht verändert, gespiegelt oder neu eingefärbt.

**Wortmarke:** „plan“ (Inter SemiBold, Tinte) + „quadro“ (Inter ExtraBold, Quadro-Blau),
ohne Leerzeichen. Die Schrift ist in die SVG-Dateien als Pfade eingebettet.

**Schutzzone:** rundum mindestens ein Rasterfeld (¼ der Höhe der Bildmarke).
**Mindestgröße:** Bildmarke 16 px, Logo mit Wortmarke 24 px Höhe.

Nicht erlaubt: Verzerren, Schatten/Effekte, andere Farben als in Abschnitt 3, Bildmarke ohne
Signal-Feld, Logo auf unruhigem Hintergrund ohne Fläche.

**White-Label:** Mandanten können in *Organisation → Branding* eigenen Namen, eigene Farben
und ein eigenes Logo hinterlegen. Dann tritt Planquadro in der Oberfläche zurück; die
Bildmarke erscheint nur noch, wenn kein eigenes Logo gesetzt ist.

Erzeugen/ändern: `python3 tools/brand/build_logo.py <InterVariable.ttf> public/assets/brand`,
danach `node tools/brand/render_png.js` (alle PNG-Varianten, App-Icons, Splash-Screens).

## 3. Farben

| Name | Hex | Verwendung |
|---|---|---|
| **Tinte** | `#14213D` | Navigation, dunkle Flächen, Wortmarke „plan“, App-Icon-Grund |
| **Quadro-Blau** | `#2557D6` | Primärfarbe: Buttons, Links, Auswahl, Wortmarke „quadro“ |
| Quadro-Blau hell | `#4C7BF0` | Hover, Hervorhebungen auf dunklem Grund |
| Quadro-Blau Fläche | `#E8EEFC` | hinterlegte Flächen, Markierungen |
| Quadro-Blau dunkel | `#1B44AE` | gedrückt/aktiv |
| **Signal-Orange** | `#FF7A1A` | Akzent: das Signal-Feld, „heute“, Aufmerksamkeit – sparsam |
| Signal-Orange Text | `#C2410C` | Orange als Schrift auf Weiß (Kontrast ≥ 4.5:1) |
| Erfolg | `#16A34A` | Bestätigungen |
| Warnung | `#D97706` | Hinweise |
| Fehler | `#DC2626` | Fehler, Sperren |

Kontrast (WCAG 2.2): Quadro-Blau auf Weiß 6.2:1, Weiß auf Tinte 16:1, Signal-Orange-Text 5.2:1 – alle für Text
geeignet. Signal-Orange ist **keine** Textfarbe auf Weiß (2.6:1); dafür gibt es die Textvariante.

## 4. Schrift

- **Logo und Marketing:** Inter (SIL Open Font License 1.1, frei auch für kommerzielle
  Nutzung), SemiBold/ExtraBold für Überschriften, Regular für Text, Ziffern tabellarisch.
- **Anwendung:** Systemschrift (San Francisco, Segoe UI, Roboto; Inter, falls installiert) –
  schnell, ohne Download, gleiche Spaltenbreiten wie bisher.

## 5. Sprache und Ton

- Klar, freundlich, auf den Punkt – wie eine gute Disposition. Du-/Sie-Form: in der
  Anwendung **Sie**, im Marketing wahlweise du.
- Fachbegriffe der Nutzer verwenden: Einsatz, Bereich, Abwesenheit, Vertretung, Entwurf,
  veröffentlichen.
- Keine Schuldzuweisung in Fehlermeldungen; immer sagen, was jetzt zu tun ist.

## 6. Bildsprache

Echte Situationen aus dem Service (Technik vor Ort, Teams, Dispositionen), natürliches Licht,
keine gestellten Stockfotos. Illustrationen aus Rasterfeldern dürfen das Planquadrat
aufnehmen (Felder, ein orangefarbenes Signal).

## 7. Vorlagen

- E-Mail-Signatur: „Planquadro · Einsatz- und Teamplanung für Service-Organisationen“
- App-Store-Untertitel (max. 30 Zeichen): „Einsatz- und Teamplanung“
- Kurzbeschreibung (80 Zeichen): „Einsätze, Teams und Abwesenheiten planen – im Browser und als App.“
