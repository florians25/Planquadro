<?php
$pageTitle = 'Gesamtplan';
require_once __DIR__ . '/../includes/header.php';
$y = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$m = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
if ($m<1){$m=12;$y--;} if ($m>12){$m=1;$y++;}
$days = daysInMonth($y,$m); $groups = usersByGroup($y,$m,false);
$myIds = array_flip(supervisedIdSet());
$showMineToggle = hasRole('manager','teamleiter');
$asgn = assignments($y,$m,true); $abs = absences($y,$m,true);
$pa = planAreaMap(); $hols = holidays($y,$m);
$lp = lastPublished($y,$m); $see = canSeeDetails();
$pm=$m-1;$py=$y;if($pm<1){$pm=12;$py--;}
$nm=$m+1;$ny=$y;if($nm>12){$nm=1;$ny++;}
$totalCols = count($days) + 2;
$cu=currentUser();
$sortedAreas=planAreas(); usort($sortedAreas, function($a,$b){return strcmp($a['short_code'],$b['short_code']);});
$approvalEnabled = getSetting('approval_system_enabled','0') === '1';
$canPropose = $approvalEnabled && !canEdit();
$showPending = $approvalEnabled;
?>

<div class="plan-bar no-print">
<div class="plan-bar-top">
<div class="plan-bar-filters" id="gpFilters">
<input type="text" id="filterName" class="inp-filter" placeholder="Suchen..." aria-label="Mitarbeitende suchen (Taste /)" title="Mitarbeitende suchen – Taste „/“ springt hierher, Esc leert" autocomplete="off">
<select id="filterRole" class="sel" data-action-change="applyFilters" aria-label="Nach Rolle filtern">
<option value="">Alle Rollen</option>
<option value="techniker">Techniker</option><option value="teamleiter">Teamleiter</option>
<option value="verwaltung_r">Verwaltung</option><option value="lager">Lager</option>
<option value="azubis">Alle Azubis</option>
<option value="azubi_1">Azubi 1. LJ</option><option value="azubi_2">Azubi 2. LJ</option><option value="azubi_3">Azubi 3. LJ</option>
</select>
<select id="filterArea" class="sel" data-action-change="applyFilters" aria-label="Nach Einsatzbereich filtern">
<option value="">Alle Bereiche</option>
<optgroup label="Kunden"><?php foreach(planAreas('kunde') as $fp):?><option value="<?php echo $fp['id'];?>"><?php echo e($fp['short_code'].' - '.$fp['name']);?></option><?php endforeach;?></optgroup>
<optgroup label="Allgemein"><?php foreach(planAreas('allgemein') as $fp):?><option value="<?php echo $fp['id'];?>"><?php echo e($fp['short_code'].' - '.$fp['name']);?></option><?php endforeach;?></optgroup>
</select>
<button type="button" id="sortToggle" class="sort-toggle-btn" data-mode="standard" data-action="toggleSort" title="Sortierung umschalten (Standard / A–Z)">
<svg viewBox="0 0 20 20" fill="currentColor" width="13" height="13" aria-hidden="true"><path d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h8a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 110 2H4a1 1 0 01-1-1z"/></svg>
<span id="sortToggleLabel">Standard</span>
</button>
<?php if($showMineToggle):?><button type="button" class="btn btn-sm btn-outline gp-mine-btn" id="mineToggleBtn" data-pq-filter="mine" aria-pressed="false" title="Nur meine Mitarbeiter anzeigen"><svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14" style="vertical-align:-2px" aria-hidden="true"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg> Meine Mitarbeiter</button><?php endif;?>
<button class="btn-reset" id="filterResetBtn" data-action="resetFilters" style="display:none" title="Filter zurücksetzen" aria-label="Filter zurücksetzen">&times;</button>
<span class="filter-count" id="filterCount" aria-live="polite"></span>
</div>
<div class="plan-bar-nav">
<a href="?year=<?php echo $py;?>&month=<?php echo $pm;?>" class="btn btn-outline">&laquo; <?php echo monthName($pm);?></a>
<div class="plan-bar-center"><span class="plan-bar-title"><?php echo monthName($m).' '.$y;?></span><a href="?year=<?php echo(int)date('Y');?>&month=<?php echo(int)date('m');?>" class="btn-today">Heute</a></div>
<a href="?year=<?php echo $ny;?>&month=<?php echo $nm;?>" class="btn btn-outline"><?php echo monthName($nm);?> &raquo;</a>
</div>
<div class="plan-bar-actions">
<?php if($canPropose):?>
<button class="btn btn-outline btn-propose-main" id="btnEnterProposal" data-action="enterProposalMode">Änderung beantragen</button>
<?php endif;?>
<button class="btn btn-outline" data-action="print">Drucken</button>
</div>
</div>
<?php if($lp):?><div class="plan-bar-status"><span class="badge bs">Stand: <?php echo date('d.m.Y H:i:s',strtotime($lp));?></span></div><?php endif;?>
</div>

