import './styles/app.css';
import { isNative } from './platform/native';
import { SCREENSHOT_DEMO } from './platform/screenshot';
import { Session } from './store/session';
import { App } from './ui/app';
import { messageOf, toast } from './ui/components';

async function registerServiceWorker(): Promise<void> {
  // Nur PWA im Browser; in der nativen App liegt die Oberfläche ohnehin im Bundle.
  if (isNative || !('serviceWorker' in navigator) || import.meta.env.DEV) return;
  try {
    await navigator.serviceWorker.register('./sw.js', { scope: './' });
  } catch {
    /* PWA-Installation optional */
  }
}

async function boot(): Promise<void> {
  const root = document.getElementById('app')!;
  const params = new URLSearchParams(window.location.search);
  const forceDemo = params.get('mock') === '1' || SCREENSHOT_DEMO;

  const session = await Session.create();
  await session.init(forceDemo);
  // CI-Screenshot-Build: ohne Eingabe mit dem Demo-Konto anmelden
  if (SCREENSHOT_DEMO && session.status === 'needs_login') await session.login('demo', 'demo');

  // Web-OIDC: Rücksprung mit ?code=&state=
  if (session.hasPendingWebOidc()) {
    const href = window.location.href;
    history.replaceState(null, '', window.location.pathname + window.location.hash);
    try {
      await session.completeOidc(href);
    } catch (e) {
      session.loginMessage = messageOf(e);
    }
  }

  const app = new App(root, session);
  app.start();
  void registerServiceWorker();
}

boot().catch((e) => {
  const root = document.getElementById('app');
  if (root) root.textContent = 'Planquadro konnte nicht gestartet werden.';
  toast(messageOf(e), 'error');
  console.error(e);
});
