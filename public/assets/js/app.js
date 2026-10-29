/* === OpsPilot Einsatzplanung v6.1.8 – app.js === */

/* ============================================================
   CORE: Date helper – FIXES timezone/UTC bug
   ============================================================ */
function localDateStr(d) {
    return d.getFullYear() + '-' +
        ('0'+(d.getMonth()+1)).slice(-2) + '-' +
        ('0'+d.getDate()).slice(-2);
}

/* ============================================================
   CORE: Shared AJAX POST
   ============================================================ */
function ajaxPost(url, formData, onSuccess) {
    fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData })
    .then(function(r) {
        if (!r.ok) throw new Error('Server-Fehler (HTTP ' + r.status + ')');
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) return r.text().then(function() { throw new Error('Unerwartete Antwort'); });
        return r.json();
    })
    .then(function(d) { if (d.success) { if (d.msg) toast(d.msg, 'success'); if (onSuccess) onSuccess(d); } else toast('Fehler: ' + (d.error || 'Unbekannt'), 'error'); })
    .catch(function(e) { toast(e.message || 'Verbindungsfehler', 'error'); });
}

/* -- Toast -- */
function toast(msg, type) {
    type = type || 'info'; var wrap = document.getElementById('toasts'); if (!wrap) return;
    var el = document.createElement('div'); el.className = 'toast toast-' + type; el.textContent = msg; wrap.appendChild(el);
    setTimeout(function() { el.remove(); }, 3500);
}

/* -- Modals -- */
function oMod(id) { var m = document.getElementById(id); if (m) m.classList.add('open'); }
function cMod(id) { var m = document.getElementById(id); if (m) m.classList.remove('open'); }
document.addEventListener('click', function(e) { if (e.target.classList.contains('modal-bg') && !e.target.classList.contains('modal-lock')) e.target.classList.remove('open'); });
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { document.querySelectorAll('.modal-bg.open:not(.modal-lock)').forEach(function(m) { m.classList.remove('open'); }); hideCtxMenu(); }
});

/* ============================================================
   OFFLINE-SPERRE
   Zeigt bei Verbindungsverlust sofort eine ganzseitige Meldung
   und verhindert jegliche Eintragungen. Wird bei Wiederverbindung
   automatisch entfernt.
   ============================================================ */
window.isOffline = false;
(function() {
    function buildOverlay() {
        if (document.getElementById('offlineOverlay')) return document.getElementById('offlineOverlay');
        var ov = document.createElement('div');
        ov.id = 'offlineOverlay';
        ov.className = 'offline-overlay';
        ov.setAttribute('role', 'alertdialog');
        ov.setAttribute('aria-live', 'assertive');
        ov.innerHTML = '<div class="offline-card">' +
            '<svg class="off-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 1l22 22"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>' +
            '<h2>Keine Verbindung</h2>' +
            '<p>Die Verbindung zum Server ist unterbrochen. Aus Sicherheitsgr\u00fcnden sind w\u00e4hrenddessen <b>keine Eintragungen</b> m\u00f6glich.</p>' +
            '<p class="off-hint"><span class="off-spinner"></span>Sobald die Verbindung wiederhergestellt ist, geht es automatisch weiter.</p>' +
            '</div>';
        (document.body || document.documentElement).appendChild(ov);
        return ov;
    }
    function setOffline(state) {
        window.isOffline = !!state;
        var ov = buildOverlay();
        if (state) { ov.classList.add('show'); }
        else { ov.classList.remove('show'); }
    }
    window.epSetOffline = setOffline;
    function refresh() { setOffline(navigator.onLine === false); }
    window.addEventListener('offline', function() { setOffline(true); });
    window.addEventListener('online', function() { setOffline(false); });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refresh);
    } else { refresh(); }
})();

/* Zentraler Schreibschutz: true (+ Hinweis) wenn offline. */
function epBlockedOffline() {
    if (window.isOffline) { toast('Keine Verbindung – Eintragungen sind derzeit nicht m\u00f6glich', 'warning'); return true; }
    return false;
}

/* ============================================================
   CUSTOM CONFIRM / PROMPT (replaces browser dialogs)
   ============================================================ */
function epConfirm(msg, onYes, onNo) {
    var bg = document.getElementById('confirmMod');
    if (!bg) {
        bg = document.createElement('div'); bg.className = 'modal-bg'; bg.id = 'confirmMod';
        bg.innerHTML = '<div class="modal modal-sm"><div class="confirm-body"><div class="confirm-icon confirm-icon-warn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><div class="confirm-msg" id="confirmMsg"></div></div><div class="mf mf-confirm"><button class="btn btn-outline" id="confirmNo">Abbrechen</button><button class="btn btn-primary" id="confirmYes">Bestätigen</button></div></div>';
        document.body.appendChild(bg);
    }
    document.getElementById('confirmMsg').textContent = msg;
    bg.classList.add('open');
    var yes = document.getElementById('confirmYes'), no = document.getElementById('confirmNo');
    var cleanup = function() { bg.classList.remove('open'); yes.onclick = null; no.onclick = null; };
    yes.onclick = function() { cleanup(); if (onYes) onYes(); };
    no.onclick = function() { cleanup(); if (onNo) onNo(); };
}
function epPrompt(msg, defVal, onOk) {
    var bg = document.getElementById('promptMod');
    if (!bg) {
        bg = document.createElement('div'); bg.className = 'modal-bg'; bg.id = 'promptMod';
        bg.innerHTML = '<div class="modal modal-sm"><div class="mh"><div class="mh-title"><div class="mh-icon mh-icon-info"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div><h3 id="promptTitle">Eingabe</h3></div><button class="mx" onclick="cMod(\'promptMod\')">&times;</button></div><div class="mb"><p class="confirm-msg" id="promptMsg"></p><textarea class="prompt-input" id="promptInput" rows="3"></textarea></div><div class="mf"><button class="btn btn-outline" onclick="cMod(\'promptMod\')">Abbrechen</button><button class="btn btn-primary" id="promptOk">Speichern</button></div></div>';
        document.body.appendChild(bg);
    }
    document.getElementById('promptMsg').textContent = msg;
    var inp = document.getElementById('promptInput'); inp.value = defVal || '';
    bg.classList.add('open'); inp.focus();
    document.getElementById('promptOk').onclick = function() { bg.classList.remove('open'); if (onOk) onOk(inp.value); };
}

/* ============================================================
   FILTER PERSISTENCE (survives month navigation)
   ============================================================ */
function saveFilterState() {
    try {
        var state = {
            name: (document.getElementById('filterName')||{}).value || '',
            role: (document.getElementById('filterRole')||{}).value || '',
            area: (document.getElementById('filterArea')||{}).value || ''
        };
        localStorage.setItem('ep_filters', JSON.stringify(state));
    } catch(e) {}
}
function restoreFilterState() {
    try {
        /* gespeicherte Sortierung anwenden (bleibt dauerhaft erhalten) */
        var sm = localStorage.getItem('ep_sort') || 'standard';
        applySort(sm, true);
    } catch(e) {}
    try {
        var raw = localStorage.getItem('ep_filters');
        if (!raw) return;
        var state = JSON.parse(raw);
        var fn = document.getElementById('filterName'), fr = document.getElementById('filterRole'), fa = document.getElementById('filterArea');
        if (fn && state.name) fn.value = state.name;
        if (fr && state.role) fr.value = state.role;
        if (fa && state.area) fa.value = state.area;
        applyFilters();
        updatePrintFilter();
    } catch(e) {}
}
/* ============================================================
   SORTIERUNG innerhalb der Gruppen
   "standard" = Manager, dann Teamleiter, dann übrige – je alphabetisch
   "alpha"    = rein alphabetisch nach Name
   ============================================================ */
function _roleRank(role) { if (role === 'manager') return 0; if (role === 'teamleiter') return 1; return 2; }
function _sortLabel(mode) { return mode === 'alpha' ? 'A\u2013Z' : 'Standard'; }
function updateSortBtn(mode) {
    var lbl = document.getElementById('sortToggleLabel'); if (lbl) lbl.textContent = _sortLabel(mode);
    var btn = document.getElementById('sortToggle');
    if (btn) { btn.setAttribute('data-mode', mode); btn.title = 'Sortierung: ' + _sortLabel(mode) + ' – klicken zum Umschalten'; }
}
function toggleSort() {
    var btn = document.getElementById('sortToggle');
    var cur = (btn && btn.getAttribute('data-mode')) || 'standard';
    var next = cur === 'alpha' ? 'standard' : 'alpha';
    applySort(next);
}
function applySort(mode, skipSave) {
    var tbl = document.getElementById('planTbl') || document.getElementById('gpTbl');
    if (!tbl) { updateSortBtn(mode || 'standard'); return; }
    mode = mode || 'standard';
    var byGroup = {};
    tbl.querySelectorAll('.plan-row').forEach(function(r) {
        var g = r.getAttribute('data-group') || '';
        (byGroup[g] = byGroup[g] || []).push(r);
    });
    tbl.querySelectorAll('.gr[data-group]').forEach(function(gr) {
        var gn = gr.getAttribute('data-group');
        var rows = byGroup[gn];
        if (!rows || !rows.length) return;
        rows.sort(function(a, b) {
            var na = a.getAttribute('data-name') || '', nb = b.getAttribute('data-name') || '';
            if (mode === 'standard') {
                var ra = _roleRank(a.getAttribute('data-realrole')), rb = _roleRank(b.getAttribute('data-realrole'));
                if (ra !== rb) return ra - rb;
            }
            return na.localeCompare(nb, 'de');
        });
        var ref = gr;
        rows.forEach(function(row) { ref.parentNode.insertBefore(row, ref.nextSibling); ref = row; });
    });
    updateSortBtn(mode);
    if (!skipSave) { try { localStorage.setItem('ep_sort', mode); } catch(e) {} }
}

/* ============================================================
   ADVANCED FILTER (name + role + area)
   ============================================================ */
function applyFilters() {
    var nameQ = (document.getElementById('filterName') || {}).value || '';
    var roleQ = (document.getElementById('filterRole') || {}).value || '';
    var areaQ = (document.getElementById('filterArea') || {}).value || '';
    nameQ = nameQ.toLowerCase().trim();
    saveFilterState();
    updatePrintFilter();
    updateFilterResetBtn();
    var tbl = document.getElementById('planTbl') || document.getElementById('gpTbl');
    if (!tbl) return;
    tbl.querySelectorAll('.plan-row').forEach(function(r) {
        var name = r.getAttribute('data-name') || '';
        var role = r.getAttribute('data-role') || '';
        var areas = r.getAttribute('data-areas') || '';
        var show = true;
        if (nameQ && name.indexOf(nameQ) === -1) show = false;
        if (roleQ) {
            if (roleQ === 'azubis') {
                if (role !== 'azubi_1' && role !== 'azubi_2' && role !== 'azubi_3') show = false;
            } else if (role !== roleQ) show = false;
        }
        if (areaQ && areas.indexOf(',' + areaQ + ',') === -1) show = false;
        if (window.gpMineOnly && r.getAttribute('data-mine') !== '1') show = false;
        r.style.display = show ? '' : 'none';
    });
    /* Hide group headers with no visible rows */
    tbl.querySelectorAll('.gr[data-group]').forEach(function(gr) {
        var gn = gr.getAttribute('data-group');
        var hasVisible = tbl.querySelector('.plan-row[data-group="' + gn + '"]:not([style*="display: none"])');
        gr.style.display = hasVisible ? '' : 'none';
    });
}
function toggleMine() {
    window.gpMineOnly = !window.gpMineOnly;
    var btn = document.getElementById('mineToggleBtn');
    if (btn) btn.classList.toggle('active', window.gpMineOnly);
    applyFilters();
}
function filterPlan(q) {
    var el = document.getElementById('filterName');
    if (el) { el.value = q; applyFilters(); } else {
        q = q.toLowerCase().trim();
        var tbl = document.getElementById('planTbl') || document.getElementById('gpTbl');
        if (!tbl) return;
        tbl.querySelectorAll('.plan-row').forEach(function(r) {
            var name = r.getAttribute('data-name') || '';
            r.style.display = (q === '' || name.indexOf(q) !== -1) ? '' : 'none';
        });
        tbl.querySelectorAll('.gr[data-group]').forEach(function(gr) {
            var gn = gr.getAttribute('data-group');
            var hasVisible = tbl.querySelector('.plan-row[data-group="' + gn + '"]:not([style*="display: none"])');
            gr.style.display = hasVisible ? '' : 'none';
        });
    }
}
function resetFilters() {
    var fn = document.getElementById('filterName'), fr = document.getElementById('filterRole'), fa = document.getElementById('filterArea');
    if (fn) fn.value = ''; if (fr) fr.value = ''; if (fa) fa.value = '';
    try { localStorage.removeItem('ep_filters'); } catch(e) {}
    applyFilters();
}
function updateFilterResetBtn() {
    var btn = document.getElementById('filterResetBtn');
    if (!btn) return;
    var fn = (document.getElementById('filterName')||{}).value || '';
    var fr = (document.getElementById('filterRole')||{}).value || '';
    var fa = (document.getElementById('filterArea')||{}).value || '';
    btn.style.display = (fn || fr || fa) ? '' : 'none';
}

