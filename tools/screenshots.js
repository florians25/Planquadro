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


/* ---- iPhone-Ansicht: App mit iOS-Sicherheitsbereichen, Statusleiste und Gerätrahmen ----
   Es läuft dieselbe Web-Oberfläche wie in der iOS-App (Capacitor/WKWebView); gerendert wird
   mit Chromium in iPhone-15-Pro-Größe (393×852 pt). Kein Simulator-Screenshot. */
const IOS = { w: 393, h: 852, top: 59, bottom: 34 };
async function iosFrame(b, rawPng, file, dark) {
  const p = await b.newPage({ viewport: { width: IOS.w + 40, height: IOS.h + 60 }, deviceScaleFactor: 2 });
  const fg = '#fff';
  const status = `<svg width="${IOS.w}" height="54" viewBox="0 0 ${IOS.w} 54" style="position:absolute;left:0;top:0">
    <text x="58" y="34" font-family="-apple-system,'SF Pro Text',Inter,Helvetica,Arial,sans-serif" font-size="17" font-weight="600" fill="${fg}" text-anchor="middle">9:41</text>
    <g fill="${fg}" transform="translate(292,24)"><rect x="0" y="8" width="3" height="4" rx="1"/><rect x="5" y="6" width="3" height="6" rx="1"/><rect x="10" y="3" width="3" height="9" rx="1"/><rect x="15" y="0" width="3" height="12" rx="1"/></g>
    <path transform="translate(316,23)" fill="${fg}" d="M8 2.6c2.4 0 4.6.9 6.3 2.4l1.2-1.3A10.6 10.6 0 0 0 8 .8 10.6 10.6 0 0 0 .5 3.7l1.2 1.3A9 9 0 0 1 8 2.6zm0 3.6c1.4 0 2.7.5 3.7 1.4l1.2-1.3A7.2 7.2 0 0 0 8 4.4c-1.9 0-3.6.7-4.9 1.9l1.2 1.3c1-.9 2.3-1.4 3.7-1.4zm0 3.6c.5 0 1 .2 1.3.5L8 12l-1.3-1.7c.3-.3.8-.5 1.3-.5z"/>
    <g transform="translate(338,23)"><rect x=".5" y=".5" width="24" height="12" rx="3.5" fill="none" stroke="${fg}" stroke-opacity=".45"/><rect x="2" y="2" width="21" height="9" rx="2" fill="${fg}"/><rect x="26" y="4.5" width="1.6" height="4" rx=".8" fill="${fg}" fill-opacity=".45"/></g>
  </svg>`;
  await p.setContent(`<html><body style="margin:0;background:transparent">
    <div id="wrap" style="display:inline-block;padding:16px 16px 34px"><div id="ph" style="position:relative;width:${IOS.w}px;height:${IOS.h}px;border-radius:55px;overflow:hidden;
      box-shadow:0 0 0 11px #1c1c1e,0 0 0 13px #3a3a3c,0 24px 48px rgba(0,0,0,.35)">
      <img src="data:image/png;base64,${rawPng.toString('base64')}" style="width:100%;height:100%;display:block">
      ${status}
      <div style="position:absolute;left:50%;top:11px;width:126px;height:37px;margin-left:-63px;border-radius:19px;background:#000"></div>
      <div style="position:absolute;left:50%;bottom:8px;width:134px;height:5px;margin-left:-67px;border-radius:3px;background:${dark ? '#fff' : '#111'}"></div>
    </div></div></body></html>`);
  await p.locator('#wrap').screenshot({ path: path.join(out, file), omitBackground: true });
  await p.close();
  console.log('Screenshot:', file);
}

async function iosScreens(b) {
  for (const dark of [false, true]) {
    const ctx = await b.newContext({ viewport: { width: IOS.w, height: IOS.h }, deviceScaleFactor: 3, isMobile: true, hasTouch: true,
      locale: 'de-DE', colorScheme: dark ? 'dark' : 'light',
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148' });
    await ctx.addInitScript((s) => {
      const st = () => { const e = document.createElement('style'); e.textContent = `:root{--safe-t:${s.top}px!important;--safe-b:${s.bottom}px!important}`; document.head.appendChild(e); };
      if (document.head) st(); else document.addEventListener('DOMContentLoaded', st);
    }, IOS);
    const a = await ctx.newPage();
    await a.goto(APP + '/');
    await a.waitForSelector('#server');
    await a.fill('#server', BASE);
    await a.click('button[type=submit]');
    await a.waitForSelector('#username');
    if (!dark) await iosFrame(b, await a.screenshot(), 'ios-login.png', dark);
    await a.fill('#username', 'm.keller');
    await a.fill('#password', PW);
    await a.click('button[type=submit]');
    await a.waitForTimeout(1500);
    for (const r of dark ? ['plan', 'team'] : ['plan', 'team', 'abwesenheiten', 'mehr']) {
      await a.evaluate((h) => { window.location.hash = h; }, '#/' + r);
      await a.waitForTimeout(900);
      await iosFrame(b, await a.screenshot(), `ios-${r}${dark ? '-dark' : ''}.png`, dark);
    }
    await ctx.close();
  }
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
  await iosScreens(b);
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
