<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Nicht angemeldet']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$db = getDB();
$cu = currentUser();
$uid = (int)$cu['id'];

switch ($action) {

case 'count':
    try {
        $s = $db->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$uid]);
        echo json_encode(['success'=>true, 'count'=>(int)$s->fetch()['c']]);
    } catch(Exception $e) { echo json_encode(['success'=>true, 'count'=>0]); }
    exit;

case 'list':
    $limit = min(30, max(1, (int)($input['limit'] ?? 15)));
    try {
        $s = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?");
        $s->execute([$uid, $limit]);
        echo json_encode(['success'=>true, 'items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e) { echo json_encode(['success'=>false, 'error'=>$e->getMessage()]); }
    exit;

case 'mark_read':
    $nid = (int)($input['id'] ?? 0);
    try {
        if ($nid) {
            $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$nid, $uid]);
        } else {
            $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0")->execute([$uid]);
        }
        echo json_encode(['success'=>true]);
    } catch(Exception $e) { echo json_encode(['success'=>false]); }
    exit;

default:
    echo json_encode(['success'=>false, 'error'=>'Unbekannte Aktion']);
}