/* ============================================================
   PLAN EDITING
   ============================================================ */
var currentTool = null;
var absTypes = {
    krank_attest:  { code:'K', color:'#16a34a', fg:'#fff' },
    krank_ganz:    { code:'K', color:'#dc2626', fg:'#fff' },
    krank_halb:    { code:'k', color:'#ef4444', fg:'#fff' },
    urlaub_genehmigt: { code:'U', color:'#16a34a', fg:'#fff' },
    urlaub_ganz:   { code:'U', color:'#dc2626', fg:'#fff' },
    urlaub_halb:   { code:'u', color:'#fbbf24', fg:'#000' },
    gleitzeit:     { code:'GL', color:'#8b5cf6', fg:'#fff' },
    gesperrt:      { code:'\u2014', color:'#94a3b8', fg:'#fff' },
    offen:         { code:'?', color:'#475569', fg:'#fff' }
};

function setToolStatus() {
    var st = document.getElementById('toolSt'); if (!st) return;
    var dBtn = document.getElementById('toolDeselect');
    st.style.color = ''; /* Reset color from remark mode */
    if (!currentTool) { st.textContent = 'Werkzeug w\u00e4hlen'; st.className = 'muted'; if(dBtn) dBtn.style.display='none'; return; }
    if(dBtn) dBtn.style.display='';
    if (currentTool.type === 'clear') { st.innerHTML = '<b>L\u00f6schen</b> aktiv'; st.className = 'txt-d'; }
    else if (currentTool.type === 'locked') { st.innerHTML = '<b>Sperren</b> aktiv'; st.className = ''; }
    else if (currentTool.type === 'remark') { st.innerHTML = '<b>\u270e Bemerkung</b> – Zellen w\u00e4hlen &amp; loslassen'; st.className = ''; st.style.color='#f97316'; }
    else if (currentTool.type === 'intremark') { st.innerHTML = '<b>\u270e Int. Bemerkung</b> – Zellen w\u00e4hlen &amp; loslassen'; st.className = ''; st.style.color='#c8b400'; }
    else { st.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:' + currentTool.color + ';vertical-align:middle;margin-right:4px"></span> <b>' + currentTool.code + '</b> aktiv'; st.className = ''; }
}

function deselectTool() {
    currentTool = null;
    var sel = document.getElementById('toolArea'); if(sel) sel.value = '';
    document.querySelectorAll('.abs-btn, #toolClr, #toolLock, #toolRemark, #toolIntRemark').forEach(function(b) { b.classList.remove('tool-active'); });
    setToolStatus();
}

function updateNvToggle() {
    var cb = document.getElementById('toolInternal');
    var label = document.getElementById('tbNvLabel');
    var box = cb ? cb.closest('.tb-nv-box') : null;
    if (!cb || !label) return;
    if (cb.checked) {
        label.textContent = 'Nicht ver\u00f6ffentlichen';
        label.style.color = '#c8b400';
        if (box) box.classList.add('nv-active');
    } else {
        label.textContent = 'Ver\u00f6ffentlichen';
        label.style.color = 'var(--ok)';
        if (box) box.classList.remove('nv-active');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('toolArea');
    if (sel) sel.addEventListener('change', function() {
        clearToolActive(); var opt = sel.options[sel.selectedIndex];
        if (!sel.value) { currentTool = null; setToolStatus(); return; }
        currentTool = { type:'assignment', value:sel.value, color:opt.dataset.c, fg:opt.dataset.f, code:opt.dataset.k };
        setToolStatus();
    });

    document.querySelectorAll('.abs-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            clearToolActive(); btn.classList.add('tool-active');
            var t = btn.dataset.t, def = absTypes[t] || {};
            currentTool = { type:'absence', value:t, code:def.code||'A', color:def.color||'#ef4444', fg:def.fg||'#fff' };
            if (sel) sel.value = ''; setToolStatus();
        });
    });

    var lockBtn = document.getElementById('toolLock');
    if (lockBtn) lockBtn.addEventListener('click', function() {
        clearToolActive(); lockBtn.classList.add('tool-active');
        currentTool = { type:'locked', code:'\u2014', color:'#94a3b8', fg:'#fff' };
        if (sel) sel.value = ''; setToolStatus();
    });

    var clr = document.getElementById('toolClr');
    if (clr) clr.addEventListener('click', function() {
        clearToolActive(); clr.classList.add('tool-active');
        currentTool = { type:'clear' }; if (sel) sel.value = ''; setToolStatus();
    });

    var remarkBtn = document.getElementById('toolRemark');
    if (remarkBtn) remarkBtn.addEventListener('click', function() {
        clearToolActive(); remarkBtn.classList.add('tool-active');
        remarkBtn.style.background='#f97316';remarkBtn.style.color='#fff';remarkBtn.style.borderColor='#f97316';
        currentTool = { type:'remark' }; if (sel) sel.value = ''; setToolStatus();
    });

    var intRemarkBtn = document.getElementById('toolIntRemark');
    if (intRemarkBtn) intRemarkBtn.addEventListener('click', function() {
        clearToolActive(); intRemarkBtn.classList.add('tool-active');
        intRemarkBtn.style.background='#c8b400';intRemarkBtn.style.color='#fff';intRemarkBtn.style.borderColor='#c8b400';
        currentTool = { type:'intremark' }; if (sel) sel.value = ''; setToolStatus();
    });

    function clearToolActive() {
        document.querySelectorAll('.abs-btn, #toolClr, #toolLock, #toolRemark, #toolIntRemark').forEach(function(b) {
            b.classList.remove('tool-active');
        });
        /* Reset remark button styling */
        var rb=document.getElementById('toolRemark');
        if(rb){rb.style.background='#f97316';rb.style.color='#fff';rb.style.borderColor='#f97316';}
        var irb=document.getElementById('toolIntRemark');
        if(irb){irb.style.background='#c8b400';irb.style.color='#fff';irb.style.borderColor='#c8b400';}
    }

    /* Cell drag – LOCKED TO ONE ROW to prevent slip */
    var selecting = false, selectedCells = [], dragUserId = null;
    document.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        if (window.isOffline) { return; }
        var c = e.target.closest('.pe-c'); if (!c || c.dataset.bl === '1') return;
        if (!document.getElementById('planTbl')) return;
        if (!currentTool) { toast('Bitte zuerst ein Werkzeug w\u00e4hlen', 'info'); return; }
        if (isEditLocked) { toast('Bearbeitung gesperrt – anderer Benutzer aktiv', 'warning'); return; }
        /* Check row lock (another user is editing this person) */
        var row = c.closest('.plan-row');
        if (row && row.classList.contains('row-locked-by-other')) {
            toast('Diese Person wird gerade von einem anderen Benutzer bearbeitet', 'warning'); return;
        }
        /* Check entry/exit date blocking */
        if (c.classList.contains('cell-unavailable')) {
            toast('Person nicht verfügbar', 'warning'); return;
        }
        e.preventDefault(); selecting = true; selectedCells = []; dragUserId = c.dataset.u; tryAddCell(c);
        /* Lock this row for other users */
        if (typeof lockRow === 'function') lockRow(parseInt(dragUserId));
    });
    document.addEventListener('mouseover', function(e) {
        if (!selecting) return;
        var c = e.target.closest('.pe-c'); if (!c || c.dataset.bl === '1') return;
        /* Lock to same row (same user) */
        if (c.dataset.u !== dragUserId) return;
        tryAddCell(c);
    });
    document.addEventListener('mouseup', function() {
        if (!selecting) return; selecting = false; dragUserId = null;
        if (selectedCells.length > 0) applyTool(selectedCells);
        selectedCells.forEach(function(c) { c.classList.remove('cell-sel'); }); selectedCells = [];
    });

    function tryAddCell(c) {
        if (selectedCells.indexOf(c) !== -1) return;
        if (c.dataset.webl === '1') { if (selecting && selectedCells.length === 0) toast('Wochenendarbeit nicht freigeschaltet', 'warning'); return; }
        if (c.classList.contains('cell-unavailable')) return;
        selectedCells.push(c); c.classList.add('cell-sel');
    }

    function applyTool(cells) {
        if (!currentTool) return;
        if (isEditLocked) { toast('Bearbeitung gesperrt – anderer Benutzer aktiv', 'warning'); return; }
        /* Filter out unavailable cells */
        cells = cells.filter(function(c){ return !c.classList.contains('cell-unavailable'); });
        if (cells.length === 0) return;
        /* Remark tool: prompt and save */
        if (currentTool.type === 'remark' || currentTool.type === 'intremark') {
            var isInt = currentTool.type === 'intremark';
            var promptLbl = (isInt ? 'Interne Bemerkung für ' : 'Bemerkung für ') + cells.length + ' Zelle(n):';
            epPrompt(promptLbl, '', function(txt) {
                var items = [];
                cells.forEach(function(c) {
                    if (isInt) {
                        items.push({ user_id: parseInt(c.dataset.u), date: c.dataset.d, internal_remark: txt });
                        if (txt) { c.dataset.intRemark = txt; c.classList.add('has-remark','has-int-remark'); }
                        else { c.dataset.intRemark = ''; c.classList.remove('has-int-remark'); if(!c.dataset.remark) c.classList.remove('has-remark'); }
                    } else {
                        items.push({ user_id: parseInt(c.dataset.u), date: c.dataset.d, remarks: txt });
                        if (txt) { c.dataset.remark = txt; c.classList.add('has-remark','has-pub-remark'); }
                        else { c.dataset.remark = ''; c.classList.remove('has-pub-remark'); if(!c.dataset.intRemark) c.classList.remove('has-remark'); }
                    }
                });
                fetch(getApiBase() + 'api/save_remark', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ items: items })
                }).then(function(r){return r.json();}).then(function(d){
                    if(d.success) toast(d.msg,'success'); else toast('Fehler','error');
                }).catch(function(e){toast(e.message,'error');});
            });
            return;
        }
        /* Check if any cells have drafts from other users */
        var otherDraftCells = cells.filter(function(c){ return c.classList.contains('draft-other'); });
        if (otherDraftCells.length > 0 && currentTool.type !== 'remark') {
            var draftAuthors = [];
            otherDraftCells.forEach(function(c){
                var by = c.dataset.draftBy || 'Unbekannt';
                if (draftAuthors.indexOf(by) === -1) draftAuthors.push(by);
            });
            epConfirm(otherDraftCells.length + ' Zelle(n) enthalten unver\u00f6ffentlichte Entwürfe von: ' + draftAuthors.join(', ') + '\n\nTrotzdem \u00fcberschreiben?', function(){
                doApplyChanges(cells, currentTool);
            });
            return;
        }
        doApplyChanges(cells, currentTool);
    }

    /* Setzt die Zellfarbe. An Wochenenden muss die Einsatzfarbe per !important
       gesetzt werden, sonst überschreibt die blasse .we/.we-open-Regel sie. */
    function setCellBg(c, color) {
        c.style.removeProperty('background');
        if (color) {
            if (c.dataset.we === '1') c.style.setProperty('background', color, 'important');
            else c.style.background = color;
        }
    }

    function doApplyChanges(cells, tool) {
        var changes = [];
        cells.forEach(function(c) {
            var uid = c.dataset.u, date = c.dataset.d;
            c.classList.remove('has-remark','has-pub-remark','has-int-remark','cell-locked','draft-mine','draft-other');
            if (tool.type === 'assignment') {
                setCellBg(c, tool.color); c.style.color = tool.fg; c.textContent = tool.code;
                c.dataset.t = 'assignment'; c.dataset.v = tool.value;
                changes.push({ user_id:parseInt(uid), date:date, type:'assignment', value:tool.value });
            } else if (tool.type === 'absence') {
                setCellBg(c, tool.color); c.style.color = tool.fg; c.textContent = tool.code;
                c.dataset.t = 'absence'; c.dataset.v = tool.value;
                changes.push({ user_id:parseInt(uid), date:date, type:'absence', value:tool.value });
            } else if (tool.type === 'locked') {
                setCellBg(c, '#94a3b8'); c.style.color = '#fff'; c.textContent = '\u2014';
                c.dataset.t = 'absence'; c.dataset.v = 'gesperrt'; c.classList.add('cell-locked');
                changes.push({ user_id:parseInt(uid), date:date, type:'locked', value:'gesperrt' });
            } else if (tool.type === 'clear') {
                setCellBg(c, ''); c.style.color = ''; c.textContent = '';
                c.dataset.t = ''; c.dataset.v = '';
                changes.push({ user_id:parseInt(uid), date:date, type:'clear', value:'' });
            }
            c.classList.add('draft-mine');
        });
        if (changes.length > 0) saveBulk(changes, false);
    }

    /* Hours / Snow */
    var ht = {};
    document.querySelectorAll('.inp-hours').forEach(function(inp) {
        inp.addEventListener('change', function() { var uid=inp.dataset.uid; clearTimeout(ht[uid]); ht[uid]=setTimeout(function(){saveMonthData(uid);},400); });
    });
    document.querySelectorAll('.inp-snow').forEach(function(cb) {
        cb.addEventListener('change', function() { saveMonthData(cb.dataset.uid); });
    });

    initContextMenu();
    initRemarkTooltip();
});

