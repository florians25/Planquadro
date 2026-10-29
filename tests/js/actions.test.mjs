// Ereignis-Delegation (public/assets/js/actions.js), Ersatz für Inline-Handler (S-21/S-22).
// Läuft ohne Browser: minimale DOM-Attrappe, die nur das Nötige nachbildet.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const src = readFileSync(new URL('../../public/assets/js/actions.js', import.meta.url), 'utf8');

function setup() {
  const listeners = {};
  const document = { addEventListener: (t, fn) => { (listeners[t] ||= []).push(fn); }, getElementById: (id) => document._ids[id] || null, _ids: {} };
  const errors = [];
  const window = { document, console, reportError: (e) => errors.push(e), location: { reload() { window.reloaded = true; } } };
  window.window = window;
  const el = (attrs, parent = null) => ({
    nodeType: 1, parentNode: parent, attrs: { ...attrs }, value: attrs.value ?? '', checked: !!attrs.checked,
    getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; },
    hasAttribute(n) { return n in this.attrs; },
  });
  const fire = (type, target) => {
    const path = []; for (let n = target; n; n = n.parentNode) path.push(n); path.push(document, window);
    const ev = { type, target, cancelBubble: false, defaultPrevented: false,
      composedPath: () => path, preventDefault() { this.defaultPrevented = true; }, stopPropagation() { this.cancelBubble = true; } };
    (listeners[type] || []).forEach((fn) => fn(ev));
    return ev;
  };
  vm.runInNewContext(src, window);
  return { window, document, el, fire, errors };
}

test('ruft globale Funktion mit JSON-Argumenten und Platzhaltern auf', () => {
  const { window, el, fire } = setup();
  const calls = [];
  vm.runInNewContext('function cMod(){ calls.push([].slice.call(arguments)); }', Object.assign(window, { calls }));
  const b = el({ 'data-action': 'cMod', 'data-args': '["pwMod",3,true,"$this","$value","$$geld"]', value: 'v' });
  fire('click', b);
  assert.equal(calls.length, 1);
  assert.deepEqual([...calls[0]].slice(0, 3), ['pwMod', 3, true]);
  assert.equal(calls[0][3], b);
  assert.equal(calls[0][4], 'v');
  assert.equal(calls[0][5], '$geld');
});

test('Pfad mit Objekt (this = Objekt) und change/input-Ereignisse', () => {
  const { window, el, fire } = setup();
  window.ABW = { n: 0, save() { this.n++; } };
  fire('click', el({ 'data-action': 'ABW.save' }));
  let checked = null;
  window.cfx = function (uid, c) { checked = [uid, c]; };
  fire('change', el({ 'data-action-change': 'cfx', 'data-args': '[7,"$checked"]', checked: true }));
  fire('click', el({ 'data-action-change': 'cfx' })); // falsches Ereignis → nichts
  assert.equal(window.ABW.n, 1);
  assert.deepEqual(checked, [7, true]);
});

test('native Funktionen und unbekannte Namen werden nicht aufgerufen', () => {
  const { window, el, fire, errors } = setup();
  window.evil = Function.prototype.call.bind(function () {}); // gebunden → nativer Code
  for (const name of ['eval', 'setTimeout', 'evil', 'gibtsnicht', 'a.__proto__', 'constructor']) fire('click', el({ 'data-action': name, 'data-args': '["1"]' }));
  assert.equal(errors.length, 6);
  assert.match(String(errors[0].message), /Unbekannte Aktion/);
});

test('Bubbling wie bei Inline-Handlern: innen zuerst, stopPropagation beendet', () => {
  const { window, el, fire } = setup();
  const order = [];
  window.outer = () => order.push('outer');
  window.inner = () => order.push('inner');
  const tr = el({ 'data-action': 'outer' });
  const btn = el({ 'data-action': 'inner' }, tr);
  fire('click', btn);
  assert.deepEqual(order, ['inner', 'outer']);
  window.pqAction('stop', (e, ev) => { ev.stopPropagation(); order.push('stop'); });
  fire('click', el({ 'data-action': 'stop' }, tr));
  assert.deepEqual(order, ['inner', 'outer', 'stop']);
});

test('data-prevent, data-return und registrierte Aktionen', () => {
  const { window, document, el, fire } = setup();
  window.jump = () => false;
  assert.equal(fire('click', el({ 'data-action': 'jump' })).defaultPrevented, false);
  assert.equal(fire('click', el({ 'data-action': 'jump', 'data-return': '' })).defaultPrevented, true);
  window.noop = () => {};
  assert.equal(fire('click', el({ 'data-action': 'noop', 'data-prevent': '' })).defaultPrevented, true);
  document._ids.annPrio = { value: '' };
  fire('click', el({ 'data-action': 'setValue', 'data-args': '["annPrio","warning"]' }));
  assert.equal(document._ids.annPrio.value, 'warning');
  fire('click', el({ 'data-action': 'reload' }));
  assert.equal(window.reloaded, true);
});

test('Fehler in einer Aktion werden gemeldet, nicht verschluckt', () => {
  const { window, el, fire, errors } = setup();
  window.boom = () => { throw new Error('kaputt'); };
  fire('click', el({ 'data-action': 'boom' }));
  fire('click', el({ 'data-action': 'boom', 'data-args': '[kein json' }));
  assert.equal(errors.length, 2);
});

test('pqActAttr erzeugt sicheres Markup', () => {
  const { window } = setup();
  const a = window.pqActAttr('removeSup', ['x"><img src=x>', '$this', 5]);
  assert.ok(!a.includes('<') && !/"[^"]*"[^ ]/.test(a.replace(/^ data-action="removeSup" data-args="[^"]*"$/, '')));
  const m = a.match(/data-args="([^"]*)"/);
  const decoded = m[1].replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
  assert.deepEqual([...JSON.parse(decoded)], ['x"><img src=x>', '$$this', 5]);
  assert.equal(window.pqActAttr('filterTeam', [], 'input'), ' data-action-input="filterTeam"');
});
