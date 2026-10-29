<?php
/**
 * Planquadro – Planungsassistent (Serverlogik)
 * --------------------------------------------------------------------------
 * Ausgelagert aus public/pages/planungsassistent.php, damit Algorithmus,
 * Übernahme und Rücknahme ohne Browser testbar sind (tests/Unit,
 * tests/Integration/PlanningAssistantTest.php).
 *
 * Bausteine
 *   epa_defaults()        Vorgaben aus den Einstellungen (Seite „Planung“)
 *   epa_cfg()             Konfiguration des Browsers normalisieren/begrenzen
 *   epa_context()         Personen, Tage, Belegung, Feiertage laden
 *   epa_availability()    Ist eine Person an einem Tag einsetzbar?
 *   epa_generate()        Vorschlag berechnen (reine Funktion, ohne DB)
 *   epa_apply()           Übernahme als Entwurf – auch als Probelauf (dry run)
 *   epa_undo()            Übernahme zurücknehmen
 *   epa_handle()          AJAX-Aktionen der Seite (liefert [Status, Antwort])
 *
 * Sicherheitsregeln (unverändert gegenüber v6.9):
 *   - geschrieben wird ausschließlich nach plan_drafts (veröffentlicht wird in
 *     der Monatsplanung), jede Übernahme ist ein Lauf in planning_runs
 *   - Sperrtag und Berufsschule werden nie überschrieben, Abwesenheiten nur mit
 *     „unlock_vacation“ und ausdrücklicher Freigabe, fremde Einsätze nur mit
 *     Regel oder Einzelfreigabe
 *   - offene Entwürfe anderer Planer bleiben stehen (außer per Einzelfreigabe
 *     oder wenn sie selbst aus dem Assistenten stammen)
 *   - Zellen werden in der Transaktion gesperrt (SELECT … FOR UPDATE), jede
 *     Änderung landet im Änderungsprotokoll (plan_change_log) und als
 *     Live-Ereignis; der Lauf im audit_log
 *   - Revisionen: Hat der Browser beim Probelauf Zell-Revisionen erhalten und
 *     schickt sie mit, wird eine inzwischen geänderte Zelle übersprungen
 *   - Personenkreis = Planungsrecht (visibleUserIds bzw. teamübergreifend nach
 *     E-1/P-6), Abwesenheitsgründe nur für Berechtigte (Art. 9 DSGVO)
 */

require_once __DIR__ . '/PlanCells.php';

/** Vorgaben aus den Einstellungen. $get(key, default) liest eine Einstellung. */
function epa_defaults(?callable $get = null): array
{
    $get = $get ?? function (string $k, $d) {
        $v = function_exists('getSetting') ? getSetting($k, null) : null;
        return ($v === null || $v === '') ? $d : $v;
    };
    $ids = function (string $k) use ($get): array {
        return array_values(array_filter(array_map('intval', explode(',', (string)$get($k, '')))));
    };
    $home = $get('epa_home_mode', 'prefer');
    return [
        'free_areas'      => $ids('epa_free_areas'),
        'prefer_free'     => $get('epa_prefer_free', '1') !== '0',
        'respect_bond'    => $get('epa_respect_bond', '1') !== '0',
        'home_mode'       => in_array($home, ['prefer', 'only', 'off'], true) ? $home : 'prefer',
        'demand_default'  => max(1, (int)$get('epa_demand_default', 2)),
        'demand_min'      => max(0, (int)$get('epa_demand_min', 1)),
        'max_consecutive' => max(1, (int)$get('epa_max_consecutive', 10)),
        'max_days_total'  => max(1, (int)$get('epa_max_days_total', 20)),
        'azubi_years'     => $ids('epa_azubi_years') ?: [2, 3],
        'azubi_max'       => max(0, (int)$get('epa_azubi_max', 1)),
        'azubi_ratio'     => max(1, (int)$get('epa_azubi_ratio', 2)),
        'exp_months'      => max(1, min(60, (int)$get('epa_exp_months', 12))),
        'bond_months'     => max(1, min(60, (int)$get('epa_bond_months', 6))),
        /* Teamleiter dürfen teamübergreifend planen, wenn freigegeben */
        'cross_team'      => $get('epa_cross_team', '0') === '1',
    ];
}

/** Lehrjahr eines Nutzers, sonst 0. Berücksichtigt auch eigene Rollen. */
function epa_azubiYear(string $role, array $customRoles): int
{
    if (preg_match('/^azubi_(\d)$/', $role, $m)) return (int)$m[1];
    $base = $customRoles[$role]['base_role'] ?? '';
    if (preg_match('/^azubi_(\d)$/', (string)$base, $m)) return (int)$m[1];
    return 0;
}

/** Vergleichbarer Rang eines OS-Levels ("L2", "Level 3", "2" ...). */
function epa_osRank(?string $v): int
{
    if ($v === null || $v === '') return 0;
    if (preg_match('/(\d+)/', $v, $m)) return (int)$m[1];
    return 0;
}

/**
 * Beschriftung einer Abwesenheit – exakt nach denselben Regeln wie die
 * Monatsplanung: Administrator immer, Manager und Teamleiter für das eigene
 * Team, alle übrigen sehen nur „Abwesend“ (Krank/Urlaub = Art. 9 DSGVO).
 */
function epa_absLabel(string $type, array $map, int $userId): string
{
    if (function_exists('isConfidentialAbsence') && isConfidentialAbsence($type)
        && function_exists('canSeeAbsenceDetails') && !canSeeAbsenceDetails($userId)) return 'Abwesend';
    return $map[$type] ?? $type;
}

/** Darf der angemeldete Nutzer über das eigene Team hinaus planen? (E-1/P-6) */
function epa_may_cross_team(array $defaults): bool
{
    if (hasRole('admin', 'manager') || hasPerm('view_all_employees')) return true;
    return !empty($defaults['cross_team']) && hasRole('teamleiter');
}

/**
 * IDs aller Mitarbeiter, die der angemeldete Nutzer planen darf (dieselbe
 * Logik wie die Monatsplanung inkl. Teamhierarchie und Vertretungen).
 * null = keine Einschränkung. Teamübergreifend nur, wenn gewünscht UND
 * erlaubt; Abwesenheitsgründe außerhalb des Teams bleiben „Abwesend“.
 */
function epa_scope(array $cfg, array $defaults): ?array
{
    if (!empty($cfg['cross_team']) && epa_may_cross_team($defaults)) return null;
    $ids = visibleUserIds();
    return $ids === null ? null : array_values(array_map('intval', $ids));
}

/** Normalisiert und begrenzt die vom Browser gesendete Konfiguration. */
function epa_cfg(array $r, array $defaults = []): array
{
    $today = date('Y-m-d');
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['start'] ?? '') ? $r['start'] : $today;
    $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['end']   ?? '') ? $r['end']   : $start;
    if (strtotime($end) < strtotime($start)) $end = $start;
    /* Harte Obergrenze: 120 Tage – schützt vor versehentlichen Jahresläufen. */
    if ((strtotime($end) - strtotime($start)) / 86400 > 120) {
        $end = date('Y-m-d', strtotime($start . ' +120 days'));
    }

    $wd = [];
    foreach ((array)($r['weekdays'] ?? [1, 2, 3, 4, 5]) as $d) {
        $d = (int)$d; if ($d >= 1 && $d <= 7) $wd[] = $d;
    }
    if (!$wd) $wd = [1, 2, 3, 4, 5];

    $dbw = [];
    foreach ((array)($r['demand_by_weekday'] ?? []) as $k => $v) {
        $k = (int)$k; if ($k >= 1 && $k <= 7) $dbw[$k] = max(0, min(99, (int)$v));
    }

    $special = [];
    foreach ((array)($r['special_days'] ?? []) as $sd) {
        $sd = (array)$sd;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd['date'] ?? '')) continue;
        $special[$sd['date']] = [
            'date'  => $sd['date'],
            'count' => max(0, min(99, (int)($sd['count'] ?? 0))),
            'note'  => mb_substr(trim((string)($sd['note'] ?? '')), 0, 120),
        ];
    }

    $az = (array)($r['azubi'] ?? []);
    $years = [];
    foreach ((array)($az['years'] ?? [1, 2, 3]) as $y) { $y = (int)$y; if ($y >= 1 && $y <= 4) $years[] = $y; }

    $ru = (array)($r['rules'] ?? []);
    $we = (array)($r['weights'] ?? []);
    $ow = $ru['overwrite'] ?? 'none';
    if (!in_array($ow, ['none', 'assignments', 'all'], true)) $ow = 'none';

    return [
        'title'    => mb_substr(trim((string)($r['title'] ?? '')), 0, 140) ?: 'Einsatzplanung',
        'area_id'  => (int)($r['area_id'] ?? 0),
        'start'    => $start,
        'end'      => $end,
        'weekdays' => array_values(array_unique($wd)),
        'skip_holidays' => !empty($r['skip_holidays']),
        'remarks'  => mb_substr(trim((string)($r['remarks'] ?? '')), 0, 200),
        'internal' => !empty($r['internal']),

        'demand_default'    => max(1, min(99, (int)($r['demand_default'] ?? 2))),
        'demand_min'        => max(0, min(99, (int)($r['demand_min'] ?? 1))),
        'demand_by_weekday' => $dbw,
        'special_days'      => array_values($special),

        'req_os_level'    => mb_substr(trim((string)($r['req_os_level'] ?? '')), 0, 20),
        'req_os_count'    => max(0, min(99, (int)($r['req_os_count'] ?? 0))),
        'req_professions' => array_slice(array_map(function ($p) { return mb_substr(trim((string)$p), 0, 20); },
                                (array)($r['req_professions'] ?? [])), 0, 10),
        'req_home_count'  => max(0, min(99, (int)($r['req_home_count'] ?? 0))),
        /* Stammbereich: egal / bevorzugen / ausschließlich */
        'home_mode'       => in_array($r['home_mode'] ?? 'prefer', ['off', 'prefer', 'only'], true)
                             ? ($r['home_mode'] ?? 'prefer') : 'prefer',
        /* Kundenerfahrung der letzten Monate mitgewichten */
        'use_experience'  => !isset($r['use_experience']) || !empty($r['use_experience']),
        /* Allgemein-Bereiche, die als frei verplanbar gelten (z. B. Technik) */
        'free_areas'      => array_slice(array_values(array_unique(array_map('intval',
                                array_key_exists('free_areas', $r) ? (array)$r['free_areas']
                                                                   : ($defaults['free_areas'] ?? [])))), 0, 30),
        /* Kundenbindung achten: wer fast immer beim selben Kunden ist, bleibt dort */
        'respect_bond'    => !isset($r['respect_bond']) || !empty($r['respect_bond']),
        'cross_team'      => !empty($r['cross_team']),
        /* Innendienst-Tage aktiv auflösen, statt nur zu dulden */
        'prefer_free'     => !isset($r['prefer_free']) || !empty($r['prefer_free']),
        /* Einzeln per Klick freigegebene Zellen: "userId|YYYY-MM-DD" */
        'force_cells'     => array_slice(array_values(array_unique(array_filter(
                                array_map('strval', (array)($r['force_cells'] ?? [])),
                                function ($k) { return (bool)preg_match('/^\d+\|\d{4}-\d{2}-\d{2}$/', $k); }
                             ))), 0, 2000),

        'roles'         => array_slice(array_map('strval', (array)($r['roles'] ?? [])), 0, 40),
        'include_users' => array_slice(array_map('intval', (array)($r['include_users'] ?? [])), 0, 200),
        'exclude_users' => array_slice(array_map('intval', (array)($r['exclude_users'] ?? [])), 0, 200),

        'azubi' => [
            'enabled'           => !empty($az['enabled']),
            'years'             => $years ?: [1, 2, 3],
            'min_per_day'       => max(0, min(20, (int)($az['min_per_day'] ?? 0))),
            'max_per_day'       => max(0, min(20, (int)($az['max_per_day'] ?? 1))),
            'max_share'         => max(0, min(100, (int)($az['max_share'] ?? 50))),
            'supervision_ratio' => max(1, min(10, (int)($az['supervision_ratio'] ?? 2))),
            'never_alone'       => !isset($az['never_alone']) || !empty($az['never_alone']),
            'skip_school_days'  => !isset($az['skip_school_days']) || !empty($az['skip_school_days']),
            'no_weekend'        => !isset($az['no_weekend']) || !empty($az['no_weekend']),
        ],
        'rules' => [
            'max_consecutive' => max(1, min(60, (int)($ru['max_consecutive'] ?? 10))),
            'max_days_total'  => max(1, min(120, (int)($ru['max_days_total'] ?? 20))),
            'weekend_flag'    => !isset($ru['weekend_flag']) || !empty($ru['weekend_flag']),
            'overwrite'       => $ow,
        ],
        'weights' => [
            'continuity' => max(0, min(100, (int)($we['continuity'] ?? 70))),
            'fairness'   => max(0, min(100, (int)($we['fairness']   ?? 50))),
            'home_area'  => max(0, min(100, (int)($we['home_area']  ?? 40))),
            'os'         => max(0, min(100, (int)($we['os']         ?? 30))),
        ],
    ];
}

