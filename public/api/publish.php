<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../../app/lib/PlanCells.php';
require_once __DIR__.'/../../app/lib/Push.php';
header('Content-Type: application/json');
apiRateLimit(30);
if(!canEdit()){http_response_code(403);echo json_encode(['error'=>'Keine Berechtigung']);exit;}
$input=secureJsonInput();
$db=getDB();
$by=cleanInt($_SESSION['user_id']);
$publishAll = !empty($input['publish_all']) && hasPerm('plan_publish_all');

/**
 * Wendet Entwürfe an (interne Entwürfe bleiben stehen).
 * Ist $onlyByUser gesetzt, nur Entwürfe dieses Benutzers.
 *
 * Die Entwürfe werden mit FOR UPDATE gesperrt: Speichert jemand während der
 * Veröffentlichung dieselbe Zelle, wartet sein Request, bis die Transaktion
 * abgeschlossen ist – sein neuer Entwurf geht nicht verloren (Befund D-02).
 * Jede angewendete Zelle wird in plan_change_log protokolliert.
 */
function applyDrafts($db, $year, $month, $by, $onlyByUser=null, &$changed=null) {
    if (!is_array($changed)) $changed = [];
    $sql = 'SELECT * FROM plan_drafts WHERE year=? AND month=? AND is_internal=0';
    $params = [$year, $month];
    if ($onlyByUser !== null) {
        $sql .= ' AND created_by=?';
        $params[] = $onlyByUser;
    }
    $s=$db->prepare($sql.' ORDER BY user_id, plan_date FOR UPDATE');$s->execute($params);
    $drafts=$s->fetchAll(PDO::FETCH_ASSOC);
    if (!$drafts) return 0;
    $state = planCellLoad($db, array_map(function($d){return [(int)$d['user_id'],$d['plan_date']];}, $drafts), true);
    $delA=$db->prepare('DELETE FROM assignments WHERE user_id=? AND plan_date=?');
    $delB=$db->prepare('DELETE FROM absences WHERE user_id=? AND absence_date=?');
    $insA=$db->prepare('INSERT INTO assignments(user_id,plan_date,plan_area_id,year,month,is_published,remarks,internal_remark,remark_by,remark_at,created_by,updated_at) VALUES(?,?,?,?,?,1,?,?,?,?,?,NOW())');
    $insB=$db->prepare('INSERT INTO absences(user_id,absence_date,absence_type,is_published,remarks,internal_remark,remark_by,remark_at,created_by,updated_at) VALUES(?,?,?,1,?,?,?,?,?,NOW())');
    $delD=$db->prepare('DELETE FROM plan_drafts WHERE id=?');
    $applied=0;
    foreach($drafts as $d) {
        $uid=(int)$d['user_id']; $date=$d['plan_date'];
        $old = planCellSnapshot($state[planCellKey($uid,$date)]);
        $delA->execute([$uid,$date]);
        $delB->execute([$uid,$date]);
        $new = [];
        if($d['action']==='set') {
            $hasAnyRemark = !empty($d['remarks']) || !empty($d['internal_remark']);
            $rBy = $hasAnyRemark ? $d['created_by'] : null; $rAt = $hasAnyRemark ? date('Y-m-d H:i:s') : null;
            if($d['change_type']==='assignment' && $d['change_value']) {
                $insA->execute([$uid,$date,(int)$d['change_value'],$year,$month,$d['remarks'],$d['internal_remark']??null,$rBy,$rAt,$by]);
                $new = ['pub'=>['type'=>'assignment','value'=>(int)$d['change_value']]];
            } elseif($d['change_type']==='absence' && $d['change_value']) {
                $insB->execute([$uid,$date,$d['change_value'],$d['remarks'],$d['internal_remark']??null,$rBy,$rAt,$by]);
                $new = ['pub'=>['type'=>'absence','value'=>$d['change_value']]];
            } elseif($d['change_type']==='locked') {
                $insB->execute([$uid,$date,'gesperrt',$d['remarks'],$d['internal_remark']??null,$rBy,$rAt,$by]);
                $new = ['pub'=>['type'=>'absence','value'=>'gesperrt']];
            }
        }
        $delD->execute([$d['id']]);
        planChangeLog($db, $uid, $date, 'publish', $old, $new + ['draft_by'=>(int)$d['created_by']], (int)$by);
        /* Nur echte Änderungen des veröffentlichten Stands benachrichtigen */
        if (json_encode($old['pub'] ?? null) !== json_encode($new['pub'] ?? null)) $changed[$uid][] = $date;
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
        $changed = [];
        $totalApplied = opsTransaction($db, function($db) use ($pubYear, $by, &$changed) {
            $n = 0; $changed = [];
            for ($pm = 1; $pm <= 12; $pm++) {
                $n += applyDrafts($db, $pubYear, $pm, $by, null, $changed);
                $db->prepare('INSERT INTO publish_log(year,month,published_by,published_at) VALUES(?,?,?,NOW())')->execute([$pubYear,$pm,$by]);
            }
            return $n;
        });
        try { planNotifyChanges($db, $changed, $by); } catch (Throwable $e) { error_log('notify: '.$e->getMessage()); }
        echo json_encode(['success'=>true,'msg'=>'Gesamtes Jahr '.$pubYear.' veröffentlicht. '.$totalApplied.' Änderungen.']);
    } catch(Throwable $e) { error_log('publish year: '.$e->getMessage()); http_response_code(500); echo json_encode(['error'=>'Datenbankfehler beim Veröffentlichen']); }
    exit;
}

