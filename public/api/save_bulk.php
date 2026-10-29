<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../../app/lib/PlanCells.php';
header('Content-Type: application/json');
apiRateLimit(120);
if(!canEdit()){http_response_code(403);echo json_encode(['error'=>'Keine Berechtigung']);exit;}
$input=secureJsonInput();
if(!$input||!isset($input['changes'])||!is_array($input['changes'])){http_response_code(400);echo json_encode(['error'=>'Ungültige Daten']);exit;}
if(count($input['changes'])>500){http_response_code(400);echo json_encode(['error'=>'Zu viele Änderungen']);exit;}
$db=getDB(); $by=cleanInt($_SESSION['user_id']);
$holDates=[];$hs=$db->query("SELECT holiday_date FROM holidays WHERE holiday_type='feiertag'");
foreach($hs->fetchAll() as $hh)$holDates[$hh['holiday_date']]=true;
$userDates=[];$userNames=[];$uds=$db->query("SELECT id,entry_date,exit_date,first_name,last_name FROM users");
foreach($uds->fetchAll() as $ud){$userDates[$ud['id']]=['entry'=>$ud['entry_date'],'exit'=>$ud['exit_date']];$userNames[$ud['id']]=$ud['first_name'].' '.$ud['last_name'];}
$pam=planAreaMap();

/* === Conflict Check Mode (batch-optimiert für große Datenbanken) === */
if(!empty($input['check_conflicts'])){
    $vacLocked=[];$sickLocked=[];$overwrites=[];$skipped=0;
    /* 1) gültige (uid,date)-Paare sammeln */
    $pairs=[]; $seen=[];
    foreach($input['changes'] as $c){
        $uid=cleanInt($c['user_id']??0);$date=cleanDate($c['date']??'');
        if(!$uid||!$date)continue;
        if(!canEditUser($uid))continue; /* Vorgesetzten-Scope */
        if(isset($holDates[$date])){$skipped++;continue;}
        if(isset($userDates[$uid])){$ud=$userDates[$uid];if(!empty($ud['entry'])&&$date<$ud['entry']){$skipped++;continue;}if(!empty($ud['exit'])&&$date>$ud['exit']){$skipped++;continue;}}
        $k=$uid.'|'.$date; if(isset($seen[$k]))continue; $seen[$k]=true;
        $pairs[]=[$uid,$date];
    }
    if(empty($pairs)){echo json_encode(['success'=>true,'vac_locked'=>[],'sick_locked'=>[],'overwrites'=>[],'skipped'=>$skipped,'can_unlock'=>hasPerm('unlock_vacation')]);exit;}
    /* 2) Bestehende Einträge in nur 3 Abfragen laden (statt 5 pro Zeile) */
    $ph=implode(',',array_fill(0,count($pairs),'(?,?)'));
    $flat=[]; foreach($pairs as $pr){$flat[]=$pr[0];$flat[]=$pr[1];}
    $asg=[];
    $st=$db->prepare("SELECT user_id,plan_date,plan_area_id FROM assignments WHERE is_published=1 AND (user_id,plan_date) IN ($ph)");
    $st->execute($flat); foreach($st->fetchAll() as $r)$asg[$r['user_id'].'|'.$r['plan_date']]=$r;
    $absM=[];
    $st=$db->prepare("SELECT user_id,absence_date,absence_type FROM absences WHERE is_published=1 AND (user_id,absence_date) IN ($ph)");
    $st->execute($flat); foreach($st->fetchAll() as $r)$absM[$r['user_id'].'|'.$r['absence_date']]=$r;
    $drf=[];
    $st=$db->prepare("SELECT user_id,plan_date,change_type,change_value FROM plan_drafts WHERE action='set' AND (user_id,plan_date) IN ($ph)");
    $st->execute($flat); foreach($st->fetchAll() as $r)$drf[$r['user_id'].'|'.$r['plan_date']]=$r;
    /* 3) auswerten */
    foreach($pairs as $pr){
        $uid=$pr[0];$date=$pr[1];$k=$uid.'|'.$date;$disp=date('d.m.',strtotime($date));
        $ab=$absM[$k]??null;
        if($ab && $ab['absence_type']==='urlaub_genehmigt'){$vacLocked[]=['user_id'=>$uid,'iso'=>$date,'user'=>$userNames[$uid]??'#'.$uid,'date'=>$disp];continue;}
        if($ab && in_array($ab['absence_type'],['krank_halb','krank_ganz','krank_attest'],true)){$sickLocked[]=['user_id'=>$uid,'iso'=>$date,'user'=>$userNames[$uid]??'#'.$uid,'date'=>$disp,'type'=>absenceName($ab['absence_type'])];continue;}
        if(isset($asg[$k])){$aName=isset($pam[(int)$asg[$k]['plan_area_id']])?$pam[(int)$asg[$k]['plan_area_id']]['short_code']:'?';$overwrites[]=['user_id'=>$uid,'iso'=>$date,'user'=>$userNames[$uid]??'','date'=>$disp,'current'=>$aName];}
        elseif($ab){$overwrites[]=['user_id'=>$uid,'iso'=>$date,'user'=>$userNames[$uid]??'','date'=>$disp,'current'=>absenceName($ab['absence_type'])];}
        elseif(isset($drf[$k])){$exD=$drf[$k];$dLabel='Entwurf';if($exD['change_type']==='assignment'&&isset($pam[(int)$exD['change_value']]))$dLabel=$pam[(int)$exD['change_value']]['short_code'].' (Entwurf)';elseif($exD['change_type']==='absence')$dLabel=absenceName($exD['change_value']).' (Entwurf)';$overwrites[]=['user_id'=>$uid,'iso'=>$date,'user'=>$userNames[$uid]??'','date'=>$disp,'current'=>$dLabel];}
    }
    echo json_encode(['success'=>true,'vac_locked'=>$vacLocked,'sick_locked'=>$sickLocked,'overwrites'=>$overwrites,'skipped'=>$skipped,'can_unlock'=>hasPerm('unlock_vacation')]);
    exit;
}

