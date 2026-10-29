import { describe, expect, it } from 'vitest';
import {
  addDays,
  addMonths,
  daysInMonth,
  eachDay,
  formatDate,
  formatDateLong,
  groupByIsoWeek,
  isIsoDate,
  isoWeek,
  isoWeekday,
  monthKey,
  monthLabel,
  monthRange,
  monthsOfDates,
  parseMonthKey,
  startOfIsoWeek,
  todayIso,
} from '../src/lib/dates';

describe('ISO-Kalenderwoche (KW)', () => {
  it.each([
    ['2026-01-01', 2026, 1], // Donnerstag
    ['2026-11-02', 2026, 45],
    ['2026-12-28', 2026, 53], // 2026 hat 53 Wochen
    ['2027-01-01', 2026, 53], // Freitag gehört noch zur KW 53/2026
    ['2027-01-04', 2027, 1],
    ['2024-12-30', 2025, 1], // Montag zählt zu KW 1/2025
    ['2021-01-03', 2020, 53],
    ['2024-02-29', 2024, 9],
  ])('%s → KW %i/%i', (date, year, week) => {
    expect(isoWeek(date)).toEqual({ year, week });
  });

  it('Wochentag 1 = Montag … 7 = Sonntag', () => {
    expect(isoWeekday('2026-11-02')).toBe(1);
    expect(isoWeekday('2026-11-01')).toBe(7);
    expect(isoWeekday('2026-11-07')).toBe(6);
  });

  it('Wochenbeginn', () => {
    expect(startOfIsoWeek('2026-11-01')).toBe('2026-10-26');
    expect(startOfIsoWeek('2026-11-02')).toBe('2026-11-02');
  });

  it('gruppiert einen Monat nach KW', () => {
    const days = eachDay('2026-11-01', '2026-11-30').map((date) => ({ date }));
    const groups = groupByIsoWeek(days);
    expect(groups.map((g) => g.label)).toEqual(['KW 44', 'KW 45', 'KW 46', 'KW 47', 'KW 48', 'KW 49']);
    expect(groups[0]!.items).toHaveLength(1); // nur So 01.11.
    expect(groups[5]!.items.map((d) => d.date)).toEqual(['2026-11-30']);
    expect(groups.reduce((n, g) => n + g.items.length, 0)).toBe(30);
  });

  it('gruppiert über den Jahreswechsel korrekt', () => {
    const groups = groupByIsoWeek(eachDay('2026-12-28', '2027-01-10').map((date) => ({ date })));
    expect(groups.map((g) => `${g.year}/${g.week}`)).toEqual(['2026/53', '2027/1']);
  });

  it('sortiert unsortierte Eingaben', () => {
    const groups = groupByIsoWeek([{ date: '2026-11-10' }, { date: '2026-11-02' }]);
    expect(groups.map((g) => g.items[0]!.date)).toEqual(['2026-11-02', '2026-11-10']);
  });
});

describe('Monats- und Datumshilfen', () => {
  it('Monatsbereiche inkl. Schaltjahr', () => {
    expect(monthRange({ year: 2026, month: 11 })).toEqual({ from: '2026-11-01', to: '2026-11-30' });
    expect(monthRange({ year: 2028, month: 2 })).toEqual({ from: '2028-02-01', to: '2028-02-29' });
    expect(daysInMonth(2026, 2)).toBe(28);
  });

  it('Monatsnavigation über Jahresgrenzen', () => {
    expect(addMonths({ year: 2026, month: 12 }, 1)).toEqual({ year: 2027, month: 1 });
    expect(addMonths({ year: 2026, month: 1 }, -1)).toEqual({ year: 2025, month: 12 });
    expect(addMonths({ year: 2026, month: 5 }, -17)).toEqual({ year: 2024, month: 12 });
  });

  it('Monatsschlüssel', () => {
    expect(monthKey({ year: 2026, month: 3 })).toBe('2026-03');
    expect(parseMonthKey('2026-03')).toEqual({ year: 2026, month: 3 });
    expect(parseMonthKey('2026-13')).toBeNull();
    expect(parseMonthKey('x')).toBeNull();
    expect(monthLabel({ year: 2026, month: 3 })).toBe('März 2026');
  });

  it('addDays über Sommerzeitumstellung', () => {
    expect(addDays('2026-03-28', 2)).toBe('2026-03-30');
    expect(addDays('2026-10-24', 2)).toBe('2026-10-26');
    expect(addDays('2026-03-01', -1)).toBe('2026-02-28');
  });

  it('Validierung und Formatierung', () => {
    expect(isIsoDate('2026-02-29')).toBe(false);
    expect(isIsoDate('2028-02-29')).toBe(true);
    expect(isIsoDate('2026-1-1')).toBe(false);
    expect(formatDate('2026-11-02')).toBe('02.11.2026');
    expect(formatDateLong('2026-11-02')).toBe('Montag, 02.11.2026');
    expect(todayIso(new Date(2026, 9, 29, 23, 30))).toBe('2026-10-29');
  });

  it('Monate geänderter Tage', () => {
    expect(monthsOfDates(['2026-11-02', '2026-11-03', '2026-12-01', 'kaputt'])).toEqual(['2026-11', '2026-12']);
  });
});
