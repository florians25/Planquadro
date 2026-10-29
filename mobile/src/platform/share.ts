/**
 * Teilen eines Einsatztages über das System-Teilen-Menü (@capacitor/share;
 * im Browser Web Share API, sonst Zwischenablage).
 *
 * Geteilt wird nur, was die Person bewusst weitergeben möchte und was nicht vertraulich ist:
 * Datum, Bereich (Kürzel/Name) und Einsatzort. NICHT geteilt werden Bemerkungen,
 * Kundenkontakte (Ansprechpartner, Telefon, E-Mail, Hinweise), Kolleginnen/Kollegen
 * und Abwesenheitsarten.
 */
import { Share } from '@capacitor/share';
import type { AssignmentDetailsResponse } from '../api/types';
import { formatDateLong } from '../lib/dates';

export interface ShareContent {
  title: string;
  text: string;
}

/** Reine Funktion: Text zum Teilen eines Tages. */
export function shareContentFor(d: AssignmentDetailsResponse): ShareContent {
  const date = formatDateLong(d.date);
  const e = d.entry;
  const lines: string[] = [];
  let title: string;
  if (!e) {
    title = `${date}: kein Einsatz`;
    lines.push(title);
  } else if (e.kind === 'absence') {
    title = `${date}: abwesend`;
    lines.push(title);
  } else {
    const area = d.area?.name ?? e.label;
    title = `${date}: ${e.code} – ${area}`;
    lines.push(title);
    const where = d.location?.address ?? d.location?.name ?? d.customer?.address ?? null;
    if (where) lines.push(`Ort: ${where}`);
  }
  lines.push('– geteilt aus Planquadro');
  return { title, text: lines.join('\n') };
}

export type ShareOutcome = 'shared' | 'copied' | 'cancelled' | 'unavailable';

export async function shareContent(c: ShareContent): Promise<ShareOutcome> {
  try {
    const can = await Share.canShare();
    if (can.value) {
      await Share.share({ title: c.title, text: c.text, dialogTitle: 'Einsatz teilen' });
      return 'shared';
    }
  } catch (e) {
    // Abbruch durch den Nutzer ist kein Fehler
    if (/cancel/i.test(String((e as Error)?.message ?? e))) return 'cancelled';
  }
  try {
    await navigator.clipboard.writeText(c.text);
    return 'copied';
  } catch {
    return 'unavailable';
  }
}