/* Month publish */
$year=(int)($input['year']??0);$month=(int)($input['month']??0);
if(!$year||!$month){http_response_code(400);echo json_encode(['error'=>'Jahr/Monat fehlt']);exit;}

if (!hasPerm('plan_publish_own')) {
    echo json_encode(['error'=>'Keine Berechtigung zum Veröffentlichen']);exit;
}

if($month<1||$month>12||$year<2000||$year>2100){http_response_code(400);echo json_encode(['error'=>'Ungültiger Monat']);exit;}
try{
    $changed = [];
    $applied = opsTransaction($db, function($db) use ($publishAll, $year, $month, $by, &$changed) {
        $changed = [];
        $n = applyDrafts($db, $year, $month, $by, $publishAll ? null : $by, $changed);
        $db->prepare('INSERT INTO publish_log(year,month,published_by,published_at)VALUES(?,?,?,NOW())')->execute([$year,$month,$by]);
        return $n;
    });
    /* Betroffene Mitarbeiter benachrichtigen (In-App + Push-Warteschlange) */
    try { planNotifyChanges($db, $changed, $by); } catch (Throwable $e) { error_log('notify: '.$e->getMessage()); }
    /* Aufräumen außerhalb der Veröffentlichungstransaktion (keine unnötigen Sperren) */
    try { $db->exec("DELETE FROM plan_drafts WHERE is_internal=1 AND created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)"); } catch(Exception $e) {}
    try { $db->exec("DELETE FROM editing_status WHERE last_ping < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"); } catch(Exception $e) {}
    $mode = $publishAll ? 'Alle Änderungen' : 'Meine Änderungen';
    /* Count remaining drafts from others */
    $otherDrafts = 0;
    try { $s=$db->prepare('SELECT COUNT(*) c FROM plan_drafts WHERE year=? AND month=? AND is_internal=0 AND created_by!=?');$s->execute([$year,$month,$by]);$otherDrafts=(int)$s->fetch()['c']; } catch(Exception $e) {}
    echo json_encode(['success'=>true,'published_at'=>date('d.m.Y H:i'),'applied'=>$applied,'mode'=>$mode,'other_drafts'=>$otherDrafts]);
}catch(Throwable $e){error_log('publish: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Datenbankfehler beim Veröffentlichen']);}
