<?php
/* !! AJAX HANDLER FIRST !! */
require_once __DIR__ . '/../includes/functions.php';
requireLogin(); requireConfigManager();
$db = getDB();
$y=isset($_GET['year'])?(int)$_GET['year']:(int)date('Y');

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act=$_POST['action']??'';
    try {
        if($act==='save'){$n=trim($_POST['name']??'');$d=$_POST['holiday_date']??'';$t=$_POST['holiday_type']??'feiertag';$id=(int)($_POST['id']??0);
            if($n&&$d){if($id>0)$db->prepare('UPDATE holidays SET name=?,holiday_date=?,holiday_type=? WHERE id=?')->execute([$n,$d,$t,$id]);
            else $db->prepare("INSERT INTO holidays(name,holiday_date,holiday_type)VALUES(?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),holiday_type=VALUES(holiday_type)")->execute([$n,$d,$t]);
            echo json_encode(['success'=>true,'msg'=>'Gespeichert.']);}else echo json_encode(['success'=>false,'error'=>'Name und Datum nötig.']);exit;}
        if($act==='range'){$n=trim($_POST['name']??'');$f=$_POST['date_from']??'';$t2=$_POST['date_to']??'';$tp=$_POST['holiday_type']??'ferien';
            if($n&&$f&&$t2){$d=$f;$c=0;while($d<=$t2&&$c<200){$db->prepare("INSERT INTO holidays(name,holiday_date,holiday_type)VALUES(?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),holiday_type=VALUES(holiday_type)")->execute([$n,$d,$tp]);$d=date('Y-m-d',strtotime($d.' +1 day'));$c++;}
            echo json_encode(['success'=>true,'msg'=>$c.' Tage eingetragen.']);}else echo json_encode(['success'=>false,'error'=>'Alle Felder nötig.']);exit;}
        if($act==='del'){$db->prepare('DELETE FROM holidays WHERE id=?')->execute([(int)$_POST['id']]);echo json_encode(['success'=>true,'msg'=>'Gelöscht.']);exit;}
        if($act==='del_range'){$name=trim($_POST['name']??'');$tp=$_POST['holiday_type']??'';$st=$db->prepare('DELETE FROM holidays WHERE name=? AND holiday_type=? AND YEAR(holiday_date)=?');$st->execute([$name,$tp,$y]);echo json_encode(['success'=>true,'msg'=>'Zeitraum gelöscht ('.$st->rowCount().' Tage).']);exit;}
        if($act==='import_api'){
            $impYear=(int)($_POST['import_year']??$y);$land=trim($_POST['bundesland']??'NW');
            $url="https://feiertage-api.de/api/?jahr={$impYear}&nur_land={$land}";
            $ctx=stream_context_create(['http'=>['timeout'=>10,'header'=>"Accept: application/json\r\n"]]);
            $json=@file_get_contents($url,false,$ctx);
            if(!$json){echo json_encode(['success'=>false,'error'=>'API nicht erreichbar. Bitte manuell eintragen.']);exit;}
            $data=json_decode($json,true);
            if(!$data||!is_array($data)){echo json_encode(['success'=>false,'error'=>'Ungültige API-Antwort']);exit;}
            $c=0;
            foreach($data as $name=>$info){
                $date=$info['datum']??'';
                if(!$date)continue;
                /* Feiertag ALWAYS takes precedence - override even if ferien exists */
                $db->prepare("INSERT INTO holidays(name,holiday_date,holiday_type,source)VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),holiday_type='feiertag',source='api_feiertag'")->execute([$name,$date,'feiertag','api_feiertag']);
                $c++;
            }
            echo json_encode(['success'=>true,'msg'=>$c.' Feiertage für '.$impYear.' ('.$land.') importiert. Feiertage haben Vorrang vor Ferien.']);exit;
        }
        if($act==='import_ferien'){
            $impYear=(int)($_POST['import_year']??$y);$land=strtoupper(trim($_POST['bundesland']??'NW'));
            $url="https://ferien-api.de/api/v1/holidays/{$land}/{$impYear}";
            $ctx=stream_context_create(['http'=>['timeout'=>10,'header'=>"Accept: application/json\r\n"]]);
            $json=@file_get_contents($url,false,$ctx);
            if(!$json){echo json_encode(['success'=>false,'error'=>'Ferien-API nicht erreichbar.']);exit;}
            $data=json_decode($json,true);
            if(!$data||!is_array($data)){echo json_encode(['success'=>false,'error'=>'Ungültige API-Antwort']);exit;}
            $added=0;$skipped=0;
            $chk=$db->prepare('SELECT id,holiday_type,name FROM holidays WHERE holiday_date=?');
            $ins=$db->prepare("INSERT INTO holidays(name,holiday_date,holiday_type,source)VALUES(?,?,?,?)");
            foreach($data as $fer){
                $name=trim($fer['name']??'');$start=$fer['start']??'';$end=$fer['end']??'';
                if(!$name||!$start)continue;
                $name=mb_convert_case($name,MB_CASE_TITLE,'UTF-8');
                $startDate=date('Y-m-d',strtotime($start));
                $endDate=$end?date('Y-m-d',strtotime($end.' -1 day')):$startDate;
                $d=$startDate;$safety=0;
                while($d<=$endDate&&$safety<60){
                    $chk->execute([$d]);$ex=$chk->fetch();
                    if(!$ex){
                        $ins->execute([$name,$d,'ferien','api_ferien']);$added++;
                    } else {
                        $skipped++; /* Already exists (feiertag or same ferien) – skip */
                    }
                    $d=date('Y-m-d',strtotime($d.' +1 day'));$safety++;
                }
            }
            echo json_encode(['success'=>true,'msg'=>$added.' Ferientage neu importiert, '.$skipped.' übersprungen (bereits vorhanden).']);exit;
        }
    } catch(Exception $e){opsApiFail($e, 'feiertage', ['success'=>false,'error'=>opsPublicMessage($e)]);}exit;
}

