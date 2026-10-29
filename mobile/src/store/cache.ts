import { decryptJson, encryptJson, isEncryptedRecord, type EncryptedRecord } from './crypto';
import type { KvStore } from './kv';

/**
 * Offline-Lesecache: zuletzt geladene Plan-/Team-/Abwesenheits-/Detaildaten,
 * je Benutzer getrennt und mit AES-GCM verschlüsselt.
 *
 * Datensatz-ID: `<server-hash>|<user_id>|<key>` – Daten verschiedener Server/Benutzer
 * werden nie vermischt. Die ID wird als AAD authentifiziert.
 */

export interface CachedValue<T> {
  data: T;
  savedAt: number;
}

interface StoredRecord extends EncryptedRecord {
  savedAt: number;
}

export type CacheKeyProvider = () => Promise<CryptoKey>;

export class OfflineCache {
  constructor(private readonly kv: KvStore, private readonly keyProvider: CacheKeyProvider, private readonly now: () => number = Date.now) {}

  private id(scope: string, key: string): string {
    return `${scope}|${key}`;
  }

  async put<T>(scope: string, key: string, data: T): Promise<void> {
    const id = this.id(scope, key);
    const savedAt = this.now();
    const rec = await encryptJson(await this.keyProvider(), { data, savedAt }, id);
    const stored: StoredRecord = { ...rec, savedAt };
    await this.kv.put(id, stored);
  }

  async get<T>(scope: string, key: string): Promise<CachedValue<T> | null> {
    const id = this.id(scope, key);
    const rec = await this.kv.get<StoredRecord>(id);
    if (!isEncryptedRecord(rec)) return null;
    try {
      return await decryptJson<CachedValue<T>>(await this.keyProvider(), rec, id);
    } catch {
      // Schlüssel gewechselt oder Datensatz manipuliert → verwerfen
      await this.kv.delete(id);
      return null;
    }
  }

  async delete(scope: string, key: string): Promise<void> {
    await this.kv.delete(this.id(scope, key));
  }

  /** Schlüssel (ohne Scope) eines Benutzers, optional nach Präfix gefiltert. */
  async keys(scope: string, prefix = ''): Promise<string[]> {
    const p = `${scope}|${prefix}`;
    return (await this.kv.keys()).filter((k) => k.startsWith(p)).map((k) => k.slice(scope.length + 1));
  }

  async clearScope(scope: string): Promise<void> {
    for (const k of await this.kv.keys()) if (k.startsWith(`${scope}|`)) await this.kv.delete(k);
  }

  async clearAll(): Promise<void> {
    await this.kv.clear();
  }

  /** Ansicht auf einen Benutzer-Scope. */
  forScope(scope: string): ScopedCache {
    return new ScopedCache(this, scope);
  }
}

export class ScopedCache {
  constructor(private readonly cache: OfflineCache, readonly scope: string) {}
  put<T>(key: string, data: T) {
    return this.cache.put(this.scope, key, data);
  }
  get<T>(key: string) {
    return this.cache.get<T>(this.scope, key);
  }
  delete(key: string) {
    return this.cache.delete(this.scope, key);
  }
  keys(prefix = '') {
    return this.cache.keys(this.scope, prefix);
  }
  clear() {
    return this.cache.clearScope(this.scope);
  }
}

/** Stabile, nicht umkehrbare Kennung für Server+Mandant+Benutzer (Scope). */
export async function scopeId(apiBase: string, tenant: string | null, userId: number): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(`${apiBase}#${tenant ?? ''}`));
  const hex = [...new Uint8Array(digest)].slice(0, 8).map((b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex}:${userId}`;
}

/** Cache-Schlüssel */
export const CacheKeys = {
  plan: (month: string) => `plan:${month}`,
  team: (month: string) => `team:${month}`,
  absences: (year: number) => `abs:${year}`,
  details: (date: string, userId: number) => `det:${date}:${userId}`,
  notifications: 'notifications',
  me: 'me',
  cursor: 'meta:cursor',
} as const;
