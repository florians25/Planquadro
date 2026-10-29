// Playwright-Rundgang (CSP, S-21/S-22): alle Seiten × Rollen mit Demodaten, sammelt
// securitypolicyviolation-Ereignisse, CSP-Konsolenmeldungen, JS-Fehler, nicht
// auflösbare data-action-Namen und verbliebene on…-Attribute; danach Klickpfade.
//   php bin/demo.php  (frische DB; Passwörter der Demo-Konten + admin gleichsetzen)
//   php -S 127.0.0.1:8080 -t public tests/router.php
//   BASE=http://127.0.0.1:8080/ PQ_DEMO_PW=… node tests/browser/csp-tour.cjs
// Verändert Daten (Planung, Veröffentlichen, Einstellungen) – nur gegen eine Wegwerf-DB.
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const PW = process.env.PQ_DEMO_PW || 'Demo-Pass-123';
const USERS = { admin: 'admin', manager: 's.hoffmann', teamleiter: 'm.keller', mitarbeiter: 'j.berger' };
const PAGES = ['dashboard', 'mein_plan', 'monatsplanung', 'gesamtplan', 'jahresuebersicht', 'urlaubsplanung',
  'abwesenheiten', 'genehmigungen', 'mitarbeiter', 'planbereiche', 'feiertage', 'stammdaten', 'rollen',
  'einstellungen', 'organisation', 'planungsassistent', 'profil', 'vertretung', 'datenschutz'];

const problems = [];
const matrix = {};
let curCtx = '';

function hook(page) {
  page.on('console', m => {
    if (m.type() === 'error' || /Content Security Policy|Refused to/.test(m.text()))
      problems.push({ ctx: curCtx, kind: /Content Security Policy|Refused to/.test(m.text()) ? 'csp' : 'console', msg: m.text().slice(0, 300) });
  });
  page.on('pageerror', e => problems.push({ ctx: curCtx, kind: 'pageerror', msg: String(e && e.message || e).slice(0, 300) }));
}

async function newSession(browser, role, opts = {}) {
  const ctx = await browser.newContext(opts);
  await ctx.addInitScript(() => {
    window.__csp = [];
    document.addEventListener('securitypolicyviolation', e => {
      window.__csp.push(e.violatedDirective + ' ' + (e.blockedURI || '') + ' ' + (e.sourceFile || '') + ':' + e.lineNumber + ' ' + (e.sample || ''));
    });
  });
  const page = await ctx.newPage();
  hook(page);
  curCtx = role + ' login';
  await page.goto(BASE);
  await page.fill('#loginUser', USERS[role]);
  await page.fill('#loginPw', PW);
  await Promise.all([page.waitForNavigation(), page.click('#loginBtn')]);
  if (!/dashboard/.test(page.url())) throw new Error('Login fehlgeschlagen für ' + role + ' → ' + page.url());
  return { ctx, page };
}

async function audit(page, label) {
  const r = await page.evaluate(() => {
    const unresolved = [], inline = [];
    const ev = (window.pqActions && window.pqActions.events) || {};
    const attrs = Object.values(ev);
    document.querySelectorAll('*').forEach(el => {
      for (const a of el.attributes) if (/^on[a-z]+$/i.test(a.name)) inline.push(el.tagName + ' ' + a.name);
      attrs.forEach(at => { if (el.hasAttribute(at)) { const n = el.getAttribute(at); if (!window.pqActions.resolve(n)) unresolved.push(at + '=' + n); } });
    });
    return { csp: window.__csp || [], unresolved: [...new Set(unresolved)], inline: [...new Set(inline)], hasPq: !!window.pqActions };
  });
  r.csp.forEach(c => problems.push({ ctx: label, kind: 'csp-event', msg: c }));
  r.unresolved.forEach(c => problems.push({ ctx: label, kind: 'unresolved-action', msg: c }));
  r.inline.forEach(c => problems.push({ ctx: label, kind: 'inline-attr', msg: c }));
  await page.evaluate(() => { window.__csp = []; });
  return r;
}

