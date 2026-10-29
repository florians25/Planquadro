<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../../app/lib/PlanCells.php';
require_once __DIR__.'/../../app/lib/Events.php';
header('Content-Type: application/json; charset=utf-8');
apiRateLimit(60);
if(!canEdit()){http_response_code(403);echo json_encode(['error'=>'Keine Berechtigung']);exit;}
$input=secureJsonInput();
if(!$input){http_response_code(400);echo json_encode(['error'=>'Ungültige Daten']);exit;}
$db=getDB();
$by=cleanInt($_SESSION['user_id']);

/**
 * Speichert öffentliche und/oder interne Bemerkung als Entwurf.
 *
 * Wichtig (Bugfix): Öffentliche und interne Bemerkung sind ZWEI unabhängige
 * Felder und dürfen GLEICHZEITIG existieren. Wird nur eines der beiden Felder
 * bearbeitet, bleibt das andere unverändert erhalten – egal ob der bisherige
 * Wert in einem bestehenden Entwurf oder in einem bereits veröffentlichten
 * Eintrag (assignments/absences) steht.
 *
 * @param bool $editPublic   Öffentliche Bemerkung wird in diesem Aufruf gesetzt
 * @param bool $editInternal Interne Bemerkung wird in diesem Aufruf gesetzt
 */
function upsertRemarkDraft($db, $uid, $date, $remark, $internalRemark, $by, $editPublic=true, $editInternal=true) {
    $yr=(int)date('Y',strtotime($date));$mo=(int)date('m',strtotime($date));

    /* 1) Aktuellen Zustand ermitteln: Entwurf hat Vorrang, sonst veröffentlichter Eintrag. */
    $base = null;            // 'draft' | 'assignment' | 'absence' | null
    $curPublic = null; $curInternal = null;
    $changeType = null; $changeValue = null; $isInternalEntry = 0;

    $s=$db->prepare('SELECT remarks, internal_remark FROM plan_drafts WHERE user_id=? AND plan_date=?');
    $s->execute([$uid,$date]);
    if($d=$s->fetch()){
        $base='draft';
        $curPublic=$d['remarks'] ?? null;
        $curInternal=$d['internal_remark'] ?? null;
    } else {
        $sa=$db->prepare('SELECT plan_area_id, remarks, internal_remark, is_internal FROM assignments WHERE user_id=? AND plan_date=? AND is_published=1');
        $sa->execute([$uid,$date]);
        if($a=$sa->fetch()){
            $base='assignment'; $changeType='assignment'; $changeValue=$a['plan_area_id'];
            $isInternalEntry=(int)($a['is_internal']??0);
            $curPublic=$a['remarks'] ?? null;
            $curInternal=$a['internal_remark'] ?? null;
        } else {
            $sb=$db->prepare('SELECT absence_type, remarks, internal_remark, is_internal FROM absences WHERE user_id=? AND absence_date=? AND is_published=1');
            $sb->execute([$uid,$date]);
            if($b=$sb->fetch()){
                $base=($b['absence_type']==='gesperrt')?'locked':'absence';
                $changeType=($b['absence_type']==='gesperrt')?'locked':'absence';
                $changeValue=$b['absence_type'];
                $isInternalEntry=(int)($b['is_internal']??0);
                $curPublic=$b['remarks'] ?? null;
                $curInternal=$b['internal_remark'] ?? null;
            }
        }
    }

    /* Nichts vorhanden, worauf sich eine Bemerkung beziehen könnte -> nichts tun. */
    if($base===null) return;

    /* 2) Neue Werte berechnen – das jeweils NICHT bearbeitete Feld bleibt erhalten. */
    $newPublic   = $editPublic   ? ($remark!==''         ? $remark         : null) : $curPublic;
    $newInternal = $editInternal ? ($internalRemark!=='' ? $internalRemark : null) : $curInternal;

    /* 3) Schreiben. */
    if($base==='draft'){
        $db->prepare('UPDATE plan_drafts SET remarks=?, internal_remark=?, created_by=?, created_at=NOW() WHERE user_id=? AND plan_date=?')
           ->execute([$newPublic, $newInternal, $by, $uid, $date]);
        return;
    }
    /* Aus veröffentlichtem Eintrag einen Entwurf erzeugen und beide Bemerkungen mitnehmen. */
    $db->prepare('INSERT INTO plan_drafts(user_id,plan_date,year,month,action,change_type,change_value,remarks,internal_remark,is_internal,created_by) VALUES(?,?,?,?,\'set\',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE remarks=VALUES(remarks),internal_remark=VALUES(internal_remark),created_by=VALUES(created_by),created_at=NOW()')
       ->execute([$uid,$date,$yr,$mo,$changeType,$changeValue,$newPublic,$newInternal,$isInternalEntry,$by]);
}

