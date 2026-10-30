/* === Planquadro – Planungshilfen für Monats- und Gesamtplan ===============
 * Alles hier ist eine ERGÄNZUNG. Raster, Werkzeugleiste, Klick-/Zieh-Bedienung,
 * Tastatur, Drag & Drop, Konfliktdialog und Live-Aktualisierung bleiben
 * unverändert (public/assets/js/app.js). Einhängepunkte in app.js:
 *   - Ereignis 'pq:bulk-saved' nach erfolgreichem api/save_bulk
 *   - window.pqTipExtra(cell, parts) / window.pqShowCellTip(cell) im Zell-Tooltip
 *
 *   1. Ansicht (je Benutzer, localStorage): Zeilendichte, Abdeckungszeile,
 *      Fadenkreuz, Wochentrenner, Details beim Überfahren
 *   2. Abdeckungszeile: eingeplante Personen je Tag und Bereich, Soll-Besetzung
 *   3. Zell-Details: voller Bereichsname, Kunde/Standort, Entwurf und Verlauf
 *   4. Legende: Klick hebt einen Bereich im Raster hervor
 *   5. Rückgängig/Wiederholen eigener Speichervorgänge (Strg+Z / Strg+Y)
 *   6. Woche/Zeitraum kopieren („Vorwoche übernehmen“)
 *   7. Druck: Name der Organisation im Kopf
 *
 * Geschrieben wird ausschließlich über saveBulk()/planWithConflictCheck()
 * (api/save_bulk mit Zell-Revisionen und dem bekannten Konfliktdialog).
 * Keine Inline-Handler (CSP ohne 'unsafe-inline').
 * ======================================================================== */

/* PQ-PLUS-LOGIC-BEGIN */
var pqPlus = {
    /* --- Datum (ohne Zeitzonen-Effekte) --- */
    parse: function(d) { var p = String(d).split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); },
    fmt: function(dt) { return dt.toISOString().slice(0, 10); },
    addDays: function(d, n) { var x = pqPlus.parse(d); x.setUTCDate(x.getUTCDate() + n); return pqPlus.fmt(x); },
    isoWeekday: function(d) { var w = pqPlus.parse(d).getUTCDay(); return w === 0 ? 7 : w; },
    mondayOf: function(d) { return pqPlus.addDays(d, 1 - pqPlus.isoWeekday(d)); },
    dayDiff: function(a, b) { return Math.round((pqPlus.parse(b) - pqPlus.parse(a)) / 86400000); },
    de: function(d) { var p = String(d).split('-'); return p[2] + '.' + p[1] + '.' + p[0]; },
    WD: ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'],

    /* --- Abdeckung ---
       cells: [{d, t, v}] (alle Zellen aller Zeilen), Rückgabe {total:{d:n}, byArea:{area:{d:n}}} */
    coverage: function(cells) {
        var total = {}, byArea = {};
        for (var i = 0; i < cells.length; i++) {
            var c = cells[i];
            if (c.t !== 'assignment' || !c.v) continue;
            total[c.d] = (total[c.d] || 0) + 1;
            (byArea[c.v] = byArea[c.v] || {})[c.d] = (byArea[c.v][c.d] || 0) + 1;
        }
        return { total: total, byArea: byArea };
    },
    /* Soll für Bereich/Tag (0 = keine Vorgabe); targets: {area: {weekday: n}} */
    need: function(targets, area, d) { var t = targets && targets[area]; return t ? (+t[pqPlus.isoWeekday(d)] || 0) : 0; },
    /* Tage mit Unterbesetzung: [{area, d, have, need}] – Feiertage ausgenommen */
    understaffed: function(cov, targets, dates, holidays) {
        var out = [], hol = {};
        (holidays || []).forEach(function(h) { hol[h] = 1; });
        Object.keys(targets || {}).forEach(function(a) {
            dates.forEach(function(d) {
                if (hol[d]) return;
                var n = pqPlus.need(targets, a, d), have = (cov.byArea[a] && cov.byArea[a][d]) || 0;
                if (n > 0 && have < n) out.push({ area: a, d: d, have: have, need: n });
            });
        });
        return out;
    },

    /* --- Zellzustand für Rückgängig: {t: ''|'assignment'|'absence', v, i: intern} --- */
    stateOfChange: function(ch) {
        if (ch.type === 'clear') return { t: '', v: '' };
        if (ch.type === 'locked') return { t: 'absence', v: 'gesperrt' };
        return { t: ch.type, v: String(ch.value) };
    },
    /* Vertrauliche Abwesenheit („A“, Art unbekannt) lässt sich nicht wiederherstellen */
    restorable: function(s) { return !!s && !(s.t === 'absence' && !s.v); },
    toChange: function(s, u, d, rev) {
        var c;
        if (!s.t) c = { user_id: +u, date: d, type: 'clear', value: '' };
        else if (s.t === 'absence' && s.v === 'gesperrt') c = { user_id: +u, date: d, type: 'locked', value: 'gesperrt' };
        else c = { user_id: +u, date: d, type: s.t, value: s.v };
        if (rev !== undefined && rev !== null) c.rev = String(rev);
        return c;
    },
    sameState: function(a, b) { return !!a && !!b && a.t === b.t && String(a.v) === String(b.v); },
    /* Eintrag für den Stapel aus gespeicherten Änderungen.
       prevOf(change) liefert den Zustand vor dem Speichern, revs die neuen Revisionen. */
    makeEntry: function(changes, prevOf, revs, internal) {
        var cells = [], seen = {};
        for (var i = changes.length - 1; i >= 0; i--) { /* letzte Änderung je Zelle gewinnt (wie save_bulk) */
            var ch = changes[i], k = ch.user_id + '_' + ch.date;
            if (seen[k] || !revs || revs[k] === undefined) continue;
            seen[k] = 1;
            var prev = prevOf(ch), next = pqPlus.stateOfChange(ch);
            next.i = !!internal;
            if (prev && pqPlus.sameState(prev, next) && !!prev.i === next.i) continue;
            cells.unshift({ u: String(ch.user_id), d: ch.date, prev: prev || null, next: next, rev: revs[k] || '0' });
        }
        return cells.length ? { cells: cells } : null;
    },
    /* Gruppen für die Wiederherstellung (je Sichtbarkeit ein Speichervorgang).
       dir 'undo' stellt prev her, 'redo' next. revOf(u, d) liefert die aktuelle Revision. */
    restoreGroups: function(entry, dir, revOf) {
        var groups = {}, skipped = 0;
        entry.cells.forEach(function(c) {
            var s = dir === 'undo' ? c.prev : c.next;
            if (!pqPlus.restorable(s)) { skipped++; return; }
            var key = s.i ? '1' : '0', r = revOf ? revOf(c.u, c.d) : null;
            (groups[key] = groups[key] || []).push(pqPlus.toChange(s, c.u, c.d, r !== null && r !== undefined ? r : c.rev));
        });
        return { groups: Object.keys(groups).sort().map(function(k) { return { internal: k === '1', changes: groups[k] }; }), skipped: skipped };
    },
    /* Nach erfolgreicher Wiederherstellung wandert der Eintrag (gleiche Richtung prev/next)
       auf den anderen Stapel – mit den neuen Revisionen. */
    withRevs: function(entry, revs) {
        return { cells: entry.cells.map(function(c) { var k = c.u + '_' + c.d; return { u: c.u, d: c.d, prev: c.prev, next: c.next, rev: revs && revs[k] !== undefined ? revs[k] : c.rev }; }) };
    },

    /* --- Woche/Zeitraum kopieren ---
       src: {"u_d": {type, value, internal, confidential}} (Stand der Quellwoche)
       rows: [{u, we, entry, exit}]   srcMonday: Montag der Quellwoche
       from/to: Zielzeitraum   opts: {absences, clearEmpty, holidays:[…]} */
    COPY_ABS: ['gleitzeit', 'gesperrt', 'offen'],
    copyChanges: function(src, rows, srcMonday, from, to, opts) {
        opts = opts || {};
        var hol = {}, out = [];
        (opts.holidays || []).forEach(function(h) { hol[h] = 1; });
        if (!from || !to || from > to) return out;
        for (var d = from; d <= to; d = pqPlus.addDays(d, 1)) {
            var wd = pqPlus.isoWeekday(d), sd = pqPlus.addDays(srcMonday, wd - 1);
            if (sd === d || hol[d]) continue;
            rows.forEach(function(r) {
                if (wd >= 6 && !r.we) return;
                if (r.entry && d < r.entry) return;
                if (r.exit && d > r.exit) return;
                var s = src[r.u + '_' + sd];
                if (s && s.type === 'assignment' && s.value) out.push({ user_id: +r.u, date: d, type: 'assignment', value: String(s.value) });
                else if (s && s.type === 'absence' && opts.absences && !s.confidential && pqPlus.COPY_ABS.indexOf(s.value) !== -1)
                    out.push(s.value === 'gesperrt' ? { user_id: +r.u, date: d, type: 'locked', value: 'gesperrt' } : { user_id: +r.u, date: d, type: 'absence', value: s.value });
                else if (!s && opts.clearEmpty && wd <= 5) out.push({ user_id: +r.u, date: d, type: 'clear', value: '' });
            });
        }
        return out;
    },

    /* --- Werkzeug-Tastenkürzel (ohne Strg/Alt, nicht in Eingabefeldern) --- */
    KEYS: [
        ['k', 'krank_attest', 'Krank mit Attest'], ['K', 'krank_ganz', 'Krank ohne Attest'], ['h', 'krank_halb', 'Krank ½ Tag'],
        ['u', 'urlaub_genehmigt', 'Urlaub genehmigt'], ['U', 'urlaub_ganz', 'Urlaub beantragt'], ['H', 'urlaub_halb', 'Urlaub ½ Tag'],
        ['g', 'gleitzeit', 'Gleitzeit'], ['o', 'offen', 'Offen / noch zu klären'],
        ['s', '#toolLock', 'Sperren'], ['l', '#toolClr', 'Löschen'], ['m', '#toolRemark', 'Bemerkung'], ['i', '#toolIntRemark', 'Interne Bemerkung'],
        ['v', '#toolInternal', 'Veröffentlichen / nicht veröffentlichen umschalten'], ['b', 'area', 'Einsatzbereich suchen'], ['?', 'help', 'Tastenkürzel anzeigen']
    ],
    keyAction: function(key) {
        if (/^[1-5]$/.test(key)) return { recent: +key - 1 };
        for (var i = 0; i < pqPlus.KEYS.length; i++) if (pqPlus.KEYS[i][0] === key) return { target: pqPlus.KEYS[i][1], label: pqPlus.KEYS[i][2] };
        return null;
    },
    keyFor: function(target) { for (var i = 0; i < pqPlus.KEYS.length; i++) if (pqPlus.KEYS[i][1] === target) return pqPlus.KEYS[i][0]; return ''; },
    keyLabel: function(k) { return k.length === 1 && k !== k.toLowerCase() ? 'Umschalt+' + k : k.toUpperCase(); },
    /* Zuletzt genutzte Bereiche: neu nach vorn, ohne Doppelte, höchstens n */
    pushRecent: function(list, id, n) { id = String(id); var out = [id]; (list || []).forEach(function(x) { if (String(x) !== id && out.length < (n || 5)) out.push(String(x)); }); return out; },
    filterAreas: function(areas, q) {
        q = String(q || '').toLowerCase().trim(); if (!q) return areas.slice();
        var words = q.split(/\s+/);
        return areas.filter(function(a) { var h = (a.code + ' ' + a.name).toLowerCase(); return words.every(function(w) { return h.indexOf(w) !== -1; }); })
            .sort(function(a, b) { var ca = a.code.toLowerCase().indexOf(q) === 0 ? 0 : 1, cb = b.code.toLowerCase().indexOf(q) === 0 ? 0 : 1; return ca - cb; });
    },

    /* --- Ansicht (je Benutzer) --- */
    VIEW_DEFAULTS: { density: 'standard', coverage: true, breakdown: false, crosshair: false, weeks: true, details: true, toolbar: true, keepTool: false },
    view: function(raw) {
        var v = {}, d = pqPlus.VIEW_DEFAULTS, o = {};
        try { o = raw ? JSON.parse(raw) : {}; } catch (e) { o = {}; }
        Object.keys(d).forEach(function(k) { v[k] = (o && typeof o[k] === typeof d[k]) ? o[k] : d[k]; });
        if (['compact', 'standard', 'comfort'].indexOf(v.density) === -1) v.density = 'standard';
        return v;
    }
};
/* PQ-PLUS-LOGIC-END */