async function cv(page, sel) {
  for (const h of await page.$$(sel)) if (await h.isVisible()) { await h.click(); return h; }
  throw new Error('kein sichtbares Element: ' + sel);
}
const settle = (page, ms = 700) => page.waitForTimeout(ms);
async function step(page, label, fn) {
  curCtx = label;
  try { await fn(); await settle(page, 300); await audit(page, label); console.log('  ok   ' + label); }
  catch (e) { problems.push({ ctx: label, kind: 'step-failed', msg: e.message.split('\n')[0] }); console.log('  FAIL ' + label + ': ' + e.message.split('\n')[0]); }
}
async function closeModals(page) {
  await page.evaluate(() => document.querySelectorAll('.modal-bg.open').forEach(m => m.classList.remove('open')));
}

(async () => {
  const browser = await chromium.launch();
  for (const role of Object.keys(USERS)) {
    const { ctx, page } = await newSession(browser, role);
    matrix[role] = {};
    for (const p of PAGES) {
      curCtx = role + ' ' + p;
      const resp = await page.goto(BASE + p, { waitUntil: 'load' });
      await settle(page);
      const st = resp ? resp.status() : 0;
      const finalUrl = page.url().replace(BASE, '');
      await audit(page, curCtx);
      matrix[role][p] = st + (finalUrl !== p ? '→' + finalUrl : '') + (problems.some(x => x.ctx === curCtx) ? ' !' : '');
    }
    await ctx.close();
  }

  /* ---------------- Klickpfade ---------------- */
  console.log('Klickpfade (manager):');
  {
    const { ctx, page } = await newSession(browser, 'manager');
    page.on('dialog', d => d.dismiss());
    // Dark-Mode-Umschalter über Benutzermenü
    await step(page, 'theme toggle', async () => {
      await page.goto(BASE + 'dashboard'); await settle(page);
      await cv(page, '.nav-user-btn');
      if (!await page.$eval('#userMenu', e => e.classList.contains('open'))) throw new Error('Benutzermenü öffnet nicht');
      const t0 = await page.getAttribute('html', 'data-theme-pref');
      await cv(page, '.pq-theme-toggle');
      const t1 = await page.getAttribute('html', 'data-theme-pref');
      await cv(page, '.pq-theme-toggle');
      const t2 = await page.getAttribute('html', 'data-theme-pref');
      if (t0 === t1 || t1 === t2) throw new Error('Theme wechselt nicht: ' + [t0, t1, t2]);
      await cv(page, '.pq-theme-toggle');
    });
    await step(page, 'Passwort-Dialog + Changelog', async () => {
      if (!await page.$eval('#userMenu', e => e.classList.contains('open'))) await cv(page, '.nav-user-btn');
      await cv(page, 'a.nav-drop-item:has-text("Passwort ändern")');
      await page.waitForSelector('#pwMod.open');
      await cv(page, '#pwMod .mx');
      if (await page.$('#pwMod.open')) throw new Error('pwMod schließt nicht');
      await cv(page, '.nav-user-btn');
      await cv(page, 'a.nav-drop-item:has-text("Was gibt es Neues")');
      await page.waitForSelector('#changelogMod.open');
      await cv(page, '#changelogMod .mf button');
    });
    await step(page, 'Benachrichtigungen', async () => {
      await cv(page, '.notif-bell'); await page.waitForSelector('#notifPanel', { state: 'visible' });
      await cv(page, '#notifPanel .notif-panel-head button');
      await page.click('body', { position: { x: 5, y: 400 } });
    });
    // Planung
    await step(page, 'Planung: Werkzeug + Zellen setzen', async () => {
      await page.goto(BASE + 'monatsplanung'); await settle(page, 1200);
      const val = await page.$eval('#toolArea option[value]:not([value=""])', o => o.value);
      await page.selectOption('#toolArea', val);
      const cells = await page.$$('.pe-c[data-bl="0"]:not(.cell-unavailable):not([data-webl="1"])');
      if (cells.length < 3) throw new Error('keine Zellen');
      const b1 = await cells[0].boundingBox(); const b2 = await cells[1].boundingBox();
      const resp = page.waitForResponse(r => r.url().includes('api/save_bulk'));
      await page.mouse.move(b1.x + 5, b1.y + 5); await page.mouse.down();
      await page.mouse.move(b2.x + 5, b2.y + 5); await page.mouse.up();
      const r = await resp; if (r.status() !== 200) throw new Error('save_bulk ' + r.status());
      await settle(page, 800);
      await cv(page, '#toolDeselect');
    });
    await step(page, 'Planung: Filter/Sortierung/Nur Änderungen', async () => {
      const draft = await page.$('[data-action="toggleDraftsOnly"]'); if (draft) { await draft.click(); await draft.click(); }
      const s = await page.$('[data-action="toggleSort"]'); if (s) { await s.click(); await s.click(); }
      await page.selectOption('#filterRole', 'techniker'); await settle(page, 300);
      await cv(page, '[data-action="resetFilters"]');
      if (await page.inputValue('#filterRole') !== '') throw new Error('Filter nicht zurückgesetzt');
    });
    await step(page, 'Planung: WOT-Dialog', async () => {
      const w = await page.$('.wot-edit'); if (!w) throw new Error('kein WOT-Knopf');
      await w.click(); await page.waitForSelector('#wotMgrMod.open');
      await cv(page, '#wotMgrMod .mx');
    });
    await step(page, 'Planung: Konfliktdialog (Mehrfach-Eintrag)', async () => {
      /* „Erfassen“ liegt seit den Planungshilfen im Menü „Mehr“ */
      const more = page.locator('details.pq-more > summary');
      if (await more.count()) await more.first().click();
      await cv(page, '[data-action="oMod"][data-args*="entryMod"]');
      await page.waitForSelector('#entryMod.open');
      await cv(page, '#entryMod [data-action="entryUsersAll"][data-args="[true]"]');
      const cnt = await page.textContent('#entryUserCnt'); if (cnt === '0') throw new Error('Alle-Auswahl wirkt nicht');
      await page.fill('#entryUserSearch', 'x'); await page.fill('#entryUserSearch', '');
      const opt = await page.$$eval('#entryType option', os => os.map(o => o.value).filter(Boolean));
      if (opt.length) await page.selectOption('#entryType', opt[0]);
      await cv(page, '#entryMod [data-action="saveEntry"]');
      await page.waitForSelector('#conflictMod.open, #confirmMod.open', { timeout: 8000 });
      if (await page.$('#conflictMod.open')) {
        await cv(page, '#conflictMod [data-action="cfxAll"][data-args="[false]"]');
        const cb = await page.$('#conflictMod .cfx-emp-cb'); if (cb) { await cb.check(); await cb.uncheck(); }
        await cv(page, '#conflictMod .mx');
      } else { console.log('     (kein Konflikt – Bestätigungsdialog)'); await cv(page, '#confirmNo'); }
      await closeModals(page);
    });
    await step(page, 'Planung: Veröffentlichen', async () => {
      await page.goto(BASE + 'monatsplanung'); await settle(page, 1000);
      await cv(page, '[data-action="publishPlan"]');
      await page.waitForSelector('#confirmMod.open');
      const resp = page.waitForResponse(r => r.url().includes('api/publish'));
      await cv(page, '#confirmYes');
      const r = await resp; if (r.status() !== 200) throw new Error('publish ' + r.status());
      await settle(page, 1500);
    });
    await step(page, 'Gesamtplan: Sortierung/Filter', async () => {
      await page.goto(BASE + 'gesamtplan'); await settle(page);
      await cv(page, '[data-action="toggleSort"]'); await page.selectOption('#filterRole', { index: 1 }); await settle(page, 300); await cv(page, '[data-action="resetFilters"]');
    });
    await step(page, 'Anträge (Genehmigungen)', async () => {
      await page.goto(BASE + 'genehmigungen'); await settle(page, 1200);
      await cv(page, '[data-action="setStatus"][data-args*="approved"]'); await settle(page, 600);
      await cv(page, '[data-action="setStatus"][data-args*="pending"]'); await settle(page, 800);
      const d = await page.$('[data-action="openDetail"]');
      if (d) { await d.click(); await page.waitForSelector('#detMod.open'); await cv(page, '#detMod .mx'); }
      else console.log('     (keine offenen Anträge)');
    });
    await step(page, 'Abwesenheiten', async () => {
      await page.goto(BASE + 'abwesenheiten'); await settle(page, 1000);
      await cv(page, '[data-action="ABW.reset"]');
      await cv(page, '[data-action="ABW.load"]'); await settle(page, 800);
    });
    await step(page, 'Team: Filter, Aktionsmenü, Dialog', async () => {
      await page.goto(BASE + 'mitarbeiter'); await settle(page);
      await cv(page, '[data-action="quickFilterStatus"][data-args*="inactive"]');
      await cv(page, '[data-action="quickFilterStatus"][data-args*="active"]');
      const b = await page.$('button[data-action="oUserMod"][data-args]');
      await b.click(); await page.waitForSelector('#userMod.open');
      await cv(page, '#userMod .mx');
      await cv(page, '[data-action="oUserMod"]:not([data-args])'); await page.waitForSelector('#userMod.open');
      await cv(page, '#userMod [data-action="wzGoTo"][data-args="[2]"]');
      await cv(page, '#userMod [data-action="wzPrev"]');
      await cv(page, '#userMod .mx');
      const q = await page.$('[data-action="quickSup"]'); if (q) { await q.click(); await page.waitForSelector('#supMod.open'); await cv(page, '#supMod .mx'); }
    });
    await step(page, 'Profil: Kalender-URL', async () => {
      await page.goto(BASE + 'profil'); await settle(page);
    });
    await ctx.close();
  }
  console.log('Klickpfade (admin):');
  {
    const { ctx, page } = await newSession(browser, 'admin');
    page.on('dialog', d => d.dismiss());
    await step(page, 'Rollen: wechseln, Alle an/aus, Override-Dialog', async () => {
      await page.goto(BASE + 'rollen'); await settle(page);
      const cards = await page.$$('.rl-role-card'); await cards[1].click();
      const key = await cards[1].getAttribute('data-role');
      await page.click(`[data-action="rlToggleAll"][data-args='["${key}",false]']`);
      await page.click(`[data-action="rlToggleAll"][data-args='["${key}",true]']`);
      const ov = await page.$(`.rl-user-btn:visible`); if (ov) { await ov.click(); await page.waitForSelector('#overrideMod.open'); await cv(page, '[data-action="rlSetOverrideMode"][data-args*="deny"]'); await page.fill('#overrideMod input[type=text], #overrideMod input[type=search]', 'a').catch(() => {}); await cv(page, '#overrideMod .mx'); }
      await cv(page, '[data-action="oMod"][data-args*="newRoleMod"]'); await page.waitForSelector('#newRoleMod.open'); await cv(page, '#newRoleMod .mx');
    });
    await step(page, 'Rollen: speichern', async () => {
      const resp = page.waitForResponse(r => r.request().method() === 'POST');
      await cv(page, '.rl-panel:visible [data-action="rlSave"], [data-action="rlSave"]:visible');
      const r = await resp; if (r.status() >= 400) throw new Error('rlSave ' + r.status());
      await settle(page, 1000);
    });
    await step(page, 'Einstellungen: alle Reiter, speichern', async () => {
      await page.goto(BASE + 'einstellungen'); await settle(page);
      for (const b of await page.$$('[data-action="stNav"]')) { if (await b.isVisible()) { await b.click(); await settle(page, 150); } }
      await cv(page, '[data-action="stNav"][data-args="[0]"]');
      const resp = page.waitForResponse(r => r.request().method() === 'POST');
      await cv(page, '[data-action="saveSettings"]');
      const r = await resp; if (r.status() >= 400) throw new Error('saveSettings ' + r.status());
      await settle(page, 600);
      await cv(page, '[data-action="oMod"][data-args*="modAddAnn"]').catch(() => {});
      if (await page.$('#modAddAnn.open')) { await cv(page, '[data-action="setValue"][data-args*="warning"]'); const v = await page.inputValue('#annPrio'); if (v !== 'warning') throw new Error('setValue'); await cv(page, '#modAddAnn .mx'); }
    });
    await step(page, 'Organisation: Seite + Speichern', async () => {
      await page.goto(BASE + 'organisation'); await settle(page);
      const resp = page.waitForResponse(r => r.request().method() === 'POST');
      await cv(page, '#fBrand button[type=submit]');
      const r = await resp; if (r.status() >= 400) throw new Error('Branding ' + r.status());
      await settle(page);
    });
    await step(page, 'Feiertage/Bereiche-Dialoge', async () => {
      await page.goto(BASE + 'feiertage'); await settle(page);
      await cv(page, '[data-action="oMod"][data-args*="holMod"]'); await page.waitForSelector('#holMod.open'); await cv(page, '#holMod .mx');
      const e = await page.$('[data-action="oHolMod"]'); if (e) { await e.click(); await page.waitForSelector('#holMod.open'); await cv(page, '#holMod .mx'); }
      await page.goto(BASE + 'planbereiche'); await settle(page);
      await cv(page, '[data-action="oAreaMod"][data-args]'); await page.waitForSelector('#areaMod.open'); await cv(page, '#areaMod .mx');
    });
    await step(page, 'Assistent: Seite', async () => {
      await page.goto(BASE + 'planungsassistent'); await settle(page, 1000);
      const b = await page.$('[data-action="EPA.newPlan"], button:has-text("Neuer Plan")'); if (b) await b.click();
    });
    await ctx.close();
  }
  console.log('Mobile Navigation (mitarbeiter, 390px):');
  {
    const { ctx, page } = await newSession(browser, 'mitarbeiter', { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    await step(page, 'mobile nav + Trotzdem anzeigen', async () => {
      await page.goto(BASE + 'dashboard'); await settle(page);
      const links = await page.$$('.mob-nav a.mob-nav-item');
      if (!links.length || !(await links[1].isVisible())) throw new Error('mob-nav unsichtbar');
      await Promise.all([page.waitForNavigation(), links[1].click()]);
      await page.goto(BASE + 'gesamtplan'); await settle(page);
      const btn = await page.$('[data-action="pqForceDesktop"]');
      if (btn && await btn.isVisible()) { await btn.click(); if (!await page.$eval('html', h => h.classList.contains('mob-force-desktop'))) throw new Error('force desktop'); }
      else throw new Error('Trotzdem-anzeigen-Knopf nicht sichtbar');
    });
    await step(page, 'mobil: Mein Plan Reiter', async () => {
      await page.goto(BASE + 'mein_plan'); await settle(page);
      await cv(page, '[data-action="switchMpTab"][data-args="[1]"]'); await settle(page, 600);
      await cv(page, '[data-action="switchMpTab"][data-args="[0]"]');
    });
    await ctx.close();
  }
  await browser.close();

  console.log('\nSeiten × Rollen (HTTP-Status, "!" = Auffälligkeit):');
  console.log(['Seite'.padEnd(18), ...Object.keys(matrix).map(r => r.padEnd(14))].join(''));
  for (const p of PAGES) console.log([p.padEnd(18), ...Object.keys(matrix).map(r => String(matrix[r][p]).padEnd(14))].join(''));
  const byKind = {};
  problems.forEach(p => { byKind[p.kind] = (byKind[p.kind] || 0) + 1; });
  console.log('\nProbleme nach Art:', JSON.stringify(byKind));
  const seen = new Set();
  problems.forEach(p => { const k = p.ctx + '|' + p.kind + '|' + p.msg; if (seen.has(k)) return; seen.add(k); console.log(' - [' + p.kind + '] ' + p.ctx + ': ' + p.msg); });
  const csp = problems.filter(p => p.kind.startsWith('csp')).length;
  console.log('\nCSP-Verletzungen gesamt: ' + csp);
  process.exit(problems.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
