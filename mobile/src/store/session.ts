/**
 * Sitzungs- und App-Zustand: Server-Einrichtung, Anmeldung (Passwort/OIDC),
 * Token-Lebenszyklus, biometrische Sperre, Abmelden und Löschen lokaler Daten.
 */
import { ApiClient, type FetchLike, type RefreshTokenStore } from '../api/client';
import { ApiError, toApiError } from '../api/errors';
import { createMockServer, MOCK_BASE_URL, type MockServer } from '../api/mock';
import type { ConfigResponse, MeResponse, TokenResponse } from '../api/types';
import { buildAuthorizeUrl, parseAuthRedirect, type PkceSession } from '../lib/pkce';
import { normalizeServerUrl, normalizeTenantSlug } from '../lib/url';
import {
  authenticateBiometric,
  biometryInfo,
  closeSystemBrowser,
  deviceName,
  initPush,
  isNative,
  isOnline,
  openSystemBrowser,
  unregisterPush,
  appVersion,
} from '../platform/native';
import { CacheKeys, OfflineCache, scopeId, type ScopedCache } from './cache';
import { generateNonExtractableKey, importKeyMaterial, randomKeyMaterial } from './crypto';
import { IdbKv, type KvStore } from './kv';
import { clearPrefs, DEFAULT_PREFS, loadPrefs, savePrefs, type AppPrefs } from './prefs';
import { WebSecureStore, type SecureStore } from './secure';
import { runSync, type SyncResult } from './sync';

export type SessionStatus = 'needs_server' | 'needs_login' | 'locked' | 'ready' | 'unavailable';

const RT_KEY = 'refresh_token';
const CACHE_KEY_NATIVE = 'cache_key';
const CACHE_KEY_WEB = 'cache-key';
const PKCE_KEY = 'opspilot.pkce';
/** Nach so langer Zeit im Hintergrund ist bei aktivierter Biometrie erneut zu entsperren. */
export const LOCK_AFTER_MS = 5 * 60 * 1000;
export const NATIVE_REDIRECT_URI = 'opspilot://auth';

export class AppLockedError extends ApiError {
  constructor() {
    super(0, 'app_locked', 'Die App ist gesperrt.');
  }
}

type Listener = () => void;

export class Session {
  status: SessionStatus = 'needs_server';
  prefs: AppPrefs = { ...DEFAULT_PREFS };
  config: ConfigResponse | null = null;
  me: MeResponse | null = null;
  api: ApiClient | null = null;
  mock: MockServer | null = null;
  cache: ScopedCache | null = null;
  online = true;
  /** Grund für Status `unavailable` */
  unavailableMessage = '';
  version = __APP_VERSION__;

  private unlocked = false;
  private backgroundSince: number | null = null;
  private pkce: PkceSession | null = null;
  private listeners = new Set<Listener>();
  readonly offline: OfflineCache;

  constructor(readonly secure: SecureStore, cacheKv: KvStore, private readonly keysKv: KvStore) {
    this.offline = new OfflineCache(cacheKv, () => this.cacheKey());
  }

  static async create(): Promise<Session> {
    let secure: SecureStore;
    if (isNative) {
      const { NativeSecureStore } = await import('../platform/secure-native');
      secure = new NativeSecureStore();
    } else {
      secure = new WebSecureStore(new IdbKv('secure'), new IdbKv('keys'));
    }
    return new Session(secure, new IdbKv('cache'), new IdbKv('keys'));
  }

  onChange(l: Listener): () => void {
    this.listeners.add(l);
    return () => this.listeners.delete(l);
  }

  private emit(): void {
    for (const l of this.listeners) l();
  }

  get isDemo(): boolean {
    return this.prefs.demo;
  }

  // ---------------------------------------------------------------- Schlüssel

  private cacheKeyPromise: Promise<CryptoKey> | null = null;

