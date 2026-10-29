import { Preferences } from '@capacitor/preferences';

/** Nicht geheime Einstellungen (Capacitor Preferences; im Web localStorage). */
export type ThemeMode = 'system' | 'light' | 'dark';

export interface AppPrefs {
  /** API-Basis inkl. /api/v1/ */
  serverUrl: string | null;
  tenant: string | null;
  /** Demo-Modus mit Beispieldaten statt Server */
  demo: boolean;
  theme: ThemeMode;
  biometric: boolean;
  /** ID der Push-Registrierung (DELETE devices/{id} beim Abmelden) */
  deviceId: number | null;
  /** zuletzt angemeldeter Benutzer (für Cache-Zuordnung offline) */
  lastUserId: number | null;
  /** Branding aus GET config für den Start ohne Netz */
  branding: { name: string; primary: string; accent: string; logo_url: string | null } | null;
}

export const DEFAULT_PREFS: AppPrefs = {
  serverUrl: null,
  tenant: null,
  demo: false,
  theme: 'system',
  biometric: false,
  deviceId: null,
  lastUserId: null,
  branding: null,
};

const KEY = 'vireo.prefs.v1';

export async function loadPrefs(): Promise<AppPrefs> {
  try {
    const { value } = await Preferences.get({ key: KEY });
    if (!value) return { ...DEFAULT_PREFS };
    return { ...DEFAULT_PREFS, ...(JSON.parse(value) as Partial<AppPrefs>) };
  } catch {
    return { ...DEFAULT_PREFS };
  }
}

export async function savePrefs(p: AppPrefs): Promise<void> {
  await Preferences.set({ key: KEY, value: JSON.stringify(p) });
}

export async function clearPrefs(): Promise<void> {
  await Preferences.remove({ key: KEY });
}
