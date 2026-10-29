/**
 * Eigene Einsätze in den Gerätekalender übernehmen (@ebarooni/capacitor-calendar).
 *
 *  - Ein Tag (Einsatzdetails): öffnet den nativen Termin-Dialog vorausgefüllt
 *    (createEventWithPrompt) – keine Kalenderberechtigung nötig, der Nutzer speichert selbst.
 *  - Nächste 14 Tage (Mehr → Kalender): legt ganztägige Termine an. Dafür wird einmalig die
 *    Kalenderberechtigung angefragt. Vorher von der App angelegte Termine werden zuerst
 *    entfernt (IDs in den Einstellungen), damit keine Doppelungen entstehen.
 *
 * Es werden nur eigene Einsätze übertragen, keine Abwesenheiten, keine Bemerkungen.
 * Ein iCal-Abo-Link ist in der API v1 nicht vorgesehen (siehe mobile/README.md).
 */
import { CapacitorCalendar } from '@ebarooni/capacitor-calendar';
import type { AssignmentDetailsResponse, PlanDay } from '../api/types';
import { parseIsoDate } from '../lib/dates';

export const CALENDAR_EXPORT_DAYS = 14;

export interface CalendarEventSpec {
  date: string;
  title: string;
  location: string | null;
  description: string;
  /** ms seit Epoch, Tagesbeginn lokal */
  startDate: number;
  /** ms seit Epoch, Beginn des Folgetags lokal (ganztägig) */
  endDate: number;
}

function dayBounds(date: string): { start: number; end: number } {
  const s = parseIsoDate(date);
  const start = new Date(s.getUTCFullYear(), s.getUTCMonth(), s.getUTCDate()).getTime();
  const end = new Date(s.getUTCFullYear(), s.getUTCMonth(), s.getUTCDate() + 1).getTime();
  return { start, end };
}

/** Reine Funktion: Termine für die eigenen Einsatztage (ohne Abwesenheiten). */
export function calendarEventsFor(days: PlanDay[], location: (date: string) => string | null = () => null): CalendarEventSpec[] {
  return days
    .filter((d) => d.entry?.kind === 'assignment')
    .map((d) => {
      const e = d.entry!;
      const { start, end } = dayBounds(d.date);
      return {
        date: d.date,
        title: `${e.code} – ${e.label}`,
        location: location(d.date),
        description: 'Einsatz aus Planquadro',
        startDate: start,
        endDate: end,
      };
    });
}

export function calendarEventForDetails(d: AssignmentDetailsResponse): CalendarEventSpec | null {
  if (d.entry?.kind !== 'assignment') return null;
  const { start, end } = dayBounds(d.date);
  return {
    date: d.date,
    title: `${d.entry.code} – ${d.area?.name ?? d.entry.label}`,
    location: d.location?.address ?? d.location?.name ?? d.customer?.address ?? null,
    description: 'Einsatz aus Planquadro',
    startDate: start,
    endDate: end,
  };
}

/** Termin-Dialog des Systems öffnen; true = gespeichert. */
export async function addEventWithPrompt(ev: CalendarEventSpec): Promise<boolean> {
  const r = await CapacitorCalendar.createEventWithPrompt({
    title: ev.title,
    startDate: ev.startDate,
    endDate: ev.endDate,
    isAllDay: true,
    description: ev.description,
    ...(ev.location ? { location: ev.location } : {}),
  });
  return !!r.id;
}

/**
 * Termine ohne Dialog anlegen. `previous` = von der App früher angelegte Termin-IDs je Datum,
 * die vorher gelöscht werden. Ergebnis: neue IDs je Datum.
 */
export async function syncEventsToCalendar(
  events: CalendarEventSpec[],
  previous: Record<string, string>,
): Promise<{ ids: Record<string, string>; created: number }> {
  const perm = await CapacitorCalendar.requestFullCalendarAccess();
  if (perm.result !== 'granted') throw new Error('calendar_denied');
  const dates = new Set(events.map((e) => e.date));
  const stale = Object.entries(previous).filter(([d]) => dates.has(d)).map(([, id]) => id);
  if (stale.length) await CapacitorCalendar.deleteEventsById({ ids: stale }).catch(() => undefined);
  const ids: Record<string, string> = Object.fromEntries(Object.entries(previous).filter(([d]) => !dates.has(d)));
  let created = 0;
  for (const ev of events) {
    const r = await CapacitorCalendar.createEvent({
      title: ev.title,
      startDate: ev.startDate,
      endDate: ev.endDate,
      isAllDay: true,
      description: ev.description,
      ...(ev.location ? { location: ev.location } : {}),
    });
    if (r.id) {
      ids[ev.date] = r.id;
      created++;
    }
  }
  return { ids, created };
}
