/** Farb-Hilfen: Kontrast nach WCAG 2.x, lesbare Vordergrundfarbe, Markenfarben je Theme. */

export interface Rgb {
  r: number;
  g: number;
  b: number;
}

/** `#rgb`, `#rrggbb` (mit/ohne #) → RGB; ungültig → null */
export function parseHex(input: string | null | undefined): Rgb | null {
  if (!input) return null;
  let s = input.trim().replace(/^#/, '');
  if (/^[0-9a-f]{3}$/i.test(s)) s = s.split('').map((c) => c + c).join('');
  if (!/^[0-9a-f]{6}$/i.test(s)) return null;
  return { r: parseInt(s.slice(0, 2), 16), g: parseInt(s.slice(2, 4), 16), b: parseInt(s.slice(4, 6), 16) };
}

export function toHex({ r, g, b }: Rgb): string {
  const h = (n: number) => Math.round(Math.min(255, Math.max(0, n))).toString(16).padStart(2, '0');
  return `#${h(r)}${h(g)}${h(b)}`;
}

/** Relative Leuchtdichte nach WCAG */
export function luminance({ r, g, b }: Rgb): number {
  const ch = (v: number) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
}

export function contrastRatio(a: Rgb, b: Rgb): number {
  const la = luminance(a);
  const lb = luminance(b);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

const WHITE: Rgb = { r: 255, g: 255, b: 255 };
const BLACK: Rgb = { r: 0, g: 0, b: 0 };

/**
 * Lesbare Vordergrundfarbe (Weiß oder fast Schwarz) für einen Hintergrund:
 * gewählt wird die Variante mit dem höheren Kontrastverhältnis.
 */
export function readableForeground(bg: string, dark = '#111827', light = '#ffffff'): string {
  const rgb = parseHex(bg);
  if (!rgb) return dark;
  const d = parseHex(dark) ?? BLACK;
  const l = parseHex(light) ?? WHITE;
  return contrastRatio(rgb, l) >= contrastRatio(rgb, d) ? light : dark;
}

/**
 * Vom Server gelieferte Vordergrundfarbe verwenden, solange sie lesbar ist
 * (Kontrast ≥ 4.5), sonst die lesbare Alternative berechnen.
 */
export function safeForeground(bg: string, fg: string | null | undefined, min = 4.5): string {
  const b = parseHex(bg);
  const f = parseHex(fg);
  if (b && f && contrastRatio(b, f) >= min) return toHex(f);
  return readableForeground(bg);
}

function mix(a: Rgb, b: Rgb, t: number): Rgb {
  return { r: a.r + (b.r - a.r) * t, g: a.g + (b.g - a.g) * t, b: a.b + (b.b - a.b) * t };
}

/**
 * Passt eine Farbe so an, dass sie auf `bg` mindestens `min` Kontrast hat:
 * schrittweise Richtung Weiß (dunkler Hintergrund) bzw. Schwarz (heller Hintergrund).
 * Der Farbton bleibt so weit wie möglich erhalten.
 */
export function ensureContrast(color: string, bg: string, min = 4.5): string {
  const c = parseHex(color);
  const b = parseHex(bg);
  if (!c || !b) return color;
  if (contrastRatio(c, b) >= min) return toHex(c);
  const target = luminance(b) < 0.5 ? WHITE : BLACK;
  for (let t = 0.05; t <= 1.0001; t += 0.05) {
    const m = mix(c, target, t);
    if (contrastRatio(m, b) >= min) return toHex(m);
  }
  return toHex(target);
}

export const THEME_SURFACES = {
  light: { bg: '#f3f4f6', card: '#ffffff' },
  dark: { bg: '#0b1220', card: '#111a2b' },
} as const;

export interface BrandTokens {
  /** Füllfarbe für Buttons/aktive Elemente */
  primary: string;
  /** lesbarer Text auf `primary` */
  primaryFg: string;
  /** Markenfarbe als Text/Link auf der Kartenfläche */
  primaryText: string;
  accent: string;
  accentFg: string;
  accentText: string;
}

/** Berechnet Marken-Tokens für ein Theme, sodass Kontraste in Hell und Dunkel passen. */
export function brandTokens(primary: string, accent: string, theme: 'light' | 'dark'): BrandTokens {
  const surface = THEME_SURFACES[theme].card;
  const p = parseHex(primary) ? toHex(parseHex(primary)!) : '#2557d6';
  const a = parseHex(accent) ? toHex(parseHex(accent)!) : '#ff7a1a';
  // Füllflächen müssen sich zumindest von der Fläche abheben (3:1, WCAG 1.4.11)
  const pFill = ensureContrast(p, surface, 3);
  const aFill = ensureContrast(a, surface, 3);
  return {
    primary: pFill,
    primaryFg: readableForeground(pFill),
    primaryText: ensureContrast(p, surface, 4.5),
    accent: aFill,
    accentFg: readableForeground(aFill),
    accentText: ensureContrast(a, surface, 4.5),
  };
}
