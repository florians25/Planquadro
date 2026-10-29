import { describe, expect, it, vi } from 'vitest';
import { ApiClient, type FetchLike, type RefreshTokenStore } from '../src/api/client';
import { ApiError, errorFromResponse } from '../src/api/errors';
import { createMockServer, MOCK_BASE_URL } from '../src/api/mock';
import type { TokenResponse } from '../src/api/types';
import meFx from '../src/api/fixtures/me.json';

const BASE = 'https://vireo.example/api/v1/';

function memStore(initial: string | null = null): RefreshTokenStore & { value: string | null; writes: Array<string | null> } {
  const s = {
    value: initial,
    writes: [] as Array<string | null>,
    async get() {
      return s.value;
    },
    async set(t: string | null) {
      s.value = t;
      s.writes.push(t);
    },
  };
  return s;
}

function jsonRes(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
}

function tokens(n: number, expiresIn = 900): TokenResponse {
  return {
    access_token: `at-${n}`,
    token_type: 'Bearer',
    expires_in: expiresIn,
    refresh_token: `rt-${n}`,
    refresh_expires_in: 2592000,
    user: meFx as TokenResponse['user'],
  };
}

interface Call {
  url: string;
  method: string;
  headers: Headers;
  body: unknown;
}

/** Fake-Server: Access-Token `at-N` ist gültig, wenn N === current. */
function fakeServer() {
  const calls: Call[] = [];
  let current = 0;
  let refreshCount = 0;
  const fetch: FetchLike = async (url, init) => {
    const headers = new Headers(init.headers);
    const body = typeof init.body === 'string' ? JSON.parse(init.body) : undefined;
    calls.push({ url, method: init.method ?? 'GET', headers, body });
    const path = new URL(url).pathname.replace('/api/v1/', '');
    if (path === 'auth/refresh') {
      refreshCount++;
      await new Promise((r) => setTimeout(r, 10));
      if (body?.refresh_token !== `rt-${current}`) return jsonRes(401, { error: { code: 'invalid_token', message: 'Token ungültig' } });
      current++;
      return jsonRes(200, tokens(current));
    }
    if (path === 'auth/login') {
      current++;
      return jsonRes(200, tokens(current));
    }
    const auth = headers.get('Authorization');
    if (auth !== `Bearer at-${current}`) return jsonRes(401, { error: { code: 'token_expired', message: 'abgelaufen' } });
    if (path === 'me') return jsonRes(200, meFx);
    if (path === 'notifications') return jsonRes(200, { unread: 0, items: [] });
    if (path === 'auth/logout') return jsonRes(204, null);
    return jsonRes(404, { error: { code: 'not_found', message: 'nicht gefunden' } });
  };
  return {
    fetch,
    calls,
    get refreshCount() {
      return refreshCount;
    },
    setCurrent(n: number) {
      current = n;
    },
  };
}

