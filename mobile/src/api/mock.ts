/**
 * Demo-/Mock-Modus: ein im Browser laufender Fake-Server, der den API-Vertrag
 * (docs/API.md) mit Beispieldaten nachbildet. Wird als `fetch`-Ersatz in den
 * ApiClient gesteckt, sodass der komplette Client-Pfad (Header, Fehler, Refresh)
 * auch ohne Backend durchlaufen wird.
 *
 * Testzugänge (Passwort immer „demo“):
 *   beliebiger Name → normal angemeldet
 *   „gesperrt“      → 403 account_locked
 *   „neu“           → 403 password_change_required
 *   „wartung“       → 503 maintenance
 */
import absencesFx from './fixtures/absences.json';
import areasFx from './fixtures/areas.json';
import configFx from './fixtures/config.json';
import holidaysFx from './fixtures/holidays.json';
import meFx from './fixtures/me.json';
import notificationsFx from './fixtures/notifications.json';
import teamFx from './fixtures/team.json';
import type {
  AbsenceRequestItem,
  AssignmentDetailsResponse,
  ConfigResponse,
  Holiday,
  MeResponse,
  MyAbsencesResponse,
  MyPlanResponse,
  NotificationItem,
  PlanDay,
  PlanEntry,
  TeamCell,
  TeamPlanResponse,
  TokenResponse,
} from './types';
import { addDays, daysInMonth, eachDay, isIsoDate, isoWeek, isoWeekday, monthRange, parseIsoDate } from '../lib/dates';

export const MOCK_BASE_URL = 'https://demo.planquadro.invalid/api/v1/';

interface Area {
  id: number;
  code: string;
  name: string;
  type: string;
  color: string;
  fg: string;
  remarks: string | null;
  customer: AssignmentDetailsResponse['customer'];
  location: AssignmentDetailsResponse['location'];
}

const AREAS = areasFx as Area[];
const HOLIDAYS = new Map<string, Holiday>((holidaysFx as Array<{ date: string; name: string; type: string }>).map((h) => [h.date, { name: h.name, type: h.type }]));
const ABS_TYPES = absencesFx.types as Record<string, { code: string; label: string; color: string; fg: string }>;

export interface MockOptions {
  latencyMs?: number;
  /** Lebensdauer des Access-Tokens im Demo-Modus (Standard 120 s, damit Refresh sichtbar wird) */
  accessTtlSec?: number;
  now?: () => number;
  /** Mandanten-Slug, den der Mock erwartet (null = egal) */
  requireTenant?: string | null;
}

interface MockState {
  access: Map<string, { userId: number; exp: number }>;
  refreshUsed: Set<string>;
  revokedFamilies: Set<string>;
  notifications: NotificationItem[];
  requests: AbsenceRequestItem[];
  devices: Map<number, unknown>;
  cursor: number;
  pendingChanges: string[];
  seq: number;
  /** Zähler für Tests */
  calls: Array<{ method: string; path: string; headers: Record<string, string> }>;
  oidcEnabled: boolean;
  approvals: boolean;
}

function hash(s: string): number {
  let h = 2166136261;
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return h >>> 0;
}

function absenceEntry(type: string, remarks: string | null = null): PlanEntry {
  const t = ABS_TYPES[type] ?? ABS_TYPES['vertraulich']!;
  return { kind: 'absence', type, code: t.code, label: t.label, color: t.color, fg: t.fg, remarks };
}

/** Deterministischer Beispielplan für Benutzer/Tag. */
function entryFor(userId: number, date: string): PlanEntry | null {
  const wd = isoWeekday(date);
  if (wd >= 6 || HOLIDAYS.get(date)?.type === 'feiertag') return null;
  const { week } = isoWeek(date);
  const h = hash(`${userId}:${date}`);
  // Urlaubswoche je Benutzer
  if ((week + userId) % 11 === 0) return absenceEntry('urlaub_genehmigt');
  if (h % 89 === 0) return absenceEntry('krank');
  if (h % 29 === 0) return absenceEntry('gleitzeit');
  const area = AREAS[(week + userId + (wd >= 4 ? 1 : 0)) % AREAS.length]!;
  return { kind: 'assignment', area_id: area.id, code: area.code, label: area.name, color: area.color, fg: area.fg, remarks: area.remarks };
}

function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  if (status === 204) return new Response(null, { status });
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
}

function err(status: number, code: string, message: string, headers: Record<string, string> = {}): Response {
  return json(status, { error: { code, message } }, headers);
}

