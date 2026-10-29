import { html } from '../lib/html';
import type { Session } from '../store/session';
import { icon } from '../ui/icons';

/** Biometrische Sperre: Refresh-Token wird erst nach erfolgreicher Prüfung freigegeben. */
export function renderLock(root: HTMLElement, session: Session): void {
  root.innerHTML = html`<main class="auth" id="main" tabindex="-1">
    <div class="auth-card center">
      <div class="lock-icon">${icon('lock', 'ic ic-xl')}</div>
      <h1>Vireo ist gesperrt</h1>
      <p class="muted" id="lock-msg" role="status"></p>
      <button type="button" class="btn btn-primary btn-block" data-action="unlock">Entsperren</button>
      <button type="button" class="btn btn-ghost btn-block" data-action="logout">Abmelden</button>
    </div>
  </main>`.value;
  const msg = root.querySelector('#lock-msg')!;
  const unlock = async () => {
    msg.textContent = '';
    const ok = await session.unlock();
    if (!ok && session.status === 'locked') msg.textContent = 'Entsperren abgebrochen. Bitte erneut versuchen.';
  };
  root.querySelector('[data-action="unlock"]')!.addEventListener('click', () => void unlock());
  root.querySelector('[data-action="logout"]')!.addEventListener('click', () => void session.logout());
  void unlock();
}

export function renderUnavailable(root: HTMLElement, session: Session): void {
  root.innerHTML = html`<main class="auth" id="main" tabindex="-1">
    <div class="auth-card center">
      <div class="lock-icon">${icon('offline', 'ic ic-xl')}</div>
      <h1>Nicht erreichbar</h1>
      <p class="muted" role="alert">${session.unavailableMessage}</p>
      <button type="button" class="btn btn-primary btn-block" data-action="retry">Erneut versuchen</button>
      <button type="button" class="btn btn-ghost btn-block" data-action="logout">Abmelden</button>
    </div>
  </main>`.value;
  root.querySelector('[data-action="retry"]')!.addEventListener('click', () => void session.retry());
  root.querySelector('[data-action="logout"]')!.addEventListener('click', () => void session.logout());
}