describe('ApiClient – Token-Refresh', () => {
  it('führt bei parallelen 401 token_expired genau einen Refresh aus (Single-Flight)', async () => {
    const srv = fakeServer();
    const store = memStore('rt-0');
    const client = new ApiClient({ baseUrl: BASE, fetch: srv.fetch, refreshTokenStore: store });
    // Kein Access-Token im Speicher → alle drei Aufrufe brauchen einen Refresh
    const results = await Promise.all([client.getMe(), client.getNotifications(), client.getMe()]);
    expect(results).toHaveLength(3);
    expect(srv.refreshCount).toBe(1);
    expect(store.value).toBe('rt-1');
  });

  it('erneuert nach 401 token_expired und wiederholt den Aufruf einmal', async () => {
    const srv = fakeServer();
    const store = memStore('rt-0');
    const client = new ApiClient({ baseUrl: BASE, fetch: srv.fetch, refreshTokenStore: store });
    await client.getMe(); // Refresh → at-1
    srv.setCurrent(1);
    // Simuliert: Access-Token im Speicher ist serverseitig abgelaufen (rt-1 bleibt gültig)
    (client as unknown as { accessToken: string }).accessToken = 'at-alt';
    const p1 = client.getMe();
    const p2 = client.getNotifications();
    await Promise.all([p1, p2]);
    expect(srv.refreshCount).toBe(2);
    const meCalls = srv.calls.filter((c) => c.url.endsWith('/me'));
    expect(meCalls.at(-1)!.headers.get('Authorization')).toBe('Bearer at-2');
  });

  it('erneuert proaktiv kurz vor Ablauf', async () => {
    const srv = fakeServer();
    let now = 1_000_000;
    const store = memStore();
    const client = new ApiClient({ baseUrl: BASE, fetch: srv.fetch, refreshTokenStore: store, now: () => now });
    await client.login({ username: 'u', password: 'p', device_name: 'test' });
    expect(store.value).toBe('rt-1');
    await client.getMe();
    expect(srv.refreshCount).toBe(0);
    now += 890_000; // 10 s vor Ablauf (900 s) → innerhalb des Sicherheitsabstands
    await client.getMe();
    expect(srv.refreshCount).toBe(1);
  });

  it('verwirft das Refresh-Token und meldet session_expired, wenn der Refresh abgelehnt wird', async () => {
    const srv = fakeServer();
    const store = memStore('rt-falsch');
    const onSessionExpired = vi.fn();
    const client = new ApiClient({ baseUrl: BASE, fetch: srv.fetch, refreshTokenStore: store, onSessionExpired });
    await expect(client.getMe()).rejects.toMatchObject({ code: 'session_expired' });
    expect(store.value).toBeNull();
    expect(onSessionExpired).toHaveBeenCalledTimes(1);
  });

  it('behält das Refresh-Token, wenn der Refresh am Netzwerk scheitert', async () => {
    const store = memStore('rt-0');
    const client = new ApiClient({
      baseUrl: BASE,
      fetch: async () => {
        throw new TypeError('Failed to fetch');
      },
      refreshTokenStore: store,
    });
    await expect(client.getMe()).rejects.toMatchObject({ code: 'network_error' });
    expect(store.value).toBe('rt-0');
  });

  it('ohne Refresh-Token → session_expired ohne Netzwerkaufruf', async () => {
    const fetch = vi.fn<FetchLike>();
    const client = new ApiClient({ baseUrl: BASE, fetch, refreshTokenStore: memStore(null) });
    await expect(client.getMe()).rejects.toMatchObject({ code: 'session_expired' });
    expect(fetch).not.toHaveBeenCalled();
  });

  it('Logout sendet das Refresh-Token und löscht lokal', async () => {
    const srv = fakeServer();
    const store = memStore();
    const client = new ApiClient({ baseUrl: BASE, fetch: srv.fetch, refreshTokenStore: store });
    await client.login({ username: 'u', password: 'p', device_name: 'test' });
    await client.logout();
    const call = srv.calls.find((c) => c.url.endsWith('auth/logout'))!;
    expect(call.method).toBe('POST');
    expect(call.body).toEqual({ refresh_token: 'rt-1' });
    expect(call.headers.get('Authorization')).toBe('Bearer at-1');
    expect(store.value).toBeNull();
    expect(client.hasAccessToken).toBe(false);
  });
});