/**
 * Bemerkung transaktional speichern: Zelle sperren, optional Revision prüfen
 * (Optimistic Concurrency wie in save_bulk), protokollieren.
 * @return array{ok:bool, rev?:string, conflict?:array}
 */
function saveRemarkLocked($db, $uid, $date, $remark, $intRemark, $by, $editPublic, $editInternal, $rev = null) {
    return opsTransaction($db, function ($db) use ($uid, $date, $remark, $intRemark, $by, $editPublic, $editInternal, $rev) {
        $k = planCellKey($uid, $date);
        $st = planCellLoad($db, [[$uid, $date]], true)[$k];
        if ($rev !== null && !hash_equals(planCellRev($db, $st), (string)$rev)
            && !($st['draft'] && (int)$st['draft']['created_by'] === (int)$by)) {
            $db->rollBack();
            return ['ok' => false, 'conflict' => ['user_id' => $uid, 'iso' => $date, 'rev' => planCellRev($db, $st)]];
        }
        upsertRemarkDraft($db, $uid, $date, $remark, $intRemark, $by, $editPublic, $editInternal);
        $after = planCellLoad($db, [[$uid, $date]])[$k];
        if (planCellRev($db, $after) === planCellRev($db, $st)) return ['ok' => true, 'rev' => planCellRev($db, $st)];
        planChangeLog($db, $uid, $date, 'remark', planCellSnapshot($st), planCellSnapshot($after) + ['public' => $editPublic ? 1 : 0, 'internal' => $editInternal ? 1 : 0], (int)$by);
        pqEventEmit($db, 'cells_changed', [[(int)$uid, $date]], false, (int)$by, ['internal' => $editInternal && !$editPublic]);
        return ['ok' => true, 'rev' => planCellRev($db, $after)];
    });
}

/* Bulk mode */
$items = $input['items'] ?? null;
if ($items) {
    $saved = 0;
    foreach ($items as $item) {
        $uid=(int)($item['user_id']??0);$date=cleanDate($item['date']??'');
        if(!$uid||!$date) continue;
        if(!canEditUser($uid)) continue; /* Vorgesetzten-Scope */
        $editPublic   = array_key_exists('remarks', $item);
        $editInternal = array_key_exists('internal_remark', $item);
        if(!$editPublic && !$editInternal) continue;
        $remark   = trim((string)($item['remarks']??''));
        $intRemark= trim((string)($item['internal_remark']??''));
        $r = saveRemarkLocked($db, $uid, $date, $remark, $intRemark, $by, $editPublic, $editInternal, $item['rev'] ?? null);
        if ($r['ok']) $saved++;
    }
    echo json_encode(['success'=>true,'msg'=>$saved.' Bemerkung(en) gespeichert']);
    exit;
}

/* Single mode */
$uid=(int)($input['user_id']??0);$date=cleanDate($input['date']??'');
if(!$uid||!$date){echo json_encode(['success'=>false,'error'=>'User/Datum fehlt']);exit;}
if(!canEditUser($uid)){http_response_code(403);echo json_encode(['success'=>false,'error'=>'Keine Berechtigung für diesen Mitarbeiter']);exit;}

$editPublic   = array_key_exists('remarks', $input);
$editInternal = array_key_exists('internal_remark', $input);
if(!$editPublic && !$editInternal){echo json_encode(['success'=>false,'error'=>'Keine Bemerkung übergeben']);exit;}

$remark   = trim((string)($input['remarks']??''));
$intRemark= trim((string)($input['internal_remark']??''));
$res = saveRemarkLocked($db, $uid, $date, $remark, $intRemark, $by, $editPublic, $editInternal, $input['rev'] ?? null);
if (!$res['ok']) {
    http_response_code(409);
    echo json_encode(['success'=>false,'error'=>'conflict','msg'=>'Die Zelle wurde inzwischen von jemand anderem geändert. Bitte neu laden.','conflicts'=>[$res['conflict']]]);
    exit;
}

if($editInternal && !$editPublic){
    $msg = $intRemark ? 'Interne Bemerkung gespeichert' : 'Interne Bemerkung entfernt';
} else {
    $msg = $remark ? 'Bemerkung gespeichert' : 'Bemerkung entfernt';
}
echo json_encode(['success'=>true,'msg'=>$msg,'by'=>$by,'at'=>date('d.m.Y H:i'),'rev'=>$res['rev']]);
