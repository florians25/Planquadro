// Planungsassistent: reine Logik aus public/assets/js/assistant-core.js (ohne DOM).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const src = readFileSync(new URL('../../public/assets/js/assistant-core.js', import.meta.url), 'utf8');
const sandbox = {};
vm.runInNewContext(src, { globalThis: sandbox, window: sandbox });
const C = sandbox.PQACore;
/* Objekte aus dem vm-Kontext haben eigene Prototypen – für deepEqual normalisieren */
const J = (x) => JSON.parse(JSON.stringify(x));

const BOOT = { today: '2026-10-29', defaults: { demand_default: 3, demand_min: 0, azubi_years: [1], free_areas: [] },
  general: [{ id: 7, default_free: true }, { id: 8, default_free: false }] };
const day = (date, o = {}) => Object.assign({ date, wd: C.weekday(date), label: date, weekend: C.weekday(date) >= 6,
  required: 2, skip: false, reason: '', holiday: null }, o);
const person = (id, o = {}) => Object.assign({ id, name: 'P' + id, azubi_year: 0, os_rank: 0, area_id: 0, weekend: false, days: {} }, o);
const perm = { absence: false };

test('Datumshilfen', () => {
  assert.equal(C.addDays('2026-10-31', 1), '2026-11-01');
  assert.equal(C.daysBetween('2026-11-01', '2026-11-30'), 29);
  assert.equal(C.weekday('2026-11-02'), 1);
  assert.equal(C.weekday('2026-11-08'), 7);
  assert.equal(C.isoWeek('2026-11-02'), 45);
  assert.equal(C.isoWeek('2027-01-01'), 53);
  assert.equal(C.dmy('2026-11-02'), '02.11.2026');
  assert.equal(C.shortName('Mustermann, Maximilian'), 'Mustermann, M.');
  assert.equal(C.esc('<a href="x">'), '&lt;a href=&quot;x&quot;&gt;');
});

test('Voreinstellung Zeitraum: Rest des Monats oder nächster Monat', () => {
  assert.deepEqual(J(C.defaultRange('2026-10-05')), { start: '2026-10-05', end: '2026-10-31', preset: 'rest' });
  assert.deepEqual(J(C.defaultRange('2026-10-29')), { start: '2026-11-01', end: '2026-11-30', preset: 'next' });
  const p = C.presets('2026-10-29');
  assert.deepEqual(J(p.map((x) => x.id)), ['rest', 'next', 'next2', '2w']);
  assert.equal(p[1].label, 'November 2026');
  assert.equal(p[2].end, '2026-12-31');
  assert.equal(p[3].end, '2026-11-11');
  const ri = C.rangeInfo('2026-11-01', '2026-11-30', [1, 2, 3, 4, 5]);
  assert.deepEqual(J([ri.days, ri.active, ri.capped]), [30, 21, false]);
  assert.equal(C.rangeInfo('2026-01-01', '2026-12-31', [1]).capped, true);
});

test('Vorgaben und Zusammenführen älterer Stände', () => {
  const d = C.defaults(BOOT);
  assert.equal(d.demand_default, 3);
  assert.equal(d.demand_min, 0);
  assert.deepEqual(J(d.free_areas), [7], 'Vorschlag des Servers, wenn in den Einstellungen nichts steht');
  assert.deepEqual(J(d.azubi.years), [1]);
  const m = C.mergeConfig(BOOT, { area_id: '5', azubi: { enabled: false }, weekdays: ['1', '2'], demand_by_weekday: [] });
  assert.equal(m.area_id, 5);
  assert.equal(m.azubi.enabled, false);
  assert.equal(m.azubi.supervision_ratio, 2, 'fehlende Felder aus den Vorgaben');
  assert.deepEqual(J(m.weekdays), [1, 2]);
  assert.deepEqual(J(m.demand_by_weekday), {});
  assert.deepEqual(J(C.mergeConfig(BOOT, { free_areas: [] }).free_areas), [], 'bewusst leere Auswahl bleibt');
  assert.deepEqual(J(C.mergeConfig(BOOT, { free_areas: [] }, { fillFree: true }).free_areas), [7], 'alter Browser-Zwischenstand');
  const S = { start: '2026-09-01', end: '2026-09-10' };
  assert.equal(C.shiftToToday(S, '2026-10-29'), true);
  assert.deepEqual(J(S), { start: '2026-10-29', end: '2026-11-07' });
});

