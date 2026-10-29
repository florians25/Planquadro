<?php
/* !! AJAX HANDLER FIRST – before any HTML output !! */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if(!canManageUsers()) { showAccessDenied('Die Benutzerverwaltung ist ausschließlich für Manager, Verwaltung (Service Administration) und Administratoren zugänglich.'); }
$db = getDB();
$canDelete = hasPerm('delete_users');

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'save') {
            $id=(int)($_POST['id']??0);
            $d=['username'=>trim($_POST['username']??''),'first_name'=>trim($_POST['first_name']??''),
                'last_name'=>trim($_POST['last_name']??''),'role'=>$_POST['role']??'mitarbeiter',
                'vacation_days'=>(float)($_POST['vacation_days']??30),
                'os_level'=>trim($_POST['os_level']??''),'weekend_allowed'=>isset($_POST['weekend_allowed'])?1:0,
                'training_profession'=>trim($_POST['training_profession']??''),
                'primary_area_id'=>!empty($_POST['primary_area_id'])?(int)$_POST['primary_area_id']:null,
                'exit_date'=>!empty($_POST['exit_date'])?$_POST['exit_date']:null,
                'entry_date'=>!empty($_POST['entry_date'])?$_POST['entry_date']:null];
            $supervisorIds = array_slice(array_values(array_filter(array_map('intval', explode(',', $_POST['supervisor_ids'] ?? '')))), 0, 4);
            $useOtp = !empty($_POST['use_otp']);
            if ($id>0) {
                $db->prepare('UPDATE users SET username=?,first_name=?,last_name=?,role=?,vacation_days=?,os_level=?,weekend_allowed=?,training_profession=?,primary_area_id=?,exit_date=?,entry_date=?,updated_at=NOW() WHERE id=?')
                   ->execute([$d['username'],$d['first_name'],$d['last_name'],$d['role'],$d['vacation_days'],$d['os_level'],$d['weekend_allowed'],$d['training_profession'],$d['primary_area_id'],$d['exit_date'],$d['entry_date'],$id]);
                if (!empty($_POST['password'])) $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($_POST['password'],PASSWORD_DEFAULT),$id]);
                // Vorgesetzte synchronisieren
                $db->prepare('DELETE FROM user_supervisors WHERE user_id=?')->execute([$id]);
                foreach ($supervisorIds as $sid) { if ($sid>0 && $sid!==$id) $db->prepare('INSERT IGNORE INTO user_supervisors(user_id,supervisor_id)VALUES(?,?)')->execute([$id,$sid]); }
                /* Ausnahme: darf für ALLE Mitarbeiter planen */
                $db->prepare('DELETE FROM permission_user_overrides WHERE user_id=? AND permission=?')->execute([$id,'view_all_employees']);
                if(!empty($_POST['plan_all'])) $db->prepare('INSERT INTO permission_user_overrides(permission,user_id,granted,created_by)VALUES(?,?,1,?)')->execute(['view_all_employees',$id,(int)$_SESSION['user_id']]);
                echo json_encode(['success'=>true,'msg'=>'Mitarbeiter aktualisiert.']); exit;
            } else {
                $otp='';$pw=$_POST['password']??'';
                if ($useOtp||!$pw){$otp=strtoupper(substr(md5(random_bytes(8)),0,8));$pw=$otp;}
                $h=password_hash($pw,PASSWORD_DEFAULT);$mcp=($otp)?1:0;
                $db->prepare('INSERT INTO users(username,password_hash,first_name,last_name,role,vacation_days,os_level,weekend_allowed,training_profession,primary_area_id,must_change_password,initial_password,exit_date,entry_date)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$d['username'],$h,$d['first_name'],$d['last_name'],$d['role'],$d['vacation_days'],$d['os_level'],$d['weekend_allowed'],$d['training_profession'],$d['primary_area_id'],$mcp,$otp?:null,$d['exit_date'],$d['entry_date']]);
                $newId = (int)$db->lastInsertId();
                foreach ($supervisorIds as $sid) { if ($sid>0 && $sid!==$newId) $db->prepare('INSERT IGNORE INTO user_supervisors(user_id,supervisor_id)VALUES(?,?)')->execute([$newId,$sid]); }
                if(!empty($_POST['plan_all'])) $db->prepare('INSERT INTO permission_user_overrides(permission,user_id,granted,created_by)VALUES(?,?,1,?)')->execute(['view_all_employees',$newId,(int)$_SESSION['user_id']]);
                $resp=['success'=>true,'msg'=>'Mitarbeiter angelegt.'];
                if($otp)$resp['otp']=$otp;
                echo json_encode($resp); exit;
            }
        }
        if ($act==='toggle'){ $db->prepare('UPDATE users SET active=NOT active WHERE id=?')->execute([(int)$_POST['id']]); echo json_encode(['success'=>true,'msg'=>'Status geändert.']); exit; }
        if ($act==='save_supervisors'){
            $uid=(int)($_POST['id']??0);
            if(!$uid){echo json_encode(['success'=>false,'error'=>'Ungültige ID']);exit;}
            $supervisorIds = array_slice(array_values(array_filter(array_map('intval', explode(',', $_POST['supervisor_ids'] ?? '')))), 0, 4);
            $db->prepare('DELETE FROM user_supervisors WHERE user_id=?')->execute([$uid]);
            foreach ($supervisorIds as $sid) { if ($sid>0 && $sid!==$uid) $db->prepare('INSERT IGNORE INTO user_supervisors(user_id,supervisor_id)VALUES(?,?)')->execute([$uid,$sid]); }
            echo json_encode(['success'=>true,'msg'=>'Vorgesetzte aktualisiert.','count'=>count($supervisorIds)]); exit;
        }
        if ($act==='promote'){
            /* Azubi abschließen → zum Techniker (Rolle "mitarbeiter") befördern */
            $uid=(int)($_POST['id']??0);
            if(!$uid){echo json_encode(['success'=>false,'error'=>'Ungültige ID']);exit;}
            $cur=$db->prepare('SELECT role,first_name,last_name FROM users WHERE id=?');$cur->execute([$uid]);$ru=$cur->fetch();
            if(!$ru){echo json_encode(['success'=>false,'error'=>'Nicht gefunden']);exit;}
            if(strpos($ru['role'],'azubi')!==0){echo json_encode(['success'=>false,'error'=>'Nur Auszubildende können befördert werden.']);exit;}
            $db->prepare("UPDATE users SET role='mitarbeiter',updated_at=NOW() WHERE id=?")->execute([$uid]);
            echo json_encode(['success'=>true,'msg'=>e($ru['first_name'].' '.$ru['last_name']).' ist jetzt Techniker.']); exit;
        }
        if ($act==='save_subordinates'){
            /* Diesem Vorgesetzten (Manager/Teamleiter) genau diese Mitarbeiter unterstellen.
               Andere Vorgesetzten-Beziehungen der Mitarbeiter bleiben erhalten. */
            $sid=(int)($_POST['id']??0);
            if(!$sid){echo json_encode(['success'=>false,'error'=>'Ungültige ID']);exit;}
            $memberIds = array_filter(array_map('intval', explode(',', $_POST['member_ids'] ?? '')));
            $db->prepare('DELETE FROM user_supervisors WHERE supervisor_id=?')->execute([$sid]);
            foreach ($memberIds as $mid) { if ($mid>0 && $mid!==$sid) $db->prepare('INSERT IGNORE INTO user_supervisors(user_id,supervisor_id)VALUES(?,?)')->execute([$mid,$sid]); }
            echo json_encode(['success'=>true,'msg'=>count($memberIds).' Mitarbeiter zugeordnet.','count'=>count($memberIds)]); exit;
        }
        if ($act==='delete_user'){
            if(!hasPerm('delete_users')){echo json_encode(['success'=>false,'error'=>'Keine Berechtigung']);exit;}
            $uid=(int)($_POST['id']??0);
            if(!$uid){echo json_encode(['success'=>false,'error'=>'Ungültige ID']);exit;}
            if($uid===$_SESSION['user_id']){echo json_encode(['success'=>false,'error'=>'Sie können sich nicht selbst löschen.']);exit;}
            /* Check if user has data */
            $hasA=$db->prepare('SELECT COUNT(*) c FROM assignments WHERE user_id=?');$hasA->execute([$uid]);$ac=(int)$hasA->fetch()['c'];
            $hasB=$db->prepare('SELECT COUNT(*) c FROM absences WHERE user_id=?');$hasB->execute([$uid]);$bc=(int)$hasB->fetch()['c'];
            /* Delete user and all related data (FK CASCADE handles assignments/absences) */
            $db->prepare('DELETE FROM user_supervisors WHERE user_id=? OR supervisor_id=?')->execute([$uid,$uid]);
            try{$db->prepare('DELETE FROM plan_drafts WHERE user_id=?')->execute([$uid]);}catch(Exception $e){}
            try{$db->prepare('DELETE FROM change_requests WHERE user_id=?')->execute([$uid]);}catch(Exception $e){}
            try{$db->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$uid]);}catch(Exception $e){}
            try{$db->prepare('DELETE FROM editing_status WHERE user_id=?')->execute([$uid]);}catch(Exception $e){}
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            echo json_encode(['success'=>true,'msg'=>'Mitarbeiter und alle zugehörigen Daten gelöscht. ('.$ac.' Einsätze, '.$bc.' Abwesenheiten)']); exit;
        }
        if ($act==='reset_pw'){
            $uid=(int)($_POST['id']??0);$otp=strtoupper(substr(md5(random_bytes(8)),0,8));
            $db->prepare('UPDATE users SET password_hash=?,must_change_password=1,initial_password=? WHERE id=?')->execute([password_hash($otp,PASSWORD_DEFAULT),$otp,$uid]);
            $s=$db->prepare('SELECT first_name,last_name,username FROM users WHERE id=?');$s->execute([$uid]);$row=$s->fetch();
            echo json_encode(['success'=>true,'msg'=>'Einmalpasswort gesetzt.','otp'=>$otp,'user'=>$row]); exit;
        }
        echo json_encode(['success'=>false,'error'=>'Unbekannte Aktion']); exit;
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit; }
}

