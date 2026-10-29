import { ApiError, errorFromResponse, toApiError } from './errors';
import type {
  AbsenceRequestCreate,
  AbsenceRequestCreated,
  AssignmentDetailsResponse,
  ConfigResponse,
  DeviceRegistered,
  DeviceRegistration,
  EventsTicketResponse,
  HealthResponse,
  HolidaysResponse,
  IsoDate,
  LoginRequest,
  MeResponse,
  MyAbsencesResponse,
  MyPlanResponse,
  NotificationsResponse,
  OidcTokenRequest,
  SyncResponse,
  TeamPlanResponse,
  TokenResponse,
} from './types';

export type FetchLike = (input: string, init: RequestInit) => Promise<Response>;

/** Persistenter Speicher für das Refresh-Token (Keychain/Keystore bzw. verschlüsselte IndexedDB). */
export interface RefreshTokenStore {
  get(): Promise<string | null>;
  set(token: string | null): Promise<void>;
}

export interface ApiClientOptions {
  /** API-Basis inkl. `/api/v1/` */
  baseUrl: string;
  /** Mandanten-Slug für SaaS → Header `X-PQ-Tenant` */
  tenant?: string | null;
  fetch?: FetchLike;
  refreshTokenStore: RefreshTokenStore;
  timeoutMs?: number;
  now?: () => number;
  /** Refresh endgültig fehlgeschlagen → Nutzer muss sich neu anmelden. */
  onSessionExpired?: () => void;
  /** Nach jedem Login/Refresh (z. B. um `me` zu aktualisieren). */
  onTokens?: (tokens: TokenResponse) => void;
}

interface RequestOptions {
  query?: Record<string, string | number | undefined | null>;
  body?: unknown;
  /** Bearer-Token mitsenden (Standard: true) */
  auth?: boolean;
}

/** Sicherheitsabstand: Access-Token wird kurz vor Ablauf proaktiv erneuert. */
const EXPIRY_SKEW_MS = 30_000;

export class ApiClient {
  readonly baseUrl: string;
  readonly tenant: string | null;
  private readonly fetchFn: FetchLike;
  private readonly store: RefreshTokenStore;
  private readonly timeoutMs: number;
  private readonly now: () => number;
  private readonly onSessionExpired: (() => void) | undefined;
  private readonly onTokens: ((t: TokenResponse) => void) | undefined;

  /** Access-Token nur im Arbeitsspeicher. */
  private accessToken: string | null = null;
  private accessExpiresAt = 0;
  /** Single-Flight-Sperre: parallele 401 lösen genau einen Refresh aus. */
  private refreshInFlight: Promise<string> | null = null;

  constructor(opts: ApiClientOptions) {
    if (!/\/api\/v1\/$/.test(opts.baseUrl)) throw new Error('baseUrl muss auf /api/v1/ enden');
    this.baseUrl = opts.baseUrl;
    this.tenant = opts.tenant ?? null;
    this.fetchFn = opts.fetch ?? ((input, init) => globalThis.fetch(input, init));
    this.store = opts.refreshTokenStore;
    this.timeoutMs = opts.timeoutMs ?? 20_000;
    this.now = opts.now ?? (() => Date.now());
    this.onSessionExpired = opts.onSessionExpired;
    this.onTokens = opts.onTokens;
  }

  // ---------------------------------------------------------------- Token-Verwaltung

  get hasAccessToken(): boolean {
    return this.accessToken !== null;
  }

  /** Verwirft das Access-Token im Speicher (z. B. bei App-Sperre). */
  dropAccessToken(): void {
    this.accessToken = null;
    this.accessExpiresAt = 0;
  }

  private async acceptTokens(t: TokenResponse): Promise<void> {
    // Rotierendes Refresh-Token: zuerst persistieren, dann verwenden.
    await this.store.set(t.refresh_token);
    this.accessToken = t.access_token;
    this.accessExpiresAt = this.now() + Math.max(0, t.expires_in) * 1000;
    this.onTokens?.(t);
  }

  /**
   * Erneuert das Token-Paar. Gleichzeitige Aufrufe teilen sich denselben Request
   * (ein bereits verwendetes Refresh-Token würde sonst die Token-Familie sperren).
   */
  refresh(): Promise<string> {
    if (!this.refreshInFlight) {
      this.refreshInFlight = this.doRefresh().finally(() => {
        this.refreshInFlight = null;
      });
    }
    return this.refreshInFlight;
  }

  private async doRefresh(): Promise<string> {
    const refreshToken = await this.store.get();
    if (!refreshToken) {
      this.dropAccessToken();
      throw new ApiError(401, 'session_expired');
    }
    let tokens: TokenResponse;
    try {
      tokens = await this.send<TokenResponse>('POST', 'auth/refresh', { body: { refresh_token: refreshToken }, auth: false });
    } catch (e) {
      const err = toApiError(e);
      if (err.status === 401 || err.code === 'account_locked' || err.code === 'password_change_required') {
        // Refresh-Token ungültig/gesperrt → lokal verwerfen, neu anmelden
        await this.store.set(null);
        this.dropAccessToken();
        this.onSessionExpired?.();
        throw new ApiError(401, err.code === 'account_locked' ? 'account_locked' : 'session_expired', null);
      }
      throw err; // z. B. offline: Refresh-Token behalten
    }
    await this.acceptTokens(tokens);
    return tokens.access_token;
  }

  private async validAccessToken(): Promise<string> {
    if (this.accessToken && this.now() < this.accessExpiresAt - EXPIRY_SKEW_MS) return this.accessToken;
    return this.refresh();
  }

  // ---------------------------------------------------------------- HTTP-Kern