<div class="print-only print-header"><span>Einsatzplan - <?php echo monthName($m).' '.$y; ?><?php if($lp):?> | Stand: <?php echo date('d.m.Y H:i',strtotime($lp));?><?php endif;?></span><span class="print-by">Gedruckt von: <?php echo e($cu['first_name'].' '.$cu['last_name']);?></span></div>
<div class="print-only print-filter" id="printFilter"></div>

<!-- Proposal Mode Banner + Toolbar -->
<div id="proposalBanner" class="proposal-banner" style="display:none">
<div class="proposal-banner-info">
<svg class="proposal-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
<b>Änderungen beantragen</b>
<span class="muted" id="proposalCount">0 Änderungen gesammelt</span>
</div>
<div class="proposal-banner-actions">
<button class="btn btn-sm btn-primary" id="btnSubmitProposals" data-action="submitProposals" disabled>Anträge absenden</button>
<button class="btn btn-sm btn-outline" data-action="exitProposalMode">Abbrechen</button>
</div>
</div>
<div id="proposalToolbar" class="tb no-print" style="display:none">
<div class="tb-row">
<div class="tbg"><label>Einsatz:</label>
<select id="propToolArea" class="sel sel-sm area-select">
<option value="">– Bereich –</option>
<optgroup label="Kunden"><?php foreach(planAreas('kunde') as $p):?>
<option value="<?php echo $p['id'];?>" data-c="<?php echo e($p['color']);?>" data-f="<?php echo e($p['fg_color']);?>" data-k="<?php echo e($p['short_code']);?>"><?php echo e($p['short_code'].' - '.$p['name']);?></option>
<?php endforeach;?></optgroup>
<optgroup label="Allgemein"><?php foreach(planAreas('allgemein') as $p):?>
<option value="<?php echo $p['id'];?>" data-c="<?php echo e($p['color']);?>" data-f="<?php echo e($p['fg_color']);?>" data-k="<?php echo e($p['short_code']);?>"><?php echo e($p['short_code'].' - '.$p['name']);?></option>
<?php endforeach;?></optgroup></select></div>
<div class="tb-sep"></div>
<div class="tbg"><label>Urlaub:</label>
<div class="abs-btn-grp"><button class="btn btn-xs prop-abs-btn" data-t="urlaub_genehmigt" style="background:#16a34a;color:#fff" title="Urlaub genehmigt">U</button><span class="abs-btn-label">Gen.</span></div>
<div class="abs-btn-grp"><button class="btn btn-xs prop-abs-btn" data-t="urlaub_ganz" style="background:#dc2626;color:#fff" title="Urlaub beantragt">U</button><span class="abs-btn-label">Beantr.</span></div>
<div class="abs-btn-grp"><button class="btn btn-xs prop-abs-btn" data-t="urlaub_halb" style="background:#fbbf24;color:#000" title="Urlaub halber Tag">u</button><span class="abs-btn-label">1/2 Tag</span></div>
</div>
<div class="tb-sep"></div>
<div class="tbg"><button class="btn btn-xs prop-abs-btn" data-t="gleitzeit" style="background:#8b5cf6;color:#fff" title="Gleitzeit">GL</button></div>
<div class="tb-sep"></div>
<div class="tbg"><span id="propToolSt" class="muted">Kein Werkzeug gewählt</span></div>
</div>
</div>