/** Abwesenheitsarten → Anzeige (wie Monatsplanung). */
const EPA_ABS_LABEL = [
    'krank_halb' => 'Krank ½', 'krank_ganz' => 'Krank', 'krank_attest' => 'Krank (Attest)',
    'urlaub_halb' => 'Urlaub ½', 'urlaub_ganz' => 'Urlaub', 'urlaub_genehmigt' => 'Urlaub',
    'gleitzeit' => 'Gleitzeit', 'gesperrt' => 'Gesperrt', 'offen' => 'Offen',
];

/** Kalendertage des Zeitraums mit Bedarf und Ausnahmegrund (ohne DB). */
function epa_days(array $cfg, array $holidays): array
{
    $special = [];
    foreach ($cfg['special_days'] as $sd) $special[$sd['date']] = $sd;

    $days = [];
    $cur  = strtotime($cfg['start']);
    $last = strtotime($cfg['end']);
    while ($cur <= $last) {
        $d  = date('Y-m-d', $cur);
        $wd = (int)date('N', $cur);
        $cur = strtotime('+1 day', $cur);

        $isHoliday = isset($holidays[$d]) && $holidays[$d]['holiday_type'] === 'feiertag';
        $inWeek    = in_array($wd, $cfg['weekdays'], true);
        $req = $cfg['demand_by_weekday'][$wd] ?? $cfg['demand_default'];
        $skip = false; $reason = '';

        if (isset($special[$d])) {
            $req  = $special[$d]['count'];
            $skip = ($req === 0);
            $reason = $skip ? 'Sondertag ohne Bedarf' : '';
        } elseif (!$inWeek) {
            $skip = true; $reason = 'Wochentag nicht aktiv';
        } elseif ($isHoliday && $cfg['skip_holidays']) {
            $skip = true; $reason = 'Feiertag: ' . $holidays[$d]['name'];
        } elseif ($req === 0) {
            $skip = true; $reason = 'Kein Bedarf';
        }

        $days[] = [
            'date'     => $d,
            'wd'       => $wd,
            'label'    => ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][$wd] . ' ' . date('d.m.', strtotime($d)),
            'weekend'  => $wd >= 6,
            'holiday'  => isset($holidays[$d]) ? $holidays[$d]['name'] : null,
            'htype'    => isset($holidays[$d]) ? $holidays[$d]['holiday_type'] : null,
            'required' => $skip ? 0 : max(0, $req),
            'skip'     => $skip,
            'reason'   => $reason,
            'note'     => $special[$d]['note'] ?? '',
        ];
    }
    return $days;
}

/**
 * Lädt Mitarbeiter, Belegung und Feiertage für den Zeitraum.
 * Ergebnis wird von Vorschlag, Probelauf und Übernahme gleichermaßen genutzt.
 */