  /** Cache-Schlüssel: nativ Schlüsselmaterial im Keychain/Keystore, Web nicht exportierbarer CryptoKey. */
  private cacheKey(): Promise<CryptoKey> {
    if (!this.cacheKeyPromise) {
      this.cacheKeyPromise = (async () => {
        if (isNative) {
          let material = await this.secure.get(CACHE_KEY_NATIVE);
          if (!material) {
            material = randomKeyMaterial();
            await this.secure.set(CACHE_KEY_NATIVE, material);
          }
          return importKeyMaterial(material);
        }
        const existing = await this.keysKv.get<CryptoKey>(CACHE_KEY_WEB);
        if (existing) return existing;
        const k = await generateNonExtractableKey();
        await this.keysKv.put(CACHE_KEY_WEB, k);
        return k;
      })().catch((e) => {
        this.cacheKeyPromise = null;
        throw e;
      });
    }
    return this.cacheKeyPromise;
  }

  // ---------------------------------------------------------------- Client

  private refreshTokenStore(): RefreshTokenStore {
    return {
      get: async () => {
        if (!this.unlocked) throw new AppLockedError();
        return this.secure.get(RT_KEY);
      },
      set: async (t) => {
        if (t) await this.secure.set(RT_KEY, t);
        else await this.secure.remove(RT_KEY);
      },
    };
  }

  private buildClient(): void {
    let fetchFn: FetchLike | undefined;
    let baseUrl = this.prefs.serverUrl ?? '';
    if (this.prefs.demo) {
      this.mock ??= createMockServer();
      fetchFn = this.mock.fetch;
      baseUrl = MOCK_BASE_URL;
    }
    this.api = new ApiClient({
      baseUrl,
      tenant: this.prefs.demo ? null : this.prefs.tenant,
      ...(fetchFn ? { fetch: fetchFn } : {}),
      refreshTokenStore: this.refreshTokenStore(),
      onSessionExpired: () => {
        if (this.status === 'ready') void this.toLogin('Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.');
      },
      onTokens: (t) => {
        this.me = t.user;
      },
    });
  }

  private async savePrefs(): Promise<void> {
    await savePrefs(this.prefs);
  }

  // ---------------------------------------------------------------- Start

  /** Ermittelt beim App-Start den Zustand. `forceDemo` z. B. über `?mock=1`. */
  async init(forceDemo = false): Promise<void> {
    this.prefs = await loadPrefs();
    this.version = await appVersion();
    this.online = await isOnline();
    if (forceDemo && !this.prefs.demo) {
      await this.resetForNewServer();
      this.prefs.demo = true;
      await this.savePrefs();
    }
    if (!this.prefs.serverUrl && !this.prefs.demo) {
      this.status = 'needs_server';
      return;
    }
    this.buildClient();
    // Bei aktiver Biometrie wird das Refresh-Token vor der Prüfung nicht einmal gelesen
    // (Biometrie ist nur bei bestehender Sitzung aktiv; Logout setzt sie zurück).
    if (this.prefs.biometric && isNative && this.prefs.lastUserId !== null) {
      this.status = 'locked';
      return;
    }
    const hasToken = !!(await this.secure.get(RT_KEY));
    if (!hasToken) {
      this.status = 'needs_login';
      void this.loadConfig().catch(() => undefined);
      return;
    }
    await this.afterUnlock();
  }

  private async afterUnlock(): Promise<void> {
    this.unlocked = true;
    const userId = this.prefs.lastUserId;
    if (userId !== null) this.cache = this.offline.forScope(await this.scopeFor(userId));
    try {
      this.me = await this.api!.getMe();
      await this.enterReady();
    } catch (e) {
      const err = toApiError(e);
      if (err.isConnectivity || err.code === 'maintenance') {
        const cachedMe = this.cache ? await this.cache.get<MeResponse>(CacheKeys.me) : null;
        if (cachedMe) {
          this.me = cachedMe.data;
          this.status = 'ready';
          this.emit();
          return;
        }
        this.status = 'unavailable';
        this.unavailableMessage = err.code === 'maintenance' ? err.userMessage : 'Keine Verbindung zum Server und noch keine Offline-Daten vorhanden.';
        return;
      }
      await this.toLogin(err.requiresLogin || err.status === 401 ? 'Bitte melde dich erneut an.' : err.userMessage);
    }
  }

