<?php
/**
 * OpsPilot Einsatzplanung – Planungsassistent (Beta)   v6.8.5
 *
 * Copyright (c) 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten.
 * -----------------------------------------------------------------------------
 * Ein geführter Assistent für komplexe Einsätze und Kundenprojekte:
 *   1. Einsatz & Zeitraum
 *   2. Bedarf pro Tag / Wochentag / Sondertage
 *   3. Team & Azubi-Regeln (Lehrjahr, Betreuungsschlüssel, Berufsschule)
 *   4. Regeln & Gewichtung (Kontinuität, Fairness, Stammbereich, OS-Level)
 *   5. Entwurfsansicht mit Live-Konfliktprüfung und Übernahme
 *
 * Die Übernahme schreibt ausschließlich in `plan_drafts` – veröffentlicht wird
 * weiterhin bewusst in der Monatsplanung.
 *
 * Voraussetzung: migrations/migrate_v6_8_0.sql und migrate_v6_8_5.sql
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../app/lib/PlanCells.php';
requireLogin();

$db  = getDB();
$ME  = (int)($_SESSION['user_id'] ?? 0);

/* Wer planen darf, darf den Assistenten nutzen (eigene Berechtigung optional). */
$EPA_IS_AJAX = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
$EPA_MAY_USE = hasPerm('use_planning_assistant') || hasPerm('plan_edit');
if (!$EPA_MAY_USE) {
    /* Hintergrundaufrufe brauchen JSON – sonst landet HTML im Browser-Handler. */
    if ($EPA_IS_AJAX) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Keine Berechtigung für den Planungsassistenten.']);
        exit;
    }
    showAccessDenied('Sie benötigen die Berechtigung "Planen" bzw. "Planungsassistent nutzen".');
}
$EPA_MAY_ABSENCE  = hasPerm('unlock_vacation');   // darf Urlaub/Krank überschreiben
$EPA_MAY_INTERNAL = hasPerm('plan_view_internal');
$EPA_BP           = bp();                          // Basis für Links (../ in /pages)

/* -----------------------------------------------------------------------------
   Vorgaben aus den Einstellungen (Seite „Planung“). Sie machen den Assistenten
   an den eigenen Betrieb anpassbar – welche Bereiche freie Kapazität sind, wie
   lange zurückgeschaut wird, welche Grenzen gelten.
   -------------------------------------------------------------------------- */
function epa_setting(string $key, $default) {
    $v = getSetting($key, null);
    return ($v === null || $v === '') ? $default : $v;
}
function epa_setting_ids(string $key): array {
    return array_values(array_filter(array_map('intval', explode(',', (string)epa_setting($key, '')))));
}
$EPA_DEFAULTS = [
    'free_areas'      => epa_setting_ids('epa_free_areas'),
    'prefer_free'     => epa_setting('epa_prefer_free', '1') !== '0',
    'respect_bond'    => epa_setting('epa_respect_bond', '1') !== '0',
    'home_mode'       => in_array(epa_setting('epa_home_mode', 'prefer'), ['prefer','only','off'], true)
                         ? epa_setting('epa_home_mode', 'prefer') : 'prefer',
    'demand_default'  => max(1, (int)epa_setting('epa_demand_default', 2)),
    'demand_min'      => max(0, (int)epa_setting('epa_demand_min', 1)),
    'max_consecutive' => max(1, (int)epa_setting('epa_max_consecutive', 10)),
    'max_days_total'  => max(1, (int)epa_setting('epa_max_days_total', 20)),
    'azubi_years'     => epa_setting_ids('epa_azubi_years') ?: [2, 3],
    'azubi_max'       => max(0, (int)epa_setting('epa_azubi_max', 1)),
    'azubi_ratio'     => max(1, (int)epa_setting('epa_azubi_ratio', 2)),
    'exp_months'      => max(1, min(60, (int)epa_setting('epa_exp_months', 12))),
    'bond_months'     => max(1, min(60, (int)epa_setting('epa_bond_months', 6))),
    /* Teamleiter duerfen teamuebergreifend planen, wenn freigegeben */
    'cross_team'      => epa_setting('epa_cross_team', '0') === '1',
];

/** Beschriftung einer Abwesenheit – exakt nach denselben Regeln wie die
 *  Monatsplanung: Administrator immer, Manager und Teamleiter fuer das eigene
 *  Team, alle uebrigen sehen nur „Abwesend“. Krank- und Urlaubsdaten gelten
 *  dabei als vertraulich (Art. 9 DSGVO). */
function epa_absLabel(string $type, array $map, int $userId): string
{
    if (isConfidentialAbsence($type) && !canSeeAbsenceDetails($userId)) return 'Abwesend';
    return $map[$type] ?? $type;
}

/* =============================================================================
   HILFSFUNKTIONEN
   ========================================================================== */

/**
 * Liefert die IDs aller Mitarbeiter, die der angemeldete Nutzer planen darf.
 * Nutzt bewusst die App-eigene Logik aus functions.php (inkl. transitiver
 * Teamhierarchie und aktiver Vertretungen), damit der Assistent exakt denselben
 * Personenkreis sieht wie die Monatsplanung. null = keine Einschränkung.
 */
function epa_scope(array $cfg = []): ?array
{
    /* Teamuebergreifend planen: nur wenn in den Einstellungen freigegeben und
       die Rolle ueberhaupt plant. Die Sichtbarkeit von Abwesenheitsgruenden
       bleibt davon unberuehrt – ausserhalb des eigenen Teams steht weiterhin
       nur „Abwesend“. */
    if (!empty($cfg['cross_team']) && epa_may_cross_team()) return null;
    $ids = visibleUserIds();
    return $ids === null ? null : array_values(array_map('intval', $ids));
}