function epa_context(PDO $db, array $cfg, ?array $scope, array $defaults = [], int $me = 0): array
{
    /* --- Rollen --- */
    $customRoles = [];
    try {
        foreach ($db->query('SELECT role_key, role_label, color, base_role, sort_order FROM custom_roles')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $customRoles[$r['role_key']] = $r;
        }
    } catch (Exception $e) { /* optional */ }

    /* --- Bereiche --- */
    $areas = [];
    foreach ($db->query('SELECT id, name, short_code, color, fg_color, area_type FROM plan_areas ORDER BY area_type, sort_order, name')->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $areas[(int)$a['id']] = $a;
    }
    $schoolAreaIds = [];
    foreach ($areas as $id => $a) {
        if (strtoupper($a['short_code']) === 'BS') $schoolAreaIds[$id] = true;
    }

    /* --- Mitarbeiter --- */
    $sql = 'SELECT id, first_name, last_name, role, os_level, training_profession,
                   primary_area_id, weekend_allowed, entry_date, exit_date
              FROM users WHERE active = 1 AND role <> \'admin\'';
    $par = [];
    if ($scope !== null) {
        if (!$scope) return ['people' => [], 'areas' => $areas, 'days' => [], 'state' => [], 'holidays' => [], 'school' => $schoolAreaIds, 'roles' => $customRoles];
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $par  = $scope;
    }
    $sql .= ' ORDER BY last_name, first_name';
    $st = $db->prepare($sql); $st->execute($par);

    $people = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $uid = (int)$u['id'];
        $year = epa_azubiYear($u['role'], $customRoles);
        $people[$uid] = [
            'id'         => $uid,
            'name'       => $u['last_name'] . ', ' . $u['first_name'],
            'short'      => mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1),
            'role'       => $u['role'],
            'role_label' => $customRoles[$u['role']]['role_label'] ?? ucfirst(str_replace('_', ' ', $u['role'])),
            'role_color' => $customRoles[$u['role']]['color'] ?? '#6b7280',
            'azubi_year' => $year,
            'os'         => (string)$u['os_level'],
            'os_rank'    => epa_osRank($u['os_level']),
            'prof'       => (string)$u['training_profession'],
            'area_id'    => $u['primary_area_id'] !== null ? (int)$u['primary_area_id'] : 0,
            'area_code'  => isset($areas[(int)$u['primary_area_id']]) ? $areas[(int)$u['primary_area_id']]['short_code'] : '',
            'weekend'    => (int)$u['weekend_allowed'] === 1,
            'entry'      => $u['entry_date'],
            'exit'       => $u['exit_date'],
            'bond'       => 0.0,      // Anteil des häufigsten Kunden (0..1)
            'bond_area'  => 0,        // dieser Kunde
            'bond_code'  => '',
            'variety'    => 0,        // Zahl verschiedener Kunden
            'exp'        => 0,        // Einsätze im gewählten Bereich (12 Monate)
            'exp_last'   => null,
            'home'       => $cfg['area_id'] && (int)$u['primary_area_id'] === (int)$cfg['area_id'],
        ];
    }

    /* --- Kundenerfahrung: Einsätze im gewählten Bereich, letzte N Monate --- */
    if ($cfg['area_id'] && $people) {
        $ids = array_keys($people);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $q = $db->prepare("SELECT user_id, COUNT(*) c, MAX(plan_date) last_date
                                 FROM assignments
                                WHERE is_published = 1 AND plan_area_id = ?
                                  AND plan_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                                  AND user_id IN ($in)
                                GROUP BY user_id");
            $q->execute(array_merge([(int)$cfg['area_id'], (int)($defaults['exp_months'] ?? 12)], $ids));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $uid = (int)$r['user_id'];
                if (!isset($people[$uid])) continue;
                $people[$uid]['exp']      = (int)$r['c'];
                $people[$uid]['exp_last'] = $r['last_date'];
            }
        } catch (Exception $e) { /* Erfahrung ist optional */ }
    }

    /* --- Kundenbindung: Wie stark hängt jemand an einem einzelnen Kunden? ----
       Wer fast immer beim selben Kunden ist, wird ungern abgezogen; wer ohnehin
       oft wechselt, lässt sich leichter umplanen. Nur Kundenbereiche zählen. */
    if ($people) {
        $ids = array_keys($people);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $q = $db->prepare("SELECT a.user_id, a.plan_area_id, COUNT(*) c
                                 FROM assignments a
                                 JOIN plan_areas pa ON pa.id = a.plan_area_id AND pa.area_type = 'kunde'
                                WHERE a.is_published = 1
                                  AND a.plan_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                                  AND a.plan_date <  CURDATE()
                                  AND a.user_id IN ($in)
                                GROUP BY a.user_id, a.plan_area_id");
            $q->execute(array_merge([(int)($defaults['bond_months'] ?? 6)], $ids));
            $agg = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $agg[(int)$r['user_id']][(int)$r['plan_area_id']] = (int)$r['c'];
            }
            foreach ($agg as $uid => $byArea) {
                if (!isset($people[$uid])) continue;
                $sum = array_sum($byArea);
                if ($sum < 5) continue;               // zu wenig Historie für eine Aussage
                arsort($byArea);
                $topId = (int)array_key_first($byArea);
                $people[$uid]['bond']      = round(reset($byArea) / $sum, 3);
                $people[$uid]['bond_area'] = $topId;
                $people[$uid]['bond_code'] = $areas[$topId]['short_code'] ?? '';
                $people[$uid]['variety']   = count($byArea);
            }
        } catch (Exception $e) { /* Historie ist optional */ }
    }

    /* --- Tage --- */
    $holidays = [];
    $h = $db->prepare('SELECT holiday_date, name, holiday_type FROM holidays WHERE holiday_date BETWEEN ? AND ?');
    $h->execute([$cfg['start'], $cfg['end']]);
    foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $row) $holidays[$row['holiday_date']] = $row;
    $days = epa_days($cfg, $holidays);

    /* --- Belegung: veröffentlicht + Entwürfe --- */
    $state = [];   // $state[uid][date] = ['t'=>..., 'l'=>Label, 'c'=>Farbe]
    $ids   = array_keys($people);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));

        $q = $db->prepare("SELECT user_id, plan_date, plan_area_id FROM assignments
                            WHERE is_published = 1 AND plan_date BETWEEN ? AND ? AND user_id IN ($in)");
        $q->execute(array_merge([$cfg['start'], $cfg['end']], $ids));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $aid = (int)$r['plan_area_id'];
            $state[(int)$r['user_id']][$r['plan_date']] = [
                't' => isset($schoolAreaIds[$aid]) ? 'schule' : 'einsatz',
                'l' => $areas[$aid]['short_code'] ?? '?',
                'c' => $areas[$aid]['color'] ?? '#6b7280',
                'f' => $areas[$aid]['fg_color'] ?? '#ffffff',
                'a' => $aid,
            ];
        }

        $q = $db->prepare("SELECT user_id, absence_date, absence_type FROM absences
                            WHERE is_published = 1 AND absence_date BETWEEN ? AND ? AND user_id IN ($in)");
        $q->execute(array_merge([$cfg['start'], $cfg['end']], $ids));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = $r['absence_type'];
            $state[(int)$r['user_id']][$r['absence_date']] = [
                't' => $t === 'gesperrt' ? 'gesperrt' : ($t === 'offen' ? 'offen' : 'abwesend'),
                'l' => epa_absLabel($t, EPA_ABS_LABEL, (int)$r['user_id']),
                'c' => '#dc2626', 'f' => '#ffffff',
                'a' => 0,
            ];
        }

        try {
            $q = $db->prepare("SELECT user_id, plan_date, action, change_type, change_value, created_by, planning_run_id FROM plan_drafts
                                WHERE plan_date BETWEEN ? AND ? AND user_id IN ($in)");
            $q->execute(array_merge([$cfg['start'], $cfg['end']], $ids));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $uid = (int)$r['user_id']; $d = $r['plan_date'];
                if ($r['action'] === 'clear') { unset($state[$uid][$d]); continue; }
                /* Offener Entwurf eines anderen Planers (nicht aus dem Assistenten):
                   wird bei der Übernahme nicht überschrieben – der Browser zeigt es an. */
                $foreign = $me > 0 && (int)$r['created_by'] !== $me && (int)($r['planning_run_id'] ?? 0) === 0;
                if ($r['change_type'] === 'assignment') {
                    $aid = (int)$r['change_value'];
                    $state[$uid][$d] = [
                        't' => isset($schoolAreaIds[$aid]) ? 'schule' : 'einsatz',
                        'l' => ($areas[$aid]['short_code'] ?? '?') . ' •',
                        'c' => $areas[$aid]['color'] ?? '#6b7280',
                        'f' => $areas[$aid]['fg_color'] ?? '#ffffff',
                        'a' => $aid,
                    ];
                } else {
                    $v = (string)$r['change_value'];
                    $state[$uid][$d] = [
                        't' => ($v === 'gesperrt' || $r['change_type'] === 'locked') ? 'gesperrt' : 'abwesend',
                        'l' => epa_absLabel($v, EPA_ABS_LABEL, (int)$uid) . ' •',
                        'c' => '#dc2626', 'f' => '#ffffff',
                        'a' => 0,
                    ];
                }
                if ($foreign) $state[$uid][$d]['o'] = 1;
            }
        } catch (Exception $e) { /* plan_drafts optional */ }
    }

    return [
        'people'   => $people,
        'areas'    => $areas,
        'days'     => $days,
        'state'    => $state,
        'holidays' => $holidays,
        'school'   => $schoolAreaIds,
        'roles'    => $customRoles,
    ];
}

/** Prüft, ob eine Person an einem Tag grundsätzlich einsetzbar ist. */
function epa_availability(array $p, array $day, array $cfg, array $ctx, bool $mayAbsence): array
{
    $d = $day['date'];
    if ($p['entry'] && $d < $p['entry']) return ['ok' => false, 'code' => 'eintritt',  'txt' => 'noch nicht im Unternehmen'];
    if ($p['exit']  && $d > $p['exit'])  return ['ok' => false, 'code' => 'austritt',  'txt' => 'bereits ausgetreten'];

    if ($day['weekend'] && $cfg['rules']['weekend_flag'] && !$p['weekend']) {
        return ['ok' => false, 'code' => 'wochenende', 'txt' => 'nicht für Wochenenddienst freigegeben'];
    }
    if ($p['azubi_year'] > 0) {
        $az = $cfg['azubi'];
        if (!$az['enabled'])                            return ['ok' => false, 'code' => 'azubi_aus',  'txt' => 'Azubis sind nicht einbezogen'];
        if (!in_array($p['azubi_year'], $az['years']))  return ['ok' => false, 'code' => 'lehrjahr',   'txt' => $p['azubi_year'] . '. Lehrjahr nicht ausgewählt'];
        if ($az['no_weekend'] && $day['weekend'])       return ['ok' => false, 'code' => 'azubi_we',   'txt' => 'Azubis ohne Wochenenddienst'];
    }

    $st = $ctx['state'][$p['id']][$d] ?? null;
    if ($st === null) return ['ok' => true, 'code' => 'frei', 'txt' => 'frei'];

    /* Einzelfreigabe per Klick – gilt nie für Sperrtag und Berufsschule. */
    $forced = in_array($p['id'] . '|' . $d, $cfg['force_cells'], true);

    switch ($st['t']) {
        case 'schule':
            return ['ok' => false, 'code' => 'schule', 'txt' => 'Berufsschule'];
        case 'gesperrt':
            return ['ok' => false, 'code' => 'gesperrt', 'txt' => 'Sperrtag'];
        case 'abwesend':
            if (($cfg['rules']['overwrite'] === 'all' || $forced) && $mayAbsence) {
                return ['ok' => true, 'code' => 'ueberschreibt_abw', 'txt' => 'überschreibt: ' . $st['l']];
            }
            return ['ok' => false, 'code' => 'abwesend', 'txt' => $st['l']];
        case 'einsatz':
            if ((int)($st['a'] ?? 0) === (int)$cfg['area_id']) {
                return ['ok' => true, 'code' => 'bereits', 'txt' => 'bereits für diesen Bereich geplant'];
            }
            /* Innendienst-Tage (z. B. Technik) gelten als verfügbar. */
            if (in_array((int)($st['a'] ?? 0), $cfg['free_areas'], true)) {
                return ['ok' => true, 'code' => 'frei_intern', 'txt' => 'verplanbar – ersetzt ' . $st['l']];
            }
            if ($forced || in_array($cfg['rules']['overwrite'], ['assignments', 'all'], true)) {
                return ['ok' => true, 'code' => 'ueberschreibt', 'txt' => 'überschreibt: ' . $st['l']];
            }
            return ['ok' => false, 'code' => 'belegt', 'txt' => 'bereits geplant: ' . $st['l']];
        case 'offen':
            return ['ok' => true, 'code' => 'offen', 'txt' => 'ersetzt offenen Tag'];
    }
    return ['ok' => true, 'code' => 'frei', 'txt' => 'frei'];
}