test('Verfügbarkeit spiegelt die Serverregeln', () => {
  const S = C.defaults(BOOT); S.area_id = 1; S.free_areas = [7];
  const p = person(1, { days: {
    '2026-11-02': { t: 'gesperrt', l: 'Gesperrt' }, '2026-11-03': { t: 'schule', l: 'BS' },
    '2026-11-04': { t: 'abwesend', l: 'Urlaub' }, '2026-11-05': { t: 'einsatz', l: 'T', a: 7 },
    '2026-11-06': { t: 'einsatz', l: 'K2', a: 2 } } });
  assert.equal(C.avail(p, day('2026-11-02'), S, perm).skip, true);
  assert.equal(C.avail(p, day('2026-11-03'), S, perm).hard, true);
  assert.equal(C.avail(p, day('2026-11-04'), S, perm).ok, false);
  S.rules.overwrite = 'all';
  assert.equal(C.avail(p, day('2026-11-04'), S, perm).ok, false, 'ohne Recht wirkungslos');
  assert.equal(C.avail(p, day('2026-11-04'), S, { absence: true }).ok, true);
  S.rules.overwrite = 'none';
  assert.equal(C.avail(p, day('2026-11-05'), S, perm).code, 'frei_intern');
  assert.equal(C.avail(p, day('2026-11-06'), S, perm).replaceable, true);
  S.force_cells = ['1|2026-11-06'];
  assert.equal(C.avail(p, day('2026-11-06'), S, perm).code, 'ueberschreibt');
  assert.equal(C.avail(person(2), day('2026-11-07'), S, perm).code, 'wochenende');
  assert.equal(C.avail(person(3, { azubi_year: 2 }), day('2026-11-09'), S, perm).code, 'lehrjahr');
});

test('Prüfung: Unterdeckung, Azubi allein, Serie, fremder Entwurf', () => {
  const S = C.defaults(BOOT); S.area_id = 1; S.demand_min = 2; S.rules.max_consecutive = 1;
  const days = [day('2026-11-02'), day('2026-11-03'), day('2026-11-04', { skip: true, required: 0, reason: 'Kein Bedarf' })];
  const R = { days, people: [person(1), person(2, { azubi_year: 1 }),
    person(3, { days: { '2026-11-03': { t: 'einsatz', l: 'K2 •', a: 2, o: 1 } } })],
    plan: { '2026-11-02': [1], '2026-11-03': [1, 2], '2026-11-04': [] } };
  S.rules.overwrite = 'assignments';
  R.plan['2026-11-03'].push(3);
  const v = C.validate(R, S, perm);
  const codes = v.conflicts.map((c) => c.code);
  assert.ok(codes.includes('unterdeckung'));
  assert.ok(codes.includes('serie'));
  assert.ok(codes.includes('fremder_entwurf'));
  assert.equal(v.stats.total, 4);
  assert.equal(v.stats.filled, 3);
  assert.equal(v.stats.coverage, 75);
  assert.equal(v.load[1], 2);
  assert.equal(v.conflicts[0].level, 'block', 'Blocker zuerst');
  assert.ok(C.explain(v.conflicts.find((c) => c.code === 'unterdeckung')).length > 10, 'jeder Punkt erklärt, was zu tun ist');

  const R2 = { days: [day('2026-11-02')], people: [person(2, { azubi_year: 1 })], plan: { '2026-11-02': [2] } };
  assert.ok(C.validate(R2, S, perm).conflicts.some((c) => c.code === 'azubi_allein'));
  S.force_cells = ['3|2026-11-03'];
  assert.ok(!C.validate(R, S, perm).conflicts.some((c) => c.code === 'fremder_entwurf'), 'freigegeben = kein Hinweis');
});

