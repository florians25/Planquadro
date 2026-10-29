import { ApiError, toApiError } from '../api/errors';
import { html, type SafeHtml } from '../lib/html';
import { displayServerUrl } from '../lib/url';
import type { Session } from '../store/session';
import { formError } from '../ui/components';
import { setBrand } from '../ui/theme';

/** Meldungen für den Login je Fehlercode (Vertrag: invalid_credentials, account_locked, password_change_required, maintenance). */
export function loginErrorMessage(e: unknown): string {
  const err = e instanceof ApiError ? e : toApiError(e);
  switch (err.code) {
    case 'invalid_credentials':
      return 'Benutzername oder Passwort ist falsch.';
    case 'account_locked':
      return 'Dein Konto ist gesperrt. Bitte wende dich an deine Planung oder Administration.';
    case 'password_change_required':
      return 'Du musst dein Passwort zuerst in der Planquadro-Weboberfläche ändern. Melde dich danach hier mit dem neuen Passwort an.';
    case 'maintenance':
      return `Planquadro wird gerade gewartet. ${err.serverMessage ?? 'Bitte versuche es später erneut.'}`;
    case 'rate_limited':
      return err.userMessage;
    case 'network_error':
    case 'timeout':
      return 'Keine Verbindung zum Server. Bitte prüfe deine Internetverbindung.';
    default:
      return err.userMessage;
  }
}

function brandHead(session: Session): SafeHtml {
  const b = session.config?.tenant ?? null;
  const name = b?.name ?? session.prefs.branding?.name ?? 'Planquadro';
  const logo = b?.branding.logo_url ?? session.prefs.branding?.logo_url ?? null;
  const logoOk = logo && /^https:\/\//.test(logo);
  return html`<div class="auth-head">
    ${logoOk
      ? html`<img src="${logo}" alt="${name}" class="auth-logo auth-logo-tenant" referrerpolicy="no-referrer">`
      : html`<img src="./icons/icon.svg" alt="" width="64" height="64" class="auth-logo">`}
    <h1>${name}</h1>
    <p class="muted">${session.isDemo ? 'Demo-Modus – Beispieldaten' : displayServerUrl(session.prefs.serverUrl ?? '')}</p>
  </div>`;
}

export function renderLogin(root: HTMLElement, session: Session): void {
  const cfg = session.config;
  if (cfg) setBrand(cfg.tenant.branding.primary, cfg.tenant.branding.accent);
  const oidc = cfg?.auth.oidc.enabled && cfg.auth.oidc.authorize_url ? cfg.auth.oidc : null;
  const passwordEnabled = cfg ? cfg.auth.password : true;
  const notice = session.loginMessage;
  session.loginMessage = '';

  root.innerHTML = html`<main class="auth" id="main" tabindex="-1">
    <div class="auth-card">
      ${brandHead(session)}
      ${notice ? html`<div class="alert alert-info" role="status">${notice}</div>` : ''}
      <div id="login-error"></div>
      ${passwordEnabled
        ? html`<form id="login-form" class="form" novalidate>
            <div class="field">
              <label for="username">Benutzername</label>
              <input id="username" name="username" type="text" autocomplete="username" autocapitalize="off" spellcheck="false" required>
            </div>
            <div class="field">
              <label for="password">Passwort</label>
              <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
          </form>`
        : ''}
      ${oidc
        ? html`${passwordEnabled ? html`<div class="divider"><span>oder</span></div>` : ''}
            <button type="button" class="btn btn-block btn-accent" data-action="oidc">${oidc.label || 'Mit Firmenkonto anmelden'}</button>`
        : ''}
      ${session.isDemo
        ? html`<p class="hint">Demo: beliebiger Benutzername, Passwort <code>demo</code>. Testfälle: <code>gesperrt</code>, <code>neu</code>, <code>wartung</code>.</p>`
        : ''}
      <div class="auth-alt">
        <button type="button" class="btn btn-ghost btn-block" data-action="server">Server ändern</button>
      </div>
    </div>
  </main>`.value;

  const errBox = root.querySelector('#login-error')!;
  const showError = (e: unknown) => {
    errBox.innerHTML = formError(loginErrorMessage(e)).value;
  };

  const form = root.querySelector<HTMLFormElement>('#login-form');
  form?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(form);
    const username = String(fd.get('username') ?? '');
    const password = String(fd.get('password') ?? '');
    errBox.innerHTML = '';
    if (!username.trim() || !password) {
      errBox.innerHTML = formError('Bitte Benutzername und Passwort eingeben.').value;
      return;
    }
    const btn = form.querySelector<HTMLButtonElement>('button[type="submit"]')!;
    btn.disabled = true;
    btn.textContent = 'Anmelden …';
    try {
      await session.login(username, password);
    } catch (e) {
      showError(e);
      btn.disabled = false;
      btn.textContent = 'Anmelden';
      const pw = root.querySelector<HTMLInputElement>('#password');
      if (pw) {
        pw.value = '';
        pw.focus();
      }
    }
  });

  root.querySelector('[data-action="oidc"]')?.addEventListener('click', async () => {
    errBox.innerHTML = '';
    try {
      await session.startOidc();
    } catch (e) {
      showError(e);
    }
  });

  root.querySelector('[data-action="server"]')!.addEventListener('click', () => void session.changeServer());

  // Konfiguration (Branding/OIDC) nachladen, falls noch nicht vorhanden
  if (!cfg) {
    session
      .loadConfig()
      .then(() => {
        if (session.status === 'needs_login' && root.isConnected) renderLogin(root, session);
      })
      .catch((e) => {
        if (toApiError(e).code === 'maintenance') showError(e);
      });
  }
}