  private async scopeFor(userId: number): Promise<string> {
    return scopeId(this.prefs.demo ? MOCK_BASE_URL : this.prefs.serverUrl ?? '', this.prefs.demo ? null : this.prefs.tenant, userId);
  }

  private async enterReady(): Promise<void> {
    const me = this.me!;
    if (this.prefs.lastUserId !== me.user.id) {
      this.prefs.lastUserId = me.user.id;
      await this.savePrefs();
    }
    this.cache = this.offline.forScope(await this.scopeFor(me.user.id));
    await this.cache.put(CacheKeys.me, me);
    this.prefs.branding = {
      name: me.tenant.name,
      primary: me.tenant.branding.primary,
      accent: me.tenant.branding.accent,
      logo_url: me.tenant.branding.logo_url,
    };
    await this.savePrefs();
    this.status = 'ready';
    this.emit();
    void this.registerPush();
  }

  /** Erneuter Versuch aus dem Status `unavailable`. */
  async retry(): Promise<void> {
    this.online = await isOnline();
    await this.afterUnlock();
    this.emit();
  }

  // ---------------------------------------------------------------- Server

  async loadConfig(): Promise<ConfigResponse> {
    if (!this.api) this.buildClient();
    this.config = await this.api!.getConfig();
    this.prefs.branding = {
      name: this.config.tenant.name,
      primary: this.config.tenant.branding.primary,
      accent: this.config.tenant.branding.accent,
      logo_url: this.config.tenant.branding.logo_url,
    };
    await this.savePrefs();
    this.emit();
    return this.config;
  }

  /**
   * Prüft die Server-Adresse (lädt `GET config`) und übernimmt sie erst bei Erfolg.
   * Wechsel des Servers verwirft alle lokalen Daten des alten Servers.
   */
  async setServer(urlInput: string, tenantInput: string): Promise<ConfigResponse> {
    const url = normalizeServerUrl(urlInput);
    const tenant = normalizeTenantSlug(tenantInput);
    const probe = new ApiClient({ baseUrl: url, tenant, refreshTokenStore: { get: async () => null, set: async () => undefined } });
    const config = await probe.getConfig();
    if (config.api_version !== 1) throw new ApiError(0, 'server_error', `Nicht unterstützte API-Version ${config.api_version}.`);
    if (url !== this.prefs.serverUrl || tenant !== this.prefs.tenant || this.prefs.demo) await this.resetForNewServer();
    this.prefs.serverUrl = url;
    this.prefs.tenant = tenant;
    this.prefs.demo = false;
    await this.savePrefs();
    this.buildClient();
    this.config = config;
    this.status = 'needs_login';
    this.emit();
    return config;
  }

  async startDemo(): Promise<void> {
    await this.resetForNewServer();
    this.prefs.demo = true;
    await this.savePrefs();
    this.buildClient();
    await this.loadConfig();
    this.status = 'needs_login';
    this.emit();
  }

  /** Zurück zur Server-Einrichtung (behält nichts vom alten Server). */
  async changeServer(): Promise<void> {
    await this.logout();
    await this.resetForNewServer();
    this.prefs.serverUrl = null;
    this.prefs.tenant = null;
    this.prefs.demo = false;
    this.prefs.branding = null;
    await this.savePrefs();
    this.api = null;
    this.config = null;
    this.status = 'needs_server';
    this.emit();
  }

  private async resetForNewServer(): Promise<void> {
    await this.offline.clearAll().catch(() => undefined);
    await this.secure.remove(RT_KEY).catch(() => undefined);
    this.prefs.lastUserId = null;
    this.prefs.deviceId = null;
    this.prefs.biometric = false;
    this.me = null;
    this.cache = null;
    this.mock = null;
  }

  // ---------------------------------------------------------------- Anmeldung

  async login(username: string, password: string): Promise<void> {
    if (!this.api) this.buildClient();
    this.unlocked = true;
    const t = await this.api!.login({ username: username.trim(), password, device_name: deviceName() });
    await this.finishLogin(t);
  }

