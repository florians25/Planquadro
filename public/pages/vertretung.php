<?php
/* !! AJAX HANDLER FIRST – before any HTML output !! */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!hasRole('admin','manager','teamleiter')) {
    showAccessDenied('Die Vertreterregelung ist nur für Teamleiter, Manager und Administratoren zugänglich.');
}
$db = getDB();
$me = currentUser();
$meId = (int)$me['id'];
$isAdmin = hasRole('admin');

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'create') {
            $deputyId = (int)($_POST['deputy_id'] ?? 0);
            $start    = $_POST['start_date'] ?? '';
            $end      = $_POST['end_date'] ?? '';
            $note     = trim($_POST['note'] ?? '');
            /* Abgebender = aktueller Benutzer (gibt eigene Rechte ab) */
            $delegatorId = $meId;

            if ($deputyId <= 0)            { echo json_encode(['success'=>false,'error'=>'Bitte eine Vertretung auswählen.']); exit; }
            if ($deputyId === $delegatorId){ echo json_encode(['success'=>false,'error'=>'Sie können sich nicht selbst vertreten.']); exit; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)) {
                echo json_encode(['success'=>false,'error'=>'Bitte einen gültigen Zeitraum angeben.']); exit;
            }
            if ($end < $start) { echo json_encode(['success'=>false,'error'=>'Das Enddatum liegt vor dem Startdatum.']); exit; }
            if ($end < date('Y-m-d')) { echo json_encode(['success'=>false,'error'=>'Der Zeitraum liegt vollständig in der Vergangenheit.']); exit; }

            /* Vertretung muss selbst Vorgesetzten-Rolle haben */
            $chk = $db->prepare("SELECT role,first_name,last_name FROM users WHERE id=? AND active=1");
            $chk->execute([$deputyId]); $dep = $chk->fetch();
            if (!$dep || !in_array($dep['role'],['admin','manager','teamleiter'],true)) {
                echo json_encode(['success'=>false,'error'=>'Als Vertretung sind nur Teamleiter, Manager oder Administratoren möglich.']); exit;
            }

            $db->prepare('INSERT INTO deputations(delegator_id,deputy_id,start_date,end_date,note,created_by) VALUES(?,?,?,?,?,?)')
               ->execute([$delegatorId,$deputyId,$start,$end,$note!==''?$note:null,$meId]);
            echo json_encode(['success'=>true,'msg'=>'Vertretung eingerichtet.']); exit;
        }
        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            /* Nur eigene Vertretungen löschen – Admin darf alle */
            if ($isAdmin) $db->prepare('DELETE FROM deputations WHERE id=?')->execute([$id]);
            else          $db->prepare('DELETE FROM deputations WHERE id=? AND delegator_id=?')->execute([$id,$meId]);
            echo json_encode(['success'=>true,'msg'=>'Vertretung beendet.']); exit;
        }
        echo json_encode(['success'=>false,'error'=>'Unbekannte Aktion']); exit;
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit;
    }
}

/* === Daten für die Anzeige === */
$today = date('Y-m-d');
function depStatus($s,$e,$today){ if($today < $s) return ['geplant','vtr-b-info']; if($today > $e) return ['abgelaufen','vtr-b-mut']; return ['aktiv','vtr-b-ok']; }

/* mögliche Vertretungen (Vorgesetzten-Rollen, aktiv, ohne sich selbst) */
$depUsers = $db->query("SELECT id,first_name,last_name,role FROM users WHERE active=1 AND role IN ('admin','manager','teamleiter') ORDER BY last_name,first_name")->fetchAll();

