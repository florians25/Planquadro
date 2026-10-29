// Playwright-Rundgang Planungsassistent: Manager und Teamleiter mit Demodaten.
// Prüft den ganzen Ablauf (① Zeitraum & Team → ② Bedarf & Regeln → ③ Vorschlag
// prüfen → ④ Übernehmen → Rückgängig), Tastaturbedienung im Raster, Vorlagen,
// gespeicherte Planungen, und sammelt JS-Fehler sowie CSP-Verstöße.
//   php bin/demo.php  (frische DB)
//   php -S 127.0.0.1:8080 -t public tests/router.php
//   BASE=http://127.0.0.1:8080 PQ_DEMO_PW=… [SHOTS=/pfad] node tests/browser/assistant-tour.cjs
// Verändert Daten (Übernahme wird wieder zurückgenommen) – nur gegen eine Wegwerf-DB.
const { chromium } = require('playwright');
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const BASE = (process.env.BASE || 'http://127.0.0.1:8080').replace(/\/$/, '');
const PW = process.env.PQ_DEMO_PW || 'Demo-Pass-123';
const SHOTS = process.env.SHOTS || '';
const AREA = process.env.AREA || 'SWN';
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

const problems = [];
let ctxLabel = '';

async function session(browser, user, opts) {
  const ctx = await browser.newContext(Object.assign({ locale: 'de-DE' }, opts));
  await ctx.addInitScript(() => {
    window.__csp = [];
    document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(e.violatedDirective + ' ' + (e.blockedURI || '') + ' ' + (e.sample || '')));
  });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error') problems.push(ctxLabel + ' console: ' + m.text().slice(0, 240)); });
  page.on('pageerror', (e) => problems.push(ctxLabel + ' pageerror: ' + String(e.message || e).slice(0, 240)));
  await page.goto(BASE + '/');
  await page.fill('input[name=username]', user);
  await page.fill('input[name=password]', PW);
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
  return { ctx, page };
}
async function shot(page, name, full) {
  if (!SHOTS) return;
  await page.evaluate(() => { const t = document.getElementById('toasts'); if (t) t.innerHTML = ''; });
  await page.waitForTimeout(250);
  await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: !!full });
}
async function csp(page) {
  const v = await page.evaluate(() => { const x = window.__csp || []; window.__csp = []; return x; });
  v.forEach((c) => problems.push(ctxLabel + ' CSP: ' + c));
}
const idle = (page) => page.waitForLoadState('networkidle');

