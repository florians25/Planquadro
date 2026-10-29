<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
header('Content-Type: application/json; charset=utf-8');
if(!isLoggedIn()){http_response_code(403);echo json_encode(['error'=>'Nicht angemeldet']);exit;}
$input=json_decode(file_get_contents('php://input'),true);
if(!$input){http_response_code(400);echo json_encode(['error'=>'Ungültige Daten']);exit;}
$old=$input['old_password']??'';$new=$input['new_password']??'';$new2=$input['new_password2']??'';
if(!$old||!$new||!$new2){echo json_encode(['success'=>false,'error'=>'Alle Felder ausfüllen']);exit;}
if(strlen($new)<6){echo json_encode(['success'=>false,'error'=>'Mindestens 6 Zeichen']);exit;}
if($new!==$new2){echo json_encode(['success'=>false,'error'=>'Passwörter stimmen nicht überein']);exit;}
$db=getDB();$s=$db->prepare('SELECT password_hash FROM users WHERE id=?');$s->execute([$_SESSION['user_id']]);$u=$s->fetch();
if(!$u||!password_verify($old,$u['password_hash'])){echo json_encode(['success'=>false,'error'=>'Aktuelles Passwort falsch']);exit;}
$db->prepare('UPDATE users SET password_hash=?, password_changed_at=NOW() WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$_SESSION['user_id']]);
opsRevokeUserTokens($db,(int)$_SESSION['user_id']);
unset($_SESSION['pw_expiry_checked']);
echo json_encode(['success'=>true,'msg'=>'Passwort geändert']);
