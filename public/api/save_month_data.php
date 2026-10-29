<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
header('Content-Type: application/json');
if(!canEdit()){http_response_code(403);echo json_encode(['error'=>'Keine Berechtigung']);exit;}
$input=json_decode(file_get_contents('php://input'),true);
$uid=(int)($input['user_id']??0);$year=(int)($input['year']??0);$month=(int)($input['month']??0);
$hours=$input['hours']??null;$snow=isset($input['snow_checked'])?(int)$input['snow_checked']:0;
if(!$uid||!$year||!$month){http_response_code(400);echo json_encode(['error'=>'Daten fehlen']);exit;}
$db=getDB();
try{
    $db->prepare('INSERT INTO user_month_data(user_id,year,month,hours,snow_checked)VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE hours=VALUES(hours),snow_checked=VALUES(snow_checked)')
       ->execute([$uid,$year,$month,$hours!=''?$hours:null,$snow]);
    echo json_encode(['success'=>true]);
}catch(Exception $e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
