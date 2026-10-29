import type { ApiClient } from '../api/client';
import type { EventsTicketResponse, LiveEventData } from '../api/types';
import { parseMonthKey } from '../lib/dates';
import { CacheKeys, type ScopedCache } from './cache';

/**
 * Live-Aktualisierung im Vordergrund per Server-Sent Events (`GET events`, docs/API.md).
 *
 * EventSource kann keinen Authorization-Header senden; deshalb holt die App vor jeder
 * Verbindung ein kurzlebiges Einmal-Ticket (`POST events/ticket`) – das Access-Token
 * erscheint nie in einer URL. Der Server beendet jede Verbindung nach kurzer Laufzeit;
 * die App baut dann mit neuem Ticket und `last_event_id` neu auf. Ereignisse nennen nur
 * betroffene Zellen; Inhalte lädt die App über die bestehenden Endpunkte nach
 * (eigener Plan über `sync`, Teamübersicht über `plan/team`). Ohne EventSource oder
 * bei dauerhaften Fehlern bleibt es beim bisherigen Sync (Push, App-Start, Reconnect).
 */

export interface LivePlan {
  /** eigener Plan betroffen → `sync` ausführen */
  sync: boolean;
  /** gecachte Team-Monate, die neu geladen werden */
  teamMonths: string[];
  /** gecachte Abwesenheitsjahre (eigene Anträge), die neu geladen werden */
  absenceYears: number[];
}

/** Reine Funktion: was ein Ereignis für die App bedeutet (nur bereits gecachte Daten). */
export function planLive(ev: LiveEventData, myId: number, cachedKeys: string[]): LivePlan {
  const cached = new Set(cachedKeys);
  const self = ev.self !== false && (ev.cells === null || ev.cells.some(([u]) => u === myId));
  const otherMonths = new Set<string>();
  if (ev.cells === null) ev.months.forEach((m) => otherMonths.add(m));
  else for (const [u, d] of ev.cells) if (u !== myId) otherMonths.add(d.slice(0, 7));
  // Eigene veröffentlichte Änderungen erscheinen auch in der Teamübersicht
  if (self && ev.published) ev.months.forEach((m) => otherMonths.add(m));
  const teamMonths = ev.published ? [...otherMonths].filter((m) => cached.has(CacheKeys.team(m))).sort() : [];
  const years = new Set(ev.months.map((m) => Number(m.slice(0, 4))));
  const absenceYears = self && (ev.type === 'request_changed' || ev.published)
    ? [...years].filter((y) => cached.has(CacheKeys.absences(y))).sort()
    : [];
  return { sync: self && ev.published, teamMonths, absenceYears };
}

type LiveApi = Pick<ApiClient, 'getTeamPlan' | 'getMyAbsences'>;

/** Lädt Team-Monate und Abwesenheitsjahre laut Plan neu; liefert die erneuerten Cache-Schlüssel. */
export async function applyLivePlan(api: LiveApi, cache: ScopedCache, plan: LivePlan): Promise<string[]> {
  const refreshed: string[] = [];
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
  return refreshed;
}

/** Ausschnitt von EventSource, den die Verbindung braucht (in Tests ersetzbar). */
export interface EventSourceLike {
  readonly readyState: number;
  onerror: ((ev: Event) => unknown) | null;
  addEventListener(type: string, listener: (ev: MessageEvent) => void): void;
  close(): void;
}
export type EventSourceFactory = (url: string) => EventSourceLike;

export interface LiveOptions {
  /** API-Basis inkl. `/api/v1/` */
  baseUrl: string;
  ticket: () => Promise<EventsTicketResponse>;
  onEvent: (ev: LiveEventData) => void;
  /** Server kennt den Cursor nicht mehr → alles neu laden */
  onReset: () => void;
  factory?: EventSourceFactory | null;
  /** Wartezeiten nach Fehlern (ms); nach dem letzten Wert gibt die Verbindung auf */
  backoff?: number[];
  setTimer?: (fn: () => void, ms: number) => unknown;
  clearTimer?: (t: unknown) => void;
}

