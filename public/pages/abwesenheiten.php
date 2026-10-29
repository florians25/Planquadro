<?php
/**
 * OpsPilot Einsatzplanung – Abwesenheiten   v6.9.0
 *
 * Copyright (c) 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten.
 *
 * Erfassung von Urlaub, Krankheit, Gleitzeit und Sperrtagen über einen Zeitraum,
 * wahlweise mit Vertretung. Ohne Angabe bleibt es ersatzlos – das ist der
 * Regelfall. Eine gewählte Vertretung übernimmt die Einsätze des Abwesenden.
 *
 * Voraussetzung: migrations/migrate_v6_9_0.sql
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../app/lib/PlanCells.php';
requireLogin();

$db = getDB();
$ME = (int)($_SESSION['user_id'] ?? 0);
$BP = bp();

/**
 * Vertretungen erweitern in dieser Anwendung den Sichtbereich, nicht die Rechte.
 * Wer jemanden mit Abwesenheitsverwaltung vertritt, soll das aber auch tun
 * dürfen – sonst läuft die Vertretung ins Leere.
 */
function abw_deputy_for(PDO $db, int $me): array
{
    $out = [];
    try {
        /* Bewusst ohne JOIN über die Rollenspalten: users.role und
           role_permissions.role haben unterschiedliche Kollationen. */
        $uq = $db->prepare('SELECT id, first_name, last_name, role FROM users WHERE id = ?');
        $pq = $db->prepare('SELECT granted FROM role_permissions WHERE role = ? AND permission = ?');
        $oq = $db->prepare('SELECT granted FROM permission_user_overrides WHERE user_id = ? AND permission = ?');

        foreach (activeDeputationDelegators($me) as $delId) {
            $uq->execute([(int)$delId]);
            $u = $uq->fetch();
            if (!$u) continue;

            $may = null;
            try {
                $oq->execute([(int)$delId, 'manage_absences']);
                $ov = $oq->fetchColumn();
                if ($ov !== false) $may = (int)$ov;
            } catch (Exception $e) { /* Tabelle optional */ }
            if ($may === null) {
                $pq->execute([$u['role'], 'manage_absences']);
                $may = (int)$pq->fetchColumn();
            }
            if ($may === 1) $out[] = $u;
        }
    } catch (Exception $e) { /* Vertretungen sind optional */ }
    return $out;
}

$DEPUTY_FOR = abw_deputy_for($db, $ME);

if (!hasPerm('manage_absences') && !hasPerm('plan_edit') && !$DEPUTY_FOR) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Keine Berechtigung für die Abwesenheitsverwaltung.']);
        exit;
    }
    showAccessDenied('Sie benötigen die Berechtigung "Abwesenheiten verwalten".');
}

$MAY_ALL      = hasPerm('view_all_employees');
$MAY_INTERNAL = hasPerm('plan_view_internal');

/** Planbare Mitarbeiter im eigenen Zuständigkeitsbereich. */
function abw_users(PDO $db): array
{
    $ids = visibleUserIds();
    $sql = "SELECT u.id, u.first_name, u.last_name, u.role, u.primary_area_id,
                   u.entry_date, u.exit_date,
                   a.short_code AS area_code, a.name AS area_name, a.color, a.fg_color
              FROM users u
         LEFT JOIN plan_areas a ON a.id = u.primary_area_id
             WHERE u.active = 1 AND u.role <> 'admin'
               AND (u.exit_date IS NULL OR u.exit_date >= CURDATE())";
    $par = [];
    if ($ids !== null) {
        if (!$ids) return [];
        $sql .= ' AND u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $par = array_map('intval', $ids);
    }
    $sql .= ' ORDER BY u.last_name, u.first_name';
    $q = $db->prepare($sql);
    $q->execute($par);
    return $q->fetchAll();
}

/**
 * Alle geplanten Einsätze einer Person an den angegebenen Tagen.
 *
 * Nutzt bewusst die App-eigene Funktion assignments($jahr, $monat, false) –
 * genau die Quelle, aus der die Monatsplanung ihre Zellen baut. Damit sieht
 * das Modul exakt dasselbe wie der Planer auf dem Bildschirm: veroeffentlichte
 * Einsaetze mit daruebergelegten Entwuerfen.
 *
 * @return array<int,array{plan_date:string,plan_area_id:int,short_code:string,
 *                         area_name:string,color:string,fg_color:string,src:string}>
 */
function abw_assignments(PDO $db, int $uid, array $dates): array
{
    if (!$dates) return [];

    /* Bereiche einmal laden – fuer Kuerzel und Farben. */
    static $areas = null;
    if ($areas === null) {
        $areas = [];
        foreach ($db->query('SELECT id, short_code, name, color, fg_color FROM plan_areas')->fetchAll() as $a) {
            $areas[(int)$a['id']] = $a;
        }
    }

    /* Je betroffenem Monat einmal die Sicht der Monatsplanung holen. */
    $months = [];
    foreach ($dates as $d) $months[substr($d, 0, 7)] = true;

    $map = [];
    foreach (array_keys($months) as $ym) {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $all = assignments($y, $m, false);      // veroeffentlicht + Entwuerfe
        if (isset($all[$uid])) {
            foreach ($all[$uid] as $date => $row) $map[$date] = $row;
        }
    }

    $out = [];
    foreach ($dates as $d) {
        if (!isset($map[$d])) continue;
        $row = $map[$d];
        $aid = (int)($row['plan_area_id'] ?? 0);
        if (!$aid || !isset($areas[$aid])) continue;
        $out[] = [
            'plan_date'    => $d,
            'plan_area_id' => $aid,
            'short_code'   => $areas[$aid]['short_code'],
            'area_name'    => $areas[$aid]['name'],
            'color'        => $areas[$aid]['color'],
            'fg_color'     => $areas[$aid]['fg_color'],
            'src'          => !empty($row['is_draft']) ? 'draft' : 'plan',
        ];
    }
    return $out;
}

const ABW_TYPES = [
    'urlaub_genehmigt' => 'Urlaub (genehmigt)',
    'urlaub_ganz'      => 'Urlaub (beantragt)',
    'urlaub_halb'      => 'Urlaub (halber Tag)',
    'krank_ganz'       => 'Krank',
    'krank_attest'     => 'Krank mit Attest',
    'krank_halb'       => 'Krank (halber Tag)',
    'gleitzeit'        => 'Gleitzeit',
    'gesperrt'         => 'Sperrtag',
];

