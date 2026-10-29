/**
 * Planquadro – Screenshots für README und Release (Web + App)
 * ---------------------------------------------------------------------------
 * Voraussetzung: laufende Instanz mit Demodaten (php bin/demo.php) und die
 * gebaute App (mobile/dist), ausgeliefert unter APP_URL; die API muss den
 * Ursprung der App zulassen (PQ_API_CORS_ORIGINS).
 *
 *   BASE_URL=http://127.0.0.1:8099 APP_URL=http://127.0.0.1:5199 \
 *   DEMO_PASSWORD=… ADMIN_PASSWORD=… node tools/screenshots.js
 *
 * Schreibt nach docs/screenshots/. Chromium über Playwright (vorinstalliert).
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
const APP = process.env.APP_URL || 'http://127.0.0.1:5199';
const PW = process.env.DEMO_PASSWORD;
const ADMIN_PW = process.env.ADMIN_PASSWORD;
const out = path.resolve(__dirname, '../docs/screenshots');
fs.mkdirSync(out, { recursive: true });

async function login(ctx, user, pw) {
  const p = await ctx.newPage();
  await p.goto(BASE + '/');
  await p.fill('input[name=username]', user);
  await p.fill('input[name=password]', pw);
  await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]);
  return p;
}

async function shot(p, url, file, opts = {}) {
  await p.goto(BASE + url);
  await p.waitForLoadState('networkidle');
  if (opts.dark) await p.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  if (opts.before) await opts.before(p);
  await p.waitForTimeout(400);
  await p.screenshot({ path: path.join(out, file), fullPage: !!opts.full });
  console.log('Screenshot:', file);
}

(async () => {
  if (!PW) throw new Error('DEMO_PASSWORD fehlt');
  const b = await chromium.launch({ args: ['--lang=de-DE'] });

  /* --- Web, Desktop --- */
  const desk = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5, locale: 'de-DE' });
  const lp = await desk.newPage();
  await lp.goto(BASE + '/');
  await lp.screenshot({ path: path.join(out, 'web-login.png') });
  console.log('Screenshot: web-login.png');
  const mgr = await login(desk, 's.hoffmann', PW);
  await shot(mgr, '/dashboard', 'web-dashboard.png');
  await shot(mgr, '/monatsplanung', 'web-monatsplanung.png');
  await shot(mgr, '/monatsplanung', 'web-monatsplanung-dark.png', { dark: true });
  await shot(mgr, '/gesamtplan', 'web-gesamtplan.png');
  await shot(mgr, '/jahresuebersicht', 'web-jahresplan.png');
  await shot(mgr, '/genehmigungen', 'web-antraege.png');
  if (ADMIN_PW) {
    const deskAdmin = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5, locale: 'de-DE' });
    const adm = await login(deskAdmin, 'admin', ADMIN_PW);
    await shot(adm, '/organisation', 'web-organisation.png');
  }

  /* --- Web, Smartphone --- */
  const phone = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, locale: 'de-DE' });
  const tm = await login(phone, 'j.berger', PW);
  await shot(tm, '/dashboard', 'web-mobil-dashboard.png');

  /* --- App (PWA-Build der iOS-/Android-App) --- */
  for (const dark of [false, true]) {
    const app = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true,
      locale: 'de-DE', colorScheme: dark ? 'dark' : 'light' });
    const a = await app.newPage();
    await a.goto(APP + '/');
    await a.waitForSelector('#server');
    if (!dark) await a.screenshot({ path: path.join(out, 'app-setup.png') });
    await a.fill('#server', BASE);
    await a.click('button[type=submit]');
    await a.waitForSelector('#username');
    if (!dark) { await a.waitForTimeout(300); await a.screenshot({ path: path.join(out, 'app-login.png') }); }
    await a.fill('#username', 'm.keller');
    await a.fill('#password', PW);
    await a.click('button[type=submit]');
    await a.waitForTimeout(1500);
    const suffix = dark ? '-dark' : '';
    for (const r of ['plan', 'team', 'abwesenheiten', 'mehr']) {
      await a.evaluate((h) => { window.location.hash = h; }, '#/' + r);
      await a.waitForTimeout(900);
      await a.screenshot({ path: path.join(out, `app-${r}${suffix}.png`) });
      console.log('Screenshot:', `app-${r}${suffix}.png`);
      if (dark && r === 'plan') break;
    }
    await app.close();
  }
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
