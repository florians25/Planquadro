<?php
$pageTitle='Jahresübersicht';
require_once __DIR__.'/../includes/header.php';
requireModule('annual');
$y=isset($_GET['year'])?(int)$_GET['year']:(int)date('Y');
$users=planningUsers(); $see=canSeeDetails(); $hols=holidays($y);
$cu=currentUser();
$pam=planAreaMap(); $allA=[]; $allAb=[];
for($mm=1;$mm<=12;$mm++){
    foreach(assignments($y,$mm,true) as $uid=>$dd) foreach($dd as $d=>$v) $allA[$uid][$d]=$v;
    foreach(absences($y,$mm,true) as $uid=>$dd) foreach($dd as $d=>$v) $allAb[$uid][$d]=$v;
}
$pub=[];$lch=[];for($mm=1;$mm<=12;$mm++){$pub[$mm]=lastPublished($y,$mm);$lch[$mm]=lastChange($y,$mm);}
$groups=usersByGroup($y, 0);
/* Filter: hide users who exited before this year */
foreach($groups as $gn=>$gu) {
    $groups[$gn] = array_filter($gu, function($u) use ($y) {
        if (empty($u['exit_date'])) return true;
        $exitYear = (int)date('Y', strtotime($u['exit_date']));
        return $exitYear >= $y; /* Show in exit year, hide from next year */
    });
    if (empty($groups[$gn])) unset($groups[$gn]);
}
?>

<!-- Combined Plan Bar -->
<div class="plan-bar no-print">
<div class="plan-bar-top">
<div class="plan-bar-nav">
<a href="?year=<?php echo $y-1;?>" class="btn btn-outline">&laquo; <?php echo $y-1;?></a>
<span class="plan-bar-title"><?php echo $y;?></span>
<a href="?year=<?php echo $y+1;?>" class="btn btn-outline"><?php echo $y+1;?> &raquo;</a>
</div>
<div class="plan-bar-filters">
<input type="text" class="inp-filter" placeholder="Suchen..." data-action-input="filterPlan" data-args='["$value"]'>
</div>
<div class="plan-bar-actions">
<?php if(canEdit()):?><button class="btn btn-outline" data-action="oMod" data-args='["yearMod"]'>Jahreseinsatz planen</button><?php endif;?>
<?php if(hasPerm('plan_publish_year')):?><button class="btn btn-outline" style="border-color:var(--p);color:var(--p)" <?php echo opsActionAttr('publishYear',[(int)$y]);?>>Jahr <?php echo $y;?> veröffentlichen</button><?php endif;?>
<button class="btn btn-outline" data-action="print">Drucken</button>
</div>
</div>
</div>

<!-- Year Tiles: 6 columns, show status + last update -->
<div class="yr-tiles no-print">
<?php for($mm=1;$mm<=12;$mm++):
$ic=($y===(int)date('Y')&&$mm===(int)date('m'));
$lp=$pub[$mm]; $lc=$lch[$mm];
$hasChanges=($lc&&(!$lp||$lc>$lp));
?>
<div class="yr-tile<?php echo $ic?' yr-tile-cur':'';?>">
<div class="yr-tile-head">
<?php echo monthName($mm);?>
<?php if($lp):?><span class="badge bs bxs">Veröff.</span>
<?php elseif($hasChanges):?><span class="badge bw bxs">Entwurf</span>
<?php else:?><span class="badge bm bxs">Leer</span><?php endif;?>
</div>
<div class="yr-tile-body">
<a href="gesamtplan?year=<?php echo $y;?>&month=<?php echo $mm;?>" class="btn btn-xs btn-outline">Ansehen</a>
<?php if(canEdit()):?><a href="monatsplanung?year=<?php echo $y;?>&month=<?php echo $mm;?>" class="btn btn-xs btn-primary">Planen</a><?php endif;?>
<?php if($lp):?><div class="yr-tile-meta">Veröff.: <?php echo date('d.m.y H:i',strtotime($lp));?></div>
<?php elseif($lc):?><div class="yr-tile-meta">Geändert: <?php echo date('d.m.y H:i',strtotime($lc));?></div>
<?php endif;?>
</div>
</div>
<?php endfor;?>
</div>

<div class="print-only print-header"><span>Jahresübersicht <?php echo $y;?></span><span class="print-by">Gedruckt von: <?php echo e($cu['first_name'].' '.$cu['last_name']);?></span></div>

