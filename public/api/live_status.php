<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../../app/lib/PlanCells.php';
header('Content-Type: application/json; charset=utf-8');
if(!isLoggedIn()){http_response_code(403);echo json_encode(['error'=>'Nicht angemeldet']);exit;}
$input=json_decode(file_get_contents('php://input'),true);
$action=$input['action']??$_GET['action']??'';
$uid=$_SESSION['user_id'];
$db=getDB();

/* Helper: compute data hash including drafts */
function computeDataHash($db, $y, $m) {
    $parts = [];
    /* Published data timestamps */
    try {
        $s=$db->prepare('SELECT MAX(updated_at) v FROM assignments WHERE year=? AND month=?');$s->execute([$y,$m]);
        $parts[] = $s->fetch()['v'] ?? '';
    } catch(Exception $e) { $parts[] = ''; }
    try {
        $start=sprintf('%04d-%02d-01',$y,$m); $end=date('Y-m-t',strtotime($start));
        $s=$db->prepare('SELECT MAX(updated_at) v FROM absences WHERE absence_date BETWEEN ? AND ?');$s->execute([$start,$end]);
        $parts[] = $s->fetch()['v'] ?? '';
    } catch(Exception $e) { $parts[] = ''; }
    /* Draft timestamps + count (this is the key fix!) */
    try {
        $s=$db->prepare('SELECT COUNT(*) c, MAX(created_at) v FROM plan_drafts WHERE year=? AND month=?');$s->execute([$y,$m]);
        $r=$s->fetch(); $parts[] = ($r['c']??'0').'_'.($r['v']??'');
    } catch(Exception $e) { $parts[] = '0_'; }
    return md5(implode('|', $parts));
}