async function tour(browser, user, opts, tag, withShots) {
  const { ctx, page } = await session(browser, user, opts);
  const S = (n, full) => withShots && shot(page, tag + '-' + n, full);
  ctxLabel = tag + ' Übersicht';
  await page.goto(BASE + '/planungsassistent'); await idle(page);
  assert.ok(await page.isVisible('#epaHome'), 'Übersicht sichtbar');
  await S('1-uebersicht', true);

  ctxLabel = tag + ' ① Zeitraum & Team';
  await page.click('#epaHome [data-act=new] >> nth=0');
  await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'fArea', null, { timeout: 2000 });
  await page.click('#btnNext');
  assert.ok(await page.isVisible('#fAreaErr'), 'ohne Bereich kein Weiter – Hinweis am Feld');
  const label = await page.$eval('#fArea', (s, a) => [...s.options].find((o) => o.textContent.startsWith(a + ' ')).value, AREA);
  await page.selectOption('#fArea', label);
  await page.fill('#fTitle', 'Netzumbau Phase 2');
  await page.waitForSelector('#liveBody .pqa-pt', { timeout: 8000 });
  await idle(page);
  assert.match(await page.textContent('#liveBody'), /Abdeckung/);
  await S('2-zeitraum-team');

  ctxLabel = tag + ' ② Bedarf & Regeln';
  await page.click('#btnNext'); await idle(page);
  assert.ok(await page.isVisible('[data-panel="1"]'));
  await page.click('[data-step-for=fDemand][data-d="1"]');   // 3 pro Tag
  await page.waitForTimeout(900); await idle(page);
  assert.match(await page.textContent('#stepSum1'), /^3 pro Tag/);
  await page.click('#moreExpert > summary');
  await S('3-bedarf-regeln');
  await page.click('#moreExpert > summary');

  ctxLabel = tag + ' ③ Vorschlag prüfen';
  await page.click('#btnNext'); await idle(page);
  await page.waitForSelector('#matrix .pqa-cell[data-u]');
  const kpi = await page.textContent('#kpis');
  assert.match(kpi, /Abdeckung/);
  await S('4-vorschlag');
  /* Tastatur: erste Zelle fokussieren, Pfeil rechts, Leertaste schaltet um */
  await page.focus('#matrix .pqa-cell[tabindex="0"]');
  await page.keyboard.press('ArrowRight');
  const before = await page.evaluate(() => document.activeElement.getAttribute('aria-pressed'));
  const cellId = await page.evaluate(() => document.activeElement.id);
  await page.keyboard.press('Space');
  await page.waitForTimeout(200);
  if (await page.isVisible('#ovAsk.open, #ovAsk:not([hidden]).open')) await page.click('#askNo');
  const after = await page.$eval('#' + cellId, (b) => b.getAttribute('aria-pressed')).catch(() => before);
  if (before === 'true') assert.equal(after, 'false', 'Leertaste entfernt den Eintrag');
  /* Kontextmenü per Tastatur */
  await page.focus('#' + cellId);
  await page.keyboard.press('Shift+F10');
  assert.ok(await page.isVisible('#epaCtx.open'), 'Kontextmenü per Tastatur');
  assert.equal(await page.evaluate(() => document.activeElement.getAttribute('role')), 'menuitem');
  await page.keyboard.press('Escape');
  assert.ok(!(await page.isVisible('#epaCtx.open')));
  /* Ansichten */
  await page.click('[data-view=days]'); assert.ok(await page.isVisible('#daysView .pqa-day'));
  await page.click('[data-view=load]'); assert.ok(await page.isVisible('#loadView'));
  await page.click('[data-view=matrix]');
  /* Passende Personen über den Spaltenkopf */
  await page.click('#matrix .pqa-dh >> nth=1');
  await page.waitForSelector('#ovAlt.open #altBody li, #ovAlt.open #altBody p.muted');
  await page.click('#ovAlt.open [data-close] >> nth=0');
  await csp(page);

  ctxLabel = tag + ' ④ Übernehmen';
  await page.click('#btnNext');
  await page.waitForSelector('#applyHeadline', { timeout: 8000 });
  const head = await page.textContent('#applyHeadline');
  assert.match(head, /^\d+ Einsätze? für \d+ Personen?/, 'klare Zusammenfassung: ' + head);
  await S('5-uebernehmen');
  await page.click('#applyBox [data-act=apply]');
  await page.waitForSelector('#appliedTitle', { timeout: 10000 });
  assert.ok(await page.isVisible('#undoPill'), 'Rückgängig ist sichtbar');
  await S('6-uebernommen');
  await page.click('#applyBox [data-act=undo]');
  await page.click('#askYes');
  await page.waitForSelector('#applyHeadline', { timeout: 10000 });
  assert.ok(!(await page.isVisible('#undoPill')), 'nach Rückgängig keine offene Übernahme');
  await csp(page);

  ctxLabel = tag + ' Speichern und Vorlage';
  await page.click('#btnSavePlan');
  await page.fill('#spName', 'Rundgang ' + tag);
  await page.click('#spSaveBtn');
  await page.waitForFunction(() => !document.getElementById('ovSave').classList.contains('open'));
  await page.waitForFunction(() => /^Gespeichert/.test(document.getElementById('epaSaved').textContent));
  await page.click('#moreBtn');
  await page.click('#moreMenu [data-act=save-profile]');
  await page.waitForFunction(() => document.getElementById('ovSave').classList.contains('open'));
  await page.fill('#spName', 'Vorlage ' + tag);
  await page.click('#spSaveBtn');
  await page.waitForSelector('#ovSave:not(.open)', { state: 'attached' });
  await page.waitForFunction(() => !document.getElementById('ovSave').classList.contains('open'));
  await idle(page);
  await page.click('[data-act=home] >> nth=0'); await idle(page);
  /* Listen laden asynchron neu – auf die neuen Einträge warten */
  await page.waitForFunction((t) => document.getElementById('homeList').textContent.includes('Rundgang ' + t)
    && document.getElementById('tplList').textContent.includes('Vorlage ' + t), tag, { timeout: 8000 });
  await S('7-uebersicht-gefuellt', true);
  /* Gespeicherte Planung wieder öffnen → Schritt ③ */
  await page.click('#homeList [data-open] >> nth=0');
  await page.waitForSelector('[data-panel="2"]:not([hidden]) #matrix .pqa-cell');
  /* Vorlage verwenden → Schritt ① mit übernommenen Vorgaben */
  await page.click('#epaWizard [data-act=templates]');
  await page.waitForSelector('#ovProf.open [data-tpl]');
  await page.click('#ovProf.open [data-tpl] >> nth=0');
  await page.waitForSelector('[data-panel="0"]:not([hidden])');
  assert.equal(await page.inputValue('#fDemand'), '3', 'Vorlage bringt den Bedarf mit');
  await csp(page);
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ args: ['--lang=de-DE'] });
  const sizes = { desktop: { width: 1440, height: 900 }, tablet: { width: 820, height: 1180 } };
  try {
    for (const [sz, vp] of Object.entries(sizes)) {
      for (const theme of ['light', 'dark']) {
        if (process.env.ONLY && process.env.ONLY !== sz + '-' + theme) continue;
        await tour(browser, 's.hoffmann', { viewport: vp, colorScheme: theme }, 'manager-' + sz + '-' + theme, true);
        console.log('ok  Manager', sz, theme);
      }
    }
    await tour(browser, 'm.keller', { viewport: sizes.desktop, colorScheme: 'light' }, 'teamleiter-desktop-light', true);
    console.log('ok  Teamleiter');
  } catch (e) {
    problems.push(ctxLabel + ' FEHLER: ' + e.message);
  }
  await browser.close();
  if (problems.length) { console.log('Befunde:\n' + problems.join('\n')); process.exit(1); }
  console.log('Rundgang ohne Befund.');
})();