/* ============================================================
   CONTEXT MENU (right-click remarks) – supports multi-select
   ============================================================ */
var ctxTarget = null;
var ctxCells = []; /* for multi-select remarks */
function initContextMenu() {
    var menu = document.createElement('div'); menu.className = 'ctx-menu'; menu.id = 'ctxMenu';
    menu.innerHTML =
        '<button class="ctx-item" id="ctxRemark"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 13V5a2 2 0 00-2-2H4a2 2 0 00-2 2v8a2 2 0 002 2h3l3 3 3-3h3a2 2 0 002-2zM5 7a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1zm1 3a1 1 0 100 2h3a1 1 0 100-2H6z" clip-rule="evenodd"/></svg><span>Bemerkung bearbeiten</span></button>' +
        '<button class="ctx-item" id="ctxRemarkBulk" style="display:none"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 13V5a2 2 0 00-2-2H4a2 2 0 00-2 2v8a2 2 0 002 2h3l3 3 3-3h3a2 2 0 002-2zM5 7a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1zm1 3a1 1 0 100 2h3a1 1 0 100-2H6z" clip-rule="evenodd"/></svg><span id="ctxBulkLabel">Bemerkung f\u00fcr X Zellen</span></button>' +
        '<button class="ctx-item ctx-item-int" id="ctxIntRemark" style="display:none"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg><span>Interne Bemerkung</span></button>' +
        '<div class="ctx-sep"></div>' +
        '<button class="ctx-item" id="ctxRemoveRemark"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9z" clip-rule="evenodd"/></svg><span>Bemerkung entfernen</span></button>' +
        '<div class="ctx-sep" id="ctxVacSep" style="display:none"></div>' +
        '<button class="ctx-item ctx-item-unlock" id="ctxUnlockVac" style="display:none"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a5 5 0 00-5 5v2a2 2 0 00-2 2v5a2 2 0 002 2h10a2 2 0 002-2v-5a2 2 0 00-2-2H7V7a3 3 0 015.905-.75 1 1 0 001.937-.5A5.002 5.002 0 0010 2z"/></svg><span>Urlaub entsperren</span></button>' +
        '<button class="ctx-item ctx-item-unlock" id="ctxUnlockSick" style="display:none"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a5 5 0 00-5 5v2a2 2 0 00-2 2v5a2 2 0 002 2h10a2 2 0 002-2v-5a2 2 0 00-2-2H7V7a3 3 0 015.905-.75 1 1 0 001.937-.5A5.002 5.002 0 0010 2z"/></svg><span>Krankheit entsperren</span></button>';
    document.body.appendChild(menu);

    document.addEventListener('contextmenu', function(e) {
        var c = e.target.closest('.pe-c');
        if (!c || !c.dataset.t) { hideCtxMenu(); return; }
        /* No context menu on read-only pages (gesamtplan) */
        if (!document.getElementById('planTbl')) return;
        e.preventDefault(); ctxTarget = c;
        /* Check if multiple cells are selected via cell-sel */
        ctxCells = Array.from(document.querySelectorAll('.cell-sel'));
        if (ctxCells.length < 2) ctxCells = [c];
        var bulkBtn = document.getElementById('ctxRemarkBulk');
        var singleBtn = document.getElementById('ctxRemark');
        if (ctxCells.length > 1) {
            bulkBtn.style.display = ''; singleBtn.style.display = 'none';
            document.getElementById('ctxBulkLabel').textContent = 'Bemerkung f\u00fcr ' + ctxCells.length + ' Zellen';
        } else {
            bulkBtn.style.display = 'none'; singleBtn.style.display = '';
        }
        /* Show/hide internal remark option */
        var intBtn = document.getElementById('ctxIntRemark');
        if (intBtn) intBtn.style.display = (typeof CAN_INTERNAL !== 'undefined' && CAN_INTERNAL) ? '' : 'none';
        /* Show/hide vacation unlock option */
        var vacBtn = document.getElementById('ctxUnlockVac');
        var sickBtn = document.getElementById('ctxUnlockSick');
        var vacSep = document.getElementById('ctxVacSep');
        var showVac = (typeof CAN_UNLOCK_VAC !== 'undefined' && CAN_UNLOCK_VAC && c.dataset.v === 'urlaub_genehmigt');
        var isSick = (c.dataset.v === 'krank_halb' || c.dataset.v === 'krank_ganz' || c.dataset.v === 'krank_attest');
        var showSick = (typeof CAN_UNLOCK_VAC !== 'undefined' && CAN_UNLOCK_VAC && isSick);
        if (vacBtn) vacBtn.style.display = showVac ? '' : 'none';
        if (sickBtn) sickBtn.style.display = showSick ? '' : 'none';
        if (vacSep) vacSep.style.display = (showVac || showSick) ? '' : 'none';
        menu.style.left = e.clientX + 'px'; menu.style.top = e.clientY + 'px'; menu.classList.add('open');
        var rect = menu.getBoundingClientRect();
        if (rect.right > window.innerWidth) menu.style.left = (e.clientX - rect.width) + 'px';
        if (rect.bottom > window.innerHeight) menu.style.top = (e.clientY - rect.height) + 'px';
    });

    document.getElementById('ctxRemark').addEventListener('click', function() {
        hideCtxMenu(); if (!ctxTarget) return;
        epPrompt('Bemerkung eingeben:', ctxTarget.dataset.remark || '', function(txt) { saveRemark(ctxTarget, txt); });
    });
    document.getElementById('ctxRemarkBulk').addEventListener('click', function() {
        hideCtxMenu();
        epPrompt('Bemerkung f\u00fcr ' + ctxCells.length + ' Zellen:', '', function(txt) {
            ctxCells.forEach(function(c) { saveRemark(c, txt); });
        });
    });
    document.getElementById('ctxIntRemark').addEventListener('click', function() {
        hideCtxMenu(); if (!ctxTarget) return;
        epPrompt('Interne Bemerkung (nur f\u00fcr berechtigte Benutzer sichtbar):', ctxTarget.dataset.intRemark || '', function(txt) {
            saveInternalRemark(ctxTarget, txt);
        });
    });
    document.getElementById('ctxRemoveRemark').addEventListener('click', function() {
        hideCtxMenu();
        var targets = ctxCells.length > 1 ? ctxCells : (ctxTarget ? [ctxTarget] : []);
        targets.forEach(function(c) { saveRemark(c, ''); });
    });
    document.getElementById('ctxUnlockVac').addEventListener('click', function() {
        hideCtxMenu(); if (!ctxTarget) return;
        epConfirm('Genehmigten Urlaub f\u00fcr diesen Tag entsperren? Der Eintrag wird gel\u00f6scht.', function() {
            unlockVacation(ctxTarget);
        });
    });
    document.getElementById('ctxUnlockSick').addEventListener('click', function() {
        hideCtxMenu(); if (!ctxTarget) return;
        epConfirm('Krankheitseintrag f\u00fcr diesen Tag entsperren? Der Eintrag wird gel\u00f6scht.', function() {
            unlockAbsence(ctxTarget);
        });
    });
    document.addEventListener('click', function() { hideCtxMenu(); });
}
function hideCtxMenu() { var m = document.getElementById('ctxMenu'); if (m) m.classList.remove('open'); }

function saveRemark(cell, txt) {
    if (epBlockedOffline()) return;
    fetch(getApiBase() + 'api/save_remark', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ user_id: parseInt(cell.dataset.u), date: cell.dataset.d, remarks: txt })
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            cell.dataset.remark = txt;
            if (txt) { cell.classList.add('has-remark','has-pub-remark'); toast('Bemerkung gespeichert', 'success'); }
            else { cell.classList.remove('has-pub-remark'); if(!cell.dataset.intRemark) cell.classList.remove('has-remark'); toast('Bemerkung entfernt', 'info'); }
        } else toast('Fehler', 'error');
    }).catch(function(e) { toast(e.message, 'error'); });
}

function saveInternalRemark(cell, txt) {
    if (epBlockedOffline()) return;
    fetch(getApiBase() + 'api/save_remark', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ user_id: parseInt(cell.dataset.u), date: cell.dataset.d, internal_remark: txt })
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            cell.dataset.intRemark = txt;
            if (txt) { cell.classList.add('has-int-remark'); } else { cell.classList.remove('has-int-remark'); }
            if (txt || cell.dataset.remark) cell.classList.add('has-remark');
            else cell.classList.remove('has-remark');
            toast(txt ? 'Interne Bemerkung gespeichert' : 'Interne Bemerkung entfernt', 'success');
        } else toast('Fehler', 'error');
    }).catch(function(e) { toast(e.message, 'error'); });
}