test('Kennzahlen und Überschrift der Übernahme', () => {
  const S = C.defaults(BOOT); S.area_id = 1; S.free_areas = [7];
  const R = { days: [day('2026-11-02'), day('2026-11-03')], stats: { filled: 3 },
    people: [person(1, { area_id: 1, days: { '2026-11-02': { t: 'einsatz', a: 7, l: 'T' } } }), person(2, { azubi_year: 1 })],
    plan: { '2026-11-02': [1, 2], '2026-11-03': [1] } };
  const m = C.summary(R, S);
  assert.deepEqual(J([m.entries, m.people, m.home, m.azubi, m.fromFree, m.planDays]), [3, 2, 2, 1, 1, 2]);
  assert.equal(C.applyHeadline({ written: 27, people: 9, skipped_total: 3 }), '27 Einsätze für 9 Personen, 3 Konflikte übersprungen');
  assert.equal(C.applyHeadline({ written: 1, people: 1, skipped_total: 0 }), '1 Einsatz für 1 Person');
  assert.equal(C.dayLevel(1, day('x', { required: 2 }), { demand_min: 1 }), 'part');
  assert.equal(C.dayLevel(0, day('x', { required: 2 }), { demand_min: 1 }), 'bad');
});

test('Personenfilter und Vorschlagsreihenfolge', () => {
  const list = [
    { id: 1, name: 'Berger, Jonas', os: 'L2', prof: '', area_code: 'SWN', role_label: 'Techniker', role: 'mitarbeiter', area_id: 1, home: true, exp: 2, azubi_year: 0, weekend: true, bond: 0 },
    { id: 2, name: 'Koch, Ben', os: '', prof: 'ITSE', area_code: 'SWN', role_label: 'Azubi', role: 'azubi_1', area_id: 1, home: false, exp: 0, azubi_year: 1, weekend: false, bond: 0.8 },
    { id: 3, name: 'Arndt, Anna', os: 'L3', prof: '', area_code: 'KAP', role_label: 'Techniker', role: 'mitarbeiter', area_id: 2, home: false, exp: 5, azubi_year: 0, weekend: false, bond: 0.2 }
  ];
  assert.deepEqual(J(C.filterPeople(list, { sort: 'best' }).map((p) => p.id)), [1, 3, 2]);
  assert.deepEqual(J(C.filterPeople(list, { sort: 'name' }).map((p) => p.id)), [3, 1, 2]);
  assert.deepEqual(J(C.filterPeople(list, { sort: 'os' }).map((p) => p.id)), [3, 1, 2]);
  assert.deepEqual(J(C.filterPeople(list, { q: 'jon ber' }).map((p) => p.id)), [1], 'Wörter in beliebiger Reihenfolge');
  assert.deepEqual(J(C.filterPeople(list, { noAz: true, we: true }).map((p) => p.id)), [1]);
  assert.deepEqual(J(C.filterPeople(list, { flex: true }).map((p) => p.id)), [1, 3]);
  assert.deepEqual(J(C.filterPeople(list, { area: '0' }).map((p) => p.id)), []);
  const s = C.sortSuggest([{ id: 1, ok: false, reason: 'Urlaub', name: 'A' }, { id: 2, ok: true, reason: 'frei', name: 'B' },
    { id: 3, ok: true, reason: 'verplanbar – ersetzt T', name: 'C' }]);
  assert.deepEqual(J(s.map((x) => x.id)), [3, 2, 1]);
});

test('Tastatursteuerung im Raster', () => {
  assert.deepEqual(J(C.gridMove(0, 0, 'ArrowRight', 3, 5)), [0, 1]);
  assert.deepEqual(J(C.gridMove(0, 4, 'ArrowRight', 3, 5)), [0, 4]);
  assert.deepEqual(J(C.gridMove(2, 2, 'ArrowDown', 3, 5)), [2, 2]);
  assert.deepEqual(J(C.gridMove(1, 3, 'Home', 3, 5)), [1, 0]);
  assert.deepEqual(J(C.gridMove(1, 3, 'End', 3, 5, true)), [2, 4]);
  assert.deepEqual(J(C.gridMove(0, 0, 'PageDown', 30, 5)), [10, 0]);
  assert.equal(C.gridMove(0, 0, 'a', 3, 5), null);
  assert.equal(C.gridMove(0, 0, 'ArrowUp', 0, 0), null);
});
