/**
 * Planquadro – Scrollen in Plantabellen (.ps: Monatsplanung, Gesamtplan, Mein Plan, Jahr)
 * ---------------------------------------------------------------------------
 * - Die Tabelle passt sich der Fensterhöhe unter Navigation und Werkzeugleiste an:
 *   Kopfzeile (Tage), Namensspalte, Abdeckungszeile und waagerechte Scrollleiste
 *   bleiben immer sichtbar.
 * - Mausrad über der Tabelle schiebt zuerst die Seite, bis die Tabelle unter der
 *   Werkzeugleiste „andockt“; danach scrollt die Tabelle selbst.
 * - Mausrad über der Tageskopfzeile scrollt waagerecht (ohne Umschalttaste).
 * - „Heute“: springt zur heutigen Spalte; beim Öffnen des aktuellen Monats steht
 *   der heutige Tag automatisch im Blick.
 * - Die Scrollposition bleibt beim Neuladen (z. B. nach Veröffentlichen) erhalten.
 * Reine Darstellung, keine Daten; ohne JavaScript verhält sich die Tabelle wie bisher.
 */
(function () {
    'use strict';
    var KEY = 'pq-scroll:';
    var MAX_AGE = 30 * 60 * 1000;
    var tables = [];

    function wide() { return window.innerWidth >= 768; }

    /* Unterkante aller oben fixierten Leisten (Navigation, ggf. Werkzeugleiste) */
    function dockTop() {
        var top = 0;
        var nav = document.querySelector('.nav');
        if (nav) top = nav.getBoundingClientRect().height;
        var tb = document.querySelector('.tb');
        if (tb && getComputedStyle(tb).position === 'sticky' && tb.offsetParent !== null) {
            top = (parseFloat(getComputedStyle(tb).top) || top) + tb.getBoundingClientRect().height;
        }
        return Math.round(top + 8);
    }

    function fit(ps) {
        if (!wide()) { ps.classList.remove('pq-fit'); ps.style.removeProperty('--pq-ps-h'); return; }
        var h = Math.max(320, window.innerHeight - dockTop() - 10);
        ps.style.setProperty('--pq-ps-h', h + 'px');
        ps.classList.add('pq-fit');
    }

    function fitAll() { tables.forEach(fit); }

    /* Breite der links fixierten Spalten (Name, Info …) */
    function stickyWidth(ps) {
        var left = ps.getBoundingClientRect().left, w = 0;
        ps.querySelectorAll('thead tr:first-child .sc').forEach(function (c) {
            w = Math.max(w, c.getBoundingClientRect().right - left);
        });
        return w;
    }

    function todayHead(ps) { return ps.querySelector('thead .td-head, thead th.td'); }

    function scrollToToday(ps, smooth) {
        var th = todayHead(ps);
        if (!th) return false;
        var target = th.offsetLeft - stickyWidth(ps) - th.offsetWidth * 2;
        ps.scrollTo({ left: Math.max(0, target), behavior: smooth ? 'smooth' : 'auto' });
        return true;
    }

    function todayVisible(ps) {
        var th = todayHead(ps);
        if (!th) return true;
        var r = th.getBoundingClientRect(), p = ps.getBoundingClientRect();
        return r.left >= p.left + stickyWidth(ps) && r.right <= p.right;
    }

    function storeKey() { return KEY + location.pathname + location.search; }

    function save() {
        try {
            var data = { t: Date.now(), w: window.scrollY, p: tables.map(function (ps) { return [ps.scrollLeft, ps.scrollTop]; }) };
            sessionStorage.setItem(storeKey(), JSON.stringify(data));
        } catch (e) { /* Speicher gesperrt: ohne Wiederherstellung weiter */ }
    }

    function restore() {
        var data = null;
        try { data = JSON.parse(sessionStorage.getItem(storeKey()) || 'null'); } catch (e) { data = null; }
        if (!data || !data.p || Date.now() - data.t > MAX_AGE) return false;
        tables.forEach(function (ps, i) {
            if (data.p[i]) { ps.scrollLeft = data.p[i][0]; ps.scrollTop = data.p[i][1]; }
        });
        if (typeof data.w === 'number') window.scrollTo(0, data.w);
        return true;
    }

    function onWheel(ps, e) {
        if (e.ctrlKey || !ps.classList.contains('pq-fit')) return;
        var dx = e.deltaX, dy = e.deltaY;
        if (e.deltaMode === 1) { dx *= 32; dy *= 32; }
        /* Über der Tageskopfzeile: senkrechtes Rad scrollt waagerecht */
        if (!e.shiftKey && Math.abs(dy) > Math.abs(dx) && e.target.closest && e.target.closest('thead') &&
            ps.scrollWidth > ps.clientWidth) {
            ps.scrollLeft += dy;
            e.preventDefault();
            return;
        }
        /* Nach unten: erst die Seite bis zum Andocken schieben */
        if (dy > 0 && Math.abs(dy) > Math.abs(dx)) {
            var gap = ps.getBoundingClientRect().top - dockTop();
            var pageLeft = document.documentElement.scrollHeight - window.innerHeight - window.scrollY;
            if (gap > 1 && pageLeft > 1) {
                window.scrollBy(0, Math.min(dy, gap, pageLeft));
                e.preventDefault();
            }
        }
    }

    function init() {
        tables = Array.prototype.slice.call(document.querySelectorAll('.ps'));
        if (!tables.length) return;
        fitAll();
        tables.forEach(function (ps) {
            ps.addEventListener('wheel', function (e) { onWheel(ps, e); }, { passive: false });
        });
        var restored = restore();
        if (!restored) tables.forEach(function (ps) { if (!todayVisible(ps)) scrollToToday(ps, false); });

        /* „Heute“ im aktuellen Monat: nicht neu laden, sondern zur Spalte springen */
        document.querySelectorAll('.btn-today').forEach(function (a) {
            a.addEventListener('click', function (e) {
                var ps = tables[0];
                if (!ps || !todayHead(ps)) return;
                e.preventDefault();
                var gap = ps.getBoundingClientRect().top - dockTop();
                if (gap > 1) window.scrollBy({ top: gap, behavior: 'smooth' });
                scrollToToday(ps, true);
                var th = todayHead(ps);
                th.classList.remove('pq-today-pulse'); void th.offsetWidth; th.classList.add('pq-today-pulse');
            });
        });

        var t = null;
        window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(fitAll, 100); });
        /* Werkzeugleiste ändert ihre Höhe (Werkzeug gewählt, Zeilenumbruch) */
        var tb = document.querySelector('.tb');
        if (tb && typeof ResizeObserver !== 'undefined') new ResizeObserver(function () { clearTimeout(t); t = setTimeout(fitAll, 60); }).observe(tb);
        window.addEventListener('pagehide', save);
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') save(); });
    }

    window.pqPlanScroll = { toToday: function () { if (tables[0]) scrollToToday(tables[0], true); } };

    /* nach app.js/planning-plus.js (Filter, Abdeckungszeile), damit Breiten stimmen */
    if (document.readyState === 'complete') init();
    else window.addEventListener('load', init);
})();
