import type { SafeHtml } from '../lib/html';
import type { Loaded } from '../store/data';
import type { Session } from '../store/session';

export interface ScreenCtx {
  session: Session;
  /** Inhaltsbereich des Bildschirms */
  root: HTMLElement;
  /** Pfadsegmente nach dem Bildschirmnamen, z. B. ['2026-11'] */
  args: string[];
  /** Titel der Kopfzeile; `back` = Hash-Ziel für den Zurück-Button */
  setTitle(title: string, back?: string): void;
  /** Offline-/Cache-Hinweis passend zu den geladenen Daten setzen */
  setDataState(loaded: Loaded<unknown> | null): void;
  /** Handler für Pull-to-Refresh */
  onRefresh(fn: () => Promise<void>): void;
  navigate(hash: string): void;
  /** false, sobald der Nutzer weiternavigiert ist (verhindert späte Renderings) */
  isCurrent(): boolean;
  render(content: SafeHtml): void;
  /** Ungelesen-Zähler im Tab aktualisieren */
  setUnread(n: number): void;
}

export type Screen = (ctx: ScreenCtx) => Promise<void> | void;
