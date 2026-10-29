// Planungshilfen: reine Logik aus public/assets/js/planning-plus.js (ohne DOM).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../public/assets/js/planning-plus.js', import.meta.url), 'utf8');
const m = src.match(/\/\* PQ-PLUS-LOGIC-BEGIN \*\/([\s\S]*?)\/\* PQ-PLUS-LOGIC-END \*\//);
assert.ok(m, 'PQ-PLUS-LOGIC nicht gefunden');
const pqPlus = new Function(m[1] + 'return pqPlus;')();

test('Datum: Wochentag, Montag, Tage addieren – auch über Monats-/Jahresgrenzen und Zeitumstellung', () => {
  assert.equal(pqPlus.isoWeekday('2026-10-26'), 1);
  assert.equal(pqPlus.isoWeekday('2026-11-01'), 7);
  assert.equal(pqPlus.mondayOf('2026-11-01'), '2026-10-26');
  assert.equal(pqPlus.addDays('2026-10-24', 2), '2026-10-26', 'Zeitumstellung 25.10.');
  assert.equal(pqPlus.addDays('2026-12-30', 3), '2027-01-02');
  assert.equal(pqPlus.dayDiff('2026-10-01', '2026-10-31'), 30);
  assert.equal(pqPlus.de('2026-11-03'), '03.11.2026');
});

test('Abdeckung zählt nur Einsätze, je Tag und je Bereich', () => {
  const cov = pqPlus.coverage([
    { d: '2026-11-02', t: 'assignment', v: '1' }, { d: '2026-11-02', t: 'assignment', v: '1' },
    { d: '2026-11-02', t: 'assignment', v: '2' }, { d: '2026-11-02', t: 'absence', v: 'urlaub_genehmigt' },
    { d: '2026-11-02', t: 'absence', v: '' }, { d: '2026-11-03', t: '', v: '' },
  ]);
  assert.deepEqual(cov.total, { '2026-11-02': 3 });
  assert.deepEqual(cov.byArea, { 1: { '2026-11-02': 2 }, 2: { '2026-11-02': 1 } });
});

test('Unterbesetzung nach Soll je Wochentag, Feiertage ausgenommen', () => {
  const cov = pqPlus.coverage([{ d: '2026-11-02', t: 'assignment', v: '1' }]);
  const targets = { 1: { 1: 2, 2: 1 } };              // Mo 2, Di 1
  const dates = ['2026-11-02', '2026-11-03', '2026-11-04'];
  assert.deepEqual(pqPlus.understaffed(cov, targets, dates, []), [
    { area: '1', d: '2026-11-02', have: 1, need: 2 }, { area: '1', d: '2026-11-03', have: 0, need: 1 }]);
  assert.deepEqual(pqPlus.understaffed(cov, targets, dates, ['2026-11-03']).length, 1);
  assert.equal(pqPlus.need(targets, '1', '2026-11-04'), 0, 'Mi ohne Vorgabe');
  assert.equal(pqPlus.need({}, '1', '2026-11-02'), 0);
});

test('Rückgängig: Eintrag aus gespeicherten Änderungen (letzte je Zelle, nur geschriebene, keine No-ops)', () => {
  const prev = { '7_2026-11-02': { t: 'assignment', v: '1', i: false }, '7_2026-11-03': { t: '', v: '', i: false }, '8_2026-11-02': { t: 'assignment', v: '2', i: false } };
  const changes = [
    { user_id: 7, date: '2026-11-02', type: 'assignment', value: '3' },
    { user_id: 7, date: '2026-11-03', type: 'locked', value: 'gesperrt' },
    { user_id: 8, date: '2026-11-02', type: 'assignment', value: '2' },          // gleich wie vorher → kein Eintrag
    { user_id: 9, date: '2026-11-02', type: 'clear', value: '' },               // nicht geschrieben (keine Revision)
  ];
  const e = pqPlus.makeEntry(changes, c => prev[c.user_id + '_' + c.date], { '7_2026-11-02': 'r1', '7_2026-11-03': 'r2', '8_2026-11-02': 'r3' }, false);
  assert.equal(e.cells.length, 2);
  assert.deepEqual(e.cells[0], { u: '7', d: '2026-11-02', prev: prev['7_2026-11-02'], next: { t: 'assignment', v: '3', i: false }, rev: 'r1' });
  assert.deepEqual(e.cells[1].next, { t: 'absence', v: 'gesperrt', i: false });
  assert.equal(pqPlus.makeEntry([], () => null, {}, false), null);
});

test('Rückgängig/Wiederholen: Änderungen mit Revision, getrennt nach Sichtbarkeit, vertrauliche übersprungen', () => {
  const entry = { cells: [
    { u: '7', d: '2026-11-02', prev: { t: 'assignment', v: '1', i: false }, next: { t: 'assignment', v: '3', i: true }, rev: 'r1' },
    { u: '7', d: '2026-11-03', prev: { t: '', v: '', i: false }, next: { t: 'absence', v: 'gesperrt', i: true }, rev: 'r2' },
    { u: '8', d: '2026-11-02', prev: { t: 'absence', v: '', i: false }, next: { t: 'assignment', v: '2', i: true }, rev: 'r3' },
    { u: '9', d: '2026-11-02', prev: { t: 'absence', v: 'gleitzeit', i: true }, next: { t: '', v: '', i: false }, rev: 'r4' },
  ] };
  const u = pqPlus.restoreGroups(entry, 'undo', (uu, dd) => (uu === '7' && dd === '2026-11-02' ? 'dom' : null));
  assert.equal(u.skipped, 1, '„A“ ist nicht wiederherstellbar');
  assert.deepEqual(u.groups, [
    { internal: false, changes: [
      { user_id: 7, date: '2026-11-02', type: 'assignment', value: '1', rev: 'dom' },
      { user_id: 7, date: '2026-11-03', type: 'clear', value: '', rev: 'r2' }] },
    { internal: true, changes: [{ user_id: 9, date: '2026-11-02', type: 'absence', value: 'gleitzeit', rev: 'r4' }] }]);
  const r = pqPlus.restoreGroups(entry, 'redo', () => null);
  assert.equal(r.skipped, 0);
  assert.equal(r.groups[1].changes[1].type, 'locked');
  const moved = pqPlus.withRevs(entry, { '7_2026-11-02': 'n1' });
  assert.equal(moved.cells[0].rev, 'n1');
  assert.equal(moved.cells[1].rev, 'r2');
  assert.deepEqual(moved.cells[0].prev, entry.cells[0].prev, 'Richtung bleibt');
});

test('Woche kopieren: Einsätze je Wochentag, Wochenende/Ein-/Austritt/Feiertag beachtet, nie Urlaub/Krank', () => {
  const src = {
    '7_2026-10-19': { type: 'assignment', value: '1' }, '7_2026-10-20': { type: 'absence', value: 'gleitzeit' },
    '7_2026-10-21': { type: 'absence', value: 'urlaub_genehmigt' }, '7_2026-10-22': { type: 'absence', value: '', confidential: 1 },
    '7_2026-10-24': { type: 'assignment', value: '2' }, '8_2026-10-19': { type: 'absence', value: 'gesperrt' },
  };
  const rows = [{ u: '7', we: false }, { u: '8', we: true, exit: '2026-10-27' }];
  const base = pqPlus.copyChanges(src, rows, '2026-10-19', '2026-10-26', '2026-11-01', {});
  assert.deepEqual(base, [{ user_id: 7, date: '2026-10-26', type: 'assignment', value: '1' }]);
  const all = pqPlus.copyChanges(src, rows, '2026-10-19', '2026-10-26', '2026-11-01', { absences: true, clearEmpty: true, holidays: ['2026-10-30'] });
  const k = all.map(c => c.user_id + ' ' + c.date + ' ' + c.type + ' ' + c.value);
  assert.ok(k.includes('7 2026-10-27 absence gleitzeit'));
  assert.ok(k.includes('8 2026-10-26 locked gesperrt'));
  assert.ok(!k.some(x => /urlaub|krank/.test(x)), 'keine vertraulichen Arten');
  assert.ok(!k.some(x => x.startsWith('7 2026-10-28')), 'genehmigter Urlaub wird nicht zu „leer“');
  assert.ok(!k.some(x => x.startsWith('7 2026-10-31')), 'Wochenende ohne Freigabe');
  assert.ok(!k.some(x => x.includes('2026-10-30')), 'Feiertag');
  assert.ok(!k.some(x => x.startsWith('8 2026-10-28')), 'nach Austritt');
  assert.ok(k.includes('8 2026-10-27 clear '), 'leerer Werktag leert (Option)');
  assert.ok(!k.some(x => x.startsWith('7 2026-10-29')), 'vertrauliche Abwesenheit („A“) ist nicht leer');
  assert.deepEqual(pqPlus.copyChanges(src, rows, '2026-10-19', '2026-10-19', '2026-10-25', {}), [], 'Quelle = Ziel');
  assert.deepEqual(pqPlus.copyChanges(src, rows, '2026-10-19', '2026-11-02', '2026-11-01', {}), [], 'von > bis');
});

test('Ansicht: Standardwerte, ungültige Werte werden verworfen', () => {
  assert.deepEqual(pqPlus.view(''), pqPlus.VIEW_DEFAULTS);
  assert.deepEqual(pqPlus.view('kaputt{'), pqPlus.VIEW_DEFAULTS);
  const v = pqPlus.view(JSON.stringify({ density: 'riesig', coverage: false, crosshair: 'ja', toolbar: false }));
  assert.equal(v.density, 'standard');
  assert.equal(v.coverage, false);
  assert.equal(v.crosshair, false);
  assert.equal(v.toolbar, false);
});

test('Tastenkürzel, Schnellwahl und Bereichssuche', () => {
  assert.deepEqual(pqPlus.keyAction('u'), { target: 'urlaub_genehmigt', label: 'Urlaub genehmigt' });
  assert.equal(pqPlus.keyAction('U').target, 'urlaub_ganz');
  assert.equal(pqPlus.keyAction('K').target, 'krank_ganz');
  assert.deepEqual(pqPlus.keyAction('3'), { recent: 2 });
  assert.equal(pqPlus.keyAction('6'), null);
  assert.equal(pqPlus.keyAction('x'), null, 'Strg+X bleibt Drag-&-Drop vorbehalten, x allein ist frei');
  assert.equal(pqPlus.keyLabel('K'), 'Umschalt+K');
  assert.equal(pqPlus.keyLabel('k'), 'K');
  assert.equal(pqPlus.keyFor('#toolClr'), 'l');
  const keys = pqPlus.KEYS.map(k => k[0]);
  assert.equal(new Set(keys).size, keys.length, 'keine doppelten Kürzel');
  assert.deepEqual(pqPlus.pushRecent(['1', '2', '3', '4', '5'], 3, 5), ['3', '1', '2', '4', '5']);
  assert.deepEqual(pqPlus.pushRecent(['1', '2', '3', '4', '5'], 9, 5), ['9', '1', '2', '3', '4']);
  const areas = [{ code: 'KAP', name: 'Klinikum am Park' }, { code: 'SWN', name: 'Stadtwerke Nordhafen' }, { code: 'K1', name: 'Kunde Nord (Beispiel)' }];
  assert.deepEqual(pqPlus.filterAreas(areas, 'park').map(a => a.code), ['KAP']);
  assert.deepEqual(pqPlus.filterAreas(areas, 'k').map(a => a.code), ['KAP', 'K1', 'SWN'], 'Kürzel-Treffer zuerst');
  assert.deepEqual(pqPlus.filterAreas(areas, 'nord kunde').map(a => a.code), ['K1']);
  assert.equal(pqPlus.filterAreas(areas, '').length, 3);
});