/** Darf der angemeldete Nutzer ueber das eigene Team hinaus planen? */
function epa_may_cross_team(): bool
{
    if (hasRole('admin', 'manager') || hasPerm('view_all_employees')) return true;
    return !empty($GLOBALS['EPA_DEFAULTS']['cross_team']) && hasRole('teamleiter');
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

/** Normalisiert und begrenzt die vom Browser gesendete Konfiguration. */
function epa_cfg(array $r): array
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
    foreach ((array)($r['weekdays'] ?? [1,2,3,4,5]) as $d) {
        $d = (int)$d; if ($d >= 1 && $d <= 7) $wd[] = $d;
    }
    if (!$wd) $wd = [1,2,3,4,5];

    $dbw = [];
    foreach ((array)($r['demand_by_weekday'] ?? []) as $k => $v) {
        $k = (int)$k; if ($k >= 1 && $k <= 7) $dbw[$k] = max(0, min(99, (int)$v));
    }

    $special = [];
    foreach ((array)($r['special_days'] ?? []) as $sd) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd['date'] ?? '')) continue;
        $special[$sd['date']] = [
            'date'  => $sd['date'],
            'count' => max(0, min(99, (int)($sd['count'] ?? 0))),
            'note'  => mb_substr(trim((string)($sd['note'] ?? '')), 0, 120),
        ];
    }

    $az = (array)($r['azubi'] ?? []);
    $years = [];
    foreach ((array)($az['years'] ?? [1,2,3]) as $y) { $y = (int)$y; if ($y >= 1 && $y <= 4) $years[] = $y; }

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
        /* Stammbereich: egal / bevorzugen / ausschliesslich */
        'home_mode'       => in_array($r['home_mode'] ?? 'prefer', ['off','prefer','only'], true)
                             ? ($r['home_mode'] ?? 'prefer') : 'prefer',
        /* Kundenerfahrung der letzten Monate mitgewichten */
        'use_experience'  => !isset($r['use_experience']) || !empty($r['use_experience']),
        /* Einzeln per Klick freigegebene Zellen: "userId|YYYY-MM-DD" */
        /* Allgemein-Bereiche, die als frei verplanbar gelten (z. B. Technik) */
        'free_areas'      => array_slice(array_values(array_unique(array_map('intval',
                                array_key_exists('free_areas', $r) ? (array)$r['free_areas']
                                                                   : ($GLOBALS['EPA_DEFAULTS']['free_areas'] ?? [])))), 0, 30),
        /* Kundenbindung achten: wer fast immer beim selben Kunden ist, bleibt dort */
        'respect_bond'    => !isset($r['respect_bond']) || !empty($r['respect_bond']),
        'cross_team'      => !empty($r['cross_team']),
        /* Innendienst-Tage aktiv aufloesen, statt nur zu dulden */
        'prefer_free'     => !isset($r['prefer_free']) || !empty($r['prefer_free']),
        'force_cells'     => array_slice(array_values(array_unique(array_filter(
                                array_map('strval', (array)($r['force_cells'] ?? [])),
                                function ($k) { return (bool)preg_match('/^\d+\|\d{4}-\d{2}-\d{2}$/', $k); }
                             ))), 0, 2000),

        'roles'         => array_slice(array_map('strval', (array)($r['roles'] ?? [])), 0, 40),
        'include_users' => array_slice(array_map('intval', (array)($r['include_users'] ?? [])), 0, 200),
        'exclude_users' => array_slice(array_map('intval', (array)($r['exclude_users'] ?? [])), 0, 200),

        'azubi' => [
            'enabled'           => !empty($az['enabled']),
            'years'             => $years ?: [1,2,3],
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

/**
 * Lädt Mitarbeiter, Belegung und Feiertage für den Zeitraum.
 * Ergebnis wird sowohl von der Generierung als auch von der Übernahme genutzt.
 */
function epa_context(PDO $db, array $cfg, ?array $scope): array
{
    /* --- Rollen --- */
    $customRoles = [];
    try {
        foreach ($db->query('SELECT role_key, role_label, color, base_role, sort_order FROM custom_roles')->fetchAll() as $r) {
            $customRoles[$r['role_key']] = $r;
        }
    } catch (Exception $e) { /* optional */ }

    /* --- Bereiche --- */
    $areas = [];
    foreach ($db->query('SELECT id, name, short_code, color, fg_color, area_type FROM plan_areas ORDER BY area_type, sort_order, name')->fetchAll() as $a) {
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
        if (!$scope) return ['people' => [], 'areas' => $areas, 'days' => [], 'state' => [], 'holidays' => []];
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $par  = $scope;
    }
    $sql .= ' ORDER BY last_name, first_name';
    $st = $db->prepare($sql); $st->execute($par);

    $people = [];
    foreach ($st->fetchAll() as $u) {
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
            'bond'       => 0.0,      // Anteil des haeufigsten Kunden (0..1)
            'bond_area'  => 0,        // dieser Kunde
            'bond_code'  => '',
            'variety'    => 0,        // Zahl verschiedener Kunden
            'exp'        => 0,        // Einsaetze im gewaehlten Bereich (12 Monate)
            'exp_last'   => null,
            'home'       => $cfg['area_id'] && (int)$u['primary_area_id'] === (int)$cfg['area_id'],
        ];
    }

    /* --- Kundenerfahrung: Einsaetze im gewaehlten Bereich, letzte 12 Monate --- */
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
            $q->execute(array_merge([(int)$cfg['area_id'], (int)($GLOBALS['EPA_DEFAULTS']['exp_months'] ?? 12)], $ids));
            foreach ($q->fetchAll() as $r) {
                $uid = (int)$r['user_id'];
                if (!isset($people[$uid])) continue;
                $people[$uid]['exp']      = (int)$r['c'];
                $people[$uid]['exp_last'] = $r['last_date'];
            }
        } catch (Exception $e) { /* Erfahrung ist optional */ }
    }

    /* --- Kundenbindung: Wie stark haengt jemand an einem einzelnen Kunden? ----
       Wer fast immer beim selben Kunden ist, wird ungern abgezogen; wer ohnehin
       oft wechselt, laesst sich leichter umplanen. Nur Kundenbereiche zaehlen. */
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
            $q->execute(array_merge([(int)($GLOBALS['EPA_DEFAULTS']['bond_months'] ?? 6)], $ids));
            $agg = [];
            foreach ($q->fetchAll() as $r) {
                $agg[(int)$r['user_id']][(int)$r['plan_area_id']] = (int)$r['c'];
            }
            foreach ($agg as $uid => $byArea) {
                if (!isset($people[$uid])) continue;
                $sum = array_sum($byArea);
                if ($sum < 5) continue;               // zu wenig Historie fuer eine Aussage
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
    foreach ($h->fetchAll() as $row) $holidays[$row['holiday_date']] = $row;

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

    /* --- Belegung: veröffentlicht + Entwürfe --- */
    $state = [];   // $state[uid][date] = ['t'=>..., 'l'=>Label, 'c'=>Farbe]
    $ids   = array_keys($people);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));

        $q = $db->prepare("SELECT user_id, plan_date, plan_area_id FROM assignments
                            WHERE is_published = 1 AND plan_date BETWEEN ? AND ? AND user_id IN ($in)");
        $q->execute(array_merge([$cfg['start'], $cfg['end']], $ids));
        foreach ($q->fetchAll() as $r) {
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
        $absLabel = [
            'krank_halb' => 'Krank ½', 'krank_ganz' => 'Krank', 'krank_attest' => 'Krank (Attest)',
            'urlaub_halb' => 'Urlaub ½', 'urlaub_ganz' => 'Urlaub', 'urlaub_genehmigt' => 'Urlaub',
            'gleitzeit' => 'Gleitzeit', 'gesperrt' => 'Gesperrt', 'offen' => 'Offen',
        ];
        foreach ($q->fetchAll() as $r) {
            $t = $r['absence_type'];
            $state[(int)$r['user_id']][$r['absence_date']] = [
                't' => $t === 'gesperrt' ? 'gesperrt' : ($t === 'offen' ? 'offen' : 'abwesend'),
                'l' => epa_absLabel($t, $absLabel, (int)$r['user_id']),
                'c' => '#dc2626', 'f' => '#ffffff',
                'a' => 0,
            ];
        }

        try {
            $q = $db->prepare("SELECT user_id, plan_date, action, change_type, change_value FROM plan_drafts
                                WHERE plan_date BETWEEN ? AND ? AND user_id IN ($in)");
            $q->execute(array_merge([$cfg['start'], $cfg['end']], $ids));
            foreach ($q->fetchAll() as $r) {
                $uid = (int)$r['user_id']; $d = $r['plan_date'];
                if ($r['action'] === 'clear') { unset($state[$uid][$d]); continue; }
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
                        'l' => epa_absLabel($v, $absLabel, (int)$uid) . ' •',
                        'c' => '#dc2626', 'f' => '#ffffff',
                        'a' => 0,
                    ];
                }
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
            if ($p['azubi_year'] > 0 && $cfg['azubi']['skip_school_days']) {
                return ['ok' => false, 'code' => 'schule', 'txt' => 'Berufsschule'];
            }
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

/** Kernstück: erzeugt den Planungsvorschlag. */
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
            /* „Ausschliesslich Stammbereich“: nur Leute, die den Kunden ohnehin betreuen.
               Wer erkennbar Erfahrung dort hat, zaehlt ebenfalls dazu. */
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
        $limited_pool = [];   // nur an den Belastungsgrenzen gescheitert – Notnagel fuer offene Plaetze
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
            /* Kundenbindung: Wer fast ausschliesslich bei einem anderen Kunden ist, wird
               ungern abgezogen. Wer ohnehin viel wechselt, ist der bessere Kandidat. */
            if ($cfg['respect_bond'] && $p['bond'] > 0 && $p['bond_area'] !== (int)$cfg['area_id']) {
                if ($p['bond'] >= 0.6) {
                    $score -= $w['home_area'] * 1.4 * $p['bond'];
                } elseif ($p['variety'] >= 3) {
                    $score += $w['home_area'] * 0.35;      // wechselt viel – gut umplanbar
                }
            }
            /* Kundenerfahrung: gedaempft, damit ein einzelner Vielflieger nicht alles bekommt. */
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

            /* Innendienst-Tage sind freie Kapazitaet – solche Leute lassen sich am
               leichtesten umplanen und werden deshalb bevorzugt herangezogen. */
            if (($av['code'] ?? '') === 'frei_intern') {
                /* Innendienst ist Fuellarbeit. Ist die Vorauswahl aktiv, geht ein
                   solcher Tag klar vor einem ohnehin unverplanten Kollegen –
                   der bleibt als Reserve fuer andere Kunden verfuegbar. */
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
           Kontinuitätsgewichtung nicht dauerhaft aussen vor bleiben. */
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

        /* Vorgabe „mindestens N aus dem Stammbereich“ aktiv besetzen, statt sie
           hinterher nur zu bemaengeln. */
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
           Bleiben Plaetze offen, obwohl noch Leute koennten, werden die zuvor
           an den Belastungsgrenzen zurueckgestellten Personen herangezogen.
           Das wird offen als Hinweis ausgewiesen, nicht stillschweigend getan. */
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
           Bleiben trotz allem Plätze offen, wird der Betreuungsschlüssel als
           letzter Hebel geprüft: Fehlt nur eine Fachkraft, damit ein Azubi
           mitkann, wird sie bevorzugt geholt. Danach zaehlt nur noch, dass
           der Tag ueberhaupt besetzt ist. */
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

/** Baut die Antwort für den Browser: Personen, Tage, Belegung, Vorschlag. */
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

/* =============================================================================
   AJAX
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');

    /* Auch schwere Fehler sollen als JSON ankommen. Sonst sieht der Nutzer nur
       „Unerwartete Antwort des Servers“ und weiss nicht, woran es lag. */
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false,
                'error' => 'Serverfehler beim Verarbeiten der Anfrage. Bitte melden Sie den Vorgang.',
                'detail' => basename($e['file']) . ':' . $e['line']]);
        }
    });

    $act = $_POST['action'] ?? '';
    /* Der Umfang haengt von der Konfiguration ab (teamuebergreifend ja/nein). */
    $rawCfg = json_decode($_POST['config'] ?? '[]', true) ?: [];
    $scope  = epa_scope(['cross_team' => !empty($rawCfg['cross_team'])]);

    try {
        /* --------------------------------------------------- Vorschlag bauen */
        if ($act === 'generate') {
            $cfg = epa_cfg(json_decode($_POST['config'] ?? '[]', true) ?: []);
            $ctx = epa_context($db, $cfg, $scope);
            if (!$ctx['people']) {
                echo json_encode(['success' => false, 'error' => 'Für Sie sind keine planbaren Mitarbeiter hinterlegt.']); exit;
            }
            $res = epa_generate($cfg, $ctx, $EPA_MAY_ABSENCE);
            echo json_encode(['success' => true, 'cfg' => $cfg] + epa_payload($cfg, $ctx, $res));
            exit;
        }

        /* ---------------- Gespeicherte Planungen: Liste ---------------- */
        if ($act === 'plan_list') {
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
            echo json_encode(['success' => true, 'items' => $q->fetchAll(), 'me' => $ME]);
            exit;
        }

        /* ---------------- Gespeicherte Planung sichern ---------------- */
        if ($act === 'plan_save') {
            $cfg  = epa_cfg(json_decode($_POST['config'] ?? '[]', true) ?: []);
            $plan = json_decode($_POST['plan'] ?? '[]', true) ?: [];
            $id   = (int)($_POST['id'] ?? 0);
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150) ?: $cfg['title'];
            $stats = json_decode($_POST['stats'] ?? '[]', true) ?: [];

            $result = json_encode(['plan' => $plan, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
            $conf   = json_encode($cfg, JSON_UNESCAPED_UNICODE);
            $shared = !empty($_POST['shared']) ? 1 : 0;

            if ($id > 0) {
                /* Nur eigene Planungen dürfen überschrieben werden. */
                $own = $db->prepare('SELECT created_by FROM planning_runs WHERE id = ?');
                $own->execute([$id]);
                if ((int)$own->fetchColumn() !== $ME) {
                    echo json_encode(['success' => false, 'error' => 'Diese Planung gehört jemand anderem. Bitte als neue Planung sichern.']);
                    exit;
                }
                $db->prepare(
                    'UPDATE planning_runs
                        SET title = ?, plan_area_id = ?, start_date = ?, end_date = ?,
                            config_json = ?, result_json = ?, slots_total = ?, slots_filled = ?,
                            conflicts = ?, shared = ?, is_saved = 1, updated_at = NOW()
                      WHERE id = ?')
                  ->execute([$name, $cfg['area_id'], $cfg['start'], $cfg['end'], $conf, $result,
                             (int)($stats['total'] ?? 0), (int)($stats['filled'] ?? 0),
                             (int)($stats['blocks'] ?? 0) + (int)($stats['warns'] ?? 0), $shared, $id]);
            } else {
                $db->prepare(
                    'INSERT INTO planning_runs
                        (title, plan_area_id, start_date, end_date, config_json, result_json,
                         status, is_saved, shared, slots_total, slots_filled, conflicts,
                         created_by, created_at, updated_at)
                     VALUES (?,?,?,?,?,?, "entwurf", 1, ?, ?,?,?, ?, NOW(), NOW())')
                  ->execute([$name, $cfg['area_id'], $cfg['start'], $cfg['end'], $conf, $result, $shared,
                             (int)($stats['total'] ?? 0), (int)($stats['filled'] ?? 0),
                             (int)($stats['blocks'] ?? 0) + (int)($stats['warns'] ?? 0), $ME]);
                $id = (int)$db->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id, 'msg' => 'Planung gespeichert.']);
            exit;
        }

        /* ---------------- Gespeicherte Planung laden ---------------- */
        if ($act === 'plan_load') {
            $q = $db->prepare('SELECT * FROM planning_runs WHERE id = ? AND is_saved = 1');
            $q->execute([(int)($_POST['id'] ?? 0)]);
            $row = $q->fetch();
            if (!$row || ((int)$row['created_by'] !== $ME && empty($row['shared']))) {
                echo json_encode(['success' => false, 'error' => 'Planung nicht gefunden oder nicht freigegeben.']);
                exit;
            }
            $cfg = epa_cfg(json_decode($row['config_json'], true) ?: []);
            $res = json_decode($row['result_json'], true) ?: [];
            /* Frisch gegen die Datenbank prüfen – zwischenzeitlich kann sich
               am Urlaub oder an anderen Einsätzen einiges geändert haben. */
            $ctx  = epa_context($db, $cfg, epa_scope($cfg));
            $gen  = epa_generate($cfg, $ctx, $EPA_MAY_ABSENCE);
            $data = epa_payload($cfg, $ctx, $gen);
            $data['success'] = true;
            $data['config']  = $cfg;
            $data['plan']    = $res['plan'] ?? $gen['plan'];   // gespeicherte Besetzung hat Vorrang
            $data['run_id']  = (int)$row['id'];
            $data['name']    = $row['title'];
            $data['shared']  = (int)$row['shared'];
            $data['own']     = ((int)$row['created_by'] === $ME);
            echo json_encode($data);
            exit;
        }

        /* ---------------- Gespeicherte Planung löschen ---------------- */
        if ($act === 'plan_delete') {
            $id = (int)($_POST['id'] ?? 0);
            $q = $db->prepare('SELECT created_by FROM planning_runs WHERE id = ? AND is_saved = 1');
            $q->execute([$id]);
            $owner = $q->fetchColumn();
            if ($owner === false || (int)$owner !== $ME) {
                echo json_encode(['success' => false, 'error' => 'Nur eigene Planungen können gelöscht werden.']);
                exit;
            }
            $db->prepare('UPDATE planning_runs SET is_saved = 0 WHERE id = ?')->execute([$id]);
            echo json_encode(['success' => true, 'msg' => 'Planung entfernt.']);
            exit;
        }

        /* ------------- Kandidatenliste mit Stammbereich und Erfahrung ------- */
        if ($act === 'candidates') {
            $cfg = epa_cfg(json_decode($_POST['config'] ?? '[]', true) ?: []);
            $ctx = epa_context($db, $cfg, $scope);
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
            echo json_encode(['success' => true, 'items' => $out,
                              'area' => $ctx['areas'][$cfg['area_id']] ?? null]);
            exit;
        }

        /* ------------------------------------- Alternativen für einen Tag */
        if ($act === 'suggest') {
            $cfg  = epa_cfg(json_decode($_POST['config'] ?? '[]', true) ?: []);
            $date = $_POST['date'] ?? '';
            $ctx  = epa_context($db, $cfg, $scope);
            $day  = null;
            foreach ($ctx['days'] as $d) if ($d['date'] === $date) { $day = $d; break; }
            if (!$day) { echo json_encode(['success' => false, 'error' => 'Tag liegt außerhalb des Zeitraums.']); exit; }

            $taken = array_flip(array_map('intval', (array)json_decode($_POST['taken'] ?? '[]', true)));
            $out = [];
            foreach ($ctx['people'] as $uid => $p) {
                if (isset($taken[$uid])) continue;
                $av = epa_availability($p, $day, $cfg, $ctx, $EPA_MAY_ABSENCE);
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
            echo json_encode(['success' => true, 'items' => array_slice($out, 0, 60)]);
            exit;
        }

        /* ------------------------------------------- Übernahme als Entwurf */
        if ($act === 'apply') {
            $cfg  = epa_cfg(json_decode($_POST['config'] ?? '[]', true) ?: []);
            $plan = json_decode($_POST['plan'] ?? '[]', true) ?: [];
            if (!$cfg['area_id']) { echo json_encode(['success' => false, 'error' => 'Bitte zuerst einen Einsatzbereich wählen.']); exit; }

            $ctx = epa_context($db, $cfg, $scope);
            $allowed = $ctx['people'];
            $dayIdx = [];
            foreach ($ctx['days'] as $d) $dayIdx[$d['date']] = $d;

            $written = 0; $skipped = [];
            $db->beginTransaction();

            $runStmt = $db->prepare('INSERT INTO planning_runs
                (title, plan_area_id, start_date, end_date, config_json, result_json, status,
                 slots_total, slots_filled, conflicts, created_by, applied_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $runStmt->execute([
                $cfg['title'], $cfg['area_id'], $cfg['start'], $cfg['end'],
                json_encode($cfg, JSON_UNESCAPED_UNICODE), json_encode($plan, JSON_UNESCAPED_UNICODE),
                'uebernommen', 0, 0, 0, $ME,
            ]);
            $runId = (int)$db->lastInsertId();

            $ins = $db->prepare(
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

            foreach ($plan as $date => $uids) {
                if (!isset($dayIdx[$date])) { continue; }
                foreach ((array)$uids as $uid) {
                    $uid = (int)$uid;
                    if (!isset($allowed[$uid])) { $skipped[] = 'Kein Planungsrecht (#' . $uid . ')'; continue; }

                    /* Serverseitige Sicherung: Blocker niemals überschreiben. */
                    $st = $ctx['state'][$uid][$date] ?? null;
                    $forced = in_array($uid . '|' . $date, $cfg['force_cells'], true);
                    if ($st) {
                        if ($st['t'] === 'gesperrt' || $st['t'] === 'schule') {
                            $skipped[] = $allowed[$uid]['name'] . ' am ' . date('d.m.', strtotime($date)) . ': ' . $st['l'];
                            continue;
                        }
                        if ($st['t'] === 'abwesend'
                            && !((($cfg['rules']['overwrite'] === 'all') || $forced) && $EPA_MAY_ABSENCE)) {
                            $skipped[] = $allowed[$uid]['name'] . ' am ' . date('d.m.', strtotime($date)) . ': ' . $st['l'];
                            continue;
                        }
                        /* Fremder Einsatz: nur ersetzen, wenn es ausdrücklich erlaubt wurde –
                           entweder global über die Regel oder per Klick für genau diese Zelle. */
                        if ($st['t'] === 'einsatz' && (int)($st['a'] ?? 0) !== (int)$cfg['area_id']
                            && !$forced
                            && !in_array((int)($st['a'] ?? 0), $cfg['free_areas'], true)
                            && !in_array($cfg['rules']['overwrite'], ['assignments', 'all'], true)) {
                            $skipped[] = $allowed[$uid]['name'] . ' am ' . date('d.m.', strtotime($date))
                                       . ': bereits geplant (' . $st['l'] . ')';
                            continue;
                        }
                    }

                    /* Zelle sperren; Entwürfe anderer Planer nicht stillschweigend
                       überschreiben (Optimistic Concurrency, wie api/save_bulk). */
                    $cell = planCellLoad($db, [[$uid, $date]], true)[planCellKey($uid, $date)];
                    if ($cell['draft'] && (int)$cell['draft']['created_by'] !== $ME && !$forced
                        && (int)($cell['draft']['planning_run_id'] ?? 0) === 0) {
                        $skipped[] = $allowed[$uid]['name'] . ' am ' . date('d.m.', strtotime($date)) . ': offener Entwurf eines anderen Planers';
                        continue;
                    }
                    planChangeLog($db, $uid, $date, 'assistant_apply', planCellSnapshot($cell),
                        ['draft' => ['action' => 'set', 'type' => 'assignment', 'value' => (string)$cfg['area_id'], 'by' => $ME], 'run_id' => $runId], $ME, 'assistant');
                    $ins->execute([
                        $uid, $date, (int)date('Y', strtotime($date)), (int)date('n', strtotime($date)),
                        (string)$cfg['area_id'],
                        $cfg['remarks'] !== '' ? $cfg['remarks'] : $cfg['title'],
                        ($cfg['internal'] && $EPA_MAY_INTERNAL) ? 1 : 0,
                        $ME, $runId,
                    ]);
                    $written++;
                }
            }

            $db->prepare('UPDATE planning_runs SET slots_filled = ?, conflicts = ? WHERE id = ?')
               ->execute([$written, count($skipped), $runId]);
            $db->commit();

            /* Protokolleintrag – dieselbe Tabelle wie Login und Einstellungen. */
            try {
                $db->prepare('INSERT INTO audit_log(user_id, action, details, ip_address) VALUES(?,?,?,?)')
                   ->execute([
                       $ME, 'planning_assistant_apply',
                       'Lauf #' . $runId . ($cfg['cross_team'] ? ' (teamübergreifend)' : '')
                       . ' – ' . $written . ' Entwürfe für '
                       . ($ctx['areas'][$cfg['area_id']]['short_code'] ?? '?')
                       . ' (' . $cfg['start'] . ' bis ' . $cfg['end'] . '): ' . $cfg['title'],
                       opsClientIp(),
                   ]);
            } catch (Exception $e) { /* Protokoll ist optional */ }

            echo json_encode([
                'success' => true, 'run_id' => $runId, 'written' => $written,
                'skipped' => array_slice($skipped, 0, 40), 'skipped_total' => count($skipped),
                'msg' => $written . ' Einträge als Entwurf übernommen.',
            ]);
            exit;
        }

        /* ------------------------------------------- Übernahme zurücknehmen */
        if ($act === 'undo') {
            $runId = (int)($_POST['run_id'] ?? 0);
            $chk = $db->prepare('SELECT created_by FROM planning_runs WHERE id = ?');
            $chk->execute([$runId]); $row = $chk->fetch();
            if (!$row) { echo json_encode(['success' => false, 'error' => 'Lauf nicht gefunden.']); exit; }
            if ((int)$row['created_by'] !== $ME && !hasPerm('manage_settings')) {
                echo json_encode(['success' => false, 'error' => 'Nur der Ersteller kann diese Übernahme zurücknehmen.']); exit;
            }
            $db->beginTransaction();
            $rows = $db->prepare('SELECT user_id, plan_date FROM plan_drafts WHERE planning_run_id = ? FOR UPDATE');
            $rows->execute([$runId]);
            foreach ($rows->fetchAll() as $r) {
                planChangeLog($db, (int)$r['user_id'], $r['plan_date'], 'assistant_undo', ['run_id' => $runId], null, $ME, 'assistant');
            }
            $del = $db->prepare('DELETE FROM plan_drafts WHERE planning_run_id = ?');
            $del->execute([$runId]);
            $db->commit();
            $db->prepare("UPDATE planning_runs SET status='verworfen' WHERE id = ?")->execute([$runId]);
            echo json_encode(['success' => true, 'msg' => $del->rowCount() . ' Entwürfe zurückgenommen.']);
            exit;
        }

        /* -------------------------------------------------------- Vorlagen */
        if ($act === 'profile_save') {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);
            if ($name === '') { echo json_encode(['success' => false, 'error' => 'Bitte einen Namen für die Vorlage angeben.']); exit; }
            $cfg = epa_cfg(json_decode($_POST['config'] ?? '[]', true) ?: []);
            $id  = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare('UPDATE planning_profiles SET name=?, description=?, plan_area_id=?, config_json=? WHERE id=? AND (created_by=? OR ?=1)')
                   ->execute([$name, mb_substr((string)($_POST['description'] ?? ''), 0, 255), $cfg['area_id'] ?: null,
                              json_encode($cfg, JSON_UNESCAPED_UNICODE), $id, $ME, hasPerm('manage_settings') ? 1 : 0]);
            } else {
                $db->prepare('INSERT INTO planning_profiles (name, description, plan_area_id, config_json, is_shared, created_by) VALUES (?,?,?,?,?,?)')
                   ->execute([$name, mb_substr((string)($_POST['description'] ?? ''), 0, 255), $cfg['area_id'] ?: null,
                              json_encode($cfg, JSON_UNESCAPED_UNICODE), !empty($_POST['shared']) ? 1 : 0, $ME]);
                $id = (int)$db->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id, 'msg' => 'Vorlage gespeichert.']);
            exit;
        }

        if ($act === 'profile_list') {
            $s = $db->prepare('SELECT p.id, p.name, p.description, p.plan_area_id, p.is_shared, p.use_count,
                                      p.created_by, p.updated_at, a.short_code
                                 FROM planning_profiles p
                                 LEFT JOIN plan_areas a ON a.id = p.plan_area_id
                                WHERE p.is_shared = 1 OR p.created_by = ?
                                ORDER BY p.updated_at DESC LIMIT 100');
            $s->execute([$ME]);
            echo json_encode(['success' => true, 'items' => $s->fetchAll(PDO::FETCH_ASSOC), 'me' => $ME]);
            exit;
        }

        if ($act === 'profile_load') {
            $s = $db->prepare('SELECT config_json FROM planning_profiles WHERE id = ? AND (is_shared = 1 OR created_by = ?)');
            $s->execute([(int)($_POST['id'] ?? 0), $ME]);
            $r = $s->fetch();
            if (!$r) { echo json_encode(['success' => false, 'error' => 'Vorlage nicht gefunden.']); exit; }
            $db->prepare('UPDATE planning_profiles SET use_count = use_count + 1 WHERE id = ?')->execute([(int)$_POST['id']]);
            echo json_encode(['success' => true, 'config' => json_decode($r['config_json'], true)]);
            exit;
        }

        if ($act === 'profile_delete') {
            $st = $db->prepare('DELETE FROM planning_profiles WHERE id = ? AND (created_by = ? OR ? = 1)');
            $st->execute([(int)($_POST['id'] ?? 0), $ME, hasPerm('manage_settings') ? 1 : 0]);
            echo json_encode(['success' => $st->rowCount() > 0, 'msg' => 'Vorlage gelöscht.',
                              'error' => $st->rowCount() ? '' : 'Löschen nicht erlaubt.']);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion.']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* =============================================================================
   SEITENDATEN (Erstaufbau)
   ========================================================================== */
$scope = epa_scope();   // Seitenaufbau: zunächst nur das eigene Team

$areas = $db->query('SELECT id, name, short_code, color, fg_color, area_type FROM plan_areas ORDER BY area_type DESC, sort_order, name')->fetchAll();

$roleRows = [];
try { $roleRows = $db->query('SELECT role_key, role_label, color, base_role, sort_order FROM custom_roles ORDER BY sort_order')->fetchAll(); } catch (Exception $e) {}
if (!$roleRows) {
    $roleRows = [
        ['role_key' => 'manager', 'role_label' => 'Manager', 'color' => '#2563eb', 'base_role' => 'manager', 'sort_order' => 2],
        ['role_key' => 'teamleiter', 'role_label' => 'Teamleiter', 'color' => '#7c3aed', 'base_role' => 'teamleiter', 'sort_order' => 3],
        ['role_key' => 'mitarbeiter', 'role_label' => 'Techniker', 'color' => '#059669', 'base_role' => 'mitarbeiter', 'sort_order' => 6],
        ['role_key' => 'azubi_1', 'role_label' => 'Azubi 1. LJ', 'color' => '#ec4899', 'base_role' => 'azubi_1', 'sort_order' => 7],
        ['role_key' => 'azubi_2', 'role_label' => 'Azubi 2. LJ', 'color' => '#f472b6', 'base_role' => 'azubi_2', 'sort_order' => 8],
        ['role_key' => 'azubi_3', 'role_label' => 'Azubi 3. LJ', 'color' => '#f9a8d4', 'base_role' => 'azubi_3', 'sort_order' => 9],
    ];
}

$uSql = 'SELECT id, first_name, last_name, role, os_level, training_profession, primary_area_id
           FROM users WHERE active = 1 AND role <> \'admin\'';
$uPar = [];
if ($scope !== null) {
    if (!$scope) { $scope = [0]; }
    $uSql .= ' AND id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
    $uPar = $scope;
}
$uSql .= ' ORDER BY last_name, first_name';
$us = $db->prepare($uSql); $us->execute($uPar);
$userRows = $us->fetchAll();

$osLevels = array_values(array_filter(array_unique(array_map(function ($u) { return trim((string)$u['os_level']); }, $userRows))));
sort($osLevels);
$professions = array_values(array_filter(array_unique(array_map(function ($u) { return trim((string)$u['training_profession']); }, $userRows))));
sort($professions);

$boot = [
    'areas'   => $areas,
    'roles'   => $roleRows,
    'users'   => $userRows,
    'os'      => $osLevels,
    'profs'   => $professions,
    'perm'    => ['absence' => $EPA_MAY_ABSENCE, 'internal' => $EPA_MAY_INTERNAL,
                  'all' => ($scope === null)],
    /* Allgemein-Bereiche mit Vorschlag, welche als freie Kapazitaet gelten.
       Bewusst nicht am Kuerzel festgemacht – das heisst nicht ueberall "T". */
    'general' => array_values(array_map(function ($a) {
                     $txt = mb_strtolower($a['name'] . ' ' . $a['short_code']);
                     $a['default_free'] = !preg_match('/schul|urlaub|krank|feiertag|frei|abwesen/u', $txt);
                     return $a;
                 }, array_filter($areas, function ($a) {
                     return $a['area_type'] === 'allgemein' && strtoupper($a['short_code']) !== 'BS';
                 }))),
    'defaults'     => $EPA_DEFAULTS,
    'may_cross'    => epa_may_cross_team(),
    'own_team_only'=> ($scope !== null),
    'today'        => date('Y-m-d'),
    'planning_url' => $EPA_BP . 'monatsplanung',
];

$pageTitle = 'Planungsassistent';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Planungsassistent – nur Ergänzungen, die es in der App noch nicht gibt.
   Alles Übrige kommt aus style.css (card/ch/cb, fg, wz-steps, pt, modal …). */
.epa-head { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin-bottom: .75rem; }
.epa-title { font-size: 1.02rem; font-weight: 800; }
/* minmax(0, 1fr) ist entscheidend: ohne das wächst die linke Spalte mit der
   Tabellenbreite mit und schiebt die Prüfliste aus dem Bild. */
.epa-cols { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: .85rem; align-items: start; }
.epa-cols > .card { min-width: 0; }
.epa-cols > .card:last-child { position: sticky; top: .5rem; }
@media (max-width: 1150px) { .epa-cols { grid-template-columns: minmax(0, 1fr); }
                             .epa-cols > .card:last-child { position: static; } }

/* Bedarf je Wochentag */
.epa-dgrid { display: grid; grid-template-columns: repeat(7, 1fr); gap: .4rem; }
.epa-dcell { border: 1px solid var(--bd); border-radius: var(--r); padding: .4rem .3rem; text-align: center; }
.epa-dcell.epa-off { background: var(--s); border-style: dashed; opacity: .55; }
.epa-dcell b { display: block; font-size: .68rem; color: var(--mu); text-transform: uppercase; letter-spacing: .04em; margin-bottom: .2rem; }
.epa-dcell input { width: 100%; border: none; text-align: center; font-size: .95rem; font-weight: 700; background: none; padding: 0; color: var(--tx); }
.epa-dcell input:focus { outline: none; color: var(--p); }

/* Sondertage */
.epa-sd { display: flex; align-items: center; gap: .45rem; padding: .35rem .45rem; border: 1px solid var(--bd); border-radius: var(--r); margin-bottom: .35rem; }
.epa-sd input { padding: .28rem .4rem; border: 1px solid var(--bd); border-radius: var(--r); font-size: .8rem; }
.epa-sd input[type=date] { width: 140px; } .epa-sd input[type=number] { width: 62px; }
.epa-sd input[type=text] { flex: 1; min-width: 0; }

/* Zahlenfeld in Einstellungszeilen */
.epa-row { display: flex; align-items: center; gap: .75rem; padding: .5rem 0; border-bottom: 1px solid var(--bd); }
.epa-row:last-child { border-bottom: none; }
.epa-row-t { font-size: .85rem; font-weight: 600; }
.epa-row-d { font-size: .76rem; color: var(--mu); line-height: 1.45; }
.epa-row-r { margin-left: auto; display: flex; align-items: center; gap: .35rem; flex-shrink: 0; }
.epa-num { width: 64px; padding: .3rem .35rem; border: 1px solid var(--bd); border-radius: var(--r); text-align: center; font-weight: 700; font-size: .85rem; }

/* Gewichtungsregler */
.epa-w { display: grid; grid-template-columns: 1fr 140px 38px; align-items: center; gap: .7rem; padding: .45rem 0; border-bottom: 1px solid var(--bd); }
.epa-w:last-child { border-bottom: none; }
.epa-w input[type=range] { width: 100%; accent-color: var(--p); }
.epa-w-v { font-size: .82rem; font-weight: 800; text-align: right; color: var(--p); }

/* Ergebnis: Matrix auf Basis der Planungstabelle (.pt) */
.epa-ps { overflow: auto; max-height: calc(100vh - 330px); border: 1px solid var(--bd); border-radius: var(--r); }
.epa-ps .pt { font-size: .74rem; }
.epa-ps .pt th.cn, .epa-ps .pt td.cn { width: 148px; min-width: 148px; max-width: 148px; text-align: left !important;
                                        padding: 2px 5px; position: sticky; left: 0; z-index: 15; background: #fff; }
@media (min-width: 1500px) { .epa-ps .pt th.cn, .epa-ps .pt td.cn { width: 176px; min-width: 176px; max-width: 176px; } }
.epa-nm { gap: .25rem; }
.epa-nm-t { font-size: .72rem; }
.epa-ps .pt thead th.cn { z-index: 26; background: #f8fafc; }
.epa-ps .pt tbody tr:hover td.cn { background: #eef4ff; }
.epa-cell { display: block; width: 100%; height: 26px; border: none; background: none; padding: 0; font-size: .68rem; font-weight: 700; cursor: pointer; }
.epa-cell:hover { box-shadow: inset 0 0 0 2px var(--pl); }
.epa-cell.pick { color: #fff; }
.epa-cell.ov { background-image: repeating-linear-gradient(135deg, rgba(0,0,0,0) 0 4px, rgba(255,255,255,.35) 4px 8px); }
/* Ersetzte Einträge: kleines Eckdreieck in der Farbe des bisherigen Bereichs.
   Das liest sich auf 26 px Zellhöhe deutlich besser als ein Randstreifen. */
.epa-cell.fromfree, .epa-cell.fromplan { font-size: .6rem; letter-spacing: -.02em; }
.epa-cell.fromfree::before, .epa-cell.fromplan::before {
  content: ''; position: absolute; left: 0; top: 0; width: 0; height: 0;
  border-top: 9px solid var(--epa-old, #64748b); border-right: 9px solid transparent; }
.epa-cell.fromplan::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0;
  height: 2px; background: repeating-linear-gradient(90deg, rgba(255,255,255,.85) 0 3px, transparent 3px 6px); }
.epa-cell.fromfree::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0;
  height: 2px; background: rgba(255,255,255,.6); }
.epa-cell.bad { box-shadow: inset 0 0 0 2px var(--err); }
.epa-cell.skip { cursor: default; background: #fafbfc; }
.epa-cell.skip:hover { box-shadow: none; }
.epa-cell.abs { background: var(--errbg); color: var(--err); font-size: .62rem; }
.epa-cell.lock { background: repeating-linear-gradient(135deg, #f1f5f9 0 4px, #e2e8f0 4px 8px); }
.epa-nm { display: flex; align-items: center; gap: .35rem; }
.epa-nm-t { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; font-size: .76rem; font-weight: 600; }
.epa-av { width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: .58rem; font-weight: 800; flex-shrink: 0; }
.epa-load { font-size: .64rem; font-weight: 800; color: var(--mu); background: var(--s); border: 1px solid var(--bd); border-radius: 4px; padding: 0 .25rem; }
.epa-foot-cnt { font-size: .7rem; font-weight: 800; }
.epa-foot-cnt.short { color: var(--err); }
.epa-foot-cnt.ok { color: var(--ok); }

/* Kennzahlen */
.epa-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(118px, 1fr)); gap: .5rem; margin-bottom: .75rem; }
.epa-kpi { background: var(--card); border: 1px solid var(--bd); border-radius: var(--r2); padding: .55rem .7rem; box-shadow: var(--sh); }
.epa-kpi-n { font-size: 1.3rem; font-weight: 800; line-height: 1.1; }
.epa-kpi-l { font-size: .66rem; color: var(--mu); font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.epa-bar { height: 4px; border-radius: 3px; background: var(--bd); overflow: hidden; margin-top: .35rem; }
.epa-bar i { display: block; height: 100%; }

/* Prüfliste */
.epa-cf { display: flex; gap: .5rem; width: 100%; text-align: left; align-items: flex-start; padding: .45rem .5rem; border: 1px solid var(--bd); border-radius: var(--r); margin-bottom: .35rem; background: #fff; cursor: pointer; }
.epa-cf:hover { background: var(--s); }
.epa-cf.block { border-left: 3px solid var(--err); }
.epa-cf.warn { border-left: 3px solid var(--wn); }
.epa-cf.info { border-left: 3px solid var(--inf); }
.epa-cf-m { font-size: .78rem; line-height: 1.4; }
.epa-cf-d { font-size: .68rem; color: var(--mu); }
.epa-cf-list { max-height: calc(100vh - 400px); overflow-y: auto; }

/* Personenliste im Dialog */
.epa-alt { display: flex; align-items: center; gap: .5rem; padding: .4rem .5rem; border: 1px solid var(--bd); border-radius: var(--r); margin-bottom: .3rem; }
.epa-alt.no { opacity: .6; background: var(--s); }
.epa-alt-r { font-size: .7rem; color: var(--mu); margin-left: auto; }

.epa-hero { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
            flex-wrap: wrap; margin-bottom: .9rem; }
.epa-plans { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: .6rem; }
.epa-plan { border: 1px solid var(--bd); border-radius: var(--r2); padding: .7rem .8rem; background: #fff;
            display: flex; flex-direction: column; gap: .45rem; transition: box-shadow .12s, border-color .12s; }
.epa-plan:hover { box-shadow: var(--sh); border-color: var(--pl); }
.epa-plan-h { display: flex; align-items: center; gap: .4rem; }
.epa-plan-a { font-size: .64rem; font-weight: 800; padding: .1rem .35rem; border-radius: 4px; flex-shrink: 0; }
.epa-plan-t { font-size: .92rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.epa-plan-m { font-size: .76rem; color: var(--mu); line-height: 1.45; }
.epa-plan-b { display: flex; gap: .35rem; align-items: center; margin-top: auto; padding-top: .35rem;
              border-top: 1px solid var(--bd); }
.epa-plan-b .btn { padding: .18rem .5rem; }
.epa-cov { height: 5px; border-radius: 3px; background: var(--bd); overflow: hidden; }
.epa-cov i { display: block; height: 100%; }
.epa-recap { border: 1px solid var(--bd); border-radius: var(--r); overflow: hidden; }
.epa-rc { display: grid; grid-template-columns: 170px 1fr auto; gap: .6rem; align-items: center;
          padding: .45rem .7rem; border-bottom: 1px solid var(--bd); font-size: .84rem; }
.epa-rc:last-child { border-bottom: none; }
.epa-rc:nth-child(even) { background: var(--s); }
.epa-rc-l { color: var(--mu); font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.epa-rc-v { min-width: 0; }
.epa-rc-v b { font-weight: 700; }
.epa-rc-v small { display: block; color: var(--mu); font-size: .74rem; }
.epa-rc.warn { border-left: 3px solid var(--wn); }
.epa-recap-note { margin-top: .6rem; font-size: .8rem; }
.epa-flash { animation: epaFlash 1.4s ease-out; }
@keyframes epaFlash { 0%, 40% { box-shadow: 0 0 0 3px rgba(226,0,26,.35); background: var(--pbg); } 100% { box-shadow: none; } }
.epa-cnt { display: inline-block; min-width: 15px; padding: 0 .2rem; margin-left: .25rem; border-radius: 7px;
           background: rgba(0,0,0,.12); font-size: .62rem; font-weight: 800; line-height: 15px; text-align: center; }
.epa-bond { font-size: .62rem; font-weight: 700; padding: .05rem .3rem; border-radius: 4px;
            background: var(--wnbg); color: #92400e; }
.epa-flex { font-size: .62rem; font-weight: 700; padding: .05rem .3rem; border-radius: 4px;
            background: var(--ibg); color: #075985; }
.epa-beta { display: inline-block; font-size: .62rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase;
            padding: .1rem .35rem; border-radius: 4px; background: var(--ibg); color: #0369a1; border: 1px solid #bae6fd; }
.epa-sum { font-size: .78rem; margin: -.35rem 0 .7rem; }
.epa-sum b { color: var(--tx); }

/* Matrix-Feinschliff */
.epa-ps .pt tbody tr:nth-child(even) td { background: #fbfcfd; }
.epa-ps .pt tbody tr:nth-child(even) td.cn { background: #fbfcfd; }
.epa-ps .pt tbody tr:hover td { background: #eef4ff; }
.epa-ps .pt th.kw-start, .epa-ps .pt td.kw-start { border-left: 2px solid var(--grid-strong); }
.epa-ps .pt thead th.short { background: #fff1f2 !important; }
.epa-ps .pt thead th.short .dn { color: var(--err); }
.epa-ps .pt thead th.today { background: var(--nv) !important; }
.epa-ps .pt thead th.today .dn, .epa-ps .pt thead th.today .wn, .epa-ps .pt thead th.today .kwl { color: #fff; }
.epa-ps .pt thead .kwl { font-size: .58rem; color: var(--mu); height: 11px; line-height: 11px; }
.epa-fbar { height: 4px; border-radius: 2px; background: var(--bd); overflow: hidden; margin: 2px 3px 0; }
.epa-fbar i { display: block; height: 100%; }
.epa-cell.repl::after { content: '⇄'; position: absolute; right: 1px; top: -1px; font-size: .6rem; opacity: .85; }
.epa-cell { position: relative; }

.epa-filter { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; margin-bottom: .4rem; }
.epa-legend { display: flex; gap: .8rem; flex-wrap: wrap; padding: .5rem .8rem; border-top: 1px solid var(--bd); font-size: .72rem; color: var(--mu); }
.epa-legend span { display: inline-flex; align-items: center; gap: .3rem; }
.epa-legend i { width: 14px; height: 12px; border-radius: 2px; border: 1px solid var(--bd); display: inline-block; }
.epa-exp { font-size: .62rem; font-weight: 700; padding: .05rem .3rem; border-radius: 4px; background: var(--okbg); color: #15803d; }
.epa-home { font-size: .62rem; font-weight: 700; padding: .05rem .3rem; border-radius: 4px; background: var(--pbg); color: var(--p); }
.epa-kw { font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--mu);
          margin: .6rem 0 .3rem; padding-bottom: .2rem; border-bottom: 1px solid var(--bd); }
.epa-kw:first-child { margin-top: 0; }
.epa-day { border: 1px solid var(--bd); border-radius: var(--r); padding: .5rem .65rem; margin-bottom: .4rem; }
.epa-day.gap { border-left: 3px solid var(--err); }
.epa-day.full { border-left: 3px solid var(--ok); }
.epa-day.epa-off { background: var(--s); color: var(--mu); }
.epa-day-h { display: flex; align-items: center; gap: .5rem; font-size: .82rem; font-weight: 700; }
.epa-day-n { display: flex; gap: .3rem; flex-wrap: wrap; margin-top: .35rem; }
.epa-chip { font-size: .72rem; font-weight: 600; padding: .12rem .4rem; border-radius: 4px; background: var(--s); border: 1px solid var(--bd); }
.epa-chip.az { background: #fce7f3; border-color: #fbcfe8; color: #be185d; }
.epa-chip.hm { background: var(--pbg); border-color: rgba(226,0,26,.25); color: var(--p); }
.epa-lrow { display: grid; grid-template-columns: 190px 1fr 46px; gap: .5rem; align-items: center; padding: .3rem 0; border-bottom: 1px solid var(--bd); font-size: .8rem; }
.epa-lrow:last-child { border-bottom: none; }
.epa-lbar { height: 8px; border-radius: 4px; background: var(--bd); overflow: hidden; }
.epa-lbar i { display: block; height: 100%; background: var(--p); }

.epa-busy { position: fixed; inset: 0; background: rgba(243,244,246,.8); z-index: 900; display: none; align-items: center; justify-content: center; flex-direction: column; gap: .7rem; }
.epa-busy.on { display: flex; }

/* ---------- Druckansicht ---------- */
#epaPrint { display: none; }
@media print {
  body > *:not(#epaPrint) { display: none !important; }
  nav, .nav, .mob-nav, .app-footer, #toasts, #appLoader, .modal-bg, .epa-busy { display: none !important; }
  #epaPrint { display: block !important; position: static; }
  @page { size: A4 landscape; margin: 8mm; }
  html, body { width: 100%; margin: 0; padding: 0; }
  #epaPrint { font-size: 9pt; color: #000; width: 100%; max-width: none; }
  #epaPrint h1 { font-size: 15pt; margin-bottom: .25rem; }
  #epaPrint .epa-pmeta { font-size: 8.5pt; color: #444; margin-bottom: .6rem; }
  #epaPrint table { width: 100%; border-collapse: collapse; margin-bottom: .5rem; }
  #epaPrint th, #epaPrint td { border: 1px solid #999; padding: 2px 3px; font-size: 7.5pt; text-align: center; }
  #epaPrint th.epa-n, #epaPrint td.epa-n { text-align: left; white-space: nowrap; }
  #epaPrint thead th { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint .epa-pk { background: #ddd !important; font-weight: 700; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint .epa-we { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint .epa-sec { font-weight: 700; margin: .5rem 0 .25rem; font-size: 10pt; }
  #epaPrint ul { margin-left: 1rem; font-size: 8pt; }
  #epaPrint .epa-pfoot { margin-top: .6rem; font-size: 7.5pt; color: #555; border-top: 1px solid #999; padding-top: .25rem; }
  /* Tabellenkopf auf jeder Seite wiederholen, Zeilen nicht zerreissen */
  #epaPrint thead { display: table-header-group; }
  #epaPrint tr, #epaPrint td, #epaPrint th { page-break-inside: avoid; }
  /* Kopfdaten spannen über die ganze Breite, nicht als schmaler Block links */
  #epaPrint table.epa-hd { width: 100%; table-layout: fixed; margin: 0 0 .35rem;
                         border-bottom: 1.5px solid #444; }
  #epaPrint table.epa-hd td { border: none; text-align: left; font-size: 8.5pt;
                            padding: 0 .5rem .3rem 0; vertical-align: top; }
  #epaPrint table.epa-hd td b { font-size: 7pt; text-transform: uppercase; letter-spacing: .04em; color: #555; }
  #epaPrint table.epa-lst th, #epaPrint table.epa-lst td { font-size: 8pt; padding: 2px 4px; }
  #epaPrint table.epa-lst tr.epa-kwsep td { background: #eee !important; font-weight: 700; text-align: left;
                                 -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint table.epa-lst tr.epa-off td { color: #777; }
  #epaPrint tr.epa-gap td, #epaPrint td.epa-gap { background: #f3f3f3 !important; font-weight: 700;
                                          -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint tr.epa-tot td { border-top: 2px solid #666; font-weight: 700; }
  #epaPrint .epa-sec.epa-pb { page-break-before: always; }

  /* ---- Einsatzmatrix ---- */
  #epaPrint table.epa-mx { table-layout: fixed; width: 100%; }
  #epaPrint table.epa-mx th, #epaPrint table.epa-mx td { padding: 2px 0; font-size: 9pt; line-height: 1.3; height: 20px; }
  #epaPrint table.epa-mx.sm th, #epaPrint table.epa-mx.sm td { font-size: 8pt; height: 17px; }
  #epaPrint table.epa-mx.xs th, #epaPrint table.epa-mx.xs td { font-size: 6.8pt; height: 14px; letter-spacing: -.02em; }
  #epaPrint table.epa-mx th.epa-n, #epaPrint table.epa-mx td.epa-n { width: 42mm; padding-left: 4px; font-size: 8.5pt; }
  #epaPrint table.epa-mx.xs th.nm, #epaPrint table.epa-mx.xs td.nm { width: 34mm; font-size: 7pt; }
  #epaPrint table.epa-mx td.epa-n .epa-az { font-size: 6pt; color: #555; }
  #epaPrint table.epa-mx th.epa-sum, #epaPrint table.epa-mx td.epa-sum { width: 9mm; font-weight: 700; background: #f2f2f2 !important;
      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint table.epa-mx .epa-kwrow th { border: none; }
  #epaPrint table.epa-mx th.epa-kwh { border: none; border-bottom: 1px solid #999; font-size: 6.5pt; font-weight: 700;
      color: #444; letter-spacing: .03em; padding-bottom: 1px; }
  #epaPrint table.epa-mx th .epa-dd { display: block; font-weight: 700; font-size: 9pt; }
  #epaPrint table.epa-mx th .epa-wd { display: block; font-size: 6.5pt; color: #555; font-weight: 400; }
  #epaPrint table.epa-mx.xs th .epa-dd { font-size: 6.8pt; }
  #epaPrint table.epa-mx.xs th .epa-wd { font-size: 5.5pt; }
  #epaPrint table.epa-mx .epa-wk { border-left: 1.4px solid #555 !important; }
  #epaPrint table.epa-mx .epa-we { background: #ececec !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint table.epa-mx .epa-off { background: #f7f7f7 !important; color: #999;
      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint table.epa-mx td.epa-pk { font-weight: 700; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint table.epa-mx td.epa-other { background: #dcdcdc !important; color: #333; font-size: 6.5pt;
      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint table.epa-mx td.epa-abs { background: #fff !important; color: #777; font-weight: 700;
      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint table.epa-mx tr.epa-tot td { border-top: 1.6px solid #444; font-weight: 700; background: #fafafa !important;
      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint table.epa-mx tr.epa-tot td.epa-gap { background: #e6e6e6 !important; }
  #epaPrint table.epa-mx tbody tr:nth-child(even) td.nm { background: #fafafa !important;
      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint .epa-mxleg { display: flex; gap: .8rem; font-size: 6.5pt; color: #444; margin: .2rem 0 .5rem; }
  #epaPrint .epa-mxleg span { display: inline-flex; align-items: center; gap: .2rem; }
  #epaPrint .epa-mxleg i { width: 10px; height: 8px; display: inline-block; border: 1px solid #999;
      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #epaPrint .epa-mxleg .epa-lo { background: #dcdcdc; } #epaPrint .epa-mxleg .epa-la { background: #fff; }
  #epaPrint .epa-mxleg .epa-lw { background: #ececec; }
}
.epa-spin { width: 30px; height: 30px; border: 3px solid var(--bd); border-top-color: var(--p); border-radius: 50%; animation: epaSpin .7s linear infinite; }
@keyframes epaSpin { to { transform: rotate(360deg); } }
</style>

<!-- ================= Startseite: gespeicherte Planungen ================= -->
<div id="epaHome">
  <div class="epa-hero">
    <div>
      <h2 style="font-size:1.15rem;font-weight:800">Planungsassistent <span class="epa-beta">Beta</span></h2>
      <p class="muted" style="font-size:.84rem;margin-top:.15rem">Komplexe Einsätze und Kundenprojekte planen.</p>
    </div>
    <button class="btn btn-primary" onclick="EPA.newPlan()">
      <svg viewBox="0 0 20 20" fill="currentColor" width="15" height="15"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
      Neue Planung anlegen</button>
  </div>

  <div class="card">
    <div class="ch"><span>Gespeicherte Planungen</span>
      <span class="muted" id="homeCount" style="font-weight:400;font-size:.75rem"></span></div>
    <div class="cb" id="homeList"><p class="muted">Wird geladen …</p></div>
  </div>
</div>

<!-- ================= Assistent ================= -->
<div id="epaWizard" style="display:none">
<div class="epa-head">
  <h2>Planungsassistent</h2>
  <span class="epa-beta" title="Neue Funktion – noch in Erprobung">Beta</span>
  <span class="epa-title" id="epaSub">Neuer Einsatz</span>
  <span class="badge bw" id="epaRange">Zeitraum offen</span>
  <div style="margin-left:auto;display:flex;gap:.4rem">
    <button class="btn btn-outline btn-sm" onclick="EPA.home()">&larr; Übersicht</button>
    <button class="btn btn-outline btn-sm" onclick="EPA.savePlan()" id="btnSavePlan">Planung speichern</button>
    <button class="btn btn-outline btn-sm" onclick="EPA.openProfiles()">Vorlagen</button>
    <button class="btn btn-outline btn-sm" onclick="EPA.saveProfile()">Als Vorlage sichern</button>
    <button class="btn btn-outline btn-sm" onclick="EPA.reset()">Zurücksetzen</button>
    <a class="btn btn-outline btn-sm" href="<?php echo e($EPA_BP); ?>monatsplanung">Zur Monatsplanung</a>
  </div>
</div>

<div class="alert" style="display:flex;gap:.6rem;align-items:flex-start;background:var(--ibg);border:1px solid #bae6fd;color:#075985;border-radius:var(--r2);padding:.6rem .85rem;margin-bottom:.75rem;font-size:.82rem">
  <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" style="flex-shrink:0;margin-top:1px"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
  <div><b>Beta.</b> Der Assistent schreibt nur <b>Entwürfe</b> – veröffentlicht wird in der Monatsplanung, jede Übernahme ist rücknehmbar.</div>
</div>

<div class="card">
  <div class="wz-steps" id="epaSteps">
    <button type="button" class="wz-step active" data-step="0" onclick="EPA.go(0)"><span class="wz-num">1</span><span class="wz-label">Einsatz &amp; Zeitraum</span></button>
    <button type="button" class="wz-step" data-step="1" onclick="EPA.go(1)"><span class="wz-num">2</span><span class="wz-label">Bedarf</span></button>
    <button type="button" class="wz-step" data-step="2" onclick="EPA.go(2)"><span class="wz-num">3</span><span class="wz-label">Team &amp; Azubis</span></button>
    <button type="button" class="wz-step" data-step="3" onclick="EPA.go(3)"><span class="wz-num">4</span><span class="wz-label">Regeln</span></button>
    <button type="button" class="wz-step" data-step="4" onclick="EPA.go(4)"><span class="wz-num">5</span><span class="wz-label">Zusammenfassung</span></button>
    <button type="button" class="wz-step" data-step="5" onclick="EPA.go(5)"><span class="wz-num">6</span><span class="wz-label">Entwurf</span></button>
  </div>

  <div class="cb">

    <!-- ---------------- Schritt 1 ---------------- -->
    <div class="wz-panel" data-panel="0">
      <div class="wz-sec-title">Einsatz</div>
      <p class="wz-hint">Bezeichnung und Einsatzbereich stehen später an jedem Planeintrag.</p>
      <div class="fg2">
        <div class="fg"><label>Bezeichnung</label><input type="text" id="fTitle" placeholder="z. B. Rollout Kunde Nord – Phase 2"></div>
        <div class="fg"><label>Einsatzbereich / Kunde</label><select id="fArea"></select></div>
      </div>
      <div class="fg"><label>Bemerkung am Eintrag (optional)</label><input type="text" id="fRemarks" placeholder="Leer lassen = Bezeichnung verwenden"></div>
      <div class="fg-check" id="rowInternal"><input type="checkbox" id="fInternal"><label for="fInternal">Als interne Eintragung markieren (nur für Berechtigte sichtbar)</label></div>

      <div class="wz-sec-title" style="margin-top:1rem">Zeitraum</div>
      <p class="wz-hint">Höchstens 120 Tage je Lauf. Längere Projekte in Etappen planen.</p>
      <div class="fg2">
        <div class="fg"><label>Von</label><input type="date" id="fStart"></div>
        <div class="fg"><label>Bis</label><input type="date" id="fEnd"></div>
      </div>
      <div class="fg">
        <label>Einsatztage</label>
        <div class="wd-checks" id="fWeekdays"></div>
      </div>
      <div class="fg-check"><input type="checkbox" id="fSkipHol" checked><label for="fSkipHol">Gesetzliche Feiertage überspringen</label></div>
    </div>

    <!-- ---------------- Schritt 2 ---------------- -->
    <div class="wz-panel" data-panel="1" style="display:none">
      <div class="wz-sec-title">Regelbedarf</div>
      <p class="wz-hint">Gilt für jeden aktiven Tag, sofern darunter nichts Abweichendes steht.</p>
      <div class="epa-row">
        <div><div class="epa-row-t">Personen pro Tag (Soll)</div><div class="epa-row-d">Zielbesetzung, die der Assistent zu erreichen versucht.</div></div>
        <div class="epa-row-r"><input type="number" class="epa-num" id="fDemand" value="2" min="1" max="99"></div>
      </div>
      <div class="epa-row">
        <div><div class="epa-row-t">Mindestbesetzung</div><div class="epa-row-d">Wird sie unterschritten, meldet die Prüfung einen Blocker statt einer Warnung.</div></div>
        <div class="epa-row-r"><input type="number" class="epa-num" id="fDemandMin" value="1" min="0" max="99"></div>
      </div>

      <div class="wz-sec-title" style="margin-top:1rem">Bedarf je Wochentag</div>
      <p class="wz-hint">Leer heißt Regelbedarf. Ausgegraute Tage sind in Schritt 1 nicht aktiviert.</p>
      <div class="epa-dgrid" id="fDemandWd"></div>

      <div class="wz-sec-title" style="margin-top:1rem">Sondertage</div>
      <p class="wz-hint">Einzelne Termine mit abweichender Besetzung. Wert 0 nimmt den Tag aus der Planung.</p>
      <div id="fSpecial"></div>
      <button class="btn btn-outline btn-xs" style="margin-top:.4rem" onclick="EPA.addSpecial()">+ Sondertag</button>

      <div class="wz-sec-title" style="margin-top:1rem">Fachliche Anforderungen</div>
      <p class="wz-hint">Der Assistent bevorzugt passende Profile und meldet, wenn eine Vorgabe an einem Tag nicht erfüllt ist.</p>
      <div class="fg2">
        <div class="fg"><label>Mindest-OS-Level</label><select id="fOsLevel"></select></div>
        <div class="fg"><label>Davon mindestens (Personen/Tag)</label><input type="number" id="fOsCount" value="0" min="0" max="99"></div>
      </div>
      <div class="fg2">
        <div class="fg"><label>Aus dem Stammbereich (Personen/Tag)</label>
          <input type="number" id="fHomeCount" value="0" min="0" max="99">
          <small class="muted">Diese Plätze werden gezielt mit Leuten besetzt, die den Kunden betreuen.</small></div>
        <div class="fg"><label>Ausbildungsberuf bevorzugen</label><div class="wd-checks" id="fProfs"></div></div>
      </div>
      <div class="fg2">
        <div class="fg"><label>Stammbereich der Mitarbeiter</label>
          <select id="fHomeMode">
            <option value="prefer">Bevorzugen – Stammbereich zuerst, andere ergänzen</option>
            <option value="only">Ausschließlich – nur Stammbereich oder Kundenerfahrung</option>
            <option value="off">Egal – Stammbereich nicht berücksichtigen</option>
          </select>
          <small class="muted" id="fHomeModeHint"></small></div>
        <div class="fg"><label>Kundenerfahrung</label>
          <div class="fg-check"><input type="checkbox" id="fUseExp" checked><label for="fUseExp">Frühere Einsätze beim Kunden mitgewichten</label></div>
          <small class="muted">Wer in den letzten 12 Monaten dort war, kennt die Anlage.</small></div>
      </div>
    </div>

    <!-- ---------------- Schritt 3 ---------------- -->
    <div class="wz-panel" data-panel="2" style="display:none">
      <div class="wz-sec-title">Kandidatenkreis</div>
      <p class="wz-hint">Ohne Rollenauswahl kommen alle planbaren Mitarbeiter infrage.</p>
      <div class="wd-checks" id="fRoles"></div>

      <div class="wz-sec-title" style="margin-top:1rem">Einzelne Personen</div>
      <p class="wz-hint">„Fest“ plant an jedem möglichen Tag ein, „Raus“ schließt komplett aus. Die Liste zeigt Stammbereich, Qualifikation und frühere Einsätze beim gewählten Kunden.</p>

      <div class="epa-filter">
        <input type="text" class="inp-sm" id="fPersonSearch" placeholder="Name, Kürzel, OS-Level oder Beruf …" style="flex:1;min-width:180px">
        <select class="sel sel-sm" id="fFltArea"><option value="">Alle Stammbereiche</option></select>
        <select class="sel sel-sm" id="fFltRole"><option value="">Alle Rollen</option></select>
        <select class="sel sel-sm" id="fFltSort">
          <option value="best">Sortierung: Beste zuerst</option>
          <option value="name">Sortierung: Name</option>
          <option value="exp">Sortierung: Kundenerfahrung</option>
          <option value="os">Sortierung: OS-Level</option>
        </select>
      </div>
      <div class="epa-filter">
        <label class="wd-check"><input type="checkbox" id="fFltHome"> Nur Stammbereich</label>
        <label class="wd-check"><input type="checkbox" id="fFltExp"> Nur mit Kundenerfahrung</label>
        <label class="wd-check"><input type="checkbox" id="fFltNoAz"> Ohne Azubis</label>
        <label class="wd-check"><input type="checkbox" id="fFltWe"> Nur mit Wochenend-Freigabe</label>
        <label class="wd-check"><input type="checkbox" id="fFltFlex"> Nur gut umplanbare</label>
        <span class="muted" id="fPeopleCnt" style="font-size:.75rem;margin-left:auto"></span>
      </div>
      <div class="epa-filter">
        <button class="btn btn-xs btn-outline" id="fBulkFix">Sichtbare fest einplanen</button>
        <button class="btn btn-xs btn-outline" id="fBulkOut">Sichtbare ausschließen</button>
        <button class="btn btn-xs btn-outline" id="fBulkClr">Auswahl zurücksetzen</button>
      </div>
      <div class="sup-pick" id="fPeople" style="max-height:320px"></div>

      <div class="wz-sec-title" style="margin-top:1rem">Auszubildende</div>
      <p class="wz-hint">Azubis zählen auf die Tagesbesetzung, brauchen aber Begleitung. Die Regeln werden bei jedem Vorschlag und jeder Änderung geprüft.</p>
      <div class="fg-check"><input type="checkbox" id="azOn" checked><label for="azOn">Auszubildende einbeziehen</label></div>
      <div id="azBox">
        <div class="fg"><label>Zugelassene Lehrjahre</label>
          <div class="wd-checks" id="azYears">
            <label class="wd-check"><input type="checkbox" data-year="1"> 1. LJ</label>
            <label class="wd-check"><input type="checkbox" data-year="2" checked> 2. LJ</label>
            <label class="wd-check"><input type="checkbox" data-year="3" checked> 3. LJ</label>
          </div>
        </div>
        <div class="epa-row">
          <div><div class="epa-row-t">Höchstens Azubis pro Tag</div><div class="epa-row-d">Absolute Obergrenze, unabhängig von der Teamgröße.</div></div>
          <div class="epa-row-r"><input type="number" class="epa-num" id="azMax" value="1" min="0" max="20"></div>
        </div>
        <div class="epa-row">
          <div><div class="epa-row-t">Höchstanteil an der Tagesbesetzung</div><div class="epa-row-d">50 % bei 4 Personen erlaubt höchstens 2 Azubis.</div></div>
          <div class="epa-row-r"><input type="number" class="epa-num" id="azShare" value="50" min="0" max="100"><span class="muted">%</span></div>
        </div>
        <div class="epa-row">
          <div><div class="epa-row-t">Betreuungsschlüssel</div><div class="epa-row-d">Eine Fachkraft betreut höchstens so viele Azubis.</div></div>
          <div class="epa-row-r"><span class="muted">1 :</span><input type="number" class="epa-num" id="azRatio" value="2" min="1" max="10"></div>
        </div>
        <div class="fg-check"><input type="checkbox" id="azAlone" checked><label for="azAlone">Nie allein beim Kunden (Tag nur mit Azubis wird gemeldet)</label></div>
        <div class="fg-check"><input type="checkbox" id="azSchool" checked><label for="azSchool">Berufsschultage aussparen</label></div>
        <div class="fg-check"><input type="checkbox" id="azWe" checked><label for="azWe">Kein Wochenenddienst für Azubis</label></div>
      </div>
    </div>

    <!-- ---------------- Schritt 4 ---------------- -->
    <div class="wz-panel" data-panel="3" style="display:none">
      <div class="wz-sec-title">Grenzen pro Person</div>
      <div class="epa-row">
        <div><div class="epa-row-t">Tage am Stück</div><div class="epa-row-d">Danach wird die Person zunächst übersprungen.</div></div>
        <div class="epa-row-r"><input type="number" class="epa-num" id="rMaxStreak" value="10" min="1" max="60"></div>
      </div>
      <div class="epa-row">
        <div><div class="epa-row-t">Tage im gesamten Zeitraum</div><div class="epa-row-d">Verhindert, dass wenige Köpfe das ganze Projekt tragen.</div></div>
        <div class="epa-row-r"><input type="number" class="epa-num" id="rMaxTotal" value="20" min="1" max="120"></div>
      </div>
      <div class="fg-check"><input type="checkbox" id="rWeekend" checked><label for="rWeekend">Wochenend-Freigabe beachten</label></div>

      <div class="wz-sec-title" style="margin-top:1rem">Bestehende Einträge</div>
      <p class="wz-hint">Sperrtage und Berufsschule werden grundsätzlich nie überschrieben.</p>
      <div class="fg">
        <label>Umgang mit belegten Tagen</label>
        <select id="rOverwrite">
          <option value="none">Nichts überschreiben – belegte Tage überspringen</option>
          <option value="assignments">Andere Einsätze überschreiben</option>
          <option value="all">Einsätze und Abwesenheiten überschreiben</option>
        </select>
        <small class="muted" id="rOverwriteHint"></small>
        <small class="muted" style="display:block;margin-top:.3rem">Abwesenheiten zu überschreiben heißt, in Urlaub oder
          Krankmeldung einzugreifen. Krankmeldungen sind Gesundheitsdaten – bitte nur nach Rücksprache und mit Anlass.
          Jede Übernahme wird protokolliert.</small>
      </div>

      <div class="fg" style="margin-top:.6rem">
        <label>Als verplanbar behandeln</label>
        <div class="wd-checks" id="fFreeAreas"></div>
        <small class="muted">Vorausgewählt sind alle Innendienst-Bereiche. Wer an einem solchen Tag steht, gilt als verfügbar und wird beim Übernehmen umgetragen. Berufsschule ist bewusst nicht wählbar.</small>
      </div>
      <div class="fg-check" id="rowCross"><input type="checkbox" id="fCrossTeam">
        <label for="fCrossTeam">Teamübergreifend planen – auch Mitarbeitende außerhalb des eigenen Teams einbeziehen</label></div>
      <small class="muted" id="crossHint" style="display:block;margin:-.2rem 0 .6rem">Mit Bedacht einsetzen: Sie greifen damit in die
        Planung anderer Teams ein. Sprechen Sie sich vorher ab. Abwesenheitsgründe außerhalb Ihres Teams bleiben verborgen,
        und jede Übernahme wird protokolliert.</small>

      <div class="fg-check"><input type="checkbox" id="fPreferFree" checked>
        <label for="fPreferFree">Innendienst-Tage aktiv auflösen – wer dort steht, wird bevorzugt zum Kunden geplant</label></div>
      <div class="fg-check"><input type="checkbox" id="fRespectBond" checked>
        <label for="fRespectBond">Kundenbindung beachten – wer fast immer beim selben Kunden ist, bleibt dort</label></div>
      <small class="muted">Bevorzugt Personen, die ohnehin zwischen Kunden wechseln. Grundlage sind die letzten 6 Monate.</small>

      <div class="wz-sec-title" style="margin-top:1rem">Gewichtung</div>
      <p class="wz-hint">Entscheidet nur bei gleichwertigen Kandidaten, wer den Zuschlag bekommt.</p>
      <div class="epa-w"><div><div class="epa-row-t">Kontinuität</div><div class="epa-row-d">Möglichst dieselben Köpfe über den ganzen Einsatz.</div></div>
        <input type="range" id="wCont" min="0" max="100" value="70"><div class="epa-w-v" id="wContV">70</div></div>
      <div class="epa-w"><div><div class="epa-row-t">Gleichverteilung</div><div class="epa-row-d">Last fair über das Team streuen.</div></div>
        <input type="range" id="wFair" min="0" max="100" value="50"><div class="epa-w-v" id="wFairV">50</div></div>
      <div class="epa-w"><div><div class="epa-row-t">Stammbereich</div><div class="epa-row-d">Wer den Kunden ohnehin betreut, kommt zuerst.</div></div>
        <input type="range" id="wHome" min="0" max="100" value="40"><div class="epa-w-v" id="wHomeV">40</div></div>
      <div class="epa-w"><div><div class="epa-row-t">Qualifikation</div><div class="epa-row-d">OS-Level und Ausbildungsberuf.</div></div>
        <input type="range" id="wOs" min="0" max="100" value="30"><div class="epa-w-v" id="wOsV">30</div></div>

    </div>

    <!-- ---------------- Schritt 5: Zusammenfassung ---------------- -->
    <div class="wz-panel" data-panel="4" style="display:none">
      <div class="wz-sec-title">Vorgaben prüfen</div>
      <p class="wz-hint">Alles auf einen Blick. Über „Ändern“ springen Sie direkt zum jeweiligen Feld – der Wert wird dort kurz hervorgehoben.</p>
      <div class="epa-recap" id="epaRecap"></div>
      <div class="epa-recap-note" id="epaRecapWarn"></div>
    </div>

    <!-- ---------------- Schritt 6: Entwurf ---------------- -->
    <div class="wz-panel" data-panel="5" style="display:none">
      <div id="resEmpty">
        <div class="wz-sec-title">Entwurf</div>
        <p class="wz-hint">Noch nichts berechnet. Starten Sie den Vorschlag – danach lässt sich jede Zelle von Hand ändern.</p>
        <button class="btn btn-primary" onclick="EPA.generate()">Vorschlag berechnen</button>
      </div>

      <div id="resWrap" style="display:none">
        <div class="epa-kpis" id="kpis"></div>
        <div class="epa-sum muted" id="epaSum"></div>
        <div class="epa-cols">
          <div class="card">
            <div class="ch">
              <span style="display:flex;gap:.25rem;align-items:center">
                <button class="btn btn-xs btn-primary" data-view="matrix">Matrix</button>
                <button class="btn btn-xs btn-outline" data-view="days">Tage</button>
                <button class="btn btn-xs btn-outline" data-view="load">Auslastung</button>
              </span>
              <span style="display:flex;gap:.35rem">
                <button class="btn btn-outline btn-xs" id="btnOnly" onclick="EPA.toggleOnlyPlanned()">Nur Verplante</button>
                <button class="btn btn-outline btn-xs" onclick="EPA.resort()" title="Zeilen nach aktueller Auslastung neu ordnen">Sortieren</button>
                <button class="btn btn-outline btn-xs" onclick="EPA.print()" title="Druckansicht öffnen – im Druckdialog lässt sich auch „Als PDF speichern“ wählen">Drucken</button>
                <button class="btn btn-outline btn-xs" onclick="EPA.generate()">Neu berechnen</button>
              </span>
            </div>
            <div class="epa-ps" id="matrix"></div>
            <div id="daysView" style="display:none"></div>
            <div id="loadView" style="display:none"></div>
            <div class="epa-legend" id="legend"></div>
          </div>
          <div class="card">
            <div class="ch"><span>Prüfung</span><span class="muted" id="cfCount" style="font-weight:400;font-size:.72rem"></span></div>
            <div style="padding:.5rem .6rem;border-bottom:1px solid var(--bd);display:flex;gap:.25rem;flex-wrap:wrap">
              <button class="btn btn-xs btn-primary" data-lvl="all">Alle</button>
              <button class="btn btn-xs btn-outline" data-lvl="block">Blocker</button>
              <button class="btn btn-xs btn-outline" data-lvl="warn">Warnungen</button>
              <button class="btn btn-xs btn-outline" data-lvl="info">Hinweise</button>
            </div>
            <div class="cb epa-cf-list" id="cfList"></div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /cb -->

  <div class="mf">
    <span class="muted" id="footHint" style="margin-right:auto;font-size:.78rem"></span>
    <button class="btn btn-outline" id="btnBack" onclick="EPA.prev()">Zurück</button>
    <button class="btn btn-primary" id="btnNext" onclick="EPA.next()">Weiter</button>
    <button class="btn btn-primary" id="btnApply" style="display:none" onclick="EPA.apply()">In Entwurf übernehmen</button>
  </div>
</div>

<!-- ---------------- Dialoge (Bauweise der App) ---------------- -->
<div class="modal-bg" id="ovAlt"><div class="modal modal-lg">
  <div class="mh"><h3 id="altTitle">Personen</h3><button class="mx" onclick="cMod('ovAlt')">&times;</button></div>
  <div class="mb" id="altBody"></div>
  <div class="mf"><button class="btn btn-outline" onclick="cMod('ovAlt')">Schließen</button></div>
</div></div>

<div class="modal-bg" id="ovProf"><div class="modal">
  <div class="mh"><h3>Vorlagen</h3><button class="mx" onclick="cMod('ovProf')">&times;</button></div>
  <div class="mb" id="profBody"></div>
  <div class="mf"><button class="btn btn-outline" onclick="cMod('ovProf')">Schließen</button></div>
</div></div>

<div class="modal-bg" id="ovSave"><div class="modal">
  <div class="mh"><h3 id="spTitle">Sichern</h3><button class="mx" onclick="cMod('ovSave')">&times;</button></div>
  <div class="mb">
    <div class="fg"><label>Name</label><input type="text" id="spName" placeholder="z. B. Rollout Kunde Nord – Phase 2"></div>
    <div class="fg" id="spDescRow"><label>Kurzbeschreibung (optional)</label><input type="text" id="spDesc"></div>
    <div class="fg-check"><input type="checkbox" id="spShared" checked><label for="spShared">Für andere Planende freigeben</label></div>
    <small class="muted" id="spHint"></small>
  </div>
  <div class="mf"><button class="btn btn-outline" onclick="cMod('ovSave')">Abbrechen</button><button class="btn btn-primary" id="spSaveBtn">Sichern</button></div>
</div></div>

<div class="modal-bg" id="ovApply"><div class="modal">
  <div class="mh"><h3 id="applyTitle">Übernahme</h3><button class="mx" onclick="cMod('ovApply')">&times;</button></div>
  <div class="mb" id="applyBody"></div>
  <div class="mf" id="applyFoot"></div>
</div></div>

</div><!-- /epaWizard -->

<div id="epaPrint"></div>

<div class="epa-busy" id="busy"><div class="epa-spin"></div><p class="muted" id="busyTxt">Vorschlag wird berechnet …</p></div>

<script>
"use strict";
var BOOT = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

var EPA = (function () {

  /* ===================================================== Hilfsmittel */
  var $ = function (id) { return document.getElementById(id); };
  var WD = ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
  var KEY = 'epa_cfg_v1';

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function dmy(d) { var p = String(d || '').split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : d; }
  function addDays(iso, n) { var d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); }
  function osRank(v) { var m = String(v || '').match(/(\d+)/); return m ? parseInt(m[1], 10) : 0; }
  /* „Mustermann, Maximilian“ -> „Mustermann, M.“ – spart Breite in der Matrix. */
  function shortName(n) {
    var i = String(n).indexOf(', ');
    if (i < 0) return n;
    var last = n.slice(0, i), first = n.slice(i + 2);
    return last + ', ' + (first ? first.charAt(0) + '.' : '');
  }
  function say(msg, type) { if (typeof toast === 'function') toast(msg, type || 'info'); else alert(msg); }
  function busy(on, txt) { if (txt) $('busyTxt').textContent = txt; $('busy').classList.toggle('on', !!on); }

  var AREA = {}, ROLE = {}, USER = {};
  BOOT.areas.forEach(function (a) { AREA[a.id] = a; });
  BOOT.roles.forEach(function (r) { ROLE[r.role_key] = r; });
  BOOT.users.forEach(function (u) { USER[u.id] = u; });

  function azYear(role) {
    var m = /^azubi_(\d)$/.exec(role || '');
    if (m) return +m[1];
    m = /^azubi_(\d)$/.exec((ROLE[role] || {}).base_role || '');
    return m ? +m[1] : 0;
  }
  function roleColor(r) { return (ROLE[r] || {}).color || '#6b7280'; }
  function initials(u) { return ((u.first_name || ' ')[0] + (u.last_name || ' ')[0]).toUpperCase(); }

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

  /* ===================================================== Zustand */
  /* Bereiche mit freier Kapazität. Erste Wahl sind die Einstellungen; ist dort
     nichts gepflegt, dient die Erkennung des Servers als Vorschlag. */
  function defaultFreeAreas() {
    var cfg = (BOOT.defaults && BOOT.defaults.free_areas) || [];
    if (cfg.length) return cfg.map(Number);
    return (BOOT.general || []).filter(function (a) { return !!a.default_free; })
                               .map(function (a) { return +a.id; });
  }

  function defaults() {
    var start = BOOT.today, D = BOOT.defaults || {};
    return {
      title: '', area_id: 0, start: start, end: addDays(start, 13),
      weekdays: [1, 2, 3, 4, 5], skip_holidays: true, remarks: '', internal: false,
      demand_default: D.demand_default || 2, demand_min: (D.demand_min === 0 ? 0 : (D.demand_min || 1)),
      demand_by_weekday: {}, special_days: [],
      req_os_level: '', req_os_count: 0, req_professions: [], req_home_count: 0,
      home_mode: D.home_mode || 'prefer', use_experience: true, force_cells: [],
      free_areas: defaultFreeAreas(),
      respect_bond: D.respect_bond !== false, prefer_free: D.prefer_free !== false, cross_team: false,
      roles: [], include_users: [], exclude_users: [],
      azubi: { enabled: true, years: (D.azubi_years && D.azubi_years.length ? D.azubi_years.map(Number) : [2, 3]),
               max_per_day: (D.azubi_max === 0 ? 0 : (D.azubi_max || 1)), max_share: 50,
               supervision_ratio: D.azubi_ratio || 2, never_alone: true, skip_school_days: true, no_weekend: true },
      rules: { max_consecutive: D.max_consecutive || 10, max_days_total: D.max_days_total || 20,
               weekend_flag: true, overwrite: 'none' },
      weights: { continuity: 70, fairness: 50, home_area: 40, os: 30 }
    };
  }
  var S = defaults(), R = null, step = 0, LAST = 5;
  var onlyPlanned = true, cfFilter = 'all', conflicts = [], lastRunId = 0, dirty = false;
  var CAND = null, candArea = 0, resView = 'matrix';
  var rowOrder = null;   // eingefrorene Zeilenreihenfolge der Matrix
  var planId = 0, planName = '', planShared = 0, planOwn = true;

  /* ===================================================== Aufbau */
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

  function buildWeekdays() {
    var h = '';
    for (var d = 1; d <= 7; d++) {
      h += '<label class="wd-check"><input type="checkbox" data-wd="' + d + '"> ' + WD[d] + '</label>';
    }
    $('fWeekdays').innerHTML = h;
    $('fWeekdays').addEventListener('change', function (e) {
      var el = e.target.closest('[data-wd]'); if (!el) return;
      var d = +el.dataset.wd, i = S.weekdays.indexOf(d);
      if (el.checked && i < 0) S.weekdays.push(d);
      if (!el.checked && i >= 0) S.weekdays.splice(i, 1);
      if (!S.weekdays.length) { S.weekdays = [d]; el.checked = true; say('Mindestens ein Wochentag muss aktiv bleiben.', 'warning'); }
      S.weekdays.sort();
      buildDemandGrid(); touched();
    });
  }

  function buildChecks(boxId, items, valueOf, labelOf, listName) {
    var box = $(boxId);
    if (!items.length) { box.innerHTML = '<span class="muted" style="font-size:.8rem">Keine Auswahl vorhanden.</span>'; return; }
    box.innerHTML = items.map(function (it) {
      return '<label class="wd-check"><input type="checkbox" data-v="' + esc(valueOf(it)) + '"> ' + esc(labelOf(it)) + '</label>';
    }).join('');
    box.addEventListener('change', function (e) {
      var el = e.target.closest('[data-v]'); if (!el) return;
      var v = el.dataset.v, arr = S[listName], i = arr.indexOf(v);
      if (el.checked && i < 0) arr.push(v);
      if (!el.checked && i >= 0) arr.splice(i, 1);
      touched();
    });
  }

  function buildOs() {
    var h = '<option value="">Keine Vorgabe</option>';
    BOOT.os.forEach(function (o) { h += '<option value="' + esc(o) + '">' + esc(o) + '</option>'; });
    $('fOsLevel').innerHTML = h;
  }

  function buildDemandGrid() {
    var h = '';
    for (var d = 1; d <= 7; d++) {
      var off = S.weekdays.indexOf(d) < 0, v = S.demand_by_weekday[d];
      h += '<div class="epa-dcell' + (off ? ' off' : '') + '"><b>' + WD[d] + '</b>' +
           '<input type="number" min="0" max="99" data-wd="' + d + '"' + (off ? ' disabled' : '') +
           ' value="' + (v === undefined || v === null ? '' : v) + '" placeholder="' + S.demand_default + '"></div>';
    }
    $('fDemandWd').innerHTML = h;
    $('fDemandWd').querySelectorAll('input').forEach(function (i) {
      i.addEventListener('input', function () {
        if (i.value === '') delete S.demand_by_weekday[i.dataset.wd];
        else S.demand_by_weekday[i.dataset.wd] = Math.max(0, Math.min(99, +i.value || 0));
        touched();
      });
    });
  }

  function buildSpecial() {
    var box = $('fSpecial');
    if (!S.special_days.length) {
      box.innerHTML = '<span class="muted" style="font-size:.8rem">Noch keine Sondertage – überall gilt der Regelbedarf.</span>';
      return;
    }
    box.innerHTML = S.special_days.map(function (sd, i) {
      return '<div class="epa-sd">' +
        '<input type="date" data-f="date" data-i="' + i + '" value="' + esc(sd.date) + '">' +
        '<input type="number" min="0" max="99" data-f="count" data-i="' + i + '" value="' + (+sd.count) + '" title="Personen an diesem Tag – 0 nimmt den Tag heraus">' +
        '<input type="text" data-f="note" data-i="' + i + '" value="' + esc(sd.note || '') + '" placeholder="Notiz, z. B. Go-Live">' +
        '<button class="btn btn-xs btn-outline" data-rm="' + i + '">Entfernen</button></div>';
    }).join('');
  }

  function buildPeople() {
    var rows = filteredPeople(), total = (CAND || BOOT.users).length;
    $('fPeopleCnt').textContent = rows.length === total
      ? rows.length + ' Personen'
      : rows.length + ' von ' + total + ' Personen';

    if (!rows.length) {
      $('fPeople').innerHTML = '<div class="muted" style="padding:.6rem;font-size:.8rem">Keine Person passt zu den Filtern.</div>';
      return;
    }
    $('fPeople').innerHTML = rows.map(function (p) {
      var fix = S.include_users.indexOf(+p.id) >= 0, out = S.exclude_users.indexOf(+p.id) >= 0;
      var area = AREA[p.area_id], tags = '';
      if (p.home) tags += '<span class="epa-home" title="Betreut diesen Kunden als Stammbereich">Stammbereich</span>';
      else if (area) tags += '<span class="epi-tag" style="background:' + esc(area.color) + ';color:' + esc(area.fg_color) + '">' + esc(area.short_code) + '</span>';
      if (p.exp > 0) tags += '<span class="epa-exp" title="Einsätze bei diesem Kunden in den letzten 12 Monaten' +
                             (p.exp_last ? ', zuletzt ' + dmy(p.exp_last) : '') + '">' + p.exp + '× dort</span>';
      if (p.bond >= 0.6 && p.bond_code && !p.home)
        tags += '<span class="epa-bond" title="Ist überwiegend bei diesem Kunden – wird nur ungern abgezogen">fest bei ' +
                esc(p.bond_code) + ' (' + Math.round(p.bond * 100) + '%)</span>';
      else if (p.variety >= 3)
        tags += '<span class="epa-flex" title="Wechselt häufig zwischen Kunden – gut umplanbar">wechselt oft</span>';
      if (p.os) tags += '<span class="epi-tag epi-os">' + esc(p.os) + '</span>';
      if (p.prof) tags += '<span class="epi-tag epi-tp">' + esc(p.prof) + '</span>';
      tags += '<span class="epi-tag epi-role">' + esc(p.azubi_year ? 'Azubi ' + p.azubi_year + '. LJ' : p.role_label) + '</span>';
      return '<div class="sup-pick-item" style="cursor:default">' +
        '<span class="epi-name">' + esc(p.name) + '</span>' +
        '<span class="epi-meta">' + tags +
          '<button class="btn btn-xs ' + (fix ? 'btn-primary' : 'btn-outline') + '" data-fix="' + p.id + '" title="An jedem möglichen Tag einplanen">Fest</button>' +
          '<button class="btn btn-xs ' + (out ? 'btn-danger' : 'btn-outline') + '" data-out="' + p.id + '" title="Von der Planung ausschließen">Raus</button>' +
        '</span></div>';
    }).join('');
  }

  /* ===================================================== Formular */
  var FIELDS = [
    ['fTitle', 'title', 's'], ['fArea', 'area_id', 'i'], ['fStart', 'start', 's'], ['fEnd', 'end', 's'],
    ['fSkipHol', 'skip_holidays', 'b'], ['fRemarks', 'remarks', 's'], ['fInternal', 'internal', 'b'],
    ['fDemand', 'demand_default', 'i'], ['fDemandMin', 'demand_min', 'i'],
    ['fOsLevel', 'req_os_level', 's'], ['fOsCount', 'req_os_count', 'i'], ['fHomeCount', 'req_home_count', 'i'],
    ['fHomeMode', 'home_mode', 's'], ['fUseExp', 'use_experience', 'b'],
    ['fRespectBond', 'respect_bond', 'b'], ['fPreferFree', 'prefer_free', 'b'],
    ['fCrossTeam', 'cross_team', 'b'],
    ['azOn', 'azubi.enabled', 'b'], ['azMax', 'azubi.max_per_day', 'i'], ['azShare', 'azubi.max_share', 'i'],
    ['azRatio', 'azubi.supervision_ratio', 'i'], ['azAlone', 'azubi.never_alone', 'b'],
    ['azSchool', 'azubi.skip_school_days', 'b'], ['azWe', 'azubi.no_weekend', 'b'],
    ['rMaxStreak', 'rules.max_consecutive', 'i'], ['rMaxTotal', 'rules.max_days_total', 'i'],
    ['rWeekend', 'rules.weekend_flag', 'b'], ['rOverwrite', 'rules.overwrite', 's'],
    ['wCont', 'weights.continuity', 'i'], ['wFair', 'weights.fairness', 'i'],
    ['wHome', 'weights.home_area', 'i'], ['wOs', 'weights.os', 'i']
  ];
  function getP(o, p) { return p.split('.').reduce(function (a, k) { return a ? a[k] : undefined; }, o); }
  function setP(o, p, v) { var k = p.split('.'), t = o; for (var i = 0; i < k.length - 1; i++) t = t[k[i]]; t[k[k.length - 1]] = v; }

  function bindFields() {
    FIELDS.forEach(function (f) {
      var el = $(f[0]); if (!el) return;
      var ev = (el.type === 'checkbox' || el.tagName === 'SELECT' || el.type === 'date') ? 'change' : 'input';
      el.addEventListener(ev, function () {
        setP(S, f[1], f[2] === 'b' ? el.checked : (f[2] === 'i' ? (+el.value || 0) : el.value));
        after(f[1]); touched();
      });
    });
    $('azYears').addEventListener('change', function (e) {
      var el = e.target.closest('[data-year]'); if (!el) return;
      var y = +el.dataset.year, i = S.azubi.years.indexOf(y);
      if (el.checked && i < 0) S.azubi.years.push(y);
      if (!el.checked && i >= 0) S.azubi.years.splice(i, 1);
      S.azubi.years.sort(); touched();
    });
    ['fPersonSearch', 'fFltArea', 'fFltRole', 'fFltSort', 'fFltHome', 'fFltExp', 'fFltNoAz', 'fFltWe', 'fFltFlex']
      .forEach(function (id) {
        var el = $(id); if (!el) return;
        el.addEventListener(el.tagName === 'INPUT' && el.type === 'text' ? 'input' : 'change', buildPeople);
      });
    $('fBulkFix').addEventListener('click', function () { bulk('fix'); });
    $('fBulkOut').addEventListener('click', function () { bulk('out'); });
    $('fBulkClr').addEventListener('click', function () {
      S.include_users = []; S.exclude_users = []; buildPeople(); touched();
      say('Auswahl zurückgesetzt.', 'info');
    });
    document.querySelectorAll('[data-view]').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('[data-view]').forEach(function (x) {
          x.classList.remove('btn-primary'); x.classList.add('btn-outline');
        });
        b.classList.add('btn-primary'); b.classList.remove('btn-outline');
        resView = b.dataset.view;
        $('matrix').style.display = resView === 'matrix' ? '' : 'none';
        $('daysView').style.display = resView === 'days' ? '' : 'none';
        $('loadView').style.display = resView === 'load' ? '' : 'none';
        if (R) render();
      });
    });
    $('fPeople').addEventListener('click', function (e) {
      var f = e.target.closest('[data-fix]'), o = e.target.closest('[data-out]');
      if (!f && !o) return;
      var id = +(f ? f.dataset.fix : o.dataset.out);
      var mine = f ? S.include_users : S.exclude_users, other = f ? S.exclude_users : S.include_users;
      var i = mine.indexOf(id);
      if (i >= 0) mine.splice(i, 1);
      else { mine.push(id); var j = other.indexOf(id); if (j >= 0) other.splice(j, 1); }
      buildPeople(); touched();
    });
    $('fSpecial').addEventListener('input', function (e) {
      var el = e.target.closest('[data-f]'); if (!el) return;
      var i = +el.dataset.i, f = el.dataset.f; if (!S.special_days[i]) return;
      S.special_days[i][f] = f === 'count' ? Math.max(0, Math.min(99, +el.value || 0)) : el.value;
      touched();
    });
    $('fSpecial').addEventListener('click', function (e) {
      var b = e.target.closest('[data-rm]'); if (!b) return;
      S.special_days.splice(+b.dataset.rm, 1); buildSpecial(); touched();
    });
    document.querySelectorAll('[data-lvl]').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('[data-lvl]').forEach(function (x) {
          x.classList.remove('btn-primary'); x.classList.add('btn-outline');
        });
        b.classList.add('btn-primary'); b.classList.remove('btn-outline');
        cfFilter = b.dataset.lvl; renderConflicts();
      });
    });
    $('matrix').addEventListener('click', function (e) {
      var head = e.target.closest('th[data-day]');
      if (head) { openAlt(head.dataset.day); return; }
      var c = e.target.closest('.epa-cell'); if (!c) return;
      toggleCell(+c.dataset.u, c.dataset.d);
    });
    /* Rechtsklick in der Matrix: Aktionen für die ganze Person. */
    $('matrix').addEventListener('contextmenu', function (e) {
      var c = e.target.closest('.epa-cell');
      var row = e.target.closest('tr');
      var uid = c ? +c.dataset.u : (row && row.dataset.uid ? +row.dataset.uid : 0);
      if (!uid) return;
      e.preventDefault(); e.stopPropagation();
      openCtx(e.clientX, e.clientY, uid, c ? c.dataset.d : null);
    });
    document.addEventListener('click', closeCtx);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeCtx(); });
  }

  function after(path) {
    if (path === 'title') $('epaSub').textContent = S.title || 'Neuer Einsatz';
    if (path === 'area_id' || path === 'start' || path === 'end') { paintRange(); loadCandidates(); }
    if (path === 'home_mode') paintHomeHint();
    if (path === 'cross_team') {
      CAND = null; candArea = 0; loadCandidates();
      say(S.cross_team ? 'Teamübergreifende Planung aktiv – bitte abstimmen.' : 'Wieder auf das eigene Team beschränkt.',
          S.cross_team ? 'warning' : 'info');
    }
    if (path === 'demand_default') buildDemandGrid();
    if (path === 'azubi.enabled') $('azBox').style.opacity = S.azubi.enabled ? '1' : '.5';
    if (path.indexOf('weights.') === 0) paintWeights();
    if (path === 'rules.overwrite') paintOwHint();
    if (path === 'start' && S.end < S.start) { S.end = S.start; $('fEnd').value = S.end; paintRange(); }
  }
  function paintWeights() {
    $('wContV').textContent = S.weights.continuity; $('wFairV').textContent = S.weights.fairness;
    $('wHomeV').textContent = S.weights.home_area;  $('wOsV').textContent = S.weights.os;
  }
  function paintOwHint() {
    var h = $('rOverwriteHint');
    if (S.rules.overwrite === 'none') h.textContent = 'Sicherste Variante: Tage mit vorhandenem Eintrag bleiben unverändert.';
    else if (S.rules.overwrite === 'assignments') h.textContent = 'Einsätze anderer Bereiche werden ersetzt. Urlaub, Krankheit und Sperrtage bleiben unangetastet.';
    else h.textContent = BOOT.perm.absence
      ? 'Auch Urlaub und Krankmeldungen werden überschrieben – bitte nur nach Rücksprache.'
      : 'Ihnen fehlt das Recht, Abwesenheiten zu überschreiben. Es wirkt wie „Andere Einsätze überschreiben“.';
  }
  function paintRange() {
    var c = $('epaRange');
    if (!S.start || !S.end) { c.textContent = 'Zeitraum offen'; return; }
    var n = Math.round((new Date(S.end + 'T12:00:00') - new Date(S.start + 'T12:00:00')) / 86400000) + 1;
    var a = AREA[S.area_id];
    c.textContent = (a ? a.short_code + ' · ' : '') + dmy(S.start) + ' – ' + dmy(S.end) + ' · ' + n + ' Tage';
  }

  function paintHomeHint() {
    var h = $('fHomeModeHint');
    if (!h) return;
    if (S.home_mode === 'only') h.textContent = 'Strikt: Nur wer den Kunden als Stammbereich hat oder dort schon im Einsatz war. Fest eingeplante Personen bleiben davon unberührt.';
    else if (S.home_mode === 'off') h.textContent = 'Der Stammbereich fließt nicht in die Auswahl ein.';
    else h.textContent = 'Empfohlen: Stammbereich und Kundenerfahrung zählen deutlich, andere füllen auf.';
  }

  /* Kandidaten inkl. Stammbereich und Kundenerfahrung für den gewählten Bereich. */
  function loadCandidates() {
    if (!S.area_id) { CAND = null; candArea = 0; buildPeople(); return; }
    if (candArea === +S.area_id && CAND) { buildPeople(); return; }
    post('candidates', { config: JSON.stringify(S) }).then(function (d) {
      if (!d.success) { CAND = null; buildPeople(); return; }
      CAND = d.items; candArea = +S.area_id; buildPeople();
    }).catch(function () { CAND = null; buildPeople(); });
  }

  /* Innendienst-Bereiche, die als freie Kapazität gelten dürfen. */
  function buildFreeAreas() {
    var box = $('fFreeAreas');
    var list = BOOT.general || [];
    if (!list.length) { box.innerHTML = '<span class="muted" style="font-size:.8rem">Keine Allgemein-Bereiche vorhanden.</span>'; return; }
    box.innerHTML = list.map(function (a) {
      return '<label class="wd-check"><input type="checkbox" data-free="' + a.id + '"> ' + esc(a.short_code) +
             ' <span class="muted" style="font-weight:400">' + esc(a.name) + '</span></label>';
    }).join('');
    box.addEventListener('change', function (e) {
      var el = e.target.closest('[data-free]'); if (!el) return;
      var id = +el.dataset.free, i = S.free_areas.indexOf(id);
      if (el.checked && i < 0) S.free_areas.push(id);
      if (!el.checked && i >= 0) S.free_areas.splice(i, 1);
      touched();
    });
  }

  function buildFilterSelects() {
    var a = '<option value="">Alle Stammbereiche</option>';
    BOOT.areas.forEach(function (x) { a += '<option value="' + x.id + '">' + esc(x.short_code) + '</option>'; });
    a += '<option value="0">Ohne Stammbereich</option>';
    $('fFltArea').innerHTML = a;
    var r = '<option value="">Alle Rollen</option>';
    BOOT.roles.forEach(function (x) { r += '<option value="' + esc(x.role_key) + '">' + esc(x.role_label) + '</option>'; });
    $('fFltRole').innerHTML = r;
  }

  /* Liste der Personen nach allen Filtern – Grundlage für Anzeige und Sammelaktionen. */
  function filteredPeople() {
    var src = CAND || BOOT.users.map(function (u) {
      return { id: +u.id, name: u.last_name + ', ' + u.first_name, role: u.role,
               role_label: (ROLE[u.role] || {}).role_label || u.role, role_color: roleColor(u.role),
               azubi_year: azYear(u.role), os: u.os_level || '', prof: u.training_profession || '',
               area_id: +(u.primary_area_id || 0), area_code: (AREA[u.primary_area_id] || {}).short_code || '',
               home: +(u.primary_area_id || 0) === +S.area_id, exp: 0, exp_last: null,
               bond: 0, bond_code: '', variety: 0, weekend: false };
    });
    var q = ($('fPersonSearch').value || '').toLowerCase().trim();
    var fa = $('fFltArea').value, fr = $('fFltRole').value;
    var onlyHome = $('fFltHome').checked, onlyExp = $('fFltExp').checked;
    var noAz = $('fFltNoAz').checked, onlyWe = $('fFltWe').checked;
    var onlyFlex = $('fFltFlex') && $('fFltFlex').checked;

    var out = src.filter(function (p) {
      if (q) {
        var hay = (p.name + ' ' + p.os + ' ' + p.prof + ' ' + p.area_code + ' ' + p.role_label).toLowerCase();
        if (hay.indexOf(q) < 0) return false;
      }
      if (fa !== '' && +p.area_id !== +fa) return false;
      if (fr !== '' && p.role !== fr) return false;
      if (onlyHome && !p.home) return false;
      if (onlyExp && !(p.exp > 0)) return false;
      if (noAz && p.azubi_year > 0) return false;
      if (onlyWe && !p.weekend) return false;
      if (onlyFlex && !(p.bond < 0.6 || p.home)) return false;
      return true;
    });

    var sort = $('fFltSort').value;
    out.sort(function (a, b) {
      if (sort === 'name') return a.name.localeCompare(b.name, 'de');
      if (sort === 'exp')  return (b.exp - a.exp) || a.name.localeCompare(b.name, 'de');
      if (sort === 'os')   return (osRank(b.os) - osRank(a.os)) || a.name.localeCompare(b.name, 'de');
      if (a.home !== b.home) return a.home ? -1 : 1;
      if (a.exp !== b.exp) return b.exp - a.exp;
      return a.name.localeCompare(b.name, 'de');
    });
    return out;
  }

  function writeForm() {
    FIELDS.forEach(function (f) {
      var el = $(f[0]); if (!el) return;
      var v = getP(S, f[1]);
      if (f[2] === 'b') el.checked = !!v; else el.value = v;
    });
    $('fArea').value = S.area_id ? String(S.area_id) : '';
    $('epaSub').textContent = S.title || 'Neuer Einsatz';
    $('fWeekdays').querySelectorAll('[data-wd]').forEach(function (i) { i.checked = S.weekdays.indexOf(+i.dataset.wd) >= 0; });
    $('azYears').querySelectorAll('[data-year]').forEach(function (i) { i.checked = S.azubi.years.indexOf(+i.dataset.year) >= 0; });
    $('fRoles').querySelectorAll('[data-v]').forEach(function (i) { i.checked = S.roles.indexOf(i.dataset.v) >= 0; });
    $('fProfs').querySelectorAll('[data-v]').forEach(function (i) { i.checked = S.req_professions.indexOf(i.dataset.v) >= 0; });
    $('azBox').style.opacity = S.azubi.enabled ? '1' : '.5';
    $('fFreeAreas').querySelectorAll('[data-free]').forEach(function (i) {
      i.checked = S.free_areas.indexOf(+i.dataset.free) >= 0;
    });
    buildDemandGrid(); buildSpecial(); buildPeople(); paintWeights(); paintOwHint(); paintHomeHint();
    paintRange(); paintRecap();
  }
  /* Kurzfassung aller Vorgaben. Jede Zeile führt per „Ändern“ zum Feld zurück. */
  function paintRecap() {
    var box = $('epaRecap'); if (!box) return;
    var a = AREA[S.area_id], wd = S.weekdays.map(function (d) { return WD[d]; }).join(', ');
    var free = S.free_areas.map(function (id) { return (AREA[id] || {}).short_code || id; }).join(', ');
    var days = 0;
    if (S.start && S.end) days = Math.round((new Date(S.end + 'T12:00:00') - new Date(S.start + 'T12:00:00')) / 86400000) + 1;

    var rows = [
      { l: 'Einsatz', v: '<b>' + esc(S.title || 'ohne Bezeichnung') + '</b>' +
           (a ? ' · ' + esc(a.short_code + ' – ' + a.name) : '<small>Kein Einsatzbereich gewählt</small>'),
        step: 0, field: 'fTitle', warn: !S.area_id || !S.title },
      { l: 'Zeitraum', v: '<b>' + dmy(S.start) + ' – ' + dmy(S.end) + '</b> · ' + days + ' Kalendertage' +
           '<small>Einsatztage: ' + esc(wd) + (S.skip_holidays ? ' · Feiertage werden übersprungen' : '') + '</small>',
        step: 0, field: 'fStart' },
      { l: 'Bedarf', v: '<b>' + S.demand_default + ' pro Tag</b>, mindestens ' + S.demand_min +
           (S.special_days.length ? '<small>' + S.special_days.length + ' Sondertag(e) abweichend</small>' : ''),
        step: 1, field: 'fDemand', warn: S.demand_min > S.demand_default },
      { l: 'Qualifikation', v: (S.req_os_level ? '<b>' + esc(S.req_os_level) + '</b>, davon ' + S.req_os_count + ' pro Tag' : 'keine Vorgabe') +
           (S.req_professions.length ? '<small>Beruf bevorzugt: ' + esc(S.req_professions.join(', ')) + '</small>' : ''),
        step: 1, field: 'fOsLevel' },
      { l: 'Stammbereich', v: '<b>' + (S.home_mode === 'only' ? 'ausschließlich' : (S.home_mode === 'off' ? 'wird ignoriert' : 'bevorzugt')) + '</b>' +
           (S.req_home_count ? ' · mindestens ' + S.req_home_count + ' pro Tag' : '') +
           '<small>Kundenerfahrung ' + (S.use_experience ? 'wird mitgewichtet' : 'bleibt unberücksichtigt') + '</small>',
        step: 1, field: 'fHomeMode' },
      { l: 'Umfang', v: '<b>' + (S.cross_team ? 'teamübergreifend' : 'eigenes Team') + '</b>' +
           (S.cross_team ? '<small>Bitte mit den betroffenen Teams abstimmen.</small>' : ''),
        step: 3, field: 'fCrossTeam', warn: S.cross_team },
      { l: 'Kandidatenkreis', v: (S.roles.length ? '<b>' + esc(S.roles.map(function (r) { return (ROLE[r] || {}).role_label || r; }).join(', ')) + '</b>' : '<b>alle Rollen</b>') +
           ((S.include_users.length || S.exclude_users.length)
             ? '<small>' + S.include_users.length + ' fest eingeplant · ' + S.exclude_users.length + ' ausgeschlossen</small>' : ''),
        step: 2, field: 'fRoles' },
      { l: 'Azubis', v: S.azubi.enabled
             ? '<b>' + S.azubi.years.map(function (y) { return y + '. LJ'; }).join(', ') + '</b> · höchstens ' +
               S.azubi.max_per_day + ' pro Tag' +
               '<small>Betreuungsschlüssel 1:' + S.azubi.supervision_ratio +
               (S.azubi.never_alone ? ' · nie allein' : '') + (S.azubi.skip_school_days ? ' · Berufsschule ausgespart' : '') + '</small>'
             : '<b>nicht einbezogen</b>',
        step: 2, field: 'azOn', warn: S.azubi.enabled && !S.azubi.years.length },
      { l: 'Grenzen', v: '<b>' + S.rules.max_consecutive + '</b> Tage am Stück · <b>' + S.rules.max_days_total + '</b> Tage gesamt' +
           '<small>Wochenend-Freigabe wird ' + (S.rules.weekend_flag ? 'beachtet' : 'ignoriert') + '</small>',
        step: 3, field: 'rMaxStreak' },
      { l: 'Belegte Tage', v: '<b>' + (S.rules.overwrite === 'none' ? 'nichts überschreiben'
             : (S.rules.overwrite === 'assignments' ? 'Einsätze überschreiben' : 'Einsätze und Abwesenheiten')) + '</b>' +
           '<small>' + (free ? 'Als frei behandelt: ' + esc(free) : 'Keine Innendienst-Bereiche freigegeben') + '</small>',
        step: 3, field: 'rOverwrite',
        warn: S.rules.overwrite === 'all' && !BOOT.perm.absence },
      { l: 'Umplanung', v: '<b>' + (S.prefer_free ? 'Innendienst wird aktiv aufgelöst' : 'Innendienst nur bei Bedarf') + '</b> · ' +
           (S.respect_bond ? 'Kundenbindung wird beachtet' : 'Kundenbindung wird ignoriert') +
           '<small>Gewichtung: Kontinuität ' + S.weights.continuity + ' · Gleichverteilung ' + S.weights.fairness +
           ' · Stammbereich ' + S.weights.home_area + ' · Qualifikation ' + S.weights.os + '</small>',
        step: 3, field: 'fRespectBond' }
    ];

    box.innerHTML = rows.map(function (r, i) {
      return '<div class="epa-rc' + (r.warn ? ' warn' : '') + '">' +
        '<div class="epa-rc-l">' + esc(r.l) + '</div>' +
        '<div class="epa-rc-v">' + r.v + '</div>' +
        '<button class="btn btn-xs btn-outline" data-jump="' + i + '">Ändern</button></div>';
    }).join('');
    box.querySelectorAll('[data-jump]').forEach(function (b) {
      var r = rows[+b.dataset.jump];
      b.addEventListener('click', function () { jumpField(r.step, r.field); });
    });

    var warn = [];
    if (!S.area_id) warn.push('Es ist noch kein Einsatzbereich gewählt.');
    if (S.demand_min > S.demand_default) warn.push('Die Mindestbesetzung liegt über dem Regelbedarf.');
    if (S.azubi.enabled && !S.azubi.years.length) warn.push('Azubis sind einbezogen, aber kein Lehrjahr ist ausgewählt.');
    if (S.rules.overwrite === 'all' && !BOOT.perm.absence) warn.push('Ihnen fehlt das Recht, Abwesenheiten zu überschreiben – die Einstellung bleibt wirkungslos.');
    $('epaRecapWarn').innerHTML = warn.length
      ? '<div class="alert alert-err" style="padding:.5rem .7rem;border-radius:var(--r)">' +
        warn.map(function (w) { return esc(w); }).join('<br>') + '</div>'
      : '<span class="muted">Alle Pflichtangaben sind vollständig.</span>';
  }

  /* Sprung zum betroffenen Feld – inklusive kurzer Hervorhebung. */
  function jumpField(target, fieldId) {
    go(target);
    setTimeout(function () {
      var el = $(fieldId); if (!el) return;
      var box = el.closest('.fg') || el.closest('.epa-row') || el.closest('.fg-check') || el;
      box.scrollIntoView({ block: 'center', behavior: 'smooth' });
      box.classList.remove('epa-flash');
      void box.offsetWidth;
      box.classList.add('epa-flash');
      setTimeout(function () { box.classList.remove('epa-flash'); }, 1600);
      if (el.focus) { try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); } }
    }, 120);
  }

  function touched() {
    if (R) dirty = true;
    paintRecap();
    paintFoot();
    try { sessionStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {}
  }

  /* ===================================================== Schritte */
  var HINTS = [
    'Bezeichnung und Einsatzbereich stehen später an jedem Planeintrag.',
    'Der Regelbedarf gilt für alle aktiven Tage – Ausnahmen darunter eintragen.',
    'Ohne Rollenauswahl kommen alle planbaren Mitarbeiter infrage.',
    'Die Gewichtung entscheidet nur bei gleichwertigen Kandidaten.',
    'Prüfen Sie die Vorgaben – „Ändern“ springt direkt zum betroffenen Feld.',
    'Der Vorschlag ist ein Entwurf – veröffentlicht wird weiterhin in der Monatsplanung.'
  ];
  function go(n) {
    if (n > step && !stepOk()) return;
    step = Math.max(0, Math.min(LAST, n));
    document.querySelectorAll('.wz-panel').forEach(function (p) {
      p.style.display = (+p.dataset.panel === step) ? '' : 'none';
    });
    document.querySelectorAll('.wz-step').forEach(function (b) {
      var i = +b.dataset.step;
      b.classList.toggle('active', i === step);
      b.classList.toggle('done', i < step);
    });
    $('btnBack').style.display = step === 0 ? 'none' : '';
    $('btnNext').style.display = step === LAST ? 'none' : '';
    paintFoot();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function paintFoot() {
    var h = HINTS[step];
    if (step === LAST && R && dirty) h = 'Die Eingaben haben sich geändert – bitte neu berechnen.';
    $('footHint').textContent = h;
    $('btnApply').style.display = (step === LAST && R) ? '' : 'none';
    var nb = $('btnNext');
    nb.textContent = (step === 4) ? (R ? 'Zum Entwurf' : 'Vorschlag berechnen') : 'Weiter';
  }
  function stepOk() {
    if (step === 0) {
      if (!S.area_id) { say('Bitte wählen Sie einen Einsatzbereich.', 'warning'); return false; }
      if (!S.start || !S.end) { say('Bitte Zeitraum angeben.', 'warning'); return false; }
      if (S.end < S.start) { say('Das Enddatum liegt vor dem Start.', 'warning'); return false; }
    }
    if (step === 1 && S.demand_min > S.demand_default) say('Die Mindestbesetzung ist höher als der Regelbedarf.', 'warning');
    return true;
  }
  function next() {
    if (!stepOk()) return;
    if (step === 4 && !R) { go(5); generate(); return; }   /* aus der Zusammenfassung direkt rechnen */
    go(step + 1);
  }

  /* ===================================================== Vorschlag */
  function generate() {
    if (!S.area_id) { say('Bitte zuerst einen Einsatzbereich wählen.', 'warning'); go(0); return; }
    busy(true, 'Vorschlag wird berechnet …');
    post('generate', { config: JSON.stringify(S) }).then(function (d) {
      busy(false);
      if (!d.success) { say(d.error || 'Berechnung fehlgeschlagen.', 'error'); return; }
      R = { days: d.days, people: d.people, plan: d.plan || {}, area: d.area };
      R.days.forEach(function (day) { if (!R.plan[day.date]) R.plan[day.date] = []; });
      dirty = false; rowOrder = null;
      $('resEmpty').style.display = 'none';
      $('resWrap').style.display = '';
      render();
      step = LAST; go(LAST);
      var n = conflicts.filter(function (c) { return c.level === 'block'; }).length;
      say(n ? 'Vorschlag steht – ' + n + ' Punkt(e) brauchen Ihre Entscheidung.' : 'Vorschlag steht.', n ? 'warning' : 'success');
    }).catch(function (e) { busy(false); say(e.message, 'error'); });
  }

  function stateOf(p, d) { return (p.days && p.days[d]) ? p.days[d] : null; }
  function cellKey(uid, date) { return uid + '|' + date; }
  function isForced(uid, date) { return S.force_cells.indexOf(cellKey(uid, date)) >= 0; }
  function setForced(uid, date, on) {
    var k = cellKey(uid, date), i = S.force_cells.indexOf(k);
    if (on && i < 0) S.force_cells.push(k);
    if (!on && i >= 0) S.force_cells.splice(i, 1);
  }

  function avail(p, day) {
    var d = day.date;
    if (p.entry && d < p.entry) return { ok: false, why: 'noch nicht im Unternehmen' };
    if (p.exit && d > p.exit) return { ok: false, why: 'bereits ausgetreten' };
    if (day.weekend && S.rules.weekend_flag && !p.weekend) return { ok: false, why: 'nicht für Wochenenddienst freigegeben' };
    if (p.azubi_year > 0) {
      if (!S.azubi.enabled) return { ok: false, why: 'Azubis sind nicht einbezogen' };
      if (S.azubi.years.indexOf(p.azubi_year) < 0) return { ok: false, why: p.azubi_year + '. Lehrjahr nicht ausgewählt' };
      if (S.azubi.no_weekend && day.weekend) return { ok: false, why: 'Azubis ohne Wochenenddienst' };
    }
    var st = stateOf(p, d);
    if (!st) return { ok: true, why: 'frei' };
    if (st.t === 'schule') return { ok: false, why: 'Berufsschule', hard: true };
    if (st.t === 'gesperrt') return { ok: false, why: 'Sperrtag', hard: true };
    var forced = isForced(p.id, d);
    if (st.t === 'abwesend') {
      if ((S.rules.overwrite === 'all' || forced) && BOOT.perm.absence)
        return { ok: true, ov: true, why: 'überschreibt: ' + st.l };
      return { ok: false, why: st.l, replaceable: BOOT.perm.absence, current: st.l, kind: 'abwesenheit' };
    }
    if (st.t === 'einsatz') {
      if (+st.a === +S.area_id) return { ok: true, why: 'bereits für diesen Bereich geplant' };
      if (S.free_areas.indexOf(+st.a) >= 0) return { ok: true, ov: true, why: 'verplanbar – ersetzt ' + st.l };
      if (forced || S.rules.overwrite === 'assignments' || S.rules.overwrite === 'all')
        return { ok: true, ov: true, why: 'überschreibt: ' + st.l };
      return { ok: false, why: 'bereits geplant: ' + st.l, replaceable: true, current: st.l, kind: 'einsatz' };
    }
    if (st.t === 'offen') return { ok: true, why: 'ersetzt offenen Tag' };
    return { ok: true, why: 'frei' };
  }

  /* ===================================================== Prüfung */
  function validate() {
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
          out.push({ level: 'warn', date: day.date, uid: p.id,
            msg: p.name + ': mehr als ' + S.rules.max_consecutive + ' Tage am Stück (ab ' + day.label + ').' });
        }
      });

      if (day.skip) {
        if (ids.length) out.push({ level: 'warn', date: day.date, uid: 0,
          msg: day.label + ' ist ausgenommen (' + (day.reason || 'kein Bedarf') + '), es sind aber ' + ids.length + ' Personen eingeplant.' });
        else if (day.holiday && S.skip_holidays && S.weekdays.indexOf(day.wd) >= 0)
          out.push({ level: 'info', date: day.date, uid: 0, msg: day.label + ' übersprungen – ' + (day.reason || day.holiday) });
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
        var a = avail(p, day);
        if (!a.ok) out.push({ level: 'block', date: day.date, uid: uid, msg: p.name + ' am ' + day.label + ': ' + a.why + '.' });
        else if (a.ov) out.push({ level: 'info', date: day.date, uid: uid, msg: p.name + ' am ' + day.label + ' – ' + a.why });
      });

      var gap = day.required - ids.length;
      if (gap > 0) {
        /* Nicht nur melden, dass etwas fehlt – auch warum niemand mehr kann. */
        var reasons = {}, freeLeft = 0;
        R.people.forEach(function (p) {
          if (ids.indexOf(p.id) >= 0) return;
          var a = avail(p, day);
          if (a.ok) { freeLeft++; return; }
          var key = a.why.replace(/:.*$/, '');
          reasons[key] = (reasons[key] || 0) + 1;
        });
        var top = Object.keys(reasons).sort(function (x, y) { return reasons[y] - reasons[x]; }).slice(0, 3)
                    .map(function (k) { return reasons[k] + '× ' + k; }).join(', ');
        out.push({ level: ids.length < S.demand_min ? 'block' : 'warn', date: day.date, uid: 0,
          msg: day.label + ': ' + ids.length + ' von ' + day.required + ' Plätzen besetzt (' + gap + ' offen). ' +
               (freeLeft > 0 ? freeLeft + ' Person(en) wären noch verfügbar.' : 'Niemand mehr verfügbar' + (top ? ' – ' + top + '.' : '.')) });
      }
      else if (gap < 0) out.push({ level: 'info', date: day.date, uid: 0,
        msg: day.label + ': ' + ids.length + ' Personen bei ' + day.required + ' Plätzen.' });

      if (S.azubi.enabled && nAz > 0) {
        if (S.azubi.never_alone && nTech === 0) out.push({ level: 'warn', date: day.date, uid: 0,
          msg: day.label + ': Azubis wären ohne Fachkraft vor Ort.' });
        if (nAz > nTech * S.azubi.supervision_ratio) out.push({ level: 'warn', date: day.date, uid: 0,
          msg: day.label + ': Betreuungsschlüssel 1:' + S.azubi.supervision_ratio + ' nicht eingehalten (' + nTech + ' Fachkräfte, ' + nAz + ' Azubis).' });
        if (nAz > S.azubi.max_per_day) out.push({ level: 'warn', date: day.date, uid: 0,
          msg: day.label + ': ' + nAz + ' Azubis, erlaubt sind ' + S.azubi.max_per_day + '.' });
      }
      if (S.req_os_count > 0 && S.req_os_level && nOs < S.req_os_count) out.push({ level: 'warn', date: day.date, uid: 0,
        msg: day.label + ': nur ' + nOs + ' von ' + S.req_os_count + ' Personen mit ' + S.req_os_level + '.' });
      if (S.req_home_count > 0 && S.area_id && nHome < S.req_home_count) out.push({ level: 'warn', date: day.date, uid: 0,
        msg: day.label + ': nur ' + nHome + ' von ' + S.req_home_count + ' Personen aus dem Stammbereich.' });
    });

    Object.keys(load).forEach(function (uid) {
      if (load[uid] > S.rules.max_days_total) out.push({ level: 'warn', date: '', uid: +uid,
        msg: (byId[uid] ? byId[uid].name : '#' + uid) + ': ' + load[uid] + ' Tage im Zeitraum (Grenze ' + S.rules.max_days_total + ').' });
    });
    S.include_users.forEach(function (uid) {
      var p = byId[uid]; if (!p) return;
      R.days.forEach(function (day) {
        if (day.skip || (R.plan[day.date] || []).indexOf(+uid) >= 0) return;
        var a = avail(p, day);
        out.push({ level: a.ok ? 'warn' : 'block', date: day.date, uid: +uid,
          msg: p.name + ' ist fest eingeplant, fehlt aber am ' + day.label + (a.ok ? '.' : ' (' + a.why + ').') });
      });
    });

    var ord = { block: 0, warn: 1, info: 2 };
    out.sort(function (a, b) { return (ord[a.level] - ord[b.level]) || String(a.date).localeCompare(String(b.date)); });
    conflicts = out;
    R.load = load;
    R.stats = {
      total: total, filled: filled, coverage: total > 0 ? Math.round(filled / total * 100) : 100,
      used: Object.keys(load).length,
      blocks: out.filter(function (c) { return c.level === 'block'; }).length,
      warns: out.filter(function (c) { return c.level === 'warn'; }).length,
      infos: out.filter(function (c) { return c.level === 'info'; }).length
    };
  }

  /* ===================================================== Darstellung */
  /* Sammelaktion auf alles, was der Filter gerade zeigt. */
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
    buildPeople(); touched();
    say(rows.length + (kind === 'fix' ? ' Personen fest eingeplant.' : ' Personen ausgeschlossen.'), 'info');
  }

  function isoWeek(iso) {
    var d = new Date(iso + 'T12:00:00');
    var t = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    t.setDate(t.getDate() + 3 - ((t.getDay() + 6) % 7));
    var w1 = new Date(t.getFullYear(), 0, 4);
    return 1 + Math.round(((t - w1) / 86400000 - 3 + ((w1.getDay() + 6) % 7)) / 7);
  }

  function renderSummary() {
    var s = R.stats, planDays = R.days.filter(function (d) { return !d.skip; }).length;
    var az = 0, hm = 0, byId = {};
    R.people.forEach(function (p) { byId[p.id] = p; });
    Object.keys(R.plan).forEach(function (dt) {
      (R.plan[dt] || []).forEach(function (u) {
        var p = byId[u]; if (!p) return;
        if (p.azubi_year > 0) az++;
        if (+p.area_id === +S.area_id) hm++;
      });
    });
    /* Woher kommt die Kapazität? */
    var fromFree = 0, fromPlan = 0;
    Object.keys(R.plan).forEach(function (dt) {
      (R.plan[dt] || []).forEach(function (u) {
        var p = byId[u]; if (!p) return;
        var st = stateOf(p, dt);
        if (!st || st.t !== 'einsatz' || +st.a === +S.area_id) return;
        if (S.free_areas.indexOf(+st.a) >= 0) fromFree++; else fromPlan++;
      });
    });
    var avg = planDays ? (s.filled / planDays) : 0;
    $('epaSum').innerHTML =
      '<b>' + planDays + '</b> Einsatztage · Ø <b>' + avg.toFixed(1).replace('.', ',') + '</b> Personen/Tag · ' +
      '<b>' + hm + '</b> aus dem Stammbereich · <b>' + az + '</b> durch Azubis' +
      (fromFree ? ' · <b>' + fromFree + '</b> aus dem Innendienst übernommen' : '') +
      (fromPlan ? ' · <b>' + fromPlan + '</b> ersetzen einen bestehenden Einsatz' : '');
  }

  function render() {
    validate(); renderKpis(); renderSummary(); renderConflicts(); renderLegend();
    if (resView === 'days') renderDays();
    else if (resView === 'load') renderLoad();
    else renderMatrix();
    paintFoot();
  }

  /* Legende zeigt ausschliesslich das, was in der Matrix auch wirklich vorkommt. */
  function renderLegend() {
    var a = R.area || {}, seen = {}, other = {}, free = {};
    var people = onlyPlanned ? R.people.filter(function (p) { return (R.load[p.id] || 0) > 0; }) : R.people;

    R.days.forEach(function (d) {
      if (d.skip) { seen.skip = true; return; }
      people.forEach(function (p) {
        var picked = (R.plan[d.date] || []).indexOf(p.id) >= 0;
        var st = stateOf(p, d.date);
        if (picked) {
          seen.pick = true;
          var av = avail(p, d), stp = stateOf(p, d.date);
          if (!av.ok) seen.bad = true;
          if (stp && stp.t === 'einsatz' && +stp.a !== +S.area_id) {
            if (S.free_areas.indexOf(+stp.a) >= 0) {
              seen.fromFree = true; seen.fromFreeC = stp.c; seen.fromFreeL = stp.l;
            } else seen.fromPlan = true;
          } else if (stp && stp.t === 'abwesend') seen.fromPlan = true;
          return;
        }
        if (!st) return;
        if (st.t === 'gesperrt') seen.lock = true;
        else if (st.t === 'abwesend') seen.abs = true;
        else if (st.t === 'schule') seen.school = true;
        else if (st.t === 'einsatz') {
          if (S.free_areas.indexOf(+st.a) >= 0) free[st.l] = st;
          else other[st.l] = st;
        }
      });
    });

    var parts = [];
    function sw(bg, fg, txt, label) {
      return '<span><i style="background:' + bg + ';color:' + (fg || '#fff') +
             ';font-size:.58rem;line-height:12px;text-align:center;font-weight:800">' + (txt || '') + '</i> ' + label + '</span>';
    }
    if (seen.pick) parts.push(sw(esc(a.color || '#e2001a'), esc(a.fg_color || '#fff'), '', 'geplant: ' + esc(a.short_code || 'Einsatz')));
    function corner(oldC, label) {
      return '<span><i style="background:' + esc(a.color || '#e2001a') +
             ';background-image:linear-gradient(135deg,' + esc(oldC) + ' 0 6px,transparent 6px)"></i> ' + label + '</span>';
    }
    if (seen.fromFree) parts.push(corner(seen.fromFreeC,
      'Innendienst aufgelöst – Zelle zeigt „' + esc(seen.fromFreeL) + '›' + esc(a.short_code || '') + '“'));
    if (seen.fromPlan) parts.push(corner('#64748b', 'ersetzt einen bestehenden Eintrag'));
    if (seen.bad) parts.push('<span><i style="background:#fff;box-shadow:inset 0 0 0 2px var(--err)"></i> Konflikt – bitte prüfen</span>');

    Object.keys(free).slice(0, 3).forEach(function (k) {
      parts.push(sw(esc(free[k].c), esc(free[k].f), esc(k), 'Innendienst – gilt als frei'));
    });
    Object.keys(other).slice(0, 4).forEach(function (k) {
      parts.push(sw(esc(other[k].c), esc(other[k].f), esc(k), 'anderer Einsatz' +
        (S.rules.overwrite === 'none' ? ' – per Klick ersetzbar' : '')));
    });
    if (Object.keys(other).length > 4) parts.push('<span>… und weitere Bereiche</span>');
    if (seen.school) parts.push('<span><i style="background:#6b7280;color:#fff;font-size:.58rem;line-height:12px;text-align:center;font-weight:800">BS</i> Berufsschule – nie überschreibbar</span>');
    if (seen.abs) parts.push('<span><i style="background:var(--errbg);border-color:#fecaca"></i> Abwesenheit</span>');
    if (seen.lock) parts.push('<span><i style="background:repeating-linear-gradient(135deg,#f1f5f9 0 4px,#e2e8f0 4px 8px)"></i> Sperrtag – nie überschreibbar</span>');
    if (seen.skip) parts.push('<span><i style="background:#fafbfc"></i> kein Bedarf</span>');

    parts.push('<span style="margin-left:auto">Linksklick setzt oder entfernt · Rechtsklick öffnet weitere Aktionen · Klick auf den Spaltenkopf zeigt passende Personen</span>');
    $('legend').innerHTML = parts.join('');
  }

  /* Tagesansicht: was ist wann offen, wer ist dabei. */
  var daysOnlyGaps = false;
  function renderDays() {
    var byId = {}; R.people.forEach(function (p) { byId[p.id] = p; });
    var gapCount = R.days.filter(function (d) { return !d.skip && (R.plan[d.date] || []).length < d.required; }).length;
    var h = '<div class="epa-filter" style="margin-bottom:.5rem">' +
            '<label class="wd-check"><input type="checkbox" id="dvGaps"' + (daysOnlyGaps ? ' checked' : '') +
            '> Nur Tage mit offenen Plätzen</label>' +
            '<span class="muted" style="font-size:.75rem;margin-left:auto">' + gapCount + ' von ' +
            R.days.filter(function (d) { return !d.skip; }).length + ' Tagen unvollständig</span></div>';
    var lastKw = null;
    R.days.forEach(function (d) {
      var idsNow = R.plan[d.date] || [];
      if (daysOnlyGaps && (d.skip || idsNow.length >= d.required)) return;
      var kw = isoWeek(d.date);
      if (kw !== lastKw) { lastKw = kw; h += '<div class="epa-kw">Kalenderwoche ' + kw + '</div>'; }
      var ids = R.plan[d.date] || [];
      if (d.skip) {
        h += '<div class="epa-day off"><div class="epa-day-h">' + esc(d.label) +
             '<span class="muted" style="font-weight:400">' + esc(d.reason || 'kein Bedarf') + '</span></div></div>';
        return;
      }
      var gap = d.required - ids.length;
      h += '<div class="epa-day ' + (gap > 0 ? 'gap' : 'full') + '">' +
             '<div class="epa-day-h">' + esc(d.label) +
             '<span class="badge ' + (gap > 0 ? (ids.length < S.demand_min ? 'bd' : 'bw') : 'bs') + '">' +
               ids.length + ' / ' + d.required + '</span>' +
             (d.holiday ? '<span class="muted" style="font-weight:400">' + esc(d.holiday) + '</span>' : '') +
             (d.note ? '<span class="muted" style="font-weight:400">' + esc(d.note) + '</span>' : '') +
             (gap > 0 ? '<button class="btn btn-xs btn-outline" data-fill="' + d.date + '" style="margin-left:auto">' +
                        gap + ' offen – Personen zeigen</button>' : '') +
           '</div><div class="epa-day-n">';
      if (!ids.length) h += '<span class="muted" style="font-size:.78rem">Niemand eingeplant.</span>';
      ids.forEach(function (uid) {
        var p = byId[uid]; if (!p) return;
        var cls = 'epa-chip' + (p.azubi_year > 0 ? ' az' : (+p.area_id === +S.area_id ? ' hm' : ''));
        h += '<span class="' + cls + '" title="' + esc(p.role_label + (p.os ? ' · ' + p.os : '')) + '">' + esc(p.name) +
             (p.azubi_year ? ' (' + p.azubi_year + '. LJ)' : '') + '</span>';
      });
      h += '</div></div>';
    });
    $('daysView').innerHTML = '<div style="padding:.6rem .8rem;max-height:calc(100vh - 330px);overflow:auto">' + h + '</div>';
    $('daysView').querySelectorAll('[data-fill]').forEach(function (b) {
      b.addEventListener('click', function () { openAlt(b.dataset.fill); });
    });
    var g = $('dvGaps');
    if (g) g.addEventListener('change', function () { daysOnlyGaps = g.checked; renderDays(); });
  }

  /* Auslastung: wer trägt wie viel, inklusive Stammbereich und Erfahrung. */
  function renderLoad() {
    var rows = R.people.slice().filter(function (p) { return (R.load[p.id] || 0) > 0; });
    var max = 1;
    rows.forEach(function (p) { max = Math.max(max, R.load[p.id] || 0); });
    rows.sort(function (a, b) { return (R.load[b.id] || 0) - (R.load[a.id] || 0) || a.name.localeCompare(b.name, 'de'); });
    var h = '';
    rows.forEach(function (p) {
      var n = R.load[p.id] || 0;
      h += '<div class="epa-lrow"><div style="display:flex;align-items:center;gap:.35rem;min-width:0">' +
             '<span class="epa-av" style="background:' + esc(p.role_color || '#6b7280') + '">' + esc(p.short) + '</span>' +
             '<span class="epa-nm-t" title="' + esc(p.role_label) + '">' + esc(p.name) + '</span>' +
             (+p.area_id === +S.area_id ? '<span class="epa-home">S</span>' : '') +
           '</div><div class="epa-lbar"><i style="width:' + Math.round(n / max * 100) + '%"></i></div>' +
           '<div style="text-align:right;font-weight:700">' + n + '</div></div>';
    });
    var unused = R.people.length - rows.length;
    $('loadView').innerHTML = '<div style="padding:.6rem .8rem;max-height:calc(100vh - 330px);overflow:auto">' +
      (h || '<p class="muted">Noch niemand eingeplant.</p>') +
      (unused > 0 ? '<p class="muted" style="font-size:.76rem;margin-top:.6rem">' + unused + ' weitere Person(en) sind nicht eingeplant.</p>' : '') +
      '</div>';
  }

  function renderKpis() {
    var s = R.stats;
    var col = s.coverage >= 100 ? 'var(--ok)' : (s.coverage >= 80 ? 'var(--wn)' : 'var(--err)');
    $('kpis').innerHTML =
      '<div class="epa-kpi"><div class="epa-kpi-n" style="color:' + col + '">' + s.coverage + '&thinsp;%</div>' +
        '<div class="epa-kpi-l">Abdeckung</div><div class="epa-bar"><i style="width:' + Math.min(100, s.coverage) + '%;background:' + col + '"></i></div></div>' +
      '<div class="epa-kpi"><div class="epa-kpi-n">' + s.filled + '<span class="muted" style="font-size:.8rem"> / ' + s.total + '</span></div><div class="epa-kpi-l">Besetzte Plätze</div></div>' +
      '<div class="epa-kpi"><div class="epa-kpi-n">' + s.used + '</div><div class="epa-kpi-l">Eingesetzte Personen</div></div>' +
      '<div class="epa-kpi"><div class="epa-kpi-n" style="color:' + (s.blocks ? 'var(--err)' : 'var(--ok)') + '">' + s.blocks + '</div><div class="epa-kpi-l">Blocker</div></div>' +
      '<div class="epa-kpi"><div class="epa-kpi-n" style="color:' + (s.warns ? 'var(--wn)' : 'var(--tx)') + '">' + s.warns + '</div><div class="epa-kpi-l">Warnungen</div></div>';
  }

  /* Reihenfolge einmal festlegen und danach halten. Beim Nachplanen von Hand
     sollen die Zeilen stehen bleiben – sonst springt einem die Person, die man
     gerade bearbeitet, unter dem Mauszeiger weg. */
  function buildRowOrder() {
    rowOrder = R.people.slice().sort(function (a, b) {
      var la = R.load[a.id] || 0, lb = R.load[b.id] || 0;
      if ((la > 0) !== (lb > 0)) return la > 0 ? -1 : 1;
      if (la !== lb) return lb - la;
      return a.name.localeCompare(b.name, 'de');
    }).map(function (p) { return p.id; });
  }

  function renderMatrix() {
    var days = R.days, people = R.people.slice();
    if (!rowOrder) buildRowOrder();
    var pos = {};
    rowOrder.forEach(function (id, i) { pos[id] = i; });
    people.sort(function (a, b) {
      var pa = pos[a.id], pb = pos[b.id];
      if (pa === undefined && pb === undefined) return a.name.localeCompare(b.name, 'de');
      if (pa === undefined) return 1;
      if (pb === undefined) return -1;
      return pa - pb;
    });
    if (onlyPlanned) people = people.filter(function (p) { return (R.load[p.id] || 0) > 0; });

    var today = BOOT.today, lastKw = null;
    var h = '<table class="pt"><thead><tr class="hd-row"><th class="sc cn">Mitarbeiter</th>';
    days.forEach(function (d) {
      var n = (R.plan[d.date] || []).length;
      var short = !d.skip && n < d.required;
      var kwNow = isoWeek(d.date), newKw = (kwNow !== lastKw); lastKw = kwNow;
      var cls = 'cd' + (d.weekend ? ' we' : '') + (d.htype === 'feiertag' ? ' hl-ft' : (d.htype ? ' hl-fe' : '')) +
                (short ? ' short' : '') + (d.date === today ? ' today' : '') + (newKw ? ' kw-start' : '');
      var tip = d.skip ? (d.reason || 'kein Bedarf')
              : ('Bedarf ' + d.required + ', besetzt ' + n + (d.note ? ' · ' + d.note : '') + (d.holiday ? ' · ' + d.holiday : '') +
                 ' – klicken für passende Personen');
      h += '<th class="' + cls + '" data-day="' + d.date + '" title="' + esc(tip) + '" style="cursor:pointer">' +
           '<div class="kwl">' + (newKw ? 'KW' + kwNow : '') + '</div>' +
           '<div class="dn">' + parseInt(d.date.slice(8), 10) + '</div><div class="wn">' + WD[d.wd] + '</div></th>';
    });
    h += '</tr></thead><tbody>';

    if (!people.length) h += '<tr><td class="cn" colspan="' + (days.length + 1) + '" style="padding:.8rem">Keine Personen im Vorschlag.</td></tr>';
    people.forEach(function (p) {
      h += '<tr data-uid="' + p.id + '"><td class="sc cn"><div class="epa-nm">' +
             '<span class="epa-av" style="background:' + esc(p.role_color || '#6b7280') + '">' + esc(p.short) + '</span>' +
             '<span class="epa-nm-t" title="' + esc(p.name + ' · ' + p.role_label + (p.os ? ' · ' + p.os : '')) + '">' + esc(shortName(p.name)) + '</span>' +
             (+p.area_id === +S.area_id ? '<span class="epa-home" title="Stammbereich">S</span>' : '') +
             (p.azubi_year ? '<span class="epa-chip az" style="padding:0 .2rem;font-size:.6rem">' + p.azubi_year + '. LJ</span>' : '') +
             '<span class="epa-load">' + (R.load[p.id] || 0) + '</span></div></td>';
      var lk = null;
      days.forEach(function (d) {
        var w = isoWeek(d.date), nk = (w !== lk); lk = w;
        h += '<td class="cd' + (d.weekend ? ' we' : '') + (nk ? ' kw-start' : '') + '">' + cellHtml(p, d) + '</td>';
      });
      h += '</tr>';
    });

    h += '</tbody><tfoot><tr><td class="sc cn" style="font-weight:700;font-size:.72rem">Besetzung / Bedarf</td>';
    var lk2 = null;
    days.forEach(function (d) {
      var n = (R.plan[d.date] || []).length, cls = 'epa-foot-cnt';
      var w = isoWeek(d.date), nk = (w !== lk2); lk2 = w;
      var pct = d.required ? Math.min(100, Math.round(n / d.required * 100)) : 0;
      var col = n >= d.required ? 'var(--ok)' : (n < S.demand_min ? 'var(--err)' : 'var(--wn)');
      if (!d.skip) cls += n >= d.required ? ' ok' : (n < S.demand_min ? ' short' : '');
      h += '<td class="cd' + (nk ? ' kw-start' : '') + '"><span class="' + cls + '">' +
           (d.skip ? '–' : n + '/' + d.required) + '</span>' +
           (d.skip ? '' : '<span class="epa-fbar"><i style="width:' + pct + '%;background:' + col + '"></i></span>') +
           '</td>';
    });
    h += '</tr></tfoot></table>';

    $('matrix').innerHTML = h;
    $('btnOnly').textContent = onlyPlanned ? 'Alle anzeigen' : 'Nur Verplante';
  }

  function cellHtml(p, d) {
    var picked = (R.plan[d.date] || []).indexOf(p.id) >= 0;
    var st = stateOf(p, d.date), cls = 'epa-cell', style = '', label = '', title;

    if (picked) {
      var a = avail(p, d);
      var bg = (R.area && R.area.color) || '#e2001a', fg = (R.area && R.area.fg_color) || '#ffffff';
      cls += ' pick' + (a.ok ? '' : ' bad');
      label = esc((R.area && R.area.short_code) || '✓');
      title = p.name + ' – ' + d.label + ' (' + (a.ok ? a.why : 'Konflikt: ' + a.why) + ')';

      /* Ersetzt der Eintrag etwas, zeigt die Zelle beide Bereiche: links schmal
         der bisherige, rechts der neue Einsatz. So ist auf einen Blick zu sehen,
         woher die Kapazität kommt. */
      if (st && st.t === 'einsatz' && +st.a !== +S.area_id) {
        var frei = S.free_areas.indexOf(+st.a) >= 0;
        cls += frei ? ' fromfree' : ' fromplan';
        style = 'background:' + esc(bg) + ';color:' + esc(fg) + ';--epa-old:' + esc(st.c);
        label = esc(st.l) + '›' + label;
        title = p.name + ' – ' + d.label + ': ' + st.l + ' → ' + ((R.area && R.area.short_code) || '') +
                (frei ? ' · Innendienst wird aufgelöst' : ' · bestehender Einsatz wird ersetzt');
      } else if (st && st.t === 'abwesend') {
        cls += ' fromplan';
        style = 'background:' + esc(bg) + ';color:' + esc(fg) + ';--epa-old:var(--err)';
        title = p.name + ' – ' + d.label + ': ' + st.l + ' wird überschrieben';
      } else {
        style = 'background:' + esc(bg) + ';color:' + esc(fg);
      }
    } else if (d.skip) {
      cls += ' skip'; title = d.label + ': ' + (d.reason || 'kein Bedarf');
    } else if (st && st.t === 'gesperrt') {
      cls += ' lock'; title = p.name + ' – ' + d.label + ': ' + st.l;
    } else if (st && st.t === 'abwesend') {
      cls += ' epa-abs'; label = esc(String(st.l).slice(0, 3)); title = p.name + ' – ' + d.label + ': ' + st.l;
    } else if (st && (st.t === 'einsatz' || st.t === 'schule')) {
      style = 'background:' + esc(st.c) + ';color:' + esc(st.f || '#ffffff'); label = esc(st.l);
      title = p.name + ' – ' + d.label + ': ' + st.l;
      if (st.t === 'einsatz' && !d.skip) { cls += ' repl'; title += ' · klicken, um zu ersetzen'; }
    } else if (st && st.t === 'abwesend' && BOOT.perm.absence && !d.skip) {
      cls += ' abs repl'; label = esc(String(st.l).slice(0, 3));
      title = p.name + ' – ' + d.label + ': ' + st.l + ' · klicken, um zu überschreiben';
    } else {
      title = p.name + ' – ' + d.label + ': ' + avail(p, d).why;
    }
    return '<button type="button" class="' + cls + '" id="c_' + p.id + '_' + d.date + '" style="' + style + '" ' +
           'data-u="' + p.id + '" data-d="' + d.date + '" title="' + esc(title) + '">' + label + '</button>';
  }

  function toggleCell(uid, date) {
    var day = null, p = null, i;
    for (i = 0; i < R.days.length; i++) if (R.days[i].date === date) { day = R.days[i]; break; }
    for (i = 0; i < R.people.length; i++) if (R.people[i].id === uid) { p = R.people[i]; break; }
    if (!day || !p) return;
    if (day.skip) { say(day.label + ' gehört nicht zur Planung (' + (day.reason || 'kein Bedarf') + ').', 'warning'); return; }
    var list = R.plan[date] || (R.plan[date] = []), k = list.indexOf(uid);
    if (k >= 0) {
      list.splice(k, 1);
      setForced(uid, date, false);   /* Freigabe zusammen mit der Zelle zurücknehmen */
      render();
      return;
    }
    var a = avail(p, day);
    if (a.ok) { list.push(uid); render(); return; }

    /* Bestehender Eintrag: Ersetzen anbieten statt nur abzulehnen. */
    if (a.replaceable) { askReplace(p, day, a); return; }
    say(p.name + ' am ' + day.label + ': ' + a.why + '.', 'warning');
  }

  /* Rückfrage vor dem Ersetzen – bewusst mit Klartext, was überschrieben wird. */
  function askReplace(p, day, a) {
    var abw = a.kind === 'abwesenheit';
    $('applyTitle').textContent = abw ? 'Abwesenheit überschreiben?' : 'Bestehenden Einsatz ersetzen?';
    $('applyBody').innerHTML =
      '<p><b>' + esc(p.name) + '</b> ist am <b>' + esc(day.label) + '</b> bereits eingetragen: ' +
      '<b>' + esc(a.current) + '</b>.</p>' +
      '<p style="margin-top:.5rem">Soll dieser Eintrag durch <b>' + esc((R.area && R.area.short_code) || 'diesen Einsatz') +
      '</b> ersetzt werden? Die Änderung wird als Entwurf gespeichert und lässt sich zurücknehmen.</p>' +
      (abw ? '<p class="alert alert-err" style="margin-top:.7rem">Es handelt sich um eine Abwesenheit (' + esc(a.current) +
             '). Bitte nur nach Rücksprache mit der Person überschreiben.</p>' : '');
    $('applyFoot').innerHTML =
      '<button class="btn btn-outline" onclick="cMod(\'ovApply\')">Abbrechen</button>' +
      '<button class="btn btn-primary" id="replBtn">' + (abw ? 'Trotzdem ersetzen' : 'Ersetzen') + '</button>';
    $('replBtn').onclick = function () {
      setForced(p.id, day.date, true);
      (R.plan[day.date] || (R.plan[day.date] = [])).push(p.id);
      cMod('ovApply'); render(); touched();
      say(esc(a.current) + ' ersetzt.', 'success');
    };
    oMod('ovApply');
  }

  /* ---------------- Kontextmenü ---------------- */
  function closeCtx() { var m = $('epaCtx'); if (m) m.classList.remove('open'); }

  function openCtx(x, y, uid, date) {
    var m = $('epaCtx');
    if (!m) { m = document.createElement('div'); m.id = 'epaCtx'; m.className = 'ctx-menu'; document.body.appendChild(m); }
    var p = null;
    for (var i = 0; i < R.people.length; i++) if (R.people[i].id === uid) { p = R.people[i]; break; }
    if (!p) return;
    var day = null;
    if (date) for (var j = 0; j < R.days.length; j++) if (R.days[j].date === date) { day = R.days[j]; break; }
    var onDay = date && (R.plan[date] || []).indexOf(uid) >= 0;
    var load = R.load[uid] || 0;
    var excluded = S.exclude_users.indexOf(uid) >= 0;
    var fixed = S.include_users.indexOf(uid) >= 0;

    var it = [];
    it.push('<div style="padding:.4rem .85rem;font-size:.75rem;font-weight:800;color:var(--mu);border-bottom:1px solid var(--bd)">' +
            esc(p.name) + ' · ' + load + ' Tag(e)</div>');
    if (day && !day.skip) {
      it.push(row('day', onDay ? 'Diesen Tag entfernen' : 'Für diesen Tag einplanen'));
      it.push(row('alt', 'Passende Personen für ' + esc(day.label)));
      it.push('<div class="ctx-sep"></div>');
    }
    it.push(row('drop', 'Aus dem Vorschlag nehmen' + (load ? ' (' + load + ' Tage)' : '')));
    it.push(row('out', excluded ? 'Ausschluss aufheben' : 'Ganz ausschließen (nicht mehr vorschlagen)'));
    it.push(row('fix', fixed ? 'Feste Einplanung aufheben' : 'An allen möglichen Tagen einplanen'));
    m.innerHTML = it.join('');

    m.querySelectorAll('[data-act]').forEach(function (b) {
      b.addEventListener('click', function (ev) {
        ev.stopPropagation(); closeCtx(); ctxAction(b.dataset.act, uid, date);
      });
    });
    m.classList.add('open');
    var w = m.offsetWidth || 240, h = m.offsetHeight || 200;
    m.style.left = Math.min(x, window.innerWidth - w - 8) + 'px';
    m.style.top  = Math.min(y, window.innerHeight - h - 8) + 'px';

    function row(act, label) {
      return '<button class="ctx-item" data-act="' + act + '"><span>' + label + '</span></button>';
    }
  }

  function ctxAction(act, uid, date) {
    var p = null, i;
    for (i = 0; i < R.people.length; i++) if (R.people[i].id === uid) { p = R.people[i]; break; }
    if (!p) return;

    if (act === 'day') { toggleCell(uid, date); return; }
    if (act === 'alt')  { openAlt(date); return; }

    if (act === 'drop' || act === 'out') {
      var n = 0;
      Object.keys(R.plan).forEach(function (d) {
        var k = R.plan[d].indexOf(uid);
        if (k >= 0) { R.plan[d].splice(k, 1); n++; setForced(uid, d, false); }
      });
      var fi = S.include_users.indexOf(uid);
      if (fi >= 0) S.include_users.splice(fi, 1);
      if (act === 'out') {
        var ei = S.exclude_users.indexOf(uid);
        if (ei >= 0) { S.exclude_users.splice(ei, 1); say(p.name + ' ist wieder wählbar.', 'info'); }
        else { S.exclude_users.push(uid); say(p.name + ' wird nicht mehr vorgeschlagen' + (n ? ' – ' + n + ' Tag(e) entfernt.' : '.'), 'info'); }
        buildPeople();
      } else {
        say(p.name + ': ' + n + ' Tag(e) aus dem Vorschlag entfernt.', 'info');
      }
      touched(); render();
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
          if (list.indexOf(uid) >= 0) return;
          if (!avail(p, d).ok) return;
          list.push(uid); added++;
        });
        say(p.name + ' an ' + added + ' Tag(en) eingeplant.', 'success');
      }
      buildPeople(); touched(); render();
    }
  }

  function renderConflicts() {
    var items = conflicts.filter(function (c) { return cfFilter === 'all' || c.level === cfFilter; });
    var s = R.stats;
    $('cfCount').textContent = s.blocks + ' Blocker · ' + s.warns + ' Warnungen · ' + s.infos + ' Hinweise';
    var counts = { all: conflicts.length, block: s.blocks, warn: s.warns, info: s.infos };
    document.querySelectorAll('[data-lvl]').forEach(function (b) {
      var base = { all: 'Alle', block: 'Blocker', warn: 'Warnungen', info: 'Hinweise' }[b.dataset.lvl];
      b.innerHTML = base + '<span class="epa-cnt">' + counts[b.dataset.lvl] + '</span>';
      b.disabled = counts[b.dataset.lvl] === 0 && b.dataset.lvl !== 'all';
    });
    if (!items.length) {
      $('cfList').innerHTML = '<p class="muted" style="font-size:.82rem;padding:.5rem 0">' +
        (cfFilter === 'all' ? 'Keine offenen Punkte – der Entwurf erfüllt alle Vorgaben.' : 'Nichts in dieser Kategorie.') + '</p>';
      return;
    }
    $('cfList').innerHTML = items.map(function (c, i) {
      return '<button type="button" class="epa-cf ' + c.level + '" data-cf="' + i + '">' +
        '<span class="badge ' + (c.level === 'block' ? 'bd' : (c.level === 'warn' ? 'bw' : 'bi')) + '">' +
          (c.level === 'block' ? 'Blocker' : (c.level === 'warn' ? 'Warnung' : 'Hinweis')) + '</span>' +
        '<span><span class="epa-cf-m">' + esc(c.msg) + '</span><br>' +
        '<span class="epa-cf-d">' + (c.date ? dmy(c.date) + ' · ' : '') +
          (c.uid ? 'zur Zelle springen' : 'passende Personen anzeigen') + '</span></span></button>';
    }).join('');
    $('cfList').querySelectorAll('[data-cf]').forEach(function (b) {
      b.addEventListener('click', function () { jumpTo(items[+b.dataset.cf]); });
    });
  }

  function jumpTo(c) {
    if (c.uid) {
      var el = $('c_' + c.uid + '_' + c.date);
      if (el) {
        el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
        el.style.outline = '3px solid var(--nv)';
        setTimeout(function () { el.style.outline = ''; }, 1400);
        return;
      }
      if (onlyPlanned) { onlyPlanned = false; renderMatrix(); setTimeout(function () { jumpTo(c); }, 60); return; }
    }
    if (c.date) openAlt(c.date);
  }

  function openAlt(date) {
    var day = null;
    for (var i = 0; i < R.days.length; i++) if (R.days[i].date === date) { day = R.days[i]; break; }
    if (!day) return;
    var taken = R.plan[date] || [];
    $('altTitle').textContent = 'Personen für ' + day.label + ' · ' + taken.length + ' von ' + day.required + ' besetzt';
    $('altBody').innerHTML = '<p class="muted">Wird geladen …</p>';
    oMod('ovAlt');
    post('suggest', { config: JSON.stringify(S), date: date, taken: JSON.stringify(taken) }).then(function (d) {
      if (!d.success) { $('altBody').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
      if (!d.items.length) { $('altBody').innerHTML = '<p class="muted">Keine weiteren Personen verfügbar.</p>'; return; }
      /* Verfügbare zuerst, darin die am leichtesten Umplanbaren. */
      var rank = function (it) {
        if (!it.ok) return 9;
        if (/Innendienst|verplanbar/.test(it.reason)) return 0;   // Technik & Co.
        if (/frei/.test(it.reason)) return 1;
        if (it.home) return 2;
        return 3;
      };
      var items = d.items.slice().sort(function (x, y) {
        return rank(x) - rank(y) || (y.exp || 0) - (x.exp || 0) || x.name.localeCompare(y.name, 'de');
      });
      var okN = items.filter(function (i) { return i.ok; }).length;
      var head = '<p class="muted" style="margin-bottom:.5rem">' + okN + ' verfügbar, ' +
                 (items.length - okN) + ' nicht einsetzbar. Oben stehen die, die sich am leichtesten umplanen lassen.</p>';

      $('altBody').innerHTML = head + items.map(function (it) {
        var u = USER[it.id] || {}, tags = '', hint = '';
        if (it.home) tags += '<span class="epa-home">Stammbereich</span>';
        else if (it.area_code) tags += '<span class="epi-tag epi-role">' + esc(it.area_code) + '</span>';
        if (it.exp > 0) tags += '<span class="epa-exp">' + it.exp + '× dort</span>';
        if (it.os) tags += '<span class="epi-tag epi-os">' + esc(it.os) + '</span>';
        if (it.azubi_year) tags += '<span class="epi-tag epi-role">Azubi ' + it.azubi_year + '. LJ</span>';
        if (it.bond >= 0.6 && it.bond_code && !it.home) {
          tags += '<span class="epa-bond">fest bei ' + esc(it.bond_code) + '</span>';
          hint = 'ist überwiegend dort – lieber jemand anderen';
        } else if (rank(it) === 0) hint = 'Innendienst – lässt sich leicht umplanen';
        return '<div class="epa-alt' + (it.ok ? '' : ' no') + '">' +
          '<span class="epa-av" style="background:' + esc(roleColor(u.role)) + '">' + esc(u.first_name ? initials(u) : '?') + '</span>' +
          '<span class="epi-name">' + esc(it.name) + (hint ? '<br><small class="muted">' + esc(hint) + '</small>' : '') + '</span>' +
          '<span class="epi-meta">' + tags + '</span>' +
          '<span class="epa-alt-r">' + esc(it.reason) + '</span>' +
          (it.ok ? '<button class="btn btn-xs btn-primary" data-add="' + it.id + '">Einplanen</button>' : '') +
        '</div>';
      }).join('');
      $('altBody').querySelectorAll('[data-add]').forEach(function (b) {
        b.addEventListener('click', function () {
          toggleCell(+b.dataset.add, date);
          b.closest('.epa-alt').remove();
          $('altTitle').textContent = 'Personen für ' + day.label + ' · ' + (R.plan[date] || []).length + ' von ' + day.required + ' besetzt';
        });
      });
    }).catch(function (e) { $('altBody').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }

  /* ===================================================== Übernahme */
  function apply() {
    if (!R) { say('Bitte zuerst einen Vorschlag berechnen.', 'warning'); return; }
    var total = Object.keys(R.plan).reduce(function (n, d) { return n + R.plan[d].length; }, 0);
    if (!total) { say('Der Entwurf enthält keine Einträge.', 'warning'); return; }
    var s = R.stats;
    $('applyTitle').textContent = 'In Entwurf übernehmen';
    $('applyBody').innerHTML =
      '<p><b>' + total + ' Einträge</b> für <b>' + esc((R.area && R.area.short_code) || '') + '</b> im Zeitraum ' +
      dmy(S.start) + ' – ' + dmy(S.end) + ' werden als <b>Entwurf</b> gespeichert. ' +
      'Veröffentlicht wird weiterhin in der Monatsplanung.</p>' +
      (s.blocks ? '<p class="alert alert-err" style="margin-top:.7rem">' + s.blocks + ' Blocker offen. Betroffene Tage überspringt der Server und listet sie anschließend auf.</p>' : '') +
      (s.warns ? '<p class="muted" style="margin-top:.5rem">' + s.warns + ' Warnung(en) bleiben bestehen – sie verhindern die Übernahme nicht.</p>' : '') +
      (S.internal && BOOT.perm.internal ? '<p class="muted" style="margin-top:.5rem">Die Einträge werden als intern markiert.</p>' : '');
    $('applyFoot').innerHTML =
      '<button class="btn btn-outline" onclick="cMod(\'ovApply\')">Abbrechen</button>' +
      '<button class="btn btn-primary" id="doApplyBtn">Jetzt übernehmen</button>';
    $('doApplyBtn').onclick = doApply;
    oMod('ovApply');
  }

  function doApply() {
    cMod('ovApply'); busy(true, 'Entwürfe werden gespeichert …');
    post('apply', { config: JSON.stringify(S), plan: JSON.stringify(R.plan) }).then(function (d) {
      busy(false);
      if (!d.success) { say(d.error || 'Übernahme fehlgeschlagen.', 'error'); return; }
      lastRunId = d.run_id;
      if (planId && R) {   /* gespeicherte Planung auf den neuesten Stand bringen */
        post('plan_save', { id: planId, name: planName || S.title, shared: planShared,
          config: JSON.stringify(S), plan: JSON.stringify(R.plan), stats: JSON.stringify(R.stats) });
      }
      $('applyTitle').textContent = 'Übernahme abgeschlossen';
      var h = '<p><b style="color:var(--ok)">' + d.written + ' Einträge</b> als Entwurf gespeichert.</p>';
      if (d.skipped_total) {
        h += '<p class="muted" style="margin-top:.6rem">' + d.skipped_total + ' Eintrag/Einträge übersprungen – dort steht ein Sperrtag, Berufsschule oder eine geschützte Abwesenheit:</p>' +
             '<ul style="margin:.4rem 0 0 1.1rem;font-size:.8rem;color:var(--mu)">' +
             d.skipped.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') +
             (d.skipped_total > d.skipped.length ? '<li>… und ' + (d.skipped_total - d.skipped.length) + ' weitere</li>' : '') + '</ul>';
      }
      h += '<p class="muted" style="margin-top:.8rem">Die Einträge stehen jetzt in der Monatsplanung als Entwurf. Dort prüfen und wie gewohnt veröffentlichen.</p>';
      $('applyBody').innerHTML = h;
      $('applyFoot').innerHTML =
        '<button class="btn btn-outline btn-danger-text" id="undoBtn">Übernahme zurücknehmen</button>' +
        '<span style="flex:1"></span>' +
        '<button class="btn btn-outline" onclick="cMod(\'ovApply\')">Hier bleiben</button>' +
        '<a class="btn btn-primary" href="' + BOOT.planning_url + '">Zur Monatsplanung</a>';
      $('undoBtn').onclick = undo;
      oMod('ovApply');
      say(d.msg || 'Übernommen.', 'success');
    }).catch(function (e) { busy(false); say(e.message, 'error'); });
  }

  function undo() {
    if (!lastRunId || !confirm('Alle Entwürfe dieser Übernahme wieder entfernen?')) return;
    busy(true, 'Übernahme wird zurückgenommen …');
    post('undo', { run_id: lastRunId }).then(function (d) {
      busy(false); cMod('ovApply');
      if (!d.success) { say(d.error || 'Zurücknehmen fehlgeschlagen.', 'error'); return; }
      lastRunId = 0; say(d.msg || 'Zurückgenommen.', 'success');
    }).catch(function (e) { busy(false); say(e.message, 'error'); });
  }

  /* ===================================================== Vorlagen */
  function openProfiles() {
    $('profBody').innerHTML = '<p class="muted">Wird geladen …</p>';
    oMod('ovProf');
    post('profile_list', {}).then(function (d) {
      if (!d.success) { $('profBody').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
      if (!d.items.length) {
        $('profBody').innerHTML = '<p class="muted">Noch keine Vorlagen. Sichern Sie eine fertige Konfiguration über „Als Vorlage sichern“ – beim nächsten Kundeneinsatz sind Sie damit sofort startklar.</p>';
        return;
      }
      $('profBody').innerHTML = d.items.map(function (p) {
        return '<div class="sup-pick-item" style="cursor:default">' +
          '<span class="epi-name">' + esc(p.name) +
            (p.description ? '<br><small class="muted">' + esc(p.description) + '</small>' : '') + '</span>' +
          '<span class="epi-meta">' +
            (p.short_code ? '<span class="epi-tag">' + esc(p.short_code) + '</span>' : '') +
            (p.use_count > 0 ? '<span class="epi-tag epi-role">' + p.use_count + '× genutzt</span>' : '') +
            '<button class="btn btn-xs btn-primary" data-load="' + p.id + '">Laden</button>' +
            (String(p.created_by) === String(d.me) ? '<button class="btn btn-xs btn-outline" data-del="' + p.id + '">Löschen</button>' : '') +
          '</span></div>';
      }).join('');
      $('profBody').querySelectorAll('[data-load]').forEach(function (b) {
        b.addEventListener('click', function () { loadProfile(+b.dataset.load); });
      });
      $('profBody').querySelectorAll('[data-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('Vorlage wirklich löschen?')) return;
          post('profile_delete', { id: +b.dataset.del }).then(function (r) {
            if (r.success) { say('Vorlage gelöscht.', 'success'); openProfiles(); }
            else say(r.error || 'Löschen nicht möglich.', 'error');
          });
        });
      });
    }).catch(function (e) { $('profBody').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }

  function loadProfile(id) {
    post('profile_load', { id: id }).then(function (d) {
      if (!d.success) { say(d.error || 'Vorlage nicht gefunden.', 'error'); return; }
      var def = defaults();
      S = Object.assign(def, d.config);
      S.azubi = Object.assign(defaults().azubi, d.config.azubi || {});
      S.rules = Object.assign(defaults().rules, d.config.rules || {});
      S.weights = Object.assign(defaults().weights, d.config.weights || {});
      S.demand_by_weekday = d.config.demand_by_weekday || {};
      S.special_days = d.config.special_days || [];
      if (S.end < BOOT.today) {
        var len = Math.round((new Date(S.end + 'T12:00:00') - new Date(S.start + 'T12:00:00')) / 86400000);
        S.start = BOOT.today; S.end = addDays(BOOT.today, len);
        say('Der Zeitraum der Vorlage lag in der Vergangenheit und wurde auf heute gelegt.', 'warning');
      }
      R = null; dirty = false;
      $('resWrap').style.display = 'none'; $('resEmpty').style.display = '';
      writeForm(); cMod('ovProf'); step = 0; go(0);
      say('Vorlage geladen.', 'success');
    }).catch(function (e) { say(e.message, 'error'); });
  }

  function saveProfile() {
    if (!S.area_id) { say('Bitte zuerst einen Einsatzbereich wählen.', 'warning'); go(0); return; }
    $('spName').value = S.title || ''; $('spDesc').value = '';
    $('spDescRow').style.display = '';
    $('spTitle').textContent = 'Als Vorlage sichern';
    $('spHint').textContent = 'Vorlagen sichern nur die Anforderungen, nicht die Besetzung.';
    $('spSaveBtn').onclick = doSaveProfile;
    oMod('ovSave');
  }
  function doSaveProfile() {
    var name = $('spName').value.trim();
    if (!name) { say('Bitte einen Namen angeben.', 'warning'); return; }
    post('profile_save', { name: name, description: $('spDesc').value.trim(),
      shared: $('spShared').checked ? 1 : 0, config: JSON.stringify(S) }).then(function (d) {
      if (!d.success) { say(d.error || 'Speichern fehlgeschlagen.', 'error'); return; }
      cMod('ovSave'); say('Vorlage gespeichert.', 'success');
    }).catch(function (e) { say(e.message, 'error'); });
  }

  /* ===================================================== Sonstiges */
  function reset() {
    if (!confirm('Alle Eingaben verwerfen und neu beginnen?')) return;
    S = defaults(); R = null; dirty = false; lastRunId = 0; onlyPlanned = false;
    try { sessionStorage.removeItem(KEY); } catch (e) {}
    $('resWrap').style.display = 'none'; $('resEmpty').style.display = '';
    writeForm(); step = 0; go(0); say('Zurückgesetzt.', 'info');
  }
  function addSpecial() {
    var last = S.special_days.length ? S.special_days[S.special_days.length - 1].date : S.start;
    S.special_days.push({ date: last || BOOT.today, count: S.demand_default, note: '' });
    buildSpecial(); touched();
  }
  function toggleOnlyPlanned() { onlyPlanned = !onlyPlanned; renderMatrix(); renderLegend(); }
  function resort() { if (!R) return; buildRowOrder(); renderMatrix(); say('Zeilen neu sortiert.', 'info'); }

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
      (S.cross_team ? ' Teamübergreifend geplant.' : '') +
      (S.remarks ? '<br>Bemerkung: ' + esc(S.remarks) : '') + '</div>';

    /* Matrix – bei sehr langen Zeiträumen bleibt sie lesbar, weil der Kopf
       auf jeder Seite wiederholt wird (siehe Druck-Stylesheet). */
    h += '<div class="epa-sec">Einsatzmatrix</div>';
    /* Bei langen Zeiträumen automatisch etwas kleiner setzen, damit die Matrix
       auf der Breite bleibt statt umzubrechen. */
    var scale = days.length > 45 ? ' xs' : (days.length > 30 ? ' sm' : '');
    h += '<table class="epa-mx' + scale + '"><thead>';

    /* Kalenderwochen als eigene Kopfzeile */
    h += '<tr class="epa-kwrow"><th class="epa-n"></th>';
    var kwRun = [], lastK = null;
    days.forEach(function (d) {
      var k = isoWeek(d.date);
      if (k !== lastK) { kwRun.push({ kw: k, n: 1 }); lastK = k; } else kwRun[kwRun.length - 1].n++;
    });
    kwRun.forEach(function (r) { h += '<th colspan="' + r.n + '" class="epa-kwh">KW ' + r.kw + '</th>'; });
    h += '<th></th></tr>';

    h += '<tr><th class="epa-n">Mitarbeiter</th>';
    lastK = null;
    days.forEach(function (d) {
      var k = isoWeek(d.date), nk = (k !== lastK); lastK = k;
      var cls = (d.skip ? 'epa-off' : (d.weekend ? 'epa-we' : '')) + (nk ? ' epa-wk' : '');
      h += '<th class="' + cls + '"><span class="epa-dd">' + parseInt(d.date.slice(8), 10) + '</span>' +
           '<span class="epa-wd">' + WD[d.wd] + '</span></th>';
    });
    h += '<th class="epa-sum">Σ</th></tr></thead><tbody>';

    used.forEach(function (p) {
      h += '<tr><td class="epa-n">' + esc(p.name) + (p.azubi_year ? ' <span class="epa-az">Azubi</span>' : '') + '</td>';
      lastK = null;
      days.forEach(function (d) {
        var k = isoWeek(d.date), nk = (k !== lastK); lastK = k;
        var on = (R.plan[d.date] || []).indexOf(p.id) >= 0;
        var st = stateOf(p, d.date);
        var cls = (nk ? 'epa-wk ' : '') + (d.skip ? 'epa-off' : (d.weekend ? 'epa-we' : ''));
        var txt = '', style = '';
        if (on) {
          cls += ' epa-pk';
          txt = esc(a.short_code || 'X');
          style = 'background:' + esc(a.color || '#333') + ';color:' + esc(a.fg_color || '#fff') + ';';
        } else if (st && st.t === 'einsatz') {
          txt = esc(st.l.replace(' •', ''));
          cls += ' epa-other';
        } else if (st && (st.t === 'abwesend' || st.t === 'gesperrt' || st.t === 'schule')) {
          txt = st.t === 'schule' ? 'BS' : (st.t === 'gesperrt' ? '—' : 'A');
          cls += ' epa-abs';
        }
        h += '<td class="' + cls + '" style="' + style + '">' + txt + '</td>';
      });
      h += '<td class="epa-sum">' + (R.load[p.id] || 0) + '</td></tr>';
    });
    h += '<tr class="epa-tot"><td class="epa-n">Besetzung / Bedarf</td>';
    lastK = null;
    days.forEach(function (d) {
      var k = isoWeek(d.date), nk = (k !== lastK); lastK = k;
      var n = (R.plan[d.date] || []).length;
      var cls = (nk ? 'epa-wk ' : '') + (!d.skip && n < d.required ? 'epa-gap' : '');
      h += '<td class="' + cls + '">' + (d.skip ? '–' : n + '/' + d.required) + '</td>';
    });
    h += '<td class="epa-sum">' + s.filled + '</td></tr></tbody></table>';
    h += '<div class="epa-mxleg"><span><i class="epa-lp" style="background:' + esc(a.color || '#333') + '"></i> geplant</span>' +
         '<span><i class="epa-lo"></i> anderer Einsatz</span>' +
         '<span><i class="epa-la"></i> abwesend / gesperrt</span>' +
         '<span><i class="epa-lw"></i> Wochenende, kein Bedarf</span></div>';

    /* Tagesliste – die eigentlich nützliche Fassung zum Weitergeben. */
    h += '<div class="epa-sec epa-pb">Einsatzliste</div><table class="epa-lst"><thead><tr>' +
         '<th style="width:22%">Tag</th><th style="width:8%">Soll</th><th>Besetzung</th></tr></thead><tbody>';
    var lastKw = null;
    days.forEach(function (d) {
      var kw = isoWeek(d.date);
      if (kw !== lastKw) { lastKw = kw; h += '<tr class="epa-kwsep"><td colspan="3">Kalenderwoche ' + kw + '</td></tr>'; }
      var ids = R.plan[d.date] || [];
      if (d.skip) {
        h += '<tr class="epa-off"><td class="epa-n">' + esc(d.label) + '</td><td>–</td><td class="epa-n">' +
             esc(d.reason || 'kein Bedarf') + '</td></tr>';
        return;
      }
      var names = ids.map(function (u) {
        var p = byId[u]; if (!p) return '';
        return esc(p.name) + (p.azubi_year ? ' (Azubi)' : '');
      }).filter(Boolean).join(', ');
      h += '<tr' + (ids.length < d.required ? ' class="epa-gap"' : '') + '>' +
           '<td class="epa-n">' + esc(d.label) + '</td>' +
           '<td>' + ids.length + '/' + d.required + '</td>' +
           '<td class="epa-n">' + (names || '<i>nicht besetzt</i>') + '</td></tr>';
    });
    h += '</tbody></table>';

    /* Einsatztage je Person – für Rückfragen und Abstimmung. */
    h += '<div class="epa-sec">Einsatztage je Person</div><table class="epa-lst"><thead><tr>' +
         '<th>Mitarbeiter</th><th style="width:12%">Tage</th><th style="width:34%">Zeitraum</th></tr></thead><tbody>';
    used.forEach(function (p) {
      var dts = days.filter(function (d) { return (R.plan[d.date] || []).indexOf(p.id) >= 0; });
      var span = dts.length ? dmy(dts[0].date) + ' – ' + dmy(dts[dts.length - 1].date) : '';
      h += '<tr><td class="epa-n">' + esc(p.name) + '</td><td>' + (R.load[p.id] || 0) + '</td>' +
           '<td class="epa-n">' + span + '</td></tr>';
    });
    h += '</tbody></table>';

    /* Offene Punkte */
    /* Datenschutz: Der Ausdruck verlässt das System. Er enthält deshalb nur
       tagesbezogene Befunde (Unterdeckung, Betreuung) – keine personenbezogenen
       Gründe wie Urlaub oder Krankmeldung. */
    var open = conflicts.filter(function (c) { return c.level !== 'info'; });
    var dayLevel = open.filter(function (c) { return !c.uid; });
    var personLevel = open.length - dayLevel.length;
    if (dayLevel.length) {
      h += '<div class="epa-sec">Offene Punkte (' + dayLevel.length + ')</div><ul>';
      dayLevel.slice(0, 40).forEach(function (c) {
        h += '<li>' + (c.level === 'block' ? 'Blocker: ' : 'Warnung: ') + esc(c.msg) + '</li>';
      });
      if (dayLevel.length > 40) h += '<li>… und ' + (dayLevel.length - 40) + ' weitere</li>';
      h += '</ul>';
    } else {
      h += '<div class="epa-sec">Keine offenen Punkte auf Tagesebene.</div>';
    }
    if (personLevel) {
      h += '<p style="font-size:8pt">Zusätzlich ' + personLevel +
           ' personenbezogene(r) Hinweis(e) – aus Datenschutzgründen nicht im Ausdruck enthalten, ' +
           'sie stehen in der Prüfliste im Assistenten.</p>';
    }

    h += '<div class="epa-pfoot">Erstellt mit dem Planungsassistenten der Einsatzplanung · ' +
         'Ausdruck vom ' + new Date().toLocaleDateString('de-DE') + ' · Entwurfsstand, Änderungen vorbehalten.<br>' +
         'Enthält bewusst keine Abwesenheitsgründe. Personenbezogene Daten – bitte vertraulich behandeln ' +
         'und nicht länger aufbewahren als nötig.</div>';

    var box = $('epaPrint');
    box.innerHTML = h;
    /* Der Druck-Stil blendet alle direkten Kinder von <body> aus. Der Behälter
       liegt im Inhaltsbereich, muss also vorher nach oben wandern – sonst
       verschwindet er mit seinem Elternelement und die Seite bleibt leer. */
    if (box.parentNode !== document.body) document.body.appendChild(box);
    say('Druckansicht wird geöffnet – im Dialog kann auch „Als PDF speichern“ gewählt werden.', 'info');
    setTimeout(function () { window.print(); }, 300);
  }

  /* ---------------- Startseite ---------------- */
  function home() {
    $('epaHome').style.display = '';
    $('epaWizard').style.display = 'none';
    window.scrollTo({ top: 0 });
    loadPlans();
  }
  function wizard() {
    $('epaHome').style.display = 'none';
    $('epaWizard').style.display = '';
    window.scrollTo({ top: 0 });
  }

  function loadPlans() {
    post('plan_list', {}).then(function (d) {
      if (!d.success) { $('homeList').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
      $('homeCount').textContent = d.items.length ? d.items.length + ' Planung(en)' : '';
      if (!d.items.length) {
        $('homeList').innerHTML =
          '<div style="text-align:center;padding:1.4rem .8rem">' +
          '<p style="font-size:.9rem;font-weight:600">Noch keine gespeicherte Planung.</p>' +
          '<p class="muted" style="font-size:.82rem;margin:.35rem 0 .9rem;max-width:52ch;margin-inline:auto">' +
          'Legen Sie eine neue Planung an. Sie können sie jederzeit speichern und später weiterbearbeiten – ' +
          'auch bevor Sie sie in die Monatsplanung übernehmen.</p>' +
          '<button class="btn btn-primary" onclick="EPA.newPlan()">Neue Planung anlegen</button></div>';
        return;
      }
      $('homeList').innerHTML = '<div class="epa-plans">' + d.items.map(function (p) {
        var cov = p.slots_total > 0 ? Math.round(p.slots_filled / p.slots_total * 100) : 0;
        var col = cov >= 100 ? 'var(--ok)' : (cov >= 80 ? 'var(--wn)' : 'var(--err)');
        var mine = String(p.created_by) === String(d.me);
        var when = p.updated_at || p.created_at || '';
        return '<div class="epa-plan">' +
          '<div class="epa-plan-h">' +
            (p.short_code ? '<span class="epa-plan-a" style="background:' + esc(p.color || '#666') +
                            ';color:' + esc(p.fg_color || '#fff') + '">' + esc(p.short_code) + '</span>' : '') +
            '<span class="epa-plan-t" title="' + esc(p.title) + '">' + esc(p.title) + '</span>' +
            (p.applied_at ? '<span class="badge bs" style="margin-left:auto">übernommen</span>' :
                            '<span class="badge bw" style="margin-left:auto">Entwurf</span>') +
          '</div>' +
          '<div class="epa-plan-m">' + dmy(p.start_date) + ' – ' + dmy(p.end_date) +
            (p.area_name ? ' · ' + esc(p.area_name) : '') + '<br>' +
            p.slots_filled + ' von ' + p.slots_total + ' Plätzen' +
            (p.conflicts > 0 ? ' · ' + p.conflicts + ' offene Punkte' : ' · keine offenen Punkte') +
            (mine ? '' : ' · von ' + esc(p.owner || 'Kollege')) + '</div>' +
          '<div class="epa-cov"><i style="width:' + Math.min(100, cov) + '%;background:' + col + '"></i></div>' +
          '<div class="epa-plan-m" style="font-size:.7rem">Zuletzt geändert: ' + dmyt(when) +
            (p.shared == 1 ? ' · freigegeben' : '') + '</div>' +
          '<div class="epa-plan-b">' +
            '<button class="btn btn-xs btn-primary" data-open="' + p.id + '">Öffnen</button>' +
            (mine ? '<button class="btn btn-xs btn-outline" data-del="' + p.id + '">Löschen</button>' : '') +
          '</div></div>';
      }).join('') + '</div>';

      $('homeList').querySelectorAll('[data-open]').forEach(function (b) {
        b.addEventListener('click', function () { openPlan(+b.dataset.open); });
      });
      $('homeList').querySelectorAll('[data-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('Diese gespeicherte Planung entfernen? Bereits übernommene Entwürfe bleiben bestehen.')) return;
          post('plan_delete', { id: +b.dataset.del }).then(function (r) {
            if (r.success) { say('Planung entfernt.', 'success'); loadPlans(); }
            else say(r.error || 'Löschen nicht möglich.', 'error');
          });
        });
      });
    }).catch(function (e) { $('homeList').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }

  function dmyt(v) {
    if (!v) return '–';
    var p = String(v).split(' ');
    return dmy(p[0]) + (p[1] ? ', ' + p[1].slice(0, 5) + ' Uhr' : '');
  }

  function newPlan() {
    S = defaults(); R = null; dirty = false; lastRunId = 0; rowOrder = null;
    planId = 0; planName = ''; planShared = 0; planOwn = true;
    try { sessionStorage.removeItem(KEY); } catch (e) {}
    $('resWrap').style.display = 'none'; $('resEmpty').style.display = '';
    writeForm(); step = 0; go(0); wizard();
  }

  function openPlan(id) {
    busy(true, 'Planung wird geladen …');
    post('plan_load', { id: id }).then(function (d) {
      busy(false);
      if (!d.success) { say(d.error || 'Planung nicht gefunden.', 'error'); return; }
      var def = defaults();
      S = Object.assign(def, d.config);
      S.azubi   = Object.assign(defaults().azubi, d.config.azubi || {});
      S.rules   = Object.assign(defaults().rules, d.config.rules || {});
      S.weights = Object.assign(defaults().weights, d.config.weights || {});
      planId = d.run_id; planName = d.name || ''; planShared = d.shared; planOwn = !!d.own;
      R = { days: d.days, people: d.people, plan: d.plan || {}, area: d.area };
      R.days.forEach(function (day) { if (!R.plan[day.date]) R.plan[day.date] = []; });
      rowOrder = null; dirty = false; lastRunId = 0;
      writeForm();
      $('resEmpty').style.display = 'none';
      $('resWrap').style.display = '';
      render();
      wizard(); step = LAST; go(LAST);
      var n = conflicts.filter(function (c) { return c.level === 'block'; }).length;
      say(n ? 'Planung geladen – ' + n + ' Punkt(e) haben sich seit dem Speichern ergeben.'
            : 'Planung geladen.', n ? 'warning' : 'success');
    }).catch(function (e) { busy(false); say(e.message, 'error'); });
  }

  function savePlan() {
    if (!R) { say('Bitte zuerst einen Vorschlag berechnen.', 'warning'); return; }
    $('spName').value = planName || S.title || '';
    $('spDesc').value = '';
    $('spDescRow').style.display = 'none';
    $('spShared').checked = !!planShared;
    $('spTitle').textContent = planId ? 'Planung speichern' : 'Planung sichern';
    $('spHint').textContent = (planId && planOwn)
      ? 'Die vorhandene Planung wird überschrieben.'
      : 'Die Planung wird neu angelegt und erscheint auf der Startseite.';
    $('spSaveBtn').onclick = doSavePlan;
    oMod('ovSave');
  }

  function doSavePlan() {
    var name = $('spName').value.trim();
    if (!name) { say('Bitte einen Namen angeben.', 'warning'); return; }
    post('plan_save', {
      id: (planOwn ? planId : 0), name: name,
      shared: $('spShared').checked ? 1 : 0,
      config: JSON.stringify(S), plan: JSON.stringify(R.plan),
      stats: JSON.stringify(R.stats)
    }).then(function (d) {
      if (!d.success) { say(d.error || 'Speichern fehlgeschlagen.', 'error'); return; }
      planId = d.id; planName = name; planShared = $('spShared').checked ? 1 : 0; planOwn = true;
      cMod('ovSave'); say('Planung gespeichert.', 'success');
    }).catch(function (e) { say(e.message, 'error'); });
  }

  function init() {
    buildAreas(); buildWeekdays(); buildOs();
    buildChecks('fRoles', BOOT.roles, function (r) { return r.role_key; }, function (r) { return r.role_label; }, 'roles');
    buildChecks('fProfs', BOOT.profs, function (p) { return p; }, function (p) { return p; }, 'req_professions');
    bindFields();

    try {
      var saved = sessionStorage.getItem(KEY);
      if (saved) {
        var o = JSON.parse(saved), d = defaults();
        /* Aeltere Staende kannten die Innendienst-Freigabe noch nicht –
           dann greift wieder die Vorauswahl statt einer leeren Liste. */
        if (!Array.isArray(o.free_areas) || !o.free_areas.length) delete o.free_areas;
        S = Object.assign(d, o);
        S.azubi = Object.assign(defaults().azubi, o.azubi || {});
        S.rules = Object.assign(defaults().rules, o.rules || {});
        S.weights = Object.assign(defaults().weights, o.weights || {});
      }
    } catch (e) { S = defaults(); }

    if (!BOOT.perm.internal) $('rowInternal').style.display = 'none';
    if (!BOOT.may_cross || !BOOT.own_team_only) {
      $('rowCross').style.display = 'none';
      $('crossHint').style.display = 'none';
    }
    buildFilterSelects(); buildFreeAreas();
    writeForm(); paintHomeHint(); loadCandidates(); go(0);
    home();

    window.addEventListener('beforeunload', function (e) {
      if (R && !lastRunId) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  document.addEventListener('DOMContentLoaded', init);

  return {
    go: go, next: next, prev: function () { go(step - 1); }, generate: generate, apply: apply,
    reset: reset, addSpecial: addSpecial, toggleOnlyPlanned: toggleOnlyPlanned, resort: resort,
    openProfiles: openProfiles, saveProfile: saveProfile, doSaveProfile: doSaveProfile,
    print: printPlan, home: home, newPlan: newPlan, savePlan: savePlan
  };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