/* Meine abgegebenen Vertretungen */
$mine = $db->prepare("SELECT d.*, u.first_name df, u.last_name dl, u.role dr
                      FROM deputations d JOIN users u ON u.id=d.deputy_id
                      WHERE d.delegator_id=? ORDER BY d.start_date DESC");
$mine->execute([$meId]); $mine = $mine->fetchAll();

/* Wo ich selbst Vertretung bin (aktiv/geplant) */
$asDeputy = $db->prepare("SELECT d.*, u.first_name gf, u.last_name gl
                          FROM deputations d JOIN users u ON u.id=d.delegator_id
                          WHERE d.deputy_id=? AND d.end_date>=? ORDER BY d.start_date");
$asDeputy->execute([$meId,$today]); $asDeputy = $asDeputy->fetchAll();

/* Admin: Gesamtübersicht */
$all = [];
if ($isAdmin) {
    $all = $db->query("SELECT d.*, g.first_name gf, g.last_name gl, p.first_name df, p.last_name dl
                       FROM deputations d
                       JOIN users g ON g.id=d.delegator_id
                       JOIN users p ON p.id=d.deputy_id
                       ORDER BY d.end_date>=CURDATE() DESC, d.start_date DESC")->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.vtr-grid { display: grid; grid-template-columns: 360px 1fr; gap: 1.1rem; align-items: start; }
.vtr-grid .card, .vtr-admin-card { padding: 1.2rem 1.35rem; }
.vtr-h { font-size: .95rem; font-weight: 800; margin: 0 0 .2rem; }
.vtr-dates { display: grid; grid-template-columns: 1fr 1fr; gap: .7rem; }
.vtr-list { display: flex; flex-direction: column; gap: .5rem; }
.vtr-item { display: flex; align-items: center; gap: .7rem; padding: .55rem .7rem; border: 1px solid var(--bd); border-radius: 11px; background: #fff; }
.vtr-item-in { background: var(--pbg); border-color: #f5c2c7; }
.vtr-item-main { flex: 1; min-width: 0; }
.vtr-item-name { font-size: .86rem; font-weight: 700; display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.vtr-item-sub { font-size: .72rem; color: var(--mu); margin-top: .1rem; }
.vtr-av { width: 38px; height: 38px; border-radius: 50%; background: var(--p); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: .82rem; flex: none; }
.vtr-av-alt { background: #475569; }
.vtr-badge { display: inline-block; padding: .08rem .4rem; border-radius: 999px; font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; }
.vtr-b-ok { background: #dcfce7; color: #16a34a; }
.vtr-b-info { background: #e0f2fe; color: #0284c7; }
.vtr-b-mut { background: #f1f5f9; color: #64748b; }
@media screen and (max-width: 820px) { .vtr-grid { grid-template-columns: 1fr; } }
</style>
<div class="content">
  <div class="ph"><div class="phl"><h1>Vertretung</h1><span class="muted" style="font-size:.82rem">Rechte bei Abwesenheit zeitlich begrenzt an eine Vertretung übergeben</span></div></div>

  <div class="vtr-grid">
    <div class="card vtr-form-card">
      <h3 class="vtr-h">Neue Vertretung einrichten</h3>
      <p class="muted" style="font-size:.8rem;margin:.2rem 0 .9rem">Wählen Sie eine Vertretung und einen Zeitraum. Während dieser Zeit sieht und bearbeitet die Vertretung Ihr Team und kann Anträge genehmigen.</p>
      <div class="fg"><label>Vertretung</label>
        <select class="inp" id="vDeputy">
          <option value="">— bitte wählen —</option>
          <?php foreach($depUsers as $du): if((int)$du['id']===$meId) continue; ?>
          <option value="<?php echo (int)$du['id'];?>"><?php echo e($du['last_name'].', '.$du['first_name'].' ('.roleName($du['role']).')');?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vtr-dates">
        <div class="fg"><label>Von</label><input type="date" class="inp" id="vStart" value="<?php echo $today;?>"></div>
        <div class="fg"><label>Bis</label><input type="date" class="inp" id="vEnd" value="<?php echo $today;?>"></div>
      </div>
      <div class="fg"><label>Notiz (optional)</label><input type="text" class="inp" id="vNote" maxlength="255" placeholder="z. B. Urlaub, Krankheit"></div>
      <button class="btn btn-primary btn-full" onclick="vCreate()">Vertretung speichern</button>
    </div>

    <div class="card">
      <h3 class="vtr-h">Meine Vertretungen</h3>
      <?php if(!$mine): ?>
        <p class="muted" style="font-size:.85rem;margin:.4rem 0">Sie haben aktuell keine Vertretungen eingerichtet.</p>
      <?php else: ?>
        <div class="vtr-list">
        <?php foreach($mine as $d): [$slabel,$scls]=depStatus($d['start_date'],$d['end_date'],$today); ?>
          <div class="vtr-item">
            <div class="vtr-av"><?php echo e(mb_strtoupper(mb_substr($d['df'],0,1).mb_substr($d['dl'],0,1)));?></div>
            <div class="vtr-item-main">
              <div class="vtr-item-name"><?php echo e($d['dl'].', '.$d['df']);?> <span class="vtr-badge <?php echo $scls;?>"><?php echo $slabel;?></span></div>
              <div class="vtr-item-sub"><?php echo date('d.m.Y',strtotime($d['start_date'])).' – '.date('d.m.Y',strtotime($d['end_date']));?><?php if($d['note']):?> · <?php echo e($d['note']);?><?php endif;?></div>
            </div>
            <button class="btn btn-sm btn-outline" onclick="vDelete(<?php echo (int)$d['id'];?>)">Beenden</button>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if($asDeputy): ?>
        <h3 class="vtr-h" style="margin-top:1.2rem">Ich vertrete</h3>
        <div class="vtr-list">
        <?php foreach($asDeputy as $d): [$slabel,$scls]=depStatus($d['start_date'],$d['end_date'],$today); ?>
          <div class="vtr-item vtr-item-in">
            <div class="vtr-av vtr-av-alt"><?php echo e(mb_strtoupper(mb_substr($d['gf'],0,1).mb_substr($d['gl'],0,1)));?></div>
            <div class="vtr-item-main">
              <div class="vtr-item-name"><?php echo e($d['gl'].', '.$d['gf']);?> <span class="vtr-badge <?php echo $scls;?>"><?php echo $slabel;?></span></div>
              <div class="vtr-item-sub"><?php echo date('d.m.Y',strtotime($d['start_date'])).' – '.date('d.m.Y',strtotime($d['end_date']));?> · Sie übernehmen dieses Team</div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if($isAdmin && $all): ?>
  <div class="card vtr-admin-card" style="margin-top:1.1rem">
    <h3 class="vtr-h">Alle Vertretungen (Administration)</h3>
    <div class="vtr-list">
    <?php foreach($all as $d): [$slabel,$scls]=depStatus($d['start_date'],$d['end_date'],$today); ?>
      <div class="vtr-item">
        <div class="vtr-item-main">
          <div class="vtr-item-name"><?php echo e($d['gl'].', '.$d['gf']);?> → <?php echo e($d['dl'].', '.$d['df']);?> <span class="vtr-badge <?php echo $scls;?>"><?php echo $slabel;?></span></div>
          <div class="vtr-item-sub"><?php echo date('d.m.Y',strtotime($d['start_date'])).' – '.date('d.m.Y',strtotime($d['end_date']));?><?php if($d['note']):?> · <?php echo e($d['note']);?><?php endif;?></div>
        </div>
        <button class="btn btn-sm btn-outline" onclick="vDelete(<?php echo (int)$d['id'];?>)">Löschen</button>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function vCreate(){
  var dep=document.getElementById('vDeputy').value;
  var s=document.getElementById('vStart').value, e=document.getElementById('vEnd').value;
  if(!dep){toast('Bitte eine Vertretung auswählen.','warning');return;}
  if(!s||!e){toast('Bitte einen Zeitraum angeben.','warning');return;}
  if(e<s){toast('Das Enddatum liegt vor dem Startdatum.','warning');return;}
  var fd=new FormData();fd.append('action','create');fd.append('deputy_id',dep);fd.append('start_date',s);fd.append('end_date',e);fd.append('note',document.getElementById('vNote').value);
  ajaxPost('vertretung',fd,function(d){toast(d.msg||'Gespeichert.','success');setTimeout(function(){location.reload();},700);});
}
function vDelete(id){
  epConfirm('Diese Vertretung wirklich beenden?',function(){
    var fd=new FormData();fd.append('action','delete');fd.append('id',id);
    ajaxPost('vertretung',fd,function(d){toast(d.msg||'Beendet.','success');setTimeout(function(){location.reload();},600);});
  });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
