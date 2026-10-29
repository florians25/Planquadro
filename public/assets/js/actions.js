/*
 * Planquadro – zentrale Ereignis-Delegation (ersetzt Inline-Handler (on…-Attribute)).
 *
 * Grund: Die Content-Security-Policy erlaubt keine Inline-Skripte ('unsafe-inline'
 * fehlt in script-src). Inline-Handler würden blockiert. Stattdessen tragen Elemente
 *
 *   data-action="name"            → bei click
 *   data-action-change="name"     → bei change
 *   data-action-input="name"      → bei input
 *   data-args='[1,"text"]'        → Argumente als JSON-Array (optional)
 *   data-prevent                  → event.preventDefault() vor dem Aufruf
 *   data-return                   → wie früher „return f()“ im Handler: Rückgabe false verhindert die Standardaktion
 *
 * "name" ist eine mit pqAction() registrierte Aktion oder eine selbst definierte
 * globale Funktion, auch als Pfad ("EPA.go"). Native Browserfunktionen (eval,
 * setTimeout, fetch …) werden nie direkt aufgerufen.
 *
 * Platzhalter in data-args: "$this" (Element), "$event", "$value" (el.value),
 * "$checked" (el.checked). Echte Zeichenketten, die mit "$" beginnen, werden von
 * opsActionAttr() (PHP) bzw. pqActAttr() (JS) als "$$…" kodiert.
 *
 * Das Verhalten entspricht Inline-Handlern: Es laufen alle Aktionen auf dem Weg
 * vom Ziel nach oben (innerste zuerst), bis eine Aktion stopPropagation() ruft.
 * Der Listener sitzt am document und wird vor allen anderen Skripten registriert
 * (Datei steht synchron im <head>).
 */
(function (w, d) {
    'use strict';
    var registry = Object.create(null);
    var EVENTS = { click: 'data-action', change: 'data-action-change', input: 'data-action-input' };
    var SEG = /^[A-Za-z_$][\w$]*$/;
    var BAD = ['__proto__', 'prototype', 'constructor'];

    /** Aktion registrieren: fn(el, event, ...args), this = Element. */
    function pqAction(name, fn) {
        if (typeof name === 'object') { for (var k in name) registry[k] = name[k]; return; }
        registry[name] = fn;
    }

    function isNative(fn) {
        try { return /\{\s*\[native code\]\s*\}\s*$/.test(Function.prototype.toString.call(fn)); }
        catch (e) { return true; }
    }

    /** Liefert {fn, ctx, reg} oder null. */
    function resolve(name) {
        if (!name) return null;
        if (registry[name]) return { fn: registry[name], ctx: null, reg: true };
        var parts = String(name).split('.'), ctx = w, obj = w;
        for (var i = 0; i < parts.length; i++) {
            if (!SEG.test(parts[i]) || BAD.indexOf(parts[i]) !== -1 || obj == null) return null;
            ctx = obj;
            obj = obj[parts[i]];
        }
        if (typeof obj !== 'function' || isNative(obj)) return null;
        return { fn: obj, ctx: ctx, reg: false };
    }

    function parseArgs(el, ev) {
        var raw = el.getAttribute('data-args');
        if (raw === null || raw === '') return [];
        var a = JSON.parse(raw);
        if (!Array.isArray(a)) a = [a];
        return a.map(function (v) {
            if (typeof v !== 'string' || v.charAt(0) !== '$') return v;
            if (v === '$this') return el;
            if (v === '$event') return ev;
            if (v === '$value') return el.value;
            if (v === '$checked') return el.checked;
            if (v.charAt(1) === '$') return v.slice(1);
            return v;
        });
    }

    function report(err) {
        if (typeof w.reportError === 'function') w.reportError(err);
        else if (w.console) console.error(err);
    }

    function run(el, ev, attr) {
        var name = el.getAttribute(attr);
        var r = resolve(name);
        if (!r) { report(new Error('Unbekannte Aktion: ' + name)); return; }
        if (el.hasAttribute('data-prevent')) ev.preventDefault();
        var res;
        try {
            var args = parseArgs(el, ev);
            res = r.reg ? r.fn.apply(el, [el, ev].concat(args)) : r.fn.apply(r.ctx, args);
        } catch (err) { report(err); return; }
        if (res === false && el.hasAttribute('data-return')) ev.preventDefault();
    }

    function dispatch(ev) {
        var attr = EVENTS[ev.type];
        var path = ev.composedPath ? ev.composedPath() : null;
        if (!path) { path = []; for (var n = ev.target; n; n = n.parentNode) path.push(n); }
        for (var i = 0; i < path.length; i++) {
            var el = path[i];
            if (el === d || el === w) break;
            if (el.nodeType === 1 && el.hasAttribute(attr)) {
                run(el, ev, attr);
                if (ev.cancelBubble) break;
            }
        }
    }

    for (var t in EVENTS) d.addEventListener(t, dispatch, false);

    /** Attribut-Markup für per JS erzeugtes HTML: ' data-action="…" data-args="…"'. */
    function pqActAttr(name, args, type) {
        var attr = type && type !== 'click' ? 'data-action-' + type : 'data-action';
        var s = ' ' + attr + '="' + escAttr(name) + '"';
        if (args && args.length) {
            s += ' data-args="' + escAttr(JSON.stringify(args.map(function (v) {
                return typeof v === 'string' && v.charAt(0) === '$' ? '$' + v : v;
            }))) + '"';
        }
        return s;
    }
    function escAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* Allgemeine Aktionen (ersetzen bisher als Inline-Code geschriebene Einzeiler) */
    pqAction({
        print: function () { w.print(); },
        reload: function () { w.location.reload(); },
        select: function (el) { el.select(); },
        /* Klasse an einem Element (id) umschalten: data-args='["userMenu","open"]' */
        toggleClass: function (el, ev, id, cls) { var t = d.getElementById(id); if (t) t.classList.toggle(cls); },
        /* Wert eines Feldes (id) setzen: data-args='["annPrio","info"]' */
        setValue: function (el, ev, id, val) { var t = d.getElementById(id); if (t) t.value = val; }
    });

    w.pqAction = pqAction;
    w.pqActAttr = pqActAttr;
    w.pqActions = { resolve: resolve, events: EVENTS };
})(window, document);