function unlockVacation(cell) {
    if (epBlockedOffline()) return;
    var uid = parseInt(cell.dataset.u);
    var date = cell.dataset.d;
    fetch(getApiBase() + 'api/save_bulk', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ changes: [{ user_id: uid, date: date, type: 'clear', value: '' }], force_vacation: 1 })
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            cell.style.removeProperty('background'); cell.style.color = ''; cell.textContent = '';
            cell.dataset.t = ''; cell.dataset.v = '';
            cell.removeAttribute('data-vaclock');
            cell.classList.remove('has-remark', 'has-pub-remark', 'has-int-remark', 'cell-locked');
            window._planHasDrafts = true;
            var ub = document.getElementById('unpubBadge'); if (ub) ub.style.display = '';
            toast('Urlaub entsperrt', 'success');
        } else toast('Fehler: ' + (d.error || ''), 'error');
    }).catch(function(e) { toast(e.message, 'error'); });
}

function unlockAbsence(cell) {
    if (epBlockedOffline()) return;
    var uid = parseInt(cell.dataset.u);
    var date = cell.dataset.d;
    fetch(getApiBase() + 'api/save_bulk', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ changes: [{ user_id: uid, date: date, type: 'clear', value: '' }], force_sick: 1 })
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            cell.style.removeProperty('background'); cell.style.color = ''; cell.textContent = '';
            cell.dataset.t = ''; cell.dataset.v = '';
            cell.classList.remove('has-remark', 'has-pub-remark', 'has-int-remark', 'cell-locked');
            window._planHasDrafts = true;
            var ub = document.getElementById('unpubBadge'); if (ub) ub.style.display = '';
            toast('Krankheit entsperrt', 'success');
        } else toast('Fehler: ' + (d.error || ''), 'error');
    }).catch(function(e) { toast(e.message, 'error'); });
}

/* ============================================================
   CUSTOM TOOLTIP SYSTEM
   ============================================================ */
function initRemarkTooltip() {
    var tip = document.createElement('div'); tip.className = 'ep-tip'; tip.id = 'epTip';
    document.body.appendChild(tip);
    var lockSvg = '<svg viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;vertical-align:middle;margin-right:3px;color:#818cf8"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>';
    var draftSvg = '<svg viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;vertical-align:middle;margin-right:3px;color:#fbbf24"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>';

    var hoverTimer = null;
    document.addEventListener('mouseover', function(e) {
        var c = e.target.closest('.pe-c');
        if (!c) { clearTimeout(hoverTimer); tip.style.display = 'none'; return; }
        /* Suppress native browser tooltip */
        if (c.hasAttribute('title')) { c.dataset.origTitle = c.getAttribute('title'); c.removeAttribute('title'); }
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function() { showCellTip(c); }, 180);
    });
    document.addEventListener('mouseout', function(e) {
        var c = e.target.closest('.pe-c');
        if (c) {
            clearTimeout(hoverTimer); tip.style.display = 'none';
            /* Restore title for accessibility */
            if (c.dataset.origTitle) { c.setAttribute('title', c.dataset.origTitle); delete c.dataset.origTitle; }
        }
    });

    function showCellTip(c) {
        var parts = [];
        var hasPublic = c.dataset.remark && c.dataset.remark.trim();
        var hasInternal = c.dataset.intRemark && c.dataset.intRemark.trim();
        var hasDraftOther = c.classList.contains('draft-other');
        var hasDraftMine = c.classList.contains('draft-mine');
        var isInternal = c.classList.contains('cell-internal');
        var isUnavailable = c.classList.contains('cell-unavailable');
        var isVacLock = c.dataset.vaclock === '1';
        var isSick = (c.dataset.v === 'krank_halb' || c.dataset.v === 'krank_ganz' || c.dataset.v === 'krank_attest');
        var isAbsence = c.dataset.t === 'absence';
        var seeDetails = (typeof CAN_SEE_DETAILS !== 'undefined' && CAN_SEE_DETAILS);

        /* Unavailable */
        if (isUnavailable) {
            parts.push('<div class="ep-tip-row" style="color:#f87171"><svg viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;vertical-align:middle;margin-right:3px"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>Person nicht verf\u00fcgbar</div>');
        }

        /* Absence details – only for authorized roles */
        if (seeDetails) {
            if (isVacLock) {
                parts.push('<div class="ep-tip-row" style="color:#fbbf24"><svg viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;vertical-align:middle;margin-right:3px"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>Genehmigter Urlaub (gesperrt)</div>');
            }
            if (isSick && isAbsence) {
                var sickLabel = {krank_halb:'Krank (halber Tag)',krank_ganz:'Krank (ganzer Tag)',krank_attest:'Krank (Attest)'}[c.dataset.v] || 'Krank';
                parts.push('<div class="ep-tip-row" style="color:#f87171"><svg viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;vertical-align:middle;margin-right:3px"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3.586L7.707 9.293a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 10.586V7z" clip-rule="evenodd"/></svg>' + sickLabel + '</div>');
            }
            if (c.classList.contains('cell-locked') && !isSick) {
                parts.push('<div class="ep-tip-row" style="color:#94a3b8"><svg viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;vertical-align:middle;margin-right:3px"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>Gesperrt</div>');
            }
        } else if (isAbsence && !isUnavailable) {
            /* Techniker: generic "Abwesend" without details */
            parts.push('<div class="ep-tip-row" style="color:var(--mu)">Abwesend</div>');
        }

        /* Draft info – 2 lines */
        if (hasDraftOther) {
            parts.push('<div class="ep-tip-row ep-tip-draft">' + draftSvg + '<b>Entwurf von ' + esc(c.dataset.draftBy || '?') + '</b><br><span style="opacity:.7;font-size:.68rem;margin-left:13px">Noch nicht ver\u00f6ffentlicht</span></div>');
        } else if (hasDraftMine) {
            parts.push('<div class="ep-tip-row ep-tip-mine"><svg viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;vertical-align:middle;margin-right:3px;color:#60a5fa"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg><b>Eigener Entwurf</b><br><span style="opacity:.7;font-size:.68rem;margin-left:13px">Noch nicht ver\u00f6ffentlicht</span></div>');
        }

        /* Internal entry marker */
        if (isInternal) {
            parts.push('<div class="ep-tip-row" style="color:#c8b400;font-size:.72rem"><svg viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;vertical-align:middle;margin-right:3px"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"/><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z"/></svg>Wird nicht ver\u00f6ffentlicht</div>');
        }

        /* Public remark */
        if (hasPublic) {
            parts.push('<div class="ep-tip-row">' + esc(c.dataset.remark) + '</div>');
        }

        /* Internal remark */
        if (hasInternal) {
            parts.push('<div class="ep-tip-row" style="color:#a5b4fc">' + lockSvg + '<span style="font-weight:600">Intern:</span> ' + esc(c.dataset.intRemark) + '</div>');
        }

        /* Author/timestamp */
        var metaParts = [];
        if (c.dataset.remarkBy) metaParts.push(esc(c.dataset.remarkBy));
        if (c.dataset.remarkAt) metaParts.push(esc(c.dataset.remarkAt));
        if (metaParts.length > 0 && (hasPublic || hasInternal)) {
            parts.push('<div class="ep-tip-meta">' + metaParts.join(' \u00b7 ') + '</div>');
        }

        if (parts.length === 0) { tip.style.display = 'none'; return; }

        tip.innerHTML = parts.join('');
        tip.style.display = 'block';
        var rect = c.getBoundingClientRect();
        var tipW = tip.offsetWidth, tipH = tip.offsetHeight;
        var left = Math.min(rect.left, window.innerWidth - tipW - 8);
        var top = rect.top - tipH - 8;
        if (top < 4) top = rect.bottom + 8;
        tip.style.left = Math.max(4, left) + 'px';
        tip.style.top = top + 'px';
    }
}

/* ============================================================
   API
   ============================================================ */
function saveBulk(changes, showToast, opts) {
    if (epBlockedOffline()) return;
    opts = opts || {};
    var isInternal = document.getElementById('toolInternal') ? document.getElementById('toolInternal').checked : false;
    var payload = {changes:changes};
    if(isInternal) payload.is_internal = 1;
    if(opts.force_vacation) payload.force_vacation = 1;
    if(opts.force_sick) payload.force_sick = 1;
    if(opts.is_internal) payload.is_internal = 1;
    fetch(getApiBase()+'api/save_bulk',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
    .then(function(d){if(d.success){
        if(showToast)toast(d.count+' Zelle(n) gespeichert','success');
        if(d.vac_blocked>0) toast(d.vac_blocked+' Zelle(n) übersprungen (genehmigter Urlaub gesperrt)','warning');
        lastRefreshHash='';
        window._planHasDrafts=true;
        var ub=document.getElementById('unpubBadge');if(ub)ub.style.display='';
        var hint=document.getElementById('liveUpdateHint');
        if(hint){hint.textContent=d.count+' gespeichert';hint.style.display='';setTimeout(function(){hint.style.display='none';},3000);}
        if(typeof liveCellRefresh==='function')setTimeout(function(){liveCellRefresh(false);},200);
    }else toast('Fehler: '+(d.error||''),'error');})
    .catch(function(e){toast(e.message||'Verbindungsfehler','error');});
}
function publishPlan(y,m,all){if(epBlockedOffline())return;
    var msg = all ? 'ALLE \u00c4nderungen f\u00fcr '+m+'/'+y+' ver\u00f6ffentlichen?' : 'Meine \u00c4nderungen f\u00fcr '+m+'/'+y+' ver\u00f6ffentlichen?\n\n\u00c4nderungen anderer Benutzer bleiben als Entw\u00fcrfe erhalten.';
    epConfirm(msg, function(){
        fetch(getApiBase()+'api/publish',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({year:y,month:m,publish_all:all?1:0})})
        .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
        .then(function(d){if(d.success){
            var info=d.applied+' \u00c4nderung(en) ver\u00f6ffentlicht';
            if(d.other_drafts>0) info+='. '+d.other_drafts+' \u00c4nderung(en) anderer Benutzer verbleiben als Entw\u00fcrfe.';
            toast(info,'success');
            window._planHasDrafts=false;window._publishedThisSession=true;setTimeout(function(){location.reload();},1200);
        }else toast('Fehler: '+(d.error||''),'error');})
        .catch(function(e){toast(e.message,'error');});
    });
}
/* Warn before leaving with unpublished drafts – custom modal */
window._planHasDrafts = false;
window._leaveTarget = null;
window._publishedThisSession = false;

function showLeaveModal(targetUrl) {
    window._leaveTarget = targetUrl;
    oMod('leaveMod');
}

