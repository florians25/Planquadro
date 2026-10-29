/**
 * Android-Zurück-Taste (reine Logik): Navigation innerhalb der App statt App schließen.
 *
 * Reihenfolge:
 *  1. offener Dialog → schließen
 *  2. Tastatur offen → Tastatur schließen
 *  3. Bildschirm mit Zurück-Ziel (z. B. Einsatzdetails) → dorthin
 *  4. anderer Tab oder anderer Monat/Jahr → „Mein Plan“ (Startbildschirm)
 *  5. Startbildschirm bzw. Anmelde-/Einrichtungsbildschirm → App in den Hintergrund
 *     (nicht beenden – Sitzung und Zustand bleiben erhalten)
 */
export interface BackState {
  /** App-Status ready (Hauptnavigation sichtbar)? */
  ready: boolean;
  dialogOpen: boolean;
  keyboardOpen: boolean;
  /** Ziel des sichtbaren Zurück-Buttons der Kopfzeile oder null */
  backHref: string | null;
  /** aktueller Hash, z. B. `#/team/2026-11` */
  hash: string;
}

export type BackAction =
  | { action: 'close-dialog' }
  | { action: 'hide-keyboard' }
  | { action: 'navigate'; hash: string }
  | { action: 'minimize' };

export const HOME_HASH = '#/plan';

export function decideBack(s: BackState): BackAction {
  if (s.dialogOpen) return { action: 'close-dialog' };
  if (s.keyboardOpen) return { action: 'hide-keyboard' };
  if (!s.ready) return { action: 'minimize' };
  if (s.backHref && s.backHref.startsWith('#/') && s.backHref !== s.hash) return { action: 'navigate', hash: s.backHref };
  const h = s.hash.replace(/\/+$/, '');
  if (h === '' || h === '#' || h === '#/' || h === HOME_HASH) return { action: 'minimize' };
  return { action: 'navigate', hash: HOME_HASH };
}
