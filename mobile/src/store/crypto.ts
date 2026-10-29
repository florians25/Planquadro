/** AES-GCM-Verschlüsselung für lokal gespeicherte Daten (WebCrypto). */

export interface EncryptedRecord {
  v: 1;
  iv: Uint8Array;
  ct: ArrayBuffer;
}

const enc = new TextEncoder();
const dec = new TextDecoder();

/** Erzeugt einen NICHT exportierbaren AES-GCM-256-Schlüssel (Web: in IndexedDB ablegbar). */
export function generateNonExtractableKey(): Promise<CryptoKey> {
  return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}

/** Zufälliges Schlüsselmaterial (32 Byte, base64) – nativ im sicheren Speicher abgelegt. */
export function randomKeyMaterial(): string {
  const bytes = new Uint8Array(32);
  crypto.getRandomValues(bytes);
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin);
}

/** Importiert base64-Schlüsselmaterial als nicht exportierbaren AES-GCM-Schlüssel. */
export async function importKeyMaterial(b64: string): Promise<CryptoKey> {
  const bin = atob(b64);
  const raw = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) raw[i] = bin.charCodeAt(i);
  if (raw.length !== 32) throw new Error('Ungültiges Schlüsselmaterial');
  return crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
}

/**
 * Verschlüsselt einen JSON-Wert. `aad` (z. B. der Datensatzschlüssel) wird
 * authentifiziert, damit Einträge nicht unbemerkt vertauscht werden können.
 */
export async function encryptJson(key: CryptoKey, value: unknown, aad: string): Promise<EncryptedRecord> {
  const iv = new Uint8Array(12);
  crypto.getRandomValues(iv);
  const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: enc.encode(aad) }, key, enc.encode(JSON.stringify(value)));
  return { v: 1, iv, ct };
}

export async function decryptJson<T>(key: CryptoKey, rec: EncryptedRecord, aad: string): Promise<T> {
  const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: rec.iv as Uint8Array<ArrayBuffer>, additionalData: enc.encode(aad) }, key, rec.ct);
  return JSON.parse(dec.decode(pt)) as T;
}

export function isEncryptedRecord(v: unknown): v is EncryptedRecord {
  return !!v && typeof v === 'object' && (v as EncryptedRecord).v === 1 && (v as EncryptedRecord).iv instanceof Uint8Array;
}
