<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../../app/lib/PlanCells.php';
require_once __DIR__.'/../../app/lib/Push.php';
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Nicht angemeldet']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$db = getDB();
$cu = currentUser();

/* Helper: Ist der aktuelle User Vorgesetzter von $uid? (oder Manager/Admin) */
function isSupervisorOf($uid) {
    if (canViewAllEmployees()) return true;
    $set = visibleUserIds();
    if ($set === null) return true;
    return in_array((int)$uid, array_map('intval', $set), true);
}

/* SQL-Bedingung für Vorgesetzten-Filter (inkl. transitiv unterstellter Teams) */
function supervisorCondition() {
    if (canViewAllEmployees()) return ['', []];
    $set = visibleUserIds();
    if ($set === null) return ['', []];
    if (empty($set)) return [' AND 1=0', []];
    $in = implode(',', array_map('intval', $set));
    return [" AND cr.user_id IN ($in)", []];
}

switch ($action) {

/* == Offene Anträge zählen (gefiltert nach Vorgesetztem) == */
case 'pending_count':
    try {
        list($cond, $params) = supervisorCondition();
        $s = $db->prepare("SELECT COUNT(*) c FROM change_requests cr WHERE status='pending'".$cond);
        $s->execute($params);
        echo json_encode(['success'=>true, 'count'=>(int)$s->fetch()['c']]);
    } catch(Exception $e) { echo json_encode(['success'=>true, 'count'=>0]); }
    exit;

/* == Gesammelte Änderungen einreichen == */
case 'submit_batch':
    $items = $input['items'] ?? [];
    if (empty($items)) { echo json_encode(['success'=>false, 'error'=>'Keine Daten']); exit; }

    $krankTypes = ['krank_halb','krank_ganz','krank_attest'];
    $saved = 0; $skipped = 0;

    try { $db->beginTransaction();
    foreach ($items as $item) {
        $uid   = (int)($item['user_id'] ?? 0);
        $date  = $item['date'] ?? '';
        $type  = $item['type'] ?? '';
        $value = $item['value'] ?? '';
        if (!$uid || !opsValidDate($date) || !in_array($type, ['assignment','absence'], true) || !$value) continue;
        if (!canEdit() && $uid !== (int)$cu['id']) continue;
        if (canEdit() && $uid !== (int)$cu['id'] && !canEditUser($uid)) continue; /* Team-Scope */
        if (!canEdit() && $type === 'absence' && in_array($value, $krankTypes)) continue;
        if (!canEdit() && $value === 'gesperrt') continue;

        $chk = $db->prepare("SELECT id FROM change_requests WHERE user_id=? AND plan_date=? AND status='pending'");
        $chk->execute([$uid, $date]);
        if ($chk->fetch()) { $skipped++; continue; }

        $oldType = 'empty'; $oldValue = null;
        $s1 = $db->prepare('SELECT plan_area_id FROM assignments WHERE user_id=? AND plan_date=? AND is_published=1');
        $s1->execute([$uid, $date]); $r1 = $s1->fetch();
        if ($r1) { $oldType = 'assignment'; $oldValue = $r1['plan_area_id']; }
        else {
            $s2 = $db->prepare('SELECT absence_type FROM absences WHERE user_id=? AND absence_date=? AND is_published=1');
            $s2->execute([$uid, $date]); $r2 = $s2->fetch();
            if ($r2) { $oldType = 'absence'; $oldValue = $r2['absence_type']; }
        }

        $areaId = ($type === 'assignment') ? (int)$value : null;
        $absType = ($type === 'absence') ? $value : null;
        $remarks = trim($item['remarks'] ?? '');

        $db->prepare('INSERT INTO change_requests (user_id, plan_date, request_type, plan_area_id, absence_type, old_type, old_value, remarks, status, requested_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$uid, $date, $type, $areaId, $absType, $oldType, $oldValue, $remarks ?: null, 'pending', $cu['id']]);
        $saved++;
    }
    $db->commit();
    $msg = $saved.' Änderungsantrag/-anträge eingereicht';
    if ($skipped) $msg .= ' ('.$skipped.' übersprungen – bereits offen)';
    echo json_encode(['success'=>true, 'msg'=>$msg, 'count'=>$saved]);
    } catch(Exception $e) { $db->rollBack(); error_log('change_requests: '.$e->getMessage()); echo json_encode(['success'=>false,'error'=>'Datenbankfehler']); }
    exit;

/* == Anträge auflisten (gruppiert, gefiltert) == */
case 'list':
    $status = $input['status'] ?? 'pending';
    list($cond, $params) = supervisorCondition();
    try {
        $sql = "SELECT cr.*, u.first_name, u.last_name, u.role, u.os_level, u.snow_id, u.primary_area_id,
                       u.training_profession, u.vacation_days,
                       rb.first_name AS req_first, rb.last_name AS req_last,
                       ab.first_name AS app_first, ab.last_name AS app_last,
                       pa.short_code AS area_code, pa.name AS area_name, pa.color AS area_color, pa.fg_color AS area_fg
                FROM change_requests cr
                JOIN users u ON u.id = cr.user_id
                JOIN users rb ON rb.id = cr.requested_by
                LEFT JOIN users ab ON ab.id = cr.approved_by
                LEFT JOIN plan_areas pa ON pa.id = cr.plan_area_id
                WHERE cr.status = ?".$cond."
                ORDER BY u.last_name, u.first_name, cr.plan_date ASC";
        array_unshift($params, $status);
        $s = $db->prepare($sql); $s->execute($params);
        echo json_encode(['success'=>true, 'requests'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e) { error_log('change_requests: '.$e->getMessage()); echo json_encode(['success'=>false, 'error'=>'Datenbankfehler']); }
    exit;

/* == Sammelgenehmigung == */
case 'approve_batch':
    /* Genehmigen erfordert das dafür vorgesehene Recht (Befund S-10) */
    if (!hasPerm('approve_requests')) { echo json_encode(['success'=>false,'error'=>'Keine Berechtigung']); exit; }
    $ids = $input['ids'] ?? [];
    if (empty($ids)) { echo json_encode(['success'=>false,'error'=>'Keine IDs']); exit; }
    try { $db->beginTransaction(); $done=0; $changedCells=[];
    foreach ($ids as $rid) {
        $rid = (int)$rid;
        $s = $db->prepare("SELECT * FROM change_requests WHERE id=? AND status='pending'");
        $s->execute([$rid]); $req = $s->fetch(PDO::FETCH_ASSOC);
        if (!$req || !isSupervisorOf($req['user_id'])) continue;
        $uid = (int)$req['user_id']; $date = $req['plan_date'];
        $yr = (int)date('Y', strtotime($date)); $mo = (int)date('m', strtotime($date));
        $before = planCellLoad($db, [[$uid, $date]], true)[planCellKey($uid, $date)];
        $db->prepare('DELETE FROM assignments WHERE user_id=? AND plan_date=?')->execute([$uid, $date]);
        $db->prepare('DELETE FROM absences WHERE user_id=? AND absence_date=?')->execute([$uid, $date]);
        if ($req['request_type'] === 'assignment' && $req['plan_area_id']) {
            $db->prepare('INSERT INTO assignments (user_id, plan_date, plan_area_id, year, month, is_published, created_by, updated_at) VALUES (?,?,?,?,?,1,?,NOW())')
               ->execute([$uid, $date, $req['plan_area_id'], $yr, $mo, $cu['id']]);
        } elseif ($req['request_type'] === 'absence' && $req['absence_type']) {
            $db->prepare('INSERT INTO absences (user_id, absence_date, absence_type, is_published, created_by, updated_at) VALUES (?,?,?,1,?,NOW())')
               ->execute([$uid, $date, $req['absence_type'], $cu['id']]);
        }
        $db->prepare("UPDATE change_requests SET status='approved', approved_by=?, updated_at=NOW() WHERE id=?")->execute([$cu['id'], $rid]);
        planChangeLog($db, $uid, $date, 'approve', planCellSnapshot($before),
            ['pub' => ['type' => $req['request_type'], 'value' => $req['request_type'] === 'assignment' ? (int)$req['plan_area_id'] : $req['absence_type']], 'request_id' => $rid], (int)$cu['id']);
        $dateF = date('d.m.Y', strtotime($date));
        addNotification($uid, 'Antrag genehmigt', 'Dein Änderungsantrag für den '.$dateF.' wurde genehmigt.', 'mein_plan');
        $changedCells[$uid][] = $date;
        $done++;
    }
    $db->commit();
    /* Push an die App (die In-App-Meldung "Antrag genehmigt" gibt es bereits) */
    try {
        $q = $db->prepare("INSERT INTO push_queue(user_id,title,body,data) SELECT ?, 'Antrag genehmigt', ?, ? FROM DUAL WHERE EXISTS (SELECT 1 FROM push_devices WHERE user_id=?)");
        foreach ($changedCells as $pu => $pd) $q->execute([$pu, count($pd).' Antrag/Anträge genehmigt.', json_encode(['type'=>'plan_changed','dates'=>implode(',',$pd)]), $pu]);
    } catch (Throwable $e) { error_log('push: '.$e->getMessage()); }
    echo json_encode(['success'=>true, 'msg'=>$done.' Antrag/Anträge genehmigt']);
    } catch(Exception $e) { $db->rollBack(); error_log('change_requests: '.$e->getMessage()); echo json_encode(['success'=>false,'error'=>'Datenbankfehler']); }
    exit;

/* == Sammelablehnung == */
case 'reject_batch':
    if (!hasPerm('approve_requests')) { echo json_encode(['success'=>false,'error'=>'Keine Berechtigung']); exit; }
    $ids = $input['ids'] ?? []; $reason = trim($input['reason'] ?? '');
    if (empty($ids)) { echo json_encode(['success'=>false,'error'=>'Keine IDs']); exit; }
    try { $done=0;
    foreach ($ids as $rid) {
        $rid = (int)$rid;
        $s = $db->prepare("SELECT user_id, plan_date FROM change_requests WHERE id=? AND status='pending'");
        $s->execute([$rid]); $req = $s->fetch(PDO::FETCH_ASSOC);
        if (!$req || !isSupervisorOf($req['user_id'])) continue;
        $db->prepare("UPDATE change_requests SET status='rejected', rejection_reason=?, approved_by=?, updated_at=NOW() WHERE id=? AND status='pending'")
           ->execute([$reason ?: null, $cu['id'], $rid]);
        $dateF = date('d.m.Y', strtotime($req['plan_date']));
        $msg = 'Dein Änderungsantrag für den '.$dateF.' wurde abgelehnt.';
        if ($reason) $msg .= ' Begründung: '.$reason;
        addNotification($req['user_id'], 'Antrag abgelehnt', $msg, 'mein_plan');
        $done++;
    }
    echo json_encode(['success'=>true, 'msg'=>$done.' Antrag/Anträge abgelehnt']);
    } catch(Exception $e) { error_log('change_requests: '.$e->getMessage()); echo json_encode(['success'=>false,'error'=>'Datenbankfehler']); }
    exit;

/* == Wochenkontext == */
case 'week_context':
    if (!canEdit()) { echo json_encode(['success'=>false,'error'=>'Keine Berechtigung']); exit; }
    $uid = (int)($input['user_id'] ?? 0); $date = $input['date'] ?? '';
    if (!$uid || !opsValidDate($date)) { echo json_encode(['success'=>false,'error'=>'Fehlende Daten']); exit; }
    if (!canEditUser($uid)) { echo json_encode(['success'=>false,'error'=>'Keine Berechtigung für diesen Mitarbeiter']); exit; }
    $ts = strtotime($date); $dow = (int)date('N', $ts);
    $monTs = strtotime('-'.($dow-1).' days', $ts);
    $weekDays = []; for ($i=0;$i<7;$i++) $weekDays[] = date('Y-m-d', strtotime("+{$i} days", $monTs));
    $us = $db->prepare('SELECT id,first_name,last_name,role,os_level,snow_id,primary_area_id,vacation_days,training_profession FROM users WHERE id=?');
    $us->execute([$uid]); $user = $us->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo json_encode(['success'=>false,'error'=>'Nicht gefunden']); exit; }
    $cells = []; $pam = planAreaMap();
    foreach ($weekDays as $wd) {
        $cell = ['date'=>$wd, 'type'=>'empty', 'label'=>'', 'bg'=>'', 'fg'=>'', 'we'=>((int)date('N',strtotime($wd))>=6)?1:0];
        $s1 = $db->prepare('SELECT * FROM assignments WHERE user_id=? AND plan_date=? AND is_published=1');
        $s1->execute([$uid,$wd]); $r1=$s1->fetch(PDO::FETCH_ASSOC);
        if ($r1 && isset($pam[$r1['plan_area_id']])) {
            $pa=$pam[$r1['plan_area_id']];
            $cell=array_merge($cell,['type'=>'assignment','label'=>$pa['short_code'],'bg'=>$pa['color'],'fg'=>$pa['fg_color'],'name'=>$pa['name']]);
        } else {
            $s2=$db->prepare('SELECT * FROM absences WHERE user_id=? AND absence_date=? AND is_published=1');
            $s2->execute([$uid,$wd]); $r2=$s2->fetch(PDO::FETCH_ASSOC);
            if ($r2 && isConfidentialAbsence($r2['absence_type']) && !canSeeAbsenceDetails($uid)) {
                $cell=array_merge($cell,['type'=>'absence','label'=>'A','bg'=>'#ef4444','fg'=>'#fff','name'=>'Abwesend']);
            } elseif ($r2) $cell=array_merge($cell,['type'=>'absence','label'=>absenceLabel($r2['absence_type']),'bg'=>absenceColor($r2['absence_type']),'fg'=>absenceFg($r2['absence_type']),'name'=>absenceName($r2['absence_type'])]);
        }
        $hs=$db->prepare('SELECT * FROM holidays WHERE holiday_date=?');$hs->execute([$wd]);$hol=$hs->fetch(PDO::FETCH_ASSOC);
        if ($hol) $cell['holiday']=$hol['name'];
        $cells[]=$cell;
    }
    $pr=$db->prepare("SELECT * FROM change_requests WHERE user_id=? AND plan_date BETWEEN ? AND ? AND status='pending'");
    $pr->execute([$uid,$weekDays[0],end($weekDays)]);
    echo json_encode(['success'=>true,'user'=>$user,'cells'=>$cells,'pending_requests'=>$pr->fetchAll(PDO::FETCH_ASSOC)]);
    exit;

/* == Meine Anträge == */
case 'my_requests':
    $uid=(int)$cu['id']; $status=$input['status']??'';
    $sql="SELECT cr.*,pa.short_code AS area_code,pa.name AS area_name,pa.color AS area_color,pa.fg_color AS area_fg,ab.first_name AS app_first,ab.last_name AS app_last FROM change_requests cr LEFT JOIN plan_areas pa ON pa.id=cr.plan_area_id LEFT JOIN users ab ON ab.id=cr.approved_by WHERE cr.user_id=?";
    $params=[$uid]; if($status){$sql.=" AND cr.status=?";$params[]=$status;} $sql.=" ORDER BY cr.created_at DESC LIMIT 50";
    try{$s=$db->prepare($sql);$s->execute($params);echo json_encode(['success'=>true,'requests'=>$s->fetchAll(PDO::FETCH_ASSOC)]);}
    catch(Exception $e){error_log('change_requests: '.$e->getMessage());echo json_encode(['success'=>false,'error'=>'Datenbankfehler']);}
    exit;

/* == Pending-Map (für gestrichelte Umrandung) == */
case 'pending_map':
    $yr=(int)($input['year']??0);$mo=(int)($input['month']??0);
    if(!$yr||!$mo){echo json_encode(['success'=>true,'map'=>[]]);exit;}
    $start=sprintf('%04d-%02d-01',$yr,$mo);$end=date('Y-m-t',strtotime($start));
    list($cond,$cparams)=supervisorCondition();
    try{$s=$db->prepare("SELECT cr.user_id,cr.plan_date FROM change_requests cr WHERE cr.status='pending' AND cr.plan_date BETWEEN ? AND ?".$cond);
    $s->execute([$start,$end]);$map=[];foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$map[$r['user_id'].'_'.$r['plan_date']]=1;
    echo json_encode(['success'=>true,'map'=>$map]);}catch(Exception $e){echo json_encode(['success'=>true,'map'=>[]]);}
    exit;

/* == Dashboard-Statistik == */
case 'dashboard_stats':
    $uid=(int)$cu['id'];
    try{
        $myPending=$db->prepare("SELECT COUNT(*) c FROM change_requests WHERE user_id=? AND status='pending'");$myPending->execute([$uid]);$mp=(int)$myPending->fetch()['c'];
        $myApproved=$db->prepare("SELECT COUNT(*) c FROM change_requests WHERE user_id=? AND status='approved' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");$myApproved->execute([$uid]);$ma=(int)$myApproved->fetch()['c'];
        $myRejected=$db->prepare("SELECT COUNT(*) c FROM change_requests WHERE user_id=? AND status='rejected' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");$myRejected->execute([$uid]);$mr=(int)$myRejected->fetch()['c'];
        $toApprove=0;$totalApproved=0;$totalRejected=0;
        if(canEdit()){
            list($cond,$params)=supervisorCondition();
            $s=$db->prepare("SELECT COUNT(*) c FROM change_requests cr WHERE status='pending'".$cond);$s->execute($params);$toApprove=(int)$s->fetch()['c'];
            $s2=$db->prepare("SELECT COUNT(*) c FROM change_requests cr WHERE status='approved'".$cond);$s2->execute($params);$totalApproved=(int)$s2->fetch()['c'];
            $s3=$db->prepare("SELECT COUNT(*) c FROM change_requests cr WHERE status='rejected'".$cond);$s3->execute($params);$totalRejected=(int)$s3->fetch()['c'];
        }
        echo json_encode(['success'=>true,'my_pending'=>$mp,'my_approved_week'=>$ma,'my_rejected_week'=>$mr,'to_approve'=>$toApprove,'total_approved'=>$totalApproved,'total_rejected'=>$totalRejected]);
    }catch(Exception $e){echo json_encode(['success'=>true,'my_pending'=>0,'my_approved_week'=>0,'my_rejected_week'=>0,'to_approve'=>0,'total_approved'=>0,'total_rejected'=>0]);}
    exit;

default:
    echo json_encode(['success'=>false,'error'=>'Unbekannte Aktion']);
}
