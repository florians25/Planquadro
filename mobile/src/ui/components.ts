import { ApiError, toApiError } from '../api/errors';
import { safeForeground } from '../lib/color';
import { formatDateTime } from '../lib/dates';
import { cssColor, html, type SafeHtml } from '../lib/html';
import { haptic } from '../platform/native';
import { icon } from './icons';

/** Farbiger Code-Badge (Farbe/Schrift aus der API, Schrift bei zu wenig Kontrast korrigiert). */
export function codeBadge(code: string, color: string, fg: string | null | undefined, extraClass = ''): SafeHtml {
  const bg = cssColor(color);
  const text = safeForeground(bg, fg);
  return html`<span class="badge ${extraClass}" style="background:${bg};color:${text}">${code}</span>`;
}

export function loading(label = 'Wird geladen …'): SafeHtml {
  return html`<div class="state" role="status" aria-live="polite"><span class="spinner" aria-hidden="true"></span><span>${label}</span></div>`;
}

export function errorState(e: unknown, retryLabel = 'Erneut versuchen'): SafeHtml {
  const err = toApiError(e);
  return html`<div class="state state-error" role="alert">
    <p>${err.userMessage}</p>
    <button type="button" class="btn" data-action="retry">${retryLabel}</button>
  </div>`;
}

export function emptyState(text: string): SafeHtml {
  return html`<div class="state"><p>${text}</p></div>`;
}

export function offlineBanner(savedAt: number, reason = 'Offline'): SafeHtml {
  return html`<div class="banner banner-offline" role="status">${icon('offline')}<span><strong>${reason}</strong> – Stand: ${formatDateTime(savedAt)}</span></div>`;
}

export function formError(message: string): SafeHtml {
  return html`<div class="alert alert-error" role="alert">${message}</div>`;
}

export function messageOf(e: unknown): string {
  return e instanceof ApiError ? e.userMessage : toApiError(e).userMessage;
}

// ------------------------------------------------------------------ Toast

let toastTimer: ReturnType<typeof setTimeout> | null = null;

export function toast(message: string, kind: 'info' | 'ok' | 'error' = 'info'): void {
  let el = document.getElementById('toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'toast';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    document.body.appendChild(el);
  }
  el.className = `toast toast-${kind} show`;
  if (kind === 'ok') haptic('success');
  else if (kind === 'error') haptic('error');
  el.textContent = message;
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el?.classList.remove('show'), 3500);
}

// ------------------------------------------------------------------ Bestätigungsdialog

export function confirmDialog(title: string, text: string, confirmLabel: string, danger = false): Promise<boolean> {
  return new Promise((resolve) => {
    const dlg = document.createElement('dialog');
    dlg.className = 'dialog';
    dlg.setAttribute('aria-labelledby', 'dlg-title');
    dlg.innerHTML = html`<form method="dialog">
      <h2 id="dlg-title">${title}</h2>
      <p>${text}</p>
      <div class="dialog-actions">
        <button type="submit" value="cancel" class="btn btn-ghost">Abbrechen</button>
        <button type="submit" value="ok" class="btn ${danger ? 'btn-danger' : 'btn-primary'}">${confirmLabel}</button>
      </div>
    </form>`.value;
    document.body.appendChild(dlg);
    dlg.addEventListener('close', () => {
      resolve(dlg.returnValue === 'ok');
      dlg.remove();
    });
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else resolve(window.confirm(`${title}\n\n${text}`));
  });
}
