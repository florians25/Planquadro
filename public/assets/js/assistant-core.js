/* =========================================================================
   Planquadro – Planungsassistent: reine Logik (ohne DOM)
   -------------------------------------------------------------------------
   Prüfung des Vorschlags, Verfügbarkeit, Zeiträume, Texte und Kennzahlen.
   Wird von assistant.js genutzt und in tests/js/assistant.test.mjs ohne
   Browser getestet. Keine Seiteneffekte, kein Zugriff auf document/window.
   ========================================================================= */
(function (root) {
  'use strict';

  var WD = ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
  var MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function parse(iso) { var p = String(iso).split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12, 0, 0); }
  function fmt(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function dmy(d) { var p = String(d || '').split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : (d || ''); }
  function dm(d) { var p = String(d || '').split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' : (d || ''); }
  function addDays(iso, n) { var d = parse(iso); d.setDate(d.getDate() + n); return fmt(d); }
  function daysBetween(a, b) { return Math.round((parse(b) - parse(a)) / 86400000); }
  function weekday(iso) { var w = parse(iso).getDay(); return w === 0 ? 7 : w; }
  function isoWeek(iso) {
    var d = parse(iso);
    var t = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    t.setDate(t.getDate() + 3 - ((t.getDay() + 6) % 7));
    var w1 = new Date(t.getFullYear(), 0, 4);
    return 1 + Math.round(((t - w1) / 86400000 - 3 + ((w1.getDay() + 6) % 7)) / 7);
  }
  function osRank(v) { var m = String(v || '').match(/(\d+)/); return m ? parseInt(m[1], 10) : 0; }
  /* „Mustermann, Maximilian“ → „Mustermann, M.“ – spart Breite im Raster. */
  function shortName(n) {
    n = String(n || '');
    var i = n.indexOf(', ');
    if (i < 0) return n;
    var first = n.slice(i + 2);
    return n.slice(0, i) + ', ' + (first ? first.charAt(0) + '.' : '');
  }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
  function monthEnd(iso) { var d = parse(iso); return fmt(new Date(d.getFullYear(), d.getMonth() + 1, 0, 12)); }
  function monthStart(iso) { var d = parse(iso); return fmt(new Date(d.getFullYear(), d.getMonth(), 1, 12)); }
  function nextMonthStart(iso) { var d = parse(iso); return fmt(new Date(d.getFullYear(), d.getMonth() + 1, 1, 12)); }
  function monthName(iso) { var d = parse(iso); return MONTHS[d.getMonth()] + ' ' + d.getFullYear(); }

  /* ------------------------------------------------------------ Zeiträume */
  /** Schnellwahl für den Zeitraum, ausgehend von heute. */
  function presets(today) {
    var nm = nextMonthStart(today), nm2 = nextMonthStart(nm);
    var restLabel = 'Rest ' + MONTHS[parse(today).getMonth()];
    return [
      { id: 'rest', label: restLabel, start: today, end: monthEnd(today) },
      { id: 'next', label: monthName(nm), start: nm, end: monthEnd(nm) },
      { id: 'next2', label: monthName(nm2), start: nm2, end: monthEnd(nm2) },
      { id: '2w', label: 'Nächste 2 Wochen', start: today, end: addDays(today, 13) }
    ];
  }
  /** Vorauswahl: Rest des Monats, wenn noch mindestens 10 Tage bleiben, sonst nächster Monat. */
  function defaultRange(today) {
    var left = daysBetween(today, monthEnd(today)) + 1;
    if (left >= 10) return { start: today, end: monthEnd(today), preset: 'rest' };
    var nm = nextMonthStart(today);
    return { start: nm, end: monthEnd(nm), preset: 'next' };
  }
  /** Kalendertage und Einsatztage (nur nach Wochentag) eines Zeitraums. */
  function rangeInfo(start, end, weekdays) {
    if (!start || !end || end < start) return { days: 0, active: 0, capped: false };
    var n = daysBetween(start, end) + 1, act = 0;
    for (var i = 0; i < Math.min(n, 121); i++) if (weekdays.indexOf(weekday(addDays(start, i))) >= 0) act++;
    return { days: n, active: act, capped: n > 121 };
  }

  /* ------------------------------------------------------- Konfiguration */
  function defaultFreeAreas(boot) {
    var cfg = (boot.defaults && boot.defaults.free_areas) || [];
    if (cfg.length) return cfg.map(Number);
    return (boot.general || []).filter(function (a) { return !!a.default_free; }).map(function (a) { return +a.id; });
  }

  function defaults(boot) {
    var D = boot.defaults || {}, r = defaultRange(boot.today);
    return {
      title: '', area_id: 0, start: r.start, end: r.end,
      weekdays: [1, 2, 3, 4, 5], skip_holidays: true, remarks: '', internal: false,
      demand_default: D.demand_default || 2, demand_min: (D.demand_min === 0 ? 0 : (D.demand_min || 1)),
      demand_by_weekday: {}, special_days: [],
      req_os_level: '', req_os_count: 0, req_professions: [], req_home_count: 0,
      home_mode: D.home_mode || 'prefer', use_experience: true, force_cells: [],
      free_areas: defaultFreeAreas(boot),
      respect_bond: D.respect_bond !== false, prefer_free: D.prefer_free !== false, cross_team: false,
      roles: [], include_users: [], exclude_users: [],
      azubi: { enabled: true, years: (D.azubi_years && D.azubi_years.length ? D.azubi_years.map(Number) : [2, 3]),
               max_per_day: (D.azubi_max === 0 ? 0 : (D.azubi_max || 1)), max_share: 50, min_per_day: 0,
               supervision_ratio: D.azubi_ratio || 2, never_alone: true, skip_school_days: true, no_weekend: true },
      rules: { max_consecutive: D.max_consecutive || 10, max_days_total: D.max_days_total || 20,
               weekend_flag: true, overwrite: 'none' },
      weights: { continuity: 70, fairness: 50, home_area: 40, os: 30 }
    };
  }

  /** Gespeicherte/geladene Konfiguration auf die aktuelle Struktur bringen. */
  function mergeConfig(boot, cfg, opts) {
    cfg = cfg || {};
    var d = defaults(boot), out = {}, k;
    for (k in d) out[k] = d[k];
    for (k in cfg) if (Object.prototype.hasOwnProperty.call(cfg, k)) out[k] = cfg[k];
    ['azubi', 'rules', 'weights'].forEach(function (g) {
      var o = {}, x;
      for (x in d[g]) o[x] = d[g][x];
      var src = (cfg[g] && typeof cfg[g] === 'object') ? cfg[g] : {};
      for (x in src) o[x] = src[x];
      out[g] = o;
    });
    out.demand_by_weekday = (cfg.demand_by_weekday && typeof cfg.demand_by_weekday === 'object' && !Array.isArray(cfg.demand_by_weekday))
      ? cfg.demand_by_weekday : {};
    ['special_days', 'weekdays', 'roles', 'include_users', 'exclude_users', 'req_professions', 'force_cells'].forEach(function (a) {
      if (!Array.isArray(out[a])) out[a] = d[a];
    });
    /* Ältere Stände kannten die Innendienst-Freigabe nicht – dann greift die
       Vorauswahl statt einer leeren Liste (nur für Browser-Zwischenstände). */
    if (!Array.isArray(out.free_areas) || (opts && opts.fillFree && !out.free_areas.length)) out.free_areas = d.free_areas;
    out.weekdays = out.weekdays.map(Number); out.include_users = out.include_users.map(Number);
    out.exclude_users = out.exclude_users.map(Number); out.free_areas = out.free_areas.map(Number);
    out.azubi.years = (out.azubi.years || []).map(Number);
    out.area_id = +out.area_id || 0;
    return out;
  }

  /** Liegt der Zeitraum einer Vorlage in der Vergangenheit, wird er ab heute gelegt. */
  function shiftToToday(S, today) {
    if (!S.end || S.end >= today) return false;
    var len = Math.max(0, daysBetween(S.start, S.end));
    S.start = today; S.end = addDays(today, len);
    return true;
  }

  /* ------------------------------------------------------- Verfügbarkeit */
  function stateOf(p, d) { return (p.days && p.days[d]) ? p.days[d] : null; }
  function isForced(S, uid, date) { return S.force_cells.indexOf(uid + '|' + date) >= 0; }
  /** Offener Entwurf eines anderen Planers mit anderem Inhalt, nicht freigegeben?
      Der Server lässt ihn bei der Übernahme stehen (epa_apply_decide). */
  function foreignDraft(p, date, S) {
    var st = stateOf(p, date);
    if (!st || !st.o || isForced(S, p.id, date)) return false;
    return !(st.t === 'einsatz' && +st.a === +S.area_id);
  }

  /**
   * Spiegelt epa_availability() des Servers. skip = würde beim Übernehmen
   * vom Server übersprungen (Zustand der Zelle), sonst nur ein Regelverstoß.
   */
  function avail(p, day, S, perm) {
    var d = day.date;
    if (p.entry && d < p.entry) return { ok: false, why: 'noch nicht im Unternehmen', code: 'eintritt' };
    if (p.exit && d > p.exit) return { ok: false, why: 'bereits ausgetreten', code: 'austritt' };
    if (day.weekend && S.rules.weekend_flag && !p.weekend) return { ok: false, why: 'nicht für Wochenenddienst freigegeben', code: 'wochenende' };
    if (p.azubi_year > 0) {
      if (!S.azubi.enabled) return { ok: false, why: 'Azubis sind nicht einbezogen', code: 'azubi_aus' };
      if (S.azubi.years.indexOf(p.azubi_year) < 0) return { ok: false, why: p.azubi_year + '. Lehrjahr nicht ausgewählt', code: 'lehrjahr' };
      if (S.azubi.no_weekend && day.weekend) return { ok: false, why: 'Azubis ohne Wochenenddienst', code: 'azubi_we' };
    }
    var st = stateOf(p, d);
    if (!st) return { ok: true, why: 'frei', code: 'frei' };
    if (st.t === 'schule') return { ok: false, why: 'Berufsschule', hard: true, skip: true, code: 'schule' };
    if (st.t === 'gesperrt') return { ok: false, why: 'Sperrtag', hard: true, skip: true, code: 'gesperrt' };
    var forced = isForced(S, p.id, d);
    if (st.t === 'abwesend') {
      if ((S.rules.overwrite === 'all' || forced) && perm.absence) return { ok: true, ov: true, why: 'überschreibt: ' + st.l, code: 'ueberschreibt_abw' };
      return { ok: false, why: st.l, replaceable: !!perm.absence, current: st.l, kind: 'abwesenheit', skip: true, code: 'abwesend' };
    }
    if (st.t === 'einsatz') {
      if (+st.a === +S.area_id) return { ok: true, why: 'bereits für diesen Bereich geplant', code: 'bereits' };
      if (S.free_areas.indexOf(+st.a) >= 0) return { ok: true, ov: true, why: 'verplanbar – ersetzt ' + st.l, code: 'frei_intern' };
      if (forced || S.rules.overwrite === 'assignments' || S.rules.overwrite === 'all') return { ok: true, ov: true, why: 'überschreibt: ' + st.l, code: 'ueberschreibt' };
      return { ok: false, why: 'bereits geplant: ' + st.l, replaceable: true, current: st.l, kind: 'einsatz', skip: true, code: 'belegt' };
    }
    if (st.t === 'offen') return { ok: true, why: 'ersetzt offenen Tag', code: 'offen' };
    return { ok: true, why: 'frei', code: 'frei' };
  }

  /* -------------------------------------------------------------- Prüfung */
  /** Live-Prüfung des (ggf. von Hand geänderten) Vorschlags. */
  function validate(R, S, perm) {
    var byId = {}; R.people.forEach(function (p) { byId[p.id] = p; });
    var out = [], load = {}, streak = {}, lastD = {}, total = 0, filled = 0;

    R.days.forEach(function (day) {
      var ids = R.plan[day.date] || [];
      ids.forEach(function (u) { load[u] = (load[u] || 0) + 1; });

      R.people.forEach(function (p) {
        if (ids.indexOf(p.id) < 0) { streak[p.id] = 0; return; }
        streak[p.id] = (lastD[p.id] && addDays(lastD[p.id], 1) === day.date) ? (streak[p.id] || 0) + 1 : 1;
        lastD[p.id] = day.date;
        if (streak[p.id] === S.rules.max_consecutive + 1) {
          out.push({ level: 'warn', code: 'serie', date: day.date, uid: p.id,
            msg: p.name + ': mehr als ' + S.rules.max_consecutive + ' Tage am Stück (ab ' + day.label + ').' });
        }
      });

      if (day.skip) {
        if (ids.length) out.push({ level: 'warn', code: 'ausgenommen', date: day.date, uid: 0,
          msg: day.label + ' ist ausgenommen (' + (day.reason || 'kein Bedarf') + '), es sind aber ' + ids.length + ' Personen eingeplant.' });
        else if (day.holiday && S.skip_holidays && S.weekdays.indexOf(day.wd) >= 0)
          out.push({ level: 'info', code: 'feiertag', date: day.date, uid: 0, msg: day.label + ' übersprungen – ' + (day.reason || day.holiday) });
        return;
      }

      total += day.required;
      filled += Math.min(ids.length, day.required);

      var nAz = 0, nTech = 0, nOs = 0, nHome = 0;
      ids.forEach(function (uid) {
        var p = byId[uid]; if (!p) return;
        if (p.azubi_year > 0) nAz++; else nTech++;
        if (S.req_os_level && p.os_rank >= osRank(S.req_os_level)) nOs++;
        if (S.area_id && +p.area_id === +S.area_id) nHome++;
        var a = avail(p, day, S, perm);
        if (!a.ok) out.push({ level: 'block', code: 'konflikt', skip: !!a.skip, date: day.date, uid: uid, msg: p.name + ' am ' + day.label + ': ' + a.why + '.' });
        else if (foreignDraft(p, day.date, S)) out.push({ level: 'warn', code: 'fremder_entwurf', skip: true, date: day.date, uid: uid,
          msg: p.name + ' am ' + day.label + ': offener Entwurf eines anderen Planers (' + stateOf(p, day.date).l + ') – wird beim Übernehmen übersprungen.' });
        else if (a.ov) out.push({ level: 'info', code: 'ueberschreibt', date: day.date, uid: uid, msg: p.name + ' am ' + day.label + ' – ' + a.why });
      });

      var gap = day.required - ids.length;
      if (gap > 0) {
        /* Nicht nur melden, dass etwas fehlt – auch warum niemand mehr kann. */
        var reasons = {}, freeLeft = 0;
        R.people.forEach(function (p) {
          if (ids.indexOf(p.id) >= 0) return;
          var a = avail(p, day, S, perm);
          if (a.ok) { freeLeft++; return; }
          var key = a.why.replace(/:.*$/, '');
          reasons[key] = (reasons[key] || 0) + 1;
        });
        var top = Object.keys(reasons).sort(function (x, y) { return reasons[y] - reasons[x]; }).slice(0, 3)
                    .map(function (k) { return reasons[k] + '× ' + k; }).join(', ');
        out.push({ level: ids.length < S.demand_min ? 'block' : 'warn', code: 'unterdeckung', date: day.date, uid: 0,
          free: freeLeft,
          msg: day.label + ': ' + ids.length + ' von ' + day.required + ' Plätzen besetzt (' + gap + ' offen). ' +
               (freeLeft > 0 ? freeLeft + ' Person(en) wären noch verfügbar.' : 'Niemand mehr verfügbar' + (top ? ' – ' + top + '.' : '.')) });
      } else if (gap < 0) out.push({ level: 'info', code: 'ueberdeckung', date: day.date, uid: 0,
        msg: day.label + ': ' + ids.length + ' Personen bei ' + day.required + ' Plätzen.' });

      if (S.azubi.enabled && nAz > 0) {
        if (S.azubi.never_alone && nTech === 0) out.push({ level: 'warn', code: 'azubi_allein', date: day.date, uid: 0,
          msg: day.label + ': Azubis wären ohne Fachkraft vor Ort.' });
        if (nAz > nTech * S.azubi.supervision_ratio) out.push({ level: 'warn', code: 'betreuung', date: day.date, uid: 0,
          msg: day.label + ': Betreuungsschlüssel 1:' + S.azubi.supervision_ratio + ' nicht eingehalten (' + nTech + ' Fachkräfte, ' + nAz + ' Azubis).' });
        if (nAz > S.azubi.max_per_day) out.push({ level: 'warn', code: 'azubi_max', date: day.date, uid: 0,
          msg: day.label + ': ' + nAz + ' Azubis, erlaubt sind ' + S.azubi.max_per_day + '.' });
      }
      if (S.req_os_count > 0 && S.req_os_level && nOs < S.req_os_count) out.push({ level: 'warn', code: 'os_level', date: day.date, uid: 0,
        msg: day.label + ': nur ' + nOs + ' von ' + S.req_os_count + ' Personen mit ' + S.req_os_level + '.' });
      if (S.req_home_count > 0 && S.area_id && nHome < S.req_home_count) out.push({ level: 'warn', code: 'stammbereich', date: day.date, uid: 0,
        msg: day.label + ': nur ' + nHome + ' von ' + S.req_home_count + ' Personen aus dem Stammbereich.' });
    });

    Object.keys(load).forEach(function (uid) {
      if (load[uid] > S.rules.max_days_total) out.push({ level: 'warn', code: 'max_tage', date: '', uid: +uid,
        msg: (byId[uid] ? byId[uid].name : '#' + uid) + ': ' + load[uid] + ' Tage im Zeitraum (Grenze ' + S.rules.max_days_total + ').' });
    });
    S.include_users.forEach(function (uid) {
      var p = byId[uid]; if (!p) return;
      R.days.forEach(function (day) {
        if (day.skip || (R.plan[day.date] || []).indexOf(+uid) >= 0) return;
        var a = avail(p, day, S, perm);
        out.push({ level: a.ok ? 'warn' : 'block', code: 'fix_fehlt', date: day.date, uid: +uid,
          msg: p.name + ' ist fest eingeplant, fehlt aber am ' + day.label + (a.ok ? '.' : ' (' + a.why + ').') });
      });
    });

    var ord = { block: 0, warn: 1, info: 2 };
    out.sort(function (a, b) { return (ord[a.level] - ord[b.level]) || String(a.date).localeCompare(String(b.date)); });
    var count = function (l) { return out.filter(function (c) { return c.level === l; }).length; };
    return {
      conflicts: out, load: load,
      stats: { total: total, filled: filled, coverage: total > 0 ? Math.round(filled / total * 100) : 100,
               used: Object.keys(load).length, blocks: count('block'), warns: count('warn'), infos: count('info') }
    };
  }

  /** Was bedeutet ein Prüfpunkt, und was ist zu tun? */
  var HELP = {
    unterdeckung: 'Es fehlen Personen. Über „Personen zeigen“ jemanden einplanen – oder in Schritt 2 den Bedarf senken bzw. Grenzen lockern.',
    konflikt: 'Die Person ist an diesem Tag eigentlich nicht einsetzbar. Zelle anklicken, um den Eintrag zu entfernen.',
    konflikt_skip: 'Diese Zelle ist belegt oder geschützt – beim Übernehmen wird sie übersprungen. Zelle anklicken, um den Eintrag zu entfernen.',
    serie: 'Mehr Tage am Stück als erlaubt. Einzelne Tage entfernen oder die Grenze in den Experten-Optionen anheben.',
    max_tage: 'Die Person trägt mehr Tage als die Grenze vorsieht. Tage an andere verteilen oder die Grenze anheben.',
    azubi_allein: 'Azubis brauchen Begleitung. Eine Fachkraft dazu einplanen oder den Azubi an diesem Tag herausnehmen.',
    betreuung: 'Zu viele Azubis je Fachkraft. Eine Fachkraft dazu einplanen oder einen Azubi herausnehmen.',
    azubi_max: 'Mehr Azubis als erlaubt. Einen Azubi herausnehmen oder die Obergrenze in den Azubi-Regeln anpassen.',
    os_level: 'Zu wenig Personen mit dem geforderten OS-Level. „Personen zeigen“ listet passende Kandidaten.',
    stammbereich: 'Zu wenig Personen aus dem Stammbereich. „Personen zeigen“ stellt sie nach oben.',
    fremder_entwurf: 'Ein Kollege hat diese Zelle schon im Entwurf belegt – sein Entwurf bleibt stehen. Rechtsklick → „Entwurf bewusst ersetzen“, wenn Sie ihn überschreiben wollen.',
    fix_fehlt: 'Die Person ist fest eingeplant, fehlt aber an diesem Tag. Zelle anklicken, um sie einzuplanen – sofern sie kann.',
    ausgenommen: 'Der Tag gehört nicht zur Planung, trotzdem sind Personen eingetragen. Einträge entfernen oder Sondertag anlegen.',
    ueberschreibt: 'Ein bestehender Eintrag wird ersetzt (Innendienst, anderer Einsatz oder freigegebene Abwesenheit).',
    ueberdeckung: 'Mehr Personen als Plätze – kein Fehler, nur ein Hinweis.',
    feiertag: 'Feiertage werden übersprungen (Einstellung in Schritt 1).',
    azubi_platz: 'Reservierte Ausbildungsplätze blieben frei – kein passender Azubi verfügbar.',
    grenze_gelockert: 'Um den Bedarf zu decken, wurde eine Belastungsgrenze überschritten.'
  };
  function explain(c) {
    if (c.code === 'konflikt' && c.skip) return HELP.konflikt_skip;
    return HELP[c.code] || '';
  }

  /* ------------------------------------------------------------ Kennzahlen */
  /** Kennzahlen für Zusammenfassung und Kopfzeile. */
  function summary(R, S) {
    var byId = {}, people = {}, entries = 0, az = 0, hm = 0, fromFree = 0, fromPlan = 0, fromAbs = 0;
    R.people.forEach(function (p) { byId[p.id] = p; });
    var planDays = R.days.filter(function (d) { return !d.skip; }).length;
    Object.keys(R.plan).forEach(function (dt) {
      (R.plan[dt] || []).forEach(function (u) {
        var p = byId[u]; if (!p) return;
        entries++; people[u] = true;
        if (p.azubi_year > 0) az++;
        if (+p.area_id === +S.area_id) hm++;
        var st = stateOf(p, dt);
        if (!st) return;
        if (st.t === 'abwesend') fromAbs++;
        if (st.t !== 'einsatz' || +st.a === +S.area_id) return;
        if (S.free_areas.indexOf(+st.a) >= 0) fromFree++; else fromPlan++;
      });
    });
    var filled = R.stats ? R.stats.filled : entries;
    return { planDays: planDays, entries: entries, people: Object.keys(people).length, azubi: az, home: hm,
             fromFree: fromFree, fromPlan: fromPlan, fromAbs: fromAbs,
             avg: planDays ? filled / planDays : 0 };
  }

  /** Überschrift der Übernahme: „27 Einsätze für 9 Personen, 3 Konflikte übersprungen“. */
  function applyHeadline(res) {
    var t = plural(res.written, 'Einsatz', 'Einsätze') + ' für ' + plural(res.people, 'Person', 'Personen');
    if (res.skipped_total) t += ', ' + plural(res.skipped_total, 'Konflikt', 'Konflikte') + ' übersprungen';
    return t;
  }

  /** Tagesabdeckung als Stufe (für Kopfzeilen, Vorschau, Fußzeile). */
  function dayLevel(n, day, S) {
    if (day.skip) return 'skip';
    if (n >= day.required) return 'ok';
    return n < S.demand_min ? 'bad' : 'part';
  }

  /* ------------------------------------------------------------- Personen */
  /** Personenliste nach Filtern; f = {q, area, role, home, exp, noAz, we, flex, sort}. */
  function filterPeople(src, f) {
    var q = String(f.q || '').toLowerCase().trim();
    var out = src.filter(function (p) {
      if (q) {
        var hay = (p.name + ' ' + p.os + ' ' + p.prof + ' ' + p.area_code + ' ' + p.role_label).toLowerCase();
        if (q.split(/\s+/).some(function (w) { return hay.indexOf(w) < 0; })) return false;
      }
      if (f.area !== '' && f.area !== undefined && +p.area_id !== +f.area) return false;
      if (f.role && p.role !== f.role) return false;
      if (f.home && !p.home) return false;
      if (f.exp && !(p.exp > 0)) return false;
      if (f.noAz && p.azubi_year > 0) return false;
      if (f.we && !p.weekend) return false;
      if (f.flex && !(p.bond < 0.6 || p.home)) return false;
      return true;
    });
    var sort = f.sort || 'best';
    out.sort(function (a, b) {
      if (sort === 'name') return a.name.localeCompare(b.name, 'de');
      if (sort === 'exp') return (b.exp - a.exp) || a.name.localeCompare(b.name, 'de');
      if (sort === 'os') return (osRank(b.os) - osRank(a.os)) || a.name.localeCompare(b.name, 'de');
      if (a.home !== b.home) return a.home ? -1 : 1;
      if (a.exp !== b.exp) return b.exp - a.exp;
      return a.name.localeCompare(b.name, 'de');
    });
    return out;
  }

  /** Reihenfolge im Dialog „Passende Personen“: leicht Umplanbare zuerst. */
  function suggestRank(it) {
    if (!it.ok) return 9;
    if (/Innendienst|verplanbar/.test(it.reason)) return 0;
    if (/frei/.test(it.reason)) return 1;
    if (it.home) return 2;
    return 3;
  }
  function sortSuggest(items) {
    return items.slice().sort(function (x, y) {
      return suggestRank(x) - suggestRank(y) || (y.exp || 0) - (x.exp || 0) || x.name.localeCompare(y.name, 'de');
    });
  }

  /* ------------------------------------------------------------- Tastatur */
  /** Nächste Rasterposition für eine Taste; null = Taste nicht zuständig. */
  function gridMove(r, c, key, rows, cols, ctrl) {
    if (!rows || !cols) return null;
    switch (key) {
      case 'ArrowRight': c = Math.min(cols - 1, c + 1); break;
      case 'ArrowLeft': c = Math.max(0, c - 1); break;
      case 'ArrowDown': r = Math.min(rows - 1, r + 1); break;
      case 'ArrowUp': r = Math.max(0, r - 1); break;
      case 'Home': c = 0; if (ctrl) r = 0; break;
      case 'End': c = cols - 1; if (ctrl) r = rows - 1; break;
      case 'PageDown': r = Math.min(rows - 1, r + 10); break;
      case 'PageUp': r = Math.max(0, r - 10); break;
      default: return null;
    }
    return [r, c];
  }

  var api = {
    WD: WD, esc: esc, dmy: dmy, dm: dm, addDays: addDays, daysBetween: daysBetween, weekday: weekday,
    isoWeek: isoWeek, osRank: osRank, shortName: shortName, plural: plural, monthName: monthName, monthStart: monthStart,
    presets: presets, defaultRange: defaultRange, rangeInfo: rangeInfo,
    defaults: defaults, mergeConfig: mergeConfig, shiftToToday: shiftToToday, defaultFreeAreas: defaultFreeAreas,
    stateOf: stateOf, isForced: isForced, foreignDraft: foreignDraft, avail: avail, validate: validate, explain: explain, HELP: HELP,
    summary: summary, applyHeadline: applyHeadline, dayLevel: dayLevel,
    filterPeople: filterPeople, suggestRank: suggestRank, sortSuggest: sortSuggest, gridMove: gridMove
  };
  root.PQACore = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