(function() {
    'use strict';
    var cfg = null, tbl = null, view = null, live = null;
    var ABS_CODE = { krank_attest: 'K', krank_ganz: 'K', krank_halb: 'k', urlaub_genehmigt: 'U', urlaub_ganz: 'U', urlaub_halb: 'u', gleitzeit: 'GL', gesperrt: '—', offen: '?' };

    function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt !== undefined) e.textContent = txt; return e; }
    function say(msg) { if (!live) return; live.textContent = ''; setTimeout(function() { live.textContent = msg; }, 30); }
    function areaById(id) { if (!cfg) return null; for (var i = 0; i < cfg.areas.length; i++) if (String(cfg.areas[i].id) === String(id)) return cfg.areas[i]; return null; }
    function stateLabel(s) {
        if (!s || !s.t) return 'leer';
        if (s.t === 'assignment') { var a = areaById(s.v); return a ? a.code : 'Einsatz'; }
        return s.v ? (ABS_CODE[s.v] || s.v) : 'Abwesend';
    }
    function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
    function sstore(k, v) { try { if (v === undefined) return sessionStorage.getItem(k); sessionStorage.setItem(k, v); } catch (e) { return null; } }
    function api(body) {
        return fetch(getApiBase() + 'api/planning_plus', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function(r) { return r.json().then(function(d) { if (!r.ok) throw new Error(d && d.error || ('HTTP ' + r.status)); return d; }); });
    }
    function dayCells() { return tbl ? tbl.querySelectorAll('tbody .pe-c') : []; }
    function firstRowCells() { var r = tbl && tbl.querySelector('tbody .plan-row'); return r ? r.querySelectorAll('.pe-c') : []; }
    /* Spaltenindex (1-basiert, für :nth-child) der Tageszellen */
    function dayColumns() {
        var r = tbl && tbl.querySelector('tbody .plan-row'), out = [];
        if (!r) return out;
        Array.prototype.forEach.call(r.children, function(td, i) { if (td.classList.contains('pe-c')) out.push({ col: i + 1, d: td.dataset.d }); });
        return out;
    }
    var styleEl = null;
    function dynStyle(id, css) {
        if (!styleEl) { styleEl = el('style'); styleEl.id = 'pqPlusDyn'; document.head.appendChild(styleEl); styleEl._parts = {}; }
        styleEl._parts[id] = css;
        styleEl.textContent = Object.keys(styleEl._parts).map(function(k) { return styleEl._parts[k]; }).join('\n');
    }
    function tblSel() { return '#' + tbl.id; }

    /* ================= 1. Ansicht ================= */
    function saveView() { store('pq-plus-view', JSON.stringify(view)); }
    function applyView() {
        var b = document.body;
        b.classList.toggle('pq-density-compact', view.density === 'compact');
        b.classList.toggle('pq-density-comfort', view.density === 'comfort');
        b.classList.toggle('pq-crosshair', !!view.crosshair);
        weekSeparators();
        coverageRender();
    }
    function buildViewMenu() {
        var bar = document.querySelector('.plan-bar-actions');
        if (!bar) return;
        var det = el('details', 'pq-view no-print');
        var sum = el('summary', 'btn btn-outline pq-view-btn');
        sum.innerHTML = '<svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14" aria-hidden="true"><path d="M5 4a1 1 0 00-2 0v7.27a2 2 0 000 3.46V16a1 1 0 102 0v-1.27a2 2 0 000-3.46V4zM11 4a1 1 0 10-2 0v1.27a2 2 0 000 3.46V16a1 1 0 102 0V8.73a2 2 0 000-3.46V4zM16 3a1 1 0 011 1v7.27a2 2 0 010 3.46V16a1 1 0 11-2 0v-1.27a2 2 0 010-3.46V4a1 1 0 011-1z"/></svg> Ansicht';
        sum.title = 'Darstellung des Plans anpassen (wird in diesem Browser gemerkt)';
        det.appendChild(sum);
        var pop = el('div', 'pq-view-pop');
        pop.setAttribute('role', 'group'); pop.setAttribute('aria-label', 'Ansicht anpassen');
        var fs = el('fieldset', 'pq-view-fs'); fs.appendChild(el('legend', '', 'Zeilenhöhe'));
        [['compact', 'Kompakt'], ['standard', 'Standard'], ['comfort', 'Komfort']].forEach(function(o) {
            var l = el('label', 'pq-view-opt'), i = el('input'); i.type = 'radio'; i.name = 'pqDensity'; i.value = o[0]; i.checked = view.density === o[0];
            i.addEventListener('change', function() { view.density = o[0]; saveView(); applyView(); });
            l.appendChild(i); l.appendChild(document.createTextNode(' ' + o[1])); fs.appendChild(l);
        });
        pop.appendChild(fs);
        var opts = [['weeks', 'Wochentrenner (Linie vor Montag)'], ['crosshair', 'Fadenkreuz: Zeile und Tag hervorheben']];
        if (cfg.coverage) opts.unshift(['coverage', 'Abdeckungszeile unter dem Plan'], ['breakdown', 'Abdeckung je Bereich aufschlüsseln']);
        if (cfg.page === 'monatsplanung' && cfg.edit) opts.push(['toolbar', 'Werkzeugleiste gruppiert (mit Bereichssuche und Schnellwahl)'], ['keepTool', 'Werkzeug beim Monatswechsel behalten']);
        if (cfg.page === 'monatsplanung') opts.push(['details', cfg.history ? 'Details beim Überfahren (Bereich, Entwurf, Verlauf)' : 'Bereichsdetails beim Überfahren']);
        else opts.push(['details', 'Bereichsdetails beim Überfahren']);
        opts.forEach(function(o) {
            var l = el('label', 'pq-view-opt'), i = el('input'); i.type = 'checkbox'; i.checked = !!view[o[0]]; i.dataset.k = o[0];
            i.addEventListener('change', function() {
                view[o[0]] = i.checked; saveView();
                if (o[0] === 'toolbar') { toast('Die Werkzeugleiste wird neu geladen …', 'info'); setTimeout(function() { location.reload(); }, 400); return; }
                if (o[0] === 'keepTool' && !i.checked) sstore('pq-tool', '');
                applyView(); syncViewInputs();
            });
            l.appendChild(i); l.appendChild(document.createTextNode(' ' + o[1])); pop.appendChild(l);
        });
        var rs = el('button', 'btn btn-xs btn-outline pq-view-reset', 'Standard wiederherstellen'); rs.type = 'button';
        rs.addEventListener('click', function() { view = pqPlus.view(''); saveView(); applyView(); syncViewInputs(); });
        pop.appendChild(rs);
        det.appendChild(pop);
        bar.insertBefore(det, bar.firstChild);
        document.addEventListener('click', function(e) { if (det.open && !det.contains(e.target)) det.open = false; });
        det.addEventListener('keydown', function(e) { if (e.key === 'Escape' && det.open) { e.stopPropagation(); det.open = false; sum.focus(); } });
        function syncViewInputs() {
            pop.querySelectorAll('input[type=checkbox]').forEach(function(i) { i.checked = !!view[i.dataset.k]; });
            pop.querySelectorAll('input[type=radio]').forEach(function(i) { i.checked = i.value === view.density; });
        }
    }
    function weekSeparators() {
        if (!view.weeks) { dynStyle('weeks', ''); return; }
        var rules = dayColumns().filter(function(c, i) { return i > 0 && pqPlus.isoWeekday(c.d) === 1; })
            .map(function(c) { return tblSel() + ' tr > :nth-child(' + c.col + ')'; });
        dynStyle('weeks', rules.length ? rules.join(',') + '{border-left:2px solid var(--pq-week-line) !important}' : '');
    }
    function initCrosshair() {
        var cols = null;
        tbl.addEventListener('mouseover', function(e) {
            if (!view.crosshair) return;
            var c = e.target.closest('.pe-c'); if (!c) return;
            var i = Array.prototype.indexOf.call(c.parentNode.children, c) + 1;
            if (cols === i) return; cols = i;
            dynStyle('cross', tblSel() + ' tbody tr.plan-row > :nth-child(' + i + '),' + tblSel() + ' thead tr > :nth-child(' + i + '){box-shadow:inset 0 0 0 999px var(--pq-cross)}');
        });
        tbl.addEventListener('mouseleave', function() { cols = null; dynStyle('cross', ''); });
    }

    /* ================= 2. Abdeckungszeile ================= */
    var covTimer = null;
    function coverageSchedule() { clearTimeout(covTimer); covTimer = setTimeout(coverageRender, 150); }
    function coverageRender() {
        if (!tbl) return;
        var old = tbl.querySelector('tfoot.pq-cov');
        if (!cfg.coverage || !view.coverage) { if (old) old.remove(); return; }
        var head = tbl.querySelector('thead tr'), cols = dayColumns();
        if (!head || !cols.length) { if (old) old.remove(); return; }
        var cells = Array.prototype.map.call(dayCells(), function(c) { return { d: c.dataset.d, t: c.dataset.t, v: c.dataset.v }; });
        var cov = pqPlus.coverage(cells), dates = cols.map(function(c) { return c.d; });
        var hols = (typeof HOLIDAYS !== 'undefined') ? HOLIDAYS : [];
        var targets = cfg.targets || {};
        var under = pqPlus.understaffed(cov, targets, dates, hols), underBy = {};
        under.forEach(function(u) { (underBy[u.d] = underBy[u.d] || []).push(u); });
        var areaIds = cfg.areas.map(function(a) { return String(a.id); }).filter(function(id) { return cov.byArea[id] || targets[id]; });
        var tf = el('tfoot', 'pq-cov');
        var heads = Array.prototype.slice.call(head.children);
        var dateByCol = {}; cols.forEach(function(c) { dateByCol[c.col] = c.d; });
        function makeRow(cls, label, valueOf, titleOf, warnOf) {
            var tr = el('tr', cls);
            heads.forEach(function(th, i) {
                var d = dateByCol[i + 1];
                var td = el('td');
                if (d) {
                    td.className = 'cd pq-cov-c' + (th.classList.contains('we') ? ' we' : '') + (th.classList.contains('hl-ft') ? ' hl-ft' : '') + (th.classList.contains('td-head') ? ' pq-cov-today' : '');
                    var v = valueOf(d); td.textContent = v; td.title = titleOf(d);
                    if (warnOf(d)) td.classList.add('pq-cov-under');
                } else {
                    td.className = th.className.replace(/\b(td-head|we|hl-ft|hl-fe)\b/g, '') + ' pq-cov-x';
                    if (i === 0) td.appendChild(label);
                }
                tr.appendChild(td);
            });
            return tr;
        }
        if (view.breakdown) {
            areaIds.forEach(function(id) {
                var a = areaById(id), lab = el('span', 'pq-cov-lab');
                var chip = el('span', 'pq-cov-chip', a.code); chip.style.background = a.color; chip.style.color = a.fg;
                lab.appendChild(chip); lab.appendChild(document.createTextNode(' ' + a.name)); lab.title = a.name;
                tf.appendChild(makeRow('pq-cov-area', lab, function(d) {
                    var n = (cov.byArea[id] && cov.byArea[id][d]) || 0, need = pqPlus.need(targets, id, d);
                    return need ? n + '/' + need : (n ? String(n) : '');
                }, function(d) {
                    var n = (cov.byArea[id] && cov.byArea[id][d]) || 0, need = pqPlus.need(targets, id, d);
                    return pqPlus.WD[pqPlus.isoWeekday(d)] + ' ' + pqPlus.de(d) + ' · ' + a.code + ': ' + n + ' eingeplant' + (need ? ', Soll ' + need + (n < need ? ' – unterbesetzt' : '') : '');
                }, function(d) { return (underBy[d] || []).some(function(u) { return u.area === id; }); }));
            });
        }
        var lab = el('span', 'pq-cov-lab pq-cov-total-lab');
        var tg = el('button', 'pq-cov-toggle', view.breakdown ? '▾' : '▸'); tg.type = 'button';
        tg.setAttribute('aria-expanded', view.breakdown ? 'true' : 'false');
        tg.setAttribute('aria-label', view.breakdown ? 'Aufschlüsselung je Bereich ausblenden' : 'Abdeckung je Bereich aufschlüsseln');
        tg.title = tg.getAttribute('aria-label');
        tg.addEventListener('click', function() { view.breakdown = !view.breakdown; saveView(); coverageRender(); var n = tbl.querySelector('.pq-cov-toggle'); if (n) n.focus(); });
        lab.appendChild(tg); lab.appendChild(document.createTextNode(' Eingeplant'));
        if (cfg.targetsEdit) {
            var sb = el('button', 'pq-cov-soll', 'Soll'); sb.type = 'button'; sb.title = 'Soll-Besetzung je Bereich und Wochentag festlegen';
            sb.addEventListener('click', openTargets); lab.appendChild(sb);
        }
        tf.appendChild(makeRow('pq-cov-total', lab, function(d) { return cov.total[d] ? String(cov.total[d]) : ''; }, function(d) {
            var u = underBy[d] || [];
            return pqPlus.WD[pqPlus.isoWeekday(d)] + ' ' + pqPlus.de(d) + ': ' + (cov.total[d] || 0) + ' Person(en) im Einsatz (alle Zeilen dieses Plans)' +
                (u.length ? '\nUnterbesetzt: ' + u.map(function(x) { var a = areaById(x.area); return (a ? a.code : x.area) + ' ' + x.have + '/' + x.need; }).join(', ') : '');
        }, function(d) { return !!underBy[d]; }));
        if (old) tbl.replaceChild(tf, old); else tbl.appendChild(tf);
    }

    /* Soll-Besetzung bearbeiten (Recht „Bereiche/Feiertage/Stammdaten pflegen“) */
    function openTargets() {
        var id = 'pqTargetsMod', bg = document.getElementById(id);
        if (bg) bg.remove();
        bg = el('div', 'modal-bg'); bg.id = id;
        var box = el('div', 'modal modal-lg');
        var mh = el('div', 'mh'); mh.appendChild(el('h3', '', 'Soll-Besetzung je Bereich und Wochentag'));
        var x = el('button', 'mx', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Schließen'); x.addEventListener('click', function() { cMod(id); }); mh.appendChild(x);
        var mb = el('div', 'mb');
        mb.appendChild(el('p', 'muted pq-small', 'Mindestens so viele Personen sollen an diesem Wochentag im Bereich eingeplant sein. Leer oder 0 = keine Vorgabe. Die Abdeckungszeile markiert Unterbesetzung dezent; gespeichert wird nichts im Plan.'));
        var wrap = el('div', 'pq-targets-wrap'), t = el('table', 'tbl pq-targets');
        var hr = el('tr'); hr.appendChild(el('th', '', 'Bereich')); for (var w = 1; w <= 7; w++) hr.appendChild(el('th', '', pqPlus.WD[w]));
        var th = el('thead'); th.appendChild(hr); t.appendChild(th);
        var tb = el('tbody');
        cfg.areas.forEach(function(a) {
            var tr = el('tr'), td0 = el('td'), chip = el('span', 'pq-cov-chip', a.code);
            chip.style.background = a.color; chip.style.color = a.fg; td0.appendChild(chip); td0.appendChild(document.createTextNode(' ' + a.name)); tr.appendChild(td0);
            for (var w = 1; w <= 7; w++) {
                var td = el('td'), i = el('input', 'pq-target-inp'); i.type = 'number'; i.min = '0'; i.max = '999'; i.step = '1'; i.inputMode = 'numeric';
                i.dataset.a = a.id; i.dataset.w = w; var cur = cfg.targets[a.id] && cfg.targets[a.id][w]; i.value = cur ? cur : '';
                i.setAttribute('aria-label', 'Soll ' + a.code + ' ' + pqPlus.WD[w]); td.appendChild(i); tr.appendChild(td);
            }
            tb.appendChild(tr);
        });
        t.appendChild(tb); wrap.appendChild(t); mb.appendChild(wrap);
        var mf = el('div', 'mf');
        var cn = el('button', 'btn btn-outline', 'Abbrechen'); cn.type = 'button'; cn.addEventListener('click', function() { cMod(id); });
        var ok = el('button', 'btn btn-primary', 'Speichern'); ok.type = 'button';
        ok.addEventListener('click', function() {
            var out = {};
            bg.querySelectorAll('.pq-target-inp').forEach(function(i) { var v = i.value.trim(); if (v !== '' && +v > 0) { (out[i.dataset.a] = out[i.dataset.a] || {})[i.dataset.w] = parseInt(v, 10); } });
            ok.disabled = true;
            api({ action: 'targets_save', targets: out }).then(function(d) {
                cfg.targets = d.targets || {}; cMod(id); coverageRender(); toast(d.msg || 'Gespeichert', 'success');
            }).catch(function(e) { toast(e.message, 'error'); }).then(function() { ok.disabled = false; });
        });
        mf.appendChild(cn); mf.appendChild(ok);
        box.appendChild(mh); box.appendChild(mb); box.appendChild(mf); bg.appendChild(box);
        document.body.appendChild(bg);
        oMod(id);
    }

    /* ================= 3. Zell-Details im Tooltip ================= */
    var infoCache = {}, infoPending = {}, hovered = null;
    function tipRow(cls, html) { return '<div class="ep-tip-row ' + cls + '">' + html + '</div>'; }
    window.pqTipExtra = function(c, parts) {
        if (!cfg || !view.details || !tbl || !tbl.contains(c)) return;
        if (c.dataset.t === 'assignment' && c.dataset.v) {
            var a = areaById(c.dataset.v);
            if (a) {
                var sub = [a.customer ? 'Kunde: ' + esc(a.customer) : '', a.location ? 'Standort: ' + esc(a.location) : ''].filter(Boolean).join(' · ');
                parts.unshift(tipRow('pq-tip-area', '<span class="pq-tip-chip" style="background:' + safeColor(a.color) + ';color:' + safeColor(a.fg) + '">' + esc(a.code) + '</span> <b>' + esc(a.name) + '</b>' + (sub ? '<br><span class="pq-tip-sub">' + sub + '</span>' : '')));
            }
        }
        if (!cfg.history || c.classList.contains('cell-unavailable')) return;
        if (!c.dataset.t && !c.classList.contains('draft-mine') && !c.classList.contains('draft-other')) return;
        var key = c.dataset.u + '_' + c.dataset.d + '_' + (c.dataset.r || '0');
        var info = infoCache[key];
        if (!info) {
            if (!infoPending[key]) {
                infoPending[key] = 1;
                api({ action: 'cell_info', user_id: +c.dataset.u, date: c.dataset.d }).then(function(d) {
                    infoCache[key] = d;
                    var tip = document.getElementById('epTip');
                    if (hovered === c && tip && typeof window.pqShowCellTip === 'function') window.pqShowCellTip(c);
                }).catch(function() { infoCache[key] = { history: [], draft: null }; }).then(function() { delete infoPending[key]; });
            }
            parts.push(tipRow('pq-tip-meta', 'Verlauf wird geladen …'));
            return;
        }
        if (info.draft && info.draft.at) parts.push(tipRow('pq-tip-meta', 'Entwurf (' + esc(info.draft.what) + ') von ' + esc(info.draft.by || '?') + ' · seit ' + esc(info.draft.at)));
        var h = (info.history || []).slice(0, 3);
        if (h.length) {
            parts.push(tipRow('pq-tip-hist', '<span class="pq-tip-h">Letzte Änderungen</span>' + h.map(function(x) {
                return '<br>' + esc(x.at) + ' · ' + esc(x.by || '–') + ' · ' + esc(x.action) + (x.to !== null && x.to !== undefined ? ': ' + esc(x.from) + ' → ' + esc(x.to) : '');
            }).join('')));
        }
    };
    function initHoverTracking() {
        document.addEventListener('mouseover', function(e) { hovered = e.target.closest ? e.target.closest('.pe-c') : null; });
    }

    /* ================= 4. Legende: Bereich hervorheben ================= */
    var hlArea = null;
    function initLegend() {
        var items = document.querySelectorAll('.legend .leg-i[data-area]');
        if (!items.length || !tbl) return;
        var sec = items[0].closest('.legend');
        var hint = el('p', 'pq-leg-hint no-print', 'Tipp: Einen Einsatzbereich anklicken, um ihn im Plan hervorzuheben (erneut klicken oder Esc hebt auf).');
        sec.insertBefore(hint, sec.firstChild);
        items.forEach(function(it) {
            it.setAttribute('role', 'button'); it.tabIndex = 0; it.setAttribute('aria-pressed', 'false'); it.classList.add('pq-leg-btn');
            var name = (it.querySelector('.leg-n') || {}).textContent || '';
            it.title = 'Bereich „' + name + '“ im Plan hervorheben';
            var go = function() { setHighlight(hlArea === it.dataset.area ? null : it.dataset.area); };
            it.addEventListener('click', go);
            it.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && hlArea && !document.querySelector('.modal-bg.open')) setHighlight(null);
        });
    }
    function setHighlight(area) {
        hlArea = area;
        document.querySelectorAll('.legend .leg-i[data-area]').forEach(function(it) {
            var on = it.dataset.area === area; it.setAttribute('aria-pressed', on ? 'true' : 'false'); it.classList.toggle('pq-leg-on', on);
        });
        tbl.classList.toggle('pq-hl-on', !!area);
        if (!area) { dynStyle('hl', ''); say('Hervorhebung aufgehoben.'); return; }
        var v = String(area).replace(/[^0-9]/g, '');
        dynStyle('hl', tblSel() + '.pq-hl-on tbody .pe-c:not([data-t="assignment"][data-v="' + v + '"]){opacity:.22}' +
            tblSel() + '.pq-hl-on tbody .pe-c[data-t="assignment"][data-v="' + v + '"]{box-shadow:inset 0 0 0 2px var(--pq-hl-ring)}');
        var n = tbl.querySelectorAll('tbody .pe-c[data-t="assignment"][data-v="' + v + '"]').length, a = areaById(v);
        say((a ? a.code + ' – ' + a.name : 'Bereich') + ' hervorgehoben: ' + n + ' Einsatztag(e) in diesem Monat.');
    }

    /* ================= 5. Rückgängig / Wiederholen ================= */
    var UNDO_KEY = 'pq-plus-undo-v1', MAX_STACK = 30;
    var snap = {}, prevMap = (typeof WeakMap !== 'undefined') ? new WeakMap() : null, stacks = { undo: [], redo: [] }, running = null;
    var btnU = null, btnR = null;
    function cellState(c) { return { t: c.dataset.t || '', v: c.dataset.v || '', i: c.classList.contains('cell-internal') }; }
    function loadStacks() { try { var s = JSON.parse(sstore(UNDO_KEY) || 'null'); if (s && s.undo && s.redo) stacks = s; } catch (e) {} }
    function saveStacks() {
        stacks.undo = stacks.undo.slice(-MAX_STACK); stacks.redo = stacks.redo.slice(-MAX_STACK);
        sstore(UNDO_KEY, JSON.stringify(stacks)); updateUndoButtons();
    }
    function describe(entry) {
        var n = entry.cells.length, labels = [];
        entry.cells.forEach(function(c) { var l = stateLabel(c.next); if (labels.indexOf(l) === -1) labels.push(l); });
        return n + ' Zelle' + (n === 1 ? '' : 'n') + ' (' + labels.slice(0, 3).join(', ') + (labels.length > 3 ? ', …' : '') + ')';
    }
    function updateUndoButtons() {
        if (!btnU) return;
        var u = stacks.undo[stacks.undo.length - 1], r = stacks.redo[stacks.redo.length - 1];
        btnU.disabled = !u || !!running; btnR.disabled = !r || !!running;
        btnU.title = u ? 'Rückgängig: ' + describe(u) + ' – Strg+Z' : 'Nichts rückgängig zu machen';
        btnR.title = r ? 'Wiederholen: ' + describe(r) + ' – Strg+Y' : 'Nichts zu wiederholen';
    }
    function initSnapshot() {
        dayCells().forEach(function(c) { snap[c.dataset.u + '_' + c.dataset.d] = cellState(c); });
        /* Der Beobachter läuft erst NACH der aktuellen Aufgabe: saveBulk() (synchron nach der
           Darstellung) liest also noch den Stand vor der Änderung. */
        new MutationObserver(function(list) {
            var touched = false;
            list.forEach(function(m) {
                var c = m.target; if (!c.classList || !c.classList.contains('pe-c')) return;
                snap[c.dataset.u + '_' + c.dataset.d] = cellState(c);
                if (m.attributeName !== 'class') touched = true;
            });
            if (touched) coverageSchedule();
        }).observe(tbl.querySelector('tbody'), { subtree: true, attributes: true, attributeFilter: ['data-t', 'data-v', 'class'] });
    }
    function wrapSaveBulk() {
        if (typeof window.saveBulk !== 'function' || window.saveBulk._pq) return;
        var orig = window.saveBulk;
        window.saveBulk = function(changes, showToast, opts) {
            if (prevMap && Array.isArray(changes)) changes.forEach(function(ch) {
                if (ch && typeof ch === 'object' && !prevMap.has(ch)) {
                    var s = snap[ch.user_id + '_' + ch.date];
                    prevMap.set(ch, s ? { t: s.t, v: s.v, i: s.i } : { t: '', v: '', i: false });
                }
            });
            return orig.apply(this, arguments);
        };
        window.saveBulk._pq = true;
    }
    function onSaved(e) {
        var d = e.detail || {}, opts = d.opts || {};
        if (opts.pqUndo && running && opts.pqRun === running.id) { runStepDone(d.revs || {}); return; }
        if (opts.pqUndo) return;
        var entry = pqPlus.makeEntry(d.changes || [], function(ch) { return prevMap ? prevMap.get(ch) : null; }, d.revs || {}, d.internal);
        if (!entry) return;
        entry.at = Date.now();
        stacks.undo.push(entry); stacks.redo = [];
        saveStacks();
    }
    function revOf(u, dd) { var c = tbl.querySelector('.pe-c[data-u="' + u + '"][data-d="' + dd + '"]'); return c ? (c.dataset.r || '0') : null; }
    function runUndo(dir) {
        if (running) return;
        if (typeof epBlockedOffline === 'function' && epBlockedOffline()) return;
        var st = dir === 'undo' ? stacks.undo : stacks.redo, entry = st[st.length - 1];
        if (!entry) { toast(dir === 'undo' ? 'Nichts rückgängig zu machen' : 'Nichts zu wiederholen', 'info'); return; }
        var plan = pqPlus.restoreGroups(entry, dir, revOf);
        if (!plan.groups.length) {
            st.pop(); saveStacks();
            toast('Diese Änderung lässt sich nicht ' + (dir === 'undo' ? 'rückgängig machen' : 'wiederholen') + ' (vertrauliche Abwesenheit).', 'warning');
            return;
        }
        running = { id: 'r' + Date.now(), dir: dir, entry: entry, groups: plan.groups, idx: 0, revs: {}, skipped: plan.skipped };
        updateUndoButtons();
        runStep();
    }
    function runStep() {
        var g = running.groups[running.idx]; running.started = Date.now();
        /* Abgebrochener Konfliktdialog: nach kurzer Zeit wieder freigeben, Eintrag bleibt erhalten */
        var id = running.id;
        setTimeout(function() { if (running && running.id === id && running.started && Date.now() - running.started >= 15000) { running = null; updateUndoButtons(); } }, 15000);
        saveBulk(g.changes, false, { internal: g.internal, pqUndo: running.dir, pqRun: running.id });
    }
    function runStepDone(revs) {
        Object.keys(revs).forEach(function(k) { running.revs[k] = revs[k]; });
        running.idx++;
        if (running.idx < running.groups.length) { runStep(); return; }
        var r = running; running = null;
        var from = r.dir === 'undo' ? stacks.undo : stacks.redo, to = r.dir === 'undo' ? stacks.redo : stacks.undo;
        var i = from.indexOf(r.entry); if (i !== -1) from.splice(i, 1);
        var moved = pqPlus.withRevs(r.entry, r.revs); moved.at = Date.now();
        to.push(moved);
        saveStacks();
        var n = r.entry.cells.length - r.skipped;
        var msg = (r.dir === 'undo' ? 'Rückgängig: ' : 'Wiederholt: ') + n + ' Zelle' + (n === 1 ? '' : 'n') + (r.skipped ? ' (' + r.skipped + ' vertrauliche übersprungen)' : '') + '.';
        toast(msg, 'success'); say(msg);
    }
    function initUndo() {
        loadStacks();
        initSnapshot();
        wrapSaveBulk();
        document.addEventListener('pq:bulk-saved', onSaved);
        var host = document.getElementById('pqUndoHost') || document.querySelector('#toolbar .tbs');
        if (host) {
            var g = el('span', 'pq-undo-grp');
            btnU = el('button', 'btn btn-xs btn-outline pq-undo-btn', '↶ Rückgängig'); btnU.type = 'button'; btnU.setAttribute('aria-keyshortcuts', 'Control+Z');
            btnR = el('button', 'btn btn-xs btn-outline pq-undo-btn', '↷ Wiederholen'); btnR.type = 'button'; btnR.setAttribute('aria-keyshortcuts', 'Control+Y Control+Shift+Z');
            btnU.addEventListener('click', function() { runUndo('undo'); });
            btnR.addEventListener('click', function() { runUndo('redo'); });
            g.appendChild(btnU); g.appendChild(btnR);
            var hint = host.id === 'pqUndoHost' ? host.firstChild : document.getElementById('liveUpdateHint');
            host.insertBefore(g, hint || null);
        }
        updateUndoButtons();
        document.addEventListener('keydown', function(e) {
            if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
            var k = String(e.key || '').toLowerCase();
            if (k !== 'z' && k !== 'y') return;
            var a = document.activeElement, tag = a ? a.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (a && a.isContentEditable)) return; /* Texteingaben behalten ihr eigenes Rückgängig */
            if (document.querySelector('.modal-bg.open')) return;
            e.preventDefault();
            runUndo(k === 'y' || e.shiftKey ? 'redo' : 'undo');
        });
    }

    /* ================= 6. Woche/Zeitraum kopieren ================= */
    var srcCache = {};
    function monthCells(y, m) {
        var k = y + '-' + m;
        if (srcCache[k]) return srcCache[k];
        srcCache[k] = fetch(getApiBase() + 'api/live_status', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'cells', year: y, month: m }) })
            .then(function(r) { return r.json(); }).then(function(d) { if (!d.success) throw new Error('Plan konnte nicht geladen werden'); return d.cells || {}; })
            .catch(function(e) { delete srcCache[k]; throw e; });
        return srcCache[k];
    }
    function sourceWeek(monday) {
        var a = monday, b = pqPlus.addDays(monday, 6), months = {};
        [a, b].forEach(function(d) { months[d.slice(0, 7)] = [+d.slice(0, 4), +d.slice(5, 7)]; });
        return Promise.all(Object.keys(months).map(function(k) { return monthCells(months[k][0], months[k][1]); })).then(function(list) {
            var all = {}; list.forEach(function(m) { Object.keys(m).forEach(function(k) { all[k] = m[k]; }); }); return all;
        });
    }
    function visibleRows() {
        return Array.prototype.filter.call(tbl.querySelectorAll('tbody .plan-row'), function(r) { return r.style.display !== 'none'; }).map(function(r) {
            return { u: r.dataset.user, we: r.dataset.we === '1', entry: r.dataset.entry || '', exit: r.dataset.exit || '' };
        });
    }
    function openCopy() {
        if (typeof epBlockedOffline === 'function' && epBlockedOffline()) return;
        var id = 'pqCopyMod', bg = document.getElementById(id);
        if (bg) bg.remove();
        var first = cfg.year + '-' + (cfg.month < 10 ? '0' : '') + cfg.month + '-01';
        var base = (cfg.today.slice(0, 7) === first.slice(0, 7)) ? cfg.today : first;
        var tgtMon = pqPlus.mondayOf(base); if (tgtMon < first && pqPlus.addDays(tgtMon, 4) < first) tgtMon = pqPlus.addDays(tgtMon, 7);
        var srcMon = pqPlus.addDays(tgtMon, -7);
        bg = el('div', 'modal-bg'); bg.id = id;
        bg.innerHTML = '<div class="modal modal-lg"><div class="mh"><h3>Woche kopieren</h3><button type="button" class="mx" aria-label="Schließen">×</button></div>' +
            '<div class="mb">' +
            '<p class="muted pq-small">Überträgt die Einsätze einer Quellwoche Wochentag für Wochentag in einen Zielzeitraum – z. B. „Vorwoche übernehmen“ oder ein Wochenmuster für mehrere Wochen. Gespeichert wird als Entwurf mit der bekannten Konfliktprüfung.</p>' +
            '<div class="fg2"><div class="fg"><label for="pqCpSrc">Quellwoche (beliebiger Tag, es zählt Mo–So)</label><input type="date" id="pqCpSrc"></div>' +
            '<div class="fg"><label>Schnellwahl</label><div class="pq-cp-quick"><button type="button" class="btn btn-xs btn-outline" data-q="prev">Vorwoche → diese Woche</button><button type="button" class="btn btn-xs btn-outline" data-q="month">Woche → Rest des Monats</button></div></div></div>' +
            '<div class="fg2"><div class="fg"><label for="pqCpFrom">Ziel von</label><input type="date" id="pqCpFrom"></div><div class="fg"><label for="pqCpTo">Ziel bis</label><input type="date" id="pqCpTo"></div></div>' +
            '<label class="pq-cp-opt"><input type="checkbox" id="pqCpAbs"> Auch Gleitzeit, Gesperrt und „Offen“ übernehmen (Urlaub und Krankheit nie)</label>' +
            '<label class="pq-cp-opt"><input type="checkbox" id="pqCpClear"> Werktage, die in der Quellwoche leer sind, im Ziel leeren</label>' +
            '<p class="pq-cp-who" id="pqCpWho"></p><p class="pq-cp-prev" id="pqCpPrev" aria-live="polite"></p>' +
            '</div><div class="mf"><button type="button" class="btn btn-outline" data-x="1">Abbrechen</button><button type="button" class="btn btn-primary" id="pqCpGo">Prüfen &amp; Übernehmen</button></div></div>';
        document.body.appendChild(bg);
        var $ = function(s) { return bg.querySelector(s); };
        $('#pqCpSrc').value = srcMon; $('#pqCpFrom').value = tgtMon; $('#pqCpTo').value = pqPlus.addDays(tgtMon, 6);
        $('.mx').addEventListener('click', function() { cMod(id); });
        $('[data-x]').addEventListener('click', function() { cMod(id); });
        bg.querySelectorAll('[data-q]').forEach(function(b) {
            b.addEventListener('click', function() {
                var cur = pqPlus.mondayOf($('#pqCpFrom').value || tgtMon);
                if (b.dataset.q === 'prev') { $('#pqCpSrc').value = pqPlus.addDays(cur, -7); $('#pqCpFrom').value = cur; $('#pqCpTo').value = pqPlus.addDays(cur, 6); }
                else { var sm = pqPlus.mondayOf($('#pqCpSrc').value || srcMon); $('#pqCpFrom').value = pqPlus.addDays(sm, 7); var y = cfg.year, m = cfg.month; $('#pqCpTo').value = pqPlus.fmt(new Date(Date.UTC(y, m, 0))); }
                preview();
            });
        });
        ['#pqCpSrc', '#pqCpFrom', '#pqCpTo', '#pqCpAbs', '#pqCpClear'].forEach(function(s) { $(s).addEventListener('change', preview); });
        var rows = visibleRows(), total = tbl.querySelectorAll('tbody .plan-row').length;
        $('#pqCpWho').textContent = 'Mitarbeitende: ' + rows.length + (rows.length < total ? ' von ' + total + ' (wie aktuell gefiltert)' : ' (alle im Plan)') + '. Zum Eingrenzen vorher die Filterleiste nutzen.';
        var last = null;
        function compute() {
            var sm = pqPlus.mondayOf($('#pqCpSrc').value), from = $('#pqCpFrom').value, to = $('#pqCpTo').value;
            if (!sm || !from || !to) return Promise.reject(new Error('Bitte Quellwoche und Zielzeitraum angeben.'));
            if (from > to) return Promise.reject(new Error('„Ziel von“ liegt nach „Ziel bis“.'));
            if (pqPlus.dayDiff(from, to) > 92) return Promise.reject(new Error('Zielzeitraum höchstens drei Monate.'));
            return sourceWeek(sm).then(function(src) {
                var hols = (typeof HOLIDAYS !== 'undefined') ? HOLIDAYS : [];
                return { sm: sm, changes: pqPlus.copyChanges(src, rows, sm, from, to, { absences: $('#pqCpAbs').checked, clearEmpty: $('#pqCpClear').checked, holidays: hols }) };
            });
        }
        function preview() {
            var p = $('#pqCpPrev'); p.textContent = 'Berechne …'; p.className = 'pq-cp-prev';
            compute().then(function(r) {
                last = r;
                var people = {}; r.changes.forEach(function(c) { people[c.user_id] = 1; });
                var n = r.changes.length;
                p.textContent = n ? n + ' Eintrag/Einträge für ' + Object.keys(people).length + ' Mitarbeitende aus der Woche ab ' + pqPlus.de(r.sm) + (n > 500 ? ' – zu viele für einen Speichervorgang (höchstens 500), bitte Zeitraum oder Auswahl verkleinern.' : '.') : 'Die Quellwoche enthält für die gewählten Mitarbeitenden nichts zu übernehmen.';
                if (n > 500) p.classList.add('pq-cp-err');
            }).catch(function(e) { last = null; p.textContent = e.message; p.classList.add('pq-cp-err'); });
        }
        $('#pqCpGo').addEventListener('click', function() {
            compute().then(function(r) {
                if (!r.changes.length) { toast('Nichts zu übernehmen', 'info'); return; }
                if (r.changes.length > 500) { toast('Höchstens 500 Einträge auf einmal – bitte Zeitraum verkleinern', 'warning'); return; }
                planWithConflictCheck(r.changes, { newLabel: 'Kopie der Woche ab ' + pqPlus.de(r.sm), closeModal: id });
            }).catch(function(e) { toast(e.message, 'warning'); });
        });
        oMod(id);
        preview();
    }
    function initCopy() {
        var bar = document.querySelector('.plan-bar-actions'); if (!bar) return;
        var ref = null;
        bar.querySelectorAll('button').forEach(function(b) { if (!ref && /Dauereinsatz/.test(b.textContent)) ref = b; });
        var b = el('button', 'btn btn-outline pq-copy-btn', 'Woche kopieren'); b.type = 'button';
        b.title = 'Einsätze einer Woche in einen anderen Zeitraum übernehmen (z. B. Vorwoche)';
        b.addEventListener('click', openCopy);
        if (ref && ref.nextSibling) bar.insertBefore(b, ref.nextSibling); else bar.appendChild(b);
    }

    /* ================= 7. Druck ================= */
    function initPrint() {
        var ph = document.querySelector('.print-header');
        if (!ph || !cfg.tenant) return;
        var s = el('span', 'pq-print-org', cfg.tenant);
        ph.insertBefore(s, ph.firstChild);
    }

    /* ================= 8. Werkzeugleiste: Gruppen, Bereichssuche, Schnellwahl, Kürzel ================= */
    var TOOL_NAMES = { krank_attest: 'Krank mit Attest', krank_ganz: 'Krank ohne Attest', krank_halb: 'Krank ½ Tag', urlaub_genehmigt: 'Urlaub genehmigt',
        urlaub_ganz: 'Urlaub beantragt', urlaub_halb: 'Urlaub ½ Tag', gleitzeit: 'Gleitzeit', offen: 'Offen / noch zu klären' };
    var areaSel = null, pickBtn = null, pickPop = null, pickInp = null, pickList = null, recentBox = null, toolbarOn = false;
    function selAreas() {
        var out = [];
        if (!areaSel) return out;
        Array.prototype.forEach.call(areaSel.querySelectorAll('option'), function(o) {
            if (!o.value) return;
            var g = o.parentNode && o.parentNode.tagName === 'OPTGROUP' ? o.parentNode.label : '';
            var a = areaById(o.value);
            out.push({ id: o.value, code: o.dataset.k || o.textContent, name: a ? a.name : o.textContent, color: o.dataset.c, fg: o.dataset.f, group: g });
        });
        return out;
    }
    function chooseArea(v) {
        if (!areaSel) return;
        areaSel.value = v || '';
        areaSel.dispatchEvent(new Event('change'));
    }
    function recentList() { try { return JSON.parse(store('pq-recent-areas') || '[]') || []; } catch (e) { return []; } }
    function renderRecent() {
        if (!recentBox) return;
        recentBox.textContent = '';
        var all = selAreas(), byId = {}; all.forEach(function(a) { byId[a.id] = a; });
        var list = recentList().filter(function(id) { return byId[id]; });
        if (!list.length) { recentBox.hidden = true; return; }
        recentBox.hidden = false;
        list.forEach(function(id, i) {
            var a = byId[id], b = el('button', 'pq-recent' + (areaSel.value === id ? ' is-on' : ''), a.code); b.type = 'button';
            b.style.background = a.color; b.style.color = a.fg; b.setAttribute('aria-pressed', areaSel.value === id ? 'true' : 'false');
            b.title = a.code + ' – ' + a.name + ' (Taste ' + (i + 1) + ')';
            b.setAttribute('aria-label', a.code + ' – ' + a.name + ', zuletzt genutzt, Taste ' + (i + 1));
            b.addEventListener('click', function() { chooseArea(id); });
            recentBox.appendChild(b);
        });
    }
    function syncPicker() {
        if (!pickBtn) return;
        var o = areaSel.options[areaSel.selectedIndex], lab = pickBtn.querySelector('.pq-area-cur');
        lab.textContent = '';
        if (o && o.value) {
            var chip = el('span', 'pq-cov-chip', o.dataset.k || ''); chip.style.background = o.dataset.c; chip.style.color = o.dataset.f;
            var a = areaById(o.value);
            lab.appendChild(chip); lab.appendChild(document.createTextNode(' ' + (a ? a.name : o.textContent)));
            pickBtn.classList.add('is-on');
        } else { lab.textContent = 'Bereich wählen …'; pickBtn.classList.remove('is-on'); }
        renderRecent();
    }
    function buildPicker(host) {
        var wrap = el('div', 'pq-area-pick');
        pickBtn = el('button', 'pq-area-btn'); pickBtn.type = 'button';
        pickBtn.setAttribute('aria-haspopup', 'listbox'); pickBtn.setAttribute('aria-expanded', 'false');
        pickBtn.title = 'Einsatzbereich als Werkzeug wählen (Taste B)';
        pickBtn.innerHTML = '<span class="pq-area-cur"></span><span class="pq-area-caret" aria-hidden="true">▾</span>';
        pickPop = el('div', 'pq-area-pop'); pickPop.hidden = true;
        pickInp = el('input', 'pq-area-inp'); pickInp.type = 'text'; pickInp.placeholder = 'Kürzel oder Name …';
        pickInp.setAttribute('role', 'combobox'); pickInp.setAttribute('aria-autocomplete', 'list'); pickInp.setAttribute('aria-expanded', 'true');
        pickInp.setAttribute('aria-controls', 'pqAreaList'); pickInp.setAttribute('aria-label', 'Einsatzbereich suchen'); pickInp.autocomplete = 'off';
        pickList = el('ul', 'pq-area-list'); pickList.id = 'pqAreaList'; pickList.setAttribute('role', 'listbox'); pickList.setAttribute('aria-label', 'Einsatzbereiche');
        pickPop.appendChild(pickInp); pickPop.appendChild(pickList);
        wrap.appendChild(pickBtn); wrap.appendChild(pickPop);
        host.appendChild(wrap);
        var active = -1, items = [];
        function render() {
            pickList.textContent = ''; items = [];
            var list = pqPlus.filterAreas(selAreas(), pickInp.value), lastG = null;
            if (!pickInp.value) list.unshift({ id: '', code: '', name: 'Kein Bereich (Werkzeug ablegen)', group: '' });
            list.forEach(function(a) {
                if (a.group && a.group !== lastG && !pickInp.value) { var h = el('li', 'pq-area-grp', a.group); h.setAttribute('role', 'presentation'); pickList.appendChild(h); lastG = a.group; }
                var li = el('li', 'pq-area-opt'); li.setAttribute('role', 'option'); li.id = 'pqAo' + (a.id || 'none'); li.dataset.v = a.id;
                li.setAttribute('aria-selected', String(areaSel.value === a.id));
                if (a.id) { var c = el('span', 'pq-cov-chip', a.code); c.style.background = a.color; c.style.color = a.fg; li.appendChild(c); }
                li.appendChild(document.createTextNode(' ' + a.name));
                li.addEventListener('mousedown', function(e) { e.preventDefault(); pick(a.id); });
                pickList.appendChild(li); items.push(li);
            });
            if (!items.length) { var n = el('li', 'pq-area-none', 'Kein Bereich gefunden'); n.setAttribute('role', 'presentation'); pickList.appendChild(n); }
            var cur = -1; items.forEach(function(x, i) { if (x.dataset.v === areaSel.value) cur = i; });
            setActive(items.length ? (pickInp.value ? 0 : Math.max(0, cur)) : -1);
        }
        function setActive(i) {
            items.forEach(function(x) { x.classList.remove('is-active'); });
            active = i;
            if (i >= 0 && items[i]) { items[i].classList.add('is-active'); pickInp.setAttribute('aria-activedescendant', items[i].id); if (items[i].scrollIntoView) items[i].scrollIntoView({ block: 'nearest' }); }
            else pickInp.removeAttribute('aria-activedescendant');
        }
        function open() { pickPop.hidden = false; pickBtn.setAttribute('aria-expanded', 'true'); pickInp.value = ''; render(); pickInp.focus(); }
        function close(focusBtn) { pickPop.hidden = true; pickBtn.setAttribute('aria-expanded', 'false'); if (focusBtn) pickBtn.focus(); }
        function pick(v) { close(true); chooseArea(v); }
        pqPlus._openPicker = open;
        pickBtn.addEventListener('click', function() { if (pickPop.hidden) open(); else close(false); });
        pickInp.addEventListener('input', render);
        pickInp.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(items.length - 1, active + 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(0, active - 1)); }
            else if (e.key === 'Enter') { e.preventDefault(); if (items[active]) pick(items[active].dataset.v); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
            else if (e.key === 'Tab') close(false);
        });
        document.addEventListener('click', function(e) { if (!pickPop.hidden && !wrap.contains(e.target)) close(false); });
    }
    function toolLabel(t) {
        if (!t) return '';
        if (t.type === 'assignment') { var a = areaById(t.value); return (t.code || '') + (a ? ' – ' + a.name : ''); }
        if (t.type === 'absence') return TOOL_NAMES[t.value] || t.value;
        return { locked: 'Sperren', clear: 'Löschen', remark: 'Bemerkung – Zellen wählen und loslassen', intremark: 'Interne Bemerkung – Zellen wählen und loslassen' }[t.type] || '';
    }
    function wrapToolStatus() {
        if (typeof window.setToolStatus !== 'function' || window.setToolStatus._pq) return;
        var orig = window.setToolStatus;
        window.setToolStatus = function() {
            var r = orig.apply(this, arguments);
            try { afterToolChange(); } catch (e) {}
            return r;
        };
        window.setToolStatus._pq = true;
    }
    var lastToolKey = null;
    function afterToolChange() {
        var t = (typeof currentTool !== 'undefined') ? currentTool : null;
        document.querySelectorAll('#toolbar .abs-btn, #toolLock, #toolClr, #toolRemark, #toolIntRemark').forEach(function(b) {
            b.setAttribute('aria-pressed', b.classList.contains('tool-active') ? 'true' : 'false');
        });
        if (toolbarOn) {
            var st = document.getElementById('toolSt');
            if (st) {
                st.style.color = ''; st.className = 'pq-tool-st' + (t ? ' is-on' : '');
                st.textContent = '';
                if (t) {
                    st.appendChild(el('span', 'pq-tool-st-k', 'Aktiv:'));
                    var sw = el('span', 'pq-tool-sw'); sw.style.background = t.color || (t.type === 'remark' ? '#f97316' : (t.type === 'intremark' ? '#c8b400' : '#94a3b8'));
                    if (t.type === 'clear') { sw.classList.add('pq-tool-sw-clear'); sw.style.background = ''; }
                    st.appendChild(sw); st.appendChild(el('b', '', toolLabel(t)));
                    st.appendChild(el('span', 'pq-tool-st-hint', 'Esc beendet'));
                } else {
                    st.appendChild(el('span', '', 'Kein Werkzeug gewählt – Bereich oder Abwesenheit wählen. Ohne Werkzeug lassen sich Einträge ziehen.'));
                }
            }
            syncPicker();
        }
        document.body.classList.toggle('pq-tool-on', !!t);
        var key = t ? t.type + ':' + (t.value || '') : '';
        if (key !== lastToolKey) { if (lastToolKey !== null && t) say('Werkzeug aktiv: ' + toolLabel(t) + '. Esc beendet.'); lastToolKey = key; }
        if (t && t.type === 'assignment' && t.value) { store('pq-recent-areas', JSON.stringify(pqPlus.pushRecent(recentList(), t.value, 5))); renderRecent(); }
        if (view && view.keepTool) sstore('pq-tool', t ? JSON.stringify({ type: t.type, value: t.value || '' }) : '');
    }
    function restoreTool() {
        if (!view.keepTool) return;
        var raw = sstore('pq-tool'); if (!raw) return;
        var t; try { t = JSON.parse(raw); } catch (e) { return; }
        if (!t || !t.type) return;
        if (t.type === 'assignment') chooseArea(t.value);
        else if (t.type === 'absence') { var b = document.querySelector('#toolbar .abs-btn[data-t="' + String(t.value).replace(/[^a-z_]/g, '') + '"]'); if (b) b.click(); }
        else { var id = { locked: 'toolLock', clear: 'toolClr', remark: 'toolRemark', intremark: 'toolIntRemark' }[t.type]; var x = id && document.getElementById(id); if (x) x.click(); }
    }
    function addKeyHints() {
        pqPlus.KEYS.forEach(function(k) {
            var tg = k[1], b = null;
            if (tg.charAt(0) === '#') b = document.querySelector(tg);
            else b = document.querySelector('#toolbar .abs-btn[data-t="' + tg + '"]');
            if (!b) return;
            if (tg === '#toolInternal') b = b.closest('.tb-nv-box') || b;
            var t = b.getAttribute('title') || k[2];
            if (t.indexOf('Taste') === -1) b.setAttribute('title', t + ' (Taste ' + pqPlus.keyLabel(k[0]) + ')');
            if (b.tagName === 'BUTTON') b.setAttribute('aria-keyshortcuts', k[0] !== k[0].toLowerCase() ? 'Shift+' + k[0] : k[0]);
        });
    }
    function openKeyHelp() {
        var id = 'pqKeysMod', bg = document.getElementById(id);
        if (!bg) {
            bg = el('div', 'modal-bg'); bg.id = id;
            var rows = pqPlus.KEYS.map(function(k) { return [pqPlus.keyLabel(k[0]), k[2]]; });
            rows.unshift(['1 – 5', 'Zuletzt genutzte Einsatzbereiche']);
            rows.push(['Esc', 'Werkzeug ablegen'], ['/', 'In die Suche springen']);
            var grid = [['Pfeiltasten, Pos1, Ende', 'Im Plan bewegen (Tab in die Matrix)'], ['Eingabe / Leertaste', 'Werkzeug auf die Zelle anwenden'], ['Entf', 'Zelle leeren'],
                ['Strg+X / Strg+C, Strg+V', 'Eintrag ausschneiden bzw. kopieren und einfügen'], ['Strg+Z / Strg+Y', 'Eigenes Speichern rückgängig / wiederholen']];
            var table = function(title, list) {
                return '<h4 class="pq-keys-h">' + esc(title) + '</h4><table class="tbl pq-keys"><tbody>' + list.map(function(r) { return '<tr><td><kbd>' + esc(r[0]) + '</kbd></td><td>' + esc(r[1]) + '</td></tr>'; }).join('') + '</tbody></table>';
            };
            bg.innerHTML = '<div class="modal"><div class="mh"><h3>Tastenkürzel der Monatsplanung</h3><button type="button" class="mx" aria-label="Schließen">×</button></div><div class="mb">' +
                '<p class="muted pq-small">Gelten, solange der Fokus nicht in einem Eingabefeld liegt.</p>' + table('Werkzeuge', rows) + table('Im Plan', grid) +
                '</div><div class="mf"><button type="button" class="btn btn-primary">Schließen</button></div></div>';
            document.body.appendChild(bg);
            bg.querySelectorAll('.mx, .mf .btn').forEach(function(b) { b.addEventListener('click', function() { cMod(id); }); });
        }
        oMod(id);
    }
    function onToolKey(e) {
        if (e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented) return;
        var a = document.activeElement, tag = a ? a.tagName : '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (a && a.isContentEditable)) return;
        if (document.querySelector('.modal-bg.open') || document.body.classList.contains('pq-dnd-active')) return;
        if (a && a.closest && a.closest('.pq-view')) return;
        if (e.key === 'Escape') {
            if (typeof currentTool !== 'undefined' && currentTool && typeof deselectTool === 'function') { e.preventDefault(); deselectTool(); say('Werkzeug abgelegt.'); }
            return;
        }
        var act = pqPlus.keyAction(e.key); if (!act) return;
        if (act.recent !== undefined) {
            var list = recentList(); if (list[act.recent] && areaSel && areaSel.querySelector('option[value="' + String(list[act.recent]).replace(/[^0-9]/g, '') + '"]')) { e.preventDefault(); chooseArea(list[act.recent]); }
            return;
        }
        var tg = act.target, b = null;
        if (tg === 'help') { e.preventDefault(); openKeyHelp(); return; }
        if (tg === 'area') { e.preventDefault(); if (pqPlus._openPicker) pqPlus._openPicker(); else if (areaSel) areaSel.focus(); return; }
        if (tg.charAt(0) === '#') b = document.querySelector(tg); else b = document.querySelector('#toolbar .abs-btn[data-t="' + tg + '"]');
        if (!b) return;
        e.preventDefault();
        if (tg === '#toolInternal') { b.checked = !b.checked; b.dispatchEvent(new Event('change')); say(b.checked ? 'Neue Einträge werden nicht veröffentlicht.' : 'Neue Einträge werden veröffentlicht.'); return; }
        b.click();
    }
    function initToolbar() {
        var tb = document.getElementById('toolbar'); if (!tb) return;
        areaSel = document.getElementById('toolArea');
        wrapToolStatus();
        document.addEventListener('keydown', onToolKey);
        addKeyHints();
        if (!view.toolbar || !areaSel) { afterToolChange(); restoreTool(); return; }
        toolbarOn = true;
        var q = function(s) { return tb.querySelector(s); };
        var absGrp = function(t) { var b = q('.abs-btn[data-t="' + t + '"]'); return b ? (b.closest('.abs-btn-grp') || b) : null; };
        var main = el('div', 'tb-row pq-tb-main'), status = el('div', 'tb-row pq-tb-status');
        function group(label, cls) {
            var g = el('div', 'pq-tb-grp ' + (cls || '')); g.setAttribute('role', 'group'); g.setAttribute('aria-label', label);
            g.appendChild(el('span', 'pq-tb-h', label)); var body = el('div', 'pq-tb-body'); g.appendChild(body); main.appendChild(g); return body;
        }
        function sub(body, label, nodes) {
            var s = el('div', 'pq-tb-sub'); if (label) s.appendChild(el('span', 'pq-tb-subh', label));
            nodes.forEach(function(n) { if (n) s.appendChild(n); }); body.appendChild(s);
        }
        /* Einsatz */
        var gE = group('Einsatz', 'pq-tb-einsatz');
        buildPicker(gE);
        areaSel.classList.add('pq-hidden-select'); areaSel.setAttribute('tabindex', '-1'); areaSel.setAttribute('aria-hidden', 'true');
        gE.appendChild(areaSel);
        recentBox = el('div', 'pq-recent-box'); recentBox.setAttribute('role', 'group'); recentBox.setAttribute('aria-label', 'Zuletzt genutzt');
        gE.appendChild(recentBox);
        /* Abwesenheit */
        var gA = group('Abwesenheit', 'pq-tb-abs');
        sub(gA, 'Krank', [absGrp('krank_attest'), absGrp('krank_ganz'), absGrp('krank_halb')]);
        sub(gA, 'Urlaub', [absGrp('urlaub_genehmigt'), absGrp('urlaub_ganz'), absGrp('urlaub_halb')]);
        var gl = q('.abs-btn[data-t="gleitzeit"]'), ofBtn = q('.abs-btn[data-t="offen"]');
        var offenLabel = ofBtn && ofBtn.parentNode ? ofBtn.parentNode.querySelector('.abs-btn-label') : null;
        var glWrap = el('div', 'abs-btn-grp'); if (gl) { glWrap.appendChild(gl); glWrap.appendChild(el('span', 'abs-btn-label', 'Gleitzeit')); }
        var ofWrap = el('div', 'abs-btn-grp'); if (ofBtn) { ofWrap.appendChild(ofBtn); ofWrap.appendChild(offenLabel || el('span', 'abs-btn-label', 'Offen')); }
        sub(gA, '', [glWrap, ofWrap]);
        /* Bearbeiten */
        var gB = group('Bearbeiten', 'pq-tb-edit');
        sub(gB, '', [q('#toolLock'), q('#toolClr'), q('#toolRemark'), q('#toolIntRemark')]);
        /* Sichtbarkeit */
        var nv = q('.tb-nv-box');
        if (nv) { var gV = group('Sichtbarkeit', 'pq-tb-vis'); gV.appendChild(nv); }
        /* Verlauf + Hilfe: rechts in der Statuszeile */
        var host = el('div', 'pq-tb-hist'); host.id = 'pqUndoHost'; host.setAttribute('role', 'group'); host.setAttribute('aria-label', 'Verlauf und Hilfe');
        var kb = el('button', 'btn btn-xs btn-outline pq-keys-btn', '⌨ Kürzel'); kb.type = 'button'; kb.title = 'Tastenkürzel anzeigen (Taste ?)'; kb.setAttribute('aria-keyshortcuts', '?');
        kb.addEventListener('click', openKeyHelp); host.appendChild(kb);
        /* Statuszeile */
        var st = q('#toolSt'), dsel = q('#toolDeselect'), hint = q('#liveUpdateHint');
        [st, dsel, hint, host].forEach(function(n) { if (n) status.appendChild(n); });
        if (st) { st.setAttribute('role', 'status'); }
        if (dsel) { dsel.textContent = '✕ Werkzeug ablegen'; dsel.title = 'Werkzeug ablegen (Esc)'; }
        /* alte, nun leere Zeilen ersetzen */
        Array.prototype.slice.call(tb.children).forEach(function(r) { if (r.classList.contains('tb-row')) r.remove(); });
        tb.appendChild(main); tb.appendChild(status);
        tb.classList.add('pq-tb');
        areaSel.addEventListener('change', syncPicker);
        afterToolChange();
        restoreTool();
    }
    /* Planleiste: seltene Aktionen (Drucken, Erfassen, Dauereinsatz, Woche kopieren) in „Mehr“ */
    function groupPlanActions() {
        var bar = document.querySelector('.plan-bar-actions'); if (!bar || bar.querySelector('.pq-more')) return;
        var picks = Array.prototype.filter.call(bar.children, function(b) { return b.tagName === 'BUTTON' && /^(Drucken|Erfassen|Dauereinsatz|Woche kopieren)$/.test(b.textContent.trim()); });
        if (picks.length < 2) return;
        var det = el('details', 'pq-more no-print');
        var sum = el('summary', 'btn btn-outline', 'Mehr'); sum.title = 'Erfassen, Dauereinsatz, Woche kopieren, Drucken';
        sum.insertAdjacentHTML('beforeend', ' <span aria-hidden="true">▾</span>');
        var pop = el('div', 'pq-more-pop'); pop.setAttribute('role', 'group'); pop.setAttribute('aria-label', 'Weitere Aktionen');
        det.appendChild(sum); det.appendChild(pop);
        bar.insertBefore(det, picks[0]);
        picks.forEach(function(b) { b.classList.add('pq-more-item'); pop.appendChild(b); b.addEventListener('click', function() { det.open = false; }); });
        document.addEventListener('click', function(e) { if (det.open && !det.contains(e.target)) det.open = false; });
        det.addEventListener('keydown', function(e) { if (e.key === 'Escape' && det.open) { e.stopPropagation(); det.open = false; sum.focus(); } });
    }

    /* ================= 9. Dashboard ================= */
    function initDashboard() {
        /* delegiert: das Dashboard tauscht seine Inhalte live aus (dashboard.js) */
        document.addEventListener('click', function(e) {
            var b = e.target.closest && e.target.closest('[data-pq-publish]');
            if (!b) return;
            var p = String(b.dataset.pqPublish).split('-');
            if (typeof publishPlan === 'function') publishPlan(+p[0], +p[1], false);
        });
    }

    /* ================= Start ================= */
    document.addEventListener('DOMContentLoaded', function() {
        initDashboard();
        var c = document.getElementById('pqPlusCfg');
        if (!c) return;
        try { cfg = JSON.parse(c.getAttribute('data-cfg') || '{}'); } catch (e) { return; }
        tbl = document.getElementById('planTbl') || document.getElementById('gpTbl');
        if (!tbl) return;
        cfg.targets = cfg.targets || {}; cfg.areas = cfg.areas || [];
        view = pqPlus.view(store('pq-plus-view'));
        live = el('div', 'sr-only'); live.id = 'pqPlusLive'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite');
        document.body.appendChild(live);
        buildViewMenu();
        initCrosshair();
        initHoverTracking();
        initLegend();
        initPrint();
        if (tbl.id === 'planTbl' && cfg.edit) initToolbar();
        if (cfg.undo && tbl.id === 'planTbl') initUndo();
        else if (tbl.querySelector('tbody')) new MutationObserver(function() { coverageSchedule(); }).observe(tbl.querySelector('tbody'), { subtree: true, attributes: true, attributeFilter: ['data-t', 'data-v'] });
        if (cfg.copy && tbl.id === 'planTbl') initCopy();
        if (tbl.id === 'planTbl') groupPlanActions();
        applyView();
    });
})();
