/**
 * Datumshilfen. Alle Kalenderdaten werden als `Y-m-d`-Strings behandelt und intern
 * mit UTC-Mitternacht gerechnet, damit Sommer-/Winterzeit keine Tage verschiebt.
 */

export type YearMonth = { year: number; month: number }; // month 1–12

const WEEKDAYS_SHORT = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
const WEEKDAYS_LONG = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

function pad(n: number): string {
  return n < 10 ? `0${n}` : String(n);
}

export function isIsoDate(s: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
  const d = parseIsoDate(s);
  return toIsoDate(d) === s;
}

/** `Y-m-d` → Date (UTC-Mitternacht) */
export function parseIsoDate(s: string): Date {
  const [y, m, d] = s.split('-').map(Number) as [number, number, number];
  return new Date(Date.UTC(y, m - 1, d));
}

/** Date (UTC) → `Y-m-d` */
export function toIsoDate(d: Date): string {
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
}

/** Heutiges Datum in lokaler Gerätezeit als `Y-m-d`. */
export function todayIso(now: Date = new Date()): string {
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export function addDays(iso: string, days: number): string {
  const d = parseIsoDate(iso);
  d.setUTCDate(d.getUTCDate() + days);
  return toIsoDate(d);
}

/** ISO-Wochentag: 1 = Montag … 7 = Sonntag (wie `weekday` der API). */
export function isoWeekday(iso: string): number {
  const wd = parseIsoDate(iso).getUTCDay();
  return wd === 0 ? 7 : wd;
}

/** ISO-8601-Kalenderwoche (KW) inkl. wochenbasiertem Jahr. */
export function isoWeek(iso: string): { year: number; week: number } {
  const d = parseIsoDate(iso);
  // Donnerstag derselben Woche bestimmt das Jahr
  d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
  const year = d.getUTCFullYear();
  const yearStart = Date.UTC(year, 0, 1);
  const week = Math.ceil(((d.getTime() - yearStart) / 86_400_000 + 1) / 7);
  return { year, week };
}

/** Montag der Woche, in der `iso` liegt. */
export function startOfIsoWeek(iso: string): string {
  return addDays(iso, 1 - isoWeekday(iso));
}

export function daysInMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

export function monthRange({ year, month }: YearMonth): { from: string; to: string } {
  return { from: `${year}-${pad(month)}-01`, to: `${year}-${pad(month)}-${pad(daysInMonth(year, month))}` };
}

export function addMonths({ year, month }: YearMonth, delta: number): YearMonth {
  const idx = year * 12 + (month - 1) + delta;
  return { year: Math.floor(idx / 12), month: (idx % 12 + 12) % 12 + 1 };
}

export function yearMonthOf(iso: string): YearMonth {
  return { year: Number(iso.slice(0, 4)), month: Number(iso.slice(5, 7)) };
}

/** `2026-11` */
export function monthKey({ year, month }: YearMonth): string {
  return `${year}-${pad(month)}`;
}

export function parseMonthKey(key: string): YearMonth | null {
  const m = /^(\d{4})-(\d{2})$/.exec(key);
  if (!m) return null;
  const month = Number(m[2]);
  if (month < 1 || month > 12) return null;
  return { year: Number(m[1]), month };
}

export function monthLabel({ year, month }: YearMonth): string {
  return `${MONTHS[month - 1]} ${year}`;
}

export function weekdayShort(iso: string): string {
  return WEEKDAYS_SHORT[isoWeekday(iso) - 1]!;
}

export function weekdayLong(iso: string): string {
  return WEEKDAYS_LONG[isoWeekday(iso) - 1]!;
}

/** `02.11.` */
export function formatDayMonth(iso: string): string {
  return `${iso.slice(8, 10)}.${iso.slice(5, 7)}.`;
}

/** `02.11.2026` */
export function formatDate(iso: string): string {
  return `${iso.slice(8, 10)}.${iso.slice(5, 7)}.${iso.slice(0, 4)}`;
}

/** `Montag, 02.11.2026` */
export function formatDateLong(iso: string): string {
  return `${weekdayLong(iso)}, ${formatDate(iso)}`;
}

/** Zeitstempel (ISO 8601 oder ms) → `29.10.2026, 18:00` in Gerätezeit */
export function formatDateTime(value: string | number | Date): string {
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}, ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export interface WeekGroup<T> {
  /** z. B. „KW 45“ */
  label: string;
  year: number;
  week: number;
  /** Montag der Woche */
  start: string;
  items: T[];
}

/** Gruppiert sortierte Tage nach ISO-Kalenderwoche. */
export function groupByIsoWeek<T extends { date: string }>(days: T[]): WeekGroup<T>[] {
  const sorted = [...days].sort((a, b) => a.date.localeCompare(b.date));
  const groups: WeekGroup<T>[] = [];
  let current: WeekGroup<T> | null = null;
  for (const day of sorted) {
    const { year, week } = isoWeek(day.date);
    if (!current || current.year !== year || current.week !== week) {
      current = { label: `KW ${week}`, year, week, start: startOfIsoWeek(day.date), items: [] };
      groups.push(current);
    }
    current.items.push(day);
  }
  return groups;
}

/** Alle Tage von `from` bis `to` (inklusive). */
export function eachDay(from: string, to: string): string[] {
  const out: string[] = [];
  for (let d = from; d <= to; d = addDays(d, 1)) out.push(d);
  return out;
}

/** Monate (`YYYY-MM`), in denen die angegebenen Tage liegen – sortiert, eindeutig. */
export function monthsOfDates(dates: string[]): string[] {
  return [...new Set(dates.filter(isIsoDate).map((d) => d.slice(0, 7)))].sort();
}