  private buildUrl(path: string, query?: RequestOptions['query']): string {
    const url = new URL(path.replace(/^\/+/, ''), this.baseUrl);
    if (query) {
      for (const [k, v] of Object.entries(query)) {
        if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
      }
    }
    return url.toString();
  }

  /** Einzelner HTTP-Aufruf ohne Refresh-Logik. */
  private async send<T>(method: string, path: string, opts: RequestOptions & { token?: string } = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (this.tenant) headers['X-PQ-Tenant'] = this.tenant;
    if (opts.token) headers['Authorization'] = `Bearer ${opts.token}`;
    let body: string | undefined;
    if (opts.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.body);
    }
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), this.timeoutMs) : null;
    let res: Response;
    try {
      const init: RequestInit = { method, headers, cache: 'no-store', credentials: 'omit' };
      if (body !== undefined) init.body = body;
      if (controller) init.signal = controller.signal;
      res = await this.fetchFn(this.buildUrl(path, opts.query), init);
    } catch (e) {
      throw toApiError(e);
    } finally {
      if (timer) clearTimeout(timer);
    }
    if (!res.ok) throw await errorFromResponse(res);
    if (res.status === 204) return undefined as T;
    const text = await res.text();
    if (!text) return undefined as T;
    try {
      return JSON.parse(text) as T;
    } catch {
      throw new ApiError(res.status, 'server_error', 'Ungültige Antwort vom Server.');
    }
  }

  /** Geschützter Aufruf: Bearer-Token, bei `401 token_expired` genau ein Refresh + Wiederholung. */
  async request<T>(method: string, path: string, opts: RequestOptions = {}): Promise<T> {
    if (opts.auth === false) return this.send<T>(method, path, opts);
    const token = await this.validAccessToken();
    try {
      return await this.send<T>(method, path, { ...opts, token });
    } catch (e) {
      if (e instanceof ApiError && e.status === 401 && e.code === 'token_expired') {
        // Falls ein paralleler Request bereits erneuert hat, dessen Token nutzen.
        const fresh = this.accessToken && this.accessToken !== token ? this.accessToken : await this.refresh();
        return this.send<T>(method, path, { ...opts, token: fresh });
      }
      if (e instanceof ApiError && e.status === 401 && e.code === 'invalid_token') {
        await this.store.set(null);
        this.dropAccessToken();
        this.onSessionExpired?.();
      }
      throw e;
    }
  }

  // ---------------------------------------------------------------- Öffentliche Endpunkte

  getConfig(): Promise<ConfigResponse> {
    return this.send('GET', 'config');
  }

  getHealth(): Promise<HealthResponse> {
    return this.send('GET', 'health');
  }

  async login(req: LoginRequest): Promise<TokenResponse> {
    const t = await this.send<TokenResponse>('POST', 'auth/login', { body: req });
    await this.acceptTokens(t);
    return t;
  }

  async oidcToken(req: OidcTokenRequest): Promise<TokenResponse> {
    const t = await this.send<TokenResponse>('POST', 'auth/oidc/token', { body: req });
    await this.acceptTokens(t);
    return t;
  }

  // ---------------------------------------------------------------- Geschützte Endpunkte

  /** Meldet ab (sperrt die Token-Familie) und verwirft lokale Tokens – auch bei Fehlern. */
  async logout(): Promise<void> {
    const refreshToken = await this.store.get().catch(() => null);
    try {
      if (this.accessToken) {
        await this.send('POST', 'auth/logout', { token: this.accessToken, body: refreshToken ? { refresh_token: refreshToken } : {} });
      } else if (refreshToken) {
        await this.request('POST', 'auth/logout', { body: { refresh_token: refreshToken } });
      }
    } catch {
      /* Abmeldung lokal trotzdem durchführen */
    } finally {
      this.dropAccessToken();
      await this.store.set(null);
    }
  }

  getMe(): Promise<MeResponse> {
    return this.request('GET', 'me');
  }

  getMyPlan(from: IsoDate, to: IsoDate): Promise<MyPlanResponse> {
    return this.request('GET', 'plan/my', { query: { from, to } });
  }

  getTeamPlan(year: number, month: number): Promise<TeamPlanResponse> {
    return this.request('GET', 'plan/team', { query: { year, month } });
  }

  getAssignment(date: IsoDate, userId?: number): Promise<AssignmentDetailsResponse> {
    return this.request('GET', `assignments/${encodeURIComponent(date)}`, { query: { user_id: userId } });
  }

  getMyAbsences(year: number): Promise<MyAbsencesResponse> {
    return this.request('GET', 'absences/my', { query: { year } });
  }

  createAbsenceRequest(req: AbsenceRequestCreate): Promise<AbsenceRequestCreated> {
    return this.request('POST', 'absences/requests', { body: req });
  }

  getHolidays(year: number): Promise<HolidaysResponse> {
    return this.request('GET', 'holidays', { query: { year } });
  }

  getNotifications(limit = 30): Promise<NotificationsResponse> {
    return this.request('GET', 'notifications', { query: { limit } });
  }

  markNotificationRead(id: number): Promise<void> {
    return this.request('POST', `notifications/${encodeURIComponent(String(id))}/read`);
  }

  markAllNotificationsRead(): Promise<void> {
    return this.request('POST', 'notifications/read-all');
  }

  getSync(since?: string | null): Promise<SyncResponse> {
    return this.request('GET', 'sync', { query: { since: since ?? undefined } });
  }

  getEventsTicket(): Promise<EventsTicketResponse> {
    return this.request('POST', 'events/ticket');
  }

  registerDevice(reg: DeviceRegistration): Promise<DeviceRegistered> {
    return this.request('POST', 'devices', { body: reg });
  }

  unregisterDevice(id: number): Promise<void> {
    return this.request('DELETE', `devices/${encodeURIComponent(String(id))}`);
  }
}