/** Kernstück: erzeugt den Planungsvorschlag (reine Funktion, keine DB). */
function epa_generate(array $cfg, array $ctx, bool $mayAbsence): array
{
    $people  = $ctx['people'];
    $days    = $ctx['days'];
    $w       = $cfg['weights'];
    $az      = $cfg['azubi'];
    $rules   = $cfg['rules'];
    $include = array_flip($cfg['include_users']);
    $exclude = array_flip($cfg['exclude_users']);
    $roleOk  = $cfg['roles'] ? array_flip($cfg['roles']) : null;

    /* --- Kandidatenpool --- */
    $pool = [];
    foreach ($people as $uid => $p) {
        if (isset($exclude[$uid])) continue;
        if (!isset($include[$uid])) {
            if ($roleOk !== null && !isset($roleOk[$p['role']])) continue;
            if ($p['azubi_year'] > 0 && !$az['enabled']) continue;
            /* „Ausschließlich Stammbereich“: nur Leute, die den Kunden ohnehin betreuen.
               Wer erkennbar Erfahrung dort hat, zählt ebenfalls dazu. */
            if ($cfg['home_mode'] === 'only' && $cfg['area_id']
                && !$p['home'] && $p['exp'] < 1) continue;
        }
        $pool[$uid] = $p;
    }

    $plan      = [];   // [date] => [uid,...]
    $conflicts = [];
    $count     = [];   // uid => Anzahl Tage im Lauf
    $streak    = [];   // uid => aktuelle Serie
    $lastDay   = [];   // uid => letztes geplantes Datum
    $maxStreak = [];
    foreach ($pool as $uid => $p) { $count[$uid] = 0; $streak[$uid] = 0; $lastDay[$uid] = null; $maxStreak[$uid] = 0; }

    $maxTotal = $rules['max_days_total'];
    $slotsTotal = 0; $slotsFilled = 0;

    foreach ($days as $di => $day) {
        $plan[$day['date']] = [];
        if ($day['skip']) {
            if ($day['holiday'] && $cfg['skip_holidays'] && in_array($day['wd'], $cfg['weekdays'], true)) {
                $conflicts[] = ['level' => 'info', 'date' => $day['date'], 'user_id' => 0,
                    'code' => 'feiertag', 'msg' => $day['label'] . ' übersprungen – ' . $day['reason']];
            }
            continue;
        }
        $need = $day['required'];
        $slotsTotal += $need;
        $prev = $di > 0 ? $days[$di - 1]['date'] : null;

        /* --- Bewertung aller verfügbaren Kandidaten --- */
        $cands = [];
        $limited_pool = [];   // nur an den Belastungsgrenzen gescheitert – Notnagel für offene Plätze
        foreach ($pool as $uid => $p) {
            $av = epa_availability($p, $day, $cfg, $ctx, $mayAbsence);
            if (!$av['ok']) continue;
            $overTotal  = ($count[$uid] >= $maxTotal && !isset($include[$uid]));
            $overStreak = ($prev !== null && in_array($uid, $plan[$prev] ?? [], true)
                           && $streak[$uid] >= $rules['max_consecutive'] && !isset($include[$uid]));
            $limited    = $overTotal || $overStreak;

            $score = 0.0;
            if ($count[$uid] > 0)                              $score += $w['continuity'] * 1.0;
            if ($prev !== null && in_array($uid, $plan[$prev] ?? [], true)) $score += $w['continuity'] * 0.6;
            /* Stammbereich: Wer den Kunden ohnehin betreut, hat Vorrang. */
            if ($cfg['area_id'] && $p['home']) {
                $score += $w['home_area'] * ($cfg['home_mode'] === 'off' ? 0.0 : 1.6);
            }
            /* Kundenbindung: Wer fast ausschließlich bei einem anderen Kunden ist, wird
               ungern abgezogen. Wer ohnehin viel wechselt, ist der bessere Kandidat. */
            if ($cfg['respect_bond'] && $p['bond'] > 0 && $p['bond_area'] !== (int)$cfg['area_id']) {
                if ($p['bond'] >= 0.6) {
                    $score -= $w['home_area'] * 1.4 * $p['bond'];
                } elseif ($p['variety'] >= 3) {
                    $score += $w['home_area'] * 0.35;      // wechselt viel – gut umplanbar
                }
            }
            /* Kundenerfahrung: gedämpft, damit ein einzelner Vielflieger nicht alles bekommt. */
            if ($cfg['use_experience'] && $p['exp'] > 0) {
                $score += $w['home_area'] * 0.5 * min(1.0, log(1 + $p['exp']) / log(13));
            }
            $score += $w['fairness'] * (1 - min(1, $count[$uid] / max(1, $maxTotal)));
            if ($cfg['req_os_level'] !== '' && $p['os_rank'] >= epa_osRank($cfg['req_os_level'])) $score += $w['os'];
            if ($cfg['req_professions'] && in_array($p['prof'], $cfg['req_professions'], true))   $score += $w['os'] * 0.5;
            if (isset($include[$uid]))                        $score += 10000;      // fest eingeplant
            if ($av['code'] === 'bereits')                    $score += $w['continuity'] * 0.8;
            if ($av['code'] === 'ueberschreibt' || $av['code'] === 'ueberschreibt_abw') $score -= 40;
            if ($p['azubi_year'] > 0)                         $score -= 5;          // leichter Vorrang für Fachkräfte

            /* Innendienst-Tage sind freie Kapazität – solche Leute lassen sich am
               leichtesten umplanen und werden deshalb bevorzugt herangezogen. */
            if (($av['code'] ?? '') === 'frei_intern') {
                /* Ist die Vorauswahl aktiv, geht ein solcher Tag klar vor einem
                   ohnehin unverplanten Kollegen – der bleibt als Reserve. */
                $score += $cfg['prefer_free'] ? ($w['continuity'] + 40) : ($w['home_area'] * 0.45);
            }

            $entry = ['uid' => $uid, 'score' => $score, 'azubi' => $p['azubi_year'] > 0, 'av' => $av,
                      'limit' => $limited ? ($overTotal ? 'Tagesgrenze' : 'Serie') : ''];
            if ($limited) $limited_pool[] = $entry; else $cands[] = $entry;
        }
        usort($cands, function ($a, $b) { return $b['score'] <=> $a['score']; });

        /* --- Auswahl unter Beachtung der Azubi-Regeln --- */
        $capAzubi = $az['enabled'] ? min($az['max_per_day'], (int)floor($need * $az['max_share'] / 100)) : 0;
        $picked = []; $nAz = 0; $nTech = 0; $taken = [];

        /* Reservierte Ausbildungsplätze zuerst besetzen, damit Azubis bei hoher
           Kontinuitätsgewichtung nicht dauerhaft außen vor bleiben. */
        $reserve = $az['enabled'] ? min($az['min_per_day'], $capAzubi, $need) : 0;
        if ($reserve > 0) {
            foreach ($cands as $c) {
                if ($nAz >= $reserve) break;
                if (!$c['azubi']) continue;
                $picked[] = $c; $taken[$c['uid']] = true; $nAz++;
            }
            if ($nAz < $reserve) {
                $conflicts[] = ['level' => 'info', 'date' => $day['date'], 'user_id' => 0, 'code' => 'azubi_platz',
                    'msg' => $day['label'] . ': ' . ($reserve - $nAz) . ' reservierte(r) Ausbildungsplatz/-plätze unbesetzt – kein passender Azubi verfügbar.'];
            }
        }

        /* Vorgabe „mindestens N aus dem Stammbereich“ aktiv besetzen. */
        if ($cfg['req_home_count'] > 0 && $cfg['area_id']) {
            $needHome = min($cfg['req_home_count'], $need);
            $haveHome = 0;
            foreach ($picked as $c) if (!empty($people[$c['uid']]['home'])) $haveHome++;
            foreach ($cands as $c) {
                if ($haveHome >= $needHome || count($picked) >= $need) break;
                if (isset($taken[$c['uid']])) continue;
                if (empty($people[$c['uid']]['home'])) continue;
                if ($c['azubi']) { if ($nAz >= $capAzubi) continue; $nAz++; } else { $nTech++; }
                $picked[] = $c; $taken[$c['uid']] = true; $haveHome++;
            }
        }

        foreach ($cands as $c) {
            if (isset($taken[$c['uid']])) continue;
            if (count($picked) >= $need) break;
            if ($c['azubi']) {
                if ($nAz >= $capAzubi) continue;
                $nAz++;
            } else {
                $nTech++;
            }
            $picked[] = $c; $taken[$c['uid']] = true;
        }

        /* --- Zweiter Durchgang: Rest des Bedarfs decken ---------------------
           Bleiben Plätze offen, obwohl noch Leute könnten, werden die zuvor
           an den Belastungsgrenzen zurückgestellten Personen herangezogen –
           offen als Hinweis ausgewiesen, nicht stillschweigend. */
        if (count($picked) < $need && $limited_pool) {
            usort($limited_pool, function ($a, $b) { return $b['score'] <=> $a['score']; });
            foreach ($limited_pool as $c) {
                if (count($picked) >= $need) break;
                if (isset($taken[$c['uid']])) continue;
                if ($c['azubi']) { if ($nAz >= $capAzubi) continue; $nAz++; } else { $nTech++; }
                $picked[] = $c; $taken[$c['uid']] = true;
                $conflicts[] = ['level' => 'info', 'date' => $day['date'], 'user_id' => $c['uid'],
                    'code' => 'grenze_gelockert',
                    'msg' => $people[$c['uid']]['name'] . ' am ' . $day['label'] . ' eingeplant, um den Bedarf zu decken – '
                           . ($c['limit'] === 'Serie' ? 'überschreitet die Tage am Stück.' : 'überschreitet die Tage im Zeitraum.')];
            }
        }

        /* --- Dritter Durchgang: Restlücken gezielt schließen -------------------
           Fehlt nur eine Fachkraft, damit ein Azubi mitkann, wird sie bevorzugt
           geholt. Danach zählt nur noch, dass der Tag besetzt ist. */
        if (count($picked) < $need) {
            $rest = array_merge($cands, $limited_pool);
            usort($rest, function ($a, $b) { return $b['score'] <=> $a['score']; });
            foreach ($rest as $c) {
                if (count($picked) >= $need) break;
                if (isset($taken[$c['uid']])) continue;
                if ($c['azubi']) continue;                       // Fachkräfte zuerst
                $picked[] = $c; $taken[$c['uid']] = true; $nTech++;
            }
            foreach ($rest as $c) {
                if (count($picked) >= $need) break;
                if (isset($taken[$c['uid']])) continue;
                if ($c['azubi'] && $nAz >= $capAzubi) continue;
                if ($c['azubi']) $nAz++; else $nTech++;
                $picked[] = $c; $taken[$c['uid']] = true;
            }
        }

        /* Betreuungsschlüssel durchsetzen: überzählige Azubis wieder entfernen. */
        $limit = ($nTech === 0 && !$az['never_alone']) ? $az['max_per_day'] : $nTech * $az['supervision_ratio'];
        $dropped = 0;
        while ($nAz > $limit) {
            $hit = false;
            for ($i = count($picked) - 1; $i >= 0; $i--) {
                if ($picked[$i]['azubi'] && !isset($include[$picked[$i]['uid']])) {
                    unset($taken[$picked[$i]['uid']]);
                    array_splice($picked, $i, 1);
                    $nAz--; $dropped++; $hit = true; break;
                }
            }
            if (!$hit) break;   // nur noch fest eingeplante Azubis übrig
        }

        /* Frei gewordene Plätze mit Fachkräften auffüllen. */
        if (count($picked) < $need) {
            foreach ($cands as $c) {
                if (count($picked) >= $need) break;
                if ($c['azubi'] || isset($taken[$c['uid']])) continue;
                $picked[] = $c; $taken[$c['uid']] = true; $nTech++;
            }
        }

        if ($nAz < $reserve && $dropped > 0) {
            $conflicts[] = ['level' => 'warn', 'date' => $day['date'], 'user_id' => 0, 'code' => 'azubi_platz',
                'msg' => $day['label'] . ': reservierte Ausbildungsplätze mussten frei bleiben – der Betreuungsschlüssel 1:' . $az['supervision_ratio'] . ' lässt nicht mehr Azubis zu.'];
        }
        if ($dropped > 0 && count($picked) < $need) {
            $conflicts[] = ['level' => 'warn', 'date' => $day['date'], 'user_id' => 0, 'code' => 'betreuung',
                'msg' => $day['label'] . ': ' . $dropped . ' Azubi-Platz/Plätze bleiben frei – zu wenige Fachkräfte für den Schlüssel 1:' . $az['supervision_ratio'] . '.'];
        }
        if ($az['never_alone'] && $nTech === 0 && $nAz > 0) {
            $conflicts[] = ['level' => 'block', 'date' => $day['date'], 'user_id' => 0, 'code' => 'azubi_allein',
                'msg' => $day['label'] . ': Azubis wären ohne Fachkraft vor Ort.'];
        }
        if ($nAz > $nTech * $az['supervision_ratio']) {
            $conflicts[] = ['level' => 'warn', 'date' => $day['date'], 'user_id' => 0, 'code' => 'betreuung',
                'msg' => $day['label'] . ': Betreuungsschlüssel 1:' . $az['supervision_ratio'] . ' nicht eingehalten (' . $nTech . ' Fachkräfte, ' . $nAz . ' Azubis).'];
        }

        /* --- Fortschreiben --- */
        foreach ($picked as $c) {
            $uid = $c['uid'];
            $plan[$day['date']][] = $uid;
            $count[$uid]++;
            $streak[$uid] = ($prev !== null && $lastDay[$uid] === $prev) ? $streak[$uid] + 1 : 1;
            if ($streak[$uid] > $maxStreak[$uid]) $maxStreak[$uid] = $streak[$uid];
            $lastDay[$uid] = $day['date'];
            if ($c['av']['code'] === 'ueberschreibt' || $c['av']['code'] === 'ueberschreibt_abw') {
                $conflicts[] = ['level' => 'info', 'date' => $day['date'], 'user_id' => $uid, 'code' => 'ueberschreibt',
                    'msg' => $people[$uid]['name'] . ' am ' . $day['label'] . ' – ' . $c['av']['txt']];
            }
        }
        $slotsFilled += count($picked);

        /* --- Unterdeckung --- */
        $gap = $need - count($picked);
        if ($gap > 0) {
            $lvl = count($picked) < $cfg['demand_min'] ? 'block' : 'warn';
            $conflicts[] = ['level' => $lvl, 'date' => $day['date'], 'user_id' => 0, 'code' => 'unterdeckung',
                'msg' => $day['label'] . ': ' . count($picked) . ' von ' . $need . ' Plätzen besetzt (' . $gap . ' offen).'];
        }

        /* --- Fachliche Mindestanforderungen --- */
        if ($cfg['req_os_count'] > 0 && $cfg['req_os_level'] !== '') {
            $need_rank = epa_osRank($cfg['req_os_level']); $have = 0;
            foreach ($plan[$day['date']] as $uid) if ($people[$uid]['os_rank'] >= $need_rank) $have++;
            if ($have < $cfg['req_os_count']) {
                $conflicts[] = ['level' => 'warn', 'date' => $day['date'], 'user_id' => 0, 'code' => 'os_level',
                    'msg' => $day['label'] . ': nur ' . $have . ' von ' . $cfg['req_os_count'] . ' Personen mit ' . $cfg['req_os_level'] . '.'];
            }
        }
        if ($cfg['req_home_count'] > 0 && $cfg['area_id']) {
            $have = 0;
            foreach ($plan[$day['date']] as $uid) if ($people[$uid]['area_id'] === (int)$cfg['area_id']) $have++;
            if ($have < $cfg['req_home_count']) {
                $conflicts[] = ['level' => 'warn', 'date' => $day['date'], 'user_id' => 0, 'code' => 'stammbereich',
                    'msg' => $day['label'] . ': nur ' . $have . ' von ' . $cfg['req_home_count'] . ' Personen mit diesem Stammbereich.'];
            }
        }
    }

    /* --- Fest eingeplante Personen, die nicht durchgängig konnten --- */
    foreach ($cfg['include_users'] as $uid) {
        if (!isset($people[$uid])) continue;
        foreach ($days as $day) {
            if ($day['skip']) continue;
            if (in_array($uid, $plan[$day['date']], true)) continue;
            $av = epa_availability($people[$uid], $day, $cfg, $ctx, $mayAbsence);
            $conflicts[] = ['level' => 'block', 'date' => $day['date'], 'user_id' => $uid, 'code' => 'fix_blockiert',
                'msg' => $people[$uid]['name'] . ' ist fest eingeplant, am ' . $day['label'] . ' aber nicht verfügbar (' . $av['txt'] . ').'];
        }
    }

    /* --- Grenzwerte: fest eingeplante Personen dürfen sie überschreiten --- */
    foreach ($count as $uid => $n) {
        if ($n > $rules['max_days_total']) {
            $conflicts[] = ['level' => 'warn', 'date' => '', 'user_id' => $uid, 'code' => 'max_tage',
                'msg' => $people[$uid]['name'] . ': ' . $n . ' Tage im Zeitraum (Grenze ' . $rules['max_days_total'] . ') – als fest eingeplant übersteuert.'];
        }
        if ($maxStreak[$uid] > $rules['max_consecutive']) {
            $conflicts[] = ['level' => 'warn', 'date' => '', 'user_id' => $uid, 'code' => 'max_serie',
                'msg' => $people[$uid]['name'] . ': bis zu ' . $maxStreak[$uid] . ' Tage am Stück (Grenze ' . $rules['max_consecutive'] . ').'];
        }
    }

    /* --- Kennzahlen --- */
    $used = array_filter($count);
    arsort($used);
    $order = ['block' => 0, 'warn' => 1, 'info' => 2];
    usort($conflicts, function ($a, $b) use ($order) {
        return [$order[$a['level']], $a['date']] <=> [$order[$b['level']], $b['date']];
    });

    return [
        'plan'      => $plan,
        'conflicts' => $conflicts,
        'load'      => $count,
        'stats'     => [
            'slots_total'  => $slotsTotal,
            'slots_filled' => $slotsFilled,
            'coverage'     => $slotsTotal > 0 ? round($slotsFilled / $slotsTotal * 100) : 100,
            'people_used'  => count($used),
            'blocks'       => count(array_filter($conflicts, function ($c) { return $c['level'] === 'block'; })),
            'warns'        => count(array_filter($conflicts, function ($c) { return $c['level'] === 'warn'; })),
            'infos'        => count(array_filter($conflicts, function ($c) { return $c['level'] === 'info'; })),
        ],
    ];
}