if($action==='ping'){
    $page=$input['page']??'';$y=(int)($input['year']??0);$m=(int)($input['month']??0);
    if($page&&$y&&$m) pingEditing($uid,$page,$y,$m);
    $editors=activeEditors($page,$y,$m,$uid);

    /* Data hash including drafts */
    $hash = ($y&&$m) ? computeDataHash($db, $y, $m) : '';

    /* Draft count for badge – split by mine vs others, exclude is_internal */
    $draftCount = 0; $myDraftCount = 0; $otherDraftCount = 0;
    try {
        $s=$db->prepare('SELECT COUNT(*) c FROM plan_drafts WHERE year=? AND month=? AND is_internal=0');$s->execute([$y,$m]);$draftCount=(int)$s->fetch()['c'];
        $s2=$db->prepare('SELECT COUNT(*) c FROM plan_drafts WHERE year=? AND month=? AND is_internal=0 AND created_by=?');$s2->execute([$y,$m,$uid]);$myDraftCount=(int)$s2->fetch()['c'];
        $otherDraftCount = $draftCount - $myDraftCount;
    } catch(Exception $e) {}

    $allowConcurrent=true;
    try{$s=$db->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$s->execute(['allow_concurrent_edit']);$r=$s->fetch();if($r)$allowConcurrent=$r['setting_value']!=='0';}catch(Exception $e){}

    $firstEditor=0;
    if(!$allowConcurrent && !empty($editors)){
        try{$fs=$db->prepare('SELECT user_id FROM editing_status WHERE page=? AND year=? AND month=? AND last_ping>=DATE_SUB(NOW(),INTERVAL 30 SECOND) ORDER BY id ASC LIMIT 1');$fs->execute([$page,$y,$m]);$fr=$fs->fetch();if($fr)$firstEditor=(int)$fr['user_id'];}catch(Exception $e){}
    }

    $editorsDetail=[];
    try{
        /* Check if target_uid column exists */
        $cols=$db->query('SHOW COLUMNS FROM editing_status LIKE \'target_uid\'')->fetchAll();
        if(count($cols)>0){
            $eds=$db->prepare('SELECT es.user_id, es.target_uid, u.first_name, u.last_name FROM editing_status es JOIN users u ON u.id=es.user_id WHERE es.page=? AND es.year=? AND es.month=? AND es.last_ping>=DATE_SUB(NOW(),INTERVAL 30 SECOND) AND es.user_id!=?');
        } else {
            $eds=$db->prepare('SELECT es.user_id, NULL as target_uid, u.first_name, u.last_name FROM editing_status es JOIN users u ON u.id=es.user_id WHERE es.page=? AND es.year=? AND es.month=? AND es.last_ping>=DATE_SUB(NOW(),INTERVAL 30 SECOND) AND es.user_id!=?');
        }
        $eds->execute([$page,$y,$m,$uid]);
        $editorsDetail=$eds->fetchAll(PDO::FETCH_ASSOC);
    }catch(Exception $e){$editorsDetail=[];}

    echo json_encode([
        'success'=>true,
        'editors'=>$editors,
        'editors_detail'=>$editorsDetail,
        'data_hash'=>$hash,
        'draft_count'=>$draftCount,
        'my_drafts'=>$myDraftCount,
        'other_drafts'=>$otherDraftCount,
        'allow_concurrent'=>$allowConcurrent,
        'first_editor'=>$firstEditor?:$uid
    ]);

} elseif($action==='cells'){
    $y=(int)($input['year']??0);$m=(int)($input['month']??0);
    if(!$y||!$m){echo json_encode(['success'=>false]);exit;}
    $asgn=assignments($y,$m,false);$abs=absences($y,$m,false);$pam=planAreaMap();
    $canInt=hasPerm('plan_view_internal');
    /* Cache user names for remark_by */
    $uNames=[];try{$us=$db->query('SELECT id,first_name,last_name FROM users');foreach($us->fetchAll() as $uu)$uNames[$uu['id']]=$uu['first_name'].' '.$uu['last_name'];}catch(Exception $e){}
    $cells=[];
    foreach($asgn as $uid2=>$dates){foreach($dates as $d=>$a){
        $k=$uid2.'_'.$d;$p=isset($pam[$a['plan_area_id']])?$pam[$a['plan_area_id']]:null;
        if(!$p)continue;
        $isInt=!empty($a['is_internal']);
        if($isInt && !$canInt) continue;
        $c=['type'=>'assignment','value'=>$a['plan_area_id'],'label'=>$p['short_code'],'bg'=>$p['color'],'fg'=>$p['fg_color'],'remark'=>$a['remarks']??'','draft'=>!empty($a['is_draft']),'internal'=>$isInt?1:0];
        if(!empty($a['internal_remark'])&&$canInt) $c['int_remark']=$a['internal_remark'];
        if(!empty($a['remark_by'])&&isset($uNames[(int)$a['remark_by']])) $c['remark_by_name']=$uNames[(int)$a['remark_by']];
        if(!empty($a['remark_at'])) $c['remark_at']=date('d.m. H:i',strtotime($a['remark_at']));
        $cells[$k]=$c;
    }}
    foreach($abs as $uid2=>$dates){foreach($dates as $d=>$a){
        $k=$uid2.'_'.$d;
        $isInt=!empty($a['is_internal']);
        if($isInt && !$canInt) continue;
        $abt=$a['absence_type'];
        if(!canSeeAbsenceDetails($uid2) && isConfidentialAbsence($abt)){
            /* Vertraulich: nur "abwesend", ohne Art/Bemerkung */
            $cells[$k]=['type'=>'absence','value'=>'','label'=>'A','bg'=>'#ef4444','fg'=>'#fff','remark'=>'','draft'=>!empty($a['is_draft']),'internal'=>0,'confidential'=>1];
            continue;
        }
        $c=['type'=>'absence','value'=>$abt,'label'=>absenceLabel($abt),'bg'=>absenceColor($abt),'fg'=>absenceFg($abt),'remark'=>$a['remarks']??'','draft'=>!empty($a['is_draft']),'internal'=>$isInt?1:0];
        if(!empty($a['internal_remark'])&&$canInt) $c['int_remark']=$a['internal_remark'];
        if(!empty($a['remark_by'])&&isset($uNames[(int)$a['remark_by']])) $c['remark_by_name']=$uNames[(int)$a['remark_by']];
        if(!empty($a['remark_at'])) $c['remark_at']=date('d.m. H:i',strtotime($a['remark_at']));
        $cells[$k]=$c;
    }}
    $hash = computeDataHash($db, $y, $m);
    /* Load draft map for per-cell draft author info */
    $draftInfo=[];
    try{
        $ds=$db->prepare('SELECT pd.user_id,pd.plan_date,pd.created_by,u.first_name,u.last_name FROM plan_drafts pd LEFT JOIN users u ON u.id=pd.created_by WHERE pd.year=? AND pd.month=? AND pd.is_internal=0');
        $ds->execute([$y,$m]);
        foreach($ds->fetchAll(PDO::FETCH_ASSOC) as $dr){
            $dk=(int)$dr['user_id'].'_'.$dr['plan_date'];
            $draftInfo[$dk]=['by_id'=>(int)$dr['created_by'],'by'=>trim(($dr['first_name']??'').' '.($dr['last_name']??''))];
        }
    }catch(Exception $e){}
    /* Revisionen je Zelle für die Versionsprüfung beim Speichern (fehlend = leer) */
    $revs = [];
    try { $revs = planMonthRevs($db, $y, $m); } catch (Exception $e) {}
    echo json_encode(['success'=>true,'cells'=>$cells,'data_hash'=>$hash,'drafts'=>$draftInfo,'my_uid'=>$uid,'revs'=>$revs]);

} elseif($action==='leave'){
    $page=$input['page']??'';$y=(int)($input['year']??0);$m=(int)($input['month']??0);
    $db->prepare('DELETE FROM editing_status WHERE user_id=? AND page=? AND year=? AND month=?')->execute([$uid,$page,$y,$m]);
    echo json_encode(['success'=>true]);

} elseif($action==='lock_row'){
    $targetUid = (int)($input['target_uid'] ?? 0);
    if (!$targetUid) { echo json_encode(['success'=>false]); exit; }
    try {
        $db->prepare('INSERT INTO editing_status(user_id,page,year,month,target_uid) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE last_ping=NOW(),target_uid=?')
                ->execute([$_SESSION['user_id'],'monatsplanung',(int)($input['year']??0),(int)($input['month']??0),$targetUid,$targetUid]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e) {
        try {
            $db->prepare('INSERT INTO editing_status(user_id,page,year,month) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_ping=NOW()')
                ->execute([$_SESSION['user_id'],'monatsplanung',(int)($input['year']??0),(int)($input['month']??0)]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e2) { echo json_encode(['success'=>false]); }
    }

} else {
    echo json_encode(['error'=>'Unbekannte Aktion']);
}
