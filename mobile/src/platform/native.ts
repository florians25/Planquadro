/**
 * Kapselt alle Capacitor-Plugins. Im Browser (PWA) werden native Funktionen
 * (Push, Biometrie, System-Browser) sauber übersprungen.
 */
import { App } from '@capacitor/app';
import { Browser } from '@capacitor/browser';
import { Capacitor } from '@capacitor/core';
import { Network } from '@capacitor/network';
import { PushNotifications, type PushNotificationSchema } from '@capacitor/push-notifications';
import { SplashScreen } from '@capacitor/splash-screen';
import { StatusBar, Style } from '@capacitor/status-bar';
import { Haptics, ImpactStyle, NotificationType } from '@capacitor/haptics';
import { Keyboard } from '@capacitor/keyboard';
import { BiometricAuth, BiometryType } from '@aparajita/capacitor-biometric-auth';
import type { DevicePlatform, PushProvider } from '../api/types';

export const isNative = Capacitor.isNativePlatform();
export const platform = Capacitor.getPlatform() as DevicePlatform;

export function deviceName(): string {
  if (platform === 'ios') return 'Planquadro iOS-App';
  if (platform === 'android') return 'Planquadro Android-App';
  const ua = navigator.userAgent;
  const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
  return `Planquadro Web (${browser})`;
}

// ------------------------------------------------------------------ Netzwerk

export async function isOnline(): Promise<boolean> {
  try {
    return (await Network.getStatus()).connected;
  } catch {
    return typeof navigator === 'undefined' ? true : navigator.onLine;
  }
}

export function onNetworkChange(cb: (online: boolean) => void): void {
  void Network.addListener('networkStatusChange', (s) => cb(s.connected)).catch(() => {
    window.addEventListener('online', () => cb(true));
    window.addEventListener('offline', () => cb(false));
  });
}

// ------------------------------------------------------------------ Biometrie

export interface BiometryInfo {
  available: boolean;
  label: string;
  reason: string;
}

export async function biometryInfo(): Promise<BiometryInfo> {
  if (!isNative) return { available: false, label: 'Biometrie', reason: 'Im Browser nicht verfügbar.' };
  try {
    const r = await BiometricAuth.checkBiometry();
    const label =
      r.biometryType === BiometryType.faceId ? 'Face ID'
      : r.biometryType === BiometryType.touchId ? 'Touch ID'
      : r.biometryType === BiometryType.fingerprintAuthentication ? 'Fingerabdruck'
      : r.biometryType === BiometryType.faceAuthentication ? 'Gesichtserkennung'
      : 'Biometrie';
    return { available: r.isAvailable, label, reason: r.isAvailable ? '' : r.reason || 'Keine Biometrie eingerichtet.' };
  } catch {
    return { available: false, label: 'Biometrie', reason: 'Biometrie konnte nicht geprüft werden.' };
  }
}

/** true = erfolgreich; false = abgebrochen/fehlgeschlagen */
export async function authenticateBiometric(reason: string): Promise<boolean> {
  if (!isNative) return false;
  try {
    await BiometricAuth.authenticate({
      reason,
      cancelTitle: 'Abbrechen',
      iosFallbackTitle: 'Code eingeben',
      allowDeviceCredential: true,
      androidTitle: 'Planquadro entsperren',
      androidSubtitle: reason,
    });
    return true;
  } catch {
    return false;
  }
}

// ------------------------------------------------------------------ App-Lebenszyklus

export function onAppStateChange(cb: (active: boolean) => void): void {
  if (isNative) {
    void App.addListener('appStateChange', (s) => cb(s.isActive));
  } else {
    document.addEventListener('visibilitychange', () => cb(document.visibilityState === 'visible'));
  }
}

export function onAppUrlOpen(cb: (url: string) => void): void {
  if (isNative) void App.addListener('appUrlOpen', (e) => cb(e.url));
}

export function onBackButton(cb: () => void): void {
  if (platform === 'android') void App.addListener('backButton', () => cb());
}

/** URL, mit der die App gestartet wurde (Kaltstart über Deep Link/Schnellaktion/Widget). */
export async function launchUrl(): Promise<string | null> {
  if (!isNative) return null;
  try {
    return (await App.getLaunchUrl())?.url ?? null;
  } catch {
    return null;
  }
}

// ------------------------------------------------------------------ Haptik

export type HapticKind = 'selection' | 'light' | 'success' | 'error' | 'warning';

/** Haptisches Feedback (nur nativ; respektiert die Systemeinstellung „Vibration“). */
export function haptic(kind: HapticKind): void {
  if (!isNative) return;
  const run = async () => {
    if (kind === 'selection') await Haptics.selectionChanged();
    else if (kind === 'light') await Haptics.impact({ style: ImpactStyle.Light });
    else
      await Haptics.notification({
        type: kind === 'success' ? NotificationType.Success : kind === 'error' ? NotificationType.Error : NotificationType.Warning,
      });
  };
  void run().catch(() => undefined);
}

