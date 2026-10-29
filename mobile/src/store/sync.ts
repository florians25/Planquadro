import type { ApiClient } from '../api/client';
import type { SyncResponse } from '../api/types';
import { monthRange, monthsOfDates, parseMonthKey } from '../lib/dates';
import { CacheKeys, type ScopedCache } from './cache';

export interface SyncPlan {
  fullResync: boolean;
  /** Monate (YYYY-MM) des eigenen Plans, die neu geladen werden */
  planMonths: string[];
  /** Monate der Teamübersicht, die neu geladen werden */
  teamMonths: string[];
  /** Jahre der Abwesenheitsübersicht, die neu geladen werden */
  absenceYears: number[];
  /** Detail-Einträge (Cache-Schlüssel), die verworfen werden */
  dropKeys: string[];
}

/**
 * Reine Funktion: leitet aus der Sync-Antwort und den aktuell gecachten Schlüsseln ab,
 * was neu geladen bzw. verworfen werden muss. Nur bereits gecachte Monate werden neu
 * geladen – nicht gecachte Monate holt die App beim nächsten Öffnen ohnehin frisch.
 */
export function planSync(res: SyncResponse, cachedKeys: string[]): SyncPlan {
  if (res.full_resync) return { fullResync: true, planMonths: [], teamMonths: [], absenceYears: [], dropKeys: [] };
  const months = new Set(monthsOfDates(res.changed_dates));
  const dates = new Set(res.changed_dates);
  const planMonths: string[] = [];
  const teamMonths: string[] = [];
  const absenceYears: number[] = [];
  const dropKeys: string[] = [];
  const years = new Set([...months].map((m) => Number(m.slice(0, 4))));
  for (const key of cachedKeys) {
    const [kind, a, b] = key.split(':');
    if (kind === 'plan' && a && months.has(a)) planMonths.push(a);
    else if (kind === 'team' && a && months.has(a)) teamMonths.push(a);
    else if (kind === 'abs' && a && years.has(Number(a))) absenceYears.push(Number(a));
    else if (kind === 'det' && a && b !== undefined && dates.has(a)) dropKeys.push(key);
  }
  return {
    fullResync: false,
    planMonths: planMonths.sort(),
    teamMonths: teamMonths.sort(),
    absenceYears: absenceYears.sort(),
    dropKeys,
  };
}

export interface SyncResult {
  /** Erster Sync: nur Cursor gemerkt */
  initial: boolean;
  fullResync: boolean;
  changedDates: string[];
  refreshed: string[];
}

type SyncApi = Pick<ApiClient, 'getSync' | 'getMyPlan' | 'getTeamPlan' | 'getMyAbsences'>;

let running: Promise<SyncResult> | null = null;

/**
 * Führt einen Sync aus (Single-Flight). Der neue Cursor wird erst gespeichert, wenn
 * alle betroffenen Monate erfolgreich neu geladen wurden – bei Fehlern wird beim
 * nächsten Mal mit dem alten Cursor erneut synchronisiert.
 */
export function runSync(api: SyncApi, cache: ScopedCache): Promise<SyncResult> {
  if (!running) {
    running = doSync(api, cache).finally(() => {
      running = null;
    });
  }
  return running;
}

async function doSync(api: SyncApi, cache: ScopedCache): Promise<SyncResult> {
  const cursor = (await cache.get<string>(CacheKeys.cursor))?.data ?? null;
  const res = await api.getSync(cursor);
  if (!cursor) {
    await cache.put(CacheKeys.cursor, res.cursor);
    return { initial: true, fullResync: false, changedDates: [], refreshed: [] };
  }
  const plan = planSync(res, await cache.keys());
  if (plan.fullResync) {
    await cache.clear();
    await cache.put(CacheKeys.cursor, res.cursor);
    return { initial: false, fullResync: true, changedDates: [], refreshed: [] };
  }
  const refreshed: string[] = [];
  for (const m of plan.planMonths) {
    const ym = parseMonthKey(m);
    if (!ym) continue;
    const { from, to } = monthRange(ym);
    await cache.put(CacheKeys.plan(m), await api.getMyPlan(from, to));
    refreshed.push(CacheKeys.plan(m));
  }
  for (const m of plan.teamMonths) {
    const ym = parseMonthKey(m);
    if (!ym) continue;
    await cache.put(CacheKeys.team(m), await api.getTeamPlan(ym.year, ym.month));
    refreshed.push(CacheKeys.team(m));
  }
  for (const y of plan.absenceYears) {
    await cache.put(CacheKeys.absences(y), await api.getMyAbsences(y));
    refreshed.push(CacheKeys.absences(y));
  }
  for (const k of plan.dropKeys) await cache.delete(k);
  await cache.put(CacheKeys.cursor, res.cursor);
  return { initial: false, fullResync: false, changedDates: res.changed_dates, refreshed };
}