/* ── Normal page rendering ── */
$pageTitle = 'Mitarbeiter';
require_once __DIR__ . '/../includes/header.php';
$users=$db->query("SELECT * FROM users ORDER BY active DESC, FIELD(role,'admin','manager','teamleiter','verwaltung','mitarbeiter','lager','azubi_1','azubi_2','azubi_3'), last_name")->fetchAll();
$roles=['admin'=>'Administrator','mitarbeiter'=>'Techniker','teamleiter'=>'Teamleiter','manager'=>'Manager','verwaltung'=>'Verwaltung','lager'=>'Lager','azubi_1'=>'Azubi 1. LJ','azubi_2'=>'Azubi 2. LJ','azubi_3'=>'Azubi 3. LJ'];
/* Vorgesetzte + Unterstellte laden */
$supMap=[]; $reportsMap=[];
try{$svRows=$db->query('SELECT user_id,supervisor_id FROM user_supervisors')->fetchAll();
    foreach($svRows as $sr){$supMap[(int)$sr['user_id']][]=(int)$sr['supervisor_id']; $reportsMap[(int)$sr['supervisor_id']][]=(int)$sr['user_id'];}
}catch(Exception $e){}
/* Ausnahme "darf alle planen" je Benutzer */
$planAllSet=[];
try{foreach($db->query("SELECT user_id FROM permission_user_overrides WHERE permission='view_all_employees' AND granted=1")->fetchAll() as $r) $planAllSet[(int)$r['user_id']]=true;}catch(Exception $e){}
/* Kennzahlen */
$today=date('Y-m-d');
$cntActive=0;$cntInactive=0;$cntExited=0;$cntByRole=[];
foreach($users as $cu2){
    if(!$cu2['active']){$cntInactive++;}
    elseif(!empty($cu2['exit_date'])&&$cu2['exit_date']<$today){$cntExited++;}
    else{$cntActive++;}
    if($cu2['active'])$cntByRole[$cu2['role']]=($cntByRole[$cu2['role']]??0)+1;
}
/* Rollen, die Mitarbeiter führen können */
$supervisorRoles=['admin','manager','teamleiter'];
?>
<div class="ph"><div class="phl"><h1>Mitarbeiter</h1><span class="muted" style="font-size:.82rem"><?php echo $cntActive;?> aktiv · <?php echo $cntByRole['teamleiter']??0;?> Teamleiter · <?php echo $cntByRole['manager']??0;?> Manager</span></div><button class="btn btn-sm btn-primary" onclick="oUserMod()">Neuer Mitarbeiter</button></div>