document.addEventListener('DOMContentLoaded', function() {
    /* Setup leave modal buttons */
    var leaveBtn = document.getElementById('leaveConfirmBtn');
    var pubBtn = document.getElementById('leavePublishBtn');
    if (leaveBtn) {
        leaveBtn.addEventListener('click', function() {
            cMod('leaveMod');
            window._planHasDrafts = false; /* Prevent re-trigger */
            if (window._leaveTarget) window.location.href = window._leaveTarget;
        });
    }
    if (pubBtn) {
        pubBtn.addEventListener('click', function() {
            cMod('leaveMod');
            /* Publish own changes then navigate */
            if (typeof PY !== 'undefined' && typeof PM !== 'undefined') {
                fetch(getApiBase()+'api/publish',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({year:PY,month:PM,publish_all:0})})
                .then(function(r){return r.json();})
                .then(function(d){
                    if(d.success){
                        toast(d.applied+' \u00c4nderung(en) ver\u00f6ffentlicht','success');
                        window._planHasDrafts = false;
                        window._publishedThisSession = true;
                        if(window._leaveTarget) setTimeout(function(){ window.location.href = window._leaveTarget; }, 800);
                    } else { toast('Fehler: '+(d.error||''),'error'); }
                }).catch(function(e){toast(e.message,'error');});
            }
        });
    }

    /* Intercept all nav links */
    document.querySelectorAll('a[href]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            if (!window._planHasDrafts) return;
            var href = link.getAttribute('href');
            /* Skip non-navigation links */
            if (!href || href === '#' || href.indexOf('javascript:') === 0) return;
            /* Skip print, modal openers */
            if (link.onclick) return;
            /* Skip month navigation (same page) */
            if (href.indexOf('?year=') === 0) return;
            e.preventDefault();
            showLeaveModal(href);
        });
    });
});
function saveMonthData(uid){if(window.isOffline)return;if(typeof PY==='undefined'||typeof PM==='undefined')return;
var h=document.querySelector('.inp-hours[data-uid="'+uid+'"]');var s=document.querySelector('.inp-snow[data-uid="'+uid+'"]');
fetch(getApiBase()+'api/save_month_data',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user_id:parseInt(uid),year:PY,month:PM,hours:h?h.value:'',snow_checked:s?(s.checked?1:0):0})})
.then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();}).then(function(d){if(!d.success)toast('Fehler','error');}).catch(function(){});}

/* ============================================================
   WOT MANAGER
   ============================================================ */