  private async finishLogin(t: TokenResponse): Promise<void> {
    this.me = t.user;
    // Benutzerwechsel auf demselben Gerät: Cache des Vorgängers verwerfen
    if (this.prefs.lastUserId !== null && this.prefs.lastUserId !== t.user.user.id) {
      await this.offline.clearAll();
    }
    await this.enterReady();
  }

  webRedirectUri(): string {
    return `${window.location.origin}${window.location.pathname}`;
  }

  async startOidc(): Promise<void> {
    const cfg = this.config ?? (await this.loadConfig());
    const authorize = cfg.auth.oidc.enabled ? cfg.auth.oidc.authorize_url : null;
    if (!authorize) throw new ApiError(0, 'module_disabled');
    const redirect = isNative ? NATIVE_REDIRECT_URI : this.webRedirectUri();
    const { url, session } = await buildAuthorizeUrl(authorize, redirect);
    this.pkce = session;
    if (!isNative) sessionStorage.setItem(PKCE_KEY, JSON.stringify(session));
    await openSystemBrowser(url);
  }

  /** true, wenn die URL ein (Web-)OIDC-Rücksprung ist, der verarbeitet werden kann. */
  hasPendingWebOidc(): boolean {
    if (isNative) return false;
    const p = new URLSearchParams(window.location.search);
    return (p.has('code') || p.has('error')) && p.has('state') && !!sessionStorage.getItem(PKCE_KEY);
  }

  async completeOidc(redirectUrl: string): Promise<void> {
    await closeSystemBrowser();
    let pkce = this.pkce;
    if (!pkce && !isNative) {
      const raw = sessionStorage.getItem(PKCE_KEY);
      pkce = raw ? (JSON.parse(raw) as PkceSession) : null;
    }
    this.pkce = null;
    if (!isNative) sessionStorage.removeItem(PKCE_KEY);
    const { code, state, error } = parseAuthRedirect(redirectUrl);
    if (error) throw new ApiError(0, 'invalid_credentials', 'Die Anmeldung beim Firmenkonto wurde abgebrochen oder abgelehnt.');
    if (!pkce || !code || state !== pkce.state || Date.now() - pkce.createdAt > 10 * 60 * 1000) {
      throw new ApiError(0, 'invalid_request', 'Die Anmeldung konnte nicht zugeordnet werden. Bitte erneut versuchen.');
    }
    if (!this.api) this.buildClient();
    this.unlocked = true;
    const t = await this.api!.oidcToken({ code, code_verifier: pkce.verifier, redirect_uri: pkce.redirectUri });
    await this.finishLogin(t);
  }

  private async toLogin(message: string): Promise<void> {
    this.api?.dropAccessToken();
    await this.secure.remove(RT_KEY).catch(() => undefined);
    this.me = null;
    this.status = 'needs_login';
    this.loginMessage = message;
    this.emit();
  }

  /** Hinweis für den Login-Bildschirm (z. B. „Sitzung abgelaufen“) */
  loginMessage = '';

  // ---------------------------------------------------------------- Biometrie / Sperre

  async unlock(): Promise<boolean> {
    const info = await biometryInfo();
    if (!info.available) {
      // Biometrie nicht (mehr) verfügbar: zurück zur Passwort-Anmeldung
      this.prefs.biometric = false;
      await this.savePrefs();
      await this.toLogin('Biometrie ist auf diesem Gerät nicht verfügbar. Bitte melde dich mit deinem Passwort an.');
      return false;
    }
    const ok = await authenticateBiometric('Zum Entsperren von OpsPilot');
    if (!ok) return false;
    await this.afterUnlock();
    this.emit();
    return true;
  }

  async setBiometric(enabled: boolean): Promise<{ ok: boolean; message: string }> {
    if (!enabled) {
      this.prefs.biometric = false;
      await this.savePrefs();
      return { ok: true, message: 'Biometrische Entsperrung deaktiviert.' };
    }
    const info = await biometryInfo();
    if (!info.available) return { ok: false, message: info.reason || 'Auf diesem Gerät ist keine Biometrie verfügbar.' };
    const ok = await authenticateBiometric(`${info.label} für OpsPilot aktivieren`);
    if (!ok) return { ok: false, message: 'Bestätigung abgebrochen.' };
    this.prefs.biometric = true;
    await this.savePrefs();
    return { ok: true, message: `Entsperren mit ${info.label} aktiviert.` };
  }