<div class="team-stats">
  <button type="button" class="team-stat" onclick="quickFilterStatus('active')"><span class="team-stat-n"><?php echo $cntActive;?></span><span class="team-stat-l">Aktiv</span></button>
  <button type="button" class="team-stat" onclick="quickFilterStatus('inactive')"><span class="team-stat-n"><?php echo $cntInactive;?></span><span class="team-stat-l">Inaktiv</span></button>
  <button type="button" class="team-stat" onclick="quickFilterStatus('exited')"><span class="team-stat-n"><?php echo $cntExited;?></span><span class="team-stat-l">Ausgetreten</span></button>
  <button type="button" class="team-stat" onclick="quickFilterRole('')"><span class="team-stat-n"><?php echo count($users);?></span><span class="team-stat-l">Gesamt</span></button>
</div>

<div class="team-search" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
<input type="text" class="inp-filter" id="teamSearch" placeholder="Mitarbeiter suchen..." oninput="filterTeam()">
<select class="sel sel-sm" id="teamRoleFilter" onchange="filterTeam()" style="width:auto">
<option value="">Alle Rollen</option>
<?php foreach($roles as $rk=>$rl): if(($cntByRole[$rk]??0)===0 && $rk!=='admin') continue; ?><option value="<?php echo e($rk);?>"><?php echo e($rl);?></option><?php endforeach;?>
</select>
<select class="sel sel-sm" id="teamStatusFilter" onchange="filterTeam()" style="width:auto">
<option value="">Alle Status</option>
<option value="active">Aktiv</option>
<option value="inactive">Inaktiv</option>
<option value="pre_entry">Vor Eintritt</option>
<option value="exited">Ausgetreten</option>
</select>
<span class="muted" id="teamCount" style="font-size:.78rem;margin-left:auto"></span>
</div>
<div class="card"><div class="cb p0"><div class="tbl-wrap">
<table class="tbl tbl-team" id="teamTbl"><thead><tr><th>Name</th><th>Rolle</th><th>Vorgesetzte</th><th>Team</th><th>Haupt-Einsatz</th><th>Ein-/Austritt</th><th>Status</th><th>Aktionen</th></tr></thead><tbody>
<?php
$allAreas = planAreaMap();
$allUsersMap=[]; foreach($users as $ux) $allUsersMap[$ux['id']]=$ux;
foreach($users as $u):
$uSups=$supMap[$u['id']]??[];
?>
<?php
$today=date('Y-m-d');
$uStatus='active';
if(!$u['active'])$uStatus='inactive';
elseif(!empty($u['entry_date'])&&$u['entry_date']>$today)$uStatus='pre_entry';
elseif(!empty($u['exit_date'])&&$u['exit_date']<$today)$uStatus='exited';
?>
<tr class="team-row <?php echo $u['active']?'':'row-off';?>" data-search="<?php echo e(mb_strtolower($u['last_name'].' '.$u['first_name'].' '.$u['username']));?>" data-status="<?php echo $uStatus;?>" data-role="<?php echo e($u['role']);?>">
<td><b><?php echo e($u['last_name'].', '.$u['first_name']);?></b><div class="team-sub"><?php echo e($u['username']);?><?php if(!empty($u['training_profession'])):?> · <span class="ts tp-badge"><?php echo e($u['training_profession']);?></span><?php endif;?><?php if($u['os_level']??''):?> · <span class="ts os-badge"><?php echo e($u['os_level']);?></span><?php endif;?></div></td>
<td><span class="rb r-<?php echo e($u['role']);?>"><?php echo e(roleName($u['role']));?></span></td>
<td><span class="sup-names" data-uid="<?php echo $u['id'];?>"><?php if(!empty($uSups)){$sNames=[];foreach($uSups as $sid){if(isset($allUsersMap[$sid]))$sNames[]=e($allUsersMap[$sid]['last_name'].', '.$allUsersMap[$sid]['first_name']);}echo implode('; ',$sNames)?:'-';}else echo "\xe2\x80\x93";?></span> <button type="button" class="sup-edit-btn" title="Vorgesetzte ändern" onclick='quickSup(<?php echo $u['id'];?>,<?php echo json_encode($u['last_name'].", ".$u['first_name'],JSON_HEX_APOS|JSON_HEX_QUOT);?>,<?php echo json_encode(array_values($uSups));?>)'><svg viewBox="0 0 20 20" fill="currentColor" width="13" height="13"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg></button></td>
<td><?php $myReports=$reportsMap[$u['id']]??[]; if(in_array($u['role'],$supervisorRoles,true)): ?><button type="button" class="team-manage-btn" onclick='quickSub(<?php echo $u['id'];?>,<?php echo json_encode($u['last_name'].", ".$u['first_name'],JSON_HEX_APOS|JSON_HEX_QUOT);?>,<?php echo json_encode(array_values($myReports));?>)'><svg viewBox="0 0 20 20" fill="currentColor" width="12" height="12"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg> <?php echo count($myReports);?></button><?php else: echo '<span class="muted">'."\xe2\x80\x93".'</span>'; endif; ?></td>
<td><?php if(!empty($u['primary_area_id'])&&isset($allAreas[$u['primary_area_id']])):$pa=$allAreas[$u['primary_area_id']];?><span class="ts" style="background:<?php echo e($pa['color']);?>;color:<?php echo e($pa['fg_color']);?>"><?php echo e($pa['short_code']);?></span> <?php echo e($pa['name']);?><?php else:?><?php echo "\xe2\x80\x93";?><?php endif;?></td>
<td class="team-dates"><?php echo !empty($u['entry_date']??null)?date('d.m.Y',strtotime($u['entry_date'])):"\xe2\x80\x93";?><br><span class="muted"><?php echo !empty($u['exit_date'])?date('d.m.Y',strtotime($u['exit_date'])):"\xe2\x80\x93";?></span></td>
<td><?php
  if($uStatus==='inactive')echo '<span class="badge bm">Inaktiv</span>';
  elseif($uStatus==='exited')echo '<span class="badge bd">Ausgetreten</span>';
  elseif($uStatus==='pre_entry')echo '<span class="badge bw">Vor Eintritt</span>';
  else echo '<span class="badge bs">Aktiv</span>';
