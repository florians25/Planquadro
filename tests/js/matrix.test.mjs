// Planungsmatrix: reine Logik für Drag & Drop (Ausschneiden/Einfügen) und Filter.
// Lädt nur die markierten Blöcke aus public/assets/js/app.js (ohne DOM).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../public/assets/js/app.js', import.meta.url), 'utf8');
const block = (name) => {
  const m = src.match(new RegExp('/\\* ' + name + '-BEGIN \\*/([\\s\\S]*?)/\\* ' + name + '-END \\*/'));
  assert.ok(m, name + ' nicht gefunden');
  return m[1];
};
const { pqDnd, pqFilterMatch } = new Function(block('PQ-DND-LOGIC') + block('PQ-FILTER-LOGIC') + 'return {pqDnd,pqFilterMatch};')();

const cell = (o) => Object.assign({ u: '7', d: '2026-11-03', t: '', v: '', bl: false, unavail: false, webl: false, vaclock: false, rowLocked: false }, o);
const k1 = cell({ t: 'assignment', v: '1' });

test('Quelle: nur echte Einträge sind ziehbar', () => {
  assert.equal(pqDnd.checkSource(k1, false), '');
  assert.equal(pqDnd.checkSource(cell({ t: 'absence', v: 'gleitzeit' }), false), '');
  assert.equal(pqDnd.checkSource(cell({ t: 'absence', v: 'gesperrt' }), false), '');
  assert.notEqual(pqDnd.checkSource(cell({}), false), '', 'leere Zelle');
  assert.match(pqDnd.checkSource(cell({ t: 'absence', v: '' }), true), /Detailrecht/, 'vertrauliche Abwesenheit („A“)');
  assert.notEqual(pqDnd.checkSource(Object.assign({}, k1, { rowLocked: true }), false), '');
  assert.notEqual(pqDnd.checkSource(Object.assign({}, k1, { unavail: true }), false), '');
});

test('Quelle: genehmigter Urlaub/Krank nur kopieren, nicht verschieben', () => {
  const vac = cell({ t: 'absence', v: 'urlaub_genehmigt', vaclock: true });
  const sick = cell({ t: 'absence', v: 'krank_attest' });
  assert.match(pqDnd.checkSource(vac, false), /nicht verschieben/);
  assert.match(pqDnd.checkSource(sick, false), /nicht verschieben/);
  assert.equal(pqDnd.checkSource(vac, true), '');
  assert.equal(pqDnd.checkSource(cell({ t: 'absence', v: 'urlaub_genehmigt' }), false), '', 'Urlaub nur als Entwurf ist verschiebbar');
});

test('Ziel: gesperrte Zellen sind keine Ziele', () => {
  assert.equal(pqDnd.checkTarget(k1, cell({ d: '2026-11-04' })), '');
  assert.equal(pqDnd.checkTarget(k1, cell({ u: '9', t: 'assignment', v: '2' })), '', 'überschreibt Einsatz wie das Werkzeug');
  assert.equal(pqDnd.checkTarget(k1, cell({})), 'same');
  assert.equal(pqDnd.checkTarget({ u: 7, d: '2026-11-03' }, cell({})), 'same', 'Nutzer-ID als Zahl/Text');
  assert.match(pqDnd.checkTarget(k1, cell({ d: '2026-11-05', bl: true })), /Feiertag/);
  assert.match(pqDnd.checkTarget(k1, cell({ d: '2026-11-05', unavail: true })), /nicht verfügbar/);
  assert.match(pqDnd.checkTarget(k1, cell({ d: '2026-11-07', webl: true })), /Wochenend/);
  assert.match(pqDnd.checkTarget(k1, cell({ u: '8', rowLocked: true })), /anderen Benutzer/);
  assert.notEqual(pqDnd.checkTarget(k1, cell({ u: '8', t: 'absence', v: 'urlaub_genehmigt', vaclock: true })), '');
  assert.notEqual(pqDnd.checkTarget(k1, cell({ u: '8', t: 'absence', v: 'krank_halb' })), '');
  assert.notEqual(pqDnd.checkTarget(k1, cell({ u: '8', t: 'absence', v: '' })), '', 'vertrauliche Abwesenheit');
  assert.notEqual(pqDnd.checkTarget(k1, null), '');
});

test('Änderungen: Verschieben = Ziel setzen + Quelle leeren, Kopieren = nur Ziel', () => {
  const t = cell({ u: '9', d: '2026-11-10' });
  assert.deepEqual(pqDnd.changes(k1, t, false), [
    { user_id: 9, date: '2026-11-10', type: 'assignment', value: '1' },
    { user_id: 7, date: '2026-11-03', type: 'clear', value: '' },
  ]);
  assert.deepEqual(pqDnd.changes(k1, t, true), [{ user_id: 9, date: '2026-11-10', type: 'assignment', value: '1' }]);
  assert.equal(pqDnd.changes(cell({ t: 'absence', v: 'gesperrt' }), t, true)[0].type, 'locked');
  assert.equal(pqDnd.changes(cell({ t: 'absence', v: 'gleitzeit' }), t, true)[0].type, 'absence');
});

test('Kopiermodus über Strg, Alt oder ⌘', () => {
  assert.equal(pqDnd.isCopy({}), false);
  assert.equal(pqDnd.isCopy({ ctrlKey: true }), true);
  assert.equal(pqDnd.isCopy({ altKey: true }), true);
  assert.equal(pqDnd.isCopy({ metaKey: true }), true);
  assert.equal(pqDnd.isCopy(null), false);
});

test('Ansage für Screenreader', () => {
  assert.equal(pqDnd.describe('K1', k1, 'Muster, Max', cell({ d: '2026-11-04' }), 'Beispiel, Eva', false),
    'K1 von Muster, Max (03.11.2026) nach Beispiel, Eva (04.11.2026) verschoben.');
  assert.match(pqDnd.describe('', k1, 'A', k1, 'B', true), /^Eintrag .* kopiert\.$/);
});

test('Filter: Suche nach allen Wörtern, Rolle, Bereich, Schalter', () => {
  const r = { name: 'müller max', role: 'techniker', areas: ',1,3,', mine: true, drafts: false };
  const q = (o) => Object.assign({ name: '', role: '', area: '', mine: false, drafts: false }, o);
  assert.equal(pqFilterMatch(r, q({})), true);
  assert.equal(pqFilterMatch(r, q({ name: 'müller' })), true);
  assert.equal(pqFilterMatch(r, q({ name: 'max mü' })), true, 'Vorname zuerst');
  assert.equal(pqFilterMatch(r, q({ name: 'müller max' })), true, 'bisherige Eingaben treffen weiter');
  assert.equal(pqFilterMatch(r, q({ name: 'max  zz' })), false);
  assert.equal(pqFilterMatch(r, q({ role: 'techniker' })), true);
  assert.equal(pqFilterMatch(r, q({ role: 'lager' })), false);
  assert.equal(pqFilterMatch({ ...r, role: 'azubi_2' }, q({ role: 'azubis' })), true);
  assert.equal(pqFilterMatch(r, q({ role: 'azubis' })), false);
  assert.equal(pqFilterMatch(r, q({ area: '3' })), true);
  assert.equal(pqFilterMatch(r, q({ area: '13' })), false, 'Bereich exakt, nicht als Teilstring');
  assert.equal(pqFilterMatch(r, q({ mine: true })), true);
  assert.equal(pqFilterMatch({ ...r, mine: false }, q({ mine: true })), false);
  assert.equal(pqFilterMatch(r, q({ drafts: true })), false);
});
