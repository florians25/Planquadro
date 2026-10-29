<?php
/* !! AJAX HANDLER FIRST !! */
require_once __DIR__ . '/../includes/functions.php';
requireLogin(); requireConfigManager();
$db = getDB();

/**
 * Normalisiert die Sortierung eines Typs auf eine lückenlose Folge 1..N.
 * Dadurch sind doppelte oder fehlende Sortierwerte ausgeschlossen (Autosortierung).
 */
function normalizeAreaSort($db, $type) {
    $rows = $db->prepare('SELECT id FROM plan_areas WHERE area_type=? ORDER BY sort_order, name');
    $rows->execute([$type]);
    $upd = $db->prepare('UPDATE plan_areas SET sort_order=? WHERE id=?');
    $i = 1;
    foreach ($rows->fetchAll() as $r) { $upd->execute([$i++, $r['id']]); }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act=$_POST['action']??'';
    try {
        if ($act==='save') {
            $id=(int)($_POST['id']??0);$n=trim($_POST['name']??'');$c=strtoupper(trim($_POST['short_code']??''));
            $bg=$_POST['color']??'#3b82f6';$fg=$_POST['fg_color']??'#ffffff';$tp=$_POST['area_type']??'kunde';
            if(!in_array($tp,['kunde','allgemein'],true)) $tp='kunde';
            if($n&&$c){
                if($id>0){
                    $db->prepare('UPDATE plan_areas SET name=?,short_code=?,color=?,fg_color=?,area_type=? WHERE id=?')
                       ->execute([$n,$c,$bg,$fg,$tp,$id]);
                } else {
                    /* Neuer Bereich wird automatisch ans Ende seines Typs einsortiert. */
                    $mx=$db->prepare('SELECT COALESCE(MAX(sort_order),0)+1 AS nx FROM plan_areas WHERE area_type=?');
                    $mx->execute([$tp]); $nx=(int)$mx->fetch()['nx'];
                    $db->prepare('INSERT INTO plan_areas(name,short_code,color,fg_color,area_type,sort_order)VALUES(?,?,?,?,?,?)')
                       ->execute([$n,$c,$bg,$fg,$tp,$nx]);
                }
                /* Beide Typen normalisieren – deckt auch einen Typwechsel beim Bearbeiten ab. */
                normalizeAreaSort($db,'kunde'); normalizeAreaSort($db,'allgemein');
                echo json_encode(['success'=>true,'msg'=>'Bereich gespeichert.']);
            }
            else echo json_encode(['success'=>false,'error'=>'Name und Kürzel erforderlich.']);exit;
        }
        if ($act==='del'){
            $db->prepare('DELETE FROM plan_areas WHERE id=?')->execute([(int)$_POST['id']]);
            normalizeAreaSort($db,'kunde'); normalizeAreaSort($db,'allgemein');
            echo json_encode(['success'=>true,'msg'=>'Gelöscht.']);exit;
        }
        if ($act==='reorder'){
            $id=(int)($_POST['id']??0);$dir=($_POST['dir']??'')==='up'?'up':'down';
            $cur=$db->prepare('SELECT id, area_type, sort_order FROM plan_areas WHERE id=?');$cur->execute([$id]);
            if($a=$cur->fetch()){
                $type=$a['area_type'];
                normalizeAreaSort($db,$type);
                $cur->execute([$id]); $a=$cur->fetch();
                $so=(int)$a['sort_order'];
                if($dir==='up') $q=$db->prepare('SELECT id, sort_order FROM plan_areas WHERE area_type=? AND sort_order<? ORDER BY sort_order DESC LIMIT 1');
                else            $q=$db->prepare('SELECT id, sort_order FROM plan_areas WHERE area_type=? AND sort_order>? ORDER BY sort_order ASC LIMIT 1');
                $q->execute([$type,$so]);
                if($nb=$q->fetch()){
                    $db->prepare('UPDATE plan_areas SET sort_order=? WHERE id=?')->execute([(int)$nb['sort_order'],$a['id']]);
                    $db->prepare('UPDATE plan_areas SET sort_order=? WHERE id=?')->execute([$so,$nb['id']]);
                }
            }
            echo json_encode(['success'=>true]);exit;
        }
    } catch(Exception $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}exit;
}