/** Antwort für den Browser: Personen, Tage, Belegung, Vorschlag. */
function epa_payload(array $cfg, array $ctx, array $res): array
{
    $rows = [];
    $touched = [];
    foreach ($res['plan'] as $d => $uids) foreach ($uids as $uid) $touched[$uid] = true;

    foreach ($ctx['people'] as $uid => $p) {
        $inPlan = isset($touched[$uid]);
        $p['days'] = $ctx['state'][$uid] ?? new stdClass();
        $p['load'] = $res['load'][$uid] ?? 0;
        $p['in_plan'] = $inPlan;
        $rows[] = $p;
    }
    usort($rows, function ($a, $b) {
        if ($a['in_plan'] !== $b['in_plan']) return $a['in_plan'] ? -1 : 1;
        if ($a['load'] !== $b['load']) return $b['load'] <=> $a['load'];
        return strcmp($a['name'], $b['name']);
    });

    return [
        'days'      => $ctx['days'],
        'people'    => $rows,
        'plan'      => $res['plan'],
        'conflicts' => $res['conflicts'],
        'stats'     => $res['stats'],
        'area'      => $ctx['areas'][$cfg['area_id']] ?? null,
    ];
}

/** Gründe, aus denen eine Zelle bei der Übernahme übersprungen wird. */
const EPA_SKIP_REASONS = [
    'scope'           => 'Kein Planungsrecht für diese Person',
    'gesperrt'        => 'Sperrtag – wird nie überschrieben',
    'schule'          => 'Berufsschule – wird nie überschrieben',
    'abwesend'        => 'Geschützte Abwesenheit (Urlaub, Krankheit …)',
    'belegt'          => 'Bereits anders geplant – Überschreiben nicht freigegeben',
    'fremder_entwurf' => 'Offener Entwurf eines anderen Planers',
    'geaendert'       => 'Zelle wurde inzwischen geändert',
];

/**
 * Entscheidung für eine Zelle der Übernahme (ohne Schreibzugriff).
 * @return array{ok:bool, code:string, msg:string, kind:string}
 *   kind: neu | gleich | intern | ersetzt | abwesenheit | offen
 */