// ------------------------------------------------------------------ Tastatur

let keyboardOpen = false;

export function isKeyboardOpen(): boolean {
  return keyboardOpen;
}

export async function hideKeyboard(): Promise<void> {
  if (isNative) await Keyboard.hide().catch(() => undefined);
  (document.activeElement as HTMLElement | null)?.blur?.();
}

/**
 * Tastatur: Klasse `kb-open` am <body> (blendet die Tab-Leiste aus) und fokussiertes Feld
 * in den sichtbaren Bereich scrollen.
 */
export function initKeyboard(): void {
  if (!isNative) return;
  const show = () => {
    keyboardOpen = true;
    document.body.classList.add('kb-open');
    const el = document.activeElement as HTMLElement | null;
    if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
  };
  const hide = () => {
    keyboardOpen = false;
    document.body.classList.remove('kb-open');
  };
  void Keyboard.addListener('keyboardDidShow', show).catch(() => undefined);
  void Keyboard.addListener('keyboardWillHide', hide).catch(() => undefined);
  if (platform === 'ios') void Keyboard.setAccessoryBarVisible({ isVisible: true }).catch(() => undefined);
}

export async function minimizeApp(): Promise<void> {
  if (platform === 'android') await App.minimizeApp().catch(() => undefined);
}

export async function appVersion(): Promise<string> {
  if (isNative) {
    try {
      const info = await App.getInfo();
      return `${info.version} (${info.build})`;
    } catch {
      /* Fallback */
    }
  }
  return __APP_VERSION__;
}

// ------------------------------------------------------------------ System-Browser (OIDC)

export async function openSystemBrowser(url: string): Promise<void> {
  if (isNative) await Browser.open({ url, presentationStyle: 'popover' });
  else window.location.assign(url);
}

export async function closeSystemBrowser(): Promise<void> {
  if (isNative) await Browser.close().catch(() => undefined);
}

// ------------------------------------------------------------------ Darstellung

export async function applyNativeTheme(dark: boolean): Promise<void> {
  if (!isNative) return;
  try {
    // Kopfzeile ist in beiden Themes dunkel (Tinte) → helle Statusleisten-Schrift
    await StatusBar.setStyle({ style: Style.Dark });
    if (platform === 'android') await StatusBar.setBackgroundColor({ color: dark ? '#070d18' : '#14213d' });
  } catch {
    /* nicht kritisch */
  }
}

export async function hideSplash(): Promise<void> {
  if (isNative) await SplashScreen.hide().catch(() => undefined);
}

// ------------------------------------------------------------------ Push

export interface PushHandlers {
  onToken(reg: { platform: DevicePlatform; provider: PushProvider; token: string }): void;
  onPlanChanged(dates: string[]): void;
  onOpen(data: Record<string, unknown>): void;
}

let pushInitialized = false;

/**
 * Registriert Push (FCM auf Android, APNs auf iOS). Im Browser wird Push bewusst
 * übersprungen (siehe README).
 */
export async function initPush(h: PushHandlers): Promise<'unsupported' | 'denied' | 'registered'> {
  if (!isNative) return 'unsupported';
  let perm = await PushNotifications.checkPermissions();
  if (perm.receive === 'prompt' || perm.receive === 'prompt-with-rationale') perm = await PushNotifications.requestPermissions();
  if (perm.receive !== 'granted') return 'denied';
  if (!pushInitialized) {
    pushInitialized = true;
    const provider: PushProvider = platform === 'ios' ? 'apns' : 'fcm';
    await PushNotifications.addListener('registration', (t) => h.onToken({ platform, provider, token: t.value }));
    await PushNotifications.addListener('registrationError', () => undefined);
    const handleData = (n: PushNotificationSchema) => {
      const data = (n.data ?? {}) as Record<string, unknown>;
      if (data['type'] === 'plan_changed') {
        h.onPlanChanged(String(data['dates'] ?? '').split(',').map((s) => s.trim()).filter(Boolean));
      }
    };
    await PushNotifications.addListener('pushNotificationReceived', handleData);
    await PushNotifications.addListener('pushNotificationActionPerformed', (a) => {
      handleData(a.notification);
      h.onOpen((a.notification.data ?? {}) as Record<string, unknown>);
    });
  }
  await PushNotifications.register();
  return 'registered';
}

export async function unregisterPush(): Promise<void> {
  if (!isNative) return;
  try {
    await PushNotifications.removeAllListeners();
    await PushNotifications.unregister();
  } catch {
    /* ignorieren */
  }
  pushInitialized = false;
}