<div class="ps ps-yr"><table class="pt pt-yr" id="planTbl">
<thead>
<tr class="yr-month-row">
<th class="sc cn yr-corner" rowspan="2">Mitarbeiter</th>
<?php for($mm=1;$mm<=12;$mm++){$dc=count(daysInMonth($y,$mm));
$isCur=($y===(int)date('Y')&&$mm===(int)date('m'));?>
<th colspan="<?php echo $dc;?>" class="yr-ml <?php echo $isCur?'yr-ml-cur':'';?>"><?php echo monthName($mm);?></th>
<?php }?>
</tr>
<tr class="yr-day-row">
<?php for($mm=1;$mm<=12;$mm++){$dd=daysInMonth($y,$mm);foreach($dd as $d){
$dn=(int)date('j',strtotime($d));$we=isWE($d);$td=($d===date('Y-m-d'));
$hl=isset($hols[$d]);$isFt=$hl&&$hols[$d]['holiday_type']==='feiertag';
$hlc='';if($isFt)$hlc='hl-ft';elseif($hl)$hlc='hl-fe';
$f=($dn===1);
echo '<th class="cy';
if($we) echo ' we'; if($td) echo ' td'; if($hlc) echo ' '.$hlc; if($f) echo ' xb';
echo '" title="'.$dn.'. '.monthName($mm);
if($hl) echo ' – '.e($hols[$d]['name']);
echo '">'.$dn.'</th>';}}?>
</tr>
</thead>
<tbody>
<?php foreach($groups as $gn=>$gu): if(empty($gu)) continue; ?>
<tr class="gr"><td colspan="999"><?php echo e($gn);?></td></tr>
<?php foreach($gu as $u):?>
<tr class="plan-row" data-name="<?php echo e(mb_strtolower($u['last_name'].' '.$u['first_name']));?>">
<td class="sc cn"><?php echo e($u['last_name'].', '.mb_substr($u['first_name'],0,1).'.');?></td>
<?php for($mm=1;$mm<=12;$mm++){$dd=daysInMonth($y,$mm);foreach($dd as $d){
$we=isWE($d);$td=($d===date('Y-m-d'));
$hl=isset($hols[$d]);$isFt=$hl&&$hols[$d]['holiday_type']==='feiertag';
$hlc='';if($isFt)$hlc='hl-ft';elseif($hl)$hlc='hl-fe';
$f=((int)date('j',strtotime($d))===1);
$bg='';$fg='';$label='';
$entryBlocked=(!empty($u['entry_date']) && $d < $u['entry_date']);
$exitBlocked=(!empty($u['exit_date']) && $d > $u['exit_date']);
if($entryBlocked||$exitBlocked){$bg='';$fg='';$label='';}
elseif(isset($allAb[$u['id']][$d])){
$abt=$allAb[$u['id']][$d]['absence_type'];
$showDetail=$see && (canSeeAbsenceDetails($u['id']) || !isConfidentialAbsence($abt));
if($showDetail){$bg=absenceColor($abt);$fg=absenceFg($abt);$label=absenceLabel($abt);}
elseif(canSeeAbsenceDetails($u['id'])||!isConfidentialAbsence($abt)){$bg='#ef4444';$fg='#fff';$label='A';}
else{$bg='#ef4444';$fg='#fff';$label='A';}}
elseif(isset($allA[$u['id']][$d])&&isset($pam[$allA[$u['id']][$d]['plan_area_id']])){$bg=$pam[$allA[$u['id']][$d]['plan_area_id']]['color'];$fg=$pam[$allA[$u['id']][$d]['plan_area_id']]['fg_color'];$label=$pam[$allA[$u['id']][$d]['plan_area_id']]['short_code'];}
echo '<td class="cy';
if($we) echo ' we'; if($td) echo ' td'; if($hlc) echo ' '.$hlc; if($f) echo ' xb';
if($entryBlocked||$exitBlocked) echo ' cell-unavailable';
echo '"';
if($bg) echo ' style="background:'.e($bg).($we?' !important':'').';color:'.e($fg?:'#fff').'"';
$cellTitle=($entryBlocked||$exitBlocked)?'Person nicht verfügbar':((int)date('j',strtotime($d)).'. '.monthName((int)date('m',strtotime($d))).($label?' – '.$label:''));
echo ' title="'.e($cellTitle).'"';
echo '>'.$label.'</td>';}}?>
</tr><?php endforeach; endforeach;?>
</tbody></table></div>

<?php if(canEdit()):?>
<!-- Jahreseinsatz Modal -->
<div class="modal-bg" id="yearMod"><div class="modal">
<div class="mh"><h3>Jahreseinsatz planen</h3><button class="mx" data-action="cMod" data-args='["yearMod"]'>&times;</button></div>
<div class="mb">
<p class="modal-hint">Setzt den gleichen Einsatz für alle Werktage des Jahres <?php echo $y;?>. Wochenenden und Feiertage werden automatisch übersprungen.</p>
<div class="fg"><label>Mitarbeiter</label><select id="yrUser"><?php foreach(planningUsers() as $eu):?><option value="<?php echo $eu['id'];?>"><?php echo e($eu['last_name'].', '.$eu['first_name']);?></option><?php endforeach;?></select></div>
<div class="fg"><label>Planbereich</label><select id="yrArea">
<optgroup label="Kunden"><?php foreach(planAreas('kunde') as $p):?><option value="<?php echo $p['id'];?>"><?php echo e($p['short_code'].' – '.$p['name']);?></option><?php endforeach;?></optgroup>
<optgroup label="Allgemein"><?php foreach(planAreas('allgemein') as $p):?><option value="<?php echo $p['id'];?>"><?php echo e($p['short_code'].' – '.$p['name']);?></option><?php endforeach;?></optgroup>
</select></div>
</div><div class="mf"><button class="btn btn-outline" data-action="cMod" data-args='["yearMod"]'>Abbrechen</button><button class="btn btn-primary" <?php echo opsActionAttr('saveYearPlan',[(int)$y]);?>>Für ganzes Jahr setzen</button></div>
</div></div>
<?php endif;?>

<?php require_once __DIR__.'/../includes/legend.php';?>
<?php require_once __DIR__.'/../includes/footer.php';?>
