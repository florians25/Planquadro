<?php
$pageTitle = 'Rollen & Rechte';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/* AJAX handler before any HTML output */
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    requireLoginJson(); /* Befund S-14 */
    header('Content-Type: application/json; charset=utf-8');
    if(!hasPerm('manage_roles')){ echo json_encode(['success'=>false,'error'=>'Keine Berechtigung']); exit; }
    $input = json_decode(file_get_contents('php://input'),true);
    if(!$input || !verifyCsrf($input['_token']??'')){ echo json_encode(['success'=>false,'error'=>'Ungültiges Token']); exit; }
    $db = getDB();
    $act = $input['action'] ?? '';
    if ($act === 'save_perms') {
        $role = preg_replace('/[^a-z0-9_]/', '', $input['role'] ?? '');
        $perms = $input['perms'] ?? [];
        if (!$role) { echo json_encode(['success'=>false,'error'=>'Rolle fehlt']); exit; }
        /* Befund S-16: Ohne manage_roles für die Administrator-Rolle könnte
           niemand mehr Rollen verwalten. */
        if ($role === 'admin' && !in_array('manage_roles', $perms, true)) {
            echo json_encode(['success'=>false,'error'=>'Der Administrator-Rolle kann „Rollen & Rechte verwalten“ nicht entzogen werden.']); exit;
        }
        if (!array_key_exists($role, allRoles())) { echo json_encode(['success'=>false,'error'=>'Unbekannte Rolle']); exit; }
        $allP = array_keys(allPermissions());
        try {
            $db->beginTransaction();
            foreach ($allP as $pKey) {
                $granted = in_array($pKey, $perms) ? 1 : 0;
                /* Früher rollengebundene Rechte hat der Administrator immer (gesperrt) */
                if ($role === 'admin' && permissionIsRoleBound($pKey)) $granted = 1;
                $db->prepare('INSERT INTO role_permissions(role,permission,granted) VALUES(?,?,?) ON DUPLICATE KEY UPDATE granted=?')
                   ->execute([$role, $pKey, $granted, $granted]);
            }
            $db->commit();
            echo json_encode(['success'=>true,'msg'=>'Berechtigungen für '.roleName($role).' gespeichert.']);
        } catch(Exception $e) {
            if($db->inTransaction()) $db->rollBack();
            echo json_encode(['success'=>false,'error'=>'Datenbankfehler']);
        }
        exit;
    }
    if ($act === 'add_override') {
        $userId = (int)($input['user_id'] ?? 0);
        $perm = preg_replace('/[^a-z_]/', '', $input['permission'] ?? '');
        $granted = !empty($input['granted']) ? 1 : 0;
        if (!$userId || !$perm) { echo json_encode(['success'=>false,'error'=>'Daten fehlen']); exit; }
        if (!isset(allPermissions()[$perm])) { echo json_encode(['success'=>false,'error'=>'Ungültige Berechtigung']); exit; }
        if ($perm === 'manage_roles' && !$granted && $userId === (int)$_SESSION['user_id']) {
            echo json_encode(['success'=>false,'error'=>'Sie können sich dieses Recht nicht selbst entziehen.']); exit;
        }
        try {
            setUserOverride($userId, $perm, (bool)$granted);
            echo json_encode(['success'=>true,'msg'=>'Benutzer-Überschreibung gespeichert.']);
        } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'Datenbankfehler']); }
        exit;
    }
    if ($act === 'remove_override') {
        $userId = (int)($input['user_id'] ?? 0);
        $perm = preg_replace('/[^a-z_]/', '', $input['permission'] ?? '');
        if (!$userId || !$perm) { echo json_encode(['success'=>false,'error'=>'Daten fehlen']); exit; }
        try {
            removeUserOverride($userId, $perm);
            echo json_encode(['success'=>true,'msg'=>'Überschreibung entfernt.']);
        } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'Datenbankfehler']); }
        exit;
    }
    if ($act === 'get_overrides') {
        $perm = preg_replace('/[^a-z_]/', '', $input['permission'] ?? '');
        echo json_encode(['success'=>true,'overrides'=>getUserOverrides($perm)]);
        exit;
    }
    if ($act === 'nobody_except') {
        $perm = preg_replace('/[^a-z_]/', '', $input['permission'] ?? '');
        $role = preg_replace('/[^a-z0-9_]/', '', $input['role'] ?? '');
        $allowedIds = array_map('intval', $input['allowed_user_ids'] ?? []);
        if (!$perm || !$role || empty($allowedIds)) { echo json_encode(['success'=>false,'error'=>'Daten fehlen']); exit; }
        if (!isset(allPermissions()[$perm])) { echo json_encode(['success'=>false,'error'=>'Ungültige Berechtigung']); exit; }
        try {
            $db->beginTransaction();
            /* Nur Overrides der Benutzer dieser Rolle ersetzen – Ausnahmen für
               Benutzer anderer Rollen bleiben erhalten (Befund S-15). */
            $db->prepare('DELETE o FROM permission_user_overrides o JOIN users u ON u.id=o.user_id WHERE o.permission=? AND u.role=?')->execute([$perm, $role]);
            /* Get ALL users with this role */
            $s = $db->prepare('SELECT id FROM users WHERE role=? AND active=1');
            $s->execute([$role]);
            $allRoleUsers = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'id');
            /* Deny all in role EXCEPT the selected ones */
            foreach ($allRoleUsers as $uid) {
                if (!in_array((int)$uid, $allowedIds)) {
                    setUserOverride((int)$uid, $perm, false);
                }
            }
            /* Grant the selected ones explicitly */
            foreach ($allowedIds as $uid) {
                setUserOverride($uid, $perm, true);
            }
            $db->commit();
            $denied = count(array_diff(array_map('intval', $allRoleUsers), $allowedIds));
            echo json_encode(['success'=>true,'msg'=>count($allowedIds).' Person(en) erlaubt, '.$denied.' verweigert.']);
        } catch(Exception $e) {
            if($db->inTransaction()) $db->rollBack();
            echo json_encode(['success'=>false,'error'=>'Datenbankfehler']);
        }
        exit;
    }
    if ($act === 'create_role') {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($input['role_key'] ?? '')));
        $label = trim($input['role_label'] ?? '');
        $color = preg_replace('/[^#a-fA-F0-9]/', '', $input['color'] ?? '#6b7280');
        $baseRole = preg_replace('/[^a-z0-9_]/', '', $input['base_role'] ?? 'mitarbeiter');
        if (!$key || !$label || strlen($key) < 2) { echo json_encode(['success'=>false,'error'=>'Schlüssel (mind. 2 Zeichen) und Name erforderlich']); exit; }
        if (strlen($key) > 30) { echo json_encode(['success'=>false,'error'=>'Schlüssel max. 30 Zeichen']); exit; }
        if (mb_strlen($label) > 100) { echo json_encode(['success'=>false,'error'=>'Name max. 100 Zeichen']); exit; }
        if (array_key_exists($key, allRoles())) { echo json_encode(['success'=>false,'error'=>'Schlüssel "'.$key.'" existiert bereits.']); exit; }
        if (!array_key_exists($baseRole, allRoles())) { echo json_encode(['success'=>false,'error'=>'Unbekannte Basisrolle']); exit; }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6b7280';
        try {
            $db->beginTransaction();
            $db->prepare('INSERT INTO custom_roles(role_key,role_label,color,base_role,is_system,sort_order,created_by) VALUES(?,?,?,?,0,100,?)')
               ->execute([$key, $label, $color, $baseRole, $_SESSION['user_id']]);
            /* Rechte der Basisrolle kopieren (wirksame Werte, inkl. Katalog-Standard
               früher rollengebundener Rechte) */
            foreach (allPermissions() as $pKey => $pLabel) {
                $granted = roleHasPermission($baseRole, $pKey) ? 1 : 0;
                $db->prepare('INSERT IGNORE INTO role_permissions(role,permission,granted) VALUES(?,?,?)')->execute([$key, $pKey, $granted]);
            }
            $db->commit();
            echo json_encode(['success'=>true,'msg'=>'Rolle "'.$label.'" erstellt.']);
        } catch(Exception $e) {
            if($db->inTransaction()) $db->rollBack();
            if(strpos($e->getMessage(),'Duplicate')!==false) echo json_encode(['success'=>false,'error'=>'Schlüssel "'.$key.'" existiert bereits.']);
            else echo json_encode(['success'=>false,'error'=>'Datenbankfehler']);
        }
        exit;
    }
    if ($act === 'rename_role') {
        /* Eigene Rollen umbenennen (Anzeigename, Farbe). Der Schlüssel bleibt –
           er ist bei den Benutzern und Rechten gespeichert. */
        $key = preg_replace('/[^a-z0-9_]/', '', $input['role_key'] ?? '');
        $label = trim($input['role_label'] ?? '');
        $color = preg_replace('/[^#a-fA-F0-9]/', '', $input['color'] ?? '');
        if (!$key || $label === '') { echo json_encode(['success'=>false,'error'=>'Rolle und Name erforderlich']); exit; }
        if (mb_strlen($label) > 100) { echo json_encode(['success'=>false,'error'=>'Name max. 100 Zeichen']); exit; }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6b7280';
        if (in_array($key, SYSTEM_ROLES, true)) { echo json_encode(['success'=>false,'error'=>'System-Rollen können nicht umbenannt werden']); exit; }
        try {
            $s=$db->prepare('SELECT is_system FROM custom_roles WHERE role_key=?');$s->execute([$key]);$cr=$s->fetch();
            if(!$cr) { echo json_encode(['success'=>false,'error'=>'Rolle nicht gefunden']); exit; }
            if($cr['is_system']) { echo json_encode(['success'=>false,'error'=>'System-Rollen können nicht umbenannt werden']); exit; }
            $db->prepare('UPDATE custom_roles SET role_label=?, color=? WHERE role_key=?')->execute([$label, $color, $key]);
            echo json_encode(['success'=>true,'msg'=>'Rolle umbenannt in "'.$label.'".']);
        } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'Datenbankfehler']); }
        exit;
    }
    if ($act === 'delete_role') {
        $key = preg_replace('/[^a-z0-9_]/', '', $input['role_key'] ?? '');
        if (!$key) { echo json_encode(['success'=>false,'error'=>'Rolle fehlt']); exit; }
        /* Check not system role */
        try {
            $s=$db->prepare('SELECT is_system FROM custom_roles WHERE role_key=?');$s->execute([$key]);$cr=$s->fetch();
            if(!$cr) { echo json_encode(['success'=>false,'error'=>'Rolle nicht gefunden']); exit; }
            if($cr['is_system'] || in_array($key, SYSTEM_ROLES, true)) { echo json_encode(['success'=>false,'error'=>'System-Rollen können nicht gelöscht werden']); exit; }
            /* Check no users assigned */
            $s2=$db->prepare('SELECT COUNT(*) c FROM users WHERE role=?');$s2->execute([$key]);
            if((int)$s2->fetch()['c'] > 0) { echo json_encode(['success'=>false,'error'=>'Rolle ist noch Benutzern zugewiesen']); exit; }
            $db->beginTransaction();
            $db->prepare('DELETE FROM role_permissions WHERE role=?')->execute([$key]);
            $db->prepare('DELETE FROM custom_roles WHERE role_key=?')->execute([$key]);
            $db->commit();
            echo json_encode(['success'=>true,'msg'=>'Rolle gelöscht.']);
        } catch(Exception $e) { if($db->inTransaction())$db->rollBack(); echo json_encode(['success'=>false,'error'=>'Datenbankfehler']); }
        exit;
    }
    echo json_encode(['success'=>false,'error'=>'Unbekannte Aktion']); exit;
}

