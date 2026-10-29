<?php
/**
 * Planquadro – Planungshilfen („Planning plus“)
 * --------------------------------------------------------------------------
 * Serverseitige Bausteine für die Ergänzungen der Planungsmatrix:
 *   - Soll-Besetzung je Bereich und Wochentag (Tabelle plan_area_targets, Migration 0009)
 *   - Zell-Details: Entwurf von wem/wann und letzte Änderungen aus plan_change_log
 *   - Planungs-Kennzahlen fürs Dashboard
 *
 * Rechte und Vertraulichkeit wie überall: Scope über canEditUser()/visibleUserIds(),
 * Art von Urlaub/Krankheit nur mit canSeeAbsenceDetails(), interne Einträge nur
 * mit plan_view_internal. Geschrieben wird hier nur die Soll-Besetzung
 * (Recht manage_config); Planzellen ändern weiterhin ausschließlich api/save_bulk.
 *
 * Benötigt public/includes/functions.php (Rechte, Bereiche, Feiertage).
 */

const PQ_TARGET_MAX = 999;

/** Soll-Besetzung: [plan_area_id => [weekday(1–7) => min_staff]]; ohne Tabelle leer. */
function pqTargetsAll(PDO $db): array
{
    $out = [];
    try {
        foreach ($db->query('SELECT plan_area_id, weekday, min_staff FROM plan_area_targets WHERE min_staff > 0')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['plan_area_id']][(int)$r['weekday']] = (int)$r['min_staff'];
        }
    } catch (Throwable $e) { /* Migration 0009 noch nicht eingespielt */ }
    return $out;
}

/**
 * Prüft und normalisiert eine Soll-Vorgabe aus dem Browser.
 * @param mixed $in  {"<area_id>": {"1": 2, …, "7": 0}, …}
 * @return array|string  normalisierte Vorgabe oder Fehlermeldung
 */
function pqTargetsNormalize($in, array $areaIds)
{
    if (!is_array($in)) return 'Ungültige Daten';
    $known = array_flip(array_map('intval', $areaIds));
    $out = [];
    foreach ($in as $aid => $days) {
        $aid = (int)$aid;
        if (!isset($known[$aid])) return 'Unbekannter Einsatzbereich';
        if (!is_array($days)) return 'Ungültige Daten';
        foreach ($days as $wd => $n) {
            $wd = (int)$wd;
            if ($wd < 1 || $wd > 7) return 'Ungültiger Wochentag';
            if ($n === '' || $n === null) $n = 0;
            if (!is_numeric($n) || (int)$n != $n || (int)$n < 0 || (int)$n > PQ_TARGET_MAX) return 'Soll-Werte bitte als ganze Zahl von 0 bis ' . PQ_TARGET_MAX;
            if ((int)$n > 0) $out[$aid][$wd] = (int)$n;
        }
    }
    return $out;
}

