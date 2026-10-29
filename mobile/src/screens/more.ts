import { addDays, todayIso } from '../lib/dates';
import { html } from '../lib/html';
import { displayServerUrl } from '../lib/url';
import { biometryInfo, isNative } from '../platform/native';
import type { ThemeMode } from '../store/prefs';
import { confirmDialog, messageOf, toast } from '../ui/components';
import type { Screen } from '../ui/screen';
import { setThemeMode } from '../ui/theme';

const THEMES: Array<[ThemeMode, string]> = [
  ['system', 'System'],
  ['light', 'Hell'],
  ['dark', 'Dunkel'],
];

export const moreScreen: Screen = async (ctx) => {
  const { session } = ctx;
  ctx.setTitle('Mehr');
  ctx.setDataState(null);
  const me = session.me!;
  const bio = await biometryInfo();
  if (!ctx.isCurrent()) return;
  const p = session.prefs;

  ctx.render(html`
    <section class="card" aria-labelledby="h-acc">
      <h3 id="h-acc">Konto</h3>
      <dl>
        <div class="kv"><dt>Name</dt><dd>${me.user.first_name} ${me.user.last_name}</dd></div>
        <div class="kv"><dt>Benutzer</dt><dd>${me.user.username}</dd></div>
        <div class="kv"><dt>Rolle</dt><dd>${me.user.role_label}</dd></div>
        <div class="kv"><dt>Mandant</dt><dd>${me.tenant.name}</dd></div>
      </dl>
    </section>

    <section class="card" aria-labelledby="h-srv">
      <h3 id="h-srv">Server</h3>
      <dl>
        <div class="kv"><dt>Adresse</dt><dd>${session.isDemo ? html`<span class="chip chip-demo">Demo-Modus</span> Beispieldaten, kein Server` : displayServerUrl(p.serverUrl ?? '')}</dd></div>
        ${p.tenant && !session.isDemo ? html`<div class="kv"><dt>Mandanten-Kürzel</dt><dd>${p.tenant}</dd></div>` : ''}
      </dl>
      <button type="button" class="btn btn-block" data-action="change-server">Server wechseln</button>
      ${session.isDemo
        ? html`<button type="button" class="btn btn-block" data-action="demo-change">Demo: Planänderung simulieren</button>`
        : ''}
    </section>

    <section class="card" aria-labelledby="h-sec">
      <h3 id="h-sec">Sicherheit</h3>
      <div class="switch-row">
        <label for="bio-toggle">
          <span>Mit ${bio.label} entsperren</span>
          <span class="hint">${isNative
            ? bio.available
              ? 'Nach dem Start und nach mehr als 5 Minuten im Hintergrund.'
              : bio.reason || 'Auf diesem Gerät nicht verfügbar.'
            : 'Nur in der iOS-/Android-App verfügbar.'}</span>
        </label>
        <input id="bio-toggle" type="checkbox" role="switch" class="switch" ${p.biometric ? 'checked' : ''} ${bio.available ? '' : 'disabled'}>
      </div>
    </section>

    <section class="card" aria-labelledby="h-theme">
      <h3 id="h-theme">Darstellung</h3>
      <fieldset class="segmented">
        <legend class="sr-only">Farbschema</legend>
        ${THEMES.map(
          ([v, l]) => html`<label class="seg"><input type="radio" name="theme" value="${v}" ${p.theme === v ? 'checked' : ''}><span>${l}</span></label>`,
        )}
      </fieldset>
    </section>

    <section class="card" aria-labelledby="h-info">
      <h3 id="h-info">Über die App</h3>
      <dl>
        <div class="kv"><dt>Version</dt><dd>${session.version}</dd></div>
        <div class="kv"><dt>API</dt><dd>v1</dd></div>
      </dl>
      <p class="hint">Keine Analyse- oder Tracking-Dienste. Offline-Daten werden verschlüsselt auf diesem Gerät gespeichert.</p>
    </section>

    <section class="card">
      <button type="button" class="btn btn-block" data-action="logout">Abmelden</button>
      <button type="button" class="btn btn-block btn-danger" data-action="wipe">Lokale Daten löschen</button>
    </section>`);

  ctx.root.addEventListener('change', async (ev) => {
    const t = ev.target as HTMLInputElement;
    if (t.name === 'theme') {
      const mode = t.value as ThemeMode;
      await session.setTheme(mode);
      setThemeMode(mode);
    } else if (t.id === 'bio-toggle') {
      t.disabled = true;
      const res = await session.setBiometric(t.checked);
      t.checked = session.prefs.biometric;
      t.disabled = false;
      toast(res.message, res.ok ? 'ok' : 'error');
    }
  });

  ctx.root.addEventListener('click', async (ev) => {
    const a = (ev.target as HTMLElement).closest('[data-action]')?.getAttribute('data-action');
    try {
      if (a === 'logout') {
        if (await confirmDialog('Abmelden?', 'Alle lokal gespeicherten Daten werden von diesem Gerät gelöscht.', 'Abmelden')) {
          await session.logout();
        }
      } else if (a === 'wipe') {
        if (
          await confirmDialog(
            'Lokale Daten löschen?',
            'Du wirst abgemeldet. Offline-Daten, Schlüssel und Einstellungen inkl. Server-Adresse werden gelöscht.',
            'Löschen',
            true,
          )
        ) {
          await session.wipeLocal();
          toast('Lokale Daten gelöscht.', 'ok');
        }
      } else if (a === 'change-server') {
        if (await confirmDialog('Server wechseln?', 'Du wirst abgemeldet und alle lokalen Daten werden gelöscht.', 'Wechseln')) {
          await session.changeServer();
        }
      } else if (a === 'demo-change' && session.mock) {
        const t = todayIso();
        session.mock.publishChange([addDays(t, 1), addDays(t, 2)]);
        const r = await session.sync();
        toast(r ? `Synchronisiert: ${r.changedDates.length} geänderte Tage.` : 'Sync fehlgeschlagen.', r ? 'ok' : 'error');
      }
    } catch (e) {
      toast(messageOf(e), 'error');
    }
  });
};
