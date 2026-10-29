/**
 * Lokale Erinnerung am Vorabend (@capacitor/local-notifications) – Rückfall, wenn kein
 * Push konfiguriert ist (oder zusätzlich). Standard: aus; einschalten unter Mehr → Erinnerungen.
 *
 * Inhalt wie beim Widget nur nicht vertraulich: Bereichskürzel und -name des eigenen
 * Einsatzes. Für Abwesenheiten und freie Tage wird nichts geplant.
 * Nach jedem Sync werden die Erinnerungen der nächsten Tage neu geplant; beim Abmelden
 * werden alle gelöscht.
 */
import { LocalNotifications } from '@capacitor/local-notifications';
import type { PlanDay } from '../api/types';
import { addDays, parseIsoDate } from '../lib/dates';

export const REMINDER_HOUR = 18;
export const REMINDER_DAYS = 7;

export interface ReminderSpec {
  /** 32-Bit-ID, aus dem Datum abgeleitet (YYYYMMDD) – stabil für Neuplanung */
  id: number;
  at: Date;
  title: string;
  body: string;
  date: string;
}

export function reminderId(date: string): number {
  return Number(date.replace(/-/g, ''));
}

/** Reine Funktion: Erinnerungen für Einsätze ab morgen, jeweils am Vortag um 18 Uhr (nur zukünftige). */
export function planReminders(days: PlanDay[], today: string, now: Date, hour = REMINDER_HOUR): ReminderSpec[] {
  const out: ReminderSpec[] = [];
  const last = addDays(today, REMINDER_DAYS);
  for (const d of days) {
    if (d.date <= today || d.date > last) continue;
    const e = d.entry;
    if (!e || e.kind !== 'assignment') continue;
    const prev = parseIsoDate(addDays(d.date, -1));
    const at = new Date(prev.getUTCFullYear(), prev.getUTCMonth(), prev.getUTCDate(), hour, 0, 0);
    if (at.getTime() <= now.getTime()) continue;
    out.push({ id: reminderId(d.date), at, date: d.date, title: `Morgen: ${e.code} – ${e.label}`, body: 'Tippen, um die Einsatzdetails zu öffnen.' });
  }
  return out.sort((a, b) => a.at.getTime() - b.at.getTime());
}

export async function remindersPermitted(request: boolean): Promise<boolean> {
  try {
    let p = await LocalNotifications.checkPermissions();
    if (request && (p.display === 'prompt' || p.display === 'prompt-with-rationale')) p = await LocalNotifications.requestPermissions();
    return p.display === 'granted';
  } catch {
    return false;
  }
}

export async function cancelAllReminders(): Promise<void> {
  try {
    const pending = await LocalNotifications.getPending();
    if (pending.notifications.length) await LocalNotifications.cancel({ notifications: pending.notifications.map((n) => ({ id: n.id })) });
  } catch {
    /* Web/keine Berechtigung */
  }
}

export async function scheduleReminders(specs: ReminderSpec[]): Promise<number> {
  await cancelAllReminders();
  if (!specs.length) return 0;
  await LocalNotifications.schedule({
    notifications: specs.map((s) => ({
      id: s.id,
      title: s.title,
      body: s.body,
      schedule: { at: s.at, allowWhileIdle: false },
      isExactNotification: false,
      extra: { url: `planquadro://tag/${s.date}` },
    })),
  });
  return specs.length;
}

/** Tippen auf eine Erinnerung → Deep Link an die App weiterreichen. */
export function onReminderTap(cb: (url: string) => void): void {
  void LocalNotifications.addListener('localNotificationActionPerformed', (a) => {
    const url = (a.notification.extra as { url?: unknown } | undefined)?.url;
    if (typeof url === 'string') cb(url);
  }).catch(() => undefined);
}
