#!/usr/bin/env python3
"""
Erzeugt den Dark-Mode-Teil von public/assets/css/opspilot-theme.css aus
public/assets/css/style.css.

Warum generiert: style.css enthält viele fest verdrahtete helle Farben
(#fff-Hintergründe, dunkle Schrift). Statt das bewährte Stylesheet umzubauen,
werden für den Dark Mode passende Überschreibungen abgeleitet:
  * sehr helle Hintergründe  → var(--card) bzw. var(--s)
  * Pastell-Hintergründe     → abgedunkelte Mischung der Farbe
  * dunkle Schrift           → var(--tx) / var(--mu)
  * helle Rahmen             → var(--bd)
Aufruf:  python3 tools/gen-dark-css.py   (schreibt zwischen die Marker)
"""
import re, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'public/assets/css/style.css'
DST = ROOT / 'public/assets/css/opspilot-theme.css'
BEGIN, END = '/* >>> generated dark mode (tools/gen-dark-css.py) >>> */', '/* <<< generated dark mode <<< */'
PFX = 'html[data-theme="dark"]'

def hex_rgb(h):
    h = h.lstrip('#')
    if len(h) == 3: h = ''.join(c * 2 for c in h)
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))

def lum(h):
    def ch(v):
        v /= 255
        return v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4
    r, g, b = (ch(x) for x in hex_rgb(h))
    return 0.2126 * r + 0.7152 * g + 0.0722 * b

def sat(h):
    r, g, b = hex_rgb(h)
    return (max(r, g, b) - min(r, g, b)) / 255

COLOR = r'#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b|\bwhite\b'

def map_bg(c):
    c = '#ffffff' if c == 'white' else c
    L = lum(c)
    if L > 0.97: return 'var(--card)'
    if L > 0.80 and sat(c) < 0.08: return 'var(--s)'
    if L > 0.70: return f'color-mix(in srgb, {c} 16%, var(--card))'
    return None

def map_fg(c):
    if c == 'white': return None
    L = lum(c)
    if L < 0.04 and sat(c) < 0.25: return 'var(--tx)'
    if L < 0.25 and sat(c) < 0.2: return 'var(--tx2)' if L < 0.12 else 'var(--mu)'
    if L < 0.12 and sat(c) >= 0.2: return f'color-mix(in srgb, {c} 45%, #ffffff)'
    return None

def map_border(c):
    c = '#ffffff' if c == 'white' else c
    return 'var(--bd)' if lum(c) > 0.6 and sat(c) < 0.15 else None

def convert_decl(prop, val):
    p = prop.strip().lower()
    if 'gradient' in val or 'url(' in val: return None
    m = re.search(COLOR, val)
    if not m: return None
    c = m.group(0)
    if p in ('background', 'background-color'):
        if p == 'background' and len(val.split()) > 2: return None
        n = map_bg(c)
    elif p == 'color':
        n = map_fg(c)
    elif p in ('border', 'border-color', 'border-top', 'border-bottom', 'border-left', 'border-right'):
        n = map_border(c)
    else:
        return None
    if not n: return None
    imp = ' !important' if '!important' in val else ''
    return f'{p}:{re.sub(COLOR, n, val.replace("!important", "").strip(), count=1)}{imp}'

def walk(css):
    """liefert (media|None, selector, body) für alle Regeln"""
    out, i, n = [], 0, len(css)
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
    def parse(block, media):
        pos = 0
        while True:
            m = re.search(r'([^{}]+)\{', block[pos:])
            if not m: break
            sel = m.group(1).strip()
            start = pos + m.end()
            depth, j = 1, start
            while j < len(block) and depth:
                if block[j] == '{': depth += 1
                elif block[j] == '}': depth -= 1
                j += 1
            body = block[start:j - 1]
            if sel.startswith('@media'):
                parse(body, sel)
            elif not sel.startswith('@'):
                out.append((media, sel, body))
            pos = j
    parse(css, None)
    return out

def main():
    rules = []
    for media, sel, body in walk(SRC.read_text(encoding='utf-8')):
        if 'print' in (media or ''): continue
        decls = []
        for d in body.split(';'):
            if ':' not in d: continue
            prop, val = d.split(':', 1)
            c = convert_decl(prop, val)
            if c: decls.append(c)
        if not decls: continue
        sels = ', '.join(f'{PFX} {s.strip()}' if not s.strip().startswith(':root') else PFX for s in sel.split(','))
        rule = f'{sels}{{{";".join(decls)}}}'
        rules.append(f'{media}{{{rule}}}' if media else rule)
    gen = BEGIN + '\n' + '\n'.join(rules) + '\n' + END
    dst = DST.read_text(encoding='utf-8')
    if BEGIN in dst:
        dst = dst[:dst.index(BEGIN)] + gen + dst[dst.index(END) + len(END):]
    else:
        dst += '\n' + gen + '\n'
    DST.write_text(dst, encoding='utf-8')
    print(f'{len(rules)} Regeln erzeugt')

if __name__ == '__main__':
    main()