?></td>
<td class="tbl-acts">
<button class="btn btn-xs btn-outline" onclick='oUserMod(<?php echo json_encode($u,JSON_HEX_APOS|JSON_HEX_QUOT);?>,<?php echo json_encode($uSups);?>,<?php echo isset($planAllSet[$u['id']])?'true':'false';?>)'>Bearbeiten</button>
<div class="team-act-menu"><button type="button" class="btn btn-xs btn-outline team-act-toggle" onclick="toggleActMenu(this)">⋯</button>
<div class="team-act-dropdown">
<?php if(strpos($u['role'],'azubi')===0):?><button class="team-act-item team-act-promote" onclick="promoteUser(<?php echo $u['id'];?>,'<?php echo e(addslashes($u['first_name'].' '.$u['last_name']));?>')">★ Zum Techniker befördern</button><?php endif;?>
<button class="team-act-item" onclick="toggleUser(<?php echo $u['id'];?>)"><?php echo $u['active']?'Deaktivieren':'Aktivieren';?></button>
<button class="team-act-item" onclick="resetPw(<?php echo $u['id'];?>)">Passwort zurücksetzen</button>
<?php if($canDelete):?><button class="team-act-item team-act-danger" onclick="deleteUser(<?php echo $u['id'];?>,'<?php echo e(addslashes($u['last_name'].', '.$u['first_name']));?>')">Entfernen</button><?php endif;?>
</div></div>
</td>
</tr><?php endforeach;?>
<tr id="teamEmpty" style="display:none"><td colspan="8" style="text-align:center;padding:2rem;color:var(--mu)">Keine Mitarbeiter gefunden.</td></tr>
</tbody></table></div></div></div>

<!-- User Modal (Assistent) -->
<div class="modal-bg" id="userMod"><div class="modal modal-lg">
<div class="mh"><h3 id="umT">Neuer Mitarbeiter</h3><button class="mx" onclick="cMod('userMod')">&times;</button></div>
<input type="hidden" id="uId" value="0">
<div class="uf-head" id="userFlatHead">
  <div class="uf-av" id="ufAv">–</div>
  <div class="uf-info">
    <div class="uf-name" id="ufName">–</div>
    <div class="uf-meta"><span class="uf-role" id="ufRole"></span><span class="uf-dot">·</span><span id="ufUser" class="uf-user"></span></div>
  </div>
</div>
<div class="wz-steps" id="wzSteps">
  <button type="button" class="wz-step" data-step="1" onclick="wzGoTo(1)"><span class="wz-num">1</span><span class="wz-label">Zugang</span></button>
  <button type="button" class="wz-step" data-step="2" onclick="wzGoTo(2)"><span class="wz-num">2</span><span class="wz-label">Person</span></button>
  <button type="button" class="wz-step" data-step="3" onclick="wzGoTo(3)"><span class="wz-num">3</span><span class="wz-label">Einsatz</span></button>
  <button type="button" class="wz-step" data-step="4" onclick="wzGoTo(4)"><span class="wz-num">4</span><span class="wz-label">Team &amp; Abschluss</span></button>
</div>
<div class="mb">

<!-- Schritt 1: Zugangsdaten -->
<div class="wz-panel" data-panel="1">
  <div class="wz-sec-title">Zugangsdaten</div>
  <p class="wz-hint">Anmeldedaten für den neuen Mitarbeiter. Ohne Passwort wird automatisch ein Einmalpasswort erzeugt.</p>
  <div class="fg"><label>Benutzername *</label><input type="text" id="uUser" required autocomplete="off"></div>
  <div class="fg"><label>Passwort</label><input type="password" id="uPass" placeholder="Leer = Einmalpasswort"><small class="muted" id="uPassHint">Leer lassen = Einmalpasswort generieren</small></div>
  <div class="fg-check" id="uOtpRow"><input type="checkbox" id="uOtp" value="1" checked><label for="uOtp">Einmalpasswort generieren (muss bei erster Anmeldung geändert werden)</label></div>
</div>

<!-- Schritt 2: Persönliche Daten -->
<div class="wz-panel" data-panel="2" style="display:none">
  <div class="wz-sec-title">Persönliche Daten</div>
  <p class="wz-hint">Name, Rolle und Vertragsdaten.</p>
  <div class="fg2">
    <div class="fg"><label>Vorname *</label><input type="text" id="uFn" required></div>
    <div class="fg"><label>Nachname *</label><input type="text" id="uLn" required></div>
  </div>
  <div class="fg2">
    <div class="fg"><label>Rolle</label><select id="uRole"><?php foreach($roles as $v=>$l):?><option value="<?php echo $v;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
    <div class="fg"><label>Urlaubstage/Jahr</label><input type="number" step="0.5" id="uVac" value="30"></div>
  </div>
  <div class="fg2">
    <div class="fg"><label>Eintrittsdatum</label><input type="date" id="uEntry"></div>
    <div class="fg"><label>Austrittsdatum</label><input type="date" id="uExit"><small class="muted">Ab Folgemonat nicht mehr sichtbar</small></div>
  </div>
</div>

<!-- Schritt 3: Einsatz-Details -->
<div class="wz-panel" data-panel="3" style="display:none">
  <div class="wz-sec-title">Einsatz-Details</div>
  <p class="wz-hint">Optionale Angaben zum Einsatz.</p>
  <div class="fg2">
    <div class="fg"><label>OS-Level</label><input type="text" id="uOs"></div>
    <div class="fg"><label>Ausbildungsberuf</label><select id="uTp"><?php foreach(trainingProfessions() as $v=>$l):?><option value="<?php echo e($v);?>"><?php echo e($v?$v.' – '.$l:$l);?></option><?php endforeach;?></select></div>
  </div>
  <div class="fg"><label>Haupt-Einsatzbereich</label><select id="uPa"><option value="">– Kein –</option><?php foreach(planAreas() as $pa):?><option value="<?php echo $pa['id'];?>"><?php echo e($pa['short_code'].' – '.$pa['name']);?></option><?php endforeach;?></select></div>
  <div class="fg-check"><input type="checkbox" id="uWe" value="1"><label for="uWe">Wochenendarbeit erlaubt</label></div>
</div>

<!-- Schritt 4: Vorgesetzte & Abschluss -->
<div class="wz-panel" data-panel="4" style="display:none">
  <div class="wz-sec-title">Vorgesetzte &amp; Team</div>
  <p class="wz-hint">Wer ist für diesen Mitarbeiter verantwortlich? Diese Personen sehen den Plan, Urlaub &amp; Krankheit und dürfen <b>für den Mitarbeiter eintragen</b>. Maximal 4.</p>
  <div class="fg">
    <label>Vorgesetzte / Genehmigende <span class="muted">(max. 4)</span></label>
    <div class="sup-chips" id="supChips"></div>
    <input type="text" class="inp-filter sup-search" placeholder="Vorgesetzte suchen &amp; hinzufügen..." oninput="filterSupPick(this,'supPick')" style="margin:.55rem 0 .4rem">
    <div class="sup-pick" id="supPick"><?php foreach($users as $su): if(!in_array($su['role'],['manager','teamleiter','admin'])) continue; ?><label class="sup-pick-item" data-name="<?php echo e(mb_strtolower($su['last_name'].' '.$su['first_name']));?>"><input type="checkbox" value="<?php echo $su['id'];?>" class="supCb" data-label="<?php echo e($su['last_name'].', '.$su['first_name']);?>" data-role="<?php echo e(roleName($su['role']));?>" onchange="onSupChange(this)"> <span class="spi-name"><?php echo e($su['last_name'].', '.$su['first_name']);?></span> <span class="spi-role"><?php echo e(roleName($su['role']));?></span></label><?php endforeach;?></div>
    <div class="sup-count" id="supCount"><b id="supCountN">0</b> / 4 ausgewählt</div>
  </div>
  <div class="wz-summary" id="wzSummary"></div>
  <label class="fg-check" style="margin-top:.7rem;align-items:flex-start"><input type="checkbox" id="uPlanAll" style="margin-top:.2rem"><span><b>Ausnahme:</b> darf für <b>alle</b> Mitarbeiter planen<br><small class="muted">Nützlich z.&nbsp;B. für einen Teamleiter, der vertretungsweise das ganze Haus plant. Überschreibt die normale Beschränkung auf das eigene Team.</small></span></label>