describe('ApiClient – Header und URLs', () => {
  it('setzt X-Vireo-Tenant, Accept und Content-Type', async () => {
    const seen: Headers[] = [];
    const client = new ApiClient({
      baseUrl: BASE,
      tenant: 'acme',
      fetch: async (_url, init) => {
        seen.push(new Headers(init.headers));
        return jsonRes(200, tokens(1));
      },
      refreshTokenStore: memStore(),
    });
    await client.login({ username: 'u', password: 'p', device_name: 'x' });
    expect(seen[0]!.get('X-Vireo-Tenant')).toBe('acme');
    expect(seen[0]!.get('Accept')).toBe('application/json');
    expect(seen[0]!.get('Content-Type')).toBe('application/json');
    expect(seen[0]!.get('Authorization')).toBeNull();
  });

  it('ohne Mandant kein Tenant-Header', async () => {
    let headers: Headers | null = null;
    const client = new ApiClient({
      baseUrl: BASE,
      fetch: async (_u, init) => {
        headers = new Headers(init.headers);
        return jsonRes(200, { status: 'ok' });
      },
      refreshTokenStore: memStore(),
    });
    await client.getHealth();
    expect(headers!.has('X-Vireo-Tenant')).toBe(false);
  });

  it('baut Query-Parameter und kodiert Pfadsegmente', async () => {
    const urls: string[] = [];
    const store = memStore();
    const client = new ApiClient({
      baseUrl: BASE,
      fetch: async (url) => {
        urls.push(url);
        if (url.includes('auth/login')) return jsonRes(200, tokens(1));
        return jsonRes(200, {});
      },
      refreshTokenStore: store,
    });
    await client.login({ username: 'u', password: 'p', device_name: 'x' });
    await client.getMyPlan('2026-11-01', '2026-11-30');
    await client.getTeamPlan(2026, 11);
    await client.getAssignment('2026-11-02', 14);
    await client.getAssignment('2026-11-02');
    await client.getSync(null);
    await client.getSync('1842');
    expect(urls.slice(1)).toEqual([
      `${BASE}plan/my?from=2026-11-01&to=2026-11-30`,
      `${BASE}plan/team?year=2026&month=11`,
      `${BASE}assignments/2026-11-02?user_id=14`,
      `${BASE}assignments/2026-11-02`,
      `${BASE}sync`,
      `${BASE}sync?since=1842`,
    ]);
  });

  it('verlangt eine Basis auf /api/v1/', () => {
    expect(() => new ApiClient({ baseUrl: 'https://x.example/', refreshTokenStore: memStore() })).toThrow();
  });
});

describe('Fehlerabbildung', () => {
  it('liest das Vertragsformat {error:{code,message}}', async () => {
    const e = await errorFromResponse(jsonRes(403, { error: { code: 'account_locked', message: 'Konto gesperrt.' } }));
    expect(e).toBeInstanceOf(ApiError);
    expect(e.status).toBe(403);
    expect(e.code).toBe('account_locked');
    expect(e.userMessage).toBe('Konto gesperrt.');
  });

  it('password_change_required verweist auf die Weboberfläche', async () => {
    const e = await errorFromResponse(jsonRes(403, { error: { code: 'password_change_required', message: 'x' } }));
    expect(e.userMessage).toMatch(/Weboberfläche/);
  });

  it('rate_limited übernimmt Retry-After', async () => {
    const e = await errorFromResponse(jsonRes(429, { error: { code: 'rate_limited', message: 'zu viele' } }, { 'Retry-After': '30' }));
    expect(e.retryAfter).toBe(30);
    expect(e.userMessage).toContain('30 Sekunden');
  });

  it('fällt bei Nicht-JSON auf den HTTP-Status zurück', async () => {
    expect((await errorFromResponse(new Response('<html>Bad Gateway</html>', { status: 502 }))).code).toBe('server_error');
    expect((await errorFromResponse(new Response('', { status: 503 }))).code).toBe('maintenance');
    expect((await errorFromResponse(new Response('', { status: 404 }))).code).toBe('not_found');
  });

  it('Netzwerkfehler → network_error (isConnectivity)', async () => {
    const client = new ApiClient({
      baseUrl: BASE,
      fetch: async () => {
        throw new TypeError('Failed to fetch');
      },
      refreshTokenStore: memStore(),
    });
    const err = (await client.getConfig().then(() => null, (e: unknown) => e)) as ApiError;
    expect(err.code).toBe('network_error');
    expect(err.isConnectivity).toBe(true);
  });

  it('ungültiges JSON in 200-Antwort → server_error', async () => {
    const client = new ApiClient({ baseUrl: BASE, fetch: async () => new Response('kein json', { status: 200 }), refreshTokenStore: memStore() });
    await expect(client.getConfig()).rejects.toMatchObject({ code: 'server_error' });
  });

  it('401 invalid_token bei geschütztem Aufruf löscht die Sitzung', async () => {
    const store = memStore();
    const onSessionExpired = vi.fn();
    const client = new ApiClient({
      baseUrl: BASE,
      fetch: async (url) =>
        url.includes('auth/login') ? jsonRes(200, tokens(1)) : jsonRes(401, { error: { code: 'invalid_token', message: 'anderer Mandant' } }),
      refreshTokenStore: store,
      onSessionExpired,
    });
    await client.login({ username: 'u', password: 'p', device_name: 'x' });
    await expect(client.getMe()).rejects.toMatchObject({ code: 'invalid_token' });
    expect(store.value).toBeNull();
    expect(onSessionExpired).toHaveBeenCalled();
  });
});