$isInternal = !empty($input['is_internal']) ? 1 : 0;
$forceVacation = !empty($input['force_vacation']) && hasPerm('unlock_vacation');
$forceSick = !empty($input['force_sick']) && hasPerm('unlock_vacation');
$overwrite = !empty($input['overwrite_conflicts']);
$validTypes = ['assignment','absence','locked','clear'];
$validAbsences = ['krank_halb','krank_ganz','krank_attest','urlaub_halb','urlaub_ganz','urlaub_genehmigt','gleitzeit','gesperrt','offen'];

/* 1) Änderungen prüfen und je Zelle zusammenfassen (die letzte gewinnt – wie bisher) */
$work = [];
foreach ($input['changes'] as $c) {
    if (!is_array($c)) continue;
    $uid=cleanInt($c['user_id']??0);$date=cleanDate($c['date']??'');$type=cleanStr($c['type']??'',20);$val=cleanStr((string)($c['value']??''),50);
    if(!$uid||!$date)continue;
    if(!canEditUser($uid))continue; /* Vorgesetzten-Scope: nur eigenes Team */
    if(!in_array($type,$validTypes,true))continue;
    if($type==='absence'&&$val&&!in_array($val,$validAbsences,true))continue;
    if($type==='assignment'&&$val&&!isset($pam[(int)$val]))continue;
    if(isset($holDates[$date]))continue;
    if(isset($userDates[$uid])){
        $ud=$userDates[$uid];
        if(!empty($ud['entry']) && $date < $ud['entry']) continue;
        if(!empty($ud['exit']) && $date > $ud['exit']) continue;
    }
    $rev = isset($c['rev']) && is_string($c['rev']) ? $c['rev'] : null;
    $work[planCellKey($uid,$date)] = ['uid'=>$uid,'date'=>$date,'type'=>$type,'val'=>$val,'rev'=>$rev];
}

/* Lesbare Beschreibung eines Zellzustands für Konfliktmeldungen */
function sbLabel(array $st, int $uid, array $pam): string {
    [$t,$v] = planCellEffective($st);
    if ($t === null) return 'leer';
    if ($t === 'assignment') return isset($pam[(int)$v]) ? $pam[(int)$v]['short_code'] : 'Einsatz';
    if (isConfidentialAbsence($v) && !canSeeAbsenceDetails($uid)) return 'Abwesend';
    return absenceName($v);
}