</div>

</div>
<div class="mf wz-foot">
  <button class="btn btn-outline" id="wzBack" onclick="wzPrev()" style="display:none">Zurück</button>
  <button class="btn btn-outline" onclick="cMod('userMod')">Abbrechen</button>
  <span class="wz-foot-spacer"></span>
  <button class="btn btn-primary" id="wzNext" onclick="wzNext()">Weiter</button>
  <button class="btn btn-primary" id="wzSave" onclick="saveUser()" style="display:none">Speichern</button>
</div>
</div></div>

<!-- Team / Unterstellte zuordnen (grafischer Team-Builder) -->
<div class="modal-bg" id="subMod"><div class="modal modal-lg sub-modal">
<div class="mh"><h3>Team zusammenstellen</h3><button class="mx" onclick="cMod('subMod')">&times;</button></div>
<input type="hidden" id="subSid" value="0">
<div class="sub-head">
  <div class="sub-head-av" id="subAv">–</div>
  <div class="sub-head-info">
    <div class="sub-head-name" id="subSupName"></div>
    <div class="sub-head-sub">Diese Person sieht &amp; bearbeitet den Plan der Team-Mitglieder, sieht Urlaub/Krankheit und genehmigt Anträge.</div>
  </div>
  <div class="sub-head-count"><b id="subCountN">0</b><span>im Team</span></div>
</div>
<div class="mb sub-body">
  <div class="sub-col">
    <div class="sub-col-h"><span>Verfügbare Mitarbeiter</span><span class="sub-col-n" id="subAvailN">0</span></div>
    <input type="text" class="inp-filter" id="subSearch" placeholder="Suchen nach Name oder Rolle..." oninput="renderSubPanes()">
    <div class="sub-list" id="subAvail"></div>
  </div>
  <div class="sub-arrow">⇄</div>
  <div class="sub-col">
    <div class="sub-col-h sub-col-h-team"><span>Im Team</span><span class="sub-col-n" id="subTeamN">0</span></div>
    <div class="sub-search-spacer"></div>
    <div class="sub-list sub-list-team" id="subTeam"></div>
  </div>
</div>
<div class="mf"><span class="sub-foot-hint">Tippen zum Hinzufügen oder Entfernen</span><button class="btn btn-outline" onclick="cMod('subMod')">Abbrechen</button><button class="btn btn-primary" onclick="saveQuickSub()">Team speichern</button></div>
</div></div>

<!-- Schnell-Vorgesetzte Modal -->
<div class="modal-bg" id="supMod"><div class="modal">
<div class="mh"><h3>Vorgesetzte ändern</h3><button class="mx" onclick="cMod('supMod')">&times;</button></div>
<input type="hidden" id="supUid" value="0">
<div class="mb">
<p class="muted" style="font-size:.82rem;margin:0 0 .6rem">Vorgesetzte für <b id="supUserName"></b>. Diese Personen können den Plan dieses Mitarbeiters sehen, bearbeiten und Anträge genehmigen.</p>
<input type="text" class="inp-filter sup-search" placeholder="Vorgesetzte suchen..." oninput="filterSupPick(this,'supPickQuick')" style="margin-bottom:.4rem">
<div class="sup-pick" id="supPickQuick" style="max-height:300px">
<?php foreach($users as $su): if(!in_array($su['role'],['manager','teamleiter','admin'])) continue; ?>
<label class="sup-pick-item" data-name="<?php echo e(mb_strtolower($su['last_name'].' '.$su['first_name']));?>"><input type="checkbox" value="<?php echo $su['id'];?>" class="supCbQuick"> <?php echo e($su['last_name'].', '.$su['first_name'].' ('.roleName($su['role']).')');?></label>
<?php endforeach;?>
</div>
</div>
<div class="mf"><button class="btn btn-outline" onclick="cMod('supMod')">Abbrechen</button><button class="btn btn-primary" onclick="saveQuickSup()">Speichern</button></div>
</div></div>

<!-- Zugangskarte Modal -->
<div class="modal-bg" id="cardMod"><div class="modal">
<div class="mh"><h3>Zugangskarte</h3><button class="mx" onclick="cMod('cardMod')">&times;</button></div>
<div class="mb" id="cardContent">
<div class="access-card" id="accessCard">
  <div class="ac-head">OpsPilot Einsatzplanung</div>
  <div class="ac-sub">Zugangsdaten</div>
  <div class="ac-row"><span class="ac-label">Name:</span><span class="ac-val" id="cardName"></span></div>
  <div class="ac-row"><span class="ac-label">Benutzername:</span><span class="ac-val ac-mono" id="cardUser"></span></div>
  <div class="ac-row"><span class="ac-label">Einmalpasswort:</span><span class="ac-val ac-mono ac-otp" id="cardPw"></span></div>
  <div class="ac-note">Bitte ändern Sie Ihr Passwort bei der ersten Anmeldung.</div>
</div></div>
<div class="mf"><button class="btn btn-outline" onclick="cMod('cardMod')">Schließen</button><button class="btn btn-primary" onclick="printCard()">Drucken</button></div>
</div></div>