<div class="ps"><table class="pt" id="gpTbl">
<thead><tr class="hd-row">
<th class="sc cn">Mitarbeiter</th><th class="sc ca">Info</th>
<?php $lk=0; foreach($days as $d):
$kwn=kw($d);$dn=(int)date('j',strtotime($d));$wdn=wdShort($d);$we=isWE($d);$td=($d===date('Y-m-d'));
$hl=isset($hols[$d]); $hlc=''; $isFt=false;
if($hl){$isFt=$hols[$d]['holiday_type']==='feiertag'; $hlc=$isFt?'hl-ft':'hl-fe';}
$showKw=($kwn!==$lk);if($showKw)$lk=$kwn;
?><th class="cd <?php if($we) echo 'we '; if($td) echo 'td td-head '; echo $hlc; ?>" title="<?php echo $dn.'. '.monthName($m); if($hl) echo ' - '.e($hols[$d]['name']);?>">
<div class="kwl"><?php if($showKw) echo 'KW'.$kwn;?></div>
<div class="dn"><?php echo $dn;?></div><div class="wn"><?php echo $wdn;?></div>
<?php if($hl):?><div class="hol-lbl <?php echo $isFt?'hol-ft':'hol-fe';?>" title="<?php echo e($hols[$d]['name']);?>"><?php echo mb_substr($hols[$d]['name'],0,4);?></div><?php endif;?>
</th><?php endforeach;?>
</tr></thead>
<tbody>
<?php foreach($groups as $gn=>$gu): if(empty($gu)) continue; ?>
<tr class="gr" data-group="<?php echo e($gn);?>"><td class="gr-name"><?php echo e($gn);?></td><td class="gr-fill" colspan="<?php echo $totalCols-1;?>"></td></tr>
<?php foreach($gu as $u):
$weOk=(int)$u['weekend_allowed'];
$priArea = null;
if (!empty($u['primary_area_id'] ?? null) && isset($pa[$u['primary_area_id']])) $priArea = $pa[$u['primary_area_id']];
$userAreas = [];
if (isset($asgn[$u['id']])) foreach($asgn[$u['id']] as $dd=>$av) $userAreas[$av['plan_area_id']]=1;
$areasStr = ','.implode(',',array_keys($userAreas)).',';
$roleFilter = $u['role'];
if (in_array($u['role'],['manager','mitarbeiter'])) $roleFilter='techniker';
if ($u['role']==='verwaltung') $roleFilter='verwaltung_r';
$osLevel = $u['os_level'] ?? '';
$isMe = ($u['id'] == $cu['id']);
$absSee = canSeeAbsenceDetails($u['id']);
?>
<tr class="plan-row <?php if($weOk) echo 'we-allowed ';if($isMe) echo 'plan-row-me ';?>" data-uid="<?php echo $u['id'];?>" data-name="<?php echo e(mb_strtolower($u['last_name'].' '.$u['first_name']));?>" data-role="<?php echo e($roleFilter);?>" data-realrole="<?php echo e($u['role']);?>" data-areas="<?php echo e($areasStr);?>" data-me="<?php echo $isMe?1:0;?>" data-mine="<?php echo isset($myIds[$u['id']])?1:0;?>" data-group="<?php echo e($gn);?>">
<td class="sc cn"><?php echo e($u['last_name'].', '.$u['first_name']);?><?php if($u['role']==='teamleiter'):?><span class="role-tag role-tag-tl">TL</span><?php elseif($u['role']==='manager'):?><span class="role-tag role-tag-ma">MA</span><?php endif;?><?php if($weOk):?><span class="we-badge">WE</span><?php endif;?></td>
<td class="sc ca"><?php if($priArea):?><span class="ts" style="background:<?php echo e($priArea['color']);?>;color:<?php echo e($priArea['fg_color']);?>"><?php echo e($priArea['short_code']);?></span><?php endif;?><?php if($osLevel):?><span class="ts os-badge"><?php echo e($osLevel);?></span><?php endif;?><?php if(!empty($u['training_profession'])):?><span class="ts tp-badge"><?php echo e($u['training_profession']);?></span><?php endif;?></td>
<?php foreach($days as $d):
$we=isWE($d);$td=($d===date('Y-m-d'));
$hl=isset($hols[$d]); $hlc=''; $isFt=false; if($hl){$isFt=$hols[$d]['holiday_type']==='feiertag'; $hlc=$isFt?'hl-ft':'hl-fe';}
$entryBlocked=(!empty($u['entry_date']) && $d < $u['entry_date']);
$exitBlocked=(!empty($u['exit_date']) && $d > $u['exit_date']);
$cc='';$bg='';$fg='';$remark='';$remarkBy='';$remarkAt='';$ct='';$cv='';
if($entryBlocked||$exitBlocked){$cc='';$bg='';$fg='';} else {
if(isset($abs[$u['id']][$d])){$abt=$abs[$u['id']][$d]['absence_type'];$ct='absence';$cv=$abt;$abVisible=($absSee || !isConfidentialAbsence($abt));if($abVisible){$remark=$abs[$u['id']][$d]['remarks']??'';$remarkBy=$abs[$u['id']][$d]['remark_by']??'';$remarkAt=$abs[$u['id']][$d]['remark_at']??'';if($see){$cc=absenceLabel($abt);$bg=absenceColor($abt);$fg=absenceFg($abt);}else{$cc='A';$bg='#ef4444';$fg='#fff';}}else{$cc='A';$bg='#ef4444';$fg='#fff';$remark='';$remarkBy='';$remarkAt='';$cv='';}}
elseif(isset($asgn[$u['id']][$d])&&isset($pa[$asgn[$u['id']][$d]['plan_area_id']])){$p=$pa[$asgn[$u['id']][$d]['plan_area_id']];$cc=$p['short_code'];$bg=$p['color'];$fg=$p['fg_color'];$remark=$asgn[$u['id']][$d]['remarks']??'';$remarkBy=$asgn[$u['id']][$d]['remark_by']??'';$remarkAt=$asgn[$u['id']][$d]['remark_at']??'';$ct='assignment';$cv=$asgn[$u['id']][$d]['plan_area_id'];}
}
/* Only resolve author name when remark text exists */
$hasRemarkText=!empty(trim($remark));
$metaByName='';$metaAtFmt='';
if($hasRemarkText && $remarkBy){if(!isset($_gpUserNames)){$_gpUserNames=[];try{$_gpUs=getDB()->query('SELECT id,first_name,last_name FROM users');foreach($_gpUs->fetchAll() as $_gU)$_gpUserNames[$_gU['id']]=$_gU['first_name'].' '.$_gU['last_name'];}catch(Exception $e){}}$metaByName=$_gpUserNames[(int)$remarkBy]??'';}
if($hasRemarkText && $remarkAt) $metaAtFmt=date('d.m. H:i',strtotime($remarkAt));
$blocked = ($isFt||$we||$entryBlocked||$exitBlocked) ? 1 : 0;
$dateBlockTitle=$entryBlocked?'Person nicht verfügbar':($exitBlocked?'Person nicht verfügbar':'');
$isVacLock=($ct==='absence' && $cv==='urlaub_genehmigt');
?><td class="cd pc pe-c <?php if($we)echo'we ';if($we&&$weOk&&$bg)echo'we-filled ';if($td)echo'td ';echo $hlc;if($hasRemarkText)echo' has-remark has-pub-remark';if($entryBlocked||$exitBlocked)echo' cell-unavailable';?>"
data-u="<?php echo $u['id'];?>" data-d="<?php echo $d;?>" data-t="<?php echo $ct;?>" data-v="<?php echo $cv;?>" data-bl="<?php echo $blocked;?>"
<?php if($hasRemarkText):?> data-remark="<?php echo e($remark);?>"<?php endif;?>
<?php if($metaByName):?> data-remark-by="<?php echo e($metaByName);?>"<?php endif;?>
<?php if($metaAtFmt):?> data-remark-at="<?php echo e($metaAtFmt);?>"<?php endif;?>
<?php if($isVacLock):?> data-vaclock="1"<?php endif;?>
<?php if($bg):?> style="background:<?php echo e($bg);?><?php if($we) echo ' !important';?>;color:<?php echo e($fg);?>"<?php endif;?>><?php echo e($cc);?></td>
<?php endforeach;?>
</tr><?php endforeach; endforeach;?>
</tbody></table></div>
<?php $pqPlusPage = 'gesamtplan'; require __DIR__.'/../includes/planning_plus.php'; /* Planungshilfen (planning-plus.js) */ ?>
<?php require_once __DIR__.'/../includes/legend.php'; ?>