require_once __DIR__ . '/../includes/header.php';
if(!hasPerm('manage_roles')) { showAccessDenied('Sie benötigen die Berechtigung "Rollen verwalten".'); }

$allPerms = allPermissions();
$catalog = permissionCatalog();
$roles = allRoles();
/* Wirksamer Wert je Rolle (gespeicherter Wert, sonst Katalog-Standard bei
   früher rollengebundenen Rechten) – sonst würde "Speichern" vor Migration
   0006 diese Rechte entziehen. */
$permData = [];
foreach ($roles as $rKey => $rName) {
    $permData[$rKey] = [];
    foreach ($allPerms as $pKey => $pl) $permData[$rKey][$pKey] = roleHasPermission($rKey, $pKey);
}
$systemRoles = SYSTEM_ROLES;
try { $s=getDB()->query('SELECT role_key FROM custom_roles WHERE is_system=1'); foreach($s->fetchAll() as $sr) if(!in_array($sr['role_key'],$systemRoles)) $systemRoles[]=$sr['role_key']; } catch(Exception $e) {}
/* Eigene Rollen: Basisrolle für die Anzeige */
$baseRoles = [];
try { foreach (getDB()->query('SELECT role_key, base_role FROM custom_roles WHERE is_system=0')->fetchAll() as $cr) $baseRoles[$cr['role_key']] = $cr['base_role']; } catch(Exception $e) {}
/* Colors come from roleColor() in functions.php */
$groupIcons = [
    'Planung' => 'M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z',
    'Team & Abwesenheiten' => 'M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z',
    'Verwaltung' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
    'Betrieb' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
];
$permGroups = [];
foreach (permissionsByGroup() as $gName => $keys) $permGroups[$gName] = ['icon' => $groupIcons[$gName] ?? $groupIcons['Betrieb'], 'perms' => $keys];
$csrf = csrfToken();
$userCounts = [];
try { $s=getDB()->query("SELECT role, COUNT(*) c FROM users WHERE active=1 GROUP BY role"); foreach($s->fetchAll() as $r) $userCounts[$r['role']]=(int)$r['c']; } catch(Exception $e){}
?>