/** Ersetzt die gesamte Soll-Vorgabe (eine Transaktion). */
function pqTargetsSave(PDO $db, array $targets, int $by): void
{
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM plan_area_targets');
        $ins = $db->prepare('INSERT INTO plan_area_targets(plan_area_id, weekday, min_staff, updated_by) VALUES(?,?,?,?)');
        foreach ($targets as $aid => $days) foreach ($days as $wd => $n) $ins->execute([(int)$aid, (int)$wd, (int)$n, $by]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Lesbare Bezeichnung eines protokollierten Zellinhalts ({type, value} bzw.
 * Entwurf {action, type, value}). Vertrauliche Abwesenheiten ohne Detailrecht
 * erscheinen als „Abwesend“.
 */
function pqStateLabel(?array $s, int $uid, array $pam): string
{
    if (!$s) return 'leer';
    if (($s['action'] ?? 'set') === 'clear') return 'leer';
    $t = (string)($s['type'] ?? ''); $v = (string)($s['value'] ?? '');
    if ($t === 'assignment') return isset($pam[(int)$v]) ? (string)$pam[(int)$v]['short_code'] : 'Einsatz';
    if ($t === 'locked' || $v === 'gesperrt') return 'Gesperrt';
    if ($t === 'absence') {
        if (isConfidentialAbsence($v) && !canSeeAbsenceDetails($uid)) return 'Abwesend';
        return absenceName($v);
    }
    return 'leer';
}

/** Effektiver Inhalt eines Protokoll-Zustands (Entwurf vor veröffentlichtem Stand). */
function pqLogEffective(?array $st): ?array
{
    if (!$st) return null;
    if (!empty($st['draft']) && is_array($st['draft'])) return $st['draft'];
    if (!empty($st['pub']) && is_array($st['pub'])) return $st['pub'];
    return null;
}

const PQ_LOG_ACTIONS = [
    'draft_set' => 'Entwurf', 'draft_clear' => 'Entwurf (geleert)', 'overwrite' => 'Überschrieben',
    'publish' => 'Veröffentlicht', 'remark' => 'Bemerkung', 'approve' => 'Antrag genehmigt',
    'absence_module' => 'Abwesenheitsmodul', 'assistant_apply' => 'Planungsassistent',
    'assistant_undo' => 'Assistent rückgängig',
];

/**
 * Details einer Zelle für die Hover-Anzeige der Monatsplanung.
 * Aufrufer prüft canEdit() und canEditUser($uid).
 * @return array{draft: ?array, history: array<int, array>}
 */
function pqCellInfo(PDO $db, int $uid, string $date, int $limit = 5): array
{
    $pam = planAreaMap();
    $canInt = hasPerm('plan_view_internal');
    $names = [];
    $name = function ($id) use ($db, &$names) {
        $id = (int)$id; if (!$id) return '';
        if (!array_key_exists($id, $names)) {
            $s = $db->prepare('SELECT first_name, last_name FROM users WHERE id=?'); $s->execute([$id]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $names[$id] = $r ? trim($r['first_name'] . ' ' . $r['last_name']) : '';
        }
        return $names[$id];
    };

    $draft = null;
    $q = $db->prepare('SELECT action, change_type, change_value, is_internal, created_by, created_at FROM plan_drafts WHERE user_id=? AND plan_date=?');
    $q->execute([$uid, $date]);
    if (($d = $q->fetch(PDO::FETCH_ASSOC)) && (!(int)$d['is_internal'] || $canInt)) {
        $draft = ['by' => $name($d['created_by']), 'at' => date('d.m.Y H:i', strtotime($d['created_at'])),
                  'what' => pqStateLabel(['action' => $d['action'], 'type' => $d['change_type'], 'value' => $d['change_value']], $uid, $pam),
                  'internal' => (int)$d['is_internal']];
    }

    $history = [];
    $q = $db->prepare('SELECT action, old_state, new_state, changed_by, changed_at FROM plan_change_log WHERE user_id=? AND plan_date=? ORDER BY id DESC LIMIT 40');
    $q->execute([$uid, $date]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (count($history) >= $limit) break;
        $old = json_decode((string)$r['old_state'], true); $new = json_decode((string)$r['new_state'], true);
        $old = is_array($old) ? $old : null; $new = is_array($new) ? $new : null;
        /* Interne (nicht zu veröffentlichende) Entwürfe nur mit Recht – wie in live_status */
        if (!$canInt && (!empty($old['draft']['internal']) || !empty($new['draft']['internal']))) continue;
        $history[] = [
            'action' => PQ_LOG_ACTIONS[$r['action']] ?? $r['action'],
            'by' => $name($r['changed_by']),
            'at' => date('d.m.Y H:i', strtotime($r['changed_at'])),
            'from' => pqStateLabel(pqLogEffective($old), $uid, $pam),
            'to' => $r['action'] === 'remark' ? null : pqStateLabel(pqLogEffective($new), $uid, $pam),
        ];
    }
    return ['draft' => $draft, 'history' => $history];
}

/** Bereichs-Infos für Hover (voller Name, Kunde, Standort) – nur Stammdaten, keine Personendaten. */
function pqAreaInfo(PDO $db): array
{
    $out = [];
    foreach (planAreas() as $a) $out[(int)$a['id']] = ['code' => $a['short_code'], 'name' => $a['name'], 'color' => $a['color'], 'fg' => $a['fg_color'], 'type' => $a['area_type'], 'customer' => '', 'location' => ''];
    try {
        $q = $db->query('SELECT pa.id, c.name AS customer, l.name AS location FROM plan_areas pa
            LEFT JOIN customers c ON c.id = pa.customer_id LEFT JOIN locations l ON l.id = pa.location_id');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($out[(int)$r['id']])) continue;
            $out[(int)$r['id']]['customer'] = (string)($r['customer'] ?? '');
            $out[(int)$r['id']]['location'] = (string)($r['location'] ?? '');
        }
    } catch (Throwable $e) { /* Stammdaten-Migration fehlt */ }
    return $out;
}

/**
 * Planungs-Kennzahlen für das Dashboard (nur Planer, Scope wie Monatsplanung).
 * Zählt Einsätze effektiv (Entwurf vor veröffentlichtem Stand).
 */
function pqPlanningKpis(PDO $db, int $me, int $days = 14): array
{
    $scope = visibleUserIds();
    $inScope = function ($uid) use ($scope) { return $scope === null || in_array((int)$uid, array_map('intval', $scope), true); };
    $today = date('Y-m-d');
    $end = date('Y-m-d', strtotime($today . ' +' . ($days - 1) . ' day'));

    /* Offene Anträge im eigenen Bereich */
    $pending = 0;
    try {
        foreach ($db->query("SELECT user_id FROM change_requests WHERE status='pending'")->fetchAll(PDO::FETCH_COLUMN) as $u) if ($inScope($u)) $pending++;
    } catch (Throwable $e) {}

    /* Unveröffentlichte Entwürfe: eigene (je Monat) und die anderer Planer im eigenen Bereich */
    $myDrafts = 0; $myOldest = null; $scopeDrafts = 0; $scopeOldest = null; $myMonths = [];
    foreach ($db->query('SELECT user_id, year, month, created_by, created_at FROM plan_drafts WHERE is_internal=0')->fetchAll(PDO::FETCH_ASSOC) as $d) {
        if ((int)$d['created_by'] === $me) {
            $myDrafts++; if ($myOldest === null || $d['created_at'] < $myOldest) $myOldest = $d['created_at'];
            $ym = sprintf('%04d-%02d', $d['year'], $d['month']);
            if (!isset($myMonths[$ym])) $myMonths[$ym] = ['year' => (int)$d['year'], 'month' => (int)$d['month'], 'count' => 0, 'oldest' => $d['created_at']];
            $myMonths[$ym]['count']++; if ($d['created_at'] < $myMonths[$ym]['oldest']) $myMonths[$ym]['oldest'] = $d['created_at'];
        }
        if ($inScope($d['user_id']) && (int)$d['created_by'] !== $me) { $scopeDrafts++; if ($scopeOldest === null || $d['created_at'] < $scopeOldest) $scopeOldest = $d['created_at']; }
    }

    /* Personen, Feiertage, effektive Belegung im Zeitraum */
    $users = array_values(array_filter(planningUsers(true, true), function ($u) use ($today) {
        return empty($u['exit_date']) || $u['exit_date'] >= $today;
    }));
    $hol = [];
    $s = $db->prepare("SELECT holiday_date FROM holidays WHERE holiday_type='feiertag' AND holiday_date BETWEEN ? AND ?");
    $s->execute([$today, $end]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $h) $hol[$h] = true;

    $cell = []; /* "uid|date" => ['assignment', area] | ['absence', type] */
    $s = $db->prepare('SELECT user_id, plan_date, plan_area_id FROM assignments WHERE is_published=1 AND plan_date BETWEEN ? AND ?');
    $s->execute([$today, $end]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $cell[$r['user_id'] . '|' . $r['plan_date']] = ['assignment', (int)$r['plan_area_id']];
    $s = $db->prepare('SELECT user_id, absence_date, absence_type FROM absences WHERE is_published=1 AND absence_date BETWEEN ? AND ?');
    $s->execute([$today, $end]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $cell[$r['user_id'] . '|' . $r['absence_date']] = ['absence', $r['absence_type']];
    $s = $db->prepare('SELECT user_id, plan_date, action, change_type, change_value FROM plan_drafts WHERE plan_date BETWEEN ? AND ?');
    $s->execute([$today, $end]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = $r['user_id'] . '|' . $r['plan_date'];
        if ($r['action'] === 'clear') unset($cell[$k]);
        elseif ($r['change_type'] === 'assignment') $cell[$k] = ['assignment', (int)$r['change_value']];
        else $cell[$k] = ['absence', (string)$r['change_value']];
    }

    $open = 0; $count = []; /* date => area => n */
    for ($d = $today; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $wd = (int)date('N', strtotime($d));
        foreach ($users as $u) {
            if (!empty($u['entry_date']) && $d < $u['entry_date']) continue;
            if (!empty($u['exit_date']) && $d > $u['exit_date']) continue;
            $c = $cell[$u['id'] . '|' . $d] ?? null;
            if ($c && $c[0] === 'assignment') { $count[$d][$c[1]] = ($count[$d][$c[1]] ?? 0) + 1; continue; }
            if (!$c && $wd <= 5 && !isset($hol[$d])) $open++;
        }
    }

    /* Unterbesetzung gegenüber der Soll-Vorgabe */
    $targets = pqTargetsAll($db); $pam = planAreaMap(); $under = []; $underCount = 0;
    for ($d = $today; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (isset($hol[$d])) continue;
        $wd = (int)date('N', strtotime($d));
        foreach ($targets as $aid => $byWd) {
            $need = $byWd[$wd] ?? 0; if (!$need || !isset($pam[$aid])) continue;
            $have = $count[$d][$aid] ?? 0;
            if ($have < $need) {
                $underCount++;
                $under[] = ['date' => $d, 'code' => $pam[$aid]['short_code'], 'color' => $pam[$aid]['color'], 'fg' => $pam[$aid]['fg_color'], 'have' => $have, 'need' => $need];
            }
        }
    }

    return [
        'pending' => $pending, 'days' => $days, 'open_days' => $open,
        'my_drafts' => $myDrafts, 'my_oldest' => $myOldest, 'my_months' => (ksort($myMonths) ? array_values($myMonths) : []),
        'scope_drafts' => $scopeDrafts, 'scope_oldest' => $scopeOldest,
        'has_targets' => (bool)$targets, 'under_count' => $underCount, 'under' => array_slice($under, 0, 6),
    ];
}
