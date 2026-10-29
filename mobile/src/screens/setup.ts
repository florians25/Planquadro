import { html } from '../lib/html';
import { ServerUrlError } from '../lib/url';
import type { Session } from '../store/session';
import { formError, messageOf } from '../ui/components';
import { displayServerUrl } from '../lib/url';

/** Erster Start: Server-Adresse und optionaler Mandant (SaaS). */
export function renderSetup(root: HTMLElement, session: Session): void {
  const prev = session.prefs.serverUrl ? displayServerUrl(session.prefs.serverUrl) : '';
  root.innerHTML = html`<main class="auth" id="main" tabindex="-1">
    <div class="auth-card">
      <div class="auth-head">
        <img src="./icons/icon.svg" alt="" width="64" height="64" class="auth-logo">
        <h1>Planquadro</h1>
        <p class="muted">Einsatzplanung für unterwegs</p>
      </div>
      <form id="setup-form" class="form" novalidate>
        <div id="setup-error"></div>
        <div class="field">
          <label for="server">Server-Adresse</label>
          <input id="server" name="server" type="url" inputmode="url" autocomplete="url" autocapitalize="off" spellcheck="false"
            placeholder="https://planquadro.firma.de" required value="${prev}" aria-describedby="server-hint">
          <p class="hint" id="server-hint">Die Adresse erhältst du von deiner Planung bzw. Administration.</p>
        </div>
        <div class="field">
          <label for="tenant">Mandanten-Kürzel <span class="muted">(optional)</span></label>
          <input id="tenant" name="tenant" type="text" autocomplete="organization" autocapitalize="off" spellcheck="false"
            placeholder="z. B. acme" value="${session.prefs.tenant ?? ''}" aria-describedby="tenant-hint">
          <p class="hint" id="tenant-hint">Nur nötig, wenn dein Unternehmen Planquadro als Cloud-Dienst über eine gemeinsame Adresse nutzt.</p>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Weiter</button>
      </form>
      <div class="auth-alt">
        <button type="button" class="btn btn-ghost btn-block" data-action="demo">Demo-Modus starten (ohne Server)</button>
      </div>
    </div>
  </main>`.value;

  const form = root.querySelector<HTMLFormElement>('#setup-form')!;
  const errBox = root.querySelector('#setup-error')!;
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = form.querySelector<HTMLButtonElement>('button[type="submit"]')!;
    const fd = new FormData(form);
    errBox.innerHTML = '';
    btn.disabled = true;
    btn.textContent = 'Verbinde …';
    try {
      await session.setServer(String(fd.get('server') ?? ''), String(fd.get('tenant') ?? ''));
    } catch (e) {
      const msg =
        e instanceof ServerUrlError
          ? e.message
          : (() => {
              const m = messageOf(e);
              return m === 'Der Eintrag wurde nicht gefunden.' ? 'Unter dieser Adresse wurde keine Planquadro-API (v1) gefunden.' : m;
            })();
      errBox.innerHTML = formError(msg).value;
      btn.disabled = false;
      btn.textContent = 'Weiter';
      root.querySelector<HTMLInputElement>('#server')?.focus();
    }
  });
  root.querySelector('[data-action="demo"]')!.addEventListener('click', () => void session.startDemo());
}