/* ── Page ── */
$pageTitle='Feiertage';
require_once __DIR__.'/../includes/header.php';

$hs=$db->prepare('SELECT * FROM holidays WHERE YEAR(holiday_date)=? ORDER BY holiday_date');$hs->execute([$y]);$hs=$hs->fetchAll();
$feiertage=[];$ferienRanges=[];$curRange=null;
foreach($hs as $h){
    if($h['holiday_type']==='feiertag'){$feiertage[]=$h;}
    else{if($curRange&&$curRange['name']===$h['name']&&$curRange['type']===$h['holiday_type']&&date('Y-m-d',strtotime($curRange['end'].' +1 day'))===$h['holiday_date']){$curRange['end']=$h['holiday_date'];$curRange['count']++;}
    else{if($curRange)$ferienRanges[]=$curRange;$curRange=['name'=>$h['name'],'type'=>$h['holiday_type'],'start'=>$h['holiday_date'],'end'=>$h['holiday_date'],'count'=>1];}}
}if($curRange)$ferienRanges[]=$curRange;
?>
<div class="ph"><h1>Feiertage & Ferien <?php echo $y;?></h1><div class="phr">
<a href="?year=<?php echo $y-1;?>" class="btn btn-sm btn-outline">&laquo; <?php echo $y-1;?></a>
<a href="?year=<?php echo $y+1;?>" class="btn btn-sm btn-outline"><?php echo $y+1;?> &raquo;</a>
<button class="btn btn-sm btn-primary" data-action="oMod" data-args='["holMod"]'>Feiertag</button>
<button class="btn btn-sm btn-primary" data-action="oMod" data-args='["rangeMod"]'>Ferien-Zeitraum</button>
<button class="btn btn-sm btn-outline" data-action="importHolidays">Feiertage importieren</button>
<button class="btn btn-sm btn-outline" data-action="importFerien">Ferien importieren</button></div></div>

<div class="card mb"><div class="ch">Feiertage</div><div class="cb p0"><table class="tbl"><thead><tr><th>Datum</th><th>Tag</th><th>Name</th><th>Aktionen</th></tr></thead><tbody>
<?php foreach($feiertage as $h):?><tr>
<td><?php echo date('d.m.Y',strtotime($h['holiday_date']));?></td><td><?php echo wdShort($h['holiday_date']);?></td>
<td><?php echo e($h['name']);?></td>
<td class="tbl-acts"><button class="btn btn-xs btn-outline" <?php echo opsActionAttr('oHolMod',[$h]);?>>Bearbeiten</button>
<button class="btn btn-xs btn-outline btn-danger-text" <?php echo opsActionAttr('delHol',[(int)$h['id']]);?>>Entfernen</button></td>
</tr><?php endforeach;?><?php if(empty($feiertage)):?><tr><td colspan="4" class="muted tc">Keine Feiertage</td></tr><?php endif;?>
</tbody></table></div></div>

<div class="card mb"><div class="ch">Ferien (Zeiträume)</div><div class="cb p0"><table class="tbl"><thead><tr><th>Zeitraum</th><th>Name</th><th>Tage</th><th>Aktionen</th></tr></thead><tbody>
<?php foreach($ferienRanges as $fr):?><tr>
<td><?php echo date('d.m.',strtotime($fr['start'])).' – '.date('d.m.Y',strtotime($fr['end']));?></td>
<td><?php echo e($fr['name']);?></td>
<td><span class="badge bi"><?php echo $fr['count'];?> Tage</span></td>
<td class="tbl-acts"><button class="btn btn-xs btn-outline" <?php echo opsActionAttr('oRangeMod',[$fr['name'],$fr['type'],$fr['start'],$fr['end']]);?>>Bearbeiten</button>
<button class="btn btn-xs btn-outline btn-danger-text" <?php echo opsActionAttr('delRange',[$fr['name'],$fr['type']]);?>>Entfernen</button></td>
</tr><?php endforeach;?><?php if(empty($ferienRanges)):?><tr><td colspan="4" class="muted tc">Keine Ferien</td></tr><?php endif;?>
</tbody></table></div></div>

