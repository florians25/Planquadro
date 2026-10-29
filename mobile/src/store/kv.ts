/** Schlüssel-Wert-Speicher (asynchron). Implementierungen: IndexedDB und Speicher (Tests). */
export interface KvStore {
  get<T = unknown>(key: string): Promise<T | undefined>;
  put(key: string, value: unknown): Promise<void>;
  delete(key: string): Promise<void>;
  keys(): Promise<string[]>;
  clear(): Promise<void>;
}

export class MemoryKv implements KvStore {
  private map = new Map<string, unknown>();
  async get<T>(key: string): Promise<T | undefined> {
    return this.map.get(key) as T | undefined;
  }
  async put(key: string, value: unknown): Promise<void> {
    this.map.set(key, value);
  }
  async delete(key: string): Promise<void> {
    this.map.delete(key);
  }
  async keys(): Promise<string[]> {
    return [...this.map.keys()];
  }
  async clear(): Promise<void> {
    this.map.clear();
  }
}

const DB_NAME = 'planquadro';
const DB_VERSION = 1;
export const IDB_STORES = ['secure', 'keys', 'cache'] as const;
export type IdbStoreName = (typeof IDB_STORES)[number];

let dbPromise: Promise<IDBDatabase> | null = null;

function openDb(): Promise<IDBDatabase> {
  if (!dbPromise) {
    dbPromise = new Promise<IDBDatabase>((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        for (const name of IDB_STORES) if (!db.objectStoreNames.contains(name)) db.createObjectStore(name);
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error ?? new Error('IndexedDB nicht verfügbar'));
    }).catch((e) => {
      dbPromise = null;
      throw e;
    });
  }
  return dbPromise;
}

/** Nur für Tests: Verbindung schließen und Cache verwerfen. */
export async function closeDbForTests(): Promise<void> {
  if (dbPromise) (await dbPromise).close();
  dbPromise = null;
}

function promisify<T>(req: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

export class IdbKv implements KvStore {
  constructor(private readonly storeName: IdbStoreName) {}

  private async tx(mode: IDBTransactionMode): Promise<IDBObjectStore> {
    const db = await openDb();
    return db.transaction(this.storeName, mode).objectStore(this.storeName);
  }

  async get<T>(key: string): Promise<T | undefined> {
    return promisify((await this.tx('readonly')).get(key)) as Promise<T | undefined>;
  }
  async put(key: string, value: unknown): Promise<void> {
    await promisify((await this.tx('readwrite')).put(value, key));
  }
  async delete(key: string): Promise<void> {
    await promisify((await this.tx('readwrite')).delete(key));
  }
  async keys(): Promise<string[]> {
    const ks = await promisify((await this.tx('readonly')).getAllKeys());
    return ks.map(String);
  }
  async clear(): Promise<void> {
    await promisify((await this.tx('readwrite')).clear());
  }
}