function epa_apply_decide(array $cfg, array $ctx, int $uid, string $date, ?array $cell, int $me, bool $mayAbsence,
                          ?string $expectRev = null, ?string $curRev = null): array
{
    $p = $ctx['people'][$uid] ?? null;
    $dm = date('d.m.', strtotime($date));
    if (!$p) return ['ok' => false, 'code' => 'scope', 'msg' => 'Kein Planungsrecht (#' . $uid . ')', 'kind' => ''];
    $name = $p['name'];

    $st = $ctx['state'][$uid][$date] ?? null;
    $forced = in_array($uid . '|' . $date, $cfg['force_cells'], true);
    $kind = 'neu';
    if ($st) {
        if ($st['t'] === 'gesperrt' || $st['t'] === 'schule') {
            return ['ok' => false, 'code' => $st['t'], 'msg' => $name . ' am ' . $dm . ': ' . $st['l'], 'kind' => ''];
        }
        if ($st['t'] === 'abwesend') {
            if (!((($cfg['rules']['overwrite'] === 'all') || $forced) && $mayAbsence)) {
                return ['ok' => false, 'code' => 'abwesend', 'msg' => $name . ' am ' . $dm . ': ' . $st['l'], 'kind' => ''];
            }
            $kind = 'abwesenheit';
        }
        /* Fremder Einsatz: nur ersetzen, wenn ausdrücklich erlaubt – über die
           Regel, als Innendienst-Bereich oder per Klick für genau diese Zelle. */
        if ($st['t'] === 'einsatz') {
            $a = (int)($st['a'] ?? 0);
            if ($a === (int)$cfg['area_id']) $kind = 'gleich';
            elseif (in_array($a, $cfg['free_areas'], true)) $kind = 'intern';
            elseif ($forced || in_array($cfg['rules']['overwrite'], ['assignments', 'all'], true)) $kind = 'ersetzt';
            else return ['ok' => false, 'code' => 'belegt', 'msg' => $name . ' am ' . $dm . ': bereits geplant (' . $st['l'] . ')', 'kind' => ''];
        }
        if ($st['t'] === 'offen') $kind = 'offen';
    }

    /* Entwürfe anderer Planer nicht stillschweigend überschreiben
       (wie api/save_bulk); Entwürfe aus dem Assistenten dürfen ersetzt werden. */
    if ($cell && $cell['draft'] && (int)$cell['draft']['created_by'] !== $me && !$forced
        && (int)($cell['draft']['planning_run_id'] ?? 0) === 0) {
        $dr = $cell['draft'];
        if ($dr['action'] === 'set' && $dr['change_type'] === 'assignment' && (int)$dr['change_value'] === (int)$cfg['area_id']) {
            /* Steht schon genau so im Entwurf – nichts zu tun, kein Konflikt. */
            return ['ok' => false, 'code' => 'unveraendert', 'msg' => $name . ' am ' . $dm . ': bereits so im Entwurf', 'kind' => ''];
        }
        return ['ok' => false, 'code' => 'fremder_entwurf', 'msg' => $name . ' am ' . $dm . ': offener Entwurf eines anderen Planers', 'kind' => ''];
    }
    /* Revision: Zelle seit dem Probelauf verändert? */
    if ($expectRev !== null && $curRev !== null && !hash_equals($curRev, $expectRev)) {
        return ['ok' => false, 'code' => 'geaendert', 'msg' => $name . ' am ' . $dm . ': inzwischen geändert – bitte Übernahme erneut prüfen', 'kind' => ''];
    }
    return ['ok' => true, 'code' => 'ok', 'msg' => '', 'kind' => $kind];
}

/**
 * Übernahme als Entwurf. Mit $dryRun = true wird nichts geschrieben: die
 * Antwort zeigt, was geschrieben und was übersprungen würde (inkl. Revisionen
 * der zu schreibenden Zellen für die anschließende echte Übernahme).
 *
 * $env: me, may_absence, may_internal; $opts: revs (uid|date => rev), plan_id
 */
