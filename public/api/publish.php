<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
header('Content-Type: application/json');
apiRateLimit(30);
if(!canEdit()){http_response_code(403);echo json_encode(['error'=>'Keine Berechtigung']);exit;}
$input=secureJsonInput();
$db=getDB();
$by=cleanInt($_SESSION['user_id']);
$publishAll = !empty($input['publish_all']) && hasPerm('plan_publish_all');

/**
 * Apply drafts to real tables. Skips internal drafts.
 * If $onlyByUser is set, only drafts created by that user are applied.
 */
function applyDrafts($db, $year, $month, $by, $onlyByUser=null) {
    $sql = 'SELECT * FROM plan_drafts WHERE year=? AND month=? AND is_internal=0';
    $params = [$year, $month];
    if ($onlyByUser !== null) {
        $sql .= ' AND created_by=?';
        $params[] = $onlyByUser;
    }
    $s=$db->prepare($sql);$s->execute($params);
    $drafts=$s->fetchAll(PDO::FETCH_ASSOC);
    $applied=0;
    foreach($drafts as $d) {
        $uid=$d['user_id']; $date=$d['plan_date'];
        $db->prepare('DELETE FROM assignments WHERE user_id=? AND plan_date=?')->execute([$uid,$date]);
        $db->prepare('DELETE FROM absences WHERE user_id=? AND absence_date=?')->execute([$uid,$date]);
        if($d['action']==='set') {
            $hasAnyRemark = !empty($d['remarks']) || !empty($d['internal_remark']);
            if($d['change_type']==='assignment' && $d['change_value']) {
                $db->prepare('INSERT INTO assignments(user_id,plan_date,plan_area_id,year,month,is_published,remarks,internal_remark,remark_by,remark_at,created_by,updated_at) VALUES(?,?,?,?,?,1,?,?,?,?,?,NOW())')
                   ->execute([$uid,$date,(int)$d['change_value'],$year,$month,$d['remarks'],$d['internal_remark']??null,$hasAnyRemark?$d['created_by']:null,$hasAnyRemark?date('Y-m-d H:i:s'):null,$by]);
            } elseif($d['change_type']==='absence' && $d['change_value']) {
                $db->prepare('INSERT INTO absences(user_id,absence_date,absence_type,is_published,remarks,internal_remark,remark_by,remark_at,created_by,updated_at) VALUES(?,?,?,1,?,?,?,?,?,NOW())')
                   ->execute([$uid,$date,$d['change_value'],$d['remarks'],$d['internal_remark']??null,$hasAnyRemark?$d['created_by']:null,$hasAnyRemark?date('Y-m-d H:i:s'):null,$by]);
            } elseif($d['change_type']==='locked') {
                $db->prepare('INSERT INTO absences(user_id,absence_date,absence_type,is_published,remarks,internal_remark,remark_by,remark_at,created_by,updated_at) VALUES(?,?,?,1,?,?,?,?,?,NOW())')
                   ->execute([$uid,$date,'gesperrt',$d['remarks'],$d['internal_remark']??null,$hasAnyRemark?$d['created_by']:null,$hasAnyRemark?date('Y-m-d H:i:s'):null,$by]);
            }
        }
        /* Delete the applied draft (NOT internal ones - those stay) */
        $db->prepare('DELETE FROM plan_drafts WHERE id=?')->execute([$d['id']]);
        $applied++;
    }
    return $applied;
}

/* Year publish (always publish_all required) */
if (!empty($input['publish_year'])) {
    if(!hasPerm('plan_publish_year')){echo json_encode(['error'=>'Keine Berechtigung für Jahresveröffentlichung']);exit;}
    $pubYear = (int)$input['publish_year'];
    if ($pubYear < 2020 || $pubYear > 2040) { echo json_encode(['error'=>'Ungültiges Jahr']); exit; }
    try {
        $db->beginTransaction();
        $totalApplied = 0;
        for ($pm = 1; $pm <= 12; $pm++) {
            $totalApplied += applyDrafts($db, $pubYear, $pm, $by, null);
            $db->prepare('INSERT INTO publish_log(year,month,published_by,published_at) VALUES(?,?,?,NOW())')->execute([$pubYear,$pm,$by]);
        }
        $db->commit();
        echo json_encode(['success'=>true,'msg'=>'Gesamtes Jahr '.$pubYear.' veröffentlicht. '.$totalApplied.' Änderungen.']);
    } catch(Exception $e) { $db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

/* Month publish */
$year=(int)($input['year']??0);$month=(int)($input['month']??0);
if(!$year||!$month){http_response_code(400);echo json_encode(['error'=>'Jahr/Monat fehlt']);exit;}

if (!hasPerm('plan_publish_own')) {
    echo json_encode(['error'=>'Keine Berechtigung zum Veröffentlichen']);exit;
}

try{
    $db->beginTransaction();
    if ($publishAll) {
        $applied = applyDrafts($db, $year, $month, $by, null);
    } else {
        $applied = applyDrafts($db, $year, $month, $by, $by);
    }
    $db->prepare('INSERT INTO publish_log(year,month,published_by,published_at)VALUES(?,?,?,NOW())')->execute([$year,$month,$by]);
    /* Cleanup: delete stale internal drafts older than 3 months */
    try { $db->exec("DELETE FROM plan_drafts WHERE is_internal=1 AND created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)"); } catch(Exception $e) {}
    /* Cleanup: purge old editing_status */
    try { $db->exec("DELETE FROM editing_status WHERE last_ping < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"); } catch(Exception $e) {}
    $db->commit();
    $mode = $publishAll ? 'Alle Änderungen' : 'Meine Änderungen';
    /* Count remaining drafts from others */
    $otherDrafts = 0;
    try { $s=$db->prepare('SELECT COUNT(*) c FROM plan_drafts WHERE year=? AND month=? AND is_internal=0 AND created_by!=?');$s->execute([$year,$month,$by]);$otherDrafts=(int)$s->fetch()['c']; } catch(Exception $e) {}
    echo json_encode(['success'=>true,'published_at'=>date('d.m.Y H:i'),'applied'=>$applied,'mode'=>$mode,'other_drafts'=>$otherDrafts]);
}catch(Exception $e){$db->rollBack();http_response_code(500);echo json_encode(['error'=>'Datenbankfehler beim Veröffentlichen']);}
