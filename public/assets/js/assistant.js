/* =========================================================================
   Planquadro – Planungsassistent: Oberfläche
   -------------------------------------------------------------------------
   Ablauf ① Zeitraum & Team → ② Bedarf & Regeln → ③ Vorschlag prüfen →
   ④ Übernehmen. Reine Logik steht in assistant-core.js (PQACore).
   CSP: keine Inline-Handler; Farben werden per CSSOM gesetzt (data-css →
   element.style.cssText), Startdaten kommen aus <script type="application/json">.
   ========================================================================= */
(function () {
  'use strict';

  var C = window.PQACore;
  var bootEl = document.getElementById('pqaBoot');
  if (!C || !bootEl) return;
  var BOOT = JSON.parse(bootEl.textContent || '{}');
  var $ = function (id) { return document.getElementById(id); };
  var esc = C.esc, dmy = C.dmy, WD = C.WD;
  var KEY = 'epa_cfg_v1';
  var LAST = 3;

  var AREA = {}, ROLE = {}, USER = {};
  BOOT.areas.forEach(function (a) { AREA[a.id] = a; });
  BOOT.roles.forEach(function (r) { ROLE[r.role_key] = r; });
  BOOT.users.forEach(function (u) { USER[u.id] = u; });

  /* ===================================================== Zustand */
  var S = C.defaults(BOOT);    // Konfiguration
  var R = null;                // Vorschlag {days, people, plan, area, load, stats, manual}
  var step = 0, maxSeen = 0;
  var view = 'matrix', plannedOnly = true, cfFilter = 'all', conflicts = [];
  var lastRun = null;          // {id, written} – letzte Übernahme dieser Sitzung
  var applied = null;          // Ergebnis der letzten Übernahme (Schritt 4)
  var plan = { id: 0, name: '', shared: 0, own: true, applied: null };
  var profile = { id: 0, name: '', own: false };
  var CAND = null, candKey = '';
  var rowOrder = null, formDirty = false, unsaved = false;
  var preview = null, previewKey = '';
  var genSeq = 0, liveTimer = null, prevSeq = 0;
  var focusCell = null, gridIdx = [];
  var daysOnlyGaps = false;

  /* ===================================================== Hilfsmittel */
  function announce(msg) {
    var l = $('pqaLive'); if (!l) return;
    l.textContent = ''; setTimeout(function () { l.textContent = msg; }, 40);
  }
  function say(msg, type) {
    if (typeof window.toast === 'function') window.toast(msg, type || 'info'); else announce(msg);
  }
  function busy(on, txt) {
    if (txt) $('busyTxt').textContent = txt;
    $('busy').hidden = !on;
  }
  /* Farben per CSSOM – CSP-tauglich ohne style-Attribute im Markup */
  function paint(root) {
    (root || document).querySelectorAll('[data-css]').forEach(function (el) {
      el.style.cssText = el.getAttribute('data-css');
      el.removeAttribute('data-css');
    });
  }
  function css(s) { return ' data-css="' + esc(s) + '"'; }
  function oM(id) { if (typeof window.oMod === 'function') window.oMod(id); else $(id).classList.add('open'); }
  function cM(id) { if (typeof window.cMod === 'function') window.cMod(id); else $(id).classList.remove('open'); }

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(location.pathname + location.search, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.text(); }).then(function (t) {
      try { return JSON.parse(t); }
      catch (e) { throw new Error('Unerwartete Antwort des Servers. Besteht die Sitzung noch?'); }
    });
  }

  /** Eigener Bestätigungsdialog (statt confirm()) – liefert ein Promise<boolean>. */
  function ask(o) {
    return new Promise(function (resolve) {
      $('askTitle').textContent = o.title || 'Bitte bestätigen';
      $('askBody').innerHTML = o.html || ('<p>' + esc(o.text || '') + '</p>');
      var yes = $('askYes'), no = $('askNo');
      yes.textContent = o.ok || 'OK';
      yes.className = 'btn ' + (o.danger ? 'btn-danger' : 'btn-primary');
      no.textContent = o.cancel || 'Abbrechen';
      var done = function (v) {
        yes.onclick = null; no.onclick = null; cM('ovAsk'); resolve(v);
      };
      yes.onclick = function () { done(true); };
      no.onclick = function () { done(false); };
      $('ovAsk').querySelector('[data-close]').onclick = function () { done(false); };
      oM('ovAsk');
      setTimeout(function () { (o.focusOk ? yes : no).focus(); }, 40);
    });
  }

  function azYear(role) {
    var m = /^azubi_(\d)$/.exec(role || '');
    if (m) return +m[1];
    m = /^azubi_(\d)$/.exec((ROLE[role] || {}).base_role || '');
    return m ? +m[1] : 0;
  }
  function roleColor(r) { return (ROLE[r] || {}).color || '#6b7280'; }
  function initials(u) { return ((u.first_name || ' ')[0] + (u.last_name || ' ')[0]).toUpperCase(); }
  function areaChip(a, cls) {
    if (!a || !a.short_code) return '';
    return '<span class="pqa-area ' + (cls || '') + '"' + css('background:' + (a.color || '#64748b') + ';color:' + (a.fg_color || '#fff')) + '>' + esc(a.short_code) + '</span>';
  }
  function dmyt(v) {
    if (!v) return '–';
    var p = String(v).split(' ');
    return dmy(p[0]) + (p[1] ? ', ' + p[1].slice(0, 5) + ' Uhr' : '');
  }
  function findDay(date) { for (var i = 0; R && i < R.days.length; i++) if (R.days[i].date === date) return R.days[i]; return null; }
  function findPerson(uid) { for (var i = 0; R && i < R.people.length; i++) if (R.people[i].id === uid) return R.people[i]; return null; }
  function persist() { try { sessionStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {} }

  /* ===================================================== Formular */
  var FIELDS = [
    ['fTitle', 'title', 's'], ['fArea', 'area_id', 'i'], ['fStart', 'start', 's'], ['fEnd', 'end', 's'],
    ['fSkipHol', 'skip_holidays', 'b'], ['fRemarks', 'remarks', 's'], ['fInternal', 'internal', 'b'],
    ['fDemand', 'demand_default', 'i'], ['fDemandMin', 'demand_min', 'i'],
    ['fOsLevel', 'req_os_level', 's'], ['fOsCount', 'req_os_count', 'i'], ['fHomeCount', 'req_home_count', 'i'],
    ['fUseExp', 'use_experience', 'b'],
    ['fRespectBond', 'respect_bond', 'b'], ['fPreferFree', 'prefer_free', 'b'],
    ['azOn', 'azubi.enabled', 'b'], ['azMax', 'azubi.max_per_day', 'i'], ['azMinDay', 'azubi.min_per_day', 'i'],
    ['azShare', 'azubi.max_share', 'i'],
    ['azRatio', 'azubi.supervision_ratio', 'i'], ['azAlone', 'azubi.never_alone', 'b'],
    ['azSchool', 'azubi.skip_school_days', 'b'], ['azWe', 'azubi.no_weekend', 'b'],
    ['rMaxStreak', 'rules.max_consecutive', 'i'], ['rMaxTotal', 'rules.max_days_total', 'i'],
    ['rWeekend', 'rules.weekend_flag', 'b'],
    ['wCont', 'weights.continuity', 'i'], ['wFair', 'weights.fairness', 'i'],
    ['wHome', 'weights.home_area', 'i'], ['wOs', 'weights.os', 'i']
  ];
  var RADIOS = [['fHomeModeR', 'home_mode'], ['rOverwriteR', 'rules.overwrite'], ['fScope', 'cross_team']];
  function getP(o, p) { return p.split('.').reduce(function (a, k) { return a ? a[k] : undefined; }, o); }
  function setP(o, p, v) { var k = p.split('.'), t = o; for (var i = 0; i < k.length - 1; i++) t = t[k[i]]; t[k[k.length - 1]] = v; }

  function buildAreas() {
    var g = { kunde: [], allgemein: [] };
    BOOT.areas.forEach(function (a) { (g[a.area_type] || g.kunde).push(a); });
    var h = '<option value="">– bitte wählen –</option>';
    if (g.kunde.length) {
      h += '<optgroup label="Kunden / Standorte">';
      g.kunde.forEach(function (a) { h += '<option value="' + a.id + '">' + esc(a.short_code + ' – ' + a.name) + '</option>'; });
      h += '</optgroup>';
    }
    if (g.allgemein.length) {
      h += '<optgroup label="Allgemein">';
      g.allgemein.forEach(function (a) { h += '<option value="' + a.id + '">' + esc(a.short_code + ' – ' + a.name) + '</option>'; });
      h += '</optgroup>';
    }
    $('fArea').innerHTML = h;
  }

  function toggleHtml(attr, value, label, extra) {
    return '<label class="pqa-tg"><input type="checkbox" ' + attr + '="' + esc(value) + '"><span>' + label + '</span>' + (extra || '') + '</label>';
  }
  function buildToggles() {
    var h = '';
    for (var d = 1; d <= 7; d++) h += toggleHtml('data-wd', d, WD[d]);
    $('fWeekdays').innerHTML = h;
    $('fRoles').innerHTML = BOOT.roles.length ? BOOT.roles.map(function (r) {
      return toggleHtml('data-v', r.role_key, esc(r.role_label));
    }).join('') : '<span class="muted">Keine Rollen vorhanden.</span>';
    $('fProfs').innerHTML = BOOT.profs.length ? BOOT.profs.map(function (p) {
      return toggleHtml('data-v', p, esc(p));
    }).join('') : '<span class="muted">Keine Ausbildungsberufe hinterlegt.</span>';
    var gen = BOOT.general || [];
    $('fFreeAreas').innerHTML = gen.length ? gen.map(function (a) {
      return toggleHtml('data-free', a.id, esc(a.short_code) + ' <small>' + esc(a.name) + '</small>');
    }).join('') : '<span class="muted">Keine Allgemein-Bereiche vorhanden.</span>';
    var os = '<option value="">Keine Vorgabe</option>';
    BOOT.os.forEach(function (o) { os += '<option value="' + esc(o) + '">' + esc(o) + '</option>'; });
    $('fOsLevel').innerHTML = os;
    var a = '<option value="">Alle Stammbereiche</option>';
    BOOT.areas.forEach(function (x) { a += '<option value="' + x.id + '">' + esc(x.short_code) + '</option>'; });
    a += '<option value="0">Ohne Stammbereich</option>';
    $('fFltArea').innerHTML = a;
    var r = '<option value="">Alle Rollen</option>';
    BOOT.roles.forEach(function (x) { r += '<option value="' + esc(x.role_key) + '">' + esc(x.role_label) + '</option>'; });
    $('fFltRole').innerHTML = r;
  }

  function buildPresets() {
    $('fPresets').innerHTML = C.presets(BOOT.today).map(function (p) {
      return '<button type="button" class="pqa-pill" data-preset="' + p.id + '" data-s="' + p.start + '" data-e="' + p.end + '" aria-pressed="false">' +
             esc(p.label) + '</button>';
    }).join('');
  }
  function paintPresets() {
    $('fPresets').querySelectorAll('[data-preset]').forEach(function (b) {
      var on = b.dataset.s === S.start && b.dataset.e === S.end;
      b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function buildDemandGrid() {
    var h = '';
    for (var d = 1; d <= 7; d++) {
      var off = S.weekdays.indexOf(d) < 0, v = S.demand_by_weekday[d];
      h += '<label class="pqa-dcell' + (off ? ' is-off' : '') + '"><b>' + WD[d] + '</b>' +
           '<input type="number" min="0" max="99" data-wd="' + d + '"' + (off ? ' disabled' : '') +
           ' value="' + (v === undefined || v === null ? '' : v) + '" placeholder="' + S.demand_default + '" aria-label="Bedarf ' + WD[d] + '"></label>';
    }
    $('fDemandWd').innerHTML = h;
  }

  function buildSpecial() {
    var box = $('fSpecial');
    if (!S.special_days.length) {
      box.innerHTML = '<p class="muted pqa-small">Noch keine Sondertage – überall gilt der Regelbedarf.</p>';
      return;
    }
    box.innerHTML = S.special_days.map(function (sd, i) {
      return '<div class="pqa-sd">' +
        '<input type="date" data-f="date" data-i="' + i + '" value="' + esc(sd.date) + '" aria-label="Datum">' +
        '<input type="number" min="0" max="99" data-f="count" data-i="' + i + '" value="' + (+sd.count) + '" aria-label="Personen an diesem Tag" title="Personen an diesem Tag – 0 nimmt den Tag heraus">' +
        '<input type="text" data-f="note" data-i="' + i + '" value="' + esc(sd.note || '') + '" placeholder="Notiz, z. B. Go-Live" aria-label="Notiz">' +
        '<button type="button" class="btn btn-xs btn-outline" data-rm="' + i + '" aria-label="Sondertag entfernen">Entfernen</button></div>';
    }).join('');
  }

  /* ---------------- Personenliste (Fest / Raus) ---------------- */
  function peopleSource() {
    return CAND || BOOT.users.map(function (u) {
      return { id: +u.id, name: u.last_name + ', ' + u.first_name, role: u.role,
               role_label: (ROLE[u.role] || {}).role_label || u.role, role_color: roleColor(u.role),
               azubi_year: azYear(u.role), os: u.os_level || '', prof: u.training_profession || '',
               area_id: +(u.primary_area_id || 0), area_code: (AREA[u.primary_area_id] || {}).short_code || '',
               home: +(u.primary_area_id || 0) === +S.area_id, exp: 0, exp_last: null,
               bond: 0, bond_code: '', variety: 0, weekend: false };
    });
  }
  function filteredPeople() {
    return C.filterPeople(peopleSource(), {
      q: $('fPersonSearch').value, area: $('fFltArea').value, role: $('fFltRole').value,
      home: $('fFltHome').checked, exp: $('fFltExp').checked, noAz: $('fFltNoAz').checked,
      we: $('fFltWe').checked, flex: $('fFltFlex').checked, sort: $('fFltSort').value
    });
  }
  function buildPeople() {
    var rows = filteredPeople(), total = peopleSource().length;
    $('fPeopleCnt').textContent = rows.length === total ? rows.length + ' Personen' : rows.length + ' von ' + total + ' Personen';
    if (!rows.length) { $('fPeople').innerHTML = '<p class="muted pqa-small">Keine Person passt zu den Filtern.</p>'; return; }
    $('fPeople').innerHTML = rows.map(function (p) {
      var fix = S.include_users.indexOf(+p.id) >= 0, out = S.exclude_users.indexOf(+p.id) >= 0;
      var area = AREA[p.area_id], tags = '';
      if (p.home) tags += '<span class="pqa-tag pqa-tag-home" title="Betreut diesen Kunden als Stammbereich">Stammbereich</span>';
      else if (area) tags += areaChip(area, 'pqa-area-sm');
      if (p.exp > 0) tags += '<span class="pqa-tag pqa-tag-exp" title="Einsätze bei diesem Kunden in den letzten Monaten' +
                             (p.exp_last ? ', zuletzt ' + dmy(p.exp_last) : '') + '">' + p.exp + '× dort</span>';
      if (p.bond >= 0.6 && p.bond_code && !p.home)
        tags += '<span class="pqa-tag pqa-tag-bond" title="Ist überwiegend bei diesem Kunden – wird nur ungern abgezogen">fest bei ' +
                esc(p.bond_code) + ' (' + Math.round(p.bond * 100) + '%)</span>';
      else if (p.variety >= 3)
        tags += '<span class="pqa-tag pqa-tag-flex" title="Wechselt häufig zwischen Kunden – gut umplanbar">wechselt oft</span>';
      if (p.os) tags += '<span class="pqa-tag">' + esc(p.os) + '</span>';
      if (p.prof) tags += '<span class="pqa-tag">' + esc(p.prof) + '</span>';
      tags += '<span class="pqa-tag pqa-tag-role">' + esc(p.azubi_year ? 'Azubi ' + p.azubi_year + '. LJ' : p.role_label) + '</span>';
      return '<div class="pqa-person' + (fix ? ' is-fix' : '') + (out ? ' is-out' : '') + '">' +
        '<span class="pqa-person-n">' + esc(p.name) + '</span>' +
        '<span class="pqa-person-t">' + tags + '</span>' +
        '<span class="pqa-person-a" role="group" aria-label="' + esc(p.name) + '">' +
          '<button type="button" class="btn btn-xs ' + (fix ? 'btn-primary' : 'btn-outline') + '" data-fix="' + p.id + '" aria-pressed="' + fix + '" title="An jedem möglichen Tag einplanen">Fest</button>' +
          '<button type="button" class="btn btn-xs ' + (out ? 'btn-danger' : 'btn-outline') + '" data-out="' + p.id + '" aria-pressed="' + out + '" title="Von der Planung ausschließen">Raus</button>' +
        '</span></div>';
    }).join('');
    paint($('fPeople'));
  }
  function loadCandidates() {
    if (!S.area_id) { CAND = null; candKey = ''; buildPeople(); return; }
    var k = S.area_id + '|' + (S.cross_team ? 1 : 0);
    if (candKey === k && CAND) { buildPeople(); return; }
    post('candidates', { config: JSON.stringify(S) }).then(function (d) {
      if (!d.success) { CAND = null; buildPeople(); return; }
      CAND = d.items; candKey = k; buildPeople();
    }).catch(function () { CAND = null; buildPeople(); });
  }
  function bulk(kind) {
    var rows = filteredPeople();
    if (!rows.length) { say('Die Liste ist leer.', 'warning'); return; }
    rows.forEach(function (p) {
      var id = +p.id;
      var mine = kind === 'fix' ? S.include_users : S.exclude_users;
      var other = kind === 'fix' ? S.exclude_users : S.include_users;
      if (mine.indexOf(id) < 0) mine.push(id);
      var j = other.indexOf(id); if (j >= 0) other.splice(j, 1);
    });
    buildPeople(); touched('form');
    say(rows.length + (kind === 'fix' ? ' Personen fest eingeplant.' : ' Personen ausgeschlossen.'), 'info');
  }

  /* ---------------- Formular ↔ Zustand ---------------- */
  function writeForm() {
    FIELDS.forEach(function (f) {
      var el = $(f[0]); if (!el) return;
      var v = getP(S, f[1]);
      if (f[2] === 'b') el.checked = !!v; else el.value = (v === undefined || v === null) ? '' : v;
    });
    $('fArea').value = S.area_id ? String(S.area_id) : '';
    RADIOS.forEach(function (r) {
      var v = getP(S, r[1]);
      if (r[1] === 'cross_team') v = v ? 'cross' : 'own';
      document.querySelectorAll('input[name="' + r[0] + '"]').forEach(function (i) { i.checked = i.value === String(v); });
    });
    $('fWeekdays').querySelectorAll('[data-wd]').forEach(function (i) { i.checked = S.weekdays.indexOf(+i.dataset.wd) >= 0; });
    $('azYears').querySelectorAll('[data-year]').forEach(function (i) { i.checked = S.azubi.years.indexOf(+i.dataset.year) >= 0; });
    $('fRoles').querySelectorAll('[data-v]').forEach(function (i) { i.checked = S.roles.indexOf(i.dataset.v) >= 0; });
    $('fProfs').querySelectorAll('[data-v]').forEach(function (i) { i.checked = S.req_professions.indexOf(i.dataset.v) >= 0; });
    $('fFreeAreas').querySelectorAll('[data-free]').forEach(function (i) { i.checked = S.free_areas.indexOf(+i.dataset.free) >= 0; });
    buildDemandGrid(); buildSpecial(); buildPeople();
    paintTitle(); paintRange(); paintPresets(); paintWeights(); paintOwHint(); paintHomeHint(); paintAz();
    paintSummaries(); paintRecap();
  }

  function after(path) {
    if (path === 'title') paintTitle();
    if (path === 'area_id') { paintTitle(); setAreaError(false); }
    if (path === 'area_id' || path === 'start' || path === 'end') { paintRange(); paintPresets(); loadCandidates(); }
    if (path === 'home_mode') paintHomeHint();
    if (path === 'cross_team') {
      CAND = null; candKey = ''; loadCandidates();
      say(S.cross_team ? 'Teamübergreifende Planung aktiv – bitte abstimmen.' : 'Wieder auf das eigene Team beschränkt.',
          S.cross_team ? 'warning' : 'info');
    }
    if (path === 'demand_default') buildDemandGrid();
    if (path === 'azubi.enabled') paintAz();
    if (path.indexOf('weights.') === 0) paintWeights();
    if (path === 'rules.overwrite') paintOwHint();
    if (path === 'start' && S.end < S.start) { S.end = S.start; $('fEnd').value = S.end; paintRange(); }
  }

  function paintTitle() {
    var a = AREA[S.area_id];
    $('epaSub').textContent = S.title || (a ? a.name : 'Neue Planung');
  }
  function paintWeights() {
    $('wContV').textContent = S.weights.continuity; $('wFairV').textContent = S.weights.fairness;
    $('wHomeV').textContent = S.weights.home_area;  $('wOsV').textContent = S.weights.os;
  }
  function paintAz() {
    var box = $('azBox');
    box.classList.toggle('is-off', !S.azubi.enabled);
    box.querySelectorAll('input').forEach(function (i) { i.disabled = !S.azubi.enabled; });
  }
  function paintOwHint() {
    var h = $('rOverwriteHint');
    if (S.rules.overwrite === 'none') h.textContent = 'Sicherste Variante: Tage mit vorhandenem Eintrag bleiben unverändert.';
    else if (S.rules.overwrite === 'assignments') h.textContent = 'Einsätze anderer Bereiche werden ersetzt. Urlaub, Krankheit und Sperrtage bleiben unangetastet.';
    else h.textContent = BOOT.perm.absence
      ? 'Auch Urlaub und Krankmeldungen werden überschrieben – bitte nur nach Rücksprache.'
      : 'Ihnen fehlt das Recht, Abwesenheiten zu überschreiben. Es wirkt wie „Andere Einsätze ersetzen“.';
  }
  function paintHomeHint() {
    var h = $('fHomeModeHint');
    if (S.home_mode === 'only') h.textContent = 'Strikt: nur wer den Kunden als Stammbereich hat oder dort schon im Einsatz war. Fest eingeplante Personen bleiben davon unberührt.';
    else if (S.home_mode === 'off') h.textContent = 'Der Stammbereich fließt nicht in die Auswahl ein.';
    else h.textContent = 'Empfohlen: Stammbereich und Kundenerfahrung zählen deutlich, andere füllen auf.';
  }
  function paintRange() {
    var c = $('epaRange');
    if (!S.start || !S.end) { c.textContent = 'Zeitraum offen'; return; }
    var info = C.rangeInfo(S.start, S.end, S.weekdays);
    var a = AREA[S.area_id];
    c.textContent = (a ? a.short_code + ' · ' : '') + dmy(S.start) + ' – ' + dmy(S.end) + ' · ' + info.days + ' Tage';
    var r = $('rangeInfo');
    if (S.end < S.start) { r.textContent = 'Das Enddatum liegt vor dem Start.'; r.classList.add('pqa-hint-warn'); return; }
    r.classList.toggle('pqa-hint-warn', info.capped);
    r.textContent = info.days + ' Kalendertage, davon ' + info.active + ' an Einsatztagen' +
      (info.capped ? ' – mehr als 120 Tage: der Lauf wird nach 120 Tagen abgeschnitten. Längere Projekte in Etappen planen.'
                   : '. Höchstens 120 Tage je Lauf.');
  }
  function setAreaError(on) {
    var f = $('fArea'), id = 'fAreaErr', el = $(id);
    f.setAttribute('aria-invalid', on ? 'true' : 'false');
    if (on && !el) {
      el = document.createElement('p'); el.id = id; el.className = 'pqa-err';
      el.textContent = 'Bitte wählen Sie den Einsatzbereich, für den geplant wird.';
      f.parentNode.appendChild(el); f.setAttribute('aria-describedby', id);
    } else if (!on && el) { el.remove(); f.removeAttribute('aria-describedby'); }
  }

  /** Kurzfassungen an Schrittleiste und eingeklappten Bereichen. */
  function paintSummaries() {
    var a = AREA[S.area_id];
    $('stepSum0').textContent = a ? a.short_code + ' · ' + C.dm(S.start) + '–' + C.dm(S.end) + (S.cross_team ? ' · teamübergreifend' : '') : 'Bereich und Zeitraum wählen';
    $('stepSum1').textContent = S.demand_default + ' pro Tag · min. ' + S.demand_min + (S.azubi.enabled ? ' · Azubis' : '');
    $('stepSum2').textContent = R && R.stats ? R.stats.coverage + ' % · ' + C.plural(R.stats.blocks, 'Blocker', 'Blocker') : 'Raster ansehen und anpassen';
    $('stepSum3').textContent = applied ? applied.written + ' Einträge übernommen' : 'als Entwurf speichern';
    var sel = S.include_users.length, out = S.exclude_users.length;
    $('sumPeople').textContent = (sel || out) ? sel + ' fest · ' + out + ' raus' : '';
    $('sumEntry').textContent = (S.remarks ? 'Bemerkung gesetzt' : '') + (S.internal ? (S.remarks ? ' · ' : '') + 'intern' : '');
    var wd = Object.keys(S.demand_by_weekday).filter(function (k) { return S.demand_by_weekday[k] !== '' && S.demand_by_weekday[k] !== null; });
    $('sumWd').textContent = wd.length ? wd.map(function (k) { return WD[k] + ' ' + S.demand_by_weekday[k]; }).join(' · ') : '';
    $('sumSpecial').textContent = S.special_days.length ? C.plural(S.special_days.length, 'Sondertag', 'Sondertage') : '';
    $('sumQual').textContent = (S.req_os_level ? S.req_os_level + (S.req_os_count ? ' × ' + S.req_os_count : '') : '') +
      (S.req_professions.length ? (S.req_os_level ? ' · ' : '') + S.req_professions.join(', ') : '');
    $('sumAz').textContent = 'max. ' + S.azubi.max_per_day + '/Tag · 1:' + S.azubi.supervision_ratio + (S.azubi.min_per_day ? ' · ' + S.azubi.min_per_day + ' reserviert' : '');
    var ow = { none: 'nichts überschreiben', assignments: 'Einsätze ersetzen', all: 'auch Abwesenheiten' }[S.rules.overwrite];
    $('sumExpert').textContent = S.rules.max_consecutive + ' am Stück · ' + S.rules.max_days_total + ' gesamt · ' + ow;
    document.querySelectorAll('.pqa-step').forEach(function (b) {
      var i = +b.dataset.step;
      var done = (i === 0 && S.area_id && S.start && S.end) || (i === 1 && maxSeen > 1) || (i === 2 && !!R) || (i === 3 && !!applied);
      b.classList.toggle('is-done', !!done && i !== step);
    });
    var saved = $('epaSaved');
    if (plan.id) {
      saved.hidden = false;
      saved.textContent = (unsaved ? 'Ungespeicherte Änderungen' : 'Gespeichert') + (plan.applied ? ' · übernommen' : '');
      saved.classList.toggle('pqa-chip-warn', unsaved);
    } else { saved.hidden = !unsaved || !R; saved.textContent = 'Nicht gespeichert'; saved.classList.add('pqa-chip-warn'); }
  }

  /** Jede Änderung: speichern im Browser, Prüfung/Ansichten auffrischen. */
  function touched(kind) {
    unsaved = true;
    preview = null;
    if (kind === 'form' && R) formDirty = true;
    persist();
    paintRecap();
    if (R) render(); else paintSummaries();
    if (kind === 'form') scheduleLive();
    paintFoot();
  }

  function bindForm() {
    FIELDS.forEach(function (f) {
      var el = $(f[0]); if (!el) return;
      var ev = (el.type === 'checkbox' || el.tagName === 'SELECT' || el.type === 'date') ? 'change' : 'input';
      el.addEventListener(ev, function () {
        var v = f[2] === 'b' ? el.checked : (f[2] === 'i' ? (+el.value || 0) : el.value);
        if (f[2] === 'i' && el.type === 'number' && el.min !== '' && el.value !== '') v = Math.max(+el.min, Math.min(+el.max || 99, v));
        setP(S, f[1], v);
        after(f[1]); touched('form');
      });
    });
    RADIOS.forEach(function (r) {
      document.querySelectorAll('input[name="' + r[0] + '"]').forEach(function (i) {
        i.addEventListener('change', function () {
          if (!i.checked) return;
          setP(S, r[1], r[1] === 'cross_team' ? i.value === 'cross' : i.value);
          after(r[1]); touched('form');
        });
      });
    });
    document.querySelectorAll('[data-step-for]').forEach(function (b) {
      b.addEventListener('click', function () {
        var el = $(b.dataset.stepFor);
        var v = Math.max(+el.min || 0, Math.min(+el.max || 99, (+el.value || 0) + (+b.dataset.d)));
        el.value = v; el.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
    $('fWeekdays').addEventListener('change', function (e) {
      var el = e.target.closest('[data-wd]'); if (!el) return;
      var d = +el.dataset.wd, i = S.weekdays.indexOf(d);
      if (el.checked && i < 0) S.weekdays.push(d);
      if (!el.checked && i >= 0) S.weekdays.splice(i, 1);
      if (!S.weekdays.length) { S.weekdays = [d]; el.checked = true; say('Mindestens ein Wochentag muss aktiv bleiben.', 'warning'); }
      S.weekdays.sort();
      buildDemandGrid(); paintRange(); touched('form');
    });
    [['fRoles', 'roles'], ['fProfs', 'req_professions']].forEach(function (x) {
      $(x[0]).addEventListener('change', function (e) {
        var el = e.target.closest('[data-v]'); if (!el) return;
        var arr = S[x[1]], i = arr.indexOf(el.dataset.v);
        if (el.checked && i < 0) arr.push(el.dataset.v);
        if (!el.checked && i >= 0) arr.splice(i, 1);
        touched('form');
      });
    });
    $('fFreeAreas').addEventListener('change', function (e) {
      var el = e.target.closest('[data-free]'); if (!el) return;
      var id = +el.dataset.free, i = S.free_areas.indexOf(id);
      if (el.checked && i < 0) S.free_areas.push(id);
      if (!el.checked && i >= 0) S.free_areas.splice(i, 1);
      touched('form');
    });
    $('azYears').addEventListener('change', function (e) {
      var el = e.target.closest('[data-year]'); if (!el) return;
      var y = +el.dataset.year, i = S.azubi.years.indexOf(y);
      if (el.checked && i < 0) S.azubi.years.push(y);
      if (!el.checked && i >= 0) S.azubi.years.splice(i, 1);
      S.azubi.years.sort(); touched('form');
    });
    $('fPresets').addEventListener('click', function (e) {
      var b = e.target.closest('[data-preset]'); if (!b) return;
      S.start = b.dataset.s; S.end = b.dataset.e;
      $('fStart').value = S.start; $('fEnd').value = S.end;
      after('start'); after('end'); touched('form');
    });
    $('fDemandWd').addEventListener('input', function (e) {
      var i = e.target.closest('input[data-wd]'); if (!i) return;
      if (i.value === '') delete S.demand_by_weekday[i.dataset.wd];
      else S.demand_by_weekday[i.dataset.wd] = Math.max(0, Math.min(99, +i.value || 0));
      touched('form');
    });
    $('addSpecial').addEventListener('click', function () {
      var last = S.special_days.length ? S.special_days[S.special_days.length - 1].date : S.start;
      S.special_days.push({ date: last || BOOT.today, count: S.demand_default, note: '' });
      buildSpecial(); touched('form');
      var inp = $('fSpecial').querySelectorAll('input[type=date]'); if (inp.length) inp[inp.length - 1].focus();
    });
    $('fSpecial').addEventListener('input', function (e) {
      var el = e.target.closest('[data-f]'); if (!el) return;
      var i = +el.dataset.i, f = el.dataset.f; if (!S.special_days[i]) return;
      S.special_days[i][f] = f === 'count' ? Math.max(0, Math.min(99, +el.value || 0)) : el.value;
      touched('form');
    });
    $('fSpecial').addEventListener('click', function (e) {
      var b = e.target.closest('[data-rm]'); if (!b) return;
      S.special_days.splice(+b.dataset.rm, 1); buildSpecial(); touched('form');
    });
    ['fPersonSearch', 'fFltArea', 'fFltRole', 'fFltSort', 'fFltHome', 'fFltExp', 'fFltNoAz', 'fFltWe', 'fFltFlex'].forEach(function (id) {
      var el = $(id);
      el.addEventListener(el.tagName === 'INPUT' && (el.type === 'search' || el.type === 'text') ? 'input' : 'change', buildPeople);
    });
    $('fBulkFix').addEventListener('click', function () { bulk('fix'); });
    $('fBulkOut').addEventListener('click', function () { bulk('out'); });
    $('fBulkClr').addEventListener('click', function () {
      S.include_users = []; S.exclude_users = []; buildPeople(); touched('form');
      say('Auswahl zurückgesetzt.', 'info');
    });
    $('fPeople').addEventListener('click', function (e) {
      var f = e.target.closest('[data-fix]'), o = e.target.closest('[data-out]');
      if (!f && !o) return;
      var id = +(f ? f.dataset.fix : o.dataset.out);
      var mine = f ? S.include_users : S.exclude_users, other = f ? S.exclude_users : S.include_users;
      var i = mine.indexOf(id);
      if (i >= 0) mine.splice(i, 1);
      else { mine.push(id); var j = other.indexOf(id); if (j >= 0) other.splice(j, 1); }
      var keep = (f ? 'data-fix' : 'data-out') + '="' + id + '"';
      buildPeople(); touched('form');
      var nb = $('fPeople').querySelector('[' + keep + ']'); if (nb) nb.focus();
    });
  }

  /* ===================================================== Schritte */
  var HINTS = [
    'Pflicht ist nur der Einsatzbereich – alles andere ist sinnvoll vorbelegt.',
    'Die Vorschau rechts aktualisiert sich bei jeder Änderung.',
    'Klick auf eine Zelle setzt oder entfernt · Rechtsklick oder Kontextmenü-Taste für mehr · Spaltenkopf zeigt passende Personen.',
    'Übernommen wird nur als Entwurf – veröffentlicht wird in der Monatsplanung.'
  ];
  function step1Ok(show) {
    if (!S.area_id) { if (show) { setAreaError(true); say('Bitte wählen Sie einen Einsatzbereich.', 'warning'); } return false; }
    if (!S.start || !S.end) { if (show) say('Bitte Zeitraum angeben.', 'warning'); return false; }
    if (S.end < S.start) { if (show) say('Das Enddatum liegt vor dem Start.', 'warning'); return false; }
    return true;
  }
  function go(n, opts) {
    opts = opts || {};
    n = Math.max(0, Math.min(LAST, n));
    if (n > 0 && !step1Ok(true)) {
      n = 0;
      if (step === 0) { $('fArea').focus(); return; }
    }
    if (n === 1 && S.demand_min > S.demand_default && step === 1) say('Die Mindestbesetzung ist höher als der Regelbedarf.', 'warning');
    step = n; maxSeen = Math.max(maxSeen, n);
    document.querySelectorAll('.pqa-panel').forEach(function (p) { p.hidden = +p.dataset.panel !== step; });
    document.querySelectorAll('.pqa-step').forEach(function (b) {
      var i = +b.dataset.step;
      b.classList.toggle('is-active', i === step);
      if (i === step) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
    });
    $('pqaLayout').classList.toggle('has-live', step < 2);
    paintSummaries(); paintFoot();
    if (step >= 2) {
      if (!R || (formDirty && !R.manual)) generate({ silent: false });
      else render();
    }
    if (step === 3 && R) runPreview();
    if (step < 2 && R) renderLive();
    if (!opts.noScroll) window.scrollTo({ top: 0, behavior: 'smooth' });
    if (opts.focus !== false) {
      var p = document.querySelector('.pqa-panel[data-panel="' + step + '"]');
      var h = p && p.querySelector('h2, .pqa-h2');
      if (h && opts.focusHeading) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
    }
  }
  function paintFoot() {
    var h = HINTS[step];
    if (step === 2 && R && formDirty && R.manual) h = 'Die Vorgaben haben sich geändert – „Neu berechnen“ übernimmt sie (Handänderungen gehen dabei verloren).';
    $('footHint').textContent = h;
    $('btnBack').hidden = step === 0;
    var nb = $('btnNext');
    nb.hidden = false;
    nb.classList.remove('pqa-btn-go');
    if (step === 0) nb.textContent = 'Weiter: Bedarf & Regeln';
    else if (step === 1) nb.textContent = 'Vorschlag prüfen';
    else if (step === 2) nb.textContent = 'Weiter zur Übernahme';
    else if (applied) nb.textContent = 'Zur Monatsplanung';
    else { nb.textContent = 'Als Entwurf übernehmen'; nb.classList.add('pqa-btn-go'); nb.disabled = !R; }
    if (step !== 3) nb.disabled = false;
    $('staleBar').hidden = !(R && formDirty && R.manual);
  }
  function next() {
    if (step < 3) { go(step + 1, { focusHeading: true }); return; }
    if (applied) { location.href = BOOT.planning_url; return; }
    doApply();
  }

  /* ===================================================== Vorschlag */
  function readyForLive() { return S.area_id && S.start && S.end && S.end >= S.start; }
  function scheduleLive() {
    clearTimeout(liveTimer);
    if (!readyForLive()) { renderLive(); return; }
    if (R && R.manual) { renderLive(); return; }
    $('liveState').textContent = 'wird aktualisiert …';
    liveTimer = setTimeout(function () { generate({ silent: true }); }, 650);
  }

  function generate(opts) {
    opts = opts || {};
    if (!S.area_id) { say('Bitte zuerst einen Einsatzbereich wählen.', 'warning'); go(0); return Promise.resolve(); }
    var discard = !opts.silent && R && R.manual && opts.confirm;
    var start = discard ? ask({ title: 'Neu berechnen?', text: 'Ihre Handänderungen im Vorschlag gehen dabei verloren.', ok: 'Neu berechnen', focusOk: true })
                        : Promise.resolve(true);
    return start.then(function (okk) {
      if (!okk) return;
      var seq = ++genSeq;
      if (!opts.silent) busy(true, 'Vorschlag wird berechnet …');
      $('liveState').textContent = 'wird berechnet …';
      return post('generate', { config: JSON.stringify(S) }).then(function (d) {
        if (seq !== genSeq) return;
        busy(false);
        if (!d.success) { $('liveState').textContent = ''; say(d.error || 'Berechnung fehlgeschlagen.', 'error'); return; }
        R = { days: d.days, people: d.people, plan: d.plan || {}, area: d.area, manual: false };
        R.days.forEach(function (day) { if (!Array.isArray(R.plan[day.date])) R.plan[day.date] = []; });
        formDirty = false; rowOrder = null; preview = null; applied = null;
        $('resEmpty').hidden = true; $('resWrap').hidden = false;
        render();
        if (step === 3) runPreview();
        if (!opts.silent) {
          var n = conflicts.filter(function (c) { return c.level === 'block'; }).length;
          say(n ? 'Vorschlag steht – ' + n + ' Punkt(e) brauchen Ihre Entscheidung.' : 'Vorschlag steht.', n ? 'warning' : 'success');
        }
      }).catch(function (e) { if (seq === genSeq) { busy(false); $('liveState').textContent = ''; say(e.message, 'error'); } });
    });
  }

  function markManual() { if (R) { R.manual = true; preview = null; applied = null; } }

  function setForced(uid, date, on) {
    var k = uid + '|' + date, i = S.force_cells.indexOf(k);
    if (on && i < 0) S.force_cells.push(k);
    if (!on && i >= 0) S.force_cells.splice(i, 1);
  }

  function render() {
    if (!R) return;
    var v = C.validate(R, S, BOOT.perm);
    conflicts = v.conflicts; R.load = v.load; R.stats = v.stats;
    renderKpis(); renderSummaryLine(); renderConflicts();
    if (step >= 2) {
      renderLegend();
      if (view === 'days') renderDays(); else if (view === 'load') renderLoad(); else renderMatrix();
    } else renderLive();
    paintSummaries(); paintFoot();
  }

  function renderKpis() {
    var s = R.stats;
    var lvl = s.coverage >= 100 ? 'ok' : (s.coverage >= 80 ? 'part' : 'bad');
    $('kpis').innerHTML =
      '<div class="pqa-kpi pqa-kpi-main is-' + lvl + '"><span class="pqa-kpi-n">' + s.coverage + '&thinsp;%</span><span class="pqa-kpi-l">Abdeckung</span>' +
        '<span class="pqa-bar"><i' + css('width:' + Math.min(100, s.coverage) + '%') + '></i></span></div>' +
      '<div class="pqa-kpi"><span class="pqa-kpi-n">' + s.filled + '<small> / ' + s.total + '</small></span><span class="pqa-kpi-l">Besetzte Plätze</span></div>' +
      '<div class="pqa-kpi"><span class="pqa-kpi-n">' + s.used + '</span><span class="pqa-kpi-l">Eingesetzte Personen</span></div>' +
      '<button type="button" class="pqa-kpi pqa-kpi-btn' + (s.blocks ? ' is-bad' : ' is-ok') + '" data-kpi="block"><span class="pqa-kpi-n">' + s.blocks + '</span><span class="pqa-kpi-l">Blocker</span></button>' +
      '<button type="button" class="pqa-kpi pqa-kpi-btn' + (s.warns ? ' is-part' : '') + '" data-kpi="warn"><span class="pqa-kpi-n">' + s.warns + '</span><span class="pqa-kpi-l">Warnungen</span></button>';
    paint($('kpis'));
  }

  function renderSummaryLine() {
    var m = C.summary(R, S);
    $('epaSum').innerHTML =
      '<b>' + m.planDays + '</b> Einsatztage · Ø <b>' + m.avg.toFixed(1).replace('.', ',') + '</b> Personen/Tag · ' +
      '<b>' + m.home + '</b> aus dem Stammbereich · <b>' + m.azubi + '</b> durch Azubis' +
      (m.fromFree ? ' · <b>' + m.fromFree + '</b> aus dem Innendienst übernommen' : '') +
      (m.fromPlan ? ' · <b>' + m.fromPlan + '</b> ersetzen einen bestehenden Einsatz' : '');
  }

  /* ---------------- Raster ---------------- */
  /* Reihenfolge einmal festlegen und danach halten – sonst springt einem beim
     Nachplanen die Person unter dem Mauszeiger weg. */
  function buildRowOrder() {
    rowOrder = R.people.slice().sort(function (a, b) {
      var la = R.load[a.id] || 0, lb = R.load[b.id] || 0;
      if ((la > 0) !== (lb > 0)) return la > 0 ? -1 : 1;
      if (la !== lb) return lb - la;
      return a.name.localeCompare(b.name, 'de');
    }).map(function (p) { return p.id; });
  }
  function orderedPeople(only) {
    if (!rowOrder) buildRowOrder();
    var pos = {};
    rowOrder.forEach(function (id, i) { pos[id] = i; });
    var people = R.people.slice().sort(function (a, b) {
      var pa = pos[a.id], pb = pos[b.id];
      if (pa === undefined && pb === undefined) return a.name.localeCompare(b.name, 'de');
      if (pa === undefined) return 1;
      if (pb === undefined) return -1;
      return pa - pb;
    });
    return only ? people.filter(function (p) { return (R.load[p.id] || 0) > 0; }) : people;
  }

  /** Darstellung einer Zelle: Klasse, Farbe, Kürzel, Beschriftung. */
  function cellInfo(p, d, compact) {
    var picked = (R.plan[d.date] || []).indexOf(p.id) >= 0;
    var st = C.stateOf(p, d.date), cls = '', style = '', label = '', title;
    var areaCode = (R.area && R.area.short_code) || '✓';
    if (picked) {
      var a = C.avail(p, d, S, BOOT.perm);
      var bg = (R.area && R.area.color) || '#2557d6', fg = (R.area && R.area.fg_color) || '#ffffff';
      cls = 'pick' + (a.ok ? '' : ' bad');
      label = esc(areaCode);
      title = p.name + ' – ' + d.label + ': geplant ' + areaCode + ' (' + (a.ok ? a.why : 'Konflikt: ' + a.why) + ')';
      /* Ersetzt der Eintrag etwas, zeigt die Zelle beide Bereiche: Eckdreieck
         in der Farbe des bisherigen, Fläche in der des neuen Einsatzes. */
      if (st && st.t === 'einsatz' && +st.a !== +S.area_id) {
        var frei = S.free_areas.indexOf(+st.a) >= 0;
        cls += frei ? ' fromfree' : ' fromplan';
        style = 'background:' + bg + ';color:' + fg + ';--pqa-old:' + st.c;
        if (!compact) label = esc(String(st.l).replace(' •', '')) + '›' + label;
        title = p.name + ' – ' + d.label + ': ' + st.l + ' → ' + areaCode + (frei ? ' · Innendienst wird aufgelöst' : ' · bestehender Einsatz wird ersetzt');
      } else if (st && st.t === 'abwesend') {
        cls += ' fromplan';
        style = 'background:' + bg + ';color:' + fg + ';--pqa-old:#dc2626';
        title = p.name + ' – ' + d.label + ': ' + st.l + ' wird überschrieben';
      } else style = 'background:' + bg + ';color:' + fg;
      if (a.ok && C.foreignDraft(p, d.date, S)) {
        cls += ' foreign';
        title += ' · offener Entwurf eines Kollegen – bleibt beim Übernehmen stehen';
      }
    } else if (d.skip) {
      cls = 'skip'; title = d.label + ': ' + (d.reason || 'kein Bedarf');
    } else if (st && st.t === 'gesperrt') {
      cls = 'lock'; title = p.name + ' – ' + d.label + ': ' + st.l;
    } else if (st && st.t === 'abwesend') {
      cls = 'abs'; label = esc(String(st.l).slice(0, 3));
      title = p.name + ' – ' + d.label + ': ' + st.l + (BOOT.perm.absence ? ' · klicken, um zu überschreiben' : '');
    } else if (st && (st.t === 'einsatz' || st.t === 'schule')) {
      style = 'background:' + st.c + ';color:' + (st.f || '#ffffff'); label = esc(st.l);
      title = p.name + ' – ' + d.label + ': ' + st.l;
      if (st.t === 'einsatz') { cls = 'repl'; title += ' · klicken, um zu ersetzen'; }
      else cls = 'school';
    } else {
      title = p.name + ' – ' + d.label + ': ' + C.avail(p, d, S, BOOT.perm).why;
    }
    return { picked: picked, cls: cls, style: style, label: label, title: title };
  }

  /** Raster als HTML. compact = Live-Vorschau (nur lesen, kleiner). */
  function gridHtml(people, compact) {
    var days = R.days, today = BOOT.today, lastKw = null;
    var h = '<table class="pt pqa-pt' + (compact ? ' is-compact' : '') + '"><thead><tr class="hd-row"><th class="sc cn" scope="col">Mitarbeitende</th>';
    days.forEach(function (d) {
      var n = (R.plan[d.date] || []).length, lvl = C.dayLevel(n, d, S);
      var kw = C.isoWeek(d.date), newKw = kw !== lastKw; lastKw = kw;
      var cls = 'cd' + (d.weekend ? ' we' : '') + (d.htype === 'feiertag' ? ' hl-ft' : (d.htype ? ' hl-fe' : '')) +
                (lvl === 'bad' || lvl === 'part' ? ' short' : '') + (d.date === today ? ' today' : '') + (newKw ? ' kw-start' : '');
      var tip = d.skip ? d.label + ': ' + (d.reason || 'kein Bedarf')
              : d.label + ': Bedarf ' + d.required + ', besetzt ' + n + (d.note ? ' · ' + d.note : '') + (d.holiday ? ' · ' + d.holiday : '');
      var inner = '<span class="kwl">' + (newKw ? 'KW' + kw : '') + '</span><span class="dn">' + parseInt(d.date.slice(8), 10) +
                  '</span><span class="wn">' + WD[d.wd] + '</span>';
      h += '<th class="' + cls + '" scope="col" title="' + esc(tip) + '">' +
           (compact ? inner : '<button type="button" class="pqa-dh" data-day="' + d.date + '" aria-label="' + esc(tip + ' – passende Personen zeigen') + '">' + inner + '</button>') +
           '</th>';
    });
    h += '</tr></thead><tbody>';
    if (!people.length) h += '<tr><td class="cn pqa-empty-row" colspan="' + (days.length + 1) + '">Keine Personen im Vorschlag.</td></tr>';
    if (!compact) gridIdx = [];
    people.forEach(function (p, ri) {
      var rowIds = [];
      h += '<tr data-uid="' + p.id + '"><th class="sc cn" scope="row"><span class="pqa-nm">' +
             '<span class="pqa-av"' + css('background:' + (p.role_color || '#6b7280')) + ' aria-hidden="true">' + esc(p.short) + '</span>' +
             '<span class="pqa-nm-t" title="' + esc(p.name + ' · ' + p.role_label + (p.os ? ' · ' + p.os : '')) + '">' + esc(C.shortName(p.name)) + '</span>' +
             (+p.area_id === +S.area_id ? '<span class="pqa-tag pqa-tag-home pqa-tag-xs" title="Stammbereich">S</span>' : '') +
             (p.azubi_year ? '<span class="pqa-tag pqa-tag-az pqa-tag-xs">' + p.azubi_year + '. LJ</span>' : '') +
             (compact ? '' : '<span class="pqa-load" title="Tage im Vorschlag">' + (R.load[p.id] || 0) + '</span>') + '</span></th>';
      var lk = null;
      days.forEach(function (d, ci) {
        var w = C.isoWeek(d.date), nk = w !== lk; lk = w;
        var ci2 = cellInfo(p, d, compact);
        var td = 'cd' + (d.weekend ? ' we' : '') + (nk ? ' kw-start' : '');
        if (compact) {
          h += '<td class="' + td + '"><span class="pqa-cell ' + ci2.cls + '"' + (ci2.style ? css(ci2.style) : '') + ' title="' + esc(ci2.title) + '">' + ci2.label + '</span></td>';
        } else {
          var id = 'c_' + p.id + '_' + d.date;
          rowIds.push(id);
          h += '<td class="' + td + '"><button type="button" tabindex="-1" class="pqa-cell ' + ci2.cls + '" id="' + id + '"' + (ci2.style ? css(ci2.style) : '') +
               ' data-u="' + p.id + '" data-d="' + d.date + '" data-r="' + ri + '" data-c="' + ci + '" aria-pressed="' + ci2.picked + '"' +
               ' aria-label="' + esc(ci2.title) + '" title="' + esc(ci2.title) + '">' + ci2.label + '</button></td>';
        }
      });
      if (!compact) gridIdx.push(rowIds);
      h += '</tr>';
    });
    h += '</tbody><tfoot><tr><th class="sc cn" scope="row">Besetzung / Bedarf</th>';
    var lk2 = null;
    days.forEach(function (d) {
      var n = (R.plan[d.date] || []).length, lvl = C.dayLevel(n, d, S);
      var w = C.isoWeek(d.date), nk = w !== lk2; lk2 = w;
      var pct = d.required ? Math.min(100, Math.round(n / d.required * 100)) : 0;
      h += '<td class="cd' + (nk ? ' kw-start' : '') + '"><span class="pqa-foot-cnt is-' + lvl + '">' +
           (d.skip ? '–' : n + '/' + d.required) + '</span>' +
           (d.skip ? '' : '<span class="pqa-fbar is-' + lvl + '"><i' + css('width:' + pct + '%') + '></i></span>') + '</td>';
    });
    h += '</tr></tfoot></table>';
    return h;
  }

  function renderMatrix() {
    var people = orderedPeople(plannedOnly);
    var box = $('matrix');
    var hadFocus = box.contains(document.activeElement);
    var sl = box.scrollLeft, stp = box.scrollTop;
    box.innerHTML = gridHtml(people, false);
    paint(box);
    box.scrollLeft = sl; box.scrollTop = stp;
    $('btnOnly').checked = plannedOnly;
    /* Roving tabindex: genau eine Zelle ist per Tab erreichbar */
    var target = focusCell && $('c_' + focusCell.u + '_' + focusCell.d);
    if (!target && gridIdx.length && gridIdx[0].length) target = $(gridIdx[0][0]);
    if (target) { target.tabIndex = 0; if (hadFocus) target.focus({ preventScroll: true }); }
  }

  function renderLive() {
    var body = $('liveBody'), st = $('liveState');
    if (!R) {
      st.textContent = '';
      body.innerHTML = '<p class="muted pqa-small">' + (readyForLive() ? 'Vorschau wird vorbereitet …' : 'Sobald Einsatzbereich und Zeitraum stehen, erscheint hier der Vorschlag.') + '</p>';
      return;
    }
    var s = R.stats || { coverage: 0, filled: 0, total: 0, used: 0, blocks: 0, warns: 0 };
    st.textContent = R.manual && formDirty ? 'Vorgaben geändert' : 'aktuell';
    var lvl = s.coverage >= 100 ? 'ok' : (s.coverage >= 80 ? 'part' : 'bad');
    body.innerHTML =
      '<div class="pqa-live-k">' +
        '<span class="pqa-live-cov is-' + lvl + '"><b>' + s.coverage + '&thinsp;%</b> Abdeckung</span>' +
        '<span><b>' + s.filled + '</b>/' + s.total + ' Plätze</span><span><b>' + s.used + '</b> Personen</span>' +
        '<span class="' + (s.blocks ? 'is-bad' : '') + '"><b>' + s.blocks + '</b> Blocker</span>' +
        '<span class="' + (s.warns ? 'is-part' : '') + '"><b>' + s.warns + '</b> Warnungen</span>' +
      '</div>' +
      (R.manual && formDirty ? '<p class="pqa-hint pqa-hint-warn">Der Vorschlag enthält Handänderungen und wird nicht automatisch neu berechnet. <button type="button" class="btn btn-xs btn-outline" data-act="generate-confirm">Neu berechnen</button></p>' : '') +
      '<div class="pqa-ps pqa-ps-live">' + gridHtml(orderedPeople(true), true) + '</div>' +
      '<button type="button" class="btn btn-sm btn-outline pqa-live-go" data-go="2">Vorschlag im Detail prüfen →</button>';
    paint(body);
  }

  /* Legende zeigt ausschließlich, was im Raster auch vorkommt. */
  function renderLegend() {
    var a = R.area || {}, seen = {}, other = {}, free = {};
    var people = plannedOnly ? R.people.filter(function (p) { return (R.load[p.id] || 0) > 0; }) : R.people;
    R.days.forEach(function (d) {
      if (d.skip) { seen.skip = true; return; }
      people.forEach(function (p) {
        var picked = (R.plan[d.date] || []).indexOf(p.id) >= 0, st = C.stateOf(p, d.date);
        if (picked) {
          seen.pick = true;
          if (!C.avail(p, d, S, BOOT.perm).ok) seen.bad = true;
          else if (C.foreignDraft(p, d.date, S)) seen.foreign = true;
          if (st && st.t === 'einsatz' && +st.a !== +S.area_id) {
            if (S.free_areas.indexOf(+st.a) >= 0) { seen.fromFree = true; seen.fromFreeC = st.c; seen.fromFreeL = st.l; }
            else seen.fromPlan = true;
          } else if (st && st.t === 'abwesend') seen.fromPlan = true;
          return;
        }
        if (!st) return;
        if (st.t === 'gesperrt') seen.lock = true;
        else if (st.t === 'abwesend') seen.abs = true;
        else if (st.t === 'schule') seen.school = true;
        else if (st.t === 'einsatz') { if (S.free_areas.indexOf(+st.a) >= 0) free[st.l] = st; else other[st.l] = st; }
      });
    });
    var parts = [];
    function sw(bg, fg, txt, label) {
      return '<span><i class="pqa-sw"' + css('background:' + bg + ';color:' + (fg || '#fff')) + '>' + esc(txt || '') + '</i> ' + label + '</span>';
    }
    function corner(oldC, label) {
      return '<span><i class="pqa-sw pqa-sw-corner"' + css('background:' + (a.color || '#2557d6') + ';--pqa-old:' + oldC) + '></i> ' + label + '</span>';
    }
    if (seen.pick) parts.push(sw(a.color || '#2557d6', a.fg_color || '#fff', '', 'geplant: ' + esc(a.short_code || 'Einsatz')));
    if (seen.fromFree) parts.push(corner(seen.fromFreeC, 'Innendienst aufgelöst – Zelle zeigt „' + esc(seen.fromFreeL) + '›' + esc(a.short_code || '') + '“'));
    if (seen.fromPlan) parts.push(corner('#64748b', 'ersetzt einen bestehenden Eintrag'));
    if (seen.bad) parts.push('<span><i class="pqa-sw pqa-sw-bad"></i> Konflikt – bitte prüfen</span>');
    if (seen.foreign) parts.push('<span><i class="pqa-sw pqa-sw-foreign"></i> Entwurf eines Kollegen – bleibt stehen</span>');
    Object.keys(free).slice(0, 3).forEach(function (k) { parts.push(sw(free[k].c, free[k].f, k, 'Innendienst – gilt als frei')); });
    Object.keys(other).slice(0, 4).forEach(function (k) {
      parts.push(sw(other[k].c, other[k].f, k, 'anderer Einsatz' + (S.rules.overwrite === 'none' ? ' – per Klick ersetzbar' : '')));
    });
    if (Object.keys(other).length > 4) parts.push('<span>… und weitere Bereiche</span>');
    if (seen.school) parts.push('<span><i class="pqa-sw pqa-sw-school">BS</i> Berufsschule – nie überschreibbar</span>');
    if (seen.abs) parts.push('<span><i class="pqa-sw pqa-sw-abs"></i> Abwesenheit</span>');
    if (seen.lock) parts.push('<span><i class="pqa-sw pqa-sw-lock"></i> Sperrtag – nie überschreibbar</span>');
    if (seen.skip) parts.push('<span><i class="pqa-sw pqa-sw-skip"></i> kein Bedarf</span>');
    parts.push('<span class="pqa-legend-help">Klick setzt oder entfernt · Rechtsklick bzw. Kontextmenü-Taste für weitere Aktionen · Pfeiltasten bewegen · Spaltenkopf zeigt passende Personen</span>');
    $('legend').innerHTML = parts.join('');
    paint($('legend'));
  }

  /* ---------------- Tage / Auslastung ---------------- */
  function renderDays() {
    var byId = {}; R.people.forEach(function (p) { byId[p.id] = p; });
    var active = R.days.filter(function (d) { return !d.skip; });
    var gapCount = active.filter(function (d) { return (R.plan[d.date] || []).length < d.required; }).length;
    var h = '<div class="pqa-filter"><label class="pqa-chk"><input type="checkbox" id="dvGaps"' + (daysOnlyGaps ? ' checked' : '') +
            '> Nur Tage mit offenen Plätzen</label><span class="muted pqa-count">' + gapCount + ' von ' + active.length + ' Tagen unvollständig</span></div>';
    var lastKw = null;
    R.days.forEach(function (d) {
      var ids = R.plan[d.date] || [];
      if (daysOnlyGaps && (d.skip || ids.length >= d.required)) return;
      var kw = C.isoWeek(d.date);
      if (kw !== lastKw) { lastKw = kw; h += '<h3 class="pqa-kw">Kalenderwoche ' + kw + '</h3>'; }
      if (d.skip) {
        h += '<div class="pqa-day is-skip"><div class="pqa-day-h">' + esc(d.label) + '<span class="muted">' + esc(d.reason || 'kein Bedarf') + '</span></div></div>';
        return;
      }
      var gap = d.required - ids.length, lvl = C.dayLevel(ids.length, d, S);
      h += '<div class="pqa-day is-' + lvl + '"><div class="pqa-day-h">' + esc(d.label) +
           '<span class="badge ' + (lvl === 'ok' ? 'bs' : (lvl === 'bad' ? 'bd' : 'bw')) + '">' + ids.length + ' / ' + d.required + '</span>' +
           (d.holiday ? '<span class="muted">' + esc(d.holiday) + '</span>' : '') +
           (d.note ? '<span class="muted">' + esc(d.note) + '</span>' : '') +
           (gap > 0 ? '<button type="button" class="btn btn-xs btn-outline pqa-push" data-fill="' + d.date + '">' + gap + ' offen – Personen zeigen</button>' : '') +
           '</div><div class="pqa-day-n">';
      if (!ids.length) h += '<span class="muted pqa-small">Niemand eingeplant.</span>';
      ids.forEach(function (uid) {
        var p = byId[uid]; if (!p) return;
        var cls = 'pqa-chip' + (p.azubi_year > 0 ? ' is-az' : (+p.area_id === +S.area_id ? ' is-home' : ''));
        h += '<span class="' + cls + '" title="' + esc(p.role_label + (p.os ? ' · ' + p.os : '')) + '">' + esc(p.name) +
             (p.azubi_year ? ' (' + p.azubi_year + '. LJ)' : '') + '</span>';
      });
      h += '</div></div>';
    });
    $('daysView').innerHTML = '<div class="pqa-scroll">' + h + '</div>';
  }
  function renderLoad() {
    var rows = R.people.filter(function (p) { return (R.load[p.id] || 0) > 0; });
    var max = 1;
    rows.forEach(function (p) { max = Math.max(max, R.load[p.id] || 0); });
    rows.sort(function (a, b) { return (R.load[b.id] || 0) - (R.load[a.id] || 0) || a.name.localeCompare(b.name, 'de'); });
    var h = '';
    rows.forEach(function (p) {
      var n = R.load[p.id] || 0, over = n > S.rules.max_days_total;
      h += '<div class="pqa-lrow"><span class="pqa-nm"><span class="pqa-av"' + css('background:' + (p.role_color || '#6b7280')) + ' aria-hidden="true">' + esc(p.short) + '</span>' +
             '<span class="pqa-nm-t" title="' + esc(p.role_label) + '">' + esc(p.name) + '</span>' +
             (+p.area_id === +S.area_id ? '<span class="pqa-tag pqa-tag-home pqa-tag-xs">S</span>' : '') + '</span>' +
           '<span class="pqa-lbar' + (over ? ' is-over' : '') + '" role="img" aria-label="' + n + ' Tage"><i' + css('width:' + Math.round(n / max * 100) + '%') + '></i></span>' +
           '<b class="pqa-lnum">' + n + '</b></div>';
    });
    var unused = R.people.length - rows.length;
    $('loadView').innerHTML = '<div class="pqa-scroll">' + (h || '<p class="muted">Noch niemand eingeplant.</p>') +
      (unused > 0 ? '<p class="muted pqa-small">' + unused + ' weitere Person(en) sind nicht eingeplant.</p>' : '') + '</div>';
    paint($('loadView'));
  }
  function setView(v) {
    view = v;
    document.querySelectorAll('[data-view]').forEach(function (b) {
      var on = b.dataset.view === v;
      b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1;
    });
    $('matrix').hidden = v !== 'matrix'; $('daysView').hidden = v !== 'days'; $('loadView').hidden = v !== 'load';
    if (R) render();
  }

  /* ---------------- Prüfliste ---------------- */
  function renderConflicts() {
    var items = conflicts.filter(function (c) { return cfFilter === 'all' || c.level === cfFilter; });
    var s = R.stats;
    $('cfCount').textContent = s.blocks + ' Blocker · ' + s.warns + ' Warnungen · ' + s.infos + ' Hinweise';
    var counts = { all: conflicts.length, block: s.blocks, warn: s.warns, info: s.infos };
    document.querySelectorAll('[data-lvl]').forEach(function (b) {
      var base = { all: 'Alle', block: 'Blocker', warn: 'Warnungen', info: 'Hinweise' }[b.dataset.lvl];
      b.innerHTML = base + ' <span class="pqa-cnt">' + counts[b.dataset.lvl] + '</span>';
      b.disabled = counts[b.dataset.lvl] === 0 && b.dataset.lvl !== 'all';
      var on = b.dataset.lvl === cfFilter;
      b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (!items.length) {
      $('cfList').innerHTML = '<div class="pqa-ok"><b>' + (cfFilter === 'all' ? 'Alles in Ordnung.' : 'Nichts in dieser Kategorie.') + '</b>' +
        (cfFilter === 'all' ? '<span>Der Vorschlag erfüllt alle Vorgaben.</span>' : '') + '</div>';
      return;
    }
    var LBL = { block: 'Blocker', warn: 'Warnung', info: 'Hinweis' };
    $('cfList').innerHTML = '<ul class="pqa-cf-ul">' + items.slice(0, 300).map(function (c, i) {
      var help = c.level === 'info' ? '' : C.explain(c);
      var showPeople = c.date && (!c.uid || c.code === 'fix_fehlt');
      return '<li class="pqa-cf is-' + c.level + '">' +
        '<span class="pqa-cf-l">' + LBL[c.level] + (c.date ? ' · ' + dmy(c.date) : '') + '</span>' +
        '<span class="pqa-cf-m">' + esc(c.msg) + '</span>' +
        (help ? '<span class="pqa-cf-help">' + esc(help) + '</span>' : '') +
        '<span class="pqa-cf-a">' +
          (c.uid && c.date ? '<button type="button" class="btn btn-xs btn-outline" data-cf="' + i + '" data-cfa="cell">Zur Zelle</button>' : '') +
          (showPeople ? '<button type="button" class="btn btn-xs btn-outline" data-cf="' + i + '" data-cfa="alt">Personen zeigen</button>' : '') +
        '</span></li>';
    }).join('') + '</ul>' + (items.length > 300 ? '<p class="muted pqa-small">… und ' + (items.length - 300) + ' weitere</p>' : '');
    $('cfList')._items = items;
  }
  function jumpTo(c) {
    if (c.uid) {
      if (view !== 'matrix') setView('matrix');
      var el = $('c_' + c.uid + '_' + c.date);
      if (!el && plannedOnly) { plannedOnly = false; renderMatrix(); renderLegend(); el = $('c_' + c.uid + '_' + c.date); }
      if (el) {
        el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
        focusCell = { u: c.uid, d: c.date };
        document.querySelectorAll('.pqa-cell[tabindex="0"]').forEach(function (x) { x.tabIndex = -1; });
        el.tabIndex = 0; el.focus({ preventScroll: true });
        el.classList.add('is-flash'); setTimeout(function () { el.classList.remove('is-flash'); }, 1500);
        return;
      }
    }
    if (c.date) openAlt(c.date);
  }

  /* ---------------- Zellen bearbeiten ---------------- */
  function toggleCell(uid, date) {
    var day = findDay(date), p = findPerson(uid);
    if (!day || !p) return;
    focusCell = { u: uid, d: date };
    if (day.skip) { say(day.label + ' gehört nicht zur Planung (' + (day.reason || 'kein Bedarf') + ').', 'warning'); return; }
    var list = R.plan[date] || (R.plan[date] = []), k = list.indexOf(uid);
    if (k >= 0) {
      list.splice(k, 1);
      setForced(uid, date, false);   /* Freigabe zusammen mit der Zelle zurücknehmen */
      markManual(); touched('edit');
      announce(p.name + ', ' + day.label + ': entfernt.');
      return;
    }
    var a = C.avail(p, day, S, BOOT.perm);
    if (a.ok && C.foreignDraft(p, date, S)) { askForeign(p, day, true); return; }
    if (a.ok) { list.push(uid); markManual(); touched('edit'); announce(p.name + ', ' + day.label + ': eingeplant.'); return; }
    /* Bestehender Eintrag: Ersetzen anbieten statt nur abzulehnen. */
    if (a.replaceable) { askReplace(p, day, a); return; }
    say(p.name + ' am ' + day.label + ': ' + a.why + '.', 'warning');
  }
  function askReplace(p, day, a) {
    var abw = a.kind === 'abwesenheit';
    var back = document.activeElement;
    ask({
      title: abw ? 'Abwesenheit überschreiben?' : 'Bestehenden Einsatz ersetzen?',
      html: '<p><b>' + esc(p.name) + '</b> ist am <b>' + esc(day.label) + '</b> bereits eingetragen: <b>' + esc(a.current) + '</b>.</p>' +
            '<p>Soll dieser Eintrag durch <b>' + esc((R.area && R.area.short_code) || 'diesen Einsatz') + '</b> ersetzt werden? ' +
            'Die Änderung wird als Entwurf gespeichert und lässt sich zurücknehmen.</p>' +
            (abw ? '<p class="pqa-alert">Es handelt sich um eine Abwesenheit (' + esc(a.current) + '). Bitte nur nach Rücksprache mit der Person überschreiben.</p>' : ''),
      ok: abw ? 'Trotzdem ersetzen' : 'Ersetzen', danger: abw
    }).then(function (yes) {
      if (back && back.focus) try { back.focus(); } catch (e) {}
      if (!yes) return;
      setForced(p.id, day.date, true);
      (R.plan[day.date] || (R.plan[day.date] = [])).push(p.id);
      markManual(); touched('edit');
      say(a.current + ' ersetzt.', 'success');
    });
  }

  /* Offener Entwurf eines Kollegen: nur nach ausdrücklicher Bestätigung ersetzen. */
  function askForeign(p, day, add) {
    var st = C.stateOf(p, day.date), back = document.activeElement;
    ask({
      title: 'Entwurf eines anderen Planers ersetzen?',
      html: '<p>Für <b>' + esc(p.name) + '</b> am <b>' + esc(day.label) + '</b> liegt ein offener Entwurf eines Kollegen vor: <b>' +
            esc(st ? st.l : '') + '</b>.</p><p>Ohne Ihre Bestätigung bleibt er bei der Übernahme stehen. Bitte vorher absprechen.</p>',
      ok: 'Bewusst ersetzen', danger: true
    }).then(function (yes) {
      if (back && back.focus) try { back.focus(); } catch (e) {}
      if (!yes) return;
      setForced(p.id, day.date, true);
      var list = R.plan[day.date] || (R.plan[day.date] = []);
      if (add && list.indexOf(p.id) < 0) list.push(p.id);
      markManual(); touched('edit');
      say('Entwurf wird beim Übernehmen ersetzt.', 'info');
    });
  }

  /* ---------------- Kontextmenü ---------------- */
  var ctxReturn = null, ctxOpenedAt = 0;
  function closeCtx(refocus) {
    var m = $('epaCtx');
    if (!m.classList.contains('open')) return;
    m.classList.remove('open');
    if (refocus && ctxReturn && ctxReturn.focus) try { ctxReturn.focus(); } catch (e) {}
  }
  function openCtx(x, y, uid, date) {
    var m = $('epaCtx'), p = findPerson(uid);
    if (!p) return;
    ctxReturn = document.activeElement;
    var day = date ? findDay(date) : null;
    var isOnDay = date && (R.plan[date] || []).indexOf(uid) >= 0;
    var load = R.load[uid] || 0;
    var excluded = S.exclude_users.indexOf(uid) >= 0, fixed = S.include_users.indexOf(uid) >= 0;
    var it = ['<div class="pqa-ctx-h">' + esc(p.name) + ' · ' + load + ' Tag(e)</div>'];
    function row(act, label) { return '<button type="button" class="ctx-item" role="menuitem" tabindex="-1" data-ctx="' + act + '"><span>' + label + '</span></button>'; }
    if (day && !day.skip) {
      it.push(row('day', isOnDay ? 'Diesen Tag entfernen' : 'Für diesen Tag einplanen'));
      it.push(row('alt', 'Passende Personen für ' + esc(day.label)));
      if (isOnDay && C.foreignDraft(p, date, S)) it.push(row('force', 'Entwurf des Kollegen bewusst ersetzen'));
      it.push('<div class="ctx-sep" role="separator"></div>');
    }
    it.push(row('drop', 'Aus dem Vorschlag nehmen' + (load ? ' (' + load + ' Tage)' : '')));
    it.push(row('out', excluded ? 'Ausschluss aufheben' : 'Ganz ausschließen (nicht mehr vorschlagen)'));
    it.push(row('fix', fixed ? 'Feste Einplanung aufheben' : 'An allen möglichen Tagen einplanen'));
    m.innerHTML = it.join('');
    m.dataset.uid = uid; m.dataset.date = date || '';
    m.classList.add('open');
    var w = m.offsetWidth || 240, h = m.offsetHeight || 200;
    m.style.left = Math.max(8, Math.min(x, window.innerWidth - w - 8)) + 'px';
    m.style.top = Math.max(8, Math.min(y, window.innerHeight - h - 8)) + 'px';
    ctxOpenedAt = Date.now();
    var first = m.querySelector('[data-ctx]'); if (first) first.focus({ preventScroll: true });
  }
  function ctxAction(act, uid, date) {
    var p = findPerson(uid); if (!p) return;
    if (act === 'day') { toggleCell(uid, date); return; }
    if (act === 'alt') { openAlt(date); return; }
    if (act === 'force') { askForeign(p, findDay(date), false); return; }
    if (act === 'drop' || act === 'out') {
      var n = 0;
      Object.keys(R.plan).forEach(function (d) {
        var k = R.plan[d].indexOf(uid);
        if (k >= 0) { R.plan[d].splice(k, 1); n++; setForced(uid, d, false); }
      });
      var fi = S.include_users.indexOf(uid); if (fi >= 0) S.include_users.splice(fi, 1);
      if (act === 'out') {
        var ei = S.exclude_users.indexOf(uid);
        if (ei >= 0) { S.exclude_users.splice(ei, 1); say(p.name + ' ist wieder wählbar.', 'info'); }
        else { S.exclude_users.push(uid); say(p.name + ' wird nicht mehr vorgeschlagen' + (n ? ' – ' + n + ' Tag(e) entfernt.' : '.'), 'info'); }
        buildPeople();
      } else say(p.name + ': ' + n + ' Tag(e) aus dem Vorschlag entfernt.', 'info');
      markManual(); touched('edit');
      return;
    }
    if (act === 'fix') {
      var f = S.include_users.indexOf(uid);
      if (f >= 0) { S.include_users.splice(f, 1); say('Feste Einplanung für ' + p.name + ' aufgehoben.', 'info'); }
      else {
        S.include_users.push(uid);
        var e2 = S.exclude_users.indexOf(uid); if (e2 >= 0) S.exclude_users.splice(e2, 1);
        var added = 0;
        R.days.forEach(function (d) {
          if (d.skip) return;
          var list = R.plan[d.date] || (R.plan[d.date] = []);
          if (list.indexOf(uid) >= 0 || !C.avail(p, d, S, BOOT.perm).ok) return;
          list.push(uid); added++;
        });
        say(p.name + ' an ' + added + ' Tag(en) eingeplant.', 'success');
      }
      buildPeople(); markManual(); touched('edit');
    }
  }

  /* ---------------- Passende Personen für einen Tag ---------------- */
  function openAlt(date) {
    var day = findDay(date); if (!day) return;
    var title = function () { return 'Personen für ' + day.label + ' · ' + (R.plan[date] || []).length + ' von ' + day.required + ' besetzt'; };
    $('altTitle').textContent = title();
    $('altBody').innerHTML = '<p class="muted">Wird geladen …</p>';
    oM('ovAlt');
    post('suggest', { config: JSON.stringify(S), date: date, taken: JSON.stringify(R.plan[date] || []) }).then(function (d) {
      if (!d.success) { $('altBody').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
      if (!d.items.length) { $('altBody').innerHTML = '<p class="muted">Keine weiteren Personen verfügbar.</p>'; return; }
      var items = C.sortSuggest(d.items);
      var okN = items.filter(function (i) { return i.ok; }).length;
      var head = '<p class="muted pqa-small">' + okN + ' verfügbar, ' + (items.length - okN) + ' nicht einsetzbar. Oben stehen die, die sich am leichtesten umplanen lassen.</p>';
      $('altBody').innerHTML = head + '<ul class="pqa-alt-ul">' + items.map(function (it) {
        var u = USER[it.id] || {}, tags = '', hint = '';
        if (it.home) tags += '<span class="pqa-tag pqa-tag-home">Stammbereich</span>';
        else if (it.area_code) tags += '<span class="pqa-tag">' + esc(it.area_code) + '</span>';
        if (it.exp > 0) tags += '<span class="pqa-tag pqa-tag-exp">' + it.exp + '× dort</span>';
        if (it.os) tags += '<span class="pqa-tag">' + esc(it.os) + '</span>';
        if (it.azubi_year) tags += '<span class="pqa-tag pqa-tag-az">Azubi ' + it.azubi_year + '. LJ</span>';
        if (it.bond >= 0.6 && it.bond_code && !it.home) {
          tags += '<span class="pqa-tag pqa-tag-bond">fest bei ' + esc(it.bond_code) + '</span>';
          hint = 'ist überwiegend dort – lieber jemand anderen';
        } else if (C.suggestRank(it) === 0) hint = 'Innendienst – lässt sich leicht umplanen';
        return '<li class="pqa-alt' + (it.ok ? '' : ' is-no') + '">' +
          '<span class="pqa-av"' + css('background:' + roleColor(u.role)) + ' aria-hidden="true">' + esc(u.first_name ? initials(u) : '?') + '</span>' +
          '<span class="pqa-alt-n">' + esc(it.name) + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</span>' +
          '<span class="pqa-person-t">' + tags + '</span>' +
          '<span class="pqa-alt-r">' + esc(it.reason) + '</span>' +
          (it.ok ? '<button type="button" class="btn btn-xs btn-primary" data-add="' + it.id + '">Einplanen</button>' : '') +
        '</li>';
      }).join('') + '</ul>';
      paint($('altBody'));
      $('altBody').querySelectorAll('[data-add]').forEach(function (b) {
        b.addEventListener('click', function () {
          toggleCell(+b.dataset.add, date);
          var li = b.closest('.pqa-alt'), nxt = li.nextElementSibling;
          li.remove();
          $('altTitle').textContent = title();
          var f = (nxt && nxt.querySelector('[data-add]')) || $('altBody').querySelector('[data-add]') || $('ovAlt').querySelector('[data-close]');
          if (f) f.focus();
        });
      });
    }).catch(function (e) { $('altBody').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }

  /* ===================================================== ④ Übernehmen */
  function planKey() { return JSON.stringify([S, R && R.plan]); }
  function totalEntries() { return R ? Object.keys(R.plan).reduce(function (n, d) { return n + R.plan[d].length; }, 0) : 0; }

  function runPreview() {
    var box = $('applyBox');
    if (applied) { renderApplied(applied); return; }
    if (!R) { box.innerHTML = '<p class="muted">Bitte zuerst einen Vorschlag berechnen.</p>'; return; }
    if (!totalEntries()) {
      box.innerHTML = '<h2 class="pqa-h2">Nichts zu übernehmen</h2><p>Der Vorschlag enthält keine Einträge. Planen Sie in Schritt 3 Personen ein.</p>';
      paintFoot(); $('btnNext').disabled = true; return;
    }
    var key = planKey();
    if (preview && previewKey === key) { renderPreview(preview); return; }
    var seq = ++prevSeq;
    box.innerHTML = '<div class="pqa-loading"><span class="pqa-spin pqa-spin-sm" aria-hidden="true"></span> Übernahme wird geprüft …</div>';
    post('apply_preview', { config: JSON.stringify(S), plan: JSON.stringify(R.plan) }).then(function (d) {
      if (seq !== prevSeq) return;
      if (!d.success) { box.innerHTML = '<p class="pqa-alert">' + esc(d.error || 'Prüfung fehlgeschlagen.') + '</p>'; return; }
      preview = d; previewKey = key; renderPreview(d);
    }).catch(function (e) { box.innerHTML = '<p class="pqa-alert">' + esc(e.message) + '</p>'; });
  }

  function reasonList(res) {
    var groups = {};
    (res.skipped_items || []).forEach(function (it) { (groups[it.code] = groups[it.code] || []).push(it); });
    return Object.keys(groups).map(function (code) {
      var list = groups[code];
      return '<li><details><summary><b>' + (res.by_reason[code] || list.length) + '×</b> ' + esc((res.reasons || {})[code] || code) + '</summary>' +
        '<ul class="pqa-skip-ul">' + list.slice(0, 40).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') +
        (list.length > 40 ? '<li>… und ' + (list.length - 40) + ' weitere</li>' : '') + '</ul></details></li>';
    }).join('');
  }

  function renderPreview(res) {
    var s = R.stats, a = R.area || {};
    var k = res.kinds || {};
    var facts = [];
    if (k.neu) facts.push('<li><b>' + k.neu + '</b> auf freien Tagen</li>');
    if (k.intern) facts.push('<li><b>' + k.intern + '</b> lösen Innendienst auf</li>');
    if (k.ersetzt) facts.push('<li><b>' + k.ersetzt + '</b> ersetzen einen bestehenden Einsatz</li>');
    if (k.abwesenheit) facts.push('<li class="is-warn"><b>' + k.abwesenheit + '</b> überschreiben eine Abwesenheit</li>');
    if (k.gleich) facts.push('<li><b>' + k.gleich + '</b> waren bereits so geplant</li>');
    if (k.offen) facts.push('<li><b>' + k.offen + '</b> ersetzen einen offenen Tag</li>');
    if (res.unchanged) facts.push('<li><b>' + res.unchanged + '</b> stehen bereits so im Entwurf (unverändert)</li>');
    var warn = s.warns + s.blocks;
    $('applyBox').innerHTML =
      '<div class="pqa-apply-head">' +
        '<span class="pqa-apply-ic" aria-hidden="true">' + areaChip(a) + '</span>' +
        '<div><h2 class="pqa-apply-t" id="applyHeadline">' + esc(C.applyHeadline(res)) + '</h2>' +
        '<p class="muted">' + esc(S.title || 'Einsatzplanung') + ' · ' + dmy(S.start) + ' – ' + dmy(S.end) +
          (S.cross_team ? ' · teamübergreifend' : '') + (res.internal ? ' · als intern markiert' : '') + '</p></div>' +
      '</div>' +
      (facts.length ? '<ul class="pqa-facts">' + facts.join('') + '</ul>' : '') +
      (res.skipped_total ? '<div class="pqa-skip"><h3 class="pqa-h3">Wird übersprungen (' + res.skipped_total + ')</h3>' +
         '<p class="pqa-hint">Diese Zellen bleiben unverändert – der Server prüft Sperren, Abwesenheiten und Entwürfe anderer unabhängig vom Browser.</p>' +
         '<ul class="pqa-reasons">' + reasonList(res) + '</ul></div>' : '<p class="pqa-okline">Keine Zelle wird übersprungen.</p>') +
      (warn ? '<p class="pqa-hint">' + (s.blocks ? '<b>' + s.blocks + ' Blocker</b> und ' : '') + '<b>' + s.warns + ' Warnung(en)</b> aus der Prüfung bleiben bestehen – sie verhindern die Übernahme nicht. ' +
              '<button type="button" class="btn btn-xs btn-outline" data-go="2">Im Vorschlag ansehen</button></p>' : '') +
      '<div class="pqa-apply-a">' +
        '<button type="button" class="btn btn-primary pqa-btn-lg" data-act="apply"' + (res.written ? '' : ' disabled') + '>Jetzt als Entwurf übernehmen</button>' +
        '<button type="button" class="btn btn-outline" data-act="save-plan">Planung speichern</button>' +
        '<span class="pqa-hint">Veröffentlicht wird weiterhin in der Monatsplanung. Die Übernahme lässt sich mit einem Klick zurücknehmen.</span>' +
      '</div>';
    paint($('applyBox'));
    $('btnNext').disabled = !res.written;
  }

  function doApply() {
    if (!R || !totalEntries()) { say('Der Vorschlag enthält keine Einträge.', 'warning'); return; }
    var revs = (preview && previewKey === planKey()) ? preview.revs || {} : {};
    busy(true, 'Entwürfe werden gespeichert …');
    post('apply', { config: JSON.stringify(S), plan: JSON.stringify(R.plan), revs: JSON.stringify(revs), plan_id: plan.id || 0 }).then(function (d) {
      busy(false);
      if (!d.success) { say(d.error || 'Übernahme fehlgeschlagen.', 'error'); return; }
      lastRun = { id: d.run_id, written: d.written };
      applied = d; unsaved = false;
      if (plan.id && plan.own) {   /* gespeicherte Planung auf den neuesten Stand bringen */
        plan.applied = new Date().toISOString();
        post('plan_save', { id: plan.id, name: plan.name || S.title, shared: plan.shared,
          config: JSON.stringify(S), plan: JSON.stringify(R.plan), stats: JSON.stringify(R.stats) });
      }
      paintUndo(); renderApplied(d); paintSummaries(); paintFoot();
      say(d.msg || 'Übernommen.', 'success');
      var h = $('appliedTitle'); if (h) { h.setAttribute('tabindex', '-1'); h.focus(); }
    }).catch(function (e) { busy(false); say(e.message, 'error'); });
  }

  function renderApplied(d) {
    $('applyBox').innerHTML =
      '<div class="pqa-done">' +
        '<span class="pqa-done-ic" aria-hidden="true"><svg viewBox="0 0 20 20" width="22" height="22" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/></svg></span>' +
        '<div><h2 class="pqa-apply-t" id="appliedTitle">' + d.written + ' Einträge als Entwurf übernommen</h2>' +
        '<p>Die Einträge stehen jetzt in der Monatsplanung als Entwurf. Dort prüfen und wie gewohnt veröffentlichen.</p></div>' +
      '</div>' +
      (d.skipped_total ? '<div class="pqa-skip"><h3 class="pqa-h3">' + d.skipped_total + ' übersprungen</h3><ul class="pqa-reasons">' + reasonList(d) + '</ul></div>' : '') +
      '<div class="pqa-apply-a">' +
        (lastRun ? '<button type="button" class="btn btn-outline pqa-btn-undo pqa-btn-lg" data-act="undo">↶ Übernahme rückgängig machen</button>' : '') +
        '<a class="btn btn-primary" href="' + esc(BOOT.planning_url) + '">Zur Monatsplanung</a>' +
        '<button type="button" class="btn btn-outline" data-act="home">Zur Übersicht</button>' +
      '</div>';
  }

  function paintUndo() {
    var pill = $('undoPill');
    pill.hidden = !lastRun;
    if (lastRun) $('undoPillTxt').textContent = lastRun.written + ' Einträge übernommen';
  }

  function undo(runId, written) {
    var id = runId || (lastRun && lastRun.id);
    if (!id) return;
    ask({ title: 'Übernahme zurücknehmen?',
          text: 'Alle Entwürfe dieser Übernahme' + (written ? ' (' + written + ' Einträge)' : '') + ' werden wieder entfernt. Zellen, die inzwischen von Hand geändert wurden, bleiben erhalten.',
          ok: 'Zurücknehmen', danger: true, focusOk: true }).then(function (yes) {
      if (!yes) return;
      busy(true, 'Übernahme wird zurückgenommen …');
      post('undo', { run_id: id }).then(function (d) {
        busy(false);
        if (!d.success) { say(d.error || 'Zurücknehmen fehlgeschlagen.', 'error'); return; }
        if (lastRun && lastRun.id === id) {
          lastRun = null; applied = null; preview = null; plan.applied = null;
          paintUndo(); paintSummaries();
          if (step === 3) { runPreview(); paintFoot(); }
        }
        say(d.msg || 'Zurückgenommen.', 'success');
        if (!$('epaHome').hidden) loadHome();
      }).catch(function (e) { busy(false); say(e.message, 'error'); });
    });
  }

  /* ---------------- Vorgaben im Überblick ---------------- */
  function paintRecap() {
    var box = $('epaRecap'); if (!box) return;
    var a = AREA[S.area_id], wd = S.weekdays.map(function (d) { return WD[d]; }).join(', ');
    var free = S.free_areas.map(function (id) { return (AREA[id] || {}).short_code || id; }).join(', ');
    var days = (S.start && S.end) ? C.daysBetween(S.start, S.end) + 1 : 0;
    var rows = [
      { l: 'Einsatz', v: '<b>' + esc(S.title || 'ohne Bezeichnung') + '</b>' + (a ? ' · ' + esc(a.short_code + ' – ' + a.name) : '<small>Kein Einsatzbereich gewählt</small>'),
        step: 0, field: 'fArea', warn: !S.area_id },
      { l: 'Zeitraum', v: '<b>' + dmy(S.start) + ' – ' + dmy(S.end) + '</b> · ' + days + ' Kalendertage' +
           '<small>Einsatztage: ' + esc(wd) + (S.skip_holidays ? ' · Feiertage werden übersprungen' : '') + '</small>', step: 0, field: 'fStart' },
      { l: 'Umfang', v: '<b>' + (S.cross_team ? 'teamübergreifend' : 'eigenes Team') + '</b>' +
           (S.cross_team ? '<small>Bitte mit den betroffenen Teams abstimmen.</small>' : ''), step: 0, field: 'rowCross', warn: S.cross_team },
      { l: 'Kandidatenkreis', v: (S.roles.length ? '<b>' + esc(S.roles.map(function (r) { return (ROLE[r] || {}).role_label || r; }).join(', ')) + '</b>' : '<b>alle Rollen</b>') +
           ((S.include_users.length || S.exclude_users.length) ? '<small>' + S.include_users.length + ' fest eingeplant · ' + S.exclude_users.length + ' ausgeschlossen</small>' : ''),
        step: 0, field: 'fRoles' },
      { l: 'Bedarf', v: '<b>' + S.demand_default + ' pro Tag</b>, mindestens ' + S.demand_min +
           (S.special_days.length ? '<small>' + S.special_days.length + ' Sondertag(e) abweichend</small>' : ''),
        step: 1, field: 'fDemand', warn: S.demand_min > S.demand_default },
      { l: 'Qualifikation', v: (S.req_os_level ? '<b>' + esc(S.req_os_level) + '</b>, davon ' + S.req_os_count + ' pro Tag' : 'keine Vorgabe') +
           (S.req_professions.length ? '<small>Beruf bevorzugt: ' + esc(S.req_professions.join(', ')) + '</small>' : ''), step: 1, field: 'fOsLevel' },
      { l: 'Stammbereich', v: '<b>' + (S.home_mode === 'only' ? 'ausschließlich' : (S.home_mode === 'off' ? 'wird ignoriert' : 'bevorzugt')) + '</b>' +
           (S.req_home_count ? ' · mindestens ' + S.req_home_count + ' pro Tag' : '') +
           '<small>Kundenerfahrung ' + (S.use_experience ? 'wird mitgewichtet' : 'bleibt unberücksichtigt') + '</small>', step: 1, field: 'fHomeCount' },
      { l: 'Azubis', v: S.azubi.enabled
             ? '<b>' + S.azubi.years.map(function (y) { return y + '. LJ'; }).join(', ') + '</b> · höchstens ' + S.azubi.max_per_day + ' pro Tag' +
               '<small>Betreuungsschlüssel 1:' + S.azubi.supervision_ratio + (S.azubi.never_alone ? ' · nie allein' : '') +
               (S.azubi.skip_school_days ? ' · Berufsschule ausgespart' : '') + (S.azubi.min_per_day ? ' · ' + S.azubi.min_per_day + ' reserviert' : '') + '</small>'
             : '<b>nicht einbezogen</b>', step: 1, field: 'azOn', warn: S.azubi.enabled && !S.azubi.years.length },
      { l: 'Grenzen', v: '<b>' + S.rules.max_consecutive + '</b> Tage am Stück · <b>' + S.rules.max_days_total + '</b> Tage gesamt' +
           '<small>Wochenend-Freigabe wird ' + (S.rules.weekend_flag ? 'beachtet' : 'ignoriert') + '</small>', step: 1, field: 'rMaxStreak' },
      { l: 'Belegte Tage', v: '<b>' + ({ none: 'nichts überschreiben', assignments: 'Einsätze überschreiben', all: 'Einsätze und Abwesenheiten' }[S.rules.overwrite]) + '</b>' +
           '<small>' + (free ? 'Als frei behandelt: ' + esc(free) : 'Keine Innendienst-Bereiche freigegeben') + '</small>',
        step: 1, field: 'rOverwriteHint', warn: S.rules.overwrite === 'all' && !BOOT.perm.absence },
      { l: 'Umplanung', v: '<b>' + (S.prefer_free ? 'Innendienst wird aktiv aufgelöst' : 'Innendienst nur bei Bedarf') + '</b> · ' +
           (S.respect_bond ? 'Kundenbindung wird beachtet' : 'Kundenbindung wird ignoriert') +
           '<small>Gewichtung: Kontinuität ' + S.weights.continuity + ' · Gleichverteilung ' + S.weights.fairness +
           ' · Stammbereich ' + S.weights.home_area + ' · Qualifikation ' + S.weights.os + '</small>', step: 1, field: 'fRespectBond' }
    ];
    box.innerHTML = rows.map(function (r, i) {
      return '<div class="pqa-rc' + (r.warn ? ' is-warn' : '') + '"><span class="pqa-rc-l">' + esc(r.l) + '</span>' +
        '<span class="pqa-rc-v">' + r.v + '</span>' +
        '<button type="button" class="btn btn-xs btn-outline" data-jump="' + i + '" aria-label="' + esc(r.l) + ' ändern">Ändern</button></div>';
    }).join('');
    box._rows = rows;
    var warn = [];
    if (!S.area_id) warn.push('Es ist noch kein Einsatzbereich gewählt.');
    if (S.demand_min > S.demand_default) warn.push('Die Mindestbesetzung liegt über dem Regelbedarf.');
    if (S.azubi.enabled && !S.azubi.years.length) warn.push('Azubis sind einbezogen, aber kein Lehrjahr ist ausgewählt.');
    if (S.rules.overwrite === 'all' && !BOOT.perm.absence) warn.push('Ihnen fehlt das Recht, Abwesenheiten zu überschreiben – die Einstellung bleibt wirkungslos.');
    $('epaRecapWarn').innerHTML = warn.length
      ? '<div class="pqa-alert">' + warn.map(esc).join('<br>') + '</div>'
      : '<span class="muted pqa-small">Alle Pflichtangaben sind vollständig.</span>';
  }
  /* Sprung zum betroffenen Feld – öffnet eingeklappte Bereiche und hebt kurz hervor. */
  function jumpField(target, fieldId) {
    go(target, { noScroll: true });
    setTimeout(function () {
      var el = $(fieldId); if (!el) return;
      var d = el.closest('details'); while (d) { d.open = true; d = d.parentElement && d.parentElement.closest('details'); }
      var box = el.closest('.fg') || el.closest('.pqa-num-row') || el.closest('.fg-check') || el.closest('fieldset') || el;
      box.scrollIntoView({ block: 'center', behavior: 'smooth' });
      box.classList.remove('pqa-flash'); void box.offsetWidth; box.classList.add('pqa-flash');
      setTimeout(function () { box.classList.remove('pqa-flash'); }, 1600);
      var f = (el.matches('input,select,textarea,button') ? el : el.querySelector('input,select,textarea,button'));
      if (f) { try { f.focus({ preventScroll: true }); } catch (e) { f.focus(); } }
    }, 80);
  }

  /* ===================================================== Vorlagen */
  function tplItem(p, me, full) {
    var mine = String(p.created_by) === String(me);
    return '<li class="pqa-tpl">' + areaChip({ short_code: p.short_code, color: p.color, fg_color: p.fg_color }) +
      '<span class="pqa-tpl-n"><b>' + esc(p.name) + '</b>' + (p.description ? '<small>' + esc(p.description) + '</small>' : '') +
        '<small>' + (p.use_count > 0 ? p.use_count + '× genutzt · ' : '') + (p.is_shared == 1 ? 'freigegeben' : 'nur für Sie') + '</small></span>' +
      '<span class="pqa-tpl-a"><button type="button" class="btn btn-xs btn-primary" data-tpl="' + p.id + '">Verwenden</button>' +
      (full && (mine || BOOT.perm.manage) ? '<button type="button" class="btn btn-xs btn-outline" data-tpldel="' + p.id + '" aria-label="Vorlage ' + esc(p.name) + ' löschen">Löschen</button>' : '') +
      '</span></li>';
  }
  function openProfiles() {
    $('profBody').innerHTML = '<p class="muted">Wird geladen …</p>';
    oM('ovProf');
    post('profile_list', {}).then(function (d) {
      if (!d.success) { $('profBody').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
      if (!d.items.length) {
        $('profBody').innerHTML = '<p class="muted">Noch keine Vorlagen. Sichern Sie eine fertige Konfiguration über „Als Vorlage sichern“ – beim nächsten Kundeneinsatz sind Sie damit sofort startklar.</p>';
        return;
      }
      $('profBody').innerHTML = '<ul class="pqa-tpl-ul">' + d.items.map(function (p) { return tplItem(p, d.me, true); }).join('') + '</ul>';
      paint($('profBody'));
    }).catch(function (e) { $('profBody').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }
  function deleteProfile(id) {
    ask({ title: 'Vorlage löschen?', text: 'Die Vorlage wird für alle entfernt, die sie nutzen. Bereits geplante Einsätze bleiben unberührt.', ok: 'Löschen', danger: true })
      .then(function (yes) {
        if (!yes) return;
        post('profile_delete', { id: id }).then(function (r) {
          if (r.success) { say('Vorlage gelöscht.', 'success'); if ($('ovProf').classList.contains('open')) openProfiles(); loadHome(); }
          else say(r.error || 'Löschen nicht möglich.', 'error');
        });
      });
  }
  function useProfile(id) {
    post('profile_load', { id: id }).then(function (d) {
      if (!d.success) { say(d.error || 'Vorlage nicht gefunden.', 'error'); return; }
      resetState();
      S = C.mergeConfig(BOOT, d.config);
      S.force_cells = [];
      var shifted = C.shiftToToday(S, BOOT.today);
      profile = { id: d.id, name: d.name, own: !!d.own };
      writeForm(); loadCandidates(); cM('ovProf');
      wizard(); go(0);
      say('Vorlage „' + d.name + '“ geladen.' + (shifted ? ' Der Zeitraum lag in der Vergangenheit und beginnt jetzt heute.' : ''), shifted ? 'warning' : 'success');
      persist(); scheduleLive();
    }).catch(function (e) { say(e.message, 'error'); });
  }
  function saveProfile() {
    if (!S.area_id) { say('Bitte zuerst einen Einsatzbereich wählen.', 'warning'); wizard(); go(0); return; }
    $('spName').value = profile.id ? profile.name : (S.title || ''); $('spDesc').value = '';
    $('spDescRow').hidden = false;
    var canUpd = profile.id && (profile.own || BOOT.perm.manage);
    $('spUpdRow').hidden = !canUpd;
    $('spUpdate').checked = !!canUpd;
    $('spUpdateLbl').textContent = 'Vorlage „' + profile.name + '“ aktualisieren (sonst neu anlegen)';
    $('spShared').checked = true;
    $('spTitle').textContent = 'Als Vorlage sichern';
    $('spHint').textContent = 'Vorlagen sichern nur die Anforderungen, nicht die Besetzung.';
    $('spSaveBtn').onclick = function () {
      var name = $('spName').value.trim();
      if (!name) { say('Bitte einen Namen angeben.', 'warning'); $('spName').focus(); return; }
      var upd = !$('spUpdRow').hidden && $('spUpdate').checked;
      post('profile_save', { id: upd ? profile.id : 0, name: name, description: $('spDesc').value.trim(),
        shared: $('spShared').checked ? 1 : 0, config: JSON.stringify(S) }).then(function (d) {
        if (!d.success) { say(d.error || 'Speichern fehlgeschlagen.', 'error'); return; }
        profile = { id: d.id, name: name, own: true };
        cM('ovSave'); say('Vorlage gespeichert.', 'success');
      }).catch(function (e) { say(e.message, 'error'); });
    };
    oM('ovSave');
  }

  /* ===================================================== Planungen */
  function savePlan() {
    if (!R) { say('Bitte zuerst einen Vorschlag berechnen.', 'warning'); return; }
    $('spName').value = plan.name || S.title || (R.area ? R.area.name : '');
    $('spDesc').value = '';
    $('spDescRow').hidden = true; $('spUpdRow').hidden = true;
    $('spShared').checked = !!plan.shared;
    $('spTitle').textContent = plan.id ? 'Planung speichern' : 'Planung sichern';
    $('spHint').textContent = (plan.id && plan.own) ? 'Die vorhandene Planung wird überschrieben.'
                                                    : 'Die Planung wird neu angelegt und erscheint in der Übersicht.';
    $('spSaveBtn').onclick = function () {
      var name = $('spName').value.trim();
      if (!name) { say('Bitte einen Namen angeben.', 'warning'); $('spName').focus(); return; }
      post('plan_save', { id: (plan.own ? plan.id : 0), name: name, shared: $('spShared').checked ? 1 : 0,
        config: JSON.stringify(S), plan: JSON.stringify(R.plan), stats: JSON.stringify(R.stats) }).then(function (d) {
        if (!d.success) { say(d.error || 'Speichern fehlgeschlagen.', 'error'); return; }
        plan.id = d.id; plan.name = name; plan.shared = $('spShared').checked ? 1 : 0; plan.own = true;
        unsaved = false; paintSummaries();
        cM('ovSave'); say('Planung gespeichert.', 'success');
      }).catch(function (e) { say(e.message, 'error'); });
    };
    oM('ovSave');
  }
  function openPlan(id) {
    busy(true, 'Planung wird geladen …');
    post('plan_load', { id: id }).then(function (d) {
      busy(false);
      if (!d.success) { say(d.error || 'Planung nicht gefunden.', 'error'); return; }
      resetState();
      S = C.mergeConfig(BOOT, d.config);
      plan = { id: d.run_id, name: d.name || '', shared: d.shared, own: !!d.own, applied: d.applied_at || null };
      R = { days: d.days, people: d.people, plan: d.plan || {}, area: d.area, manual: true };
      R.days.forEach(function (day) { if (!Array.isArray(R.plan[day.date])) R.plan[day.date] = []; });
      writeForm(); loadCandidates();
      $('resEmpty').hidden = true; $('resWrap').hidden = false;
      wizard(); maxSeen = 2; go(2);
      unsaved = false; paintSummaries();
      var n = conflicts.filter(function (c) { return c.level === 'block'; }).length;
      say(n ? 'Planung geladen – ' + n + ' Punkt(e) haben sich seit dem Speichern ergeben.' : 'Planung geladen.', n ? 'warning' : 'success');
    }).catch(function (e) { busy(false); say(e.message, 'error'); });
  }
  function deletePlan(id) {
    ask({ title: 'Planung entfernen?', text: 'Die gespeicherte Planung verschwindet aus der Übersicht. Bereits übernommene Entwürfe bleiben bestehen.', ok: 'Entfernen', danger: true })
      .then(function (yes) {
        if (!yes) return;
        post('plan_delete', { id: id }).then(function (r) {
          if (r.success) { say('Planung entfernt.', 'success'); loadHome(); }
          else say(r.error || 'Löschen nicht möglich.', 'error');
        });
      });
  }

  /* ===================================================== Übersicht */
  function loadHome() {
    post('plan_list', {}).then(renderPlans).catch(function (e) { $('homeList').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
    post('profile_list', {}).then(function (d) {
      if (!d.success) { $('tplList').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
      $('tplCount').textContent = d.items.length ? d.items.length + ' Vorlage(n)' : '';
      if (!d.items.length) {
        $('tplList').innerHTML = '<p class="muted pqa-small">Noch keine Vorlagen. Wiederkehrende Einsätze lassen sich im Assistenten unter „Mehr → Als Vorlage sichern“ ablegen und hier mit einem Klick starten.</p>';
        return;
      }
      $('tplList').innerHTML = '<ul class="pqa-tpl-ul">' + d.items.slice(0, 5).map(function (p) { return tplItem(p, d.me, false); }).join('') + '</ul>' +
        (d.items.length > 5 ? '<button type="button" class="btn btn-xs btn-outline" data-act="templates">Alle ' + d.items.length + ' Vorlagen</button>' : '');
      paint($('tplList'));
    }).catch(function (e) { $('tplList').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
    post('run_list', {}).then(function (d) {
      if (!d.success) { $('runList').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
      var items = d.items || [];
      $('runCount').textContent = items.length ? items.length + ' aktiv' : '';
      if (!items.length) { $('runList').innerHTML = '<p class="muted pqa-small">Keine Übernahmen, die sich noch zurücknehmen lassen.</p>'; return; }
      $('runList').innerHTML = '<ul class="pqa-run-ul">' + items.slice(0, 6).map(function (r) {
        var mine = String(r.created_by) === String(d.me), rem = +r.remaining;
        return '<li class="pqa-run">' + areaChip({ short_code: r.short_code, color: r.color, fg_color: r.fg_color }) +
          '<span class="pqa-tpl-n"><b>' + esc(r.title) + '</b>' +
          '<small>' + dmy(r.start_date) + ' – ' + dmy(r.end_date) + ' · übernommen ' + dmyt(r.applied_at) + (mine ? '' : ' · von ' + esc(r.owner || 'Kollege')) + '</small>' +
          '<small>' + r.slots_filled + ' Einträge · ' + (rem ? rem + ' noch als Entwurf offen' : 'alle bereits veröffentlicht oder geändert') + '</small></span>' +
          (rem ? '<button type="button" class="btn btn-xs btn-outline pqa-btn-undo" data-undo="' + r.id + '" data-n="' + rem + '">Rückgängig</button>' : '') +
          '</li>';
      }).join('') + '</ul>';
      paint($('runList'));
    }).catch(function (e) { $('runList').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }
  function renderPlans(d) {
    if (!d.success) { $('homeList').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
    $('homeCount').textContent = d.items.length ? d.items.length + ' Planung(en)' : '';
    if (!d.items.length) {
      $('homeList').innerHTML = '<div class="pqa-empty-state"><p><b>Noch keine gespeicherte Planung.</b></p>' +
        '<p class="muted">Legen Sie eine neue Planung an. Sie können sie jederzeit speichern und später weiterbearbeiten – auch bevor Sie sie in die Monatsplanung übernehmen.</p>' +
        '<button type="button" class="btn btn-primary" data-act="new">Neue Planung</button></div>';
      return;
    }
    $('homeList').innerHTML = '<ul class="pqa-plans">' + d.items.map(function (p) {
      var cov = p.slots_total > 0 ? Math.round(p.slots_filled / p.slots_total * 100) : 0;
      var lvl = cov >= 100 ? 'ok' : (cov >= 80 ? 'part' : 'bad');
      var mine = String(p.created_by) === String(d.me);
      return '<li class="pqa-plan">' +
        '<div class="pqa-plan-h">' + areaChip({ short_code: p.short_code, color: p.color, fg_color: p.fg_color }) +
          '<h3 class="pqa-plan-t" title="' + esc(p.title) + '">' + esc(p.title) + '</h3>' +
          (p.applied_at ? '<span class="badge bs">übernommen</span>' : '<span class="badge bw">Entwurf</span>') + '</div>' +
        '<p class="pqa-plan-m">' + dmy(p.start_date) + ' – ' + dmy(p.end_date) + (p.area_name ? ' · ' + esc(p.area_name) : '') + '<br>' +
          p.slots_filled + ' von ' + p.slots_total + ' Plätzen' +
          (p.conflicts > 0 ? ' · ' + p.conflicts + ' offene Punkte' : ' · keine offenen Punkte') +
          (mine ? '' : ' · von ' + esc(p.owner || 'Kollege')) + '</p>' +
        '<span class="pqa-bar is-' + lvl + '" role="img" aria-label="Abdeckung ' + cov + ' %"><i' + css('width:' + Math.min(100, cov) + '%') + '></i></span>' +
        '<p class="pqa-plan-m pqa-small">Zuletzt geändert: ' + dmyt(p.updated_at || p.created_at) + (p.shared == 1 ? ' · freigegeben' : '') + '</p>' +
        '<div class="pqa-plan-b"><button type="button" class="btn btn-xs btn-primary" data-open="' + p.id + '">Öffnen</button>' +
          (mine ? '<button type="button" class="btn btn-xs btn-outline" data-del="' + p.id + '" aria-label="Planung ' + esc(p.title) + ' löschen">Löschen</button>' : '') +
        '</div></li>';
    }).join('') + '</ul>';
    paint($('homeList'));
  }

  function home() {
    closeCtx();
    $('epaHome').hidden = false; $('epaWizard').hidden = true;
    window.scrollTo({ top: 0 });
    loadHome();
  }
  function wizard() {
    $('epaHome').hidden = true; $('epaWizard').hidden = false;
    window.scrollTo({ top: 0 });
  }
  function resetState() {
    R = null; formDirty = false; unsaved = false; lastRun = null; applied = null; preview = null; rowOrder = null;
    plan = { id: 0, name: '', shared: 0, own: true, applied: null };
    profile = { id: 0, name: '', own: false };
    focusCell = null; maxSeen = 0; conflicts = []; plannedOnly = true;
    $('resWrap').hidden = true; $('resEmpty').hidden = false;
    paintUndo();
  }
  function newPlan() {
    resetState();
    S = C.defaults(BOOT);
    try { sessionStorage.removeItem(KEY); } catch (e) {}
    writeForm(); loadCandidates(); renderLive();
    wizard(); go(0, { focus: false });
    setTimeout(function () { $('fArea').focus(); }, 60);
  }
  function reset() {
    ask({ title: 'Zurücksetzen?', text: 'Alle Eingaben und der Vorschlag werden verworfen. Gespeicherte Planungen und bereits übernommene Entwürfe bleiben unberührt.', ok: 'Zurücksetzen', danger: true })
      .then(function (yes) { if (yes) { newPlan(); say('Zurückgesetzt.', 'info'); } });
  }

  /* ===================================================== Drucken */
  /* Druckfassung des Vorschlags. Über den Druckdialog lässt sich daraus auch
     eine PDF-Datei speichern – ohne zusätzliche Software auf dem Server. */
  function printPlan() {
    if (!R) { say('Bitte zuerst einen Vorschlag berechnen.', 'warning'); return; }
    var byId = {}; R.people.forEach(function (p) { byId[p.id] = p; });
    var a = R.area || {}, s = R.stats;
    var days = R.days, used = R.people.filter(function (p) { return (R.load[p.id] || 0) > 0; });
    used.sort(function (x, y) { return (R.load[y.id] || 0) - (R.load[x.id] || 0) || x.name.localeCompare(y.name, 'de'); });
    var planDays = days.filter(function (d) { return !d.skip; }).length;
    var h = '<h1>' + esc(S.title || 'Einsatzplanung') + (a.short_code ? ' · ' + esc(a.short_code) : '') + '</h1>' +
      '<table class="epa-hd"><tr>' +
        '<td><b>Kunde / Bereich</b><br>' + esc((a.short_code ? a.short_code + ' – ' : '') + (a.name || '–')) + '</td>' +
        '<td><b>Zeitraum</b><br>' + dmy(S.start) + ' – ' + dmy(S.end) + ' · ' + planDays + ' Einsatztage</td>' +
        '<td><b>Abdeckung</b><br>' + s.coverage + ' % · ' + s.filled + ' von ' + s.total + ' Plätzen</td>' +
        '<td><b>Beteiligte</b><br>' + s.used + ' Personen</td>' +
        '<td><b>Stand</b><br>' + dmy(BOOT.today) + '</td>' +
      '</tr></table>' +
      '<div class="epa-pmeta"><b>Entwurf aus dem Planungsassistenten – noch nicht veröffentlicht.</b>' +
      (S.cross_team ? ' Teamübergreifend geplant.' : '') + (S.remarks ? '<br>Bemerkung: ' + esc(S.remarks) : '') + '</div>';
    h += '<div class="epa-sec">Einsatzmatrix</div>';
    var scale = days.length > 45 ? ' xs' : (days.length > 30 ? ' sm' : '');
    h += '<table class="epa-mx' + scale + '"><thead><tr class="epa-kwrow"><th class="epa-n"></th>';
    var kwRun = [], lastK = null;
    days.forEach(function (d) {
      var k = C.isoWeek(d.date);
      if (k !== lastK) { kwRun.push({ kw: k, n: 1 }); lastK = k; } else kwRun[kwRun.length - 1].n++;
    });
    kwRun.forEach(function (r) { h += '<th colspan="' + r.n + '" class="epa-kwh">KW ' + r.kw + '</th>'; });
    h += '<th></th></tr><tr><th class="epa-n">Mitarbeiter</th>';
    lastK = null;
    days.forEach(function (d) {
      var k = C.isoWeek(d.date), nk = k !== lastK; lastK = k;
      h += '<th class="' + (d.skip ? 'epa-off' : (d.weekend ? 'epa-we' : '')) + (nk ? ' epa-wk' : '') + '"><span class="epa-dd">' +
           parseInt(d.date.slice(8), 10) + '</span><span class="epa-wd">' + WD[d.wd] + '</span></th>';
    });
    h += '<th class="epa-sum">Σ</th></tr></thead><tbody>';
    used.forEach(function (p) {
      h += '<tr><td class="epa-n">' + esc(p.name) + (p.azubi_year ? ' <span class="epa-az">Azubi</span>' : '') + '</td>';
      lastK = null;
      days.forEach(function (d) {
        var k = C.isoWeek(d.date), nk = k !== lastK; lastK = k;
        var on = (R.plan[d.date] || []).indexOf(p.id) >= 0, st = C.stateOf(p, d.date);
        var cls = (nk ? 'epa-wk ' : '') + (d.skip ? 'epa-off' : (d.weekend ? 'epa-we' : '')), txt = '', style = '';
        if (on) { cls += ' epa-pk'; txt = esc(a.short_code || 'X'); style = 'background:' + (a.color || '#333') + ';color:' + (a.fg_color || '#fff'); }
        else if (st && st.t === 'einsatz') { txt = esc(st.l.replace(' •', '')); cls += ' epa-other'; }
        else if (st && (st.t === 'abwesend' || st.t === 'gesperrt' || st.t === 'schule')) {
          txt = st.t === 'schule' ? 'BS' : (st.t === 'gesperrt' ? '—' : 'A'); cls += ' epa-abs';
        }
        h += '<td class="' + cls + '"' + (style ? css(style) : '') + '>' + txt + '</td>';
      });
      h += '<td class="epa-sum">' + (R.load[p.id] || 0) + '</td></tr>';
    });
    h += '<tr class="epa-tot"><td class="epa-n">Besetzung / Bedarf</td>';
    lastK = null;
    days.forEach(function (d) {
      var k = C.isoWeek(d.date), nk = k !== lastK; lastK = k;
      var n = (R.plan[d.date] || []).length;
      h += '<td class="' + (nk ? 'epa-wk ' : '') + (!d.skip && n < d.required ? 'epa-gap' : '') + '">' + (d.skip ? '–' : n + '/' + d.required) + '</td>';
    });
    h += '<td class="epa-sum">' + s.filled + '</td></tr></tbody></table>';
    h += '<div class="epa-mxleg"><span><i class="epa-lp"' + css('background:' + (a.color || '#333')) + '></i> geplant</span>' +
         '<span><i class="epa-lo"></i> anderer Einsatz</span><span><i class="epa-la"></i> abwesend / gesperrt</span>' +
         '<span><i class="epa-lw"></i> Wochenende, kein Bedarf</span></div>';
    /* Tagesliste – die eigentlich nützliche Fassung zum Weitergeben. */
    h += '<div class="epa-sec epa-pb">Einsatzliste</div><table class="epa-lst"><thead><tr>' +
         '<th class="epa-w22">Tag</th><th class="epa-w8">Soll</th><th>Besetzung</th></tr></thead><tbody>';
    var lastKw = null;
    days.forEach(function (d) {
      var kw = C.isoWeek(d.date);
      if (kw !== lastKw) { lastKw = kw; h += '<tr class="epa-kwsep"><td colspan="3">Kalenderwoche ' + kw + '</td></tr>'; }
      var ids = R.plan[d.date] || [];
      if (d.skip) { h += '<tr class="epa-off"><td class="epa-n">' + esc(d.label) + '</td><td>–</td><td class="epa-n">' + esc(d.reason || 'kein Bedarf') + '</td></tr>'; return; }
      var names = ids.map(function (u) { var p = byId[u]; return p ? esc(p.name) + (p.azubi_year ? ' (Azubi)' : '') : ''; }).filter(Boolean).join(', ');
      h += '<tr' + (ids.length < d.required ? ' class="epa-gap"' : '') + '><td class="epa-n">' + esc(d.label) + '</td>' +
           '<td>' + ids.length + '/' + d.required + '</td><td class="epa-n">' + (names || '<i>nicht besetzt</i>') + '</td></tr>';
    });
    h += '</tbody></table>';
    h += '<div class="epa-sec">Einsatztage je Person</div><table class="epa-lst"><thead><tr>' +
         '<th>Mitarbeiter</th><th class="epa-w12">Tage</th><th class="epa-w34">Zeitraum</th></tr></thead><tbody>';
    used.forEach(function (p) {
      var dts = days.filter(function (d) { return (R.plan[d.date] || []).indexOf(p.id) >= 0; });
      h += '<tr><td class="epa-n">' + esc(p.name) + '</td><td>' + (R.load[p.id] || 0) + '</td><td class="epa-n">' +
           (dts.length ? dmy(dts[0].date) + ' – ' + dmy(dts[dts.length - 1].date) : '') + '</td></tr>';
    });
    h += '</tbody></table>';
    /* Datenschutz: Der Ausdruck verlässt das System. Er enthält deshalb nur
       tagesbezogene Befunde – keine personenbezogenen Gründe (Urlaub, Krankheit). */
    var open = conflicts.filter(function (c) { return c.level !== 'info'; });
    var dayLevel = open.filter(function (c) { return !c.uid; });
    var personLevel = open.length - dayLevel.length;
    if (dayLevel.length) {
      h += '<div class="epa-sec">Offene Punkte (' + dayLevel.length + ')</div><ul>';
      dayLevel.slice(0, 40).forEach(function (c) { h += '<li>' + (c.level === 'block' ? 'Blocker: ' : 'Warnung: ') + esc(c.msg) + '</li>'; });
      if (dayLevel.length > 40) h += '<li>… und ' + (dayLevel.length - 40) + ' weitere</li>';
      h += '</ul>';
    } else h += '<div class="epa-sec">Keine offenen Punkte auf Tagesebene.</div>';
    if (personLevel) h += '<p class="epa-small">Zusätzlich ' + personLevel + ' personenbezogene(r) Hinweis(e) – aus Datenschutzgründen nicht im Ausdruck enthalten, sie stehen in der Prüfliste im Assistenten.</p>';
    h += '<div class="epa-pfoot">Erstellt mit dem Planungsassistenten der Einsatzplanung · Ausdruck vom ' + new Date().toLocaleDateString('de-DE') +
         ' · Entwurfsstand, Änderungen vorbehalten.<br>Enthält bewusst keine Abwesenheitsgründe. Personenbezogene Daten – bitte vertraulich behandeln und nicht länger aufbewahren als nötig.</div>';
    var box = $('epaPrint');
    box.innerHTML = h; paint(box);
    /* Der Druck-Stil blendet alle direkten Kinder von <body> aus – der Behälter
       muss deshalb direkt unter <body> liegen. */
    if (box.parentNode !== document.body) document.body.appendChild(box);
    say('Druckansicht wird geöffnet – im Dialog kann auch „Als PDF speichern“ gewählt werden.', 'info');
    setTimeout(function () { window.print(); }, 300);
  }

  /* ===================================================== Ereignisse */
  function closeMenu() { $('moreMenu').hidden = true; $('moreBtn').setAttribute('aria-expanded', 'false'); }
  function bindUi() {
    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t.closest('#epaCtx')) closeCtx();
      if (!t.closest('.pqa-menu')) closeMenu();
      var cl = t.closest('[data-close]');
      if (cl && !cl.onclick) { cM(cl.dataset.close); return; }
      var b = t.closest('[data-act]');
      if (b && !b.disabled) {
        var act = b.dataset.act;
        if (b.closest('#moreMenu')) closeMenu();
        if (act === 'new') newPlan();
        else if (act === 'home') home();
        else if (act === 'save-plan') savePlan();
        else if (act === 'templates') openProfiles();
        else if (act === 'save-profile') { cM('ovProf'); saveProfile(); }
        else if (act === 'print') printPlan();
        else if (act === 'reset') reset();
        else if (act === 'generate') generate({ confirm: true });
        else if (act === 'generate-confirm') generate({ confirm: true, silent: false });
        else if (act === 'resort') { if (R) { buildRowOrder(); renderMatrix(); say('Zeilen neu sortiert.', 'info'); } }
        else if (act === 'apply') doApply();
        else if (act === 'undo') undo();
        return;
      }
      var g = t.closest('[data-go]'); if (g) { go(+g.dataset.go, { focusHeading: true }); return; }
      var st = t.closest('.pqa-step'); if (st) { go(+st.dataset.step, { focusHeading: true }); return; }
      var x;
      if ((x = t.closest('[data-tpl]'))) { useProfile(+x.dataset.tpl); return; }
      if ((x = t.closest('[data-tpldel]'))) { deleteProfile(+x.dataset.tpldel); return; }
      if ((x = t.closest('[data-open]'))) { openPlan(+x.dataset.open); return; }
      if ((x = t.closest('[data-del]'))) { deletePlan(+x.dataset.del); return; }
      if ((x = t.closest('[data-undo]'))) { undo(+x.dataset.undo, +x.dataset.n); return; }
      if ((x = t.closest('[data-jump]'))) { var r = $('epaRecap')._rows[+x.dataset.jump]; if (r) jumpField(r.step, r.field); return; }
      if ((x = t.closest('[data-kpi]'))) { cfFilter = x.dataset.kpi; renderConflicts(); $('cfList').scrollIntoView({ block: 'nearest', behavior: 'smooth' }); return; }
      if ((x = t.closest('[data-cf]'))) {
        var c = ($('cfList')._items || [])[+x.dataset.cf]; if (!c) return;
        if (x.dataset.cfa === 'alt') openAlt(c.date); else jumpTo(c);
        return;
      }
      if ((x = t.closest('[data-fill]'))) { openAlt(x.dataset.fill); return; }
      if ((x = t.closest('[data-lvl]'))) { cfFilter = x.dataset.lvl; renderConflicts(); return; }
      if ((x = t.closest('[data-view]'))) { setView(x.dataset.view); return; }
      if ((x = t.closest('[data-ctx]'))) {
        var m = $('epaCtx'); var uid = +m.dataset.uid, date = m.dataset.date || null;
        closeCtx(true); ctxAction(x.dataset.ctx, uid, date); return;
      }
    });

    /* Mehr-Menü */
    $('moreBtn').addEventListener('click', function (e) {
      e.stopPropagation();
      var open = $('moreMenu').hidden;
      $('moreMenu').hidden = !open; $('moreBtn').setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var f = $('moreMenu').querySelector('[role=menuitem]'); if (f) f.focus(); }
    });
    $('moreMenu').addEventListener('keydown', function (e) { menuKeys(e, $('moreMenu'), function () { closeMenu(); $('moreBtn').focus(); }); });
    $('epaCtx').addEventListener('keydown', function (e) { menuKeys(e, $('epaCtx'), function () { closeCtx(true); }); });

    /* Ansicht-Reiter per Pfeiltasten */
    document.querySelector('.pqa-tabs').addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-view]'));
      var i = tabs.indexOf(document.activeElement); if (i < 0) return;
      var n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      e.preventDefault(); setView(n.dataset.view); n.focus();
    });

    $('btnOnly').addEventListener('change', function () { plannedOnly = $('btnOnly').checked; if (R) { renderMatrix(); renderLegend(); } });
    $('btnBack').addEventListener('click', function () { go(step - 1, { focusHeading: true }); });
    $('btnNext').addEventListener('click', next);
    $('daysView').addEventListener('change', function (e) { if (e.target.id === 'dvGaps') { daysOnlyGaps = e.target.checked; renderDays(); } });

    /* Raster: Maus */
    var mx = $('matrix');
    mx.addEventListener('click', function (e) {
      var head = e.target.closest('[data-day]'); if (head) { openAlt(head.dataset.day); return; }
      var c = e.target.closest('.pqa-cell[data-u]'); if (!c) return;
      toggleCell(+c.dataset.u, c.dataset.d);
    });
    mx.addEventListener('contextmenu', function (e) {
      var c = e.target.closest('.pqa-cell[data-u]'), row = e.target.closest('tr[data-uid]');
      var uid = c ? +c.dataset.u : (row ? +row.dataset.uid : 0);
      if (!uid) return;
      e.preventDefault(); e.stopPropagation();
      if (c) c.focus();
      openCtx(e.clientX, e.clientY, uid, c ? c.dataset.d : null);
    });
    /* Raster: Tastatur (Pfeile, Pos1/Ende, Bild auf/ab, Kontextmenü) */
    mx.addEventListener('focusin', function (e) {
      var c = e.target.closest('.pqa-cell[data-u]'); if (!c) return;
      mx.querySelectorAll('.pqa-cell[tabindex="0"]').forEach(function (x) { if (x !== c) x.tabIndex = -1; });
      c.tabIndex = 0; focusCell = { u: +c.dataset.u, d: c.dataset.d };
    });
    mx.addEventListener('keydown', function (e) {
      var c = e.target.closest('.pqa-cell[data-u]'); if (!c) return;
      if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
        e.preventDefault();
        var rc = c.getBoundingClientRect();
        openCtx(rc.left + rc.width / 2, rc.bottom, +c.dataset.u, c.dataset.d);
        return;
      }
      var mv = C.gridMove(+c.dataset.r, +c.dataset.c, e.key, gridIdx.length, gridIdx.length ? gridIdx[0].length : 0, e.ctrlKey || e.metaKey);
      if (!mv) return;
      e.preventDefault();
      var t = $(gridIdx[mv[0]] && gridIdx[mv[0]][mv[1]]);
      if (t) { c.tabIndex = -1; t.tabIndex = 0; t.focus(); t.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { closeCtx(true); if (!$('moreMenu').hidden) { closeMenu(); $('moreBtn').focus(); } }
    });
    /* Scrollen schließt das Menü – außer unmittelbar nach dem Öffnen (Fokus kann scrollen) */
    window.addEventListener('scroll', function () { if (Date.now() - ctxOpenedAt > 400) closeCtx(); }, { passive: true });
    window.addEventListener('beforeunload', function (e) {
      if (R && unsaved && !lastRun) { e.preventDefault(); e.returnValue = ''; }
    });
  }
  function menuKeys(e, menu, close) {
    var items = Array.prototype.slice.call(menu.querySelectorAll('[role=menuitem]'));
    var i = items.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
    else if (e.key === 'Home') { e.preventDefault(); items[0].focus(); }
    else if (e.key === 'End') { e.preventDefault(); items[items.length - 1].focus(); }
    else if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); close(); }
  }

  /* ===================================================== Start */
  function init() {
    buildAreas(); buildToggles(); buildPresets();
    bindForm(); bindUi();
    try {
      var saved = sessionStorage.getItem(KEY);
      if (saved) S = C.mergeConfig(BOOT, JSON.parse(saved), { fillFree: true });
    } catch (e) { S = C.defaults(BOOT); }
    if (!BOOT.perm.internal) $('rowInternal').hidden = true;
    if (!BOOT.may_cross || !BOOT.own_team_only) { $('rowCross').hidden = true; S.cross_team = false; }
    writeForm(); loadCandidates(); renderLive(); paintUndo();
    go(0, { noScroll: true, focus: false });
    home();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  /* Für Tests und Fehlersuche (keine Inline-Handler nötig) */
  window.EPA = { go: go, generate: generate, newPlan: newPlan, home: home, state: function () { return { S: S, R: R, step: step, lastRun: lastRun }; } };
})();