describe('Mock-Server (Demo-Modus) gegen den echten Client', () => {
  const make = (opts = {}) => {
    const mock = createMockServer({ latencyMs: 0, ...opts });
    const store = memStore();
    const client = new ApiClient({ baseUrl: MOCK_BASE_URL, fetch: mock.fetch, refreshTokenStore: store });
    return { mock, store, client };
  };

  it.each([
    ['gesperrt', 'account_locked', 403],
    ['neu', 'password_change_required', 403],
    ['wartung', 'maintenance', 503],
  ])('Login „%s“ → %s', async (username, code, status) => {
    const { client } = make();
    await expect(client.login({ username, password: 'demo', device_name: 't' })).rejects.toMatchObject({ code, status });
  });

  it('falsches Passwort → invalid_credentials', async () => {
    const { client } = make();
    await expect(client.login({ username: 'max', password: 'x', device_name: 't' })).rejects.toMatchObject({ code: 'invalid_credentials' });
  });

  it('liefert Plan, Team (vertraulich nur „A“) und Details', async () => {
    const { client } = make();
    await client.login({ username: 'max', password: 'demo', device_name: 't' });
    const plan = await client.getMyPlan('2026-11-01', '2026-11-30');
    expect(plan.days).toHaveLength(30);
    expect(plan.days[0]!.holiday?.name).toBe('Allerheiligen');
    const team = await client.getTeamPlan(2026, 11);
    for (const c of Object.values(team.cells)) if (c.confidential) expect(c.code).toBe('A');
    const workday = plan.days.find((d) => d.entry?.kind === 'assignment')!;
    const det = await client.getAssignment(workday.date);
    expect(det.area).not.toBeNull();
  });

  it('erkennt Wiederverwendung eines Refresh-Tokens (Rotation)', async () => {
    const { client, store, mock } = make();
    await client.login({ username: 'max', password: 'demo', device_name: 't' });
    const first = store.value!;
    await client.refresh();
    expect(store.value).not.toBe(first);
    const reuse = await mock.fetch(`${MOCK_BASE_URL}auth/refresh`, { method: 'POST', body: JSON.stringify({ refresh_token: first }) });
    expect(reuse.status).toBe(401);
    // ganze Familie gesperrt → auch das neue Token ist ungültig
    await expect(client.refresh()).rejects.toMatchObject({ code: 'session_expired' });
  });

  it('Mandanten-Header wird vom Mock geprüft', async () => {
    const mock = createMockServer({ latencyMs: 0, requireTenant: 'acme' });
    const ok = new ApiClient({ baseUrl: MOCK_BASE_URL, tenant: 'acme', fetch: mock.fetch, refreshTokenStore: memStore() });
    const bad = new ApiClient({ baseUrl: MOCK_BASE_URL, tenant: 'andere', fetch: mock.fetch, refreshTokenStore: memStore() });
    await expect(ok.getConfig()).resolves.toMatchObject({ api_version: 1 });
    await expect(bad.getConfig()).rejects.toMatchObject({ code: 'not_found' });
  });

  it('plan/my lehnt mehr als 93 Tage ab', async () => {
    const { client } = make();
    await client.login({ username: 'max', password: 'demo', device_name: 't' });
    await expect(client.getMyPlan('2026-01-01', '2026-12-31')).rejects.toMatchObject({ code: 'invalid_request' });
  });
});