function tokenId(prefix: string): string {
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  return prefix + [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
}

export function createMockServer(opts: MockOptions = {}) {
  const now = opts.now ?? Date.now;
  const latency = opts.latencyMs ?? 250;
  const ttl = opts.accessTtlSec ?? 120;
  const state: MockState = {
    access: new Map(),
    refreshUsed: new Set(),
    revokedFamilies: new Set(),
    notifications: structuredClone(notificationsFx.items) as NotificationItem[],
    requests: structuredClone(absencesFx.requests) as AbsenceRequestItem[],
    devices: new Map(),
    cursor: 1842,
    pendingChanges: [],
    seq: 100,
    calls: [],
    oidcEnabled: false,
    approvals: true,
  };

  const me = (): MeResponse => ({
    ...(structuredClone(meFx) as MeResponse),
    modules: { ...meFx.modules, approvals: state.approvals },
    server_time: new Date(now()).toISOString(),
  });

  function issue(userId: number, family = tokenId('fam-')): TokenResponse {
    const access = tokenId('mock-at-');
    state.access.set(access, { userId, exp: now() + ttl * 1000 });
    return {
      access_token: access,
      token_type: 'Bearer',
      expires_in: ttl,
      refresh_token: `mock-rt-${family}.${tokenId('')}`,
      refresh_expires_in: 2592000,
      user: me(),
    };
  }

  function auth(headers: Headers): { userId: number } | Response {
    const h = headers.get('Authorization') ?? '';
    const m = /^Bearer (.+)$/.exec(h);
    if (!m) return err(401, 'invalid_token', 'Anmeldung erforderlich.');
    const t = state.access.get(m[1]!);
    if (!t) return err(401, 'invalid_token', 'Token ungültig.');
    if (now() >= t.exp) return err(401, 'token_expired', 'Token abgelaufen.');
    return { userId: t.userId };
  }

  function planMy(from: string, to: string): MyPlanResponse {
    const days: PlanDay[] = eachDay(from, to).map((date) => ({
      date,
      weekday: isoWeekday(date),
      holiday: HOLIDAYS.get(date) ?? null,
      entry: entryFor(12, date),
    }));
    return { from, to, published_at: new Date(now() - 3_600_000).toISOString(), days };
  }

  function team(year: number, month: number): TeamPlanResponse {
    const { from, to } = monthRange({ year, month });
    const days = eachDay(from, to);
    const holidays: Record<string, Holiday> = {};
    const cells: Record<string, TeamCell> = {};
    for (const d of days) {
      const hol = HOLIDAYS.get(d);
      if (hol) holidays[d] = hol;
      for (const u of teamFx) {
        let e = entryFor(u.id, d);
        if (!e) continue;
        const confidential = !u.is_me && e.kind === 'absence' && (e.type === 'krank');
        if (confidential) e = absenceEntry('vertraulich');
        cells[`${u.id}_${d}`] = { kind: e.kind, code: e.code, color: e.color, fg: e.fg, ...(confidential ? { confidential: true } : {}) };
      }
    }
    return { year, month, days, holidays, users: structuredClone(teamFx), cells };
  }

  function details(date: string, userId: number): AssignmentDetailsResponse | null {
    const entry = entryFor(userId, date);
    if (!entry) return { date, user_id: userId, entry: null, area: null, customer: null, location: null, colleagues: [] };
    if (entry.kind === 'absence') {
      return { date, user_id: userId, entry: userId === 12 ? entry : absenceEntry('vertraulich'), area: null, customer: null, location: null, colleagues: [] };
    }
    const area = AREAS.find((a) => a.id === entry.area_id)!;
    const colleagues = teamFx
      .filter((u) => u.id !== userId)
      .filter((u) => {
        const e = entryFor(u.id, date);
        return e?.kind === 'assignment' && e.area_id === area.id;
      })
      .map((u) => ({ id: u.id, name: u.name }));
    return {
      date,
      user_id: userId,
      entry,
      area: { id: area.id, code: area.code, name: area.name, type: area.type },
      customer: area.customer ? structuredClone(area.customer) : null,
      location: area.location ? structuredClone(area.location) : null,
      colleagues,
    };
  }

  function absences(year: number): MyAbsencesResponse {
    const items: MyAbsencesResponse['items'] = [];
    let cur: { start: string; end: string; days: number; type: string } | null = null;
    for (const d of eachDay(`${year}-01-01`, `${year}-12-31`)) {
      const e = entryFor(12, d);
      const t = e?.kind === 'absence' ? e.type : null;
      if (t && cur && cur.type === t && addDays(cur.end, 1) === d) {
        cur.end = d;
        cur.days += 1;
        continue;
      }
      // Wochenenden überbrücken Urlaubsblöcke
      if (!t && cur && isoWeekday(d) >= 6) continue;
      if (cur) items.push({ ...cur, label: ABS_TYPES[cur.type]?.label ?? cur.type, published: true });
      cur = t ? { start: d, end: d, days: 1, type: t } : null;
    }
    if (cur) items.push({ ...cur, label: ABS_TYPES[cur.type]?.label ?? cur.type, published: true });
    const sick = items.filter((i) => i.type === 'krank').reduce((s, i) => s + i.days, 0);
    return {
      year,
      vacation: structuredClone(absencesFx.vacation),
      sick_days: sick,
      items,
      requests: state.requests.filter((r) => r.date.startsWith(String(year))),
    };
  }

  async function handle(method: string, path: string, url: URL, headers: Headers, body: unknown): Promise<Response> {
    if (opts.requireTenant && headers.get('X-PQ-Tenant') !== opts.requireTenant) {
      return err(404, 'not_found', 'Mandant nicht gefunden.');
    }
    const route = `${method} ${path}`;
    // ---- öffentlich
    if (route === 'GET config') {
      const c = structuredClone(configFx) as ConfigResponse;
      c.modules.approvals = state.approvals;
      if (state.oidcEnabled) c.auth.oidc = { enabled: true, label: 'Mit Firmenkonto anmelden', authorize_url: 'https://login.demo.invalid/authorize?client_id=planquadro-app' };
      return json(200, c);
    }
    if (route === 'GET health') return json(200, { status: 'ok' });
    if (route === 'POST auth/login') {
      const b = (body ?? {}) as { username?: string; password?: string };
      if (!b.username || !b.password) return err(400, 'invalid_request', 'Benutzername und Passwort angeben.');
      const u = b.username.trim().toLowerCase();
      if (u === 'wartung') return err(503, 'maintenance', 'Wartungsarbeiten bis 20:00 Uhr.');
      if (b.password !== 'demo') return err(401, 'invalid_credentials', 'Benutzername oder Passwort ist falsch.');
      if (u === 'gesperrt') return err(403, 'account_locked', 'Konto gesperrt.');
      if (u === 'neu') return err(403, 'password_change_required', 'Passwort muss geändert werden.');
      return json(200, issue(12));
    }
    if (route === 'POST auth/refresh') {
      const rt = (body as { refresh_token?: string } | undefined)?.refresh_token ?? '';
      const fam = /^mock-rt-(fam-[0-9a-f]+)\./.exec(rt)?.[1];
      if (!fam || state.revokedFamilies.has(fam)) return err(401, 'invalid_token', 'Refresh-Token ungültig.');
      if (state.refreshUsed.has(rt)) {
        state.revokedFamilies.add(fam); // Wiederverwendung → ganze Familie sperren
        return err(401, 'invalid_token', 'Refresh-Token wurde bereits verwendet.');
      }
      state.refreshUsed.add(rt);
      return json(200, issue(12, fam));
    }
    if (route === 'POST auth/oidc/token') {
      if (!state.oidcEnabled) return err(403, 'module_disabled', 'Firmenanmeldung ist nicht aktiv.');
      const b = (body ?? {}) as { code?: string; code_verifier?: string };
      if (!b.code || !b.code_verifier) return err(400, 'invalid_request', 'code und code_verifier erforderlich.');
      return json(200, issue(12));
    }

    // ---- geschützt
    const a = auth(headers);
    if (a instanceof Response) return a;

    if (route === 'POST auth/logout') {
      const rt = (body as { refresh_token?: string } | undefined)?.refresh_token ?? '';
      const fam = /^mock-rt-(fam-[0-9a-f]+)\./.exec(rt)?.[1];
      if (fam) state.revokedFamilies.add(fam);
      const tok = headers.get('Authorization')!.slice(7);
      state.access.delete(tok);
      return json(204, null);
    }
    if (route === 'GET me') return json(200, me());
    if (route === 'GET plan/my') {
      const from = url.searchParams.get('from') ?? '';
      const to = url.searchParams.get('to') ?? '';
      if (!isIsoDate(from) || !isIsoDate(to) || to < from) return err(400, 'invalid_request', 'from/to ungültig.');
      if ((parseIsoDate(to).getTime() - parseIsoDate(from).getTime()) / 86_400_000 > 92) return err(400, 'invalid_request', 'Maximal 93 Tage je Abfrage.');
      return json(200, planMy(from, to));
    }
    if (route === 'GET plan/team') {
      const y = Number(url.searchParams.get('year'));
      const m = Number(url.searchParams.get('month'));
      if (!Number.isInteger(y) || !Number.isInteger(m) || m < 1 || m > 12 || daysInMonth(y, m) < 28) return err(400, 'invalid_request', 'year/month ungültig.');
      return json(200, team(y, m));
    }
    const det = /^assignments\/(\d{4}-\d{2}-\d{2})$/.exec(path);
    if (method === 'GET' && det) {
      const userId = Number(url.searchParams.get('user_id') ?? a.userId);
      if (!teamFx.some((u) => u.id === userId)) return err(404, 'not_found', 'Mitarbeiter nicht gefunden.');
      const d = details(det[1]!, userId);
      return d ? json(200, d) : err(404, 'not_found', 'Kein Eintrag.');
    }
    if (route === 'GET absences/my') {
      const y = Number(url.searchParams.get('year') ?? new Date(now()).getFullYear());
      return json(200, absences(y));
    }
    if (route === 'POST absences/requests') {
      if (!state.approvals) return err(403, 'module_disabled', 'Anträge sind nicht aktiviert.');
      const b = (body ?? {}) as { from?: string; to?: string; type?: string };
      if (!b.from || !b.to || !isIsoDate(b.from) || !isIsoDate(b.to) || b.to < b.from) return err(400, 'invalid_request', 'Zeitraum ungültig.');
      if (!['urlaub_ganz', 'urlaub_halb', 'gleitzeit'].includes(b.type ?? '')) return err(400, 'invalid_request', 'Art ungültig.');
      let created = 0;
      let skipped = 0;
      for (const d of eachDay(b.from, b.to)) {
        if (isoWeekday(d) >= 6 || HOLIDAYS.get(d)?.type === 'feiertag') continue;
        if (state.requests.some((r) => r.date === d && r.status !== 'rejected')) {
          skipped++;
          continue;
        }
        state.requests.unshift({ id: ++state.seq, date: d, type: b.type!, status: 'pending', created_at: new Date(now()).toISOString() });
        created++;
      }
      return json(201, { created, skipped });
    }
    if (route === 'GET holidays') {
      const y = url.searchParams.get('year') ?? '';
      return json(200, { items: [...HOLIDAYS.entries()].filter(([d]) => d.startsWith(y)).map(([date, h]) => ({ date, ...h })) });
    }
    if (route === 'GET notifications') {
      const limit = Number(url.searchParams.get('limit') ?? 30);
      return json(200, { unread: state.notifications.filter((n) => !n.is_read).length, items: state.notifications.slice(0, limit) });
    }
    if (route === 'POST notifications/read-all') {
      state.notifications.forEach((n) => (n.is_read = true));
      return json(204, null);
    }
    const nr = /^notifications\/(\d+)\/read$/.exec(path);
    if (method === 'POST' && nr) {
      const n = state.notifications.find((x) => x.id === Number(nr[1]));
      if (!n) return err(404, 'not_found', 'Benachrichtigung nicht gefunden.');
      n.is_read = true;
      return json(204, null);
    }
    if (route === 'GET sync') {
      const since = url.searchParams.get('since');
      if (!since) return json(200, { cursor: String(state.cursor), changed_dates: [], full_resync: false });
      if (Number(since) < state.cursor - 1000) return json(200, { cursor: String(state.cursor), changed_dates: [], full_resync: true });
      const changed = state.pendingChanges.splice(0);
      if (changed.length) state.cursor++;
      return json(200, { cursor: String(state.cursor), changed_dates: changed, full_resync: false });
    }
    if (route === 'POST devices') {
      const id = ++state.seq;
      state.devices.set(id, body);
      return json(201, { id });
    }
    const dv = /^devices\/(\d+)$/.exec(path);
    if (method === 'DELETE' && dv) {
      state.devices.delete(Number(dv[1]));
      return json(204, null);
    }
    return err(404, 'not_found', `Unbekannter Endpunkt ${route}`);
  }

  const fetchImpl = async (input: string, init: RequestInit): Promise<Response> => {
    const url = new URL(input);
    const base = new URL(MOCK_BASE_URL);
    if (url.origin !== base.origin || !url.pathname.startsWith(base.pathname)) throw new TypeError('Failed to fetch');
    const path = decodeURIComponent(url.pathname.slice(base.pathname.length)).replace(/\/+$/, '');
    const headers = new Headers(init.headers);
    const method = (init.method ?? 'GET').toUpperCase();
    state.calls.push({ method, path, headers: Object.fromEntries(headers.entries()) });
    let body: unknown;
    if (typeof init.body === 'string' && init.body) body = JSON.parse(init.body);
    if (latency > 0) await new Promise((r) => setTimeout(r, latency));
    return handle(method, path, url, headers, body);
  };

  return {
    fetch: fetchImpl,
    state,
    /** Simuliert eine veröffentlichte Planänderung (für Sync/Push-Demo). */
    publishChange(dates: string[]) {
      state.pendingChanges.push(...dates);
      state.notifications.unshift({
        id: ++state.seq,
        title: 'Plan geändert',
        message: `Dein Plan wurde geändert (${dates.length} Tag${dates.length === 1 ? '' : 'e'}).`,
        link: 'mein_plan',
        is_read: false,
        created_at: new Date(now()).toISOString(),
      });
    },
  };
}

export type MockServer = ReturnType<typeof createMockServer>;
