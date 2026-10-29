import { describe, expect, it } from 'vitest';
import type { AssignmentDetailsResponse, PlanDay } from '../src/api/types';
import { createMockServer, MOCK_BASE_URL } from '../src/api/mock';
import { decideBack, type BackState } from '../src/platform/back';
import { calendarEventForDetails, calendarEventsFor } from '../src/platform/calendar';
import { nextWorkday, parseDeepLink } from '../src/platform/deeplink';
import { planReminders, reminderId } from '../src/platform/reminders';
import { SCREENSHOT_DEMO } from '../src/platform/screenshot';
import { shareContentFor } from '../src/platform/share';
import { buildWidgetSummary, WIDGET_DAYS } from '../src/platform/widget';

const TODAY = '2026-11-02'; // Montag

function day(date: string, entry: PlanDay['entry'], holiday: PlanDay['holiday'] = null): PlanDay {
  return { date, weekday: 1, holiday, entry };
}

const DAYS: PlanDay[] = [
  day('2026-11-02', { kind: 'assignment', area_id: 3, code: 'K1', label: 'Kunde Nord', color: '#dc2626', fg: '#ffffff', remarks: 'Geheime Bemerkung: Tür 3' }),
  day('2026-11-03', { kind: 'absence', type: 'krank', code: 'K', label: 'Krank', color: '#ef4444', fg: '#ffffff', remarks: 'Arzttermin' }),
  day('2026-11-04', { kind: 'absence', type: 'urlaub_genehmigt', code: 'U', label: 'Urlaub (genehmigt)', color: '#16a34a', fg: '#ffffff', remarks: null }),
  day('2026-11-05', null),
  day('2026-11-06', { kind: 'assignment', area_id: 5, code: 'BÜ', label: 'Büro', color: '#facc15', fg: '#facc15', remarks: null }, { name: 'Testfeiertag', type: 'feiertag' }),
  day('2026-11-07', { kind: 'assignment', area_id: 4, code: 'K2', label: 'Klinikum Süd', color: 'red; background:url(x)', fg: 'nope', remarks: null }),
];

