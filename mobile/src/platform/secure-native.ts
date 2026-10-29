import { KeychainAccess, SecureStorage } from '@aparajita/capacitor-secure-storage';
import type { SecureStore } from '../store/secure';

/**
 * Keychain (iOS) bzw. Android Keystore. Einträge sind an dieses Gerät gebunden
 * (`whenUnlockedThisDeviceOnly`) und wandern nicht in Backups/auf neue Geräte.
 */
export class NativeSecureStore implements SecureStore {
  private ready: Promise<void> | null = null;

  private init(): Promise<void> {
    if (!this.ready) {
      this.ready = (async () => {
        await SecureStorage.setKeyPrefix('opspilot_');
        await SecureStorage.setDefaultKeychainAccess(KeychainAccess.whenUnlockedThisDeviceOnly);
        await SecureStorage.setSynchronize(false); // kein iCloud-Schlüsselbund
      })();
    }
    return this.ready;
  }

  async get(key: string): Promise<string | null> {
    await this.init();
    const v = await SecureStorage.get(key, false);
    return typeof v === 'string' ? v : null;
  }

  async set(key: string, value: string): Promise<void> {
    await this.init();
    await SecureStorage.set(key, value, false);
  }

  async remove(key: string): Promise<void> {
    await this.init();
    await SecureStorage.remove(key);
  }

  async clear(): Promise<void> {
    await this.init();
    await SecureStorage.clear();
  }
}