<?php if($showPending): ?>
<script nonce="<?php echo opsCspNonce(); ?>">
var GP_YEAR=<?php echo $y;?>, GP_MONTH=<?php echo $m;?>;
var GP_MY_ID=<?php echo (int)$cu['id'];?>;
var GP_CAN_PROPOSE=<?php echo $canPropose?'true':'false';?>;
var CAN_SEE_DETAILS=<?php echo $see?'true':'false';?>;

/* === Antragsmodus (Sammelauswahl mit Ziehen) === */
var proposalMode=false, propTool=null, collectedChanges=[];
var propDragging=false;
var propAbsTypes={urlaub_genehmigt:{code:'U',color:'#16a34a',fg:'#fff'},urlaub_ganz:{code:'U',color:'#dc2626',fg:'#fff'},urlaub_halb:{code:'u',color:'#fbbf24',fg:'#000'},gleitzeit:{code:'GL',color:'#8b5cf6',fg:'#fff'}};

function enterProposalMode() {
    proposalMode = true; propTool = null; collectedChanges = [];
    document.getElementById('proposalBanner').style.display = '';
    document.getElementById('proposalToolbar').style.display = '';
    document.getElementById('btnEnterProposal').style.display = 'none';
    document.getElementById('gpFilters').style.display = 'none';
    document.getElementById('gpTbl').classList.add('proposal-active');
    document.querySelectorAll('#gpTbl .plan-row').forEach(function(r) {
        r.style.display = (r.getAttribute('data-me') === '1') ? '' : 'none';
    });
    document.querySelectorAll('#gpTbl .gr').forEach(function(r){ r.style.display = 'none'; });
    initPropTools();
    updateProposalCount();
}
function exitProposalMode() {
    proposalMode = false; propTool = null; collectedChanges = [];
    document.getElementById('proposalBanner').style.display = 'none';
    document.getElementById('proposalToolbar').style.display = 'none';
    document.getElementById('btnEnterProposal').style.display = '';
    document.getElementById('gpFilters').style.display = '';
    document.getElementById('gpTbl').classList.remove('proposal-active');
    document.querySelectorAll('#gpTbl .plan-row').forEach(function(r){ r.style.display = ''; });
    document.querySelectorAll('#gpTbl .gr').forEach(function(r){ r.style.display = ''; });
    document.querySelectorAll('.cell-proposal-mark').forEach(function(c){ c.classList.remove('cell-proposal-mark'); c.removeAttribute('data-prop-code'); });
    applyFilters();
}

