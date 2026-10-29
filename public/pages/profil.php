<?php
$pageTitle = 'Mein Profil';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/* AJAX handler for cal token */
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $act = $input['action'] ?? '';
    if ($act === 'generate_cal_token') {
        $token = bin2hex(random_bytes(32));
        try {
            /* Ensure column exists */
            try { getDB()->exec("ALTER TABLE users ADD COLUMN `cal_token` VARCHAR(64) DEFAULT NULL UNIQUE"); } catch(Exception $e) {}
            getDB()->prepare('UPDATE users SET cal_token=? WHERE id=?')->execute([$token, $_SESSION['user_id']]);
            $url = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https://':'http://') . ($_SERVER['HTTP_HOST']??'localhost') . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/api/ical?token=' . $token;
            echo json_encode(['success'=>true,'token'=>$token,'url'=>$url,'msg'=>'Kalender-Token generiert']);
        } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'Fehler']); }
        exit;
    }
    if ($act === 'revoke_cal_token') {
        try {
            getDB()->prepare('UPDATE users SET cal_token=NULL WHERE id=?')->execute([$_SESSION['user_id']]);
            echo json_encode(['success'=>true,'msg'=>'Token widerrufen']);
        } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'Fehler']); }
        exit;
    }
    echo json_encode(['success'=>false]); exit;
}

require_once __DIR__ . '/../includes/header.php';
$u = currentUser();
$y = (int)date('Y');
$pam = planAreaMap();
$priArea = (!empty($u['primary_area_id']) && isset($pam[$u['primary_area_id']])) ? $pam[$u['primary_area_id']] : null;
$glS=getDB()->prepare("SELECT COUNT(*) c FROM absences WHERE user_id=? AND YEAR(absence_date)=? AND absence_type='gleitzeit' AND is_published=1");$glS->execute([$u['id'],$y]);$glCnt=(int)$glS->fetch()['c'];
$supS=getDB()->prepare('SELECT u.first_name,u.last_name,u.role FROM user_supervisors us JOIN users u ON u.id=us.supervisor_id WHERE us.user_id=?');$supS->execute([$u['id']]);$sups=$supS->fetchAll();
/* Password expiry */
$pwDays=(int)getSetting('password_expiry_days','30');
$pwChangedAt=$u['password_changed_at']??null;
$pwDaysLeft=0;$pwExpired=false;
if($pwDays>0&&$pwChangedAt){
    $changed=strtotime($pwChangedAt);
    $expiresAt=$changed+($pwDays*86400);
    $pwDaysLeft=max(0,ceil(($expiresAt-time())/86400));
    $pwExpired=($pwDaysLeft<=0);
}
/* Account age */
$createdAt=$u['created_at']??null;
$accountDays=$createdAt?floor((time()-strtotime($createdAt))/86400):0;
?>
<div class="ph"><h1>Mein Profil</h1></div>

<div class="g prof-cols" style="grid-template-columns:1fr 1fr;gap:1rem">
<div class="card">
<div class="ch">Persönliche Daten</div>
<div class="cb">
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem">
<div class="profile-avatar"><?php echo mb_strtoupper(mb_substr($u['first_name'],0,1).mb_substr($u['last_name'],0,1)); ?></div>
<div>
<div style="font-size:1.15rem;font-weight:800"><?php echo e($u['first_name'].' '.$u['last_name']); ?></div>
<span class="rb r-<?php echo e($u['role']);?>" style="font-size:.72rem"><?php echo e(roleName($u['role'])); ?></span>
<?php if($u['os_level']):?><span class="ts os-badge"><?php echo e($u['os_level']);?></span><?php endif;?>
</div>
</div>
<table class="tbl" style="font-size:.85rem">
<tr><td class="muted" style="width:150px">Benutzername</td><td><b><?php echo e($u['username']);?></b></td></tr>
<?php if($priArea):?><tr><td class="muted">Stammbereich</td><td><span class="tag" style="background:<?php echo e($priArea['color']);?>;color:<?php echo e($priArea['fg_color']);?>"><?php echo e($priArea['short_code']);?></span> <?php echo e($priArea['name']);?></td></tr><?php endif;?>
<?php if(!empty($u['training_profession'])):?><tr><td class="muted">Ausbildungsberuf</td><td><?php echo e($u['training_profession']);?></td></tr><?php endif;?>
<?php if(!empty($u['entry_date'])):?><tr><td class="muted">Eintrittsdatum</td><td><?php echo date('d.m.Y',strtotime($u['entry_date']));?></td></tr><?php endif;?>
<?php if(!empty($u['exit_date'])):?><tr><td class="muted">Austrittsdatum</td><td><?php echo date('d.m.Y',strtotime($u['exit_date']));?></td></tr><?php endif;?>
<tr><td class="muted">Wochenendarbeit</td><td><?php echo $u['weekend_allowed']?'Ja':'Nein';?></td></tr>
<tr><td class="muted">Konto erstellt</td><td><?php echo $createdAt?date('d.m.Y',strtotime($createdAt)):'–';?> <span class="muted">(<?php echo $accountDays;?> Tage)</span></td></tr>
</table>
<?php if(!empty($sups)):?>
<div style="margin-top:.75rem"><span class="muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase">Vorgesetzte</span><br>
<?php foreach($sups as $sp):?><span class="rb r-<?php echo e($sp['role']);?>" style="margin-top:.25rem;display:inline-block"><?php echo e($sp['first_name'].' '.$sp['last_name']);?></span> <?php endforeach;?>
</div>
<?php endif;?>
</div></div>