  /** App geht in den Hintergrund / kommt zurück. */
  async handleAppActive(active: boolean): Promise<void> {
    if (!active) {
      this.backgroundSince = Date.now();
      return;
    }
    const since = this.backgroundSince;
    this.backgroundSince = null;
    if (this.status === 'ready' && this.prefs.biometric && isNative && since !== null && Date.now() - since > LOCK_AFTER_MS) {
      this.api?.dropAccessToken();
      this.unlocked = false;
      this.status = 'locked';
      this.emit();
    }
  }

  // ---------------------------------------------------------------- Push & Sync

  private async registerPush(): Promise<void> {
    if (!isNative || !this.api) return;
    try {
      await initPush({
        onToken: async (reg) => {
          try {
            const res = await this.api!.registerDevice({ ...reg, app_version: this.version.split(' ')[0] ?? this.version });
            this.prefs.deviceId = res.id;
            await this.savePrefs();
          } catch {
            /* nächster Start versucht es erneut */
          }
        },
        onPlanChanged: () => void this.sync().then(() => this.emitSync()),
        onOpen: () => {
          window.location.hash = '#/plan';
        },
      });
    } catch {
      /* Push optional */
    }
  }

  private syncListeners = new Set<(r: SyncResult) => void>();
  private lastSync: SyncResult | null = null;

  onSync(l: (r: SyncResult) => void): () => void {
    this.syncListeners.add(l);
    return () => this.syncListeners.delete(l);
  }

  private emitSync(): void {
    if (this.lastSync) for (const l of this.syncListeners) l(this.lastSync);
  }

  async sync(): Promise<SyncResult | null> {
    if (!this.api || !this.cache || this.status !== 'ready') return null;
    try {
      this.lastSync = await runSync(this.api, this.cache);
      return this.lastSync;
    } catch {
      return null;
    }
  }

  // ---------------------------------------------------------------- Abmelden / Löschen

  /** Abmelden: Server-Abmeldung, Push-Registrierung entfernen, alle lokalen Benutzerdaten löschen. */
  async logout(): Promise<void> {
    if (this.api && this.status === 'ready') {
      if (this.prefs.deviceId) await this.api.unregisterDevice(this.prefs.deviceId).catch(() => undefined);
      await this.api.logout();
    } else {
      await this.secure.remove(RT_KEY).catch(() => undefined);
    }
    await unregisterPush();
    await this.offline.clearAll().catch(() => undefined);
    await this.secure.clear().catch(() => undefined);
    this.cacheKeyPromise = null;
    if (!isNative) await this.keysKv.delete(CACHE_KEY_WEB).catch(() => undefined);
    this.prefs.lastUserId = null;
    this.prefs.deviceId = null;
    this.prefs.biometric = false;
    await this.savePrefs();
    this.me = null;
    this.cache = null;
    this.unlocked = false;
    this.loginMessage = 'Du wurdest abgemeldet.';
    this.status = this.prefs.serverUrl || this.prefs.demo ? 'needs_login' : 'needs_server';
    this.emit();
  }

  /** „Lokale Daten löschen“: Abmelden und vollständiger Reset inkl. Server-Einstellungen. */
  async wipeLocal(): Promise<void> {
    await this.logout();
    await this.offline.clearAll().catch(() => undefined);
    await this.keysKv.clear().catch(() => undefined);
    await clearPrefs();
    this.prefs = { ...DEFAULT_PREFS };
    this.api = null;
    this.mock = null;
    this.config = null;
    this.loginMessage = '';
    this.status = 'needs_server';
    this.emit();
  }

  async setTheme(theme: AppPrefs['theme']): Promise<void> {
    this.prefs.theme = theme;
    await this.savePrefs();
  }
}
