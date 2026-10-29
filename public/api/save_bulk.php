<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/security.php';
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
$validTypes = ['assignment','absence','locked','clear'];
$validAbsences = ['krank_halb','krank_ganz','krank_attest','urlaub_halb','urlaub_ganz','urlaub_genehmigt','gleitzeit','gesperrt','offen'];

try{$db->beginTransaction();$saved=0;$vacBlocked=0;
/* Sperren (Urlaub/Krank) einmalig vorab laden statt pro Zeile (Performance) */
$vacSet=[];$sickSet=[];
if(!$forceVacation || !$forceSick){
    $lp=[];$lseen=[];
    foreach($input['changes'] as $c){$lu=cleanInt($c['user_id']??0);$ld=cleanDate($c['date']??'');if($lu&&$ld){$lk=$lu.'|'.$ld;if(!isset($lseen[$lk])){$lseen[$lk]=1;$lp[]=$lu;$lp[]=$ld;}}}
    if($lp){
        $lph=implode(',',array_fill(0,count($lp)/2,'(?,?)'));
        $ls=$db->prepare("SELECT user_id,absence_date,absence_type FROM absences WHERE is_published=1 AND absence_type IN('urlaub_genehmigt','krank_halb','krank_ganz','krank_attest') AND (user_id,absence_date) IN ($lph)");
        $ls->execute($lp);
        foreach($ls->fetchAll() as $r){$kk=$r['user_id'].'|'.$r['absence_date'];if($r['absence_type']==='urlaub_genehmigt')$vacSet[$kk]=1;else $sickSet[$kk]=1;}
    }
}
foreach($input['changes'] as $c){
    $uid=cleanInt($c['user_id']??0);$date=cleanDate($c['date']??'');$type=cleanStr($c['type']??'',20);$val=cleanStr($c['value']??'',50);
    if(!$uid||!$date)continue;
    if(!canEditUser($uid))continue; /* Vorgesetzten-Scope: nur eigenes Team */
    if(!in_array($type,$validTypes))continue;
    if($type==='absence'&&$val&&!in_array($val,$validAbsences))continue;
    if(isset($holDates[$date]))continue;
    if(isset($userDates[$uid])){
        $ud=$userDates[$uid];
        if(!empty($ud['entry']) && $date < $ud['entry']) continue;
        if(!empty($ud['exit']) && $date > $ud['exit']) continue;
    }
    $yr=(int)date('Y',strtotime($date));$mo=(int)date('m',strtotime($date));

    if(!$forceVacation && isset($vacSet[$uid.'|'.$date])) { $vacBlocked++; continue; }
    if(!$forceSick && isset($sickSet[$uid.'|'.$date])) { continue; }

    if($type==='assignment'&&$val){
        $db->prepare('INSERT INTO plan_drafts(user_id,plan_date,year,month,action,change_type,change_value,is_internal,created_by) VALUES(?,?,?,?,\'set\',\'assignment\',?,?,?) ON DUPLICATE KEY UPDATE action=\'set\',change_type=\'assignment\',change_value=VALUES(change_value),is_internal=VALUES(is_internal),remarks=NULL,internal_remark=NULL,created_by=VALUES(created_by),created_at=NOW()')
           ->execute([$uid,$date,$yr,$mo,cleanInt($val),$isInternal,$by]);
    }elseif($type==='absence'&&$val){
        $db->prepare('INSERT INTO plan_drafts(user_id,plan_date,year,month,action,change_type,change_value,is_internal,created_by) VALUES(?,?,?,?,\'set\',\'absence\',?,?,?) ON DUPLICATE KEY UPDATE action=\'set\',change_type=\'absence\',change_value=VALUES(change_value),is_internal=VALUES(is_internal),remarks=NULL,internal_remark=NULL,created_by=VALUES(created_by),created_at=NOW()')
           ->execute([$uid,$date,$yr,$mo,$val,$isInternal,$by]);
    }elseif($type==='locked'){
        $db->prepare('INSERT INTO plan_drafts(user_id,plan_date,year,month,action,change_type,change_value,is_internal,created_by) VALUES(?,?,?,?,\'set\',\'locked\',\'gesperrt\',?,?) ON DUPLICATE KEY UPDATE action=\'set\',change_type=\'locked\',change_value=\'gesperrt\',is_internal=VALUES(is_internal),remarks=NULL,internal_remark=NULL,created_by=VALUES(created_by),created_at=NOW()')
           ->execute([$uid,$date,$yr,$mo,$isInternal,$by]);
    }else{
        $db->prepare('INSERT INTO plan_drafts(user_id,plan_date,year,month,action,change_type,change_value,is_internal,created_by) VALUES(?,?,?,?,\'clear\',NULL,NULL,0,?) ON DUPLICATE KEY UPDATE action=\'clear\',change_type=NULL,change_value=NULL,is_internal=0,remarks=NULL,internal_remark=NULL,created_by=VALUES(created_by),created_at=NOW()')
           ->execute([$uid,$date,$yr,$mo,$by]);
    }
    $saved++;
}$db->commit();
$resp=['success'=>true,'count'=>$saved];
if($vacBlocked>0) $resp['vac_blocked']=$vacBlocked;
echo json_encode($resp);
}catch(Exception $e){$db->rollBack();http_response_code(500);echo json_encode(['error'=>'Datenbankfehler beim Speichern']);}