<script>
var isNew=true;
function oUserMod(u,sups,planAll){
    isNew=!u; sups=sups||[];
    document.getElementById('umT').textContent=u?'Bearbeiten':'Neuer Mitarbeiter';
    var pa=document.getElementById('uPlanAll'); if(pa) pa.checked=!!planAll;
    document.getElementById('uId').value=u?u.id:0;
    document.getElementById('uUser').value=u?u.username:'';
    document.getElementById('uPass').value='';
    document.getElementById('uFn').value=u?u.first_name:'';
    document.getElementById('uLn').value=u?u.last_name:'';
    document.getElementById('uRole').value=u?u.role:'mitarbeiter';
    document.getElementById('uVac').value=u?u.vacation_days:30;
    document.getElementById('uOs').value=u?(u.os_level||''):'';
    document.getElementById('uTp').value=u?(u.training_profession||''):'';
    document.getElementById('uPa').value=u?(u.primary_area_id||''):'';
    document.getElementById('uEntry').value=u?(u.entry_date||''):'';
    document.getElementById('uExit').value=u?(u.exit_date||''):'';
    document.getElementById('uWe').checked=u?!!parseInt(u.weekend_allowed):false;
    document.getElementById('uOtpRow').style.display=u?'none':'';
    document.getElementById('uOtp').checked=!u;
    document.getElementById('uPassHint').style.display=u?'none':'';
    document.querySelectorAll('.supCb').forEach(function(cb){cb.checked=sups.indexOf(parseInt(cb.value))!==-1;});
    var ss=document.querySelector('#userMod .sup-search'); if(ss){ss.value='';filterSupPick(ss,'supPick');}
    renderSupChips(); updateSupCount();
    var modal=document.querySelector('#userMod .modal');
    if(u){
        /* Bearbeiten: breites, flaches Formular – kein Wizard */
        modal.classList.add('user-flat');
        var rs=document.getElementById('uRole');
        var roleTxt=rs.options[rs.selectedIndex]?rs.options[rs.selectedIndex].text:'';
        document.getElementById('ufAv').textContent=((u.first_name||'?').charAt(0)+(u.last_name||'').charAt(0)).toUpperCase();
        document.getElementById('ufName').textContent=(u.first_name||'')+' '+(u.last_name||'');
        document.getElementById('ufRole').textContent=roleTxt;
        document.getElementById('ufUser').textContent='@'+(u.username||'');
        document.getElementById('wzSteps').style.display='none';
        document.querySelectorAll('#userMod .wz-panel').forEach(function(p){p.style.display='';});
        document.getElementById('wzBack').style.display='none';
        document.getElementById('wzNext').style.display='none';
        document.getElementById('wzSave').style.display='';
        var sum=document.getElementById('wzSummary'); if(sum) sum.style.display='none';
    } else {
        /* Neu: Assistent */
        modal.classList.remove('user-flat');
        document.getElementById('wzSteps').style.display='';
        var sum2=document.getElementById('wzSummary'); if(sum2) sum2.style.display='';
        wzGoTo(1);
    }
    oMod('userMod');
}
/* ===== Vorgesetzten-Auswahl (Chips, max. 4) ===== */
function onSupChange(cb){
    if(cb.checked && document.querySelectorAll('#supPick .supCb:checked').length>4){
        cb.checked=false; toast('Maximal 4 Vorgesetzte pro Mitarbeiter.','warning');
    }
    renderSupChips(); updateSupCount();
}
function renderSupChips(){
    var box=document.getElementById('supChips'); if(!box)return;
    var chips='';
    document.querySelectorAll('#supPick .supCb:checked').forEach(function(cb){
        chips+='<span class="sup-chip"><span class="sup-chip-av">'+esc((cb.dataset.label||'?').charAt(0))+'</span><span class="sup-chip-txt"><span class="sup-chip-name">'+esc(cb.dataset.label||'')+'</span><span class="sup-chip-role">'+esc(cb.dataset.role||'')+'</span></span><button type="button" class="sup-chip-x" onclick="removeSup(\''+cb.value+'\')" aria-label="Entfernen">&times;</button></span>';
    });
    box.innerHTML = chips || '<span class="sup-chip-empty">Noch keine Vorgesetzten zugewiesen</span>';
}
function removeSup(id){
    var cb=document.querySelector('#supPick .supCb[value="'+id+'"]');
    if(cb){cb.checked=false; renderSupChips(); updateSupCount();}
}
function updateSupCount(){
    var n=document.querySelectorAll('#supPick .supCb:checked').length;
    var el=document.getElementById('supCountN'); if(el) el.textContent=n;
    var c=document.getElementById('supCount'); if(c) c.classList.toggle('sup-count-full', n>=4);
}
/* ===== Anlage-Assistent (Stepper) ===== */
var wzCur=1, wzMax=4;
function wzGoTo(step){
    if(step<1)step=1; if(step>wzMax)step=wzMax;
    wzCur=step;
    document.querySelectorAll('#userMod .wz-panel').forEach(function(p){
        p.style.display=(parseInt(p.getAttribute('data-panel'))===step)?'':'none';
    });
    document.querySelectorAll('#wzSteps .wz-step').forEach(function(s){
        var n=parseInt(s.getAttribute('data-step'));
        s.classList.toggle('active',n===step);
        s.classList.toggle('done',n<step);
    });
    document.getElementById('wzBack').style.display=(step>1)?'':'none';
    document.getElementById('wzNext').style.display=(step<wzMax)?'':'none';
    document.getElementById('wzSave').style.display=(step===wzMax)?'':'none';
    if(step===wzMax)wzRenderSummary();
}
function wzValidate(step){
    if(step===1){
        var un=document.getElementById('uUser').value.trim();
        if(!un){toast('Bitte einen Benutzernamen eingeben.','warning');document.getElementById('uUser').focus();return false;}
    }
    if(step===2){
        var fn=document.getElementById('uFn').value.trim(), ln=document.getElementById('uLn').value.trim();
        if(!fn||!ln){toast('Bitte Vor- und Nachnamen eingeben.','warning');document.getElementById(fn?'uLn':'uFn').focus();return false;}
    }
    return true;
}
function wzNext(){ if(wzValidate(wzCur)) wzGoTo(wzCur+1); }
function wzPrev(){ wzGoTo(wzCur-1); }
function wzRenderSummary(){
    var roleSel=document.getElementById('uRole');
    var roleTxt=roleSel.options[roleSel.selectedIndex]?roleSel.options[roleSel.selectedIndex].text:'';
    var sups=[];document.querySelectorAll('.supCb:checked').forEach(function(cb){sups.push(cb.parentNode.textContent.trim());});
    var name=(document.getElementById('uFn').value+' '+document.getElementById('uLn').value).trim();
    var html='<div class="wz-sum-row"><span>Name</span><b>'+(esc(name)||'–')+'</b></div>'+
             '<div class="wz-sum-row"><span>Benutzername</span><b>'+(esc(document.getElementById('uUser').value)||'–')+'</b></div>'+
             '<div class="wz-sum-row"><span>Rolle</span><b>'+esc(roleTxt)+'</b></div>'+
             '<div class="wz-sum-row"><span>Vorgesetzte</span><b>'+(sups.length?esc(sups.join('; ')):'– keine –')+'</b></div>';
    document.getElementById('wzSummary').innerHTML=html;
}
function saveUser(){
    var un=document.getElementById('uUser').value.trim();
    var fn=document.getElementById('uFn').value.trim(), ln=document.getElementById('uLn').value.trim();
    if(!un){toast('Bitte einen Benutzernamen eingeben.','warning');document.getElementById('uUser').focus();return;}
    if(!fn||!ln){toast('Bitte Vor- und Nachnamen eingeben.','warning');document.getElementById(fn?'uLn':'uFn').focus();return;}
    var fd=new FormData();
    fd.append('action','save');fd.append('id',document.getElementById('uId').value);
    fd.append('plan_all',document.getElementById('uPlanAll').checked?'1':'');
    fd.append('username',document.getElementById('uUser').value);fd.append('password',document.getElementById('uPass').value);
    fd.append('first_name',document.getElementById('uFn').value);fd.append('last_name',document.getElementById('uLn').value);
    fd.append('role',document.getElementById('uRole').value);fd.append('vacation_days',document.getElementById('uVac').value);
    fd.append('os_level',document.getElementById('uOs').value);
    fd.append('training_profession',document.getElementById('uTp').value);fd.append('primary_area_id',document.getElementById('uPa').value);
    var supIds=[];document.querySelectorAll('.supCb:checked').forEach(function(cb){supIds.push(cb.value);});
    fd.append('supervisor_ids',supIds.join(','));
    fd.append('entry_date',document.getElementById('uEntry').value);
    fd.append('exit_date',document.getElementById('uExit').value);
    if(document.getElementById('uWe').checked)fd.append('weekend_allowed','1');
    if(isNew&&document.getElementById('uOtp').checked)fd.append('use_otp','1');
    ajaxPost('mitarbeiter',fd,function(d){
        cMod('userMod');
        if(d.otp)showCard(document.getElementById('uFn').value+' '+document.getElementById('uLn').value,document.getElementById('uUser').value,d.otp);
        else setTimeout(function(){location.reload();},600);
    });
}
function toggleUser(id){epConfirm('Status \u00e4ndern?',function(){var fd=new FormData();fd.append('action','toggle');fd.append('id',id);ajaxPost('mitarbeiter',fd,function(){setTimeout(function(){location.reload();},600);});})}
function promoteUser(id,name){epConfirm(name+' hat die Ausbildung abgeschlossen und wird zum Techniker (Rolle „Techniker") bef\u00f6rdert. Fortfahren?',function(){var fd=new FormData();fd.append('action','promote');fd.append('id',id);ajaxPost('mitarbeiter',fd,function(d){toast(d.msg||'Bef\u00f6rdert.','success');setTimeout(function(){location.reload();},900);});})}
function deleteUser(id,name){epConfirm('ACHTUNG: "'+name+'" wird endg\u00fcltig gel\u00f6scht!\n\nAlle Eins\u00e4tze, Abwesenheiten, Antr\u00e4ge und Benachrichtigungen werden entfernt.\n\nDiese Aktion kann NICHT r\u00fcckg\u00e4ngig gemacht werden.\n\nFortfahren?',function(){
    var fd=new FormData();fd.append('action','delete_user');fd.append('id',id);
    ajaxPost('mitarbeiter',fd,function(){setTimeout(function(){location.reload();},600);});
});}
function resetPw(id){epConfirm('Einmalpasswort generieren? Aktuelles Passwort wird \u00fcberschrieben.',function(){var fd=new FormData();fd.append('action','reset_pw');fd.append('id',id);ajaxPost('mitarbeiter',fd,function(d){if(d.otp)showCard(d.user.first_name+' '+d.user.last_name,d.user.username,d.otp);});})}
function showCard(name,user,pw){document.getElementById('cardName').textContent=name;document.getElementById('cardUser').textContent=user;document.getElementById('cardPw').textContent=pw;oMod('cardMod');}
function printCard(){var c=document.getElementById('accessCard').outerHTML;var w=window.open('','_blank','width=500,height=400');w.document.write('<html><head><title>Zugangskarte</title><style>body{font-family:sans-serif;padding:20px}.access-card{border:2px solid #333;border-radius:10px;padding:24px;max-width:400px;margin:0 auto}.ac-head{font-size:18px;font-weight:900;text-align:center}.ac-sub{font-size:13px;text-align:center;color:#666;margin-bottom:16px;border-bottom:1px solid #ddd;padding-bottom:12px}.ac-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee;font-size:14px}.ac-label{font-weight:700;color:#555}.ac-val{font-weight:600}.ac-mono{font-family:monospace;letter-spacing:1px}.ac-otp{font-size:18px;color:#e2001a;font-weight:900}.ac-note{margin-top:14px;font-size:11px;color:#888;text-align:center}</style></head><body>');w.document.write(c);w.document.write('</body></html>');w.document.close();setTimeout(function(){w.print();},300);}
/* Schnell-Vorgesetzte */
function quickSup(uid,name,sups){
    sups=sups||[];
    document.getElementById('supUid').value=uid;
    document.getElementById('supUserName').textContent=name;
    document.querySelectorAll('.supCbQuick').forEach(function(cb){cb.checked=sups.indexOf(parseInt(cb.value))!==-1;});
    var sb=document.querySelector('#supMod .sup-search'); if(sb){sb.value='';filterSupPick(sb,'supPickQuick');}
    oMod('supMod');
}
function saveQuickSup(){
    var uid=document.getElementById('supUid').value;
    var ids=[];document.querySelectorAll('.supCbQuick:checked').forEach(function(cb){ids.push(cb.value);});
    if(ids.length>4){toast('Maximal 4 Vorgesetzte pro Mitarbeiter – es werden die ersten 4 gespeichert.','warning');ids=ids.slice(0,4);}
    var fd=new FormData();fd.append('action','save_supervisors');fd.append('id',uid);fd.append('supervisor_ids',ids.join(','));
    ajaxPost('mitarbeiter',fd,function(){cMod('supMod');setTimeout(function(){location.reload();},500);});
}
function filterSupPick(inp,listId){
    var q=inp.value.toLowerCase().trim();
    document.querySelectorAll('#'+listId+' .sup-pick-item').forEach(function(it){
        var n=it.getAttribute('data-name')||'';
        it.style.display=(!q||n.indexOf(q)!==-1)?'':'none';
    });
}
/* === Team-Builder (Unterstellte zuordnen) === */
var SUB_USERS = <?php
  $subData=[];
  foreach($users as $su){ if($su['role']==='admin') continue; $subData[]=['id'=>(int)$su['id'],'n'=>$su['last_name'].', '.$su['first_name'],'r'=>roleName($su['role']),'rk'=>$su['role']]; }
  echo json_encode($subData);
