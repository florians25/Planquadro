<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/security.php';
header('Content-Type: application/json; charset=utf-8');

$input = secureJsonInput();
$action = $input['action'] ?? '';
$db = getDB();

/* Aktive Meldungen: nur für angemeldete Benutzer (Befund S-19) */
if ($action === 'active') {
    if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Nicht angemeldet']); exit; }
    try {
        $today = date('Y-m-d');
        $s = $db->prepare('SELECT id, title, content, priority, visible_from, visible_until, created_at
            FROM announcements WHERE active=1
            AND (visible_from IS NULL OR visible_from <= ?)
            AND (visible_until IS NULL OR visible_until >= ?)
            ORDER BY FIELD(priority,"critical","warning","info"), created_at DESC');
        $s->execute([$today, $today]);
        echo json_encode(['success'=>true, 'items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e) { echo json_encode(['success'=>true,'items'=>[]]); }
    exit;
}

/* Admin actions require login + permission */
if (!isLoggedIn() || !hasPerm('manage_settings')) {
    http_response_code(403); echo json_encode(['error'=>'Keine Berechtigung']); exit;
}

if ($action === 'list') {
    $s = $db->query('SELECT a.*, u.first_name, u.last_name FROM announcements a LEFT JOIN users u ON u.id=a.created_by ORDER BY a.created_at DESC');
    echo json_encode(['success'=>true,'items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'create') {
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $priority = in_array($input['priority']??'',['info','warning','critical']) ? $input['priority'] : 'info';
    $from = !empty($input['visible_from']) ? cleanDate($input['visible_from']) : null;
    $until = !empty($input['visible_until']) ? cleanDate($input['visible_until']) : null;
    if (!$title || !$content) { echo json_encode(['success'=>false,'error'=>'Titel und Inhalt erforderlich']); exit; }
    try {
        $db->prepare('INSERT INTO announcements(title,content,priority,visible_from,visible_until,created_by) VALUES(?,?,?,?,?,?)')
           ->execute([$title, $content, $priority, $from, $until, $_SESSION['user_id']]);
        echo json_encode(['success'=>true,'msg'=>'Meldung erstellt']);
    } catch(Exception $e) { opsApiFail($e, 'announcements', ['success'=>false,'error'=>'Datenbankfehler']); }
    exit;
}

if ($action === 'update') {
    $id = (int)($input['id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $priority = in_array($input['priority']??'',['info','warning','critical']) ? $input['priority'] : 'info';
    $from = !empty($input['visible_from']) ? cleanDate($input['visible_from']) : null;
    $until = !empty($input['visible_until']) ? cleanDate($input['visible_until']) : null;
    $active = isset($input['active']) ? (int)$input['active'] : 1;
    if (!$id || !$title) { echo json_encode(['success'=>false,'error'=>'Daten fehlen']); exit; }
    try {
        $db->prepare('UPDATE announcements SET title=?,content=?,priority=?,visible_from=?,visible_until=?,active=? WHERE id=?')
           ->execute([$title, $content, $priority, $from, $until, $active, $id]);
        echo json_encode(['success'=>true,'msg'=>'Meldung aktualisiert']);
    } catch(Exception $e) { opsApiFail($e, 'announcements', ['success'=>false,'error'=>'Datenbankfehler']); }
    exit;
}

if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'error'=>'ID fehlt']); exit; }
    $db->prepare('DELETE FROM announcements WHERE id=?')->execute([$id]);
    echo json_encode(['success'=>true,'msg'=>'Meldung gelöscht']);
    exit;
}

if ($action === 'toggle') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'error'=>'ID fehlt']); exit; }
    $db->prepare('UPDATE announcements SET active = NOT active WHERE id=?')->execute([$id]);
    echo json_encode(['success'=>true,'msg'=>'Status geändert']);
    exit;
}

echo json_encode(['error'=>'Unbekannte Aktion']);
