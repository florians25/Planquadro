<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
if(!isLoggedIn()||!hasRole('admin','manager')){http_response_code(403);echo json_encode(['error'=>'Keine Berechtigung']);exit;}
$input=json_decode(file_get_contents('php://input'),true);
$action=$input['action']??'';$db=getDB();

if($action==='add'){
    $title=trim($input['title']??'');$desc=trim($input['description']??'');
    $start=$input['start_date']??'';$end=$input['end_date']??null;
    if(!$title||!$start){echo json_encode(['success'=>false,'error'=>'Titel und Startdatum nötig']);exit;}
    $db->prepare('INSERT INTO planned_maintenance(title,description,start_date,end_date,created_by)VALUES(?,?,?,?,?)')
       ->execute([$title,$desc?:null,$start,$end?:null,$_SESSION['user_id']]);
    echo json_encode(['success'=>true,'msg'=>'Wartung geplant']);
} elseif($action==='delete'){
    $id=(int)($input['id']??0);
    $db->prepare('DELETE FROM planned_maintenance WHERE id=?')->execute([$id]);
    echo json_encode(['success'=>true,'msg'=>'Wartung gelöscht']);
} elseif($action==='list'){
    $rows=$db->query('SELECT pm.*, u.first_name, u.last_name FROM planned_maintenance pm LEFT JOIN users u ON u.id=pm.created_by WHERE pm.is_active=1 ORDER BY pm.start_date')->fetchAll();
    echo json_encode(['success'=>true,'items'=>$rows]);
} else {
    echo json_encode(['error'=>'Unbekannte Aktion']);
}
