<?php
$pageTitle='Urlaubsplanung';
require_once __DIR__.'/../includes/functions.php';
requireLogin();
if(!hasRole('admin','manager','teamleiter')){showAccessDenied('Urlaubs- und Krankheitsübersichten sind vertraulich und nur für Teamleiter, Manager und Administratoren zugänglich.');}
require_once __DIR__.'/../includes/header.php';
$y=isset($_GET['year'])?(int)$_GET['year']:(int)date('Y'); $users=planningUsers();
?>
<div class="ph"><div class="phl"><h1>Urlaubsplanung <?php echo $y;?></h1></div><div class="phr">
<a href="?year=<?php echo $y-1;?>" class="btn btn-sm btn-outline">&laquo;</a><a href="?year=<?php echo(int)date('Y');?>" class="btn btn-sm btn-outline">Aktuell</a><a href="?year=<?php echo $y+1;?>" class="btn btn-sm btn-outline">&raquo;</a>
<?php if(canEdit()):?><button class="btn btn-sm btn-primary" onclick="oMod('bulkVacMod')">Urlaub für ALLE</button><?php endif;?>
</div></div>
<div class="card"><div class="ch" style="display:flex;justify-content:space-between;align-items:center">
<span>Übersicht <?php echo $y;?></span>
<input type="text" class="inp-filter" id="urlaubSearch" placeholder="Mitarbeiter suchen..." oninput="filterUrlaub()" style="max-width:220px">
</div>
<div class="cb p0"><table class="tbl" id="urlaubTbl"><thead><tr><th>Mitarbeiter</th><th>Rolle</th><th>Anspruch</th><th>Genommen</th><th>Rest</th><th>Kranktage</th></tr></thead><tbody>
<?php foreach($users as $u): if($u['role']==='admin') continue; $ud=vacUsed($u['id'],$y); $rl=vacLeft($u['id'],$y); $sk=sickDays($u['id'],$y);?>
<tr data-name="<?php echo e(mb_strtolower($u['last_name'].' '.$u['first_name']));?>"><td><?php echo e($u['last_name'].', '.$u['first_name']);?></td>
<td><span class="rb r-<?php echo e($u['role']);?>"><?php echo e(roleName($u['role']));?></span></td>
<td><?php echo number_format((float)$u['vacation_days'],1,',','');?></td><td><?php echo number_format($ud,1,',','');?></td>
<td class="<?php echo $rl<0?'txt-d':($rl<5?'txt-w':'');?>"><b><?php echo number_format($rl,1,',','');?></b></td>
<td><?php echo number_format($sk,1,',','');?></td></tr>
<?php endforeach;?></tbody></table></div></div>

<?php if(canEdit()):?>
<!-- Bulk vacation modal -->
<div class="modal-bg" id="bulkVacMod"><div class="modal">
<div class="mh"><h3>Urlaub für ALLE eintragen</h3><button class="mx" onclick="cMod('bulkVacMod')">&times;</button></div>
<div class="mb">
<p class="modal-hint">Trägt für alle aktiven Mitarbeiter den gewählten Urlaubstyp im Zeitraum ein (z.B. Betriebsurlaub Weihnachten).</p>
<div class="fg2">
<div class="fg"><label>Von</label><input type="date" id="bvFrom"></div>
<div class="fg"><label>Bis</label><input type="date" id="bvTo"></div>
</div>
<div class="fg"><label>Typ</label><select class="sel" id="bvType">
<option value="urlaub_genehmigt">Urlaub (genehmigt)</option>
<option value="urlaub_ganz">Urlaub (beantragt)</option>
<option value="gleitzeit">Gleitzeit</option>
<option value="gesperrt">Gesperrt</option>
</select></div>
<label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;color:var(--mu)"><input type="checkbox" id="bvSkipHol" checked> Feiertage/Wochenenden überspringen</label>
</div>
<div class="mf"><button class="btn btn-outline" onclick="cMod('bulkVacMod')">Abbrechen</button><button class="btn btn-primary" onclick="saveBulkVac()">Für alle eintragen</button></div>
</div></div>
<script>
function saveBulkVac(){
    var from=document.getElementById('bvFrom').value,to=document.getElementById('bvTo').value,type=document.getElementById('bvType').value;
    var skipHol=document.getElementById('bvSkipHol').checked;
    if(!from||!to){toast('Zeitraum angeben','warning');return;}
    /* Build changes for all users */
    var allUsers=<?php
        $uids=[];foreach(planningUsers() as $pu) $uids[]=$pu['id'];
        echo json_encode($uids);
    ?>;
    var holidays=<?php
        $hd=[];$hs=getDB()->query("SELECT holiday_date FROM holidays WHERE holiday_type='feiertag'");
        foreach($hs->fetchAll() as $h)$hd[]=$h['holiday_date'];echo json_encode($hd);
    ?>;
    var changes=[];var d=new Date(from+'T12:00:00');var end=new Date(to+'T12:00:00');
    while(d<=end){
        var dow=d.getDay();var ds=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2);
        var isWe=(dow===0||dow===6);var isHol=holidays.indexOf(ds)!==-1;
        if(!skipHol||(skipHol&&!isWe&&!isHol)){
            allUsers.forEach(function(uid){changes.push({user_id:uid,date:ds,type:'absence',value:type});});
        }
        d.setDate(d.getDate()+1);
    }
    if(changes.length===0){toast('Keine Tage','warning');return;}
    epConfirm(changes.length+' Einträge für '+allUsers.length+' Mitarbeiter anlegen?',function(){
        fetch(getApiBase()+'api/save_bulk',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({changes:changes})})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){toast(d.count+' Einträge gespeichert','success');cMod('bulkVacMod');}
            else toast('Fehler: '+(d.error||''),'error');
        }).catch(function(e){toast(e.message,'error');});
    });
}
</script>
<?php endif;?>
<script>
function filterUrlaub(){
    var q=(document.getElementById('urlaubSearch').value||'').toLowerCase().trim();
    document.querySelectorAll('#urlaubTbl tbody tr').forEach(function(r){
        var n=r.getAttribute('data-name')||'';
        r.style.display=(!q||n.indexOf(q)!==-1)?'':'none';
    });
}
</script>
<?php require_once __DIR__.'/../includes/footer.php';?>