/* ── Page ── */
$pageTitle = 'Planbereiche';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="ph"><h1>Planbereiche</h1><button class="btn btn-sm btn-primary" onclick="oAreaMod()">Neuer Bereich</button></div>
<p class="muted" style="margin:-.25rem 0 .75rem;font-size:.82rem">Die Reihenfolge wird automatisch vergeben (keine doppelten Werte). Zum Umsortieren die Pfeile nutzen.</p>
<?php foreach([['Kunden',planAreas('kunde')],['Allgemein',planAreas('allgemein')]] as [$title,$list]):?>
<div class="card mb"><div class="ch"><?php echo $title;?></div><div class="cb p0">
<table class="tbl"><thead><tr><th style="width:110px">Reihenfolge</th><th>Kürzel</th><th>Name</th><th>Vorschau</th><th>Aktionen</th></tr></thead><tbody>
<?php $cnt=count($list); foreach($list as $idx=>$a):?>
<tr>
<td><div class="area-sort-cell"><span class="area-sort-no"><?php echo $a['sort_order'];?></span>
<span class="area-sort-btns">
<button class="btn btn-xs btn-outline area-sort-btn" title="Nach oben" onclick="moveArea(<?php echo $a['id'];?>,'up')"<?php if($idx===0)echo ' disabled';?>>&#9650;</button>
<button class="btn btn-xs btn-outline area-sort-btn" title="Nach unten" onclick="moveArea(<?php echo $a['id'];?>,'down')"<?php if($idx===$cnt-1)echo ' disabled';?>>&#9660;</button>
</span></div></td>
<td><b><?php echo e($a['short_code']);?></b></td><td><?php echo e($a['name']);?></td>
<td><span class="tag" style="background:<?php echo e($a['color']);?>;color:<?php echo e($a['fg_color']);?>"><?php echo e($a['short_code']);?></span></td>
<td class="tbl-acts">
<button class="btn btn-xs btn-outline" onclick='oAreaMod(<?php echo json_encode($a,JSON_HEX_APOS|JSON_HEX_QUOT);?>)'>Bearbeiten</button>
<button class="btn btn-xs btn-outline btn-danger-text" onclick="delArea(<?php echo $a['id'];?>)">Löschen</button>
</td></tr>
<?php endforeach;?><?php if(empty($list)):?><tr><td colspan="5" class="muted tc">Keine Eintraege</td></tr><?php endif;?>
</tbody></table></div></div>
<?php endforeach;?>

<div class="modal-bg" id="areaMod"><div class="modal">
<div class="mh"><h3 id="amT">Planbereich</h3><button class="mx" onclick="cMod('areaMod')">&times;</button></div>
<input type="hidden" id="aId" value="0">
<div class="mb"><div class="fg2">
<div class="fg"><label>Name</label><input type="text" id="aN" required></div>
<div class="fg"><label>Kürzel</label><input type="text" id="aC" required maxlength="10"></div>
<div class="fg"><label>Hintergrundfarbe</label><input type="color" id="aBg" value="#3b82f6"></div>
<div class="fg"><label>Textfarbe</label><input type="color" id="aFg" value="#ffffff"></div>
<div class="fg"><label>Typ</label><select id="aTp"><option value="kunde">Kunde</option><option value="allgemein">Allgemein</option></select></div>
</div>
<p class="muted" style="font-size:.78rem;margin-top:.5rem">Die Reihenfolge wird automatisch vergeben. Neue Bereiche landen am Ende und lassen sich in der Liste per Pfeil verschieben.</p>
</div>
<div class="mf"><button class="btn btn-outline" onclick="cMod('areaMod')">Abbrechen</button><button class="btn btn-primary" onclick="saveArea()">Speichern</button></div>
</div></div>
<script>
function oAreaMod(a){document.getElementById('amT').textContent=a?'Bearbeiten: '+a.short_code:'Neuer Planbereich';document.getElementById('aId').value=a?a.id:0;document.getElementById('aN').value=a?a.name:'';document.getElementById('aC').value=a?a.short_code:'';document.getElementById('aBg').value=a?a.color:'#3b82f6';document.getElementById('aFg').value=a?a.fg_color:'#ffffff';document.getElementById('aTp').value=a?a.area_type:'kunde';oMod('areaMod');}
function saveArea(){var fd=new FormData();fd.append('action','save');fd.append('id',document.getElementById('aId').value);fd.append('name',document.getElementById('aN').value);fd.append('short_code',document.getElementById('aC').value);fd.append('color',document.getElementById('aBg').value);fd.append('fg_color',document.getElementById('aFg').value);fd.append('area_type',document.getElementById('aTp').value);ajaxPost('planbereiche',fd,function(){cMod('areaMod');setTimeout(function(){location.reload();},600);});}
function delArea(id){epConfirm('Bereich wirklich löschen?',function(){var fd=new FormData();fd.append('action','del');fd.append('id',id);ajaxPost('planbereiche',fd,function(){setTimeout(function(){location.reload();},600);});});}
function moveArea(id,dir){var fd=new FormData();fd.append('action','reorder');fd.append('id',id);fd.append('dir',dir);ajaxPost('planbereiche',fd,function(){location.reload();});}
</script>
<?php require_once __DIR__.'/../includes/footer.php';?>
