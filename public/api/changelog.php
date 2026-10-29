<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
if(!isLoggedIn()){http_response_code(401);echo json_encode(['error'=>'Nicht angemeldet']);exit;}
$input=json_decode(file_get_contents('php://input'),true);
$action=$input['action']??'';$db=getDB();

/* Tabelle changelog wird per Migration angelegt (kein DDL zur Laufzeit) */

if($action==='list'){
    try{$s=$db->query('SELECT * FROM changelog ORDER BY id DESC LIMIT 20');
    echo json_encode(['success'=>true,'entries'=>$s->fetchAll(PDO::FETCH_ASSOC)]);}
    catch(Exception $e){echo json_encode(['success'=>true,'entries'=>[]]);}
    exit;
}
if($action==='add'){
    if(!canEdit()){echo json_encode(['success'=>false,'error'=>'Keine Berechtigung']);exit;}
    $ver=trim($input['version']??'');$content=trim($input['content']??'');$date=$input['release_date']??date('Y-m-d');$type=$input['entry_type']??'feature';
    if(!$ver||!$content){echo json_encode(['success'=>false,'error'=>'Version und Inhalt erforderlich']);exit;}
    $db->prepare('INSERT INTO changelog(version,release_date,content,entry_type,created_by) VALUES(?,?,?,?,?)')->execute([$ver,$date,$content,$type,$_SESSION['user_id']]);
    echo json_encode(['success'=>true,'msg'=>'Changelog-Eintrag gespeichert']);exit;
}
if($action==='delete'){
    if(!hasRole('admin','manager')){echo json_encode(['success'=>false,'error'=>'Keine Berechtigung']);exit;}
    $db->prepare('DELETE FROM changelog WHERE id=?')->execute([(int)($input['id']??0)]);
    echo json_encode(['success'=>true,'msg'=>'Gelöscht']);exit;
}
echo json_encode(['error'=>'Unbekannte Aktion']);