describe('Widget-Zusammenfassung', () => {
  const s = buildWidgetSummary(DAYS, TODAY, 'Demo GmbH', new Date('2026-11-02T06:00:00Z'));
  const json = JSON.stringify(s);

  it('enthält genau die nächsten 7 Tage ab heute', () => {
    expect(s.days).toHaveLength(WIDGET_DAYS);
    expect(s.days[0]!.date).toBe(TODAY);
    expect(s.days[6]!.date).toBe('2026-11-08');
    expect(s.days[0]).toMatchObject({ wd: 'Mo', dm: '02.11.', kind: 'assignment', code: 'K1', label: 'Kunde Nord' });
  });

  it('enthält keine Abwesenheitsgründe und keine Bemerkungen', () => {
    expect(json).not.toMatch(/Krank|Urlaub|Arzttermin|Bemerkung|Tür 3|krank|urlaub/);
    expect(s.days[1]).toMatchObject({ kind: 'absence', code: '', label: 'Abwesend' });
    expect(s.days[2]).toMatchObject({ kind: 'absence', code: '', label: 'Abwesend' });
    // Abwesenheitsfarben verraten die Art nicht
    expect(s.days[1]!.color).toBe(s.days[2]!.color);
  });

  it('freie Tage, Feiertage und fehlende Tage', () => {
    expect(s.days[3]).toMatchObject({ kind: 'free', label: 'frei' });
    expect(s.days[4]!.holiday).toBe('Testfeiertag');
    expect(s.days[6]).toMatchObject({ kind: 'free' }); // nicht geliefert
  });

  it('Farben sind validiert (#rrggbb) und lesbar', () => {
    for (const d of s.days) {
      expect(d.color).toMatch(/^#[0-9a-f]{6}$/);
      expect(d.fg).toMatch(/^#[0-9a-f]{6}$/);
    }
    expect(s.days[4]!.fg).not.toBe('#facc15'); // gelb auf gelb korrigiert
    expect(s.days[5]!.color).toBe('#64748b'); // ungültige Farbe → neutral
  });

  it('nur bekannte Felder (kein Durchreichen von API-Objekten)', () => {
    expect(Object.keys(s).sort()).toEqual(['days', 'generated_at', 'tenant', 'today', 'v']);
    for (const d of s.days) expect(Object.keys(d).sort()).toEqual(['code', 'color', 'date', 'dm', 'fg', 'holiday', 'kind', 'label', 'wd']);
  });

  it('mit dem Demo-Server: eigener Plan ohne Vertrauliches', async () => {
    const mock = createMockServer({ latencyMs: 0 });
    const login = await mock.fetch(`${MOCK_BASE_URL}auth/login`, { method: 'POST', body: JSON.stringify({ username: 'demo', password: 'demo' }) });
    const { access_token } = (await login.json()) as { access_token: string };
    const res = await mock.fetch(`${MOCK_BASE_URL}plan/my?from=2026-01-01&to=2026-03-31`, { headers: { Authorization: `Bearer ${access_token}` } });
    const plan = (await res.json()) as { days: PlanDay[] };
    const types = new Set(plan.days.filter((d) => d.entry?.kind === 'absence').map((d) => (d.entry as { type: string }).type));
    expect(types.size).toBeGreaterThan(0);
    for (let i = 0; i < plan.days.length - 7; i += 7) {
      const w = JSON.stringify(buildWidgetSummary(plan.days, plan.days[i]!.date, 'Demo'));
      expect(w).not.toMatch(/Krank|Urlaub|Gleitzeit|Rollout|Station 4/);
    }
  });
});

describe('Deep Links', () => {
  it('OIDC-Rücksprung bleibt erhalten', () => {
    expect(parseDeepLink('planquadro://auth?code=a&state=b', TODAY)).toEqual({ type: 'oidc', url: 'planquadro://auth?code=a&state=b' });
  });

  it.each([
    ['planquadro://plan', '#/plan'],
    ['planquadro://', '#/plan'],
    ['planquadro://plan/2026-12', '#/plan/2026-12'],
    ['planquadro://tag/2026-11-05', '#/tag/2026-11-05'],
    ['planquadro://team', '#/team'],
    ['planquadro://team/2027-01', '#/team/2027-01'],
    ['planquadro://abwesenheiten/2027', '#/abwesenheiten/2027'],
    ['planquadro://antrag', '#/abwesenheiten/antrag'],
    ['planquadro://urlaub', '#/abwesenheiten/antrag'],
    ['planquadro://mitteilungen', '#/mitteilungen'],
    ['planquadro://mehr', '#/mehr'],
    ['planquadro://screen/team', '#/team'],
    ['planquadro://screen/abwesenheiten', '#/abwesenheiten'],
    ['PLANQUADRO://Screen/Mitteilungen', '#/mitteilungen'],
    ['planquadro:plan', '#/plan'],
  ])('%s → %s', (url, hash) => {
    expect(parseDeepLink(url, TODAY)).toEqual({ type: 'route', hash, theme: null });
  });

  it('tag ohne Datum → nächster Werktag', () => {
    expect(nextWorkday('2026-11-07')).toBe('2026-11-09');
    expect(nextWorkday('2026-11-04')).toBe('2026-11-04');
    expect(parseDeepLink('planquadro://screen/tag', '2026-11-08')).toMatchObject({ hash: '#/tag/2026-11-09' });
  });

  it('Farbschema-Parameter', () => {
    expect(parseDeepLink('planquadro://screen/plan?theme=dark', TODAY)).toEqual({ type: 'route', hash: '#/plan', theme: 'dark' });
    expect(parseDeepLink('planquadro://plan?theme=evil', TODAY)).toMatchObject({ theme: null });
  });

  it.each([
    'https://planquadro.de/plan',
    'javascript:alert(1)',
    'planquadro://tag/2026-02-30',
    'planquadro://tag/../../etc',
    'planquadro://plan/2026-13',
    'planquadro://unbekannt',
    'planquadro://team/2026-11/x/y',
    'planquadro://mehr/x',
    'planquadro://tag/%E0%A4%A',
    'otherapp://plan',
  ])('ungültig: %s', (url) => {
    expect(parseDeepLink(url, TODAY)).toBeNull();
  });

  it('erzeugt nur interne Hash-Routen', () => {
    const r = parseDeepLink('planquadro://tag/2026-11-05', TODAY);
    expect(r?.type === 'route' && r.hash.startsWith('#/')).toBe(true);
  });
});

describe('Android-Zurück-Taste', () => {
  const base: BackState = { ready: true, dialogOpen: false, keyboardOpen: false, backHref: null, hash: '#/plan' };

  it('schließt zuerst Dialog, dann Tastatur', () => {
    expect(decideBack({ ...base, dialogOpen: true, keyboardOpen: true, backHref: '#/plan' })).toEqual({ action: 'close-dialog' });
    expect(decideBack({ ...base, keyboardOpen: true, backHref: '#/plan' })).toEqual({ action: 'hide-keyboard' });
  });

  it('Details → Zurück-Ziel', () => {
    expect(decideBack({ ...base, hash: '#/tag/2026-11-02', backHref: '#/plan/2026-11' })).toEqual({ action: 'navigate', hash: '#/plan/2026-11' });
    expect(decideBack({ ...base, hash: '#/tag/2026-11-02/14', backHref: '#/team/2026-11' })).toEqual({ action: 'navigate', hash: '#/team/2026-11' });
  });

  it('anderer Tab/Monat → Mein Plan', () => {
    expect(decideBack({ ...base, hash: '#/team' })).toEqual({ action: 'navigate', hash: '#/plan' });
    expect(decideBack({ ...base, hash: '#/mehr' })).toEqual({ action: 'navigate', hash: '#/plan' });
    expect(decideBack({ ...base, hash: '#/plan/2027-01' })).toEqual({ action: 'navigate', hash: '#/plan' });
  });

  it('Startbildschirm und Anmeldung → App in den Hintergrund (nicht schließen)', () => {
    expect(decideBack(base)).toEqual({ action: 'minimize' });
    expect(decideBack({ ...base, hash: '' })).toEqual({ action: 'minimize' });
    expect(decideBack({ ...base, ready: false, hash: '#/team' })).toEqual({ action: 'minimize' });
  });

  it('ignoriert Zurück-Ziele außerhalb der App', () => {
    expect(decideBack({ ...base, hash: '#/team', backHref: 'https://evil.example' })).toEqual({ action: 'navigate', hash: '#/plan' });
  });
});

describe('Teilen', () => {
  const det: AssignmentDetailsResponse = {
    date: '2026-11-02',
    user_id: 12,
    entry: { kind: 'assignment', area_id: 3, code: 'K1', label: 'Kunde Nord', color: '#dc2626', fg: '#fff', remarks: 'Interne Bemerkung' },
    area: { id: 3, code: 'K1', name: 'Kunde Nord', type: 'kunde' },
    customer: { name: 'Kunde Nord GmbH', address: 'Musterstr. 1', contact: 'Frau Beispiel', phone: '+49 221 000000', email: 'it@kunde.example', notes: 'Tor 3' },
    location: { name: 'Köln Nord', address: 'Neusser Str. 100, 50733 Köln', lat: 1, lng: 2 },
    colleagues: [{ id: 14, name: 'Beispiel, Erika' }],
  };

  it('Datum, Bereich, Ort – ohne Kontakte, Bemerkungen, Kollegen', () => {
    const c = shareContentFor(det);
    expect(c.title).toBe('Montag, 02.11.2026: K1 – Kunde Nord');
    expect(c.text).toContain('Ort: Neusser Str. 100, 50733 Köln');
    expect(c.text).not.toMatch(/Bemerkung|Beispiel|\+49|@|Tor 3/);
  });

  it('Abwesenheit ohne Art', () => {
    const c = shareContentFor({ ...det, entry: { kind: 'absence', type: 'krank', code: 'K', label: 'Krank', color: '#f00', fg: '#fff', remarks: null }, area: null, customer: null, location: null });
    expect(c.text).not.toMatch(/Krank|krank/);
    expect(c.title).toContain('abwesend');
  });
});

describe('Kalender und Erinnerungen', () => {
  it('nur Einsätze, ganztägig', () => {
    const evs = calendarEventsFor(DAYS);
    expect(evs.map((e) => e.date)).toEqual(['2026-11-02', '2026-11-06', '2026-11-07']);
    const e = evs[0]!;
    expect(e.title).toBe('K1 – Kunde Nord');
    expect(e.endDate - e.startDate).toBeGreaterThanOrEqual(23 * 3600_000);
    expect(new Date(e.startDate).getDate()).toBe(2);
    expect(JSON.stringify(evs)).not.toMatch(/Bemerkung|Krank/);
  });

  it('Details → Termin mit Ort', () => {
    const d: AssignmentDetailsResponse = { date: '2026-11-02', user_id: 12, entry: DAYS[0]!.entry, area: { id: 3, code: 'K1', name: 'Kunde Nord', type: 'kunde' }, customer: null, location: { name: 'Köln', address: null, lat: null, lng: null }, colleagues: [] };
    expect(calendarEventForDetails(d)).toMatchObject({ title: 'K1 – Kunde Nord', location: 'Köln' });
    expect(calendarEventForDetails({ ...d, entry: DAYS[1]!.entry })).toBeNull();
  });

  it('Erinnerung am Vorabend 18 Uhr, nur zukünftig, nur Einsätze', () => {
    const now = new Date(2026, 10, 2, 19, 0, 0); // Mo 19 Uhr: Erinnerung für Di wäre Mo 18 Uhr → vorbei
    const r = planReminders(DAYS, TODAY, now);
    expect(r.map((x) => x.date)).toEqual(['2026-11-06', '2026-11-07']);
    expect(r[0]!.at.getHours()).toBe(18);
    expect(r[0]!.at.getDate()).toBe(5);
    expect(r[0]!.id).toBe(reminderId('2026-11-06'));
    expect(r[0]!.id).toBe(20261106);
    expect(JSON.stringify(r)).not.toMatch(/Krank|Urlaub|Bemerkung/);
  });
});

describe('Screenshot-Modus', () => {
  it('ist in normalen Builds/Tests aus', () => {
    expect(SCREENSHOT_DEMO).toBe(false);
  });

  it('Fixtures enthalten nur erfundene Kontaktdaten', async () => {
    const areas = (await import('../src/api/fixtures/areas.json')).default as Array<{ customer: { email: string | null; phone: string | null } | null }>;
    for (const a of areas) {
      if (a.customer?.email) expect(a.customer.email).toMatch(/\.example$/);
      if (a.customer?.phone) expect(a.customer.phone).toMatch(/0{6}|1{6}/);
    }
  });
});
