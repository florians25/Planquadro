<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
if(!isLoggedIn()||!canEdit()){http_response_code(403);echo json_encode(['error'=>'Keine Berechtigung']);exit;}
$input=json_decode(file_get_contents('php://input'),true);
$action=$input['action']??'';$db=getDB();

if($action==='import'){
    $items=$input['items']??[];
    if(empty($items)){echo json_encode(['success'=>false,'error'=>'Keine Daten']);exit;}
    $saved=0;$skipped=0;
    foreach($items as $h){
        $name=trim($h['name']??'');$date=$h['date']??'';$type=$h['type']??'feiertag';
        if(!$name||!$date){$skipped++;continue;}
        if(!in_array($type,['feiertag','ferien']))$type='feiertag';
        try{
            $db->prepare('INSERT INTO holidays(name,holiday_date,holiday_type,source) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),holiday_type=VALUES(holiday_type),source=VALUES(source)')
               ->execute([$name,$date,$type,'api_import']);
            $saved++;
        }catch(Exception $e){$skipped++;}
    }
    echo json_encode(['success'=>true,'msg'=>$saved.' importiert, '.$skipped.' übersprungen']);
} else {
    echo json_encode(['error'=>'Unbekannte Aktion']);
}
