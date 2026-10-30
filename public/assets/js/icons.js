/**
 * Planquadro – Icons
 * ---------------------------------------------------------------------------
 * pqIcon(name)  → SVG-Markup aus dem Sprite (assets/icons.svg, tools/icons/icons.py).
 * Außerdem bekommen Knöpfe (.btn, .mx, „Heute“) automatisch ein passendes Icon
 * anhand ihrer Beschriftung – auch Knöpfe, die Skripte später erzeugen.
 * Einzelne Knöpfe ausnehmen: data-noicon. Eigene Icons im Markup haben Vorrang.
 * Die Beschriftung selbst bleibt unverändert (nur führende Zeichen wie „«“, „✎“
 * werden durch das Icon ersetzt), Bedienung und Barrierefreiheit wie bisher.
 */
(function () {
    'use strict';
    var meta = document.querySelector('meta[name="pq-icons"]');
    var URL = meta ? meta.getAttribute('content') : 'assets/icons.svg';

    function pqIcon(name, cls) {
        return '<svg class="pq-i' + (cls ? ' ' + cls : '') + '" aria-hidden="true" focusable="false"><use href="' + URL + '#' + name + '"/></svg>';
    }
    window.pqIcon = pqIcon;

    /* Reihenfolge zählt: der erste Treffer gewinnt */
    var RULES = [
        [/^(abbrechen|schlie(ß|ss)en|verlassen|ablegen|verwerfen)$/, 'x'],
        [/(genehmigen|annehmen)/, 'check-circle'],
        [/ablehnen/, 'x-circle'],
        [/veröffentlich/, 'send'],
        [/rückgängig/, 'undo'],
        [/^wiederholen/, 'redo'],
        [/zurücksetzen/, 'undo'],
        [/(speichern|^sichern und)/, 'save'],
        [/^(jetzt )?sichern$|backup/, 'archive'],
        [/(löschen|entfernen|^entf\.?$)/, 'trash'],
        [/(bearbeiten|^ändern$|umbenennen)/, 'pencil'],
        [/drucken/, 'printer'],
        [/bereinigen/, 'eraser'],
        [/(aktualisieren|neu berechnen|neu laden|neu erzeugen)/, 'refresh'],
        [/^heute$/, 'calendar-check'],
        [/^weiter/, '>arrow-right'],
        [/^zurück/, 'arrow-left'],
        [/(importieren|hochladen|^import)/, 'upload'],
        [/(download|export|herunterladen)/, 'download'],
        [/dashboard/, 'home'],
        [/(monatsplanung|^planung$|^planen$|^jetzt planen)/, 'calendar-edit'],
        [/(mein plan|vollständiger plan)/, 'calendar-user'],
        [/gesamtplan/, 'grid'],
        [/(vorschlag|assistent)/, 'sparkles'],
        [/(fest einplanen|^übernehmen|^anwenden|^bestätigen|^ok$|^ja\b|alle gelesen|^prüfen)/, 'check'],
        [/ausschließen/, 'ban'],
        [/(hinzufügen|erstellen|anlegen|^neu\b|^neue[rsn]?\b|^\+)/, 'plus'],
        [/zuweisen/, 'user-plus'],
        [/widerrufen/, 'ban'],
        [/testen/, 'activity'],
        [/sortieren/, 'sort'],
        [/vorlage/, 'file'],
        [/kürzel/, 'keyboard'],
        [/^ansicht/, 'sliders'],
        [/^mehr$/, '>chevron-down'],
        [/(^sperren|entsperren)/, 'lock'],
        [/bemerkung/, 'message'],
        [/^such/, 'search'],
        [/filter/, 'filter'],
        [/(anzeigen|ansehen|vorschau|^details)/, 'eye'],
        [/wartung/, 'clock'],
        [/(beantragen|^antrag|anträge)/, 'clipboard-check'],
        [/urlaub/, 'sun'],
        [/einstellungen/, 'settings'],
        [/(organisation|branding)/, 'building'],
        [/kopier/, 'copy'],
        [/öffnen/, 'external'],
        [/abmelden/, 'logout'],
        [/anmelden/, 'login'],
        [/passwort/, 'key'],
        [/(team|mitarbeit)/, 'users'],
        [/(mail|e-mail)/, 'mail']
    ];
    /* Knöpfe, die nur aus einem Zeichen bestehen */
    var GLYPHS = { '⋯': ['more', 'Mehr'], '…': ['more', 'Mehr'], '✕': ['x', 'Schließen'], '×': ['x', 'Schließen'], '▲': ['chevron-up', 'Nach oben'],
        '▼': ['chevron-down', 'Nach unten'], '↻': ['refresh', 'Aktualisieren'], '«': ['chevron-left', 'Zurück'], '»': ['chevron-right', 'Weiter'],
        '+': ['plus', 'Hinzufügen'], '✎': ['pencil', 'Bearbeiten'], '🗑': ['trash', 'Löschen'] };
    var LEAD = /^\s*[«‹←↶↷⊘✕×✎⌨≡☰▦✓✔+⟲⟳↻⎙]+\s*/;
    var TRAIL = /\s*[»›→▾▼]+\s*$/;
    var SEL = 'button.btn, a.btn, .btn-today, button.mx';

    function iconFor(label) {
        var l = label.toLowerCase();
        for (var i = 0; i < RULES.length; i++) if (RULES[i][0].test(l)) return RULES[i][1];
        return null;
    }
    function textNodes(el) {
        var out = [], w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null), n;
        while ((n = w.nextNode())) if (n.nodeValue.trim()) out.push(n);
        return out;
    }

    function decorate(b) {
        if (b.hasAttribute('data-noicon') || b.querySelector('svg, img')) return;
        var raw = b.textContent.replace(/\s+/g, ' ').trim();
        if (!raw) return;
        if (GLYPHS[raw]) {
            if (!b.getAttribute('aria-label')) b.setAttribute('aria-label', b.getAttribute('title') || GLYPHS[raw][1]);
            b.textContent = '';
            b.insertAdjacentHTML('afterbegin', pqIcon(GLYPHS[raw][0]));
            b.classList.add('pq-ibtn');
            return;
        }
        var left = /^[«‹←]/.test(raw), right = /[»›→]$/.test(raw), drop = /[▾▼]$/.test(raw);
        var core = raw.replace(LEAD, '').replace(TRAIL, '').trim();
        if (core.length < 2) return;                    /* Kürzel wie „U“, „K“, „GL“ bleiben, wie sie sind */
        var name = iconFor(core), after = false;
        if (name && name.charAt(0) === '>') { name = name.slice(1); after = true; }
        if (left) { name = 'chevron-left'; after = false; }
        else if (right) { name = 'chevron-right'; after = true; }
        else if (drop) { name = 'chevron-down'; after = true; }
        if (!name) return;
        var tn = textNodes(b);
        if (tn.length) {
            tn[0].nodeValue = tn[0].nodeValue.replace(LEAD, '');
            var last = tn[tn.length - 1];
            last.nodeValue = last.nodeValue.replace(TRAIL, '');
        }
        b.insertAdjacentHTML(after ? 'beforeend' : 'afterbegin', pqIcon(name, after ? 'pq-i-after' : ''));
    }

    function scan(root) {
        if (!root || root.nodeType !== 1) return;
        if (root.matches && root.matches(SEL)) decorate(root);
        if (root.querySelectorAll) {
            var list = root.querySelectorAll(SEL);
            for (var i = 0; i < list.length; i++) decorate(list[i]);
        }
    }

    function start() {
        scan(document.body);
        if (typeof MutationObserver === 'undefined') return;
        var pending = [], queued = false;
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var m = muts[i];
                /* Knopf hat neuen Text bekommen (z. B. „Speichere …“ → „Speichern“) */
                if (m.target.nodeType === 1 && m.target.matches && m.target.matches(SEL)) pending.push(m.target);
                for (var j = 0; j < m.addedNodes.length; j++) if (m.addedNodes[j].nodeType === 1) pending.push(m.addedNodes[j]);
            }
            if (!queued && pending.length) {
                queued = true;
                requestAnimationFrame(function () { var p = pending; pending = []; queued = false; p.forEach(scan); });
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