/* =============================================================================
   AJAX
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'] ?? '';

    try {
        $allowed = [];
        foreach (abw_users($db) as $u) $allowed[(int)$u['id']] = $u;

        /* ---------------------------------------------------- Liste ---- */
        if ($act === 'list') {
            $from = $_POST['from'] ?? date('Y-m-01');
            $to   = $_POST['to']   ?? date('Y-m-t', strtotime('+2 month'));
            if (!$allowed) { echo json_encode(['success' => true, 'items' => []]); exit; }
            $in = implode(',', array_fill(0, count($allowed), '?'));

            /* Veroeffentlichte Abwesenheiten … */
            $q = $db->prepare(
                "SELECT user_id, absence_date, absence_type, remarks, substitute_user_id
                   FROM absences
                  WHERE absence_date BETWEEN ? AND ? AND user_id IN ($in)");
            $q->execute(array_merge([$from, $to], array_keys($allowed)));
            $rows = [];
            foreach ($q->fetchAll() as $r) { $r['is_published'] = 1; $rows[] = $r; }

            /* … und noch nicht veroeffentlichte Entwuerfe. Bewusst als zweite
               Abfrage: ein UNION scheitert an unterschiedlichen Kollationen. */
            try {
                $qd = $db->prepare(
                    "SELECT user_id, plan_date, change_value, change_type, remarks
                       FROM plan_drafts
                      WHERE plan_date BETWEEN ? AND ? AND user_id IN ($in)
                        AND action = 'set' AND change_type IN ('absence','locked')");
                $qd->execute(array_merge([$from, $to], array_keys($allowed)));
                foreach ($qd->fetchAll() as $r) {
                    $rows[] = [
                        'user_id' => $r['user_id'], 'absence_date' => $r['plan_date'],
                        'absence_type' => ($r['change_type'] === 'locked') ? 'gesperrt' : (string)$r['change_value'],
                        'remarks' => $r['remarks'], 'substitute_user_id' => null, 'is_published' => 0,
                    ];
                }
            } catch (Exception $e) { /* Entwuerfe sind optional */ }

            /* Einzeltage zu Zeitraeumen zusammenfassen – das ist die Sicht,
               in der Menschen ueber Abwesenheiten sprechen. */
            usort($rows, function ($a, $b) {
                return [$a['user_id'], $a['absence_date']] <=> [$b['user_id'], $b['absence_date']];
            });
            $out = [];
            $cur = null;
            foreach ($rows as $r) {
                $key = $r['user_id'] . '|' . $r['absence_type'] . '|' . (int)$r['substitute_user_id']
                     . '|' . (int)$r['is_published'];
                /* Aufeinanderfolgende Tage derselben Art zu einem Zeitraum bündeln.
                   Ein dazwischenliegendes Wochenende wird überbrückt – sonst
                   zerfiele jeder Zweiwochenurlaub in zwei Einträge. */
                if ($cur && $cur['key'] === $key) {
                    $gapOk = false;
                    for ($n = 1; $n <= 3; $n++) {
                        $next = date('Y-m-d', strtotime($cur['end'] . ' +' . $n . ' day'));
                        if ($next === $r['absence_date']) { $gapOk = true; break; }
                        if ((int)date('N', strtotime($next)) < 6) break;   // Werktag dazwischen
                    }
                    if ($gapOk) {
                        $cur['end'] = $r['absence_date'];
                        $cur['days']++;
                        continue;
                    }
                }
                if ($cur) $out[] = $cur;
                $cur = [
                    'key' => $key, 'user_id' => (int)$r['user_id'],
                    'name' => $allowed[$r['user_id']]['last_name'] . ', ' . $allowed[$r['user_id']]['first_name'],
                    'type' => $r['absence_type'], 'label' => ABW_TYPES[$r['absence_type']] ?? $r['absence_type'],
                    'start' => $r['absence_date'], 'end' => $r['absence_date'], 'days' => 1,
                    'remarks' => $r['remarks'],
                    'substitute_id' => (int)$r['substitute_user_id'],
                    'substitute' => $r['substitute_user_id'] && isset($allowed[(int)$r['substitute_user_id']])
                        ? $allowed[(int)$r['substitute_user_id']]['last_name'] . ', ' . $allowed[(int)$r['substitute_user_id']]['first_name']
                        : '',
                    'published' => (int)$r['is_published'],
                ];
            }
            if ($cur) $out[] = $cur;

            /* Vertraulichkeit wie in der Monatsplanung. Der interne
               Gruppierungsschlüssel enthält die Art und wird nie ausgeliefert
               (Befund S-11). */
            foreach ($out as &$o) {
                if (isConfidentialAbsence($o['type']) && !canSeeAbsenceDetails($o['user_id'])) {
                    $o['label'] = 'Abwesend';
                    $o['type']  = 'vertraulich';
                    $o['remarks'] = '';
                }
                unset($o['key']);
            }
            unset($o);
            usort($out, function ($a, $b) { return strcmp($b['start'], $a['start']); });
            echo json_encode(['success' => true, 'items' => $out]);
            exit;
        }

        /* ------------- Vorschau: was faellt weg, wer kann vertreten ---- */
        if ($act === 'impact') {
            $uid  = (int)($_POST['user_id'] ?? 0);
            $from = (string)($_POST['from'] ?? '');
            $to   = (string)($_POST['to'] ?? '');
            $skipWe  = !empty($_POST['skip_weekend']);
            $skipHol = !empty($_POST['skip_holidays']);
            if (!isset($allowed[$uid]) || !$from || !$to || $to < $from) {
                throw new Exception('Bitte Mitarbeiter und Zeitraum wählen.');
            }

            $hol = [];
            if ($skipHol) {
                try {
                    /* Nur gesetzliche Feiertage. Die Tabelle enthaelt auch
                       Schulferien – die sind normale Arbeitstage. */
                    $h = $db->prepare("SELECT holiday_date FROM holidays
                                        WHERE holiday_type = 'feiertag' AND holiday_date BETWEEN ? AND ?");
                    $h->execute([$from, $to]);
                    foreach ($h->fetchAll(PDO::FETCH_COLUMN) as $d) $hol[$d] = true;
                } catch (Exception $e) {}
            }

            /* Betroffene Tage bestimmen */
            $dates = [];
            for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
                $d = date('Y-m-d', $t);
                if ($skipWe && (int)date('N', $t) >= 6) continue;
                if (isset($hol[$d])) continue;
                $dates[] = $d;
            }
            if (!$dates) {
                echo json_encode(['success' => true, 'days' => 0, 'items' => [], 'subs' => []]);
                exit;
            }

            /* Einsätze, die wegfallen würden – veröffentlichte wie noch nicht
               veröffentlichte, dazu vorhandene Entwürfe. Alles drei ist Planung,
               die durch die Abwesenheit hinfällig wird. */
            $items = abw_assignments($db, $uid, $dates);

            /* Vertretungsvorschläge: wer ist frei und kennt den Bereich?
               Bewertet wird pro Kandidat über alle betroffenen Tage hinweg. */
            $subs = [];
            if ($items && $allowed) {
                $areaIds = array_values(array_unique(array_map(function ($r) { return (int)$r['plan_area_id']; }, $items)));
                $needDates = array_values(array_unique(array_map(function ($r) { return $r['plan_date']; }, $items)));
                $dIn = implode(',', array_fill(0, count($needDates), '?'));
                $cand = array_diff(array_keys($allowed), [$uid]);
                if ($cand) {
                    $cIn = implode(',', array_fill(0, count($cand), '?'));

                    /* Belegung der Kandidaten an den betroffenen Tagen */
                    $busy = [];
                    $qb = $db->prepare("SELECT user_id, absence_date FROM absences
                                         WHERE absence_date IN ($dIn) AND absence_type <> 'offen'
                                           AND user_id IN ($cIn)");
                    $qb->execute(array_merge($needDates, array_values($cand)));
                    foreach ($qb->fetchAll() as $r) $busy[(int)$r['user_id']][$r['absence_date']] = 'abwesend';

                    $qa = $db->prepare("SELECT user_id, plan_date, plan_area_id FROM assignments
                                         WHERE is_published = 1 AND plan_date IN ($dIn) AND user_id IN ($cIn)");
                    $qa->execute(array_merge($needDates, array_values($cand)));
                    foreach ($qa->fetchAll() as $r) {
                        if (!isset($busy[(int)$r['user_id']][$r['plan_date']])) {
                            $busy[(int)$r['user_id']][$r['plan_date']] = 'einsatz';
                        }
                    }

                    /* Erfahrung in den betroffenen Bereichen, letzte 12 Monate */
                    $exp = [];
                    $aIn = implode(',', array_fill(0, count($areaIds), '?'));
                    $qe = $db->prepare("SELECT user_id, COUNT(*) c FROM assignments
                                         WHERE is_published = 1 AND plan_area_id IN ($aIn)
                                           AND plan_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                           AND user_id IN ($cIn) GROUP BY user_id");
                    $qe->execute(array_merge($areaIds, array_values($cand)));
                    foreach ($qe->fetchAll() as $r) $exp[(int)$r['user_id']] = (int)$r['c'];

                    foreach ($cand as $cid) {
                        $u = $allowed[$cid];
                        $freeDays = 0; $reasons = [];
                        foreach ($needDates as $d) {
                            if (!isset($busy[$cid][$d])) $freeDays++;
                            else $reasons[$busy[$cid][$d]] = ($reasons[$busy[$cid][$d]] ?? 0) + 1;
                        }
                        $home = ((int)$u['primary_area_id'] && in_array((int)$u['primary_area_id'], $areaIds, true));
                        $score = $freeDays * 10 + ($home ? 25 : 0) + min(20, ($exp[$cid] ?? 0));
                        $why = [];
                        if ($home) $why[] = 'betreut den Bereich';
                        if (!empty($exp[$cid])) $why[] = $exp[$cid] . '× dort im Einsatz';
                        if ($freeDays === count($needDates)) $why[] = 'an allen Tagen frei';
                        elseif ($freeDays > 0) $why[] = $freeDays . ' von ' . count($needDates) . ' Tagen frei';
                        if (!empty($reasons['abwesend'])) $why[] = $reasons['abwesend'] . '× selbst abwesend';
                        if (!empty($reasons['einsatz']))  $why[] = $reasons['einsatz'] . '× anderweitig verplant';

                        $freeList = [];
                        foreach ($needDates as $d) if (!isset($busy[$cid][$d])) $freeList[] = $d;

                        $subs[] = [
                            'id' => $cid,
                            'name' => $u['last_name'] . ', ' . $u['first_name'],
                            'short' => mb_strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1)),
                            'free' => $freeDays, 'of' => count($needDates),
                            'free_dates' => $freeList,
                            'home' => $home, 'exp' => $exp[$cid] ?? 0,
                            'score' => $score, 'why' => implode(' · ', $why) ?: 'keine Einschränkung bekannt',
                            'blocked' => $reasons,
                        ];
                    }
                    usort($subs, function ($a, $b) { return $b['score'] <=> $a['score']; });
                    $subs = array_slice($subs, 0, 8);
                }
            }

            echo json_encode(['success' => true, 'days' => count($dates), 'items' => $items, 'subs' => $subs]);
            exit;
        }

        /* -------------------------------------------------- Speichern -- */
        if ($act === 'save') {
            $uid  = (int)($_POST['user_id'] ?? 0);
            $type = (string)($_POST['type'] ?? '');
            $from = (string)($_POST['from'] ?? '');
            $to   = (string)($_POST['to'] ?? '');
            $sub  = (int)($_POST['substitute'] ?? 0);
            /* Vertretung je Tag: {"YYYY-MM-DD": userId, …}. Fehlt ein Tag,
               gilt die pauschale Angabe – so bleiben alte Aufrufe gültig. */
            $subMap = json_decode((string)($_POST['substitutes'] ?? '[]'), true);
            if (!is_array($subMap)) $subMap = [];
            $rem  = mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 500);
            $skipWe  = !empty($_POST['skip_weekend']);
            $skipHol = !empty($_POST['skip_holidays']);

            if (!isset($allowed[$uid]))        throw new Exception('Diese Person können Sie nicht verwalten.');
            if (!isset(ABW_TYPES[$type]))      throw new Exception('Unbekannte Art der Abwesenheit.');
            if (!opsValidDate($from) || !opsValidDate($to) || $to < $from) throw new Exception('Bitte einen gültigen Zeitraum angeben.');
            if ($sub && !isset($allowed[$sub])) throw new Exception('Diese Vertretung können Sie nicht einsetzen.');
            if ($sub === $uid)                  throw new Exception('Eine Person kann sich nicht selbst vertreten.');
            foreach ($subMap as $d => $sid) {
                $sid = (int)$sid;
                if ($sid && !isset($allowed[$sid])) throw new Exception('Eine gewählte Vertretung dürfen Sie nicht einsetzen.');
                if ($sid === $uid)                  throw new Exception('Eine Person kann sich nicht selbst vertreten.');
            }

            $days = (strtotime($to) - strtotime($from)) / 86400 + 1;
            if ($days > 200) throw new Exception('Der Zeitraum ist zu lang (höchstens 200 Tage).');

            /* Feiertage */
            $hol = [];
            if ($skipHol) {
                try {
                    /* Nur gesetzliche Feiertage. Die Tabelle enthaelt auch
                       Schulferien – die sind normale Arbeitstage. */
                    $h = $db->prepare("SELECT holiday_date FROM holidays
                                        WHERE holiday_type = 'feiertag' AND holiday_date BETWEEN ? AND ?");
                    $h->execute([$from, $to]);
                    foreach ($h->fetchAll(PDO::FETCH_COLUMN) as $d) $hol[$d] = true;
                } catch (Exception $e) {}
            }

            /* Sofort veröffentlichen ist der Regelfall. Als Ausnahme lässt sich
               die Abwesenheit als Entwurf anlegen – dann erscheint sie in der
               Monatsplanung als Entwurf und wird dort wie gewohnt veröffentlicht. */
            $publish = !isset($_POST['publish']) || !empty($_POST['publish']);

            $db->beginTransaction();

            /* Die Anwendung führt je Tag entweder einen Einsatz oder eine
               Abwesenheit. Beim Veröffentlichen wird deshalb erst geräumt –
               genau wie api/publish.php es tut. */
            $delAsg = $db->prepare('DELETE FROM assignments WHERE user_id = ? AND plan_date = ?');
            $delAbs = $db->prepare('DELETE FROM absences    WHERE user_id = ? AND absence_date = ?');
            $delDrf = $db->prepare('DELETE FROM plan_drafts WHERE user_id = ? AND plan_date = ?');

            $insAbs = $db->prepare(
                'INSERT INTO absences (user_id, absence_date, absence_type, is_published, remarks,
                                       substitute_user_id, created_by, updated_at)
                 VALUES (?,?,?,1,?,?,?,NOW())');
            $insAsg = $db->prepare(
                'INSERT INTO assignments (user_id, plan_date, plan_area_id, year, month, is_published,
                                          remarks, remark_by, remark_at, created_by, updated_at)
                 VALUES (?,?,?,?,?,1,?,' . $ME . ',NOW(),?,NOW())');

            /* Entwurfsvariante – dasselbe Format wie api/save_bulk.php */
            $drfAbs = $db->prepare(
                "INSERT INTO plan_drafts (user_id, plan_date, year, month, action, change_type, change_value, remarks, created_by, created_at)
                 VALUES (?,?,?,?,'set','absence',?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE action='set', change_type='absence',
                     change_value=VALUES(change_value), remarks=VALUES(remarks),
                     created_by=VALUES(created_by), created_at=NOW()");
            $drfAsg = $db->prepare(
                "INSERT INTO plan_drafts (user_id, plan_date, year, month, action, change_type, change_value, remarks, created_by, created_at)
                 VALUES (?,?,?,?,'set','assignment',?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE action='set', change_type='assignment',
                     change_value=VALUES(change_value), remarks=VALUES(remarks),
                     created_by=VALUES(created_by), created_at=NOW()");

            $subCheck = $db->prepare('SELECT absence_type FROM absences WHERE user_id = ? AND absence_date = ?');
            /* Genehmigter Urlaub und Krankheit sind gesperrt wie in der
               Monatsplanung (api/save_bulk): überschreiben nur mit
               „Genehmigten Urlaub entsperren“ (Befund D-03). */
            $mayUnlock = hasPerm('unlock_vacation');
            $lockedTypes = ['urlaub_genehmigt','krank_halb','krank_ganz','krank_attest'];
            $ownCheck = $db->prepare('SELECT absence_type FROM absences WHERE user_id = ? AND absence_date = ? FOR UPDATE');
            /* Einen bestehenden ANDEREN Einsatz der Vertretung nicht stillschweigend ersetzen */
            $subAsgCheck = $db->prepare('SELECT plan_area_id FROM assignments WHERE user_id = ? AND plan_date = ? FOR UPDATE');

            /* Betroffene Tage und die dort geplanten Einsätze vorab bestimmen. */
            $dates = [];
            for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
                $d = date('Y-m-d', $t);
                if ($skipWe && (int)date('N', $t) >= 6) continue;
                if (isset($hol[$d])) continue;
                $dates[] = $d;
            }
            $byDate = [];
            foreach (abw_assignments($db, $uid, $dates) as $r) $byDate[$r['plan_date']] = $r;

            $written = 0; $moved = 0; $skipped = []; $subsUsed = [];
            foreach ($dates as $d) {
                $t      = strtotime($d);
                $yy     = (int)date('Y', $t);
                $mm     = (int)date('n', $t);
                $areaId = (int)($byDate[$d]['plan_area_id'] ?? 0);

                $ownCheck->execute([$uid, $d]);
                $ownAbs = $ownCheck->fetchColumn();
                if ($ownAbs !== false && $ownAbs !== $type && in_array($ownAbs, $lockedTypes, true) && !$mayUnlock) {
                    $skipped[] = date('d.m.', $t) . ': ' . (ABW_TYPES[$ownAbs] ?? $ownAbs) . ' ist gesperrt';
                    continue;
                }

                $before = planCellLoad($db, [[$uid, $d]], true)[planCellKey($uid, $d)];
                planChangeLog($db, $uid, $d, 'absence_module', planCellSnapshot($before),
                    $publish ? ['pub' => ['type' => 'absence', 'value' => $type]] : ['draft' => ['action' => 'set', 'type' => 'absence', 'value' => $type, 'by' => $ME]], $ME);
                if ($publish) {
                    /* Tag räumen: Einsatz, alte Abwesenheit und jeder Entwurf –
                       sonst überlagert der Entwurf die neue Abwesenheit in der
                       Monatsplanung und man sieht sie schlicht nicht. */
                    $delAsg->execute([$uid, $d]);
                    $delAbs->execute([$uid, $d]);
                    $delDrf->execute([$uid, $d]);
                    $insAbs->execute([$uid, $d, $type, $rem, $sub ?: null, $ME]);
                } else {
                    $drfAbs->execute([$uid, $d, $yy, $mm, $type, $rem, $ME]);
                }
                $written++;

                /* Vertretung je Tag – Vorrang hat die Einzelauswahl. */
                $dSub = array_key_exists($d, $subMap) ? (int)$subMap[$d] : $sub;
                if ($dSub && $areaId) {
                    $subCheck->execute([$dSub, $d]);
                    $subAbs = $subCheck->fetchColumn();
                    if ($subAbs !== false && $subAbs !== 'offen') {
                        $skipped[] = date('d.m.', $t) . ': ' . $allowed[$dSub]['last_name'] . ' ist selbst abwesend';
                        continue;
                    }
                    $subAsgCheck->execute([$dSub, $d]);
                    $subArea = $subAsgCheck->fetchColumn();
                    if ($subArea !== false && (int)$subArea !== $areaId) {
                        $skipped[] = date('d.m.', $t) . ': ' . $allowed[$dSub]['last_name'] . ' ist bereits anderweitig eingeplant';
                        continue;
                    }
                    /* Am Eintrag der Vertretung steht, für wen sie einspringt. */
                    $note = 'Vertretung für ' . $allowed[$uid]['first_name'] . ' ' . $allowed[$uid]['last_name'];
                    $sb = planCellLoad($db, [[$dSub, $d]], true)[planCellKey($dSub, $d)];
                    planChangeLog($db, $dSub, $d, 'absence_module', planCellSnapshot($sb),
                        $publish ? ['pub' => ['type' => 'assignment', 'value' => $areaId], 'substitute_for' => $uid] : ['draft' => ['action' => 'set', 'type' => 'assignment', 'value' => (string)$areaId, 'by' => $ME], 'substitute_for' => $uid], $ME);
                    if ($publish) {
                        $delAsg->execute([$dSub, $d]);
                        $delAbs->execute([$dSub, $d]);
                        $delDrf->execute([$dSub, $d]);
                        $insAsg->execute([$dSub, $d, $areaId, $yy, $mm, $note, $ME]);
                    } else {
                        $drfAsg->execute([$dSub, $d, $yy, $mm, $areaId, $note, $ME]);
                    }
                    $moved++;
                    $subsUsed[$dSub] = true;
                }
            }
            $db->commit();

            try {
                $db->prepare('INSERT INTO audit_log(user_id, action, details, ip_address) VALUES(?,?,?,?)')
                   ->execute([$ME, 'absence_bulk_save',
                       $allowed[$uid]['last_name'] . ': ' . (ABW_TYPES[$type] ?? $type) . ' ' . $from . ' bis ' . $to
                       . ' (' . $written . ' Tage' . ($sub ? ', Vertretung: ' . $allowed[$sub]['last_name'] : ', ersatzlos')
                       . ($publish ? ', veröffentlicht' : ', Entwurf') . ')',
                       opsClientIp()]);
            } catch (Exception $e) {}

            $msg = $written . ' Tag(e) ' . ($publish ? 'erfasst und veröffentlicht' : 'als Entwurf angelegt');
            if ($moved) {
                $msg .= ', ' . $moved . ' Einsatz/Einsätze übertragen';
                if (count($subsUsed) === 1) {
                    $one = (int)array_key_first($subsUsed);
                    $msg .= ' an ' . $allowed[$one]['last_name'];
                } elseif (count($subsUsed) > 1) {
                    $msg .= ' an ' . count($subsUsed) . ' Vertretungen';
                }
            } elseif ($sub || $subMap) {
                $msg .= ', keine Einsätze zu übertragen';
            } else {
                $msg .= ' – ersatzlos';
            }
            if (!$publish) $msg .= '. Veröffentlichen in der Monatsplanung';
            echo json_encode(['success' => true, 'msg' => $msg . '.',
                              'published' => $publish,
                              'skipped' => array_slice($skipped, 0, 10)]);
            exit;
        }

        /* --------------------------------------------------- Löschen --- */
        if ($act === 'delete') {
            $uid  = (int)($_POST['user_id'] ?? 0);
            $from = (string)($_POST['from'] ?? '');
            $to   = (string)($_POST['to'] ?? '');
            if (!isset($allowed[$uid])) throw new Exception('Diese Person können Sie nicht verwalten.');
            if (!opsValidDate($from) || !opsValidDate($to) || $to < $from) throw new Exception('Zeitraum fehlt oder ist ungültig.');
            if ((strtotime($to) - strtotime($from)) / 86400 > 400) throw new Exception('Der Zeitraum ist zu lang.');
            /* Gesperrte Arten (genehmigter Urlaub, Krankheit) nur mit
               unlock_vacation entfernen – wie in der Monatsplanung (D-03). */
            $keepLocked = hasPerm('unlock_vacation') ? '' : " AND absence_type NOT IN ('urlaub_genehmigt','krank_halb','krank_ganz','krank_attest')";
            $db->beginTransaction();
            $q = $db->prepare('DELETE FROM absences WHERE user_id = ? AND absence_date BETWEEN ? AND ?' . $keepLocked);
            $q->execute([$uid, $from, $to]);
            $n = $q->rowCount();
            $lk = $db->prepare('SELECT COUNT(*) FROM absences WHERE user_id = ? AND absence_date BETWEEN ? AND ?');
            $lk->execute([$uid, $from, $to]);
            $kept = (int)$lk->fetchColumn();
            /* Auch noch nicht veröffentlichte Entwürfe derselben Tage entfernen. */
            $d2 = $db->prepare("DELETE FROM plan_drafts WHERE user_id = ? AND plan_date BETWEEN ? AND ?
                                      AND change_type IN ('absence','locked')");
            $d2->execute([$uid, $from, $to]);
            $n += $d2->rowCount();
            $db->commit();
            $msg = $n . ' Tag(e) entfernt.';
            if ($kept) $msg .= ' ' . $kept . ' gesperrte(r) Tag(e) (genehmigter Urlaub/Krankheit) bleiben erhalten.';
            echo json_encode(['success' => true, 'msg' => $msg]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion.']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        /* Fachliche Meldungen (Exception) anzeigen, technische Fehler nur loggen */
        if ($e instanceof PDOException || !($e instanceof Exception)) {
            error_log('abwesenheiten: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Technischer Fehler beim Speichern.']);
        } else {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    exit;
}

/* =============================================================================
   Seite
   ========================================================================== */
$users = abw_users($db);

$pageTitle = 'Abwesenheiten';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.abw-head { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin-bottom: .8rem; }
.abw-cols { display: grid; grid-template-columns: 360px minmax(0, 1fr); gap: .85rem; align-items: start; }
@media (max-width: 1000px) { .abw-cols { grid-template-columns: minmax(0, 1fr); } }
.abw-item { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: .5rem; align-items: center;
            padding: .5rem .65rem; border: 1px solid var(--bd); border-radius: var(--r); margin-bottom: .35rem; }
.abw-item.krank { border-left: 3px solid var(--err); }
.abw-item.urlaub { border-left: 3px solid var(--ok); }
.abw-item.sonst { border-left: 3px solid var(--mu); }
.abw-n { font-size: .86rem; font-weight: 700; }
.abw-d { font-size: .76rem; color: var(--mu); line-height: 1.45; }
.abw-sub { display: inline-flex; align-items: center; gap: .25rem; font-size: .72rem; font-weight: 700;
           padding: .05rem .35rem; border-radius: 4px; background: var(--ibg); color: #075985; }
.abw-none { font-size: .72rem; color: var(--mu); font-style: italic; }
.abw-sum { background: var(--s); border: 1px solid var(--bd); border-radius: var(--r);
           padding: .45rem .65rem; font-size: .86rem; margin-bottom: .6rem; }
.abw-opts { display: flex; gap: .8rem; flex-wrap: wrap; margin-bottom: .3rem; }
.abw-ok { margin-top: .7rem; padding: .5rem .7rem; border-radius: var(--r); background: var(--okbg);
          border: 1px solid #bbf7d0; color: #15803d; font-size: .84rem; }
.abw-list { max-height: 210px; overflow-y: auto; border: 1px solid var(--bd); border-radius: var(--r); margin-top: .25rem; }
.abw-u { display: grid; grid-template-columns: auto minmax(0,1fr) auto; gap: .45rem; align-items: center;
         width: 100%; padding: .35rem .5rem; border: none; border-bottom: 1px solid var(--bd);
         background: #fff; cursor: pointer; text-align: left; font: inherit; }
.abw-u:last-child { border-bottom: none; }
.abw-u:hover { background: var(--s); }
.abw-u.on { background: var(--pbg); box-shadow: inset 3px 0 0 var(--p); }
.abw-u-av { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center;
            justify-content: center; font-size: .64rem; font-weight: 800; }
.abw-u-n { font-size: .84rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.abw-u-n small { display: block; font-size: .68rem; color: var(--mu); font-weight: 400; }
.abw-u-a { font-size: .64rem; font-weight: 800; padding: .1rem .3rem; border-radius: 4px; }
.abw-u-a.none { background: var(--s); color: var(--mu); }
.abw-empty { padding: .6rem; font-size: .8rem; color: var(--mu); }
.abw-deputy { display: flex; gap: .5rem; align-items: flex-start; padding: .5rem .75rem; margin-bottom: .7rem;
              background: var(--ibg); border: 1px solid #bae6fd; color: #075985; border-radius: var(--r2); font-size: .82rem; }
.abw-modal { max-width: 940px; width: 94vw; }
.abw-modal .mb { max-height: 68vh; overflow-y: auto; }

.abw-cands { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: .45rem; }
.abw-tile { position: relative; display: flex; flex-direction: column; gap: .15rem; align-items: flex-start;
            padding: .55rem .6rem; border: 1px solid var(--bd); border-radius: var(--r2); background: #fff;
            cursor: pointer; text-align: left; font: inherit; transition: border-color .12s, box-shadow .12s; }
.abw-tile:hover:not(:disabled) { border-color: var(--pl); box-shadow: var(--sh); }
.abw-tile.on { border-color: var(--p); background: var(--pbg); box-shadow: inset 0 0 0 1px var(--p); }
.abw-tile.off { opacity: .5; cursor: not-allowed; }
.abw-tile.best { border-left: 3px solid var(--ok); }
.abw-tile-badge { position: absolute; top: -7px; right: .5rem; font-size: .58rem; font-weight: 800;
                  text-transform: uppercase; letter-spacing: .04em; padding: .05rem .3rem; border-radius: 4px;
                  background: var(--ok); color: #fff; }
.abw-tile-av { width: 26px; height: 26px; border-radius: 50%; background: var(--ink3, #475569); color: #fff;
               display: flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 800; }
.abw-tile.none .abw-tile-av { background: var(--mu); }
.abw-tile-n { font-size: .84rem; font-weight: 700; }
.abw-tile-w { font-size: .72rem; color: var(--mu); line-height: 1.35; }
.abw-tile-bar { width: 100%; height: 4px; border-radius: 2px; background: var(--bd); overflow: hidden; margin-top: .2rem; }
.abw-tile-bar i { display: block; height: 100%; background: var(--ok); }
.abw-tile-f { font-size: .68rem; color: var(--mu); font-weight: 600; }

.abw-tbl { width: 100%; border-collapse: collapse; font-size: .82rem; }
.abw-tbl th { text-align: left; font-size: .68rem; text-transform: uppercase; letter-spacing: .04em;
              color: var(--mu); font-weight: 700; padding: .3rem .4rem; border-bottom: 1px solid var(--bd); }
.abw-tbl td { padding: .3rem .4rem; border-bottom: 1px solid var(--bd); vertical-align: middle; }
.abw-tbl tr:last-child td { border-bottom: none; }
.abw-tbl tr.covered { background: var(--okbg); }
.abw-td-d { white-space: nowrap; font-weight: 600; }
.abw-sel { width: 100%; padding: .18rem .3rem; border: 1px solid var(--bd); border-radius: var(--r); font-size: .78rem; }
#impCover.ok { color: #15803d; font-weight: 700; }

.abw-drop { display: flex; align-items: center; gap: .5rem; padding: .35rem .5rem; border: 1px solid var(--bd);
            border-radius: var(--r); margin-bottom: .25rem; font-size: .82rem; }
.abw-drop-a { font-size: .66rem; font-weight: 800; padding: .08rem .35rem; border-radius: 4px; }
.abw-cand { display: grid; grid-template-columns: auto minmax(0,1fr) auto; gap: .5rem; align-items: center;
            padding: .45rem .55rem; border: 1px solid var(--bd); border-radius: var(--r); margin-bottom: .3rem;
            cursor: pointer; }
.abw-cand:hover { background: var(--s); }
.abw-cand.on { border-color: var(--p); background: var(--pbg); }
.abw-cand.top { border-left: 3px solid var(--ok); }
.abw-cand-n { font-size: .86rem; font-weight: 700; }
.abw-cand-w { font-size: .74rem; color: var(--mu); }
.abw-cand input { accent-color: var(--p); }
.abw-prev { background: var(--s); border: 1px solid var(--bd); border-radius: var(--r);
            padding: .5rem .65rem; font-size: .8rem; line-height: 1.5; margin-top: .5rem; }
.abw-prev b { color: var(--tx); }
</style>

<?php if ($DEPUTY_FOR && !hasPerm('manage_absences')): ?>
<div class="abw-deputy">
  <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" style="flex-shrink:0;margin-top:1px"><path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.97 5.97 0 00-.75-2.906A3 3 0 0119 15v3h-3zM4.75 12.094A5.97 5.97 0 004 15v3H1v-3a3 3 0 013.75-2.906z"/></svg>
  <div>Sie handeln als <b>Vertretung für
    <?php echo e(implode(', ', array_map(function ($d) { return $d['first_name'] . ' ' . $d['last_name']; }, $DEPUTY_FOR))); ?></b>.
    Sie sehen und verwalten deshalb auch deren Team. Jede Erfassung wird mit Ihrem Namen protokolliert.</div>
</div>
<?php endif; ?>

<div class="abw-head">
  <h2 style="font-size:1.05rem;font-weight:800">Abwesenheiten</h2>
  <span class="muted" style="font-size:.82rem">Urlaub, Krankheit und Sperrtage erfassen – wahlweise mit Vertretung</span>
  <a class="btn btn-outline btn-sm" href="<?php echo e($BP); ?>monatsplanung" style="margin-left:auto">Zur Monatsplanung</a>
</div>

<div class="abw-cols">
  <!-- ---------------- Erfassung ---------------- -->
  <div class="card">
    <div class="ch"><span>Abwesenheit erfassen</span></div>
    <div class="cb">
      <div class="fg"><label>Mitarbeiter</label>
        <input type="text" class="inp-sm" id="aSearch" placeholder="Name oder Bereich suchen …" autocomplete="off">
        <div class="abw-list" id="aUserList"></div>
        <input type="hidden" id="aUser" value="">
        <?php if (!$users): ?><small class="muted">Für Sie sind keine Mitarbeiter hinterlegt.</small><?php endif; ?>
      </div>

      <div class="fg"><label>Art der Abwesenheit</label>
        <select id="aType">
          <?php foreach (ABW_TYPES as $k => $v): ?>
            <option value="<?php echo e($k); ?>"><?php echo e($v); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg2">
        <div class="fg"><label>Von</label><input type="date" id="aFrom" value="<?php echo date('Y-m-d'); ?>"></div>
        <div class="fg"><label>Bis</label><input type="date" id="aTo" value="<?php echo date('Y-m-d'); ?>"></div>
      </div>

      <div class="abw-prev" id="aPrev">Bitte Mitarbeiter und Zeitraum wählen.</div>
      <p class="wz-hint" style="margin-top:.4rem">Im nächsten Schritt sehen Sie, welche Einsätze in diesem
        Zeitraum wegfallen, und wählen dort Vertretung, Bemerkung und Veröffentlichung.</p>
    </div><!-- /cb -->

    <div class="mf">
      <button class="btn btn-outline" onclick="ABW.reset()">Zurücksetzen</button>
      <button class="btn btn-primary" onclick="ABW.save()">Weiter</button>
    </div>
  </div>

  <!-- ---------------- Übersicht ---------------- -->
  <div class="card">
    <div class="ch"><span>Erfasste Abwesenheiten</span>
      <span style="display:flex;gap:.35rem;align-items:center">
        <input type="date" class="inp-sm" id="fFrom" value="<?php echo date('Y-m-01'); ?>">
        <input type="date" class="inp-sm" id="fTo" value="<?php echo date('Y-m-t', strtotime('+2 month')); ?>">
        <button class="btn btn-xs btn-outline" onclick="ABW.load()">Anzeigen</button>
      </span></div>
    <div class="cb" id="aList" style="max-height:calc(100vh - 260px);overflow:auto">
      <p class="muted">Wird geladen …</p>
    </div>
  </div>
</div>

<!-- ---------------- Vorschau vor dem Erfassen ---------------- -->
<div class="modal-bg" id="ovImpact"><div class="modal modal-lg abw-modal">
  <div class="mh"><h3 id="impTitle">Auswirkung prüfen</h3><button class="mx" onclick="cMod('ovImpact')">&times;</button></div>
  <div class="mb" id="impBody"></div>
  <div class="mf" id="impFoot"></div>
</div></div>

<script>
"use strict";
var ABW_USERS = <?php echo json_encode(array_map(function ($u) {
    return ['id' => (int)$u['id'], 'name' => $u['last_name'] . ', ' . $u['first_name'],
            'short' => mb_strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1)),
            'area' => $u['area_code'] ?: '', 'area_name' => $u['area_name'] ?: '',
            'color' => $u['color'] ?: '#94a3b8', 'fg' => $u['fg_color'] ?: '#fff',
            'role' => roleName($u['role'])];
}, $users), JSON_UNESCAPED_UNICODE); ?>;

var ABW = (function () {
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function dmy(d) { var p = String(d || '').split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : d; }
  function say(m, t) { if (typeof toast === 'function') toast(m, t || 'info'); else alert(m); }

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(location.pathname + location.search, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.text(); }).then(function (t) {
      try { return JSON.parse(t); } catch (e) { throw new Error('Unerwartete Antwort des Servers.'); }
    });
  }

  /* Kurzvorschau im Formular – die Einzelheiten kommen im Dialog. */
  /* Mitarbeiterauswahl mit Suche über Name, Bereich und Rolle. */
  function renderUsers() {
    var q = ($('aSearch').value || '').toLowerCase().trim();
    var sel = $('aUser').value;
    var rows = ABW_USERS.filter(function (u) {
      if (!q) return true;
      return (u.name + ' ' + u.area + ' ' + u.area_name + ' ' + u.role).toLowerCase().indexOf(q) >= 0;
    });
    if (!rows.length) {
      $('aUserList').innerHTML = '<div class="abw-empty">Keine Person gefunden.</div>';
      return;
    }
    $('aUserList').innerHTML = rows.map(function (u) {
      return '<button type="button" class="abw-u' + (String(u.id) === sel ? ' on' : '') + '" data-u="' + u.id + '">' +
        '<span class="abw-u-av" style="background:' + esc(u.color) + ';color:' + esc(u.fg) + '">' + esc(u.short) + '</span>' +
        '<span class="abw-u-n">' + esc(u.name) + '<small>' + esc(u.role) + '</small></span>' +
        (u.area ? '<span class="abw-u-a" style="background:' + esc(u.color) + ';color:' + esc(u.fg) + '" title="' +
                  esc(u.area_name) + '">' + esc(u.area) + '</span>' : '<span class="abw-u-a none">–</span>') +
        '</button>';
    }).join('');
    $('aUserList').querySelectorAll('[data-u]').forEach(function (b) {
      b.addEventListener('click', function () {
        $('aUser').value = b.dataset.u;
        renderUsers();
        preview();
      });
    });
  }

  function pubHint() {
    var h = $('mPubHint'), c = $('mPublish');
    if (!h || !c) return;
    h.textContent = c.checked
      ? 'Die Abwesenheit steht sofort in der Monatsplanung und wirkt in der Einsatzplanung.'
      : 'Ausnahme: Sie wird als Entwurf angelegt und muss in der Monatsplanung noch veröffentlicht werden.';
  }

  function preview() {
    var u = $('aUser').value, from = $('aFrom').value, to = $('aTo').value;
    var box = $('aPrev'); if (!box) return;
    if (!u || !from || !to) { box.textContent = 'Bitte Mitarbeiter und Zeitraum wählen.'; return; }
    if (to < from) { box.innerHTML = '<b style="color:var(--err)">Das Enddatum liegt vor dem Start.</b>'; return; }
    var n = 0;
    for (var t = new Date(from + 'T12:00:00'); t <= new Date(to + 'T12:00:00'); t.setDate(t.getDate() + 1)) {
      var d = t.getDay();
      if (d === 0 || d === 6) continue;
      n++;
    }
    box.innerHTML = '<b>' + n + ' Arbeitstag(e)</b> im gewählten Zeitraum. ' +
                    'Weiter klicken, um Einsätze und Vertretung zu prüfen.';
  }

  /* Zwischenspeicher für die Angaben, die im Dialog gemacht werden. */
  var opt = { we: true, hol: true, remarks: '', publish: true };
  var LAST = null;   // letzte Vorschau, für die Kachelauswahl
  var WD = ['So','Mo','Di','Mi','Do','Fr','Sa'];
  function wdShort(iso) { return WD[new Date(iso + 'T12:00:00').getDay()]; }

  /* Schritt 2: Der Dialog zeigt, was tatsächlich passiert – welche Einsätze
     wegfallen, wer sie übernehmen kann und wie veröffentlicht wird. */
  function save() {
    var u = $('aUser').value;
    if (!u) { say('Bitte einen Mitarbeiter wählen.', 'warning'); return; }
    if (!$('aFrom').value || !$('aTo').value) { say('Bitte einen Zeitraum angeben.', 'warning'); return; }
    if ($('aTo').value < $('aFrom').value) { say('Das Enddatum liegt vor dem Start.', 'warning'); return; }
    opt = { we: true, hol: true, remarks: '', publish: true };
    $('impBody').innerHTML = '<p class="muted">Einsätze werden geprüft …</p>';
    $('impFoot').innerHTML = '';
    oMod('ovImpact');
    fetchImpact();
  }

  function fetchImpact() {
    post('impact', {
      user_id: $('aUser').value, from: $('aFrom').value, to: $('aTo').value,
      skip_weekend: opt.we ? 1 : 0, skip_holidays: opt.hol ? 1 : 0
    }).then(function (d) {
      if (!d.success) { $('impBody').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
      renderImpact(d);
    }).catch(function (e) { $('impBody').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }

  function renderImpact(d) {
    var uid = $('aUser').value, name = uid;
    ABW_USERS.forEach(function (u) { if (String(u.id) === String(uid)) name = u.name; });
    var ty = $('aType'), tyName = ty.options[ty.selectedIndex].text;
    $('impTitle').textContent = tyName + ' für ' + name;
    LAST = d;

    var h = '<div class="abw-sum"><b>' + dmy($('aFrom').value) + ' – ' + dmy($('aTo').value) + '</b>' +
            ' · <b>' + d.days + ' Tag(e)</b> werden erfasst</div>';

    h += '<div class="abw-opts">' +
      '<label class="wd-check"><input type="checkbox" id="mSkipWe"' + (opt.we ? ' checked' : '') + '> Wochenenden auslassen</label>' +
      '<label class="wd-check" title="Nur gesetzliche Feiertage – Schulferien sind normale Arbeitstage">' +
      '<input type="checkbox" id="mSkipHol"' + (opt.hol ? ' checked' : '') + '> Gesetzliche Feiertage auslassen</label>' +
      '</div>';

    if (!d.items.length) {
      h += '<div class="abw-ok">In diesem Zeitraum sind keine Einsätze geplant – es fällt nichts weg, ' +
           'eine Vertretung ist nicht nötig.</div>';
      h += abschluss();
      $('impBody').innerHTML = h;
      bindCommon();
      foot(false);
      return;
    }

    /* ---- Vertretungen als Kacheln: wer kommt überhaupt infrage ---- */
    h += '<div class="wz-sec-title" style="margin-top:.9rem">Wer kann übernehmen?</div>' +
         '<p class="wz-hint">Ein Klick setzt die Person für alle Tage ein, an denen sie frei ist. ' +
         'Darunter lässt sich jeder Tag einzeln ändern.</p>';

    h += '<div class="abw-cands">';
    h += '<button type="button" class="abw-tile none" data-all="0">' +
         '<span class="abw-tile-av">–</span>' +
         '<span class="abw-tile-n">Ersatzlos</span>' +
         '<span class="abw-tile-w">Alle Einsätze entfallen</span></button>';
    d.subs.forEach(function (c, i) {
      var pct = Math.round(c.free / c.of * 100);
      h += '<button type="button" class="abw-tile' + (c.free === 0 ? ' off' : '') + (i === 0 && c.free ? ' best' : '') +
             '" data-all="' + c.id + '"' + (c.free === 0 ? ' disabled' : '') + '>' +
           (i === 0 && c.free ? '<span class="abw-tile-badge">Empfehlung</span>' : '') +
           '<span class="abw-tile-av">' + esc(c.short) + '</span>' +
           '<span class="abw-tile-n">' + esc(c.name) + '</span>' +
           '<span class="abw-tile-w">' + esc(c.why) + '</span>' +
           '<span class="abw-tile-bar"><i style="width:' + pct + '%"></i></span>' +
           '<span class="abw-tile-f">' + c.free + ' von ' + c.of + ' Tagen frei</span>' +
           '</button>';
    });
    h += '</div>';
    if (!d.subs.length) h += '<p class="muted" style="font-size:.8rem">Keine weiteren Personen verfügbar.</p>';

    /* ---- Tag für Tag ---- */
    h += '<div class="wz-sec-title" style="margin-top:.9rem">Einsätze im Einzelnen (' + d.items.length + ')</div>';
    h += '<table class="abw-tbl"><thead><tr><th>Tag</th><th>Einsatz</th><th>Übernimmt</th></tr></thead><tbody>';
    d.items.forEach(function (it) {
      h += '<tr><td class="abw-td-d">' + wdShort(it.plan_date) + ' ' + dmy(it.plan_date) + '</td>' +
        '<td><span class="abw-drop-a" style="background:' + esc(it.color || '#666') + ';color:' + esc(it.fg_color || '#fff') + '">' +
          esc(it.short_code) + '</span> <span class="muted">' + esc(it.area_name) +
          (it.src === 'draft' ? ' (Entwurf)' : '') + '</span></td>' +
        '<td><select class="abw-sel" data-day="' + it.plan_date + '">' +
          '<option value="0">ersatzlos</option>' +
          d.subs.map(function (c) {
            var can = c.free_dates.indexOf(it.plan_date) >= 0;
            return '<option value="' + c.id + '"' + (can ? '' : ' disabled') + '>' +
                   esc(c.name) + (can ? '' : ' – nicht verfügbar') + '</option>';
          }).join('') +
        '</select></td></tr>';
    });
    h += '</tbody></table>';

    h += abschluss();
    $('impBody').innerHTML = h;

    /* Kachel setzt alle Tage, an denen die Person frei ist */
    $('impBody').querySelectorAll('[data-all]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.dataset.all;
        $('impBody').querySelectorAll('.abw-tile').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        var cand = null;
        LAST.subs.forEach(function (c) { if (String(c.id) === id) cand = c; });
        $('impBody').querySelectorAll('.abw-sel').forEach(function (sel) {
          if (id === '0') { sel.value = '0'; return; }
          sel.value = (cand && cand.free_dates.indexOf(sel.dataset.day) >= 0) ? id : '0';
        });
        markRows();
      });
    });
    $('impBody').querySelectorAll('.abw-sel').forEach(function (sel) {
      sel.addEventListener('change', markRows);
    });
    markRows();
    bindCommon();
    foot(true);
  }

  function abschluss() {
    return '<div class="wz-sec-title" style="margin-top:.9rem">Abschluss</div>' +
      '<div class="fg"><label>Bemerkung zur Abwesenheit (optional)</label>' +
      '<input type="text" id="mRemarks" maxlength="500" value="' + esc(opt.remarks) + '"></div>' +
      '<div class="fg-check"><input type="checkbox" id="mPublish"' + (opt.publish ? ' checked' : '') + '>' +
      '<label for="mPublish">Sofort veröffentlichen</label></div>' +
      '<small class="muted" id="mPubHint"></small>' +
      '<small class="muted" style="display:block;margin-top:.35rem">Bei der Vertretung wird automatisch ' +
      '„Vertretung für …“ am Einsatz vermerkt.</small>';
  }

  function markRows() {
    var n = 0;
    $('impBody').querySelectorAll('.abw-sel').forEach(function (sel) {
      var on = sel.value !== '0';
      sel.closest('tr').classList.toggle('covered', on);
      if (on) n++;
    });
    var info = $('impCover');
    if (info) {
      var tot = $('impBody').querySelectorAll('.abw-sel').length;
      info.textContent = n + ' von ' + tot + ' Einsätzen übernommen';
      info.className = 'muted' + (n === tot ? ' ok' : '');
    }
  }

  function bindCommon() {
    ['mSkipWe', 'mSkipHol'].forEach(function (id) {
      var el = $(id); if (!el) return;
      el.addEventListener('change', function () {
        opt.we  = $('mSkipWe').checked;
        opt.hol = $('mSkipHol').checked;
        opt.remarks = $('mRemarks') ? $('mRemarks').value : opt.remarks;
        opt.publish = $('mPublish') ? $('mPublish').checked : opt.publish;
        fetchImpact();
      });
    });
    var mp = $('mPublish');
    if (mp) mp.addEventListener('change', pubHint);
    pubHint();
  }

  function foot(hasItems) {
    $('impFoot').innerHTML =
      (hasItems ? '<span id="impCover" class="muted" style="margin-right:auto;font-size:.8rem"></span>' : '') +
      '<button class="btn btn-outline" onclick="cMod(\'ovImpact\')">Abbrechen</button>' +
      '<button class="btn btn-primary" id="impGo">Als abwesend eintragen</button>';
    $('impGo').onclick = function () {
      opt.remarks = $('mRemarks') ? $('mRemarks').value : '';
      opt.publish = $('mPublish') ? $('mPublish').checked : true;
      opt.we      = $('mSkipWe')  ? $('mSkipWe').checked  : true;
      opt.hol     = $('mSkipHol') ? $('mSkipHol').checked : true;
      var map = {};
      $('impBody').querySelectorAll('.abw-sel').forEach(function (sel) {
        map[sel.dataset.day] = parseInt(sel.value, 10) || 0;
      });
      doSave(map);
    };
    markRows();
  }

  function doSave(map) {
    cMod('ovImpact');
    post('save', {
      user_id: $('aUser').value, type: $('aType').value, from: $('aFrom').value, to: $('aTo').value,
      substitute: 0, substitutes: JSON.stringify(map || {}), remarks: opt.remarks,
      publish: opt.publish ? 1 : 0,
      skip_weekend: opt.we ? 1 : 0, skip_holidays: opt.hol ? 1 : 0
    }).then(function (d) {
      if (!d.success) { say(d.error || 'Speichern fehlgeschlagen.', 'error'); return; }
      say(d.msg, 'success');
      if (d.skipped && d.skipped.length) {
        say('Hinweis: ' + d.skipped.join(' · '), 'warning');
      }
      load();
    }).catch(function (e) { say(e.message, 'error'); });
  }

  function load() {
    $('aList').innerHTML = '<p class="muted">Wird geladen …</p>';
    post('list', { from: $('fFrom').value, to: $('fTo').value }).then(function (d) {
      if (!d.success) { $('aList').innerHTML = '<p class="muted">' + esc(d.error || 'Fehler') + '</p>'; return; }
      if (!d.items.length) {
        $('aList').innerHTML = '<p class="muted">Im gewählten Zeitraum ist nichts erfasst.</p>';
        return;
      }
      $('aList').innerHTML = d.items.map(function (it) {
        var cls = /krank/.test(it.type) ? 'krank' : (/urlaub/.test(it.type) ? 'urlaub' : 'sonst');
        var span = it.start === it.end ? dmy(it.start) : dmy(it.start) + ' – ' + dmy(it.end);
        return '<div class="abw-item ' + cls + '">' +
          '<div><div class="abw-n">' + esc(it.name) +
          (it.published ? '' : ' <span class="badge bw" style="font-size:.62rem">Entwurf</span>') + '</div>' +
          '<div class="abw-d">' + esc(it.label) + ' · ' + span + ' · ' + it.days + ' Tag(e)' +
          (it.remarks ? '<br>' + esc(it.remarks) : '') + '</div>' +
          '<div style="margin-top:.25rem">' + (it.substitute
              ? '<span class="abw-sub">Vertretung: ' + esc(it.substitute) + '</span>'
              : '<span class="abw-none">ersatzlos</span>') + '</div></div>' +
          '<button class="btn btn-xs btn-outline" data-del="' + it.user_id + '|' + it.start + '|' + it.end + '">Entfernen</button>' +
        '</div>';
      }).join('');
      $('aList').querySelectorAll('[data-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          var p = b.dataset.del.split('|');
          if (!confirm('Diese Abwesenheit entfernen? Bereits übertragene Einsätze der Vertretung bleiben bestehen.')) return;
          post('delete', { user_id: p[0], from: p[1], to: p[2] }).then(function (r) {
            if (r.success) { say(r.msg, 'success'); load(); }
            else say(r.error || 'Löschen nicht möglich.', 'error');
          });
        });
      });
    }).catch(function (e) { $('aList').innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }

  function reset() {
    $('aUser').value = ''; $('aSearch').value = ''; renderUsers();
    $('aFrom').value = $('aTo').value = new Date().toISOString().slice(0, 10);
    preview();
  }

  document.addEventListener('DOMContentLoaded', function () {
    $('aSearch').addEventListener('input', renderUsers);
    renderUsers();
    ['aType', 'aFrom', 'aTo'].forEach(function (id) {
      var el = $(id); if (!el) return;
      el.addEventListener('change', preview);
    });
    $('aFrom').addEventListener('change', function () {
      if ($('aTo').value < $('aFrom').value) $('aTo').value = $('aFrom').value;
      preview();
    });
    preview(); load();
  });

  return { save: save, load: load, reset: reset };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