function initPropTools() {
    var sel = document.getElementById('propToolArea');
    sel.value = '';
    sel.onchange = function() {
        clearPropToolActive();
        var opt = sel.options[sel.selectedIndex];
        if (!sel.value) { propTool=null; updatePropSt(); return; }
        propTool = {type:'assignment', value:sel.value, color:opt.dataset.c, fg:opt.dataset.f, code:opt.dataset.k};
        updatePropSt();
    };
    document.querySelectorAll('.prop-abs-btn').forEach(function(btn){
        btn.onclick = function(){
            clearPropToolActive(); btn.classList.add('tool-active');
            var t=btn.dataset.t, def=propAbsTypes[t]||{};
            propTool={type:'absence',value:t,code:def.code||'A',color:def.color,fg:def.fg};
            sel.value=''; updatePropSt();
        };
    });
}
function clearPropToolActive(){document.querySelectorAll('.prop-abs-btn').forEach(function(b){b.classList.remove('tool-active');});}
function updatePropSt(){
    var st=document.getElementById('propToolSt');
    if(!propTool){st.textContent='Kein Werkzeug gew\u00e4hlt';st.className='muted';return;}
    st.innerHTML='<span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:'+propTool.color+';vertical-align:middle;margin-right:4px"></span> <b>'+propTool.code+'</b> \u2013 Klicke oder ziehe \u00fcber Tage';
    st.className='';
}
function updateProposalCount(){
    var n=collectedChanges.length;
    document.getElementById('proposalCount').textContent=n+' \u00c4nderung'+(n!==1?'en':'')+' gesammelt';
    document.getElementById('btnSubmitProposals').disabled=(n===0);
}