function openWotManager(uid,name){document.getElementById('wotMgrUid').value=uid;document.getElementById('wotMgrTitle').textContent='WOTs \u2013 '+name;document.getElementById('nwNum').value='';document.getElementById('nwColor').value='#3b82f6';document.getElementById('nwLink').value='';document.getElementById('nwRem').value='';var vis=document.getElementById('nwVisible');if(vis)vis.checked=true;oMod('wotMgrMod');loadWotList(uid);}
function openWotDetail(uid,name){document.getElementById('wotDetTitle').textContent='WOTs \u2013 '+name;var list=document.getElementById('wotDetList');list.innerHTML='<p class="muted">Laden...</p>';oMod('wotDetMod');
fetch(getApiBase()+'api/save_wots',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list',user_id:uid,year:PY,month:PM})})
.then(function(r){return r.json();}).then(function(d){if(!d.success||!d.wots||d.wots.length===0){list.innerHTML='<p class="muted">Keine WOTs</p>';return;}
var html='<ul class="wot-list">';d.wots.forEach(function(w){html+='<li class="wot-item"><div class="wot-item-color" style="background:'+safeColor(w.color||'#3b82f6')+'"></div><div class="wot-item-body"><div class="wot-item-num">'+esc(w.wot_number)+'</div>';if(w.link_url)html+='<div class="wot-item-link"><a href="'+esc(safeUrl(w.link_url))+'" target="_blank" rel="noopener noreferrer">'+esc(w.link_url.substring(0,50))+'</a></div>';if(w.remarks)html+='<div class="wot-item-rem">'+esc(w.remarks)+'</div>';html+='</div></li>';});html+='</ul>';list.innerHTML=html;}).catch(function(){list.innerHTML='<p class="txt-d">Fehler</p>';});}

function loadWotList(uid){var list=document.getElementById('wotMgrList');list.innerHTML='<p class="muted">Laden...</p>';
fetch(getApiBase()+'api/save_wots',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list',user_id:uid,year:PY,month:PM})})
.then(function(r){return r.json();}).then(function(d){if(!d.success||!d.wots||d.wots.length===0){list.innerHTML='<p class="muted">Keine WOTs</p>';return;}
var html='<ul class="wot-list">';d.wots.forEach(function(w){
var isHidden=(w.visible_to_user==='0'||w.visible_to_user===0);
var visIcon=isHidden?'<svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;opacity:.4;vertical-align:middle"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"/><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z"/></svg>':'<svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;color:var(--ok);vertical-align:middle"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>';
html+='<li class="wot-item" data-wid="'+w.id+'"><div class="wot-item-color" style="background:'+safeColor(w.color||'#3b82f6')+'"></div><div class="wot-item-body"><div class="wot-item-num">'+esc(w.wot_number)+' '+visIcon+'</div>';
if(w.link_url)html+='<div class="wot-item-link"><a href="'+esc(safeUrl(w.link_url))+'" target="_blank" rel="noopener noreferrer">'+esc(w.link_url.substring(0,50))+'</a></div>';
if(w.remarks)html+='<div class="wot-item-rem">'+esc(w.remarks)+'</div>';
html+='</div><div class="wot-item-acts"><button class="btn btn-xs btn-outline" onclick="toggleWotVis('+w.id+','+(isHidden?1:0)+')">'+(isHidden?'Zeigen':'Verbergen')+'</button> <button class="btn btn-xs btn-outline" onclick="editWot('+w.id+')">Bearb.</button> <button class="btn btn-xs btn-outline btn-danger-text" onclick="deleteWot('+w.id+')">Entf.</button></div></li>';});html+='</ul>';list.innerHTML=html;}).catch(function(){list.innerHTML='<p class="txt-d">Fehler</p>';});}

function addWot(){if(epBlockedOffline())return;var uid=parseInt(document.getElementById('wotMgrUid').value);var num=document.getElementById('nwNum').value.trim();if(!num){toast('WOT-Nummer eingeben','warning');return;}var vis=document.getElementById('nwVisible');
fetch(getApiBase()+'api/save_wots',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',user_id:uid,year:PY,month:PM,wot_number:num,link_url:document.getElementById('nwLink').value.trim(),color:document.getElementById('nwColor').value,remarks:document.getElementById('nwRem').value.trim(),visible_to_user:vis&&!vis.checked?0:1})})
.then(function(r){return r.json();}).then(function(d){if(d.success){toast('WOT hinzugef\u00fcgt','success');document.getElementById('nwNum').value='';document.getElementById('nwLink').value='';document.getElementById('nwRem').value='';loadWotList(uid);setTimeout(function(){location.reload();},1200);}else toast('Fehler','error');}).catch(function(e){toast(e.message,'error');});}
function toggleWotVis(wid,newVal){var uid=parseInt(document.getElementById('wotMgrUid').value);fetch(getApiBase()+'api/save_wots',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update',id:wid,visible_to_user:newVal})}).then(function(r){return r.json();}).then(function(d){if(d.success)loadWotList(uid);else toast('Fehler','error');}).catch(function(){});}
function deleteWot(wid){epConfirm('WOT l\u00f6schen?',function(){var uid=parseInt(document.getElementById('wotMgrUid').value);fetch(getApiBase()+'api/save_wots',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id:wid})}).then(function(r){return r.json();}).then(function(d){if(d.success)loadWotList(uid);else toast('Fehler','error');}).catch(function(){});});}
function editWot(wid){var item=document.querySelector('.wot-item[data-wid="'+wid+'"]');if(!item)return;var body=item.querySelector('.wot-item-body');var numEl=item.querySelector('.wot-item-num');var linkEl=item.querySelector('.wot-item-link a');var remEl=item.querySelector('.wot-item-rem');var colorEl=item.querySelector('.wot-item-color');var oldNum=numEl?numEl.childNodes[0].textContent.trim():'';var oldLink=linkEl?linkEl.getAttribute('href'):'';var oldRem=remEl?remEl.textContent:'';var oldColor=colorEl?colorEl.style.background||'#3b82f6':'#3b82f6';
body.innerHTML='<div style="display:flex;flex-direction:column;gap:4px"><input type="text" class="inp-sm" id="ew_num_'+wid+'" value="'+esc(oldNum)+'" style="font-weight:700"><div style="display:flex;gap:4px"><input type="color" id="ew_col_'+wid+'" value="'+rgbToHex(oldColor)+'" style="width:30px;height:26px"><input type="url" class="inp-sm" id="ew_link_'+wid+'" value="'+esc(oldLink)+'" placeholder="Link" style="flex:1"></div><textarea class="inp-sm" id="ew_rem_'+wid+'" rows="2">'+esc(oldRem)+'</textarea></div>';
item.querySelector('.wot-item-acts').innerHTML='<button class="btn btn-xs btn-primary" onclick="saveWotEdit('+wid+')">OK</button><button class="btn btn-xs btn-outline" onclick="loadWotList('+document.getElementById('wotMgrUid').value+')">Abbruch</button>';}
function saveWotEdit(wid){var uid=parseInt(document.getElementById('wotMgrUid').value);var num=document.getElementById('ew_num_'+wid).value.trim();if(!num)return;
fetch(getApiBase()+'api/save_wots',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update',id:wid,wot_number:num,color:document.getElementById('ew_col_'+wid).value,link_url:document.getElementById('ew_link_'+wid).value.trim(),remarks:document.getElementById('ew_rem_'+wid).value.trim()})})
.then(function(r){return r.json();}).then(function(d){if(d.success)loadWotList(uid);else toast('Fehler','error');}).catch(function(){});}

/* ============================================================ MANUAL ENTRY ============================================================ */
function saveEntry(){
    if (epBlockedOffline()) return;
    var users=[];document.querySelectorAll('.entryUserCb:checked').forEach(function(cb){users.push(parseInt(cb.value));});
    if(users.length===0){toast('Bitte mindestens einen Mitarbeiter wählen','warning');return;}
    var from=document.getElementById('entryFrom').value;var to=document.getElementById('entryTo').value;
    var typeVal=document.getElementById('entryType').value;
    var inclWe=document.getElementById('entryWe')?document.getElementById('entryWe').checked:false;
    if(!from||!to||!typeVal){toast('Bitte Zeitraum und Typ angeben','warning');return;}
    var parts=typeVal.split(':');var type=parts[0]==='a'?'assignment':'absence';var value=parts[1];
    if(value==='gesperrt')type='locked';
    var changes=[];var d=new Date(from+'T12:00:00');var end=new Date(to+'T12:00:00');
    while(d<=end){var dow=d.getDay();var isWe=(dow===0||dow===6);if(!isWe||inclWe){var ds=localDateStr(d);users.forEach(function(uid){changes.push({user_id:uid,date:ds,type:type,value:value});});}d.setDate(d.getDate()+1);}
    if(changes.length===0){toast('Keine passenden Tage im Zeitraum','warning');return;}
    var et=document.getElementById('entryType');
    var newLabel=(et&&et.selectedOptions&&et.selectedOptions[0])?et.selectedOptions[0].text:'';
    planWithConflictCheck(changes, {closeModal:'entryMod', newLabel:newLabel});
}
function filterEntryUsers(){
    var q=(document.getElementById('entryUserSearch').value||'').toLowerCase().trim();
    document.querySelectorAll('#entryUserPick .sup-pick-item').forEach(function(it){
        var n=it.getAttribute('data-name')||'';it.style.display=(!q||n.indexOf(q)!==-1)?'':'none';
    });
}
function entryUsersAll(state){
    document.querySelectorAll('#entryUserPick .sup-pick-item').forEach(function(it){
        if(it.style.display==='none')return;var cb=it.querySelector('.entryUserCb');if(cb)cb.checked=state;
    });
    updateEntryCnt();
}
function updateEntryCnt(){
    var n=document.querySelectorAll('.entryUserCb:checked').length;
    var el=document.getElementById('entryUserCnt');if(el)el.textContent=n;
}

/* ============================================================ DAUEREINSATZ ============================================================ */
function saveRecurring(){
    var selectedUsers=[];document.querySelectorAll('.recurUserCb:checked').forEach(function(cb){selectedUsers.push(parseInt(cb.value));});
    if(selectedUsers.length===0){toast('Mitarbeiter w\u00e4hlen','warning');return;}
    var from=document.getElementById('recurFrom').value;var to=document.getElementById('recurTo').value;
    if(!from||!to){toast('Zeitraum angeben','warning');return;}
    var dayChecks=document.querySelectorAll('.recurDay:checked');var selectedDays=[];
    dayChecks.forEach(function(cb){selectedDays.push(parseInt(cb.value));});
    if(selectedDays.length===0){toast('Wochentag w\u00e4hlen','warning');return;}
    var typeVal=document.getElementById('recurType').value;
    if(!typeVal){toast('Typ w\u00e4hlen','warning');return;}
    var parts=typeVal.split(':');var type=parts[0]==='a'?'assignment':'absence';var value=parts[1];
    if(value==='gesperrt')type='locked';
    var skipHol=document.getElementById('recurSkipHol');var holDates=(typeof HOLIDAYS!=='undefined')?HOLIDAYS:[];
    var changes=[];var d=new Date(from+'T12:00:00');var end=new Date(to+'T12:00:00');
    while(d<=end){var jsDay=d.getDay();if(jsDay>=1&&jsDay<=5&&selectedDays.indexOf(jsDay)!==-1){var dateStr=localDateStr(d);var isHol=skipHol&&skipHol.checked&&holDates.indexOf(dateStr)!==-1;if(!isHol){selectedUsers.forEach(function(uid){changes.push({user_id:uid,date:dateStr,type:type,value:value});});}}d.setDate(d.getDate()+1);}
    if(changes.length===0){toast('Keine passenden Tage','warning');return;}
    /* Konfliktprüfung + interaktive Auflösung */
    var _rt=document.getElementById('recurType');
    var newLabel=(_rt&&_rt.selectedOptions&&_rt.selectedOptions[0])?_rt.selectedOptions[0].text:'';
    planWithConflictCheck(changes, {closeModal:'recurMod', newLabel:newLabel});
}

/* ============================================================
   KONFLIKTPRÜFUNG & INTERAKTIVE AUFLÖSUNG (Mehrfachplanung)
   ============================================================ */
function planWithConflictCheck(changes, ctx){
    ctx = ctx || {};
    if(!changes || !changes.length){toast('Keine Tage zum Planen','warning');return;}
    toast('Prüfe Konflikte...','info');
    fetch(getApiBase()+'api/save_bulk',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({changes:changes,check_conflicts:1})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success){toast('Fehler bei der Konfliktprüfung','error');return;}
        var ow=d.overwrites||[], vac=d.vac_locked||[], sick=d.sick_locked||[];
        if(ow.length===0 && vac.length===0 && sick.length===0){
            var skipNote=d.skipped>0?(' ('+d.skipped+' Tag(e) übersprungen)'):'';
            epConfirm(changes.length+' Eintrag/Einträge anlegen'+skipNote+'?', function(){
                _applyPlan(changes, changes, {}, ctx);
            });
            return;
        }
        showConflictResolver(changes, d, ctx);
    }).catch(function(e){toast(e.message||'Verbindungsfehler','error');});
}

function _applyPlan(allChanges, finalChanges, forceOpts, ctx){
    if(!finalChanges.length){toast('Es wurde nichts ausgewählt','warning');return;}
    saveBulk(finalChanges, true, forceOpts);
    cMod('conflictMod');
    if(ctx && ctx.closeModal) cMod(ctx.closeModal);
    setTimeout(function(){location.reload();}, 1400);
}

function showConflictResolver(allChanges, d, ctx){
    ctx = ctx || {};
    var canUnlock=!!d.can_unlock;
    var newLabel=ctx.newLabel||'neuer Eintrag';
    var items=[];
    (d.overwrites||[]).forEach(function(o){items.push({kind:'ow',uid:o.user_id,iso:o.iso,user:o.user,dateDisp:o.date,current:o.current,locked:false,defChecked:true});});
    (d.vac_locked||[]).forEach(function(o){items.push({kind:'vac',uid:o.user_id,iso:o.iso,user:o.user,dateDisp:o.date,current:'Genehmigter Urlaub',locked:!canUnlock,defChecked:false});});
    (d.sick_locked||[]).forEach(function(o){items.push({kind:'sick',uid:o.user_id,iso:o.iso,user:o.user,dateDisp:o.date,current:o.type||'Krank',locked:!canUnlock,defChecked:false});});
    var groups={}, order=[];
    items.forEach(function(it){ if(!groups[it.uid]){groups[it.uid]={user:it.user,rows:[]};order.push(it.uid);} groups[it.uid].rows.push(it); });

    var bg=document.getElementById('conflictMod'); if(bg) bg.remove();
    bg=document.createElement('div'); bg.className='modal-bg'; bg.id='conflictMod';
    var H='<div class="modal modal-lg"><div class="mh"><h3><svg viewBox="0 0 20 20" fill="currentColor" style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;color:#d97706"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>Konflikte – Auswahl treffen</h3><button class="mx" onclick="cMod(\'conflictMod\')">&times;</button></div>';
    H+='<div class="mb">';
    H+='<p class="cfx-intro">Neuer Eintrag: <b>'+esc(newLabel)+'</b>. Wähle pro Mitarbeiter, welche bestehenden Einträge <b>überschrieben</b> werden. Abgewählte bleiben unverändert.</p>';
    H+='<div class="cfx-bulk"><button type="button" class="btn btn-xs btn-outline" onclick="cfxAll(true)">Alle überschreiben</button><button type="button" class="btn btn-xs btn-outline" onclick="cfxAll(false)">Keine überschreiben</button></div>';
    H+='<div class="cfx-list">';
    order.forEach(function(uid){
        var g=groups[uid];
        H+='<div class="cfx-group" data-uid="'+uid+'"><div class="cfx-group-head"><label class="cfx-emp"><input type="checkbox" class="cfx-emp-cb" data-uid="'+uid+'" onchange="cfxToggleEmp('+uid+',this.checked)"> <b>'+esc(g.user)+'</b></label><span class="cfx-emp-cnt">'+g.rows.length+' Konflikt(e)</span></div>';
        g.rows.forEach(function(it){
            var kindBadge=it.kind==='vac'?'<span class="cfx-tag cfx-tag-vac">Urlaub</span>':(it.kind==='sick'?'<span class="cfx-tag cfx-tag-sick">Krank</span>':'');
            var dis=it.locked?' disabled':'';
            var lockNote=it.locked?'<span class="cfx-lock">gesperrt</span>':'';
            H+='<label class="cfx-row'+(it.locked?' cfx-row-locked':'')+'"><input type="checkbox" class="cfx-cb" data-uid="'+it.uid+'" data-iso="'+it.iso+'" data-kind="'+it.kind+'"'+((it.defChecked&&!it.locked)?' checked':'')+dis+' onchange="cfxUpdate()"><span class="cfx-date">'+esc(it.dateDisp)+'</span><span class="cfx-cur">'+esc(it.current)+'</span> '+kindBadge+'<span class="cfx-arrow">→</span><span class="cfx-new">'+esc(newLabel)+'</span>'+lockNote+'</label>';
        });
        H+='</div>';
    });
    H+='</div>';
    if(d.skipped>0) H+='<p class="cfx-skip">'+d.skipped+' Tag(e) werden automatisch übersprungen (Feiertage / außerhalb Beschäftigung).</p>';
    if(!canUnlock && ((d.vac_locked&&d.vac_locked.length)||(d.sick_locked&&d.sick_locked.length))) H+='<p class="cfx-skip">Urlaubs-/Kranktage lassen sich ohne Berechtigung nicht überschreiben und bleiben erhalten.</p>';
    H+='</div><div class="mf"><span class="cfx-count" id="cfxCount"></span><button class="btn btn-outline" onclick="cMod(\'conflictMod\')">Abbrechen</button><button class="btn btn-primary" id="cfxApply">Auswahl anlegen</button></div></div>';
    bg.innerHTML=H;
    document.body.appendChild(bg);
    bg._allChanges=allChanges; bg._ctx=ctx;
    bg.classList.add('open');
    document.getElementById('cfxApply').onclick=function(){cfxApply();};
    cfxUpdate();
}

function cfxAll(state){
    document.querySelectorAll('#conflictMod .cfx-cb:not([disabled])').forEach(function(cb){cb.checked=state;});
    cfxUpdate();
}
function cfxToggleEmp(uid,state){
    document.querySelectorAll('#conflictMod .cfx-cb[data-uid="'+uid+'"]:not([disabled])').forEach(function(cb){cb.checked=state;});
    cfxUpdate();
}
function cfxUpdate(){
    var bg=document.getElementById('conflictMod'); if(!bg)return;
    var all=bg._allChanges||[];
    var conflictKeys={}, checkedKeys={};
    document.querySelectorAll('#conflictMod .cfx-cb').forEach(function(cb){
        var k=cb.dataset.uid+'_'+cb.dataset.iso; conflictKeys[k]=true; if(cb.checked)checkedKeys[k]=true;
    });
    var final=all.filter(function(c){var k=c.user_id+'_'+c.date; if(conflictKeys[k])return !!checkedKeys[k]; return true;});
    var ow=document.querySelectorAll('#conflictMod .cfx-cb:checked').length;
    var el=document.getElementById('cfxCount');
    if(el) el.textContent=final.length+' Eintrag/Einträge · '+ow+' Überschreibung(en)';
    document.querySelectorAll('#conflictMod .cfx-emp-cb').forEach(function(ecb){
        var uid=ecb.dataset.uid;
        var rows=document.querySelectorAll('#conflictMod .cfx-cb[data-uid="'+uid+'"]:not([disabled])');
        var checked=document.querySelectorAll('#conflictMod .cfx-cb[data-uid="'+uid+'"]:checked:not([disabled])');
        ecb.checked = rows.length>0 && checked.length===rows.length;
        ecb.indeterminate = checked.length>0 && checked.length<rows.length;
    });
}
function cfxApply(){
    var bg=document.getElementById('conflictMod'); if(!bg)return;
    var all=bg._allChanges||[], ctx=bg._ctx||{};
    var conflictKeys={}, checkedKeys={}, forceVac=false, forceSick=false;
    document.querySelectorAll('#conflictMod .cfx-cb').forEach(function(cb){
        var k=cb.dataset.uid+'_'+cb.dataset.iso; conflictKeys[k]=true;
        if(cb.checked){checkedKeys[k]=true; if(cb.dataset.kind==='vac')forceVac=true; if(cb.dataset.kind==='sick')forceSick=true;}
    });
    var final=all.filter(function(c){var k=c.user_id+'_'+c.date; if(conflictKeys[k])return !!checkedKeys[k]; return true;});
    var opts={}; if(forceVac)opts.force_vacation=1; if(forceSick)opts.force_sick=1;
    _applyPlan(all, final, opts, ctx);
}

/* Dauereinsatz Stepper */
var recurCurrentStep=1;
function recurStep(dir){
    var next=recurCurrentStep+dir;
    if(next<1||next>3)return;
    /* Validate before advancing */
    if(dir>0&&recurCurrentStep===1){
        var cnt=document.querySelectorAll('.recurUserCb:checked').length;
        if(cnt===0){toast('Bitte mindestens einen Mitarbeiter ausw\u00e4hlen','warning');return;}
    }
    if(dir>0&&recurCurrentStep===2){
        var from=document.getElementById('recurFrom').value,to=document.getElementById('recurTo').value;
        if(!from||!to){toast('Bitte Zeitraum angeben','warning');return;}
        var days=document.querySelectorAll('.recurDay:checked').length;
        if(days===0){toast('Bitte mindestens einen Wochentag w\u00e4hlen','warning');return;}
    }
    recurCurrentStep=next;
    for(var i=1;i<=3;i++){
        var panel=document.getElementById('rp'+i);if(panel)panel.style.display=(i===next)?'':'none';
        var step=document.getElementById('rs'+i);if(step){step.className='recur-step';if(i<next)step.className+=' done';if(i===next)step.className+=' active';}
    }
    document.getElementById('recurPrevBtn').style.display=(next>1)?'':'none';
    document.getElementById('recurNextBtn').style.display=(next<3)?'':'none';
    document.getElementById('recurSaveBtn').style.display=(next===3)?'':'none';
    if(next===2)updateRecurPreview();
    if(next===3)updateRecurSummary();
}
function updateRecurPreview(){
    var from=document.getElementById('recurFrom').value,to=document.getElementById('recurTo').value;
    var days=[];document.querySelectorAll('.recurDay:checked').forEach(function(c){days.push(['','Mo','Di','Mi','Do','Fr'][parseInt(c.value)]);});
    var skip=document.getElementById('recurSkipHol').checked;
    var el=document.getElementById('recurPreview');
    if(el)el.innerHTML='<div class="muted" style="font-size:.78rem;margin-top:.5rem">'+from.split('-').reverse().join('.')+' bis '+to.split('-').reverse().join('.')+' | '+days.join(', ')+(skip?' | Feiertage überspringen':'')+'</div>';
}
function updateRecurSummary(){
    var users=document.querySelectorAll('.recurUserCb:checked').length;
    var from=document.getElementById('recurFrom').value,to=document.getElementById('recurTo').value;
    var days=[];document.querySelectorAll('.recurDay:checked').forEach(function(c){days.push(['','Mo','Di','Mi','Do','Fr'][parseInt(c.value)]);});
    var el=document.getElementById('recurSummary');
    if(el)el.innerHTML='<div class="recur-summary-box"><div class="recur-summary-row"><span class="muted">Mitarbeiter:</span> <b>'+users+'</b></div><div class="recur-summary-row"><span class="muted">Zeitraum:</span> <b>'+from.split('-').reverse().join('.')+' – '+to.split('-').reverse().join('.')+'</b></div><div class="recur-summary-row"><span class="muted">Wochentage:</span> <b>'+days.join(', ')+'</b></div></div>';
}
function recurSelectAll(checked){
    document.querySelectorAll('.user-pick-item').forEach(function(it){
        if(it.style.display==='none')return;
        var cb=it.querySelector('.recurUserCb');if(cb){cb.checked=checked;if(checked)it.classList.add('checked');else it.classList.remove('checked');}
    });
    document.getElementById('recurUserCnt').textContent=document.querySelectorAll('.recurUserCb:checked').length;
}
function recurSelectRole(role){
    recurSelectAll(false);
    document.querySelectorAll('.user-pick-item').forEach(function(it){
        var r=it.getAttribute('data-role')||'';
        var match=false;
        if(role==='techniker')match=(r==='mitarbeiter'||r==='manager'||r==='teamleiter');
        else if(role==='azubi')match=(r.indexOf('azubi')!==-1);
        else if(role==='azubi_1')match=(r==='azubi_1');
        else if(role==='azubi_2')match=(r==='azubi_2');
        else if(role==='azubi_3')match=(r==='azubi_3');
        else match=(r===role);
        if(match){var cb=it.querySelector('.recurUserCb');if(cb){cb.checked=true;it.classList.add('checked');}}
    });
    document.getElementById('recurUserCnt').textContent=document.querySelectorAll('.recurUserCb:checked').length;
}

/* ============================================================ YEAR PLANNING ============================================================ */
function saveYearPlan(year){epConfirm('Einsatz f\u00fcr ALLE Werktage '+year+' setzen?',function(){var uid=document.getElementById('yrUser').value;var aid=document.getElementById('yrArea').value;var changes=[];var d=new Date(year,0,1,12,0,0);var end=new Date(year,11,31,12,0,0);while(d<=end){var dow=d.getDay();if(dow>=1&&dow<=5){changes.push({user_id:parseInt(uid),date:localDateStr(d),type:'assignment',value:aid});}d.setDate(d.getDate()+1);}toast('Sende '+changes.length+' Eintr\u00e4ge...','info');saveBulk(changes,true);cMod('yearMod');setTimeout(function(){location.reload();},2000);});}

/* ============================================================ UTILITY ============================================================ */
function getApiBase(){var p=window.location.pathname;if(p.indexOf('/pages/')!==-1||p.indexOf('/api/')!==-1)return '../';return '';}
function currentUserId(){return parseInt(document.body.dataset.uid||'0');}
/* HTML-Escaping für Text UND Attributwerte (Befund S-05: die frühere Variante
   über textNode/innerHTML maskierte keine Anführungszeichen). */
function esc(s){if(!s&&s!==0)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
/* Nur http(s)-Links zulassen (WOT-Links u. a.) */
function safeUrl(u){u=String(u||'').trim();return /^https?:\/\//i.test(u)?u:'';}
/* Nur Hex-Farben in style-Attributen */
function safeColor(c){c=String(c||'').trim();return /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(c)?c:'#94a3b8';}
function rgbToHex(rgb){if(!rgb)return '#3b82f6';if(rgb.charAt(0)==='#')return rgb;var m=rgb.match(/\d+/g);if(!m||m.length<3)return '#3b82f6';return '#'+m.slice(0,3).map(function(x){var h=parseInt(x).toString(16);return h.length===1?'0'+h:h;}).join('');}

/* ============================================================ PASSWORD CHANGE ============================================================ */
function changePassword(){var old=document.getElementById('pwOld').value;var pw=document.getElementById('pwNew').value;var pw2=document.getElementById('pwNew2').value;if(!old||!pw||!pw2){toast('Alle Felder ausf\u00fcllen','warning');return;}if(pw.length<6){toast('Mindestens 6 Zeichen','warning');return;}if(pw!==pw2){toast('Passw\u00f6rter stimmen nicht \u00fcberein','warning');return;}fetch(getApiBase()+'api/change_password',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({old_password:old,new_password:pw,new_password2:pw2})}).then(function(r){return r.json();}).then(function(d){if(d.success){toast(d.msg,'success');cMod('pwMod');document.getElementById('pwOld').value='';document.getElementById('pwNew').value='';document.getElementById('pwNew2').value='';}else toast(d.error||'Fehler','error');}).catch(function(e){toast(e.message,'error');});}
document.addEventListener('click',function(e){var menu=document.getElementById('userMenu');if(menu&&!menu.contains(e.target))menu.classList.remove('open');});

/* ============================================================
   LIVE EDITING STATUS – real-time collaboration
   ============================================================ */
var liveInterval = null;
var lastRefreshHash = '';
var isEditLocked = false;
var liveActive = true;
var pollSpeed = 2500; /* Base poll interval ms */

function startLivePolling() {
    if (typeof PY === 'undefined' || typeof PM === 'undefined') return;
    refreshTableData();
    liveInterval = setInterval(function(){
        if(liveActive) refreshTableData();
    }, pollSpeed);
    /* Faster polling when tab is focused */
    document.addEventListener('visibilitychange', function(){
        liveActive = !document.hidden;
        if(liveActive) refreshTableData(); /* Immediate refresh on tab focus */
    });
    window.addEventListener('beforeunload', function(e) {
        /* Nur die Präsenz-Abmeldung senden – KEINE Browser-Meldung mehr.
           Hinweise auf unveröffentlichte Änderungen laufen ausschließlich über
           den app-eigenen Dialog (leaveMod), der beim Klick auf echte
           Navigations-Links erscheint und nur bei akuten Änderungen greift.
           Das Durchschalten der Monate in der Planung löst bewusst KEINE
           Warnung aus. */
        navigator.sendBeacon(getApiBase()+'api/live_status', JSON.stringify({action:'leave', page:'monatsplanung', year:PY, month:PM}));
    });
}

function refreshTableData() {
    if (typeof PY === 'undefined' || typeof PM === 'undefined') return;
    fetch(getApiBase()+'api/live_status', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'ping', page:'monatsplanung', year:PY, month:PM})
    }).then(function(r){return r.json();}).then(function(d){
        if (!d.success) return;
        /* Update editors display */
        var el = document.getElementById('liveEditors');
        if (el) {
            if (d.editors && d.editors.length > 0) {
                var names = d.editors.map(function(e){return e.first_name+' '+e.last_name;}).join(', ');
                el.innerHTML = '<span class="live-dot"></span>' + esc(names) + ' bearbeitet';
                el.style.display = '';
            } else { el.style.display = 'none'; }
        }
        /* Row-level locks */
        if (typeof updateRowLocks === 'function') {
            updateRowLocks(d.editors_detail || d.editors || []);
        }
        /* Concurrent editing lock */
        var lockBanner = document.getElementById('lockBanner');
        if (d.allow_concurrent === false && d.editors && d.editors.length > 0 && d.first_editor && d.first_editor != currentUserId()) {
            isEditLocked = true;
            if (lockBanner) { lockBanner.style.display = ''; lockBanner.querySelector('span').textContent = 'Gleichzeitige Bearbeitung ist gesperrt. ' + d.editors[0].first_name + ' ' + d.editors[0].last_name + ' bearbeitet gerade.'; }
        } else {
            isEditLocked = false;
            if (lockBanner) lockBanner.style.display = 'none';
        }
        /* Update draft count badge – separate my vs other */
        var ub = document.getElementById('unpubBadge');
        var ubOther = document.getElementById('unpubBadgeOther');
        if(ub){
            if(d.my_drafts>0){
                ub.style.display='';
                ub.textContent='Meine: '+d.my_drafts+' \u00c4nderung'+(d.my_drafts!==1?'en':'');
                window._planHasDrafts=true;
            } else { ub.style.display='none'; window._planHasDrafts=false; }
        }
        if(ubOther){
            if(d.other_drafts>0){
                ubOther.style.display='';
                ubOther.textContent='Andere: '+d.other_drafts+' \u00c4nderung'+(d.other_drafts!==1?'en':'');
            } else { ubOther.style.display='none'; }
        }
        /* Show/hide other-drafts info banner */
        var odBanner = document.getElementById('otherDraftsBanner');
        var odText = document.getElementById('otherDraftsText');
        if(odBanner && odText){
            if(d.other_drafts>0){
                odBanner.style.display='';
                odText.textContent='Es gibt '+d.other_drafts+' unver\u00f6ffentlichte \u00c4nderung'+(d.other_drafts!==1?'en':'')+' von anderen Benutzern. Diese sind im Plan sichtbar, aber noch nicht ver\u00f6ffentlicht.';
            } else { odBanner.style.display='none'; }
        }
        /* Live cell refresh if data hash changed (another user made changes) */
        if (d.data_hash && lastRefreshHash && d.data_hash !== lastRefreshHash) {
            liveCellRefresh(true); /* true = animate changed cells */
        }
        if (d.data_hash) lastRefreshHash = d.data_hash;
    }).catch(function(){});
}

