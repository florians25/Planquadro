/**
 * Minimaler, sicherer HTML-Templating-Helfer: Interpolationen werden immer escaped,
 * außer sie sind bereits `SafeHtml` (Ergebnis von `html` oder `raw`).
 */
export class SafeHtml {
  constructor(readonly value: string) {}
  toString(): string {
    return this.value;
  }
}

export function esc(value: unknown): string {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

type Interp = SafeHtml | string | number | boolean | null | undefined | Interp[];

function render(v: Interp): string {
  if (v === null || v === undefined || v === false) return '';
  if (v instanceof SafeHtml) return v.value;
  if (Array.isArray(v)) return v.map(render).join('');
  return esc(v);
}

export function html(strings: TemplateStringsArray, ...values: Interp[]): SafeHtml {
  let out = '';
  strings.forEach((s, i) => {
    out += s;
    if (i < values.length) out += render(values[i] as Interp);
  });
  return new SafeHtml(out);
}

/** Nur für vertrauenswürdige, selbst erzeugte Markup-Strings verwenden. */
export function raw(s: string): SafeHtml {
  return new SafeHtml(s);
}

/** Nur Farben im Format #rgb/#rrggbb durchlassen (für style-Attribute). */
export function cssColor(value: string | null | undefined, fallback = '#6b7280'): string {
  return value && /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(value.trim()) ? value.trim() : fallback;
}

export function setHtml(el: Element, content: SafeHtml): void {
  el.innerHTML = content.value;
}