<div class="ph">
  <div class="phl"><h1>Rollen & Rechte</h1><span class="muted" style="font-size:.82rem">Berechtigungen pro Rolle verwalten</span></div>
</div>

<div class="rl-grid">
<!-- Role sidebar -->
<div class="rl-sidebar" id="rlSidebar">
<?php $first=true; foreach($roles as $rKey=>$rName):
$cnt=$userCounts[$rKey]??0;$clr=roleColor($rKey);
$grantedCnt=0;foreach($permData[$rKey] as $v)if($v)$grantedCnt++;
$totalPerms=count($allPerms);
$isSys=in_array($rKey,$systemRoles);
?>
<button class="rl-role-card <?php if($first) echo 'active';?>" data-role="<?php echo $rKey;?>" <?php echo opsActionAttr('rlSwitch',[$rKey]);?>>
<div class="rl-role-dot" style="background:<?php echo e($clr);?>"></div>
<div class="rl-role-info">
<span class="rl-role-name"><?php echo e($rName);?><?php if(!$isSys):?><span style="font-size:.55rem;color:var(--mu);font-weight:400;margin-left:.3rem">custom</span><?php endif;?></span>
<span class="rl-role-meta"><?php echo $cnt;?> Nutzer · <?php echo $grantedCnt;?>/<?php echo $totalPerms;?> Rechte</span>
</div>
<svg class="rl-role-arrow" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
</button>
<?php $first=false; endforeach; ?>
<button class="rl-new-role-btn" data-action="oMod" data-args='["newRoleMod"]'>
<svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
Neue Rolle erstellen
</button>
</div>

