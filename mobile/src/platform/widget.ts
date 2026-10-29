/**
 * Home-Screen-Widget „Heute / Diese Woche“.
 *
 * Die App schreibt nach jedem Sync eine kleine, NICHT vertrauliche Zusammenfassung der
 * eigenen nächsten Tage in den gemeinsamen Speicher des Widgets (Android:
 * SharedPreferences `planquadro_widget`, siehe android/app/src/main/java/de/planquadro/app/widget/).
 *
 * Bewusst NICHT enthalten (Widget ist ohne Entsperren auf dem Startbildschirm sichtbar):
 *  - Abwesenheitsarten/-gründe (auch eigene: „Krank“, „Urlaub“ …) → nur „Abwesend“
 *  - Bemerkungen, Kunden, Kontakte, Adressen, Kolleginnen/Kollegen
 *  - Daten anderer Personen (es werden ausschließlich Einträge aus `plan/my` verwendet)
 */
import { registerPlugin } from '@capacitor/core';
import type { PlanDay } from '../api/types';
import { parseHex, safeForeground, toHex } from '../lib/color';
import { addDays, formatDayMonth, weekdayShort } from '../lib/dates';

export const WIDGET_FORMAT_VERSION = 1;
export const WIDGET_DAYS = 7;

export interface WidgetDay {
  date: string;
  /** „Mo“ */
  wd: string;
  /** „02.11.“ */
  dm: string;
  kind: 'assignment' | 'absence' | 'free';
  /** Kürzel des Bereichs; bei Abwesenheit/frei leer */
  code: string;
  /** Bereichsname, „Abwesend“, „frei“ */
  label: string;
  /** #rrggbb */
  color: string;
  /** #rrggbb, kontrastgeprüft */
  fg: string;
  /** Name des Feiertags (öffentliche Information) */
  holiday: string | null;
}

export interface WidgetSummary {
  v: number;
  generated_at: string;
  today: string;
  /** Anzeigename des Mandanten (aus GET me, nicht vertraulich) */
  tenant: string;
  days: WidgetDay[];
}

const NEUTRAL = '#64748b';

function hex6(c: string | null | undefined, fallback: string): string {
  const rgb = parseHex(c);
  return rgb ? toHex(rgb) : fallback;
}

/** Reine Funktion: aus Tagen von `plan/my` die Widget-Zusammenfassung bauen. */
export function buildWidgetSummary(days: PlanDay[], today: string, tenant: string, now: Date = new Date()): WidgetSummary {
  const byDate = new Map(days.map((d) => [d.date, d]));
  const out: WidgetDay[] = [];
  for (let i = 0; i < WIDGET_DAYS; i++) {
    const date = addDays(today, i);
    const d = byDate.get(date);
    const e = d?.entry ?? null;
    const base = { date, wd: weekdayShort(date), dm: formatDayMonth(date), holiday: d?.holiday?.name ?? null };
    if (e && e.kind === 'assignment') {
      const color = hex6(e.color, NEUTRAL);
      out.push({ ...base, kind: 'assignment', code: String(e.code ?? '').slice(0, 6), label: String(e.label ?? '').slice(0, 60), color, fg: hex6(safeForeground(color, e.fg), '#ffffff') });
    } else if (e && e.kind === 'absence') {
      out.push({ ...base, kind: 'absence', code: '', label: 'Abwesend', color: NEUTRAL, fg: '#ffffff' });
    } else {
      out.push({ ...base, kind: 'free', code: '', label: 'frei', color: NEUTRAL, fg: '#ffffff' });
    }
  }
  return { v: WIDGET_FORMAT_VERSION, generated_at: now.toISOString(), today, tenant: tenant.slice(0, 60), days: out };
}

// ------------------------------------------------------------------ Native Brücke

interface PlanquadroWidgetPlugin {
  update(options: { data: string }): Promise<void>;
  clear(): Promise<void>;
}

/**
 * Eigenes App-Plugin (Android: WidgetBridgePlugin.java). Auf Plattformen ohne
 * Implementierung (Web, Capacitor-iOS) schlagen Aufrufe fehl und werden ignoriert.
 */
const WidgetBridge = registerPlugin<PlanquadroWidgetPlugin>('PlanquadroWidget');

export async function writeWidget(summary: WidgetSummary): Promise<boolean> {
  try {
    await WidgetBridge.update({ data: JSON.stringify(summary) });
    return true;
  } catch {
    return false;
  }
}

export async function clearWidget(): Promise<void> {
  try {
    await WidgetBridge.clear();
  } catch {
    /* nicht implementiert */
  }
}