function epa_apply(PDO $db, array $cfg, array $plan, array $ctx, array $env, bool $dryRun = false, array $opts = []): array
{
    $me = (int)$env['me'];
    $mayAbsence = !empty($env['may_absence']);
    $internal = ($cfg['internal'] && !empty($env['may_internal'])) ? 1 : 0;
    $dayIdx = [];
    foreach ($ctx['days'] as $d) $dayIdx[$d['date']] = $d;

    /* Paare einsammeln: nur Tage im Zeitraum, je Tag jede Person einmal */
    $pairs = []; $seen = [];
    foreach ($plan as $date => $uids) {
        if (!is_string($date) || !isset($dayIdx[$date])) continue;
        foreach ((array)$uids as $uid) {
            $uid = (int)$uid;
            $k = planCellKey($uid, $date);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $pairs[] = [$uid, $date];
        }
    }
    usort($pairs, function ($a, $b) { return [$a[1], $a[0]] <=> [$b[1], $b[0]]; });
    $revsIn = is_array($opts['revs'] ?? null) ? $opts['revs'] : [];
    $sourceId = (int)($opts['plan_id'] ?? 0);

    $written = 0; $unchanged = 0; $skipped = []; $items = []; $byReason = []; $kinds = [];
    $people = []; $evCells = []; $revsOut = [];
    $runId = 0;

    if (!$dryRun) {
        $db->beginTransaction();
        if ($sourceId > 0) {
            $s = $db->prepare('SELECT id FROM planning_runs WHERE id = ? AND is_saved = 1 AND (created_by = ? OR shared = 1)');
            $s->execute([$sourceId, $me]);
            if (!$s->fetchColumn()) $sourceId = 0;
        }
        $db->prepare('INSERT INTO planning_runs
            (title, plan_area_id, start_date, end_date, config_json, result_json, status,
             slots_total, slots_filled, conflicts, created_by, applied_at, source_run_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')
           ->execute([
               $cfg['title'], $cfg['area_id'], $cfg['start'], $cfg['end'],
               json_encode($cfg, JSON_UNESCAPED_UNICODE), json_encode($plan, JSON_UNESCAPED_UNICODE),
               'uebernommen', 0, 0, 0, $me, $sourceId ?: null,
           ]);
        $runId = (int)$db->lastInsertId();
    }

    $ins = $dryRun ? null : $db->prepare(
        'INSERT INTO plan_drafts
            (user_id, plan_date, year, month, action, change_type, change_value, remarks,
             is_internal, created_by, planning_run_id, created_at)
         VALUES (?,?,?,?,\'set\',\'assignment\',?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
            action = \'set\', change_type = \'assignment\', change_value = VALUES(change_value),
            remarks = VALUES(remarks), is_internal = VALUES(is_internal),
            created_by = VALUES(created_by), planning_run_id = VALUES(planning_run_id),
            created_at = NOW()'
    );

    /* Zellen der planbaren Personen laden – in der Übernahme gesperrt */
    $loadPairs = array_values(array_filter($pairs, function ($pr) use ($ctx) { return isset($ctx['people'][$pr[0]]); }));
    $cells = planCellLoad($db, $loadPairs, !$dryRun);

    foreach ($pairs as [$uid, $date]) {
        $k = planCellKey($uid, $date);
        $cell = $cells[$k] ?? null;
        $cur = $cell ? planCellRev($db, $cell) : null;
        $exp = isset($revsIn[$k]) && is_string($revsIn[$k]) ? $revsIn[$k] : null;
        $dec = epa_apply_decide($cfg, $ctx, $uid, $date, $cell, $me, $mayAbsence, $exp, $cur);
        if (!$dec['ok'] && $dec['code'] === 'unveraendert') { $unchanged++; continue; }
        if (!$dec['ok']) {
            $skipped[] = $dec['msg'];
            $items[] = ['user_id' => $uid, 'date' => $date, 'code' => $dec['code'], 'msg' => $dec['msg']];
            $byReason[$dec['code']] = ($byReason[$dec['code']] ?? 0) + 1;
            continue;
        }
        $kinds[$dec['kind']] = ($kinds[$dec['kind']] ?? 0) + 1;
        $people[$uid] = true;
        if ($dryRun) {
            $revsOut[$k] = $cur;
            $written++;
            continue;
        }
        planChangeLog($db, $uid, $date, 'assistant_apply', planCellSnapshot($cell),
            ['draft' => ['action' => 'set', 'type' => 'assignment', 'value' => (string)$cfg['area_id'], 'by' => $me], 'run_id' => $runId], $me, 'assistant');
        $ins->execute([
            $uid, $date, (int)date('Y', strtotime($date)), (int)date('n', strtotime($date)),
            (string)$cfg['area_id'],
            $cfg['remarks'] !== '' ? $cfg['remarks'] : $cfg['title'],
            $internal, $me, $runId,
        ]);
        $evCells[] = [$uid, $date];
        $written++;
    }

    $out = [
        'written' => $written, 'people' => count($people), 'unchanged' => $unchanged,
        'skipped' => array_slice($skipped, 0, 40), 'skipped_total' => count($skipped),
        'skipped_items' => array_slice($items, 0, 200), 'by_reason' => $byReason,
        'reasons' => EPA_SKIP_REASONS, 'kinds' => $kinds, 'internal' => (bool)$internal,
    ];
    if ($dryRun) {
        $out['revs'] = $revsOut;
        return $out;
    }

    $db->prepare('UPDATE planning_runs SET slots_filled = ?, conflicts = ? WHERE id = ?')
       ->execute([$written, count($skipped), $runId]);
    if ($sourceId > 0) {
        $db->prepare('UPDATE planning_runs SET applied_at = NOW() WHERE id = ?')->execute([$sourceId]);
    }
    if (function_exists('pqEventEmit')) {
        pqEventEmit($db, 'cells_changed', $evCells, false, $me, ['internal' => (bool)$internal]);
    }
    $db->commit();

    /* Protokolleintrag – dieselbe Tabelle wie Login und Einstellungen. */
    try {
        $db->prepare('INSERT INTO audit_log(user_id, action, details, ip_address) VALUES(?,?,?,?)')
           ->execute([
               $me, 'planning_assistant_apply',
               'Lauf #' . $runId . ($cfg['cross_team'] ? ' (teamübergreifend)' : '')
               . ' – ' . $written . ' Entwürfe für '
               . ($ctx['areas'][$cfg['area_id']]['short_code'] ?? '?')
               . ' (' . $cfg['start'] . ' bis ' . $cfg['end'] . '): ' . $cfg['title'],
               function_exists('opsClientIp') ? opsClientIp() : '',
           ]);
    } catch (Exception $e) { /* Protokoll ist optional */ }

    $out['run_id'] = $runId;
    $out['msg'] = $written . ' Einträge als Entwurf übernommen.';
    return $out;
}

/**
 * Nimmt eine Übernahme zurück: entfernt genau die Entwürfe, die noch aus
 * diesem Lauf stammen. Manuell überschriebene Zellen tragen keine
 * planning_run_id mehr (api/save_bulk, D-05) und bleiben erhalten.
 */
function epa_undo(PDO $db, int $runId, int $me, bool $mayManage): array
{
    $chk = $db->prepare('SELECT created_by, status, source_run_id FROM planning_runs WHERE id = ? AND is_saved = 0');
    $chk->execute([$runId]); $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['success' => false, 'error' => 'Lauf nicht gefunden.'];
    if ((int)$row['created_by'] !== $me && !$mayManage) {
        return ['success' => false, 'error' => 'Nur der Ersteller kann diese Übernahme zurücknehmen.'];
    }
    $db->beginTransaction();
    $rows = $db->prepare('SELECT user_id, plan_date FROM plan_drafts WHERE planning_run_id = ? FOR UPDATE');
    $rows->execute([$runId]);
    $evCells = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        planChangeLog($db, (int)$r['user_id'], $r['plan_date'], 'assistant_undo', ['run_id' => $runId], null, $me, 'assistant');
        $evCells[] = [(int)$r['user_id'], $r['plan_date']];
    }
    $del = $db->prepare('DELETE FROM plan_drafts WHERE planning_run_id = ?');
    $del->execute([$runId]);
    $n = $del->rowCount();
    if (function_exists('pqEventEmit')) pqEventEmit($db, 'cells_changed', $evCells, false, $me);
    $db->prepare("UPDATE planning_runs SET status='verworfen' WHERE id = ?")->execute([$runId]);
    /* Gespeicherte Planung gilt wieder als nicht übernommen, wenn kein anderer
       Lauf aus ihr mehr aktiv ist. */
    $src = (int)($row['source_run_id'] ?? 0);
    if ($src > 0) {
        $o = $db->prepare("SELECT COUNT(*) FROM planning_runs WHERE source_run_id = ? AND status = 'uebernommen' AND id <> ?");
        $o->execute([$src, $runId]);
        if ((int)$o->fetchColumn() === 0) $db->prepare('UPDATE planning_runs SET applied_at = NULL WHERE id = ? AND is_saved = 1')->execute([$src]);
    }
    $db->commit();
    try {
        $db->prepare('INSERT INTO audit_log(user_id, action, details, ip_address) VALUES(?,?,?,?)')
           ->execute([$me, 'planning_assistant_undo', 'Lauf #' . $runId . ' – ' . $n . ' Entwürfe zurückgenommen',
                      function_exists('opsClientIp') ? opsClientIp() : '']);
    } catch (Exception $e) { /* optional */ }
    return ['success' => true, 'removed' => $n, 'msg' => $n . ' Entwürfe zurückgenommen.'];
}

/** JSON-Feld aus dem POST lesen. */
function epa_json(array $post, string $key, $default = [])
{
    $v = json_decode((string)($post[$key] ?? ''), true);
    return $v === null ? $default : $v;
}

/**
 * AJAX-Aktionen der Seite. Liefert die Antwort als Array (wird als JSON
 * ausgegeben). $env: me, may_absence, may_internal, may_manage, defaults.
 */
function epa_handle(PDO $db, string $act, array $post, array $env): array
{
    $ME = (int)$env['me'];
    $D  = $env['defaults'];
    $raw = epa_json($post, 'config', []);
    if (!is_array($raw)) $raw = [];
    /* Der Umfang hängt von der Konfiguration ab (teamübergreifend ja/nein). */
    $scope = epa_scope(['cross_team' => !empty($raw['cross_team'])], $D);
    $cfgOf = function () use ($raw, $D) { return epa_cfg($raw, $D); };

    switch ($act) {
        /* ------------------------------------------------ Vorschlag bauen */
        case 'generate': {
            $cfg = $cfgOf();
            $ctx = epa_context($db, $cfg, $scope, $D, $ME);
            if (!$ctx['people']) return ['success' => false, 'error' => 'Für Sie sind keine planbaren Mitarbeiter hinterlegt.'];
            $res = epa_generate($cfg, $ctx, !empty($env['may_absence']));
            return ['success' => true, 'cfg' => $cfg] + epa_payload($cfg, $ctx, $res);
        }

        /* -------------------------------- Gespeicherte Planungen: Liste */
        case 'plan_list': {
            $q = $db->prepare(
                "SELECT r.id, r.title, r.plan_area_id, r.start_date, r.end_date, r.status,
                        r.slots_total, r.slots_filled, r.conflicts, r.shared, r.created_by,
                        r.created_at, r.updated_at, r.applied_at,
                        a.short_code, a.name AS area_name, a.color, a.fg_color,
                        CONCAT(u.first_name,' ',u.last_name) AS owner
                   FROM planning_runs r
              LEFT JOIN plan_areas a ON a.id = r.plan_area_id
              LEFT JOIN users u ON u.id = r.created_by
                  WHERE r.is_saved = 1 AND (r.created_by = ? OR r.shared = 1)
               ORDER BY COALESCE(r.updated_at, r.created_at) DESC
                  LIMIT 100");
            $q->execute([$ME]);
            return ['success' => true, 'items' => $q->fetchAll(PDO::FETCH_ASSOC), 'me' => $ME];
        }

        /* -------------------------------- Gespeicherte Planung sichern */
        case 'plan_save': {
            $cfg  = $cfgOf();
            $plan = epa_json($post, 'plan', []);
            $id   = (int)($post['id'] ?? 0);
            $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 150) ?: $cfg['title'];
            $stats = epa_json($post, 'stats', []);
            if (!is_array($stats)) $stats = [];
            $result = json_encode(['plan' => $plan, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
            $conf   = json_encode($cfg, JSON_UNESCAPED_UNICODE);
            $shared = !empty($post['shared']) ? 1 : 0;
            if ($id > 0) {
                /* Nur eigene Planungen dürfen überschrieben werden. */
                $own = $db->prepare('SELECT created_by FROM planning_runs WHERE id = ? AND is_saved = 1');
                $own->execute([$id]);
                if ((int)$own->fetchColumn() !== $ME) {
                    return ['success' => false, 'error' => 'Diese Planung gehört jemand anderem. Bitte als neue Planung sichern.'];
                }
                $db->prepare(
                    'UPDATE planning_runs
                        SET title = ?, plan_area_id = ?, start_date = ?, end_date = ?,
                            config_json = ?, result_json = ?, slots_total = ?, slots_filled = ?,
                            conflicts = ?, shared = ?, is_saved = 1, updated_at = NOW()
                      WHERE id = ?')
                  ->execute([$name, $cfg['area_id'] ?: null, $cfg['start'], $cfg['end'], $conf, $result,
                             (int)($stats['total'] ?? 0), (int)($stats['filled'] ?? 0),
                             (int)($stats['blocks'] ?? 0) + (int)($stats['warns'] ?? 0), $shared, $id]);
            } else {
                $db->prepare(
                    'INSERT INTO planning_runs
                        (title, plan_area_id, start_date, end_date, config_json, result_json,
                         status, is_saved, shared, slots_total, slots_filled, conflicts,
                         created_by, created_at, updated_at)
                     VALUES (?,?,?,?,?,?, "entwurf", 1, ?, ?,?,?, ?, NOW(), NOW())')
                  ->execute([$name, $cfg['area_id'] ?: null, $cfg['start'], $cfg['end'], $conf, $result, $shared,
                             (int)($stats['total'] ?? 0), (int)($stats['filled'] ?? 0),
                             (int)($stats['blocks'] ?? 0) + (int)($stats['warns'] ?? 0), $ME]);
                $id = (int)$db->lastInsertId();
            }
            return ['success' => true, 'id' => $id, 'msg' => 'Planung gespeichert.'];
        }

        /* -------------------------------- Gespeicherte Planung laden */
        case 'plan_load': {
            $q = $db->prepare('SELECT * FROM planning_runs WHERE id = ? AND is_saved = 1');
            $q->execute([(int)($post['id'] ?? 0)]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || ((int)$row['created_by'] !== $ME && empty($row['shared']))) {
                return ['success' => false, 'error' => 'Planung nicht gefunden oder nicht freigegeben.'];
            }
            $cfg = epa_cfg(json_decode((string)$row['config_json'], true) ?: [], $D);
            $res = json_decode((string)$row['result_json'], true) ?: [];
            /* Frisch gegen die Datenbank prüfen – zwischenzeitlich kann sich am
               Urlaub oder an anderen Einsätzen einiges geändert haben. */
            $ctx  = epa_context($db, $cfg, epa_scope($cfg, $D), $D, $ME);
            $gen  = epa_generate($cfg, $ctx, !empty($env['may_absence']));
            $data = epa_payload($cfg, $ctx, $gen);
            $data['success'] = true;
            $data['config']  = $cfg;
            /* Gespeicherte Besetzung hat Vorrang (ältere Stände: nur der Plan) */
            $saved = (isset($res['plan']) && is_array($res['plan'])) ? $res['plan'] : null;
            $data['plan']    = $saved ?? $gen['plan'];
            $data['run_id']  = (int)$row['id'];
            $data['name']    = $row['title'];
            $data['shared']  = (int)$row['shared'];
            $data['own']     = ((int)$row['created_by'] === $ME);
            $data['applied_at'] = $row['applied_at'];
            return $data;
        }

        /* -------------------------------- Gespeicherte Planung löschen */
        case 'plan_delete': {
            $id = (int)($post['id'] ?? 0);
            $q = $db->prepare('SELECT created_by FROM planning_runs WHERE id = ? AND is_saved = 1');
            $q->execute([$id]);
            $owner = $q->fetchColumn();
            if ($owner === false || (int)$owner !== $ME) {
                return ['success' => false, 'error' => 'Nur eigene Planungen können gelöscht werden.'];
            }
            /* Ausblenden statt löschen: übernommene Läufe verweisen ggf. darauf. */
            $db->prepare('UPDATE planning_runs SET is_saved = 0 WHERE id = ?')->execute([$id]);
            return ['success' => true, 'msg' => 'Planung entfernt.'];
        }

        /* ------------------- Kandidatenliste mit Stammbereich und Erfahrung */
        case 'candidates': {
            $cfg = $cfgOf();
            $ctx = epa_context($db, $cfg, $scope, $D, $ME);
            $out = [];
            foreach ($ctx['people'] as $uid => $p) {
                $out[] = [
                    'id' => $uid, 'name' => $p['name'], 'short' => $p['short'],
                    'role' => $p['role'], 'role_label' => $p['role_label'], 'role_color' => $p['role_color'],
                    'azubi_year' => $p['azubi_year'], 'os' => $p['os'], 'prof' => $p['prof'],
                    'area_id' => $p['area_id'], 'area_code' => $p['area_code'],
                    'home' => (bool)$p['home'], 'exp' => (int)$p['exp'], 'exp_last' => $p['exp_last'],
                    'bond' => (float)$p['bond'], 'bond_code' => $p['bond_code'], 'variety' => (int)$p['variety'],
                    'weekend' => (bool)$p['weekend'],
                ];
            }
            /* Sinnvolle Grundordnung: Stammbereich, dann Erfahrung, dann Name. */
            usort($out, function ($a, $b) {
                if ($a['home'] !== $b['home']) return $a['home'] ? -1 : 1;
                if ($a['exp'] !== $b['exp'])   return $b['exp'] <=> $a['exp'];
                return strcmp($a['name'], $b['name']);
            });
            return ['success' => true, 'items' => $out, 'area' => $ctx['areas'][$cfg['area_id']] ?? null];
        }

        /* ------------------------------------- Alternativen für einen Tag */
        case 'suggest': {
            $cfg  = $cfgOf();
            $date = (string)($post['date'] ?? '');
            $ctx  = epa_context($db, $cfg, $scope, $D, $ME);
            $day  = null;
            foreach ($ctx['days'] as $d) if ($d['date'] === $date) { $day = $d; break; }
            if (!$day) return ['success' => false, 'error' => 'Tag liegt außerhalb des Zeitraums.'];
            $taken = array_flip(array_map('intval', (array)epa_json($post, 'taken', [])));
            $out = [];
            foreach ($ctx['people'] as $uid => $p) {
                if (isset($taken[$uid])) continue;
                $av = epa_availability($p, $day, $cfg, $ctx, !empty($env['may_absence']));
                $out[] = [
                    'id' => $uid, 'name' => $p['name'], 'role_label' => $p['role_label'],
                    'os' => $p['os'], 'prof' => $p['prof'], 'area_code' => $p['area_code'],
                    'exp' => (int)$p['exp'], 'exp_last' => $p['exp_last'],
                    'bond' => (float)$p['bond'], 'bond_code' => $p['bond_code'],
                    'azubi_year' => $p['azubi_year'],
                    'ok' => $av['ok'], 'reason' => $av['txt'],
                    'home' => $cfg['area_id'] && $p['area_id'] === (int)$cfg['area_id'],
                ];
            }
            usort($out, function ($a, $b) {
                if ($a['ok'] !== $b['ok'])     return $a['ok'] ? -1 : 1;
                if ($a['home'] !== $b['home']) return $a['home'] ? -1 : 1;
                if ($a['exp'] !== $b['exp'])   return $b['exp'] <=> $a['exp'];
                return strcmp($a['name'], $b['name']);
            });
            return ['success' => true, 'items' => array_slice($out, 0, 60)];
        }

        /* ------------------------- Übernahme prüfen (nichts wird geschrieben) */
        case 'apply_preview':
        /* ------------------------------------------- Übernahme als Entwurf */
        case 'apply': {
            $cfg  = $cfgOf();
            $plan = epa_json($post, 'plan', []);
            if (!is_array($plan)) $plan = [];
            if (!$cfg['area_id']) return ['success' => false, 'error' => 'Bitte zuerst einen Einsatzbereich wählen.'];
            $ctx = epa_context($db, $cfg, $scope, $D, $ME);
            $revs = epa_json($post, 'revs', []);
            $res = epa_apply($db, $cfg, $plan, $ctx, $env, $act === 'apply_preview',
                             ['revs' => is_array($revs) ? $revs : [], 'plan_id' => (int)($post['plan_id'] ?? 0)]);
            return ['success' => true] + $res;
        }

        /* ------------------------------------------- Übernahme zurücknehmen */
        case 'undo':
            return epa_undo($db, (int)($post['run_id'] ?? 0), $ME, !empty($env['may_manage']));

        /* ------------------------------ Letzte Übernahmen (für Rückgängig) */
        case 'run_list': {
            $all = !empty($env['may_manage']) ? 1 : 0;
            $q = $db->prepare(
                "SELECT r.id, r.title, r.start_date, r.end_date, r.slots_filled, r.conflicts, r.applied_at,
                        r.created_by, a.short_code, a.color, a.fg_color,
                        CONCAT(u.first_name,' ',u.last_name) AS owner, s.title AS source_title,
                        (SELECT COUNT(*) FROM plan_drafts d WHERE d.planning_run_id = r.id) AS remaining
                   FROM planning_runs r
              LEFT JOIN plan_areas a ON a.id = r.plan_area_id
              LEFT JOIN users u ON u.id = r.created_by
              LEFT JOIN planning_runs s ON s.id = r.source_run_id
                  WHERE r.is_saved = 0 AND r.status = 'uebernommen' AND (r.created_by = ? OR ? = 1)
               ORDER BY r.applied_at DESC, r.id DESC
                  LIMIT 15");
            $q->execute([$ME, $all]);
            return ['success' => true, 'items' => $q->fetchAll(PDO::FETCH_ASSOC), 'me' => $ME];
        }

        /* -------------------------------------------------------- Vorlagen */
        case 'profile_save': {
            $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 150);
            if ($name === '') return ['success' => false, 'error' => 'Bitte einen Namen für die Vorlage angeben.'];
            $cfg = $cfgOf();
            $id  = (int)($post['id'] ?? 0);
            $desc = mb_substr((string)($post['description'] ?? ''), 0, 255);
            if ($id > 0) {
                $st = $db->prepare('UPDATE planning_profiles SET name=?, description=?, plan_area_id=?, config_json=?, is_shared=? WHERE id=? AND (created_by=? OR ?=1)');
                $st->execute([$name, $desc, $cfg['area_id'] ?: null, json_encode($cfg, JSON_UNESCAPED_UNICODE),
                              !empty($post['shared']) ? 1 : 0, $id, $ME, !empty($env['may_manage']) ? 1 : 0]);
                $ok = $db->prepare('SELECT COUNT(*) FROM planning_profiles WHERE id=? AND (created_by=? OR ?=1)');
                $ok->execute([$id, $ME, !empty($env['may_manage']) ? 1 : 0]);
                if (!(int)$ok->fetchColumn()) return ['success' => false, 'error' => 'Diese Vorlage dürfen Sie nicht ändern.'];
            } else {
                $db->prepare('INSERT INTO planning_profiles (name, description, plan_area_id, config_json, is_shared, created_by) VALUES (?,?,?,?,?,?)')
                   ->execute([$name, $desc, $cfg['area_id'] ?: null,
                              json_encode($cfg, JSON_UNESCAPED_UNICODE), !empty($post['shared']) ? 1 : 0, $ME]);
                $id = (int)$db->lastInsertId();
            }
            return ['success' => true, 'id' => $id, 'msg' => 'Vorlage gespeichert.'];
        }

        case 'profile_list': {
            $s = $db->prepare('SELECT p.id, p.name, p.description, p.plan_area_id, p.is_shared, p.use_count,
                                      p.created_by, p.updated_at, a.short_code, a.name AS area_name, a.color, a.fg_color
                                 FROM planning_profiles p
                                 LEFT JOIN plan_areas a ON a.id = p.plan_area_id
                                WHERE p.is_shared = 1 OR p.created_by = ?
                                ORDER BY p.updated_at DESC LIMIT 100');
            $s->execute([$ME]);
            return ['success' => true, 'items' => $s->fetchAll(PDO::FETCH_ASSOC), 'me' => $ME,
                    'manage' => !empty($env['may_manage'])];
        }

        case 'profile_load': {
            $id = (int)($post['id'] ?? 0);
            $s = $db->prepare('SELECT id, name, config_json, created_by FROM planning_profiles WHERE id = ? AND (is_shared = 1 OR created_by = ?)');
            $s->execute([$id, $ME]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r) return ['success' => false, 'error' => 'Vorlage nicht gefunden.'];
            $db->prepare('UPDATE planning_profiles SET use_count = use_count + 1 WHERE id = ?')->execute([$id]);
            return ['success' => true, 'config' => json_decode((string)$r['config_json'], true) ?: [],
                    'id' => (int)$r['id'], 'name' => $r['name'], 'own' => (int)$r['created_by'] === $ME];
        }

        case 'profile_delete': {
            $st = $db->prepare('DELETE FROM planning_profiles WHERE id = ? AND (created_by = ? OR ? = 1)');
            $st->execute([(int)($post['id'] ?? 0), $ME, !empty($env['may_manage']) ? 1 : 0]);
            return ['success' => $st->rowCount() > 0, 'msg' => 'Vorlage gelöscht.',
                    'error' => $st->rowCount() ? '' : 'Löschen nicht erlaubt.'];
        }
    }
    return ['success' => false, 'error' => 'Unbekannte Aktion.'];
}
