import { describe, expect, it } from 'vitest';
import { CacheKeys, OfflineCache, scopeId } from '../src/store/cache';
import { decryptJson, encryptJson, generateNonExtractableKey, importKeyMaterial, randomKeyMaterial } from '../src/store/crypto';
import { IdbKv, MemoryKv } from '../src/store/kv';
import { WebSecureStore } from '../src/store/secure';

describe('AES-GCM', () => {
  it('Round-Trip mit nicht exportierbarem Schlüssel', async () => {
    const key = await generateNonExtractableKey();
    expect(key.extractable).toBe(false);
    const value = { days: [{ date: '2026-11-02', code: 'K1' }], umlaut: 'Köln' };
    const rec = await encryptJson(key, value, 'scope|plan:2026-11');
    expect(rec.iv).toHaveLength(12);
    expect(new TextDecoder().decode(rec.ct)).not.toContain('K1');
    await expect(decryptJson(key, rec, 'scope|plan:2026-11')).resolves.toEqual(value);
  });

  it('nicht exportierbarer Schlüssel kann nicht ausgelesen werden', async () => {
    const key = await generateNonExtractableKey();
    await expect(crypto.subtle.exportKey('raw', key)).rejects.toThrow();
  });

  it('falsche AAD (vertauschter Datensatz) wird erkannt', async () => {
    const key = await generateNonExtractableKey();
    const rec = await encryptJson(key, 'geheim', 'a');
    await expect(decryptJson(key, rec, 'b')).rejects.toThrow();
  });

  it('Schlüsselmaterial aus dem sicheren Speicher (nativ) importieren', async () => {
    const material = randomKeyMaterial();
    const k1 = await importKeyMaterial(material);
    const k2 = await importKeyMaterial(material);
    expect(k1.extractable).toBe(false);
    const rec = await encryptJson(k1, 42, 'x');
    await expect(decryptJson(k2, rec, 'x')).resolves.toBe(42);
    await expect(importKeyMaterial(btoa('zu kurz'))).rejects.toThrow();
  });
});

describe('OfflineCache', () => {
  const make = async (kv = new MemoryKv()) => {
    const key = await generateNonExtractableKey();
    let t = 1000;
    return { kv, cache: new OfflineCache(kv, async () => key, () => t++), key };
  };

  it('speichert verschlüsselt und liest mit Zeitstempel zurück', async () => {
    const { kv, cache } = await make();
    await cache.put('s1', CacheKeys.plan('2026-11'), { hello: 'Welt' });
    const raw = await kv.get<{ ct: ArrayBuffer }>('s1|plan:2026-11');
    expect(new TextDecoder().decode(raw!.ct)).not.toContain('Welt');
    const got = await cache.get<{ hello: string }>('s1', CacheKeys.plan('2026-11'));
    expect(got).toEqual({ data: { hello: 'Welt' }, savedAt: 1000 });
  });

  it('trennt Benutzer-Scopes und listet Schlüssel', async () => {
    const { cache } = await make();
    await cache.put('a', 'plan:2026-11', 1);
    await cache.put('a', 'team:2026-11', 2);
    await cache.put('b', 'plan:2026-11', 3);
    expect((await cache.keys('a')).sort()).toEqual(['plan:2026-11', 'team:2026-11']);
    expect(await cache.keys('a', 'plan:')).toEqual(['plan:2026-11']);
    expect((await cache.get('b', 'plan:2026-11'))!.data).toBe(3);
    await cache.clearScope('a');
    expect(await cache.keys('a')).toEqual([]);
    expect(await cache.keys('b')).toEqual(['plan:2026-11']);
  });

  it('kopierte Datensätze anderer Benutzer lassen sich nicht entschlüsseln (AAD)', async () => {
    const { kv, cache } = await make();
    await cache.put('a', 'plan:2026-11', 'von a');
    await kv.put('b|plan:2026-11', await kv.get('a|plan:2026-11'));
    expect(await cache.get('b', 'plan:2026-11')).toBeNull();
    expect(await kv.get('b|plan:2026-11')).toBeUndefined(); // verworfen
  });

  it('nach Schlüsselwechsel werden alte Daten verworfen', async () => {
    const kv = new MemoryKv();
    const { cache } = await make(kv);
    await cache.put('a', 'x', 1);
    const other = new OfflineCache(kv, generateNonExtractableKey);
    expect(await other.get('a', 'x')).toBeNull();
  });

  it('funktioniert mit IndexedDB (inkl. CryptoKey-Ablage)', async () => {
    const keys = new IdbKv('keys');
    await keys.put('k', await generateNonExtractableKey());
    const stored = await keys.get<CryptoKey>('k');
    const cache = new OfflineCache(new IdbKv('cache'), async () => stored!);
    await cache.put('s', 'abs:2026', { left: 14.5 });
    expect((await cache.get<{ left: number }>('s', 'abs:2026'))!.data.left).toBe(14.5);
    await cache.clearAll();
    expect(await cache.get('s', 'abs:2026')).toBeNull();
  });

  it('Scope-ID ist stabil und hängt von Server, Mandant und Benutzer ab', async () => {
    const a = await scopeId('https://x/api/v1/', null, 12);
    expect(a).toBe(await scopeId('https://x/api/v1/', null, 12));
    expect(a).not.toBe(await scopeId('https://x/api/v1/', 'acme', 12));
    expect(a).not.toBe(await scopeId('https://x/api/v1/', null, 13));
    expect(a).toMatch(/^[0-9a-f]{16}:12$/);
  });
});

describe('WebSecureStore (Web-Fallback)', () => {
  it('speichert Geheimnisse nur verschlüsselt', async () => {
    const values = new MemoryKv();
    const store = new WebSecureStore(values, new MemoryKv());
    await store.set('refresh_token', 'rt-geheim-123');
    const raw = JSON.stringify(await values.get('refresh_token'), (_k, v) => (v instanceof ArrayBuffer ? new TextDecoder().decode(v) : v));
    expect(raw).not.toContain('rt-geheim-123');
    expect(await store.get('refresh_token')).toBe('rt-geheim-123');
    await store.remove('refresh_token');
    expect(await store.get('refresh_token')).toBeNull();
  });

  it('clear() verwirft auch den Schlüssel', async () => {
    const values = new MemoryKv();
    const keys = new MemoryKv();
    const store = new WebSecureStore(values, keys);
    await store.set('a', '1');
    expect(await keys.keys()).toHaveLength(1);
    await store.clear();
    expect(await keys.keys()).toHaveLength(0);
    expect(await store.get('a')).toBeNull();
  });
});
