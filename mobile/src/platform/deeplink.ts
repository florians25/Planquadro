/**
 * Deep Links `planquadro://…` (reine Logik, ohne Plugins – getestet in tests/native.test.ts).
 *
 *   planquadro://auth?code=…&state=…      OIDC-Rücksprung (unverändert)
 *   planquadro://plan[/YYYY-MM]           Mein Plan (Monat)
 *   planquadro://tag/YYYY-MM-DD           Einsatzdetails eines Tages
 *   planquadro://team[/YYYY-MM]           Teamübersicht (nur mit Recht plan_view – prüft der Router)
 *   planquadro://abwesenheiten[/YYYY]     Abwesenheiten
 *   planquadro://antrag | urlaub          Abwesenheiten mit Antragsformular (Schnellaktion)
 *   planquadro://mitteilungen | mehr      Tabs
 *   planquadro://screen/<name>[/…]        Alias für CI-Screenshots (z. B. screen/team, screen/tag)
 *
 * Optionaler Parameter `?theme=light|dark|system` wird nur im Screenshot-Demo-Build ausgewertet.
 */
import { addDays, isIsoDate, isoWeekday } from '../lib/dates';

export type DeepLink =
  | { type: 'oidc'; url: string }
  | { type: 'route'; hash: string; theme: 'light' | 'dark' | 'system' | null };

export const DEEP_LINK_SCHEME = 'planquadro';

const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;
const YEAR = /^\d{4}$/;

/** Nächster Werktag (Mo–Fr) ab `today` einschließlich. */
export function nextWorkday(today: string): string {
  let d = today;
  while (isoWeekday(d) >= 6) d = addDays(d, 1);
  return d;
}

function routeFor(segments: string[], today: string): string | null {
  const [name = 'plan', a, ...rest] = segments;
  if (rest.length > 1) return null;
  switch (name) {
    case 'plan':
    case 'mein-plan':
      if (a === undefined) return '#/plan';
      return MONTH.test(a) ? `#/plan/${a}` : null;
    case 'tag':
    case 'einsatz':
    case 'details':
      if (a === undefined) return `#/tag/${nextWorkday(today)}`;
      return isIsoDate(a) ? `#/tag/${a}` : null;
    case 'team':
      if (a === undefined) return '#/team';
      return MONTH.test(a) ? `#/team/${a}` : null;
    case 'abwesenheiten':
      if (a === undefined) return '#/abwesenheiten';
      if (a === 'antrag') return '#/abwesenheiten/antrag';
      return YEAR.test(a) ? `#/abwesenheiten/${a}` : null;
    case 'antrag':
    case 'urlaub':
      return a === undefined ? '#/abwesenheiten/antrag' : null;
    case 'mitteilungen':
      return a === undefined ? '#/mitteilungen' : null;
    case 'mehr':
      return a === undefined ? '#/mehr' : null;
    default:
      return null;
  }
}

/**
 * Zerlegt eine Deep-Link-URL. Unbekannte oder fehlerhafte Links → `null` (werden ignoriert).
 * `today` (YYYY-MM-DD) wird für `tag` ohne Datum benötigt.
 */
export function parseDeepLink(url: string, today: string): DeepLink | null {
  const m = /^planquadro:(?:\/\/)?([^?#]*)(?:\?([^#]*))?/i.exec(url.trim());
  if (!m) return null;
  let segments: string[];
  try {
    segments = (m[1] ?? '').split('/').filter(Boolean).map((s) => decodeURIComponent(s).toLowerCase());
  } catch {
    return null;
  }
  if (segments[0] === 'auth') return { type: 'oidc', url };
  if (segments[0] === 'screen') segments = segments.slice(1);
  const hash = routeFor(segments, today);
  if (!hash) return null;
  const t = new URLSearchParams(m[2] ?? '').get('theme');
  const theme = t === 'light' || t === 'dark' || t === 'system' ? t : null;
  return { type: 'route', hash, theme };
}