function liveCellRefresh(animate) {
    if (typeof PY === 'undefined' || typeof PM === 'undefined') return;
    fetch(getApiBase()+'api/live_status', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'cells', year:PY, month:PM})
    }).then(function(r){return r.json();}).then(function(d){
        if (!d.success || !d.cells) return;
        var changed = 0;
        document.querySelectorAll('.pe-c').forEach(function(c) {
            if (c.classList.contains('cell-unavailable')) return;
            var key = c.dataset.u + '_' + c.dataset.d;
            var cell = d.cells[key];
            var oldType = c.dataset.t || '';
            var oldVal = c.dataset.v || '';
            var oldRemark = c.dataset.remark || '';
            if (!cell) {
                /* Server says cell is empty */
                if (oldType) {
                    c.style.removeProperty('background'); c.style.color = ''; c.textContent = '';
                    c.dataset.t = ''; c.dataset.v = '';
                    c.classList.remove('has-remark','has-pub-remark','has-int-remark','cell-locked','cell-internal','draft-mine','draft-other');
                    c.dataset.draftBy = '';
                    if(animate) flashCell(c);
                    changed++;
                }
                /* Still check for draft on empty cell */
                var emptyKey = c.dataset.u + '_' + c.dataset.d;
                var emptyDraft = d.drafts ? d.drafts[emptyKey] : null;
                if (emptyDraft) {
                    if (d.my_uid && emptyDraft.by_id === d.my_uid) { c.classList.add('draft-mine'); c.classList.remove('draft-other'); }
                    else { c.classList.add('draft-other'); c.classList.remove('draft-mine'); c.dataset.draftBy = emptyDraft.by || ''; }
                } else { c.classList.remove('draft-mine','draft-other'); c.dataset.draftBy = ''; }
                return;
            }
            var newType = cell.type || '';
            var newVal = String(cell.value || '');
            var newRemark = cell.remark || '';
            /* Compare by type+value+remark – NOT by style strings (browser converts hex to rgb) */
            var contentChanged = (oldType !== newType || oldVal !== newVal);
            var remarkChanged = (oldRemark !== newRemark);
            if(contentChanged) {
                if (cell.bg && c.dataset.we === '1') c.style.setProperty('background', cell.bg, 'important');
                else { c.style.removeProperty('background'); if (cell.bg) c.style.background = cell.bg; }
                c.style.color = cell.fg || '';
                c.textContent = cell.label || '';
                c.dataset.t = newType; c.dataset.v = newVal;
                if(animate) flashCell(c);
                changed++;
            }
            /* Sync remark metadata */
            if (newRemark) { c.dataset.remark = newRemark; c.classList.add('has-pub-remark'); }
            else { c.dataset.remark = ''; c.classList.remove('has-pub-remark'); }
            if (cell.int_remark) { c.dataset.intRemark = cell.int_remark; c.classList.add('has-int-remark'); }
            else { c.dataset.intRemark = ''; c.classList.remove('has-int-remark'); }
            /* has-remark if either public or internal */
            if (newRemark || cell.int_remark) { c.classList.add('has-remark'); } else { c.classList.remove('has-remark'); }
            if (cell.remark_by_name) c.dataset.remarkBy = cell.remark_by_name; else c.dataset.remarkBy = '';
            if (cell.remark_at) c.dataset.remarkAt = cell.remark_at; else c.dataset.remarkAt = '';
            if (newVal === 'gesperrt') c.classList.add('cell-locked'); else c.classList.remove('cell-locked');
            if (cell.internal) c.classList.add('cell-internal'); else c.classList.remove('cell-internal');
            /* Sync draft indicators */
            var draftKey = c.dataset.u + '_' + c.dataset.d;
            var draftCell = d.drafts ? d.drafts[draftKey] : null;
            if (draftCell) {
                if (d.my_uid && draftCell.by_id === d.my_uid) {
                    c.classList.add('draft-mine'); c.classList.remove('draft-other');
                    c.dataset.draftBy = '';
                } else {
                    c.classList.add('draft-other'); c.classList.remove('draft-mine');
                    c.dataset.draftBy = draftCell.by || '';
                }
            } else {
                c.classList.remove('draft-mine', 'draft-other');
                c.dataset.draftBy = '';
            }
        });
        if(d.data_hash) lastRefreshHash = d.data_hash;
        if(changed > 0) {
            var indicator = document.getElementById('liveUpdateHint');
            if (indicator) { indicator.textContent = changed+' Zelle(n) aktualisiert'; indicator.style.display = ''; setTimeout(function(){ if(indicator) indicator.style.display='none'; }, 3000); }
        }
    }).catch(function(){});
}