function addProposalCell(c) {
    if(!c||c.dataset.bl==='1') return;
    if(parseInt(c.dataset.u)!==GP_MY_ID) return;
    if(!propTool) return;
    var date=c.dataset.d;
    var existIdx=-1;
    collectedChanges.forEach(function(ch,i){if(ch.date===date)existIdx=i;});
    if(existIdx>=0) return; /* Bereits gesammelt beim Ziehen nicht nochmal */
    collectedChanges.push({user_id:GP_MY_ID, date:date, type:propTool.type, value:propTool.value, code:propTool.code, color:propTool.color, fg:propTool.fg});
    c.classList.add('cell-proposal-mark');
    c.setAttribute('data-prop-code', propTool.code);
    updateProposalCount();
}

/* Drag-Auswahl (wie bei Monatsplanung) */
document.addEventListener('mousedown', function(e){
    if(!proposalMode||e.button!==0) return;
    var c=e.target.closest('td[data-u][data-d]');
    if(!c||c.dataset.bl==='1') return;
    if(parseInt(c.dataset.u)!==GP_MY_ID) return;
    if(!propTool){return;}
    e.preventDefault(); propDragging=true;
    addProposalCell(c);
});
document.addEventListener('mouseover', function(e){
    if(!propDragging) return;
    var c=e.target.closest('td[data-u][data-d]');
    if(c) addProposalCell(c);
});
document.addEventListener('mouseup', function(){
    if(propDragging) propDragging=false;
});

function submitProposals(){
    if(!collectedChanges.length) return;
    epPrompt(collectedChanges.length+' \u00c4nderung(en) als Antrag einreichen.\nBemerkung (optional):', '', function(remark){
        /* Add remark to all items */
        var items = collectedChanges.map(function(c){
            var item = Object.assign({}, c);
            if(remark) item.remarks = remark;
            return item;
        });
        fetch(getApiBase()+'api/change_requests',{
            method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'submit_batch', items:items})
        }).then(function(r){return r.json();}).then(function(d){
            if(d.success){toast(d.msg,'success');exitProposalMode();loadPendingOverlay();}
            else toast('Fehler: '+(d.error||''),'error');
        }).catch(function(err){toast(err.message,'error');});
    });
}

/* Offene Antr\u00e4ge als Overlay anzeigen */
function loadPendingOverlay(){
    fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'pending_map',year:GP_YEAR,month:GP_MONTH})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success||!d.map)return;
        document.querySelectorAll('.cell-pending').forEach(function(c){c.classList.remove('cell-pending');});
        for(var key in d.map){
            var parts=key.split('_');var uid=parts[0],date=parts.slice(1).join('_');
            var cell=document.querySelector('td[data-u="'+uid+'"][data-d="'+date+'"]');
            if(cell)cell.classList.add('cell-pending');
        }
    }).catch(function(){});
}
document.addEventListener('DOMContentLoaded', function(){ loadPendingOverlay(); if(typeof initRemarkTooltip==='function') initRemarkTooltip(); });
</script>
<?php endif; ?>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
