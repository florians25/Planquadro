<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
header('Content-Type: application/json');
if(!canEdit()){http_response_code(403);echo json_encode(['error'=>'Keine Berechtigung']);exit;}
$db=getDB();
$input=json_decode(file_get_contents('php://input'),true);
$action=$input['action']??($_GET['action']??'list');

try {
    if ($action==='list') {
        $uid=(int)($input['user_id']??$_GET['user_id']??0);
        $year=(int)($input['year']??$_GET['year']??0);
        $month=(int)($input['month']??$_GET['month']??0);
        if(!$uid||!$year||!$month){http_response_code(400);echo json_encode(['error'=>'Parameter fehlen']);exit;}
        $s=$db->prepare('SELECT * FROM user_wots WHERE user_id=? AND year=? AND month=? ORDER BY id');
        $s->execute([$uid,$year,$month]);
        echo json_encode(['success'=>true,'wots'=>$s->fetchAll()]);
    }
    elseif ($action==='add') {
        $uid=(int)($input['user_id']??0);$year=(int)($input['year']??0);$month=(int)($input['month']??0);
        $num=trim($input['wot_number']??'');$link=trim($input['link_url']??'');
        $color=trim($input['color']??'#3b82f6');$rem=trim($input['remarks']??'');
        if(!$uid||!$year||!$month||!$num){http_response_code(400);echo json_encode(['error'=>'WOT-Nummer + User + Monat nötig']);exit;}
        $s=$db->prepare('INSERT INTO user_wots(user_id,wot_number,link_url,color,remarks,year,month,visible_to_user)VALUES(?,?,?,?,?,?,?,?)');
        $vis=(int)($input['visible_to_user']??1);
        $s->execute([$uid,$num,$link,$color,$rem,$year,$month,$vis]);
        $id=$db->lastInsertId();
        $s2=$db->prepare('SELECT * FROM user_wots WHERE id=?');$s2->execute([$id]);
        echo json_encode(['success'=>true,'wot'=>$s2->fetch()]);
    }
    elseif ($action==='update') {
        $id=(int)($input['id']??0);
        if(!$id){http_response_code(400);echo json_encode(['error'=>'ID fehlt']);exit;}
        $sets=[];$params=[];
        foreach(['wot_number','link_url','color','remarks','visible_to_user'] as $f){
            if(isset($input[$f])){$sets[]="`$f`=?";$params[]=trim($input[$f]);}
        }
        if(empty($sets)){http_response_code(400);echo json_encode(['error'=>'Keine Felder']);exit;}
        $params[]=$id;
        $db->prepare('UPDATE user_wots SET '.implode(',',$sets).' WHERE id=?')->execute($params);
        $s=$db->prepare('SELECT * FROM user_wots WHERE id=?');$s->execute([$id]);
        echo json_encode(['success'=>true,'wot'=>$s->fetch()]);
    }
    elseif ($action==='delete') {
        $id=(int)($input['id']??0);
        if(!$id){http_response_code(400);echo json_encode(['error'=>'ID fehlt']);exit;}
        $db->prepare('DELETE FROM user_wots WHERE id=?')->execute([$id]);
        echo json_encode(['success'=>true]);
    }
    else {
        http_response_code(400);echo json_encode(['error'=>'Unbekannte Aktion: '.$action]);
    }
} catch(Exception $e) {
    http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);
}
