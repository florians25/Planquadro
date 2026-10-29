import { ApiError, toApiError } from '../api/errors';
import type { ScopedCache } from './cache';

export interface Loaded<T> {
  data: T;
  fromCache: boolean;
  /** ms seit Epoch: Zeitpunkt, zu dem die Daten zuletzt vom Server kamen */
  savedAt: number;
  /** Grund, warum Cache-Daten angezeigt werden */
  offlineReason?: string;
}

/**
 * Lesen mit Offline-Fallback:
 * - online: vom Server laden und verschlüsselt cachen; bei Verbindungsfehler/Wartung Cache zeigen
 * - offline: direkt Cache zeigen; ohne Cache Fehler `network_error`
 */
export async function loadCached<T>(
  cache: ScopedCache | null,
  key: string,
  fetcher: () => Promise<T>,
  opts: { online: boolean; force?: boolean; now?: () => number } = { online: true },
): Promise<Loaded<T>> {
  const now = opts.now ?? Date.now;
  const fromCache = async (reason: string, original: ApiError): Promise<Loaded<T>> => {
    const c = cache ? await cache.get<T>(key) : null;
    if (!c) throw original;
    return { data: c.data, fromCache: true, savedAt: c.savedAt, offlineReason: reason };
  };
  if (!opts.online) return fromCache('Offline', new ApiError(0, 'network_error'));
  try {
    const data = await fetcher();
    if (cache) await cache.put(key, data).catch(() => undefined);
    return { data, fromCache: false, savedAt: now() };
  } catch (e) {
    const err = toApiError(e);
    if (err.isConnectivity) return fromCache('Offline', err);
    if (err.code === 'maintenance') return fromCache('Wartungsmodus', err);
    throw err;
  }
}

/** Schreibzugriffe nur online – Offline wird nichts in eine Warteschlange gestellt. */
export function requireOnline(online: boolean): void {
  if (!online) throw new ApiError(0, 'offline_write');
}
