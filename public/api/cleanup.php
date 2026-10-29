<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
if(!isLoggedIn()||!hasRole('admin','manager')){http_response_code(403);echo json_encode(['error'=>'Keine Berechtigung']);exit;}
$input=json_decode(file_get_contents('php://input'),true);
$action=$input['action']??'';$type=$input['type']??'';$db=getDB();

if($action!=='cleanup'||!$type){echo json_encode(['error'=>'Ungültige Aktion']);exit;}

try{
    switch($type){
        case 'old_notifications':
            $s=$db->prepare('DELETE FROM notifications WHERE is_read=1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
            $s->execute();
            echo json_encode(['success'=>true,'msg'=>$s->rowCount().' alte Benachrichtigungen gelöscht.']);
            break;
        case 'editing_status':
            $s=$db->exec('DELETE FROM editing_status WHERE last_ping < DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
            echo json_encode(['success'=>true,'msg'=>'Bearbeitungsstatus bereinigt.']);
            break;
        case 'old_requests':
            $s=$db->prepare("DELETE FROM change_requests WHERE status IN('approved','rejected') AND updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $s->execute();
            echo json_encode(['success'=>true,'msg'=>$s->rowCount().' alte Anträge gelöscht.']);
            break;
        case 'publish_log':
            $db->exec('DELETE FROM publish_log WHERE id NOT IN (SELECT id FROM (SELECT id FROM publish_log ORDER BY published_at DESC LIMIT 100) t)');
            echo json_encode(['success'=>true,'msg'=>'Publish-Log gekürzt.']);
            break;
        case 'holiday_entries':
            /* Remove assignments and absences on Feiertage */
            $yr=(int)($input['year']??date('Y'));
            $db->beginTransaction();
            $s1=$db->prepare("DELETE a FROM assignments a INNER JOIN holidays h ON a.plan_date=h.holiday_date WHERE h.holiday_type='feiertag' AND a.year=?");
            $s1->execute([$yr]);$c1=$s1->rowCount();
            $s2=$db->prepare("DELETE ab FROM absences ab INNER JOIN holidays h ON ab.absence_date=h.holiday_date WHERE h.holiday_type='feiertag' AND YEAR(ab.absence_date)=?");
            $s2->execute([$yr]);$c2=$s2->rowCount();
            $db->commit();
            echo json_encode(['success'=>true,'msg'=>'Feiertage '.$yr.' bereinigt: '.$c1.' Einsätze und '.$c2.' Abwesenheiten entfernt.']);
            break;
        case 'delete_year':
            $yr=(int)($input['year']??0);
            if($yr<2020||$yr>2040){echo json_encode(['success'=>false,'error'=>'Ungültiges Jahr']);break;}
            $db->beginTransaction();
            $s1=$db->prepare('DELETE FROM assignments WHERE year=?');$s1->execute([$yr]);$c1=$s1->rowCount();
            $start=$yr.'-01-01';$end=$yr.'-12-31';
            $s2=$db->prepare('DELETE FROM absences WHERE absence_date BETWEEN ? AND ?');$s2->execute([$start,$end]);$c2=$s2->rowCount();
            $s3=$db->prepare('DELETE FROM publish_log WHERE year=?');$s3->execute([$yr]);
            try{$db->prepare('DELETE FROM plan_drafts WHERE year=?')->execute([$yr]);}catch(Exception $e){}
            $db->commit();
            echo json_encode(['success'=>true,'msg'=>'Jahr '.$yr.' gelöscht: '.$c1.' Einsätze, '.$c2.' Abwesenheiten.']);
            break;
        case 'old_login_attempts':
            $s=$db->prepare('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
            $s->execute();
            echo json_encode(['success'=>true,'msg'=>$s->rowCount().' alte Login-Einträge gelöscht.']);
            break;
        default:
            echo json_encode(['error'=>'Unbekannter Bereinigungstyp']);
    }
}catch(Exception $e){
    if($db->inTransaction())$db->rollBack();
    opsApiFail($e, 'cleanup', ['success'=>false,'error'=>opsGenericErrorMessage()]);
}