try {
    $result = opsTransaction($db, function (PDO $db) use ($work, $by, $isInternal, $forceVacation, $forceSick, $overwrite, $pam, $userNames) {
        /* 2) Betroffene Zellen sperren und aktuellen Stand lesen. Die Sperre gilt
              bis zum Commit – parallele Speichervorgänge derselben Zelle warten
              oder scheitern mit Deadlock (→ automatische Wiederholung). */
        $pairs = array_map(function ($w) { return [$w['uid'], $w['date']]; }, array_values($work));
        $state = planCellLoad($db, $pairs, true);

        /* 3) Optimistic Concurrency: hat jemand die Zelle seit dem Laden geändert? */
        $conflicts = [];
        foreach ($work as $k => $w) {
            if ($w['rev'] === null) continue;              /* ältere Clients: bisheriges Verhalten */
            $st = $state[$k];
            $cur = planCellRev($db, $st);
            if (hash_equals($cur, $w['rev'])) continue;
            /* Eigener, noch nicht bestätigter Entwurf ist kein Konflikt */
            if ($st['draft'] && (int)$st['draft']['created_by'] === $by) continue;
            $who = null; $at = null;
            if ($st['draft']) { $who = (int)$st['draft']['created_by']; $at = $st['draft']['created_at']; }
            elseif ($st['asg']) { $at = $st['asg']['updated_at']; }
            elseif ($st['abs']) { $at = $st['abs']['updated_at']; }
            $conflicts[$k] = ['user_id'=>$w['uid'],'iso'=>$w['date'],'date'=>date('d.m.',strtotime($w['date'])),
                'user'=>$userNames[$w['uid']] ?? ('#'.$w['uid']),'current'=>sbLabel($st,$w['uid'],$pam),
                'by'=>$who ? ($userNames[$who] ?? '') : '','at'=>$at ? date('d.m. H:i',strtotime($at)) : '','rev'=>$cur];
        }
        if ($conflicts && !$overwrite) {
            $db->rollBack();
            return ['conflict' => array_values($conflicts)];
        }

        $saved=0;$vacBlocked=0;
        $upsert = $db->prepare("INSERT INTO plan_drafts(user_id,plan_date,year,month,action,change_type,change_value,is_internal,created_by) VALUES(?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE action=VALUES(action),change_type=VALUES(change_type),change_value=VALUES(change_value),is_internal=VALUES(is_internal),
            remarks=NULL,internal_remark=NULL,created_by=VALUES(created_by),created_at=NOW(),planning_run_id=NULL");
        $written = [];
        foreach ($work as $k => $w) {
            $st = $state[$k];
            $pubAbs = $st['abs']['absence_type'] ?? null;
            if (!$forceVacation && $pubAbs === 'urlaub_genehmigt') { $vacBlocked++; continue; }
            if (!$forceSick && in_array($pubAbs, ['krank_halb','krank_ganz','krank_attest'], true)) continue;
            $yr=(int)substr($w['date'],0,4);$mo=(int)substr($w['date'],5,2);
            if ($w['type']==='assignment' && $w['val']) {
                $row = ['set','assignment',(string)(int)$w['val'],$isInternal];
            } elseif ($w['type']==='absence' && $w['val']) {
                $row = ['set','absence',$w['val'],$isInternal];
            } elseif ($w['type']==='locked') {
                $row = ['set','locked','gesperrt',$isInternal];
            } else {
                $row = ['clear',null,null,0];
            }
            /* planning_run_id=NULL: ein manuell überschriebener Assistenten-Entwurf
               gehört nicht mehr zum Lauf und wird von „Rückgängig“ nicht gelöscht (D-05). */
            $upsert->execute([$w['uid'],$w['date'],$yr,$mo,$row[0],$row[1],$row[2],$row[3],$by]);
            $old = planCellSnapshot($st);
            $action = isset($conflicts[$k]) ? 'overwrite' : ($row[0]==='clear' ? 'draft_clear' : 'draft_set');
            planChangeLog($db, $w['uid'], $w['date'], $action, $old, ['draft'=>['action'=>$row[0],'type'=>$row[1],'value'=>$row[2],'internal'=>$row[3],'by'=>$by]], $by);
            $written[] = [$w['uid'],$w['date']];
            $saved++;
        }
        /* 4) Neue Revisionen an den Client zurückgeben */
        $revs = [];
        foreach (planCellLoad($db, $written) as $k => $st) {
            [$u,$d] = explode('|',$k); $revs[$u.'_'.$d] = planCellRev($db, $st);
        }
        return ['saved'=>$saved,'vac_blocked'=>$vacBlocked,'revs'=>$revs,'overwritten'=>count($conflicts)];
    });
    if (isset($result['conflict'])) {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>'conflict','msg'=>count($result['conflict']).' Zelle(n) wurden inzwischen von anderen geändert.','conflicts'=>$result['conflict']]);
        exit;
    }
    $resp=['success'=>true,'count'=>$result['saved'],'revs'=>$result['revs']];
    if($result['vac_blocked']>0) $resp['vac_blocked']=$result['vac_blocked'];
    if($result['overwritten']>0) $resp['overwritten']=$result['overwritten'];
    echo json_encode($resp);
} catch (Throwable $e) {
    error_log('save_bulk: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'Datenbankfehler beim Speichern']);
}
