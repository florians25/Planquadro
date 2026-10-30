/**
 * Planquadro – Dashboard live
 * ---------------------------------------------------------------------------
 * Das Dashboard aktualisiert sich selbst, ohne Neuladen der Seite:
 * - sofort bei Live-Ereignissen (api/events: Plan geändert, veröffentlicht, Antrag),
 * - zusätzlich regelmäßig (60 s ohne Live-Verbindung, sonst alle 5 min),
 * - beim Zurückkehren in den Tab, wenn der Stand älter als 30 s ist,
 * - über den Knopf ↻ neben „Stand hh:mm“.
 * Neu geladen wird die Seite selbst (gleiche Rechte wie beim Aufruf); ausgetauscht werden
 * nur die Inhaltsbereiche. Ankündigungen und Antragszahlen kommen wie bisher aus der API.
 */
(function () {
    'use strict';
    var root = document.querySelector('.pq-dash');
    if (!root) return;

    var POLL_FALLBACK = 60 * 1000, POLL_LIVE = 5 * 60 * 1000, STALE = 30 * 1000;
    var state = { last: Date.now(), busy: false, again: false, timer: null, debounce: null, live: false, es: null, fails: 0, stopped: false };
    var day = new Date().toDateString();
    var serverHtml = {};
    var extras = { ann: null, appr: null };
    var box = document.getElementById('pqDashLive');
    var stamp = document.getElementById('pqDashStamp');
    var btn = document.getElementById('pqDashRefresh');
    var sr = document.createElement('div');
    sr.className = 'sr-only'; sr.setAttribute('role', 'status'); sr.setAttribute('aria-live', 'polite');
    document.body.appendChild(sr);

    function apiBase() {
        if (typeof getApiBase === 'function') return getApiBase();
        var p = location.pathname;
        return (p.indexOf('/pages/') !== -1 || p.indexOf('/api/') !== -1) ? '../' : '';
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function hhmm(d) { return (d.getHours() < 10 ? '0' : '') + d.getHours() + ':' + (d.getMinutes() < 10 ? '0' : '') + d.getMinutes(); }
    function setMode(mode, text) {
        if (!box) return;
        box.setAttribute('data-mode', mode);
        if (text && stamp) stamp.textContent = text;
        box.title = mode === 'live' ? 'Live: Änderungen erscheinen sofort.'
            : mode === 'off' ? 'Keine Verbindung – der Stand wird nicht aktualisiert.'
            : 'Aktualisiert sich automatisch jede Minute.';
    }

    /* ---------- Ankündigungen und Anträge (API) ---------- */
    var annColors = { info: ['#e0f2fe', '#0369a1', '#bae6fd'], warning: ['#fef3c7', '#92400e', '#fde68a'], critical: ['#fef2f2', '#991b1b', '#fecaca'] };
    var annIcons = {
        info: 'M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z',
        warning: 'M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z',
        critical: 'M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z'
    };
    function post(api, body) {
        return fetch(apiBase() + 'api/' + api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function paintExtras() {
        var el = document.getElementById('dashAnnouncements');
        if (el && extras.ann !== null) el.innerHTML = extras.ann;
        var card = document.getElementById('dashApprovalCard'), grid = document.getElementById('dashApprovalGrid');
        if (card && grid && extras.appr !== null) { grid.innerHTML = extras.appr; card.style.display = extras.appr ? '' : 'none'; }
    }
    function loadExtras() {
        if (document.getElementById('dashAnnouncements')) {
            post('announcements', { action: 'active' }).then(function (d) {
                if (!d || !d.success) return;
                var h = '';
                (d.items || []).forEach(function (a) {
                    var c = annColors[a.priority] || annColors.info, ic = annIcons[a.priority] || annIcons.info;
                    h += '<div class="ann-card" style="background:' + c[0] + ';border:1px solid ' + c[2] + ';color:' + c[1] + '">' +
                        '<svg viewBox="0 0 20 20" fill="currentColor" style="width:18px;height:18px;flex-shrink:0"><path fill-rule="evenodd" d="' + ic + '" clip-rule="evenodd"/></svg>' +
                        '<div><b>' + esc(a.title) + '</b>' + (a.content ? '<div style="font-size:.78rem;margin-top:.15rem;opacity:.85">' + esc(a.content) + '</div>' : '') + '</div></div>';
                });
                extras.ann = h; paintExtras();
            }).catch(function () {});
        }
        if (document.getElementById('dashApprovalCard')) {
            post('change_requests', { action: 'dashboard_stats' }).then(function (d) {
                if (!d || !d.success) return;
                var h = '';
                function item(n, color, label) {
                    return '<div class="dash-approval-item"><div class="dash-approval-num" style="color:' + color + '">' + (+n) + '</div><div class="dash-approval-label">' + label + '</div></div>';
                }
                if (d.my_pending > 0) h += item(d.my_pending, 'var(--wn)', 'Meine offenen');
                if (d.my_approved_week > 0) h += item(d.my_approved_week, 'var(--ok)', 'Genehmigt (7d)');
                if (d.my_rejected_week > 0) h += item(d.my_rejected_week, 'var(--err)', 'Abgelehnt (7d)');
                if (d.to_approve > 0) h += item(d.to_approve, 'var(--p)', 'Zu genehmigen');
                extras.appr = h; paintExtras();
            }).catch(function () {});
        }
    }

    /* ---------- Inhalte austauschen ---------- */
    var PARTS = ['.pq-dash', '.pq-today'];
    PARTS.forEach(function (sel) { var n = document.querySelector(sel); if (n) serverHtml[sel] = n.innerHTML; });

    function swap(doc) {
        var changed = false;
        PARTS.forEach(function (sel) {
            var fresh = doc.querySelector(sel), cur = document.querySelector(sel);
            if (!fresh || !cur || fresh.innerHTML === serverHtml[sel]) return;
            /* Fokus behalten, wenn möglich (gleicher Link/Knopf nach dem Austausch) */
            var a = document.activeElement, key = null;
            if (a && cur.contains(a)) key = a.getAttribute('href') || a.getAttribute('data-pq-publish') || null;
            cur.innerHTML = fresh.innerHTML;
            cur.className = fresh.className;
            serverHtml[sel] = fresh.innerHTML;
            if (key) {
                var again = cur.querySelector('[href="' + key.replace(/"/g, '') + '"],[data-pq-publish="' + key.replace(/"/g, '') + '"]');
                if (again) again.focus();
            }
            changed = true;
        });
        if (changed) {
            paintExtras();
            root = document.querySelector('.pq-dash');
            root.classList.remove('pq-dash-updated'); void root.offsetWidth; root.classList.add('pq-dash-updated');
            sr.textContent = 'Dashboard aktualisiert';
        }
        return changed;
    }

    function refresh() {
        if (state.stopped) return;
        if (state.busy) { state.again = true; return; }
        if (new Date().toDateString() !== day) { location.reload(); return; } /* neuer Tag: alles neu */
        state.busy = true;
        if (box) box.classList.add('is-busy');
        fetch(location.pathname + location.search, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-PQ-Refresh': 'dashboard' } })
            .then(function (r) {
                /* Sitzung abgelaufen → Weiterleitung zur Anmeldung: nicht weiter versuchen */
                if (r.redirected && !/dashboard/.test(r.url)) { state.stopped = true; throw new Error('logout'); }
                if (!r.ok) throw new Error('http ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                swap(doc);
                loadExtras();
                state.last = Date.now();
                setMode(state.live ? 'live' : 'poll', 'Stand ' + hhmm(new Date()));
            })
            .catch(function (e) {
                setMode('off', state.stopped ? 'Abgemeldet' : 'Offline · ' + hhmm(new Date(state.last)));
            })
            .then(function () {
                state.busy = false;
                if (box) box.classList.remove('is-busy');
                schedule();
                if (state.again) { state.again = false; soon(400); }
            });
    }
    function soon(ms) { clearTimeout(state.debounce); state.debounce = setTimeout(refresh, ms); }
    function schedule() {
        clearTimeout(state.timer);
        if (state.stopped) return;
        state.timer = setTimeout(function () {
            if (document.visibilityState === 'visible') refresh(); else schedule();
        }, state.live ? POLL_LIVE : POLL_FALLBACK);
    }

    /* ---------- Live-Ereignisse ---------- */
    function connect() {
        if (typeof EventSource === 'undefined' || state.es) return;
        var es;
        try { es = new EventSource(apiBase() + 'api/events'); } catch (e) { return; }
        state.es = es;
        es.addEventListener('ready', function () { state.live = true; state.fails = 0; setMode('live'); schedule(); });
        ['cells_changed', 'published', 'request_changed', 'reset'].forEach(function (t) {
            es.addEventListener(t, function () { soon(1200); });
        });
        es.onerror = function () {
            state.live = false; setMode('poll');
            if (es.readyState === 2 || ++state.fails > 5) { es.close(); state.es = null; schedule(); }
        };
    }

    if (btn) btn.addEventListener('click', function () { refresh(); });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && Date.now() - state.last > STALE) soon(100);
    });
    window.addEventListener('online', function () { state.stopped = false; soon(100); });

    loadExtras();
    setMode('poll');
    connect();
    schedule();
})();