/* Flash animation on changed cells */
function flashCell(cell) {
    cell.classList.add('cell-flash');
    setTimeout(function(){ cell.classList.remove('cell-flash'); }, 1200);
}
document.addEventListener('DOMContentLoaded', function() {
    startLivePolling();
    colorizeAreaSelect();
    restoreFilterState();
    initCustomDropdowns();
});

/* ============================================================
   COLORIZE AREA SELECT OPTIONS
   ============================================================ */
function colorizeAreaSelect() {
    var sel = document.getElementById('toolArea');
    if (!sel) return;
    function updateSelectColor() {
        var opt = sel.options[sel.selectedIndex];
        if (opt && opt.dataset.c) {
            sel.style.borderLeft = '4px solid ' + opt.dataset.c;
        } else {
            sel.style.borderLeft = '';
        }
    }
    sel.addEventListener('change', updateSelectColor);
    updateSelectColor();
}

/* ============================================================
   SEARCHABLE USER PICKER (Dauereinsatz)
   ============================================================ */
function filterUserPick(inp) {
    var q = (inp.value || '').toLowerCase().trim();
    var roleFilter = '';
    var roleEl = document.getElementById('recurRoleFilter');
    if (roleEl) roleFilter = roleEl.value;
    /* Find items in the user-pick container */
    var pick = document.getElementById('recurUserPick');
    if (!pick) pick = inp.closest('.user-pick') || inp.parentElement;
    var items = pick.querySelectorAll('.user-pick-item');
    items.forEach(function(it) {
        var text = it.textContent.toLowerCase();
        var role = it.getAttribute('data-role') || '';
        var showName = (!q || text.indexOf(q) !== -1);
        var showRole = true;
        if (roleFilter) {
            if (roleFilter === 'azubi') showRole = (role.indexOf('azubi') !== -1);
            else if (roleFilter === 'azubi_1') showRole = (role === 'azubi_1');
            else if (roleFilter === 'azubi_2') showRole = (role === 'azubi_2');
            else if (roleFilter === 'azubi_3') showRole = (role === 'azubi_3');
            else if (roleFilter === 'techniker') showRole = (role === 'mitarbeiter' || role === 'manager' || role === 'teamleiter');
            else showRole = (role === roleFilter);
        }
        it.style.display = (showName && showRole) ? '' : 'none';
    });
}
window.filterRecurRole = function() {
    var inp = document.getElementById('recurSearch');
    if (inp) filterUserPick(inp);
};
document.addEventListener('DOMContentLoaded', function() {
    /* Count selected users in Dauereinsatz */
    document.querySelectorAll('.recurUserCb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var cnt = document.querySelectorAll('.recurUserCb:checked').length;
            var el = document.getElementById('recurUserCnt');
            if (el) el.textContent = cnt;
            var item = cb.closest('.user-pick-item');
            if (item) { if (cb.checked) item.classList.add('checked'); else item.classList.remove('checked'); }
        });
    });
});

/* ============================================================
   CUSTOM DROPDOWN (Eigenentwicklung)
   ============================================================ */
function initCustomDropdowns() {
    document.querySelectorAll('[data-cdrop]').forEach(function(el) {
        var btn = el.querySelector('.cdrop-btn');
        var list = el.querySelector('.cdrop-list');
        var items = el.querySelectorAll('.cdrop-item');
        var search = el.querySelector('.cdrop-search');
        var hiddenInput = document.getElementById(el.dataset.cdrop);
        if (!btn || !list) return;

        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            /* Close all other dropdowns */
            document.querySelectorAll('.cdrop.open').forEach(function(o) { if (o !== el) o.classList.remove('open'); });
            el.classList.toggle('open');
            if (search && el.classList.contains('open')) search.focus();
        });

        items.forEach(function(item) {
            item.addEventListener('click', function() {
                var val = item.dataset.value || '';
                var label = item.textContent.trim();
                btn.querySelector('span').textContent = label;
                items.forEach(function(i) { i.classList.remove('active'); });
                item.classList.add('active');
                if (hiddenInput) { hiddenInput.value = val; hiddenInput.dispatchEvent(new Event('change')); }
                el.classList.remove('open');
            });
        });

        if (search) {
            search.addEventListener('input', function() {
                var q = search.value.toLowerCase();
                items.forEach(function(item) {
                    item.style.display = item.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
                });
            });
            search.addEventListener('click', function(e) { e.stopPropagation(); });
        }
    });
    /* Close all dropdowns on outside click */
    document.addEventListener('click', function() {
        document.querySelectorAll('.cdrop.open').forEach(function(o) { o.classList.remove('open'); });
    });
}

/* ============================================================
   PRINT FILTER INFO
   ============================================================ */
function updatePrintFilter() {
    var pf = document.getElementById('printFilter');
    if (!pf) return;
    var parts = [];
    var fn = (document.getElementById('filterName')||{}).value;
    var fr = (document.getElementById('filterRole')||{});
    var fa = (document.getElementById('filterArea')||{});
    if (fn) parts.push('Suche: ' + fn);
    if (fr.value) parts.push('Rolle: ' + fr.options[fr.selectedIndex].text);
    if (fa.value) parts.push('Bereich: ' + fa.options[fa.selectedIndex].text);
    pf.textContent = parts.length ? 'Filter: ' + parts.join(' | ') : '';
}

/* ============================================================
   FILTER: Leere Gruppen-Überschriften ausblenden
   ============================================================ */
function hideEmptyGroups() {
    var rows = document.querySelectorAll('#planTbl tbody tr');
    var lastGr = null;
    var grHasVisible = false;
    rows.forEach(function(r) {
        if (r.classList.contains('gr')) {
            if (lastGr && !grHasVisible) lastGr.classList.add('gr-hidden');
            else if (lastGr) lastGr.classList.remove('gr-hidden');
            lastGr = r; grHasVisible = false;
        } else if (r.classList.contains('plan-row')) {
            if (r.style.display !== 'none') grHasVisible = true;
        }
    });
    if (lastGr && !grHasVisible) lastGr.classList.add('gr-hidden');
    else if (lastGr) lastGr.classList.remove('gr-hidden');
}
/* Patch applyFilters to call hideEmptyGroups */
var _origApplyFilters = typeof applyFilters === 'function' ? applyFilters : null;
if (_origApplyFilters) {
    applyFilters = function() {
        _origApplyFilters();
        hideEmptyGroups();
    };
}

/* ============================================================
   PUBLISH YEAR
   ============================================================ */
function publishYear(y){if(epBlockedOffline())return;
    epConfirm('Gesamtes Jahr '+y+' veröffentlichen? Dies betrifft alle 12 Monate.',function(){
        fetch(getApiBase()+'api/publish',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({publish_year:y})})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){toast(d.msg,'success');setTimeout(function(){location.reload();},1500);}
            else toast('Fehler: '+(d.error||''),'error');
        }).catch(function(e){toast(e.message,'error');});
    });
}

/* ============================================================
   PLANUNGSTABELLE: Schatten der fixierten Spalten beim seitlichen Scrollen
   ============================================================ */
(function(){
    function initStickyShadow(ps){
        if(!ps || ps._stickyInit) return; ps._stickyInit = true;
        var update = function(){
            if(ps.scrollLeft > 1) ps.classList.add('scrolled-x');
            else ps.classList.remove('scrolled-x');
        };
        ps.addEventListener('scroll', update, {passive:true});
        update();
    }
    function initAll(){ document.querySelectorAll('.ps').forEach(initStickyShadow); }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
    else initAll();
    /* falls die Tabelle dynamisch nachgeladen/aktualisiert wird */
    window.addEventListener('load', initAll);
})();
