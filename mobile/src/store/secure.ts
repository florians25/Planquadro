import { decryptJson, encryptJson, generateNonExtractableKey, isEncryptedRecord } from './crypto';
import type { KvStore } from './kv';

/**
 * Sicherer Speicher für Geheimnisse (Refresh-Token, Cache-Schlüssel).
 * - nativ: Keychain (iOS) / Android Keystore über @aparajita/capacitor-secure-storage
 * - Web:   IndexedDB, AES-GCM-verschlüsselt mit einem nicht exportierbaren WebCrypto-Schlüssel
 */
export interface SecureStore {
  get(key: string): Promise<string | null>;
  set(key: string, value: string): Promise<void>;
  remove(key: string): Promise<void>;
  clear(): Promise<void>;
}

export class MemorySecureStore implements SecureStore {
  private map = new Map<string, string>();
  async get(key: string) {
    return this.map.get(key) ?? null;
  }
  async set(key: string, value: string) {
    this.map.set(key, value);
  }
  async remove(key: string) {
    this.map.delete(key);
  }
  async clear() {
    this.map.clear();
  }
}

const WEB_KEY_ID = 'secure-store-key';

/**
 * Web-Fallback. Der Schlüssel ist nicht exportierbar: Skripte können damit nur
 * ver-/entschlüsseln, das Schlüsselmaterial selbst aber nicht auslesen.
 * Schutzniveau: schützt vor dem Auslesen der Rohdaten (z. B. Datei-Kopie des Profils
 * ohne Browser), nicht vor Code, der im selben Ursprung läuft (XSS).
 */
export class WebSecureStore implements SecureStore {
  private keyPromise: Promise<CryptoKey> | null = null;

  constructor(private readonly values: KvStore, private readonly keys: KvStore) {}

  private key(): Promise<CryptoKey> {
    if (!this.keyPromise) {
      this.keyPromise = (async () => {
        const existing = await this.keys.get<CryptoKey>(WEB_KEY_ID);
        if (existing) return existing;
        const k = await generateNonExtractableKey();
        await this.keys.put(WEB_KEY_ID, k);
        return k;
      })().catch((e) => {
        this.keyPromise = null;
        throw e;
      });
    }
    return this.keyPromise;
  }

  async get(key: string): Promise<string | null> {
    const rec = await this.values.get(key);
    if (!isEncryptedRecord(rec)) return null;
    try {
      return await decryptJson<string>(await this.key(), rec, `secure:${key}`);
    } catch {
      return null; // Schlüssel verloren/manipuliert → wie nicht vorhanden behandeln
    }
  }

  async set(key: string, value: string): Promise<void> {
    await this.values.put(key, await encryptJson(await this.key(), value, `secure:${key}`));
  }

  async remove(key: string): Promise<void> {
    await this.values.delete(key);
  }

  /** Löscht alle Werte UND den Schlüssel. */
  async clear(): Promise<void> {
    await this.values.clear();
    await this.keys.delete(WEB_KEY_ID);
    this.keyPromise = null;
  }
}