<div class="modal-bg" id="holMod"><div class="modal"><div class="mh"><h3 id="hmT">Feiertag</h3><button class="mx" data-action="cMod" data-args='["holMod"]'>&times;</button></div>
<input type="hidden" id="hId" value="0">
<div class="mb"><div class="fg"><label>Datum</label><input type="date" id="hD" required></div>
<div class="fg"><label>Name</label><input type="text" id="hN" required></div>
<div class="fg"><label>Typ</label><select id="hT"><option value="feiertag">Feiertag</option><option value="ferien">Ferien</option></select></div></div>
<div class="mf"><button class="btn btn-outline" data-action="cMod" data-args='["holMod"]'>Abbrechen</button><button class="btn btn-primary" data-action="saveHol">Speichern</button></div></div></div>

<div class="modal-bg" id="rangeMod"><div class="modal"><div class="mh"><h3>Ferien-Zeitraum</h3><button class="mx" data-action="cMod" data-args='["rangeMod"]'>&times;</button></div>
<div class="mb"><div class="fg"><label>Name</label><input type="text" id="rN" required placeholder="z.B. Osterferien"></div>
<div class="fg2"><div class="fg"><label>Von</label><input type="date" id="rF" required></div><div class="fg"><label>Bis</label><input type="date" id="rT" required></div></div>
<div class="fg"><label>Typ</label><select id="rTp"><option value="ferien">Ferien</option><option value="feiertag">Feiertag</option></select></div></div>
<div class="mf"><button class="btn btn-outline" data-action="cMod" data-args='["rangeMod"]'>Abbrechen</button><button class="btn btn-primary" data-action="saveRange">Eintragen</button></div></div></div>

<script nonce="<?php echo opsCspNonce(); ?>">
function oHolMod(h){document.getElementById('hmT').textContent=h?'Bearbeiten':'Feiertag';document.getElementById('hId').value=h?h.id:0;document.getElementById('hD').value=h?h.holiday_date:'';document.getElementById('hN').value=h?h.name:'';document.getElementById('hT').value=h?h.holiday_type:'feiertag';oMod('holMod');}
function oRangeMod(name,type,start,end){document.getElementById('rN').value=name;document.getElementById('rTp').value=type;document.getElementById('rF').value=start;document.getElementById('rT').value=end;oMod('rangeMod');}
function saveHol(){var fd=new FormData();fd.append('action','save');fd.append('id',document.getElementById('hId').value);fd.append('holiday_date',document.getElementById('hD').value);fd.append('name',document.getElementById('hN').value);fd.append('holiday_type',document.getElementById('hT').value);ajaxPost('feiertage?year=<?php echo $y;?>',fd,function(){cMod('holMod');setTimeout(function(){location.reload();},600);});}
function saveRange(){var name=document.getElementById('rN').value.trim();if(!name){toast('Name eingeben','warning');return;}var fd0=new FormData();fd0.append('action','del_range');fd0.append('name',name);fd0.append('holiday_type',document.getElementById('rTp').value);fetch('feiertage?year=<?php echo $y;?>',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd0}).then(function(){var fd=new FormData();fd.append('action','range');fd.append('name',name);fd.append('date_from',document.getElementById('rF').value);fd.append('date_to',document.getElementById('rT').value);fd.append('holiday_type',document.getElementById('rTp').value);ajaxPost('feiertage?year=<?php echo $y;?>',fd,function(){cMod('rangeMod');setTimeout(function(){location.reload();},600);});});}
function delHol(id){epConfirm('Feiertag entfernen?',function(){var fd=new FormData();fd.append('action','del');fd.append('id',id);ajaxPost('feiertage?year=<?php echo $y;?>',fd,function(){setTimeout(function(){location.reload();},600);});});}
function delRange(n,t){epConfirm('Ferien-Zeitraum entfernen?',function(){var fd=new FormData();fd.append('action','del_range');fd.append('name',n);fd.append('holiday_type',t);ajaxPost('feiertage?year=<?php echo $y;?>',fd,function(){setTimeout(function(){location.reload();},600);});});}
function importHolidays(){
    epConfirm('Feiertage für <?php echo $y;?> (NRW) von API importieren?',function(){
        var fd=new FormData();fd.append('action','import_api');fd.append('import_year','<?php echo $y;?>');fd.append('bundesland','NW');
        ajaxPost('feiertage?year=<?php echo $y;?>',fd,function(){setTimeout(function(){location.reload();},600);});
    });
}
function importFerien(){
    epConfirm('Schulferien für <?php echo $y;?> (NRW) automatisch importieren?',function(){
        var fd=new FormData();fd.append('action','import_ferien');fd.append('import_year','<?php echo $y;?>');fd.append('bundesland','NW');
        ajaxPost('feiertage?year=<?php echo $y;?>',fd,function(){setTimeout(function(){location.reload();},600);});
    });
}
</script>
<?php require_once __DIR__.'/../includes/footer.php';?>