<div style="display:flex;flex-direction:column;gap:1rem">
<div class="card">
<div class="ch">Jahresübersicht <?php echo $y;?></div>
<div class="cb">
<div class="profile-stats">
<div class="profile-stat"><div class="profile-stat-num" style="color:var(--ok)"><?php echo number_format(vacLeft($u['id'],$y),1,',','');?></div><div class="profile-stat-label">Resturlaub</div><div class="profile-stat-sub"><?php echo number_format(vacUsed($u['id'],$y),1,',','');?> / <?php echo number_format((float)$u['vacation_days'],1,',','');?></div></div>
<div class="profile-stat"><div class="profile-stat-num" style="color:var(--err)"><?php echo number_format(sickDays($u['id'],$y),1,',','');?></div><div class="profile-stat-label">Kranktage</div></div>
<div class="profile-stat"><div class="profile-stat-num" style="color:#7c3aed"><?php echo $glCnt;?></div><div class="profile-stat-label">Gleitzeit</div></div>
</div>
</div></div>

<div class="card">
<div class="ch">Sicherheit</div>
<div class="cb">
<div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid var(--bd)">
<div>
<div style="font-size:.85rem;font-weight:600">Passwort</div>
<?php if($pwDays>0):?>
<?php if($pwExpired):?>
<span class="badge bd" style="font-size:.7rem">Abgelaufen!</span>
<?php elseif($pwDaysLeft<=7):?>
<span class="badge bw" style="font-size:.7rem">Läuft in <?php echo $pwDaysLeft;?> Tagen ab</span>
<?php else:?>
<span class="muted" style="font-size:.75rem">Noch <?php echo $pwDaysLeft;?> Tage gültig</span>
<?php endif;?>
<?php if($pwChangedAt):?><div class="muted" style="font-size:.72rem">Zuletzt geändert: <?php echo date('d.m.Y H:i',strtotime($pwChangedAt));?></div><?php endif;?>
<?php else:?>
<span class="muted" style="font-size:.75rem">Kein Ablauf konfiguriert</span>
<?php endif;?>
</div>
<button class="btn btn-sm btn-outline" onclick="oMod('pwMod')">Ändern</button>
</div>
<div style="padding:.5rem 0">
<div style="font-size:.85rem;font-weight:600">Sitzung</div>
<span class="muted" style="font-size:.75rem">Angemeldet als <?php echo e($u['username']);?></span>
</div>
</div></div>

<div style="display:flex;gap:.5rem;flex-wrap:wrap">
<a href="mein_plan" class="btn btn-outline">Mein Plan</a>
<a href="dashboard" class="btn btn-outline">Dashboard</a>
</div>

<!-- iCal Subscription -->
<div class="card" style="grid-column:1/-1">
<div class="ch">Kalender-Abo (iCal)</div>
<div class="cb">
<p class="muted" style="font-size:.82rem;margin-bottom:.75rem">Abonniere deinen Einsatzplan in Outlook, Apple Kalender, Google Calendar oder jeder anderen Kalender-App. Das Abo wird automatisch aktualisiert.</p>
<?php
$calToken=null;
try{$ct=getDB()->prepare('SELECT cal_token FROM users WHERE id=?');$ct->execute([$u['id']]);$r=$ct->fetch();$calToken=$r['cal_token']??null;}catch(Exception $e){}
$calUrl=$calToken?((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https://':'http://').$_SERVER['HTTP_HOST'].dirname(dirname($_SERVER['SCRIPT_NAME'])).'/api/ical?token='.$calToken):null;
?>
<div id="calSection">
<?php if($calToken):?>
<div style="margin-bottom:.75rem">
<label style="font-size:.72rem;font-weight:600;color:var(--mu);text-transform:uppercase;letter-spacing:.03em">Abo-URL</label>
<div style="display:flex;gap:.4rem;margin-top:.25rem">
<input type="text" class="inp-sm" id="calUrl" value="<?php echo e($calUrl);?>" readonly style="flex:1;font-size:.72rem;font-family:monospace;background:#f8fafc" onclick="this.select()">
<button class="btn btn-sm btn-outline" onclick="navigator.clipboard.writeText(document.getElementById('calUrl').value);toast('URL kopiert','success')">Kopieren</button>
</div>
<p class="muted" style="font-size:.68rem;margin-top:.35rem">Diese URL ist privat. Teile sie nicht mit anderen – sie gibt Zugriff auf deinen Einsatzplan.</p>
</div>
<div style="display:flex;gap:.5rem">
<button class="btn btn-sm btn-outline" onclick="generateCalToken()" style="color:var(--wn);border-color:var(--wn)">Neues Token generieren</button>
<button class="btn btn-sm btn-outline" onclick="revokeCalToken()" style="color:var(--err);border-color:var(--err)">Token widerrufen</button>
</div>
<?php else:?>
<button class="btn btn-primary" onclick="generateCalToken()">Kalender-Abo aktivieren</button>
<?php endif;?>
</div>
</div></div>
<script>
function generateCalToken(){
    fetch(getApiBase()+'profil',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'generate_cal_token'})})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){toast(d.msg,'success');setTimeout(function(){location.reload();},600);}
        else toast(d.error||'Fehler','error');
    }).catch(function(e){toast(e.message,'error');});
}
function revokeCalToken(){
    epConfirm('Token widerrufen? Die Kalender-URL funktioniert dann nicht mehr.',function(){
        fetch(getApiBase()+'profil',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'revoke_cal_token'})})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){toast(d.msg,'success');setTimeout(function(){location.reload();},600);}
            else toast(d.error||'Fehler','error');
        }).catch(function(e){toast(e.message,'error');});
    });
}
</script>
</div>
</div>

<?php require_once __DIR__.'/../includes/footer.php';?>