const EVENT_TYPES = ['cells_changed', 'published', 'request_changed'] as const;
const DEFAULT_BACKOFF = [1_000, 2_000, 5_000, 10_000, 30_000, 60_000];

function defaultFactory(): EventSourceFactory | null {
  const ES = (globalThis as { EventSource?: new (url: string) => EventSourceLike }).EventSource;
  return ES ? (url) => new ES(url) : null;
}

export class LiveConnection {
  private es: EventSourceLike | null = null;
  private timer: unknown = null;
  private failures = 0;
  private running = false;
  private lastId: string | null = null;
  /** letzte Verbindung lief regulär (ready empfangen) → sofort neu verbinden */
  private healthy = false;
  connected = false;
  /** endgültig aufgegeben (Fallback: Sync) */
  gaveUp = false;
  private readonly factory: EventSourceFactory | null;
  private readonly backoff: number[];
  private readonly setTimer: (fn: () => void, ms: number) => unknown;
  private readonly clearTimer: (t: unknown) => void;

  constructor(private readonly opts: LiveOptions) {
    this.factory = opts.factory === undefined ? defaultFactory() : opts.factory;
    this.backoff = opts.backoff ?? DEFAULT_BACKOFF;
    this.setTimer = opts.setTimer ?? ((fn, ms) => setTimeout(fn, ms));
    this.clearTimer = opts.clearTimer ?? ((t) => clearTimeout(t as ReturnType<typeof setTimeout>));
  }

  /** Startet die Verbindung; `false`, wenn SSE nicht verfügbar ist. */
  start(): boolean {
    if (!this.factory || this.gaveUp) return false;
    if (this.running) return true;
    this.running = true;
    this.failures = 0;
    void this.connect();
    return true;
  }

  stop(): void {
    this.running = false;
    this.connected = false;
    if (this.timer !== null) this.clearTimer(this.timer);
    this.timer = null;
    this.es?.close();
    this.es = null;
  }

  get cursor(): string | null {
    return this.lastId;
  }

  private async connect(): Promise<void> {
    if (!this.running || !this.factory) return;
    let t: EventsTicketResponse;
    try {
      t = await this.opts.ticket();
    } catch {
      this.retry();
      return;
    }
    if (!this.running) return;
    const url = new URL(t.url, this.opts.baseUrl);
    if (this.lastId) url.searchParams.set('last_event_id', this.lastId);
    const es = this.factory(url.toString());
    this.es = es;
    this.healthy = false;
    const track = (e: MessageEvent) => {
      if (e.lastEventId) this.lastId = e.lastEventId;
    };
    es.addEventListener('ready', (e) => {
      track(e);
      this.connected = true;
      this.healthy = true;
      this.failures = 0;
    });
    es.addEventListener('bye', track);
    es.addEventListener('reset', (e) => {
      track(e);
      this.opts.onReset();
    });
    for (const type of EVENT_TYPES) {
      es.addEventListener(type, (e) => {
        track(e);
        let d: LiveEventData;
        try {
          d = JSON.parse(String(e.data)) as LiveEventData;
        } catch {
          return;
        }
        if (d && Array.isArray(d.months)) this.opts.onEvent(d);
      });
    }
    es.onerror = () => {
      // Das Ticket gilt nur einmal: nie die eingebaute Wiederverbindung nutzen
      es.close();
      if (this.es !== es) return;
      this.es = null;
      this.connected = false;
      if (this.healthy) {
        this.healthy = false;
        this.schedule(250);
      } else {
        this.retry();
      }
    };
  }

  private retry(): void {
    const delay = this.backoff[this.failures];
    this.failures++;
    if (delay === undefined) {
      this.gaveUp = true;
      this.stop();
      return;
    }
    this.schedule(delay);
  }

  private schedule(ms: number): void {
    if (!this.running) return;
    if (this.timer !== null) this.clearTimer(this.timer);
    this.timer = this.setTimer(() => {
      this.timer = null;
      void this.connect();
    }, ms);
  }
}
