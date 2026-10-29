#!/usr/bin/env python3
"""
Planquadro – Logo-Dateien erzeugen (SVG)
------------------------------------------------------------------------
Bildmarke: das Planquadrat – ein 3×3-Raster aus Einsatzfeldern, ein Feld in
Signal-Orange („jeder Einsatz an seinem Platz“).
Wortmarke: „plan“ + „quadro“, Schrift Inter (SIL Open Font License), als
Pfade eingebettet, damit das Logo ohne installierte Schrift gleich aussieht.

Aufruf:  python3 tools/brand/build_logo.py <InterVariable.ttf> <Zielordner>
Benötigt: fonttools, uharfbuzz (pip install fonttools uharfbuzz)
"""
import io, sys
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
import uharfbuzz as hb

INK, BLUE, SIGNAL, WHITE = '#14213D', '#2557D6', '#FF7A1A', '#FFFFFF'
LIGHT_ON_WHITE, LIGHT_ON_DARK = '#C9D5F5', 'rgba(255,255,255,.28)'

# Raster (Zeile, Spalte): 'f' = geplant, 'l' = frei, 's' = Signal
PATTERN = [['f', 'l', 'f'], ['l', 'f', 's'], ['f', 'l', 'l']]

def mark(x, y, size, full, light, signal=SIGNAL):
    """Bildmarke als SVG-Gruppe; size = Kantenlänge."""
    u = size / 64.0
    cell, gap, off, r = 16 * u, 4 * u, 4 * u, 4 * u
    out = []
    for ri, row in enumerate(PATTERN):
        for ci, k in enumerate(row):
            fill = {'f': full, 'l': light, 's': signal}[k]
            out.append(f'<rect x="{x + off + ci * (cell + gap):.2f}" y="{y + off + ri * (cell + gap):.2f}" '
                       f'width="{cell:.2f}" height="{cell:.2f}" rx="{r:.2f}" fill="{fill}"/>')
    return '\n  '.join(out)

def instance(path, wght):
    f = TTFont(path)
    inst = instancer.instantiateVariableFont(f, {'wght': wght, 'opsz': 32})
    buf = io.BytesIO(); inst.save(buf)
    return TTFont(io.BytesIO(buf.getvalue())), buf.getvalue()

def text_path(path, text, wght, x, baseline, size, tracking=-0.02):
    """Text als Pfad; liefert (svg_path_d, Breite in px)."""
    font, data = instance(path, wght)
    face = hb.Face(data); hfont = hb.Font(face)
    buf = hb.Buffer(); buf.add_str(text); buf.guess_segment_properties()
    hb.shape(hfont, buf, {'kern': True, 'liga': True})
    upm = font['head'].unitsPerEm; scale = size / upm
    gs = font.getGlyphSet(); order = font.getGlyphOrder()
    pen = SVGPathPen(gs); cx = 0.0
    for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
        name = order[info.codepoint]
        tp = TransformPen(pen, (scale, 0, 0, -scale, x + (cx + pos.x_offset) * scale, baseline))
        gs[name].draw(tp)
        cx += pos.x_advance + tracking * upm
    return pen.getCommands(), (cx - tracking * upm) * scale

def svg(w, h, body, title):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w:.0f} {h:.0f}" width="{w:.0f}" height="{h:.0f}" role="img" aria-label="{title}">\n'
            f'  <title>{title}</title>\n  {body}\n</svg>\n')

def logo(font, on_dark=False):
    """Horizontales Logo: Bildmarke + Wortmarke (Höhe 64)."""
    a, b = (WHITE, '#8FB0FF') if on_dark else (INK, BLUE)
    light = LIGHT_ON_DARK if on_dark else LIGHT_ON_WHITE
    full = WHITE if on_dark else BLUE
    d1, w1 = text_path(font, 'plan', 600, 80, 46, 40)
    d2, w2 = text_path(font, 'quadro', 800, 80 + w1 - 1.2, 46, 40)
    w = 80 + w1 - 1.2 + w2 + 4
    body = mark(0, 0, 64, full, light) + f'\n  <path d="{d1}" fill="{a}"/>\n  <path d="{d2}" fill="{b}"/>'
    return svg(w, 64, body, 'Planquadro')

def app_icon(size=1024, radius=0.2237, maskable=False):
    """App-Icon: Bildmarke weiß auf Tinte. maskable = mehr Rand (sichere Zone 80 %)."""
    m = size * (0.24 if maskable else 0.17)
    bg = f'<rect width="{size}" height="{size}" fill="{INK}"/>' if maskable else \
         f'<rect width="{size}" height="{size}" rx="{size * radius:.1f}" fill="{INK}"/>'
    return svg(size, size, bg + '\n  ' + mark(m, m, size - 2 * m, WHITE, LIGHT_ON_DARK), 'Planquadro')

def main():
    font, out = sys.argv[1], sys.argv[2].rstrip('/')
    files = {
        'planquadro-mark.svg': svg(64, 64, mark(0, 0, 64, BLUE, LIGHT_ON_WHITE), 'Planquadro'),
        'planquadro-mark-white.svg': svg(64, 64, mark(0, 0, 64, WHITE, LIGHT_ON_DARK), 'Planquadro'),
        'planquadro-logo.svg': logo(font),
        'planquadro-logo-dark.svg': logo(font, on_dark=True),
        'planquadro-app-icon.svg': app_icon(),
        'planquadro-app-icon-maskable.svg': app_icon(maskable=True),
        'favicon.svg': svg(64, 64, f'<rect width="64" height="64" rx="14" fill="{INK}"/>\n  ' + mark(8, 8, 48, WHITE, LIGHT_ON_DARK), 'Planquadro'),
    }
    for name, content in files.items():
        with open(f'{out}/{name}', 'w') as fh: fh.write(content)
        print('geschrieben:', name)

if __name__ == '__main__':
    main()
