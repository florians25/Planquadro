import { describe, expect, it, vi } from 'vitest';
import type { MyAbsencesResponse, MyPlanResponse, SyncResponse, TeamPlanResponse } from '../src/api/types';
import { CacheKeys, OfflineCache } from '../src/store/cache';
import { generateNonExtractableKey } from '../src/store/crypto';
import { loadCached } from '../src/store/data';
import { ApiError } from '../src/api/errors';
import { MemoryKv } from '../src/store/kv';
import { planSync, runSync } from '../src/store/sync';

const res = (p: Partial<SyncResponse>): SyncResponse => ({ cursor: '2', changed_dates: [], full_resync: false, ...p });

describe('planSync (rein)', () => {
  const cached = ['plan:2026-11', 'plan:2026-12', 'team:2026-11', 'abs:2026', 'det:2026-11-02:12', 'det:2026-11-09:12', 'me', 'meta:cursor'];

  it('lädt nur gecachte, betroffene Monate neu und verwirft betroffene Details', () => {
    const p = planSync(res({ changed_dates: ['2026-11-02', '2026-11-03', '2027-01-05'] }), cached);
    expect(p).toEqual({
      fullResync: false,
      planMonths: ['2026-11'],
      teamMonths: ['2026-11'],
      absenceYears: [2026],
      dropKeys: ['det:2026-11-02:12'],
    });
  });

  it('ohne Änderungen nichts tun', () => {
    expect(planSync(res({}), cached)).toMatchObject({ planMonths: [], teamMonths: [], absenceYears: [], dropKeys: [] });
  });

  it('full_resync hat Vorrang', () => {
    expect(planSync(res({ full_resync: true, changed_dates: ['2026-11-02'] }), cached).fullResync).toBe(true);
  });
});

function fakeApi(responses: SyncResponse[]) {
  const plan = (from: string, to: string): MyPlanResponse => ({ from, to, published_at: null, days: [] });
  return {
    getSync: vi.fn(async () => responses.shift()!),
    getMyPlan: vi.fn(async (from: string, to: string) => plan(from, to)),
    getTeamPlan: vi.fn(async (year: number, month: number) => ({ year, month, days: [], holidays: {}, users: [], cells: {} }) as TeamPlanResponse),
    getMyAbsences: vi.fn(async (year: number) => ({ year }) as MyAbsencesResponse),
  };
}

async function scoped() {
  const key = await generateNonExtractableKey();
  return new OfflineCache(new MemoryKv(), async () => key).forScope('u12');
}

describe('runSync', () => {
  it('erster Lauf merkt sich nur den Cursor', async () => {
    const cache = await scoped();
    const api = fakeApi([res({ cursor: '1842' })]);
    const r = await runSync(api, cache);
    expect(r.initial).toBe(true);
    expect(api.getSync).toHaveBeenCalledWith(null);
    expect((await cache.get(CacheKeys.cursor))!.data).toBe('1842');
  });

  it('lädt geänderte Monate neu und speichert danach den neuen Cursor', async () => {
    const cache = await scoped();
    await cache.put(CacheKeys.cursor, '1842');
    await cache.put(CacheKeys.plan('2026-11'), { stale: true });
    await cache.put(CacheKeys.plan('2026-10'), { untouched: true });
    await cache.put(CacheKeys.details('2026-11-02', 12), { stale: true });
    const api = fakeApi([res({ cursor: '1850', changed_dates: ['2026-11-02', '2026-11-03'] })]);
    const r = await runSync(api, cache);
    expect(api.getSync).toHaveBeenCalledWith('1842');
    expect(api.getMyPlan).toHaveBeenCalledTimes(1);
    expect(api.getMyPlan).toHaveBeenCalledWith('2026-11-01', '2026-11-30');
    expect(r.refreshed).toEqual(['plan:2026-11']);
    expect((await cache.get<MyPlanResponse>(CacheKeys.plan('2026-11')))!.data.from).toBe('2026-11-01');
    expect((await cache.get(CacheKeys.plan('2026-10')))!.data).toEqual({ untouched: true });
    expect(await cache.get(CacheKeys.details('2026-11-02', 12))).toBeNull();
    expect((await cache.get(CacheKeys.cursor))!.data).toBe('1850');
  });

  it('full_resync leert den Cache des Benutzers', async () => {
    const cache = await scoped();
    await cache.put(CacheKeys.cursor, '1');
    await cache.put(CacheKeys.plan('2026-11'), {});
    await cache.put(CacheKeys.team('2026-11'), {});
    const r = await runSync(fakeApi([res({ cursor: '9000', full_resync: true })]), cache);
    expect(r.fullResync).toBe(true);
    expect(await cache.keys()).toEqual([CacheKeys.cursor]);
    expect((await cache.get(CacheKeys.cursor))!.data).toBe('9000');
  });

  it('bei Fehler bleibt der alte Cursor erhalten', async () => {
    const cache = await scoped();
    await cache.put(CacheKeys.cursor, '5');
    await cache.put(CacheKeys.plan('2026-11'), {});
    const api = fakeApi([res({ cursor: '6', changed_dates: ['2026-11-02'] })]);
    api.getMyPlan.mockRejectedValueOnce(new ApiError(0, 'network_error'));
    await expect(runSync(api, cache)).rejects.toMatchObject({ code: 'network_error' });
    expect((await cache.get(CacheKeys.cursor))!.data).toBe('5');
  });

  it('parallele Aufrufe teilen sich einen Lauf', async () => {
    const cache = await scoped();
    const api = fakeApi([res({ cursor: '1' })]);
    const [a, b] = await Promise.all([runSync(api, cache), runSync(api, cache)]);
    expect(a).toBe(b);
    expect(api.getSync).toHaveBeenCalledTimes(1);
  });
});

describe('loadCached (Offline-Lesemodus)', () => {
  it('online: lädt und cacht', async () => {
    const cache = await scoped();
    const r = await loadCached(cache, 'plan:2026-11', async () => ({ v: 1 }), { online: true, now: () => 5 });
    expect(r).toEqual({ data: { v: 1 }, fromCache: false, savedAt: 5 });
    expect((await cache.get('plan:2026-11'))!.data).toEqual({ v: 1 });
  });

  it('Verbindungsfehler → Cache mit Stand', async () => {
    const cache = await scoped();
    await cache.put('plan:2026-11', { v: 'alt' });
    const r = await loadCached(cache, 'plan:2026-11', async () => {
      throw new TypeError('Failed to fetch');
    }, { online: true });
    expect(r.fromCache).toBe(true);
    expect(r.data).toEqual({ v: 'alt' });
    expect(r.offlineReason).toBe('Offline');
  });

  it('offline ohne Cache → network_error, Fetcher wird nicht aufgerufen', async () => {
    const cache = await scoped();
    const fetcher = vi.fn(async () => 1);
    await expect(loadCached(cache, 'x', fetcher, { online: false })).rejects.toMatchObject({ code: 'network_error' });
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('fachliche Fehler werden nicht durch Cache verdeckt', async () => {
    const cache = await scoped();
    await cache.put('x', 1);
    await expect(loadCached(cache, 'x', async () => {
      throw new ApiError(403, 'forbidden');
    }, { online: true })).rejects.toMatchObject({ code: 'forbidden' });
  });
});