<!-- Permission panels -->
<div class="rl-main">
<?php $first=true; foreach($roles as $rKey=>$rName):
$rPerms = $permData[$rKey]; $clr=roleColor($rKey); ?>
<div class="rl-panel <?php if($first) echo 'active';?>" id="rlP_<?php echo $rKey;?>">
<div class="rl-panel-head">
<div class="rl-panel-title">
<div class="rl-panel-dot" style="background:<?php echo e($clr);?>"></div>
<div><h2 style="font-size:1rem;font-weight:700"><?php echo e($rName);?></h2>
<span class="muted" style="font-size:.75rem"><?php echo $userCounts[$rKey]??0;?> aktive Nutzer<?php if(isset($baseRoles[$rKey])):?> · eigene Rolle, angelegt auf Basis von <?php echo e(roleName($baseRoles[$rKey]));?><?php endif;?></span></div>
</div>
<div class="rl-panel-acts">
<button class="btn btn-outline btn-sm" <?php echo opsActionAttr('rlToggleAll',[$rKey,true]);?>>Alle an</button>
<button class="btn btn-outline btn-sm" <?php echo opsActionAttr('rlToggleAll',[$rKey,false]);?>>Alle aus</button>
<?php if(!in_array($rKey,$systemRoles)):?><button class="btn btn-outline btn-sm" data-role-key="<?php echo e($rKey);?>" data-role-label="<?php echo e($rName);?>" data-role-color="<?php echo e($clr);?>" data-action="rlOpenRename" data-args='["$this"]'>Umbenennen</button><button class="btn btn-outline btn-sm" style="color:var(--err);border-color:var(--err)" <?php echo opsActionAttr('rlDeleteRole',[$rKey,$rName]);?>>Rolle löschen</button><?php endif;?>
</div>
</div>

<?php foreach($permGroups as $gName=>$gData): ?>
<div class="rl-section">
<div class="rl-section-head">
<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" style="width:16px;height:16px"><path d="<?php echo $gData['icon'];?>"/></svg>
<span><?php echo e($gName);?></span>
</div>
<?php foreach($gData['perms'] as $pKey):
if(!isset($allPerms[$pKey])) continue;
$checked = !empty($rPerms[$pKey]);
/* Administrator: "Rollen & Rechte verwalten" und früher rollengebundene Rechte sind fest */
$locked = $rKey === 'admin' && ($pKey === 'manage_roles' || permissionIsRoleBound($pKey)); ?>
<div class="rl-perm-row">
<div class="rl-perm-text">
<span class="rl-perm-label"><?php echo e($allPerms[$pKey]);?></span>
<span class="rl-perm-hint"><?php echo e($catalog[$pKey]['description']??$pKey);?><?php if($locked):?> (für Administratoren immer erteilt)<?php endif;?></span>
<?php $ov=getUserOverrides($pKey);if($ov):?><span class="rl-override-tags"><?php foreach($ov as $o):?><span class="rl-override-tag <?php echo $o['granted']?'rl-ov-grant':'rl-ov-deny';?>"><?php echo e($o['first_name'].' '.$o['last_name']);?><button class="rl-ov-rm" <?php echo opsActionAttr('rlRemoveOverride',[(int)$o['user_id'],$pKey,opsActionToken('this')]);?>>&times;</button></span><?php endforeach;?></span><?php endif;?>
</div>
<div class="rl-perm-acts">
<button type="button" class="rl-user-btn" <?php echo opsActionAttr('rlOpenOverride',[$pKey,$allPerms[$pKey],$rKey]);?> title="Einzelne Person zuweisen/verweigern">
<svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg>
</button>
<label class="rl-toggle" title="<?php echo $locked ? 'Für Administratoren fest erteilt' : 'Für alle in dieser Rolle ein/aus';?>">
<input type="checkbox" class="rl-cb" data-role="<?php echo $rKey;?>" data-perm="<?php echo $pKey;?>" <?php if($checked||$locked) echo 'checked';?><?php if($locked) echo ' disabled data-locked="1"';?>>
<span class="rl-toggle-track"><span class="rl-toggle-thumb"></span></span>
</label>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="rl-save-bar">
<span class="rl-save-info" id="rlMsg_<?php echo $rKey;?>"></span>
<button class="btn btn-primary" <?php echo opsActionAttr('rlSave',[$rKey]);?>>
<svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;margin-right:.35rem"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
Speichern
</button>
</div>
</div>
<?php $first=false; endforeach; ?>
</div>
</div>

