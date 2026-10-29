<?php
/**
 * Planzellen: Revisionen, Sperren und Änderungsprotokoll
 * --------------------------------------------------------------------------
 * Eine Zelle ist (Mitarbeiter, Tag). Ihr Zustand besteht aus höchstens einem
 * veröffentlichten Einsatz (assignments) ODER einer veröffentlichten
 * Abwesenheit (absences) und höchstens einem Entwurf (plan_drafts).
 *
 * Die REVISION einer Zelle ist ein HMAC über genau diesen Zustand. Sie wird
 * nicht gespeichert, sondern bei Bedarf berechnet – dadurch ist keine
 * Schemaänderung nötig und jeder Schreibweg (auch Altcode, Assistent,
 * Abwesenheitsmodul) ändert sie automatisch. Der HMAC-Schlüssel verhindert,
 * dass aus der Revision auf vertrauliche Inhalte (z. B. die Art einer
 * Krankmeldung) zurückgerechnet werden kann.
 *
 * Optimistic Concurrency: Der Client sendet beim Speichern die Revision, die
 * er angezeigt hat. Weicht sie vom aktuellen Stand ab, hat jemand anderes die
 * Zelle inzwischen geändert → Konflikt (HTTP 409), nichts wird geschrieben.
 */

const PLAN_CELL_EMPTY_REV = '0';

/** Schlüssel für Revisionen: VIREO_APP_KEY oder einmalig erzeugt in app_settings. */
function opsAppKey(PDO $db): string
{
    static $key = null;
    if ($key !== null) return $key;
    $env = getenv('VIREO_APP_KEY');
    if ($env !== false && strlen($env) >= 32) return $key = $env;
    $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='app_key'");
    $s->execute();
    $v = $s->fetchColumn();
    if (!$v) {
        $db->prepare("INSERT IGNORE INTO app_settings(setting_key, setting_value) VALUES('app_key', ?)")
           ->execute([bin2hex(random_bytes(32))]);
        $s->execute();
        $v = $s->fetchColumn();
    }
    return $key = (string)$v;
}

function planCellKey(int $uid, string $date): string { return $uid . '|' . $date; }

/**
 * Lädt den Rohzustand der angegebenen Zellen.
 * @param array<int, array{0:int,1:string}> $pairs  [[user_id, date], …]
 * @param bool $forUpdate  Zeilen (und Lücken im Unique-Index) sperren – nur in Transaktion
 * @return array<string, array{asg:?array, abs:?array, draft:?array}>
 */
function planCellLoad(PDO $db, array $pairs, bool $forUpdate = false): array
{
    $out = [];
    foreach ($pairs as [$u, $d]) $out[planCellKey((int)$u, $d)] = ['asg' => null, 'abs' => null, 'draft' => null];
    if (!$pairs) return $out;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    foreach (array_chunk($pairs, 200) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?,?)'));
        $flat = [];
        foreach ($chunk as [$u, $d]) { $flat[] = (int)$u; $flat[] = $d; }
        $q = $db->prepare("SELECT id, user_id, plan_date, plan_area_id, remarks, internal_remark, is_internal, remark_by, remark_at, updated_at FROM assignments WHERE (user_id, plan_date) IN ($ph)$lock");
        $q->execute($flat);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[planCellKey((int)$r['user_id'], $r['plan_date'])]['asg'] = $r;
        $q = $db->prepare("SELECT id, user_id, absence_date, absence_type, remarks, internal_remark, is_internal, substitute_user_id, updated_at FROM absences WHERE (user_id, absence_date) IN ($ph)$lock");
        $q->execute($flat);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[planCellKey((int)$r['user_id'], $r['absence_date'])]['abs'] = $r;
        $q = $db->prepare("SELECT id, user_id, plan_date, action, change_type, change_value, remarks, internal_remark, is_internal, created_by, created_at, planning_run_id FROM plan_drafts WHERE (user_id, plan_date) IN ($ph)$lock");
        $q->execute($flat);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[planCellKey((int)$r['user_id'], $r['plan_date'])]['draft'] = $r;
    }
    return $out;
}

/** Revision einer Zelle aus ihrem Rohzustand. */
function planCellRev(PDO $db, array $st): string
{
    if (!$st['asg'] && !$st['abs'] && !$st['draft']) return PLAN_CELL_EMPTY_REV;
    $c = [];
    if ($a = $st['asg'])   $c['a'] = [(int)$a['id'], (int)$a['plan_area_id'], (string)$a['remarks'], (string)$a['internal_remark'], (int)$a['is_internal']];
    if ($b = $st['abs'])   $c['b'] = [(int)$b['id'], (string)$b['absence_type'], (string)$b['remarks'], (string)$b['internal_remark'], (int)$b['is_internal']];
    if ($d = $st['draft']) $c['d'] = [(int)$d['id'], (string)$d['action'], (string)$d['change_type'], (string)$d['change_value'], (string)$d['remarks'], (string)$d['internal_remark'], (int)$d['is_internal'], (int)$d['created_by']];
    return substr(hash_hmac('sha256', json_encode($c), opsAppKey($db)), 0, 16);
}

