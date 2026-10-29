import { describe, expect, it, vi } from 'vitest';
import type { LiveEventData, MyAbsencesResponse, TeamPlanResponse } from '../src/api/types';
import { OfflineCache } from '../src/store/cache';
import { generateNonExtractableKey } from '../src/store/crypto';
import { MemoryKv } from '../src/store/kv';
import { applyLivePlan, LiveConnection, planLive, type EventSourceLike } from '../src/store/live';

const ev = (p: Partial<LiveEventData>): LiveEventData => ({
  type: 'published', published: true, months: ['2026-11'], cells: [[12, '2026-11-02']], self: true, own: false, ...p,
});
const cached = ['plan:2026-11', 'team:2026-11', 'team:2026-12', 'abs:2026', 'meta:cursor'];

describe('planLive (rein)', () => {
  it('eigene veröffentlichte Änderung → Sync, Team-Monat und Abwesenheiten', () => {
    expect(planLive(ev({}), 12, cached)).toEqual({ sync: true, teamMonths: ['2026-11'], absenceYears: [2026] });
  });

  it('Änderung eines Kollegen → nur gecachte Team-Monate, kein Sync', () => {
    const p = planLive(ev({ cells: [[14, '2026-12-01'], [14, '2027-01-04']], months: ['2026-12', '2027-01'], self: false }), 12, cached);
    expect(p).toEqual({ sync: false, teamMonths: ['2026-12'], absenceYears: [] });
  });

  it('eigener Antrag (unveröffentlicht) → nur Abwesenheiten neu laden', () => {
    expect(planLive(ev({ type: 'request_changed', published: false }), 12, cached)).toEqual({ sync: false, teamMonths: [], absenceYears: [2026] });
  });

  it('ganzer Monat ohne Zellen → Sync und Team-Monat', () => {
    expect(planLive(ev({ cells: null, self: null }), 12, cached)).toMatchObject({ sync: true, teamMonths: ['2026-11'] });
  });
});

describe('applyLivePlan', () => {
  it('lädt Team und Abwesenheiten in den Cache', async () => {
    const key = await generateNonExtractableKey();
    const cache = new OfflineCache(new MemoryKv(), async () => key).forScope('u12');
    const api = {
      getTeamPlan: vi.fn(async (year: number, month: number) => ({ year, month, days: [], holidays: {}, users: [], cells: {} }) as TeamPlanResponse),
      getMyAbsences: vi.fn(async (year: number) => ({ year }) as MyAbsencesResponse),
    };
    const r = await applyLivePlan(api, cache, { sync: false, teamMonths: ['2026-11'], absenceYears: [2026] });
    expect(r).toEqual(['team:2026-11', 'abs:2026']);
    expect(api.getTeamPlan).toHaveBeenCalledWith(2026, 11);
    expect((await cache.get<TeamPlanResponse>('team:2026-11'))!.data.month).toBe(11);
  });
});

class FakeES implements EventSourceLike {
  static all: FakeES[] = [];
  readyState = 0;
  onerror: ((ev: Event) => unknown) | null = null;
  closed = false;
  private l = new Map<string, Array<(e: MessageEvent) => void>>();
  constructor(readonly url: string) {
    FakeES.all.push(this);
  }
  addEventListener(type: string, fn: (e: MessageEvent) => void): void {
    this.l.set(type, [...(this.l.get(type) ?? []), fn]);
  }
  close(): void {
    this.closed = true;
    this.readyState = 2;
  }
  emit(type: string, data: unknown, id = ''): void {
    for (const fn of this.l.get(type) ?? []) fn({ data: JSON.stringify(data), lastEventId: id } as MessageEvent);
  }
  fail(): void {
    this.onerror?.(new Event('error'));
  }
}

const flush = () => new Promise((r) => setTimeout(r, 0));

function setup(ticketImpl?: () => Promise<{ ticket: string; expires_in: number; url: string }>) {
  FakeES.all = [];
  let n = 0;
  const timers: Array<() => void> = [];
  const ticket = vi.fn(ticketImpl ?? (async () => ({ ticket: `t${++n}`, expires_in: 30, url: `events?ticket=t${n}` })));
  const onEvent = vi.fn();
  const onReset = vi.fn();
  const live = new LiveConnection({
    baseUrl: 'https://plan.example/api/v1/',
    ticket,
    onEvent,
    onReset,
    factory: (url) => new FakeES(url),
    backoff: [10, 20],
    setTimer: (fn) => timers.push(fn),
    clearTimer: () => undefined,
  });
  const runTimers = async () => {
    while (timers.length) timers.shift()!();
    await flush();
  };
  return { live, ticket, onEvent, onReset, runTimers };
}

describe('LiveConnection', () => {
  it('ohne EventSource → Fallback (start liefert false)', () => {
    const live = new LiveConnection({ baseUrl: 'https://x/api/v1/', ticket: vi.fn(), onEvent: vi.fn(), onReset: vi.fn(), factory: null });
    expect(live.start()).toBe(false);
  });

  it('verbindet mit Einmal-Ticket (nie mit Access-Token) und liefert Ereignisse', async () => {
    const { live, ticket, onEvent } = setup();
    expect(live.start()).toBe(true);
    await flush();
    expect(ticket).toHaveBeenCalledTimes(1);
    const es = FakeES.all[0]!;
    expect(es.url).toBe('https://plan.example/api/v1/events?ticket=t1');
    expect(es.url).not.toMatch(/Bearer|access_token/);
    es.emit('ready', { cursor: '41' }, '41');
    expect(live.connected).toBe(true);
    es.emit('published', ev({}), '42');
    expect(onEvent).toHaveBeenCalledWith(ev({}));
    expect(live.cursor).toBe('42');
  });

  it('reguläres Verbindungsende → neues Ticket und Fortsetzen mit last_event_id', async () => {
    const { live, ticket, runTimers } = setup();
    live.start();
    await flush();
    const first = FakeES.all[0]!;
    first.emit('ready', {}, '41');
    first.emit('bye', {}, '45');
    first.fail();
    expect(first.closed).toBe(true);
    await runTimers();
    expect(ticket).toHaveBeenCalledTimes(2);
    expect(FakeES.all[1]!.url).toBe('https://plan.example/api/v1/events?ticket=t2&last_event_id=45');
  });

  it('reset-Ereignis meldet Neuladen', async () => {
    const { live, onReset } = setup();
    live.start();
    await flush();
    FakeES.all[0]!.emit('reset', { cursor: '9' }, '9');
    expect(onReset).toHaveBeenCalledTimes(1);
  });

  it('dauerhafte Fehler → gibt auf (Fallback bleibt der Sync)', async () => {
    const { live, runTimers } = setup(async () => {
      throw new Error('404');
    });
    live.start();
    await flush();
    await runTimers();
    await runTimers();
    expect(live.gaveUp).toBe(true);
    expect(live.start()).toBe(false);
  });

  it('stop schließt die Verbindung', async () => {
    const { live } = setup();
    live.start();
    await flush();
    live.stop();
    expect(FakeES.all[0]!.closed).toBe(true);
    expect(live.connected).toBe(false);
  });
});
