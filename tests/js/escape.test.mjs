// Regressionstest S-05: esc() muss Text UND Attributwerte sicher maskieren.
// Lädt nur die drei Hilfsfunktionen aus public/assets/js/app.js.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../public/assets/js/app.js', import.meta.url), 'utf8');
const pick = (name) => {
  const m = src.match(new RegExp('function ' + name + '\\([^)]*\\)\\{[^\\n]*\\}'));
  assert.ok(m, name + ' nicht gefunden');
  return m[0];
};
const { esc, safeUrl, safeColor } = new Function(pick('esc') + pick('safeUrl') + pick('safeColor') + 'return {esc,safeUrl,safeColor};')();

test('esc maskiert Anführungszeichen (Attribut-Ausbruch)', () => {
  assert.equal(esc('" onmouseover="x'), '&quot; onmouseover=&quot;x');
  assert.equal(esc("a'b"), 'a&#39;b');
  assert.equal(esc('<b>&</b>'), '&lt;b&gt;&amp;&lt;/b&gt;');
  assert.equal(esc(null), '');
  assert.equal(esc(0), '0');
});

test('safeUrl lässt nur http(s) zu', () => {
  assert.equal(safeUrl('javascript:alert(1)'), '');
  assert.equal(safeUrl(' JaVaScRiPt:alert(1)'), '');
  assert.equal(safeUrl('https://example.org/x'), 'https://example.org/x');
});

test('safeColor lässt nur Hexfarben zu', () => {
  assert.equal(safeColor('#12ab3f'), '#12ab3f');
  assert.equal(safeColor('red" onclick="x'), '#94a3b8');
});