/**
 * Revisionen aller nicht-leeren Zellen eines Monats ("uid_date" => rev);
 * fehlende Schlüssel bedeuten "leer" (Revision 0).
 */
function planMonthRevs(PDO $db, int $y, int $m): array
{
    $start = sprintf('%04d-%02d-01', $y, $m);
    $end = date('Y-m-t', strtotime($start));
    $cells = [];
    $q = $db->prepare('SELECT id, user_id, plan_date, plan_area_id, remarks, internal_remark, is_internal FROM assignments WHERE plan_date BETWEEN ? AND ?');
    $q->execute([$start, $end]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cells[$r['user_id'] . '_' . $r['plan_date']]['asg'] = $r;
    $q = $db->prepare('SELECT id, user_id, absence_date, absence_type, remarks, internal_remark, is_internal FROM absences WHERE absence_date BETWEEN ? AND ?');
    $q->execute([$start, $end]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cells[$r['user_id'] . '_' . $r['absence_date']]['abs'] = $r;
    $q = $db->prepare('SELECT id, user_id, plan_date, action, change_type, change_value, remarks, internal_remark, is_internal, created_by FROM plan_drafts WHERE plan_date BETWEEN ? AND ?');
    $q->execute([$start, $end]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cells[$r['user_id'] . '_' . $r['plan_date']]['draft'] = $r;
    $out = [];
    foreach ($cells as $k => $st) $out[$k] = planCellRev($db, $st + ['asg' => null, 'abs' => null, 'draft' => null]);
    return $out;
}

/**
 * Kompakter, protokollierbarer Zustand einer Zelle (für plan_change_log).
 * Enthält bewusst keine Bemerkungstexte, nur ob es welche gibt.
 */
function planCellSnapshot(array $st): array
{
    $s = [];
    if ($st['asg']) $s['pub'] = ['type' => 'assignment', 'value' => (int)$st['asg']['plan_area_id']];
    elseif ($st['abs']) $s['pub'] = ['type' => 'absence', 'value' => $st['abs']['absence_type']];
    if ($d = $st['draft']) $s['draft'] = ['action' => $d['action'], 'type' => $d['change_type'], 'value' => $d['change_value'],
        'internal' => (int)$d['is_internal'], 'by' => (int)$d['created_by'], 'remark' => ($d['remarks'] !== null && $d['remarks'] !== '') ? 1 : 0];
    return $s;
}

/** Effektiver Inhalt (Entwurf vor veröffentlichtem Stand): [type, value] oder [null, null]. */
function planCellEffective(array $st): array
{
    if ($d = $st['draft']) {
        if ($d['action'] === 'clear') return [null, null];
        if ($d['change_type'] === 'locked') return ['absence', 'gesperrt'];
        return [$d['change_type'], $d['change_value']];
    }
    if ($st['asg']) return ['assignment', (string)$st['asg']['plan_area_id']];
    if ($st['abs']) return ['absence', $st['abs']['absence_type']];
    return [null, null];
}

/** Eintrag im Änderungsprotokoll. Fehler beim Protokollieren brechen den Vorgang ab (gleiche Transaktion). */
function planChangeLog(PDO $db, int $uid, string $date, string $action, ?array $old, ?array $new, ?int $by, string $source = 'web'): void
{
    static $rid = null;
    if ($rid === null) $rid = bin2hex(random_bytes(8));
    $db->prepare('INSERT INTO plan_change_log(user_id, plan_date, action, old_state, new_state, changed_by, source, request_id) VALUES(?,?,?,?,?,?,?,?)')
       ->execute([$uid, $date, $action, $old === null ? null : json_encode($old), $new === null ? null : json_encode($new), $by, $source, $rid]);
}

/** Führt $fn in einer Transaktion aus und wiederholt bei Deadlock/Lock-Timeout (max. 3 Versuche). */
function opsTransaction(PDO $db, callable $fn)
{
    for ($attempt = 1; ; $attempt++) {
        $db->beginTransaction();
        try {
            $res = $fn($db);
            if ($db->inTransaction()) $db->commit();
            return $res;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $code = (int)($e->errorInfo[1] ?? 0);
            if (($code === 1213 || $code === 1205) && $attempt < 3) { usleep(random_int(20000, 120000)); continue; }
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