?>;
var subSet = {}, subSelfId = 0;
function quickSub(sid,name,members){
    members=members||[];
    subSelfId=parseInt(sid);
    subSet={};
    members.forEach(function(m){subSet[parseInt(m)]=true;});
    document.getElementById('subSid').value=sid;
    document.getElementById('subSupName').textContent=name;
    var parts=name.replace(',','').trim().split(/\s+/);
    var ini=(parts[0]||'?').charAt(0)+((parts[1]||'').charAt(0));
    document.getElementById('subAv').textContent=ini.toUpperCase();
    var s=document.getElementById('subSearch'); if(s)s.value='';
    renderSubPanes();
    oMod('subMod');
}
function _subCard(u,inTeam){
    var ini=esc((u.n.split(',')[1]||u.n).trim().charAt(0)+u.n.charAt(0)).toUpperCase();
    return '<button type="button" class="sub-card'+(inTeam?' in-team':'')+'" onclick="'+(inTeam?'subRemove':'subAdd')+'('+u.id+')">'+
        '<span class="sub-card-av sub-av-'+esc(u.rk)+'">'+ini+'</span>'+
        '<span class="sub-card-txt"><span class="sub-card-name">'+esc(u.n)+'</span><span class="sub-card-role">'+esc(u.r)+'</span></span>'+
        '<span class="sub-card-act">'+(inTeam?'&times;':'+')+'</span></button>';
}
function renderSubPanes(){
    var q=(document.getElementById('subSearch').value||'').toLowerCase().trim();
    var avail='',team='',na=0,nt=0;
    SUB_USERS.forEach(function(u){
        if(u.id===subSelfId) return;
        if(subSet[u.id]){ team+=_subCard(u,true); nt++; }
        else {
            if(q && (u.n.toLowerCase().indexOf(q)===-1 && u.r.toLowerCase().indexOf(q)===-1)) return;
            avail+=_subCard(u,false); na++;
        }
    });
    document.getElementById('subAvail').innerHTML = avail || '<div class="sub-empty">Keine passenden Mitarbeiter</div>';
    document.getElementById('subTeam').innerHTML = team || '<div class="sub-empty">Noch niemand im Team – links auf einen Mitarbeiter tippen.</div>';
    document.getElementById('subAvailN').textContent=na;
    document.getElementById('subTeamN').textContent=nt;
    document.getElementById('subCountN').textContent=nt;
}
function subAdd(id){ subSet[id]=true; renderSubPanes(); }
function subRemove(id){ delete subSet[id]; renderSubPanes(); }
function saveQuickSub(){
    var sid=document.getElementById('subSid').value;
    var ids=Object.keys(subSet);
    var fd=new FormData();fd.append('action','save_subordinates');fd.append('id',sid);fd.append('member_ids',ids.join(','));
    ajaxPost('mitarbeiter',fd,function(){cMod('subMod');setTimeout(function(){location.reload();},500);});
}
/* Aktionen-Menü */
function _closeActMenus(){
    document.querySelectorAll('.team-act-menu.open').forEach(function(m){
        m.classList.remove('open');
        var dd=m.querySelector('.team-act-dropdown'); if(dd)dd.style.cssText='';
    });
}
function toggleActMenu(btn){
    var menu=btn.parentNode, dd=menu.querySelector('.team-act-dropdown');
    var open=menu.classList.contains('open');
    _closeActMenus();
    if(open)return;
    menu.classList.add('open');
    var r=btn.getBoundingClientRect();
    dd.style.position='fixed';
    dd.style.right=(window.innerWidth-r.right)+'px';
    dd.style.left='auto';
    if(r.bottom+160>window.innerHeight){ dd.style.bottom=(window.innerHeight-r.top+4)+'px'; dd.style.top='auto'; }
    else { dd.style.top=(r.bottom+4)+'px'; dd.style.bottom='auto'; }
}
document.addEventListener('click',function(e){
    if(!e.target.closest('.team-act-menu')) _closeActMenus();
});
window.addEventListener('scroll',_closeActMenus,true);
window.addEventListener('resize',_closeActMenus);
/* Schnellfilter über die Statistik-Kacheln */
function quickFilterStatus(s){document.getElementById('teamStatusFilter').value=s;document.getElementById('teamRoleFilter').value='';filterTeam();}
function quickFilterRole(r){document.getElementById('teamRoleFilter').value=r;document.getElementById('teamStatusFilter').value='';filterTeam();}
/* Team search */
function filterTeam(){
    var q=document.getElementById('teamSearch').value.toLowerCase().trim();
    var sf=document.getElementById('teamStatusFilter').value;
    var rf=document.getElementById('teamRoleFilter').value;
    var shown=0;
    document.querySelectorAll('.team-row').forEach(function(r){
        var nameOk=(!q||r.getAttribute('data-search').indexOf(q)!==-1);
        var statusOk=(!sf||r.getAttribute('data-status')===sf);
        var roleOk=(!rf||r.getAttribute('data-role')===rf);
        var vis=(nameOk&&statusOk&&roleOk);
        r.style.display=vis?'':'none';
        if(vis)shown++;
    });
    var cnt=document.getElementById('teamCount');if(cnt)cnt.textContent=shown+' angezeigt';
    var empty=document.getElementById('teamEmpty');if(empty)empty.style.display=(shown===0)?'':'none';
}
document.addEventListener('DOMContentLoaded',function(){filterTeam();var s=document.getElementById('teamSearch');if(s&&window.innerWidth>768)s.focus();});
/* (alte filterTeam-Definition unten wird überschrieben) */
function _legacyFilterTeam(){
    var q=document.getElementById('teamSearch').value.toLowerCase().trim();
    var sf=document.getElementById('teamStatusFilter').value;
    document.querySelectorAll('.team-row').forEach(function(r){
        var nameOk=(!q||r.getAttribute('data-search').indexOf(q)!==-1);
        var statusOk=(!sf||r.getAttribute('data-status')===sf);
        r.style.display=(nameOk&&statusOk)?'':'none';
    });
}
</script>
<?php require_once __DIR__.'/../includes/footer.php';?>