<style>
.rl-grid{display:grid;grid-template-columns:260px 1fr;gap:1rem;max-width:1200px;margin:0 auto;min-height:60vh}
.rl-sidebar{display:flex;flex-direction:column;gap:.35rem}
.rl-role-card{display:flex;align-items:center;gap:.65rem;padding:.65rem .8rem;background:var(--card);border:1.5px solid var(--bd);border-radius:var(--r2);cursor:pointer;text-align:left;width:100%}
.rl-role-card:hover{border-color:var(--bd2);box-shadow:var(--sh)}
.rl-role-card.active{border-color:var(--p);box-shadow:0 0 0 3px rgba(37,87,214,.08)}
.rl-role-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.rl-role-info{flex:1;min-width:0}
.rl-role-name{display:block;font-size:.82rem;font-weight:700;color:var(--tx);line-height:1.3}
.rl-role-meta{display:block;font-size:.65rem;color:var(--mu)}
.rl-role-arrow{width:14px;height:14px;color:var(--mu);flex-shrink:0}
.rl-role-card.active .rl-role-arrow{color:var(--p);transform:translateX(2px)}
.rl-main{min-width:0}
.rl-panel{display:none;background:var(--card);border:1px solid var(--bd);border-radius:var(--r2);overflow:hidden}
.rl-panel.active{display:block}
.rl-panel-head{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.2rem;border-bottom:1px solid var(--bd);background:var(--s)}
.rl-panel-title{display:flex;align-items:center;gap:.65rem}
.rl-panel-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0}
.rl-panel-acts{display:flex;gap:.35rem}
.rl-section{padding:0 1.2rem}
.rl-section-head{display:flex;align-items:center;gap:.5rem;padding:.75rem 0 .4rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mu);border-bottom:1px solid var(--bd)}
.rl-perm-row{display:flex;align-items:center;justify-content:space-between;padding:.6rem 0;border-bottom:1px solid #f3f4f6}
.rl-perm-row:hover{background:#fafbfc;margin:0 -.5rem;padding-left:.5rem;padding-right:.5rem;border-radius:var(--r)}
.rl-perm-row:last-child{border-bottom:none}
.rl-perm-acts{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.rl-perm-text{flex:1;min-width:0}
.rl-perm-label{display:block;font-size:.82rem;font-weight:600;color:var(--tx)}
.rl-perm-hint{display:block;font-size:.68rem;color:var(--mu);line-height:1.3}
.rl-toggle{flex-shrink:0;position:relative}
.rl-toggle input{position:absolute;opacity:0;width:0;height:0}
.rl-toggle-track{display:block;width:36px;height:20px;background:#d1d5db;border-radius:10px;position:relative;cursor:pointer}
.rl-toggle input:checked+.rl-toggle-track{background:var(--ok)}
.rl-toggle-thumb{position:absolute;top:2px;left:2px;width:16px;height:16px;background:#fff;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.15)}
.rl-toggle input:checked+.rl-toggle-track .rl-toggle-thumb{transform:translateX(16px)}
.rl-save-bar{display:flex;justify-content:space-between;align-items:center;padding:.75rem 1.2rem;border-top:1px solid var(--bd);background:var(--s);position:sticky;bottom:0}
.rl-save-info{font-size:.75rem;color:var(--ok);opacity:0}
.rl-save-info.show{opacity:1}
@media screen and (max-width:768px){
.rl-grid{grid-template-columns:1fr}
.rl-sidebar{flex-direction:row;overflow-x:auto;gap:.3rem;padding-bottom:.5rem}
.rl-role-card{min-width:140px;flex-shrink:0}
.rl-role-arrow{display:none}
}
</style>

<!-- User Override Modal -->
<div class="modal-bg" id="overrideMod"><div class="modal">
<div class="mh"><h3 id="overrideTitle">Berechtigung zuweisen</h3><button class="mx" data-action="cMod" data-args='["overrideMod"]'>&times;</button></div>
<div class="mb">
<div class="fg"><label>Modus wählen</label>
<div class="rl-ov-modes">
<label class="rl-ov-mode" id="ovModeGrant" data-action="rlSetOverrideMode" data-args='["grant"]'>
<input type="radio" name="overrideMode" value="grant" checked>
<div class="rl-ov-mode-icon" style="background:#dcfce7;color:#16a34a"><svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg></div>
<div><b>Zusätzlich gewähren</b><span class="muted" style="font-size:.68rem;display:block">Ausgewählte Personen erhalten dieses Recht zusätzlich</span></div>
</label>
<label class="rl-ov-mode" id="ovModeNobody" data-action="rlSetOverrideMode" data-args='["nobody"]'>
<input type="radio" name="overrideMode" value="nobody">
<div class="rl-ov-mode-icon" style="background:#fef3c7;color:#d97706"><svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg></div>
<div><b>Niemand außer</b><span class="muted" style="font-size:.68rem;display:block">NUR ausgewählte Personen – Rollen-Schalter wird deaktiviert</span></div>
</label>
<label class="rl-ov-mode" id="ovModeDeny" data-action="rlSetOverrideMode" data-args='["deny"]'>
<input type="radio" name="overrideMode" value="deny">
<div class="rl-ov-mode-icon" style="background:#fef2f2;color:#dc2626"><svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg></div>
<div><b>Verweigern für</b><span class="muted" style="font-size:.68rem;display:block">Ausgewählte Personen verlieren dieses Recht</span></div>
</label>
</div>
</div>
<div class="fg"><label>Personen auswählen</label>
<input type="text" class="inp-filter" id="overrideSearch" placeholder="Suchen..." data-action-input="rlFilterOverrideUsers" style="margin-bottom:.4rem">
<div style="max-height:200px;overflow-y:auto;border:1px solid var(--bd);border-radius:var(--r);padding:.25rem" id="overrideUserList">
<?php foreach(allUsers(true) as $ou):?>
<label class="rl-ov-user-item" data-search="<?php echo e(mb_strtolower($ou['last_name'].' '.$ou['first_name']));?>"><input type="checkbox" value="<?php echo $ou['id'];?>" class="ovUserCb"> <?php echo e($ou['last_name'].', '.$ou['first_name']);?> <span class="muted" style="font-size:.65rem">(<?php echo e(roleName($ou['role']));?>)</span></label>
<?php endforeach;?>
</div>
</div>
<input type="hidden" id="overridePerm" value="">
<input type="hidden" id="overrideRole" value="">
</div>
<div class="mf"><button class="btn btn-outline" data-action="cMod" data-args='["overrideMod"]'>Abbrechen</button><button class="btn btn-primary" data-action="rlSaveOverrides">Zuweisen</button></div>
</div></div>

<!-- New Role Modal -->
<div class="modal-bg" id="newRoleMod"><div class="modal modal-sm">
<div class="mh"><h3>Neue Rolle erstellen</h3><button class="mx" data-action="cMod" data-args='["newRoleMod"]'>&times;</button></div>
<div class="mb">
<div class="fg"><label>Rollen-Schlüssel</label><input type="text" id="nrKey" placeholder="z.B. praktikant" pattern="[a-z0-9_]+" maxlength="30" style="font-family:monospace"><span class="muted" style="font-size:.65rem">Kleinbuchstaben, Zahlen, Unterstriche. Wird intern verwendet.</span></div>
<div class="fg"><label>Anzeigename</label><input type="text" id="nrLabel" placeholder="z.B. Praktikant" maxlength="100"></div>
<div class="fg2">
<div class="fg"><label>Farbe</label><input type="color" id="nrColor" value="#6b7280"></div>
<div class="fg"><label>Berechtigungen kopieren von</label><select class="sel" id="nrBase">
<?php foreach($roles as $bk=>$bn):?><option value="<?php echo $bk;?>"<?php if($bk==='mitarbeiter') echo ' selected';?>><?php echo e($bn);?></option><?php endforeach;?>
</select></div>
</div>
</div>
<div class="mf"><button class="btn btn-outline" data-action="cMod" data-args='["newRoleMod"]'>Abbrechen</button><button class="btn btn-primary" data-action="rlCreateRole">Rolle erstellen</button></div>
</div></div>

<!-- Rename Role Modal -->
<div class="modal-bg" id="renameRoleMod"><div class="modal modal-sm">
<div class="mh"><h3>Rolle umbenennen</h3><button class="mx" data-action="cMod" data-args='["renameRoleMod"]'>&times;</button></div>
<div class="mb">
<input type="hidden" id="rrKey" value="">
<div class="fg"><label for="rrLabel">Anzeigename</label><input type="text" id="rrLabel" maxlength="100"></div>
<div class="fg"><label for="rrColor">Farbe</label><input type="color" id="rrColor" value="#6b7280"></div>
<span class="muted" style="font-size:.65rem">Der Rollen-Schlüssel <code id="rrKeyShow"></code> bleibt unverändert; Benutzer und Rechte bleiben zugeordnet.</span>
</div>
<div class="mf"><button class="btn btn-outline" data-action="cMod" data-args='["renameRoleMod"]'>Abbrechen</button><button class="btn btn-primary" data-action="rlRenameRole">Speichern</button></div>
</div></div>

<style>
.rl-user-btn{background:none;border:1px solid var(--bd);border-radius:var(--r);padding:3px 5px;cursor:pointer;color:var(--mu);flex-shrink:0}
.rl-user-btn:hover{background:var(--ibg);color:var(--inf);border-color:var(--inf)}
.rl-override-tags{display:flex;flex-wrap:wrap;gap:.25rem;margin-top:.2rem}
.rl-override-tag{display:inline-flex;align-items:center;gap:.25rem;font-size:.62rem;font-weight:600;padding:.1rem .4rem;border-radius:4px;line-height:1.4}
.rl-ov-grant{background:#dcfce7;color:#16a34a}
.rl-ov-deny{background:#fef2f2;color:#dc2626}
.rl-ov-rm{background:none;border:none;cursor:pointer;font-size:.7rem;opacity:.6;padding:0 1px;line-height:1;color:inherit}
.rl-ov-rm:hover{opacity:1}
.rl-ov-user-item{display:flex;align-items:center;gap:.4rem;padding:.3rem .4rem;font-size:.82rem;cursor:pointer;border-radius:var(--r)}
.rl-ov-user-item:hover{background:var(--bg)}
.rl-ov-user-item input{margin:0}
#ovModeGrant.ov-mode-active{border-color:var(--ok);background:var(--okbg)}
#ovModeNobody.ov-mode-active{border-color:var(--wn);background:var(--wnbg)}
#ovModeDeny.ov-mode-active{border-color:var(--err);background:var(--errbg)}
.rl-ov-modes{display:flex;flex-direction:column;gap:.4rem;margin-bottom:.5rem}
.rl-ov-mode{display:flex;align-items:center;gap:.6rem;padding:.5rem .65rem;border:1.5px solid var(--bd);border-radius:var(--r);cursor:pointer}
.rl-ov-mode:hover{border-color:var(--bd2)}
.rl-ov-mode input{margin:0;flex-shrink:0}
.rl-ov-mode-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.rl-new-role-btn{display:flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem .8rem;background:none;border:1.5px dashed var(--bd);border-radius:var(--r2);cursor:pointer;color:var(--mu);font-size:.78rem;font-weight:600;width:100%}
.rl-new-role-btn:hover{border-color:var(--p);color:var(--p);background:rgba(37,87,214,.03)}
</style>

<script nonce="<?php echo opsCspNonce(); ?>">
var RL_CSRF='<?php echo $csrf;?>';
function rlSwitch(role){
    document.querySelectorAll('.rl-role-card').forEach(function(c){c.classList.toggle('active',c.dataset.role===role);});
    document.querySelectorAll('.rl-panel').forEach(function(p){p.classList.toggle('active',p.id==='rlP_'+role);});
}
function rlToggleAll(role,state){
    document.querySelectorAll('.rl-cb[data-role="'+role+'"]').forEach(function(cb){if(!cb.dataset.locked)cb.checked=state;});
}
function rlSave(role){
    var perms=[];
    document.querySelectorAll('.rl-cb[data-role="'+role+'"]').forEach(function(cb){
        if(cb.checked) perms.push(cb.dataset.perm);
    });
    fetch(getApiBase()+'rollen',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'save_perms',role:role,perms:perms,_token:RL_CSRF})})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){
            toast(d.msg,'success');
            var msg=document.getElementById('rlMsg_'+role);
            if(msg){msg.textContent='Gespeichert';msg.classList.add('show');setTimeout(function(){msg.classList.remove('show');},3000);}
            var card=document.querySelector('.rl-role-card[data-role="'+role+'"]');
            if(card){var meta=card.querySelector('.rl-role-meta');if(meta){var total=document.querySelectorAll('.rl-cb[data-role="'+role+'"]').length;meta.textContent=meta.textContent.replace(/\d+\/\d+ Rechte/,perms.length+'/'+total+' Rechte');}}
        } else toast(d.error||'Fehler','error');
    }).catch(function(e){toast(e.message,'error');});
}
function rlOpenOverride(perm,label,role){
    document.getElementById('overridePerm').value=perm;
    document.getElementById('overrideRole').value=role||'';
    document.getElementById('overrideTitle').textContent='Berechtigung: '+label;
    document.querySelectorAll('.ovUserCb').forEach(function(cb){cb.checked=false;});
    document.getElementById('overrideSearch').value='';
    rlFilterOverrideUsers();
    rlSetOverrideMode('grant');
    oMod('overrideMod');
}
function rlSetOverrideMode(mode){
    document.getElementById('ovModeGrant').classList.toggle('ov-mode-active',mode==='grant');
    document.getElementById('ovModeNobody').classList.toggle('ov-mode-active',mode==='nobody');
    document.getElementById('ovModeDeny').classList.toggle('ov-mode-active',mode==='deny');
}
function rlFilterOverrideUsers(){
    var q=(document.getElementById('overrideSearch').value||'').toLowerCase();
    document.querySelectorAll('.rl-ov-user-item').forEach(function(it){
        it.style.display=(!q||it.dataset.search.indexOf(q)!==-1)?'':'none';
    });
}
function rlSaveOverrides(){
    var perm=document.getElementById('overridePerm').value;
    var role=document.getElementById('overrideRole').value;
    var mode=document.querySelector('input[name="overrideMode"]:checked').value;
    var selectedIds=[];
    document.querySelectorAll('.ovUserCb:checked').forEach(function(cb){selectedIds.push(parseInt(cb.value));});
    if(selectedIds.length===0){toast('Bitte mindestens eine Person ausw\u00e4hlen','warning');return;}

    if(mode==='nobody'){
        /* Niemand außer: deny ALL users in role, then grant only selected */
        fetch(getApiBase()+'rollen',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'nobody_except',permission:perm,role:role,allowed_user_ids:selectedIds,_token:RL_CSRF})})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){toast(d.msg,'success');cMod('overrideMod');setTimeout(function(){location.reload();},800);}
            else toast(d.error||'Fehler','error');
        }).catch(function(e){toast(e.message,'error');});
        return;
    }

    var granted=(mode==='grant')?1:0;
    var promises=selectedIds.map(function(uid){
        return fetch(getApiBase()+'rollen',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'add_override',user_id:uid,permission:perm,granted:granted,_token:RL_CSRF})}).then(function(r){return r.json();});
    });
    Promise.all(promises).then(function(results){
        var ok=results.filter(function(r){return r.success;}).length;
        toast(ok+' \u00dcberschreibung(en) gespeichert','success');
        cMod('overrideMod');setTimeout(function(){location.reload();},800);
    }).catch(function(e){toast(e.message,'error');});
}
function rlRemoveOverride(uid,perm,btn){
    fetch(getApiBase()+'rollen',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'remove_override',user_id:uid,permission:perm,_token:RL_CSRF})})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){toast(d.msg,'success');var tag=btn.closest('.rl-override-tag');if(tag)tag.remove();}
        else toast(d.error||'Fehler','error');
    }).catch(function(e){toast(e.message,'error');});
}
function rlCreateRole(){
    var key=document.getElementById('nrKey').value.trim().toLowerCase().replace(/[^a-z0-9_]/g,'');
    var label=document.getElementById('nrLabel').value.trim();
    var color=document.getElementById('nrColor').value;
    var base=document.getElementById('nrBase').value;
    if(!key||key.length<2){toast('Schlüssel mind. 2 Zeichen (a-z, 0-9, _)','warning');return;}
    if(!label){toast('Anzeigename eingeben','warning');return;}
    fetch(getApiBase()+'rollen',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'create_role',role_key:key,role_label:label,color:color,base_role:base,_token:RL_CSRF})})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){toast(d.msg,'success');cMod('newRoleMod');setTimeout(function(){location.reload();},800);}
        else toast(d.error||'Fehler','error');
    }).catch(function(e){toast(e.message,'error');});
}
function rlOpenRename(btn){
    document.getElementById('rrKey').value=btn.dataset.roleKey;
    document.getElementById('rrKeyShow').textContent=btn.dataset.roleKey;
    document.getElementById('rrLabel').value=btn.dataset.roleLabel;
    document.getElementById('rrColor').value=btn.dataset.roleColor||'#6b7280';
    oMod('renameRoleMod');
}
function rlRenameRole(){
    var key=document.getElementById('rrKey').value;
    var label=document.getElementById('rrLabel').value.trim();
    var color=document.getElementById('rrColor').value;
    if(!label){toast('Anzeigename eingeben','warning');return;}
    fetch(getApiBase()+'rollen',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'rename_role',role_key:key,role_label:label,color:color,_token:RL_CSRF})})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){toast(d.msg,'success');cMod('renameRoleMod');setTimeout(function(){location.reload();},800);}
        else toast(d.error||'Fehler','error');
    }).catch(function(e){toast(e.message,'error');});
}
function rlDeleteRole(key,label){
    epConfirm('Rolle "'+label+'" wirklich l\u00f6schen? Alle Berechtigungen werden entfernt.',function(){
        fetch(getApiBase()+'rollen',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'delete_role',role_key:key,_token:RL_CSRF})})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){toast(d.msg,'success');setTimeout(function(){location.reload();},800);}
            else toast(d.error||'Fehler','error');
        }).catch(function(e){toast(e.message,'error');});
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
