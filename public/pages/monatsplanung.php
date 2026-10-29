<?php
$pageTitle = 'Monatsplanung';
require_once __DIR__ . '/../includes/header.php';
requireEditor();
$y = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$m = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
if ($m<1){$m=12;$y--;} if ($m>12){$m=1;$y++;}
$days = daysInMonth($y,$m); $groups = usersByGroup($y,$m);
$asgn = assignments($y,$m,false); $abs = absences($y,$m,false);
$areas = planAreas(); $pam = planAreaMap(); $hols = holidays($y,$m);
$lp = lastPublished($y,$m); $lc = lastChange($y,$m); $see = canSeeDetails();
$mdata = allMonthData($y,$m);
$pm=$m-1;$py=$y;if($pm<1){$pm=12;$py--;}
$nm=$m+1;$ny=$y;if($nm>12){$nm=1;$ny++;}
$cu = currentUser();
$canErl = hasPerm('view_erl_column');
$canInternal = hasPerm('plan_view_internal');
$canPublishAll = hasPerm('plan_publish_all');

/* Revision je Zelle (Versionsprüfung beim Speichern, siehe app/lib/PlanCells.php) */
require_once __DIR__ . '/../../app/lib/PlanCells.php';
$cellRevs = [];
try { $cellRevs = planMonthRevs(getDB(), $y, $m); } catch (Exception $e) {}

/* Load draft map: track all non-internal drafts per cell */
$draftMap = []; /* [user_id][date] => ['by'=>name, 'by_id'=>id, 'type'=>..., 'value'=>...] */
try {
    $dq = getDB()->prepare('SELECT pd.user_id, pd.plan_date, pd.change_type, pd.change_value, pd.created_by, pd.action, u.first_name, u.last_name
        FROM plan_drafts pd LEFT JOIN users u ON u.id=pd.created_by
        WHERE pd.year=? AND pd.month=? AND pd.is_internal=0');
    $dq->execute([$y,$m]);
    foreach($dq->fetchAll(PDO::FETCH_ASSOC) as $dr) {
        $draftMap[(int)$dr['user_id']][$dr['plan_date']] = [
            'by' => trim(($dr['first_name']??'').' '.($dr['last_name']??'')),
            'by_id' => (int)$dr['created_by'],
            'type' => $dr['change_type'],
            'value' => $dr['change_value'],
            'action' => $dr['action']
        ];
    }
} catch(Exception $e) {}
$myUid = (int)$cu['id'];
$extraCols = 2 + ($canErl ? 1 : 0);
$colSpan = count($days) + 2 + $extraCols + ($see?2:0);
$sortedAreas=$areas; usort($sortedAreas, function($a,$b){return strcmp($a['short_code'],$b['short_code']);});
/* User name cache for remark_by */
$_uNames=[];try{$_us=getDB()->query('SELECT id,first_name,last_name FROM users');foreach($_us->fetchAll() as $_uu)$_uNames[$_uu['id']]=$_uu['first_name'].' '.$_uu['last_name'];}catch(Exception $e){}
?>

<!-- Combined Plan Bar: filters LEFT, nav CENTER, buttons RIGHT -->
<div class="plan-bar no-print">
<div class="plan-bar-top">
<div class="plan-bar-filters">
<input type="text" id="filterName" class="inp-filter" placeholder="Suchen..." oninput="applyFilters()">
<select id="filterRole" class="sel" onchange="applyFilters()">
<option value="">Alle Rollen</option>
<option value="techniker">Techniker</option><option value="teamleiter">Teamleiter</option>
<option value="verwaltung_r">Verwaltung</option><option value="lager">Lager</option>
<option value="azubis">Alle Azubis</option>
<option value="azubi_1">Azubi 1. LJ</option><option value="azubi_2">Azubi 2. LJ</option><option value="azubi_3">Azubi 3. LJ</option>
</select>
<select id="filterArea" class="sel" onchange="applyFilters()">
<option value="">Alle Bereiche</option>
<optgroup label="Kunden"><?php foreach(planAreas('kunde') as $fp):?><option value="<?php echo $fp['id'];?>"><?php echo e($fp['short_code'].' - '.$fp['name']);?></option><?php endforeach;?></optgroup>
<optgroup label="Allgemein"><?php foreach(planAreas('allgemein') as $fp):?><option value="<?php echo $fp['id'];?>"><?php echo e($fp['short_code'].' - '.$fp['name']);?></option><?php endforeach;?></optgroup>
</select>
<button type="button" id="sortToggle" class="sort-toggle-btn" data-mode="standard" onclick="toggleSort()" title="Sortierung umschalten (Standard / A–Z)">
<svg viewBox="0 0 20 20" fill="currentColor" width="13" height="13" aria-hidden="true"><path d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h8a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 110 2H4a1 1 0 01-1-1z"/></svg>
<span id="sortToggleLabel">Standard</span>
</button>
<button class="btn-reset" id="filterResetBtn" onclick="resetFilters()" style="display:none" title="Filter zurücksetzen">✕</button>
</div>
<div class="plan-bar-nav">
<a href="?year=<?php echo $py;?>&month=<?php echo $pm;?>" class="btn btn-outline">&laquo; <?php echo monthName($pm);?></a>
<div class="plan-bar-center"><span class="plan-bar-title"><?php echo monthName($m).' '.$y;?></span><a href="?year=<?php echo(int)date('Y');?>&month=<?php echo(int)date('m');?>" class="btn-today">Heute</a></div>

<a href="?year=<?php echo $ny;?>&month=<?php echo $nm;?>" class="btn btn-outline"><?php echo monthName($nm);?> &raquo;</a>
</div>
<div class="plan-bar-actions">
<button class="btn btn-outline" onclick="window.print()">Drucken</button>
<button class="btn btn-outline" onclick="oMod('entryMod')">Erfassen</button>
<button class="btn btn-outline" onclick="oMod('recurMod')">Dauereinsatz</button>
<?php if(canEdit()||hasPerm('use_planning_assistant')):?>
<a href="<?php echo bp();?>planungsassistent" class="btn btn-assistant" title="Komplexen Einsatz oder Kundenprojekt planen">
<svg viewBox="0 0 20 20" fill="currentColor" width="15" height="15"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm7.707 8.707a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
<span class="btn-txt">Assistent</span><span class="btn-beta">Beta</span></a>
<?php endif;?>
<?php if(hasPerm('plan_publish_own')):?>
<button class="btn btn-primary" onclick="publishPlan(<?php echo $y;?>,<?php echo $m;?>,false)">Meine Änderungen veröffentlichen</button>
<?php endif;?>
<?php if(hasPerm('plan_publish_all')):?>
<button class="btn btn-outline" onclick="publishPlan(<?php echo $y;?>,<?php echo $m;?>,true)" title="Alle Änderungen veröffentlichen" style="border-color:var(--p);color:var(--p)">Alle veröffentlichen</button>
<?php endif;?>
</div>
</div>
<div class="plan-bar-status">
<?php if($lp):?><span class="badge bs">Veröffentlicht: <?php echo date('d.m.Y H:i:s',strtotime($lp));?></span><?php else:?><span class="badge bw">Entwurf</span><?php endif;?>
<span class="badge bw" id="unpubBadge" style="<?php echo hasDrafts($y,$m)?'':'display:none';?>">Unveröffentlichte Änderungen</span>
<span class="badge" id="unpubBadgeOther" style="display:none;background:#e0f2fe;color:#0369a1"></span>
<span class="badge bm" id="liveEditors" style="display:none"></span>
</div>
</div>

<div id="lockBanner" class="lock-banner" style="display:none">
<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
<span>Gleichzeitige Bearbeitung ist gesperrt. Ein anderer Benutzer bearbeitet gerade diesen Plan.</span>
</div>

<div id="otherDraftsBanner" class="other-drafts-banner no-print" style="display:none">
<svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px;flex-shrink:0;color:#0369a1"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
<span id="otherDraftsText"></span>
<?php if(hasPerm('plan_publish_all')):?><button class="btn btn-xs btn-outline" style="margin-left:auto;border-color:#0369a1;color:#0369a1" onclick="publishPlan(<?php echo $y;?>,<?php echo $m;?>,true)">Alle veröffentlichen</button><?php endif;?>
</div>

<!-- Toolbar -->
<div class="tb no-print" id="toolbar">
<div class="tb-row">
<div class="tbg"><label>Einsatz:</label>
<select id="toolArea" class="sel sel-sm area-select">
<option value="">– Bereich –</option>
<optgroup label="Kunden"><?php foreach(planAreas('kunde') as $p):?>
<option value="<?php echo $p['id'];?>" data-c="<?php echo e($p['color']);?>" data-f="<?php echo e($p['fg_color']);?>" data-k="<?php echo e($p['short_code']);?>"><?php echo e($p['short_code'].' – '.$p['name']);?></option>
<?php endforeach;?></optgroup>
<optgroup label="Allgemein"><?php foreach(planAreas('allgemein') as $p):?>
<option value="<?php echo $p['id'];?>" data-c="<?php echo e($p['color']);?>" data-f="<?php echo e($p['fg_color']);?>" data-k="<?php echo e($p['short_code']);?>"><?php echo e($p['short_code'].' – '.$p['name']);?></option>
<?php endforeach;?></optgroup></select></div>
<div class="tbg tbs"><span id="toolSt" class="muted">Werkzeug wählen</span>
<button class="btn btn-xs btn-outline" id="toolDeselect" style="display:none" onclick="deselectTool()">✕ Ablegen</button>
<span id="liveUpdateHint" class="live-update-hint" style="display:none;margin-left:auto">Aktualisiert</span></div>
</div>
<div class="tb-row">
<div class="tbg"><label>Krank:</label>
<div class="abs-btn-grp"><button class="btn btn-xs abs-btn" data-t="krank_attest" style="background:#16a34a;color:#fff" title="Krank mit Attest">K</button><span class="abs-btn-label">Attest</span></div>
<div class="abs-btn-grp"><button class="btn btn-xs abs-btn" data-t="krank_ganz" style="background:#dc2626;color:#fff" title="Krank ohne Attest">K</button><span class="abs-btn-label">Ohne</span></div>
<div class="abs-btn-grp"><button class="btn btn-xs abs-btn" data-t="krank_halb" style="background:#ef4444;color:#fff" title="Krank halber Tag">k</button><span class="abs-btn-label">½ Tag</span></div>
</div>
<div class="tb-sep"></div>
<div class="tbg"><label>Urlaub:</label>
<div class="abs-btn-grp"><button class="btn btn-xs abs-btn" data-t="urlaub_genehmigt" style="background:#16a34a;color:#fff" title="Urlaub genehmigt">U</button><span class="abs-btn-label">Gen.</span></div>
<div class="abs-btn-grp"><button class="btn btn-xs abs-btn" data-t="urlaub_ganz" style="background:#dc2626;color:#fff" title="Urlaub beantragt">U</button><span class="abs-btn-label">Beantr.</span></div>
<div class="abs-btn-grp"><button class="btn btn-xs abs-btn" data-t="urlaub_halb" style="background:#fbbf24;color:#000" title="Urlaub halber Tag">u</button><span class="abs-btn-label">½ Tag</span></div>
</div>
<div class="tb-sep"></div>
<div class="tbg"><button class="btn btn-xs abs-btn" data-t="gleitzeit" style="background:#8b5cf6;color:#fff" title="Gleitzeit">GL</button></div>
<div class="tb-sep"></div>
<div class="tbg"><button class="btn btn-xs abs-btn" data-t="offen" style="background:#475569;color:#fff" title="Offen / noch zu klären">?</button><span class="abs-btn-label">Offen</span></div>
<div class="tb-sep"></div>
<div class="tbg">
<button class="btn btn-xs btn-outline" id="toolLock" title="Sperren">&#x2298; Sperren</button>
<button class="btn btn-xs btn-outline" id="toolClr">&#x2715; Löschen</button>
<button class="btn btn-xs" id="toolRemark" title="Bemerkung (Masseneintragung)" style="background:#f97316;color:#fff;border:1px solid #f97316"><span class="tool-pen">&#x270E;</span> Bemerkung</button>
</div>
<?php if(hasPerm('plan_view_internal')):?>
<div class="tb-sep"></div>
<div class="tbg">
<button class="btn btn-xs" id="toolIntRemark" title="Interne Bemerkung (Masseneintragung – nicht sichtbar für Mitarbeiter)" style="background:#c8b400;color:#fff;border:1px solid #c8b400"><span class="tool-pen">&#x270E;</span> Int. Bemerkung</button>
</div>
<div class="tb-sep"></div>
<div class="tbg">
<div class="tb-nv-box">
<label class="tb-nv-toggle" title="Umschalten: Einträge veröffentlichen / nicht veröffentlichen">
<input type="checkbox" id="toolInternal" onchange="updateNvToggle()">
<span class="tb-nv-track"><span class="tb-nv-thumb"></span></span>
</label>
<span class="tb-nv-label" id="tbNvLabel">Veröffentlichen</span>
</div>
</div>
<?php endif;?>
</div>
</div>
</div>

<div class="print-only print-header"><span>Monatsplan – <?php echo monthName($m).' '.$y; ?><?php if($lp):?> | Stand: <?php echo date('d.m.Y H:i',strtotime($lp));?><?php endif;?></span><span class="print-by">Gedruckt von: <?php echo e($cu['first_name'].' '.$cu['last_name']);?></span></div>
<div class="print-only print-filter" id="printFilter"></div>

<div class="ps"><table class="pt pe" id="planTbl">
<thead><tr class="hd-row">
<th class="sc cn">Mitarbeiter</th>
<th class="sc ca">Info</th>
<?php if($see):?><th class="sc cr-start">RU Anf.</th><?php endif;?>
<?php $lk=0;foreach($days as $d):
$kwn=kw($d);$dn=(int)date('j',strtotime($d));$wdn=wdShort($d);$we=isWE($d);$td=($d===date('Y-m-d'));
$hl=isset($hols[$d]); $hlc=''; $isFt=false;
if($hl){$isFt=$hols[$d]['holiday_type']==='feiertag'; $hlc=$isFt?'hl-ft':'hl-fe';}
$showKw=($kwn!==$lk);if($showKw)$lk=$kwn;
?><th class="cd <?php if($we) echo 'we '; if($td) echo 'td td-head '; echo $hlc;?>" title="<?php echo $dn.'. '.monthName($m); if($hl) echo ' – '.e($hols[$d]['name']);?>">
<div class="kwl"><?php if($showKw) echo 'KW'.$kwn;?></div>
<div class="dn"><?php echo $dn;?></div><div class="wn"><?php echo $wdn;?></div>
<?php if($hl):?><div class="hol-lbl <?php echo $isFt?'hol-ft':'hol-fe';?>" title="<?php echo e($hols[$d]['name']);?>"><?php echo mb_substr($hols[$d]['name'],0,4);?></div><?php endif;?>
</th><?php endforeach;?>
<?php if($see):?><th class="cr-end">RU Ende</th><?php endif;?>
<th class="cw no-print">WOTs</th>
<th class="ch-col no-print">Std</th>
<?php if($canErl):?><th class="cs-col no-print">Erl.</th><?php endif;?>
</tr></thead>
<tbody>
<?php
/* Detect currently locked-out users */
$lockedUsers=[];
try{
    $maxAttempts=(int)getSetting('max_login_attempts','5');
    $lockoutMin=(int)getSetting('lockout_duration_minutes','15');
    if($maxAttempts>0){
        $ls=getDB()->prepare("SELECT u.id FROM users u JOIN (SELECT username, COUNT(*) c FROM login_attempts WHERE success=0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE) GROUP BY username HAVING c >= ?) locked ON locked.username=u.username");
        $ls->execute([$lockoutMin,$maxAttempts]);
        foreach($ls->fetchAll() as $lr) $lockedUsers[(int)$lr['id']]=true;
    }
}catch(Exception $e){}
?>
<?php foreach($groups as $gn=>$gu): if(empty($gu)) continue;?>
<tr class="gr" data-group="<?php echo e($gn);?>"><td class="gr-name"><?php echo e($gn);?></td><td class="gr-fill" colspan="<?php echo $colSpan-1;?>"></td></tr>
<?php foreach($gu as $u):
$uwots=userWots($u['id'],$y,$m); $wotCnt=count($uwots);
$weOk=(int)$u['weekend_allowed'];
$md = $mdata[$u['id']] ?? ['hours'=>'','snow_checked'=>0];
$priArea = null;
if (!empty($u['primary_area_id'] ?? null) && isset($pam[$u['primary_area_id']])) $priArea = $pam[$u['primary_area_id']];
$userAreas = [];
if (isset($asgn[$u['id']])) foreach($asgn[$u['id']] as $dd=>$av) $userAreas[$av['plan_area_id']]=1;
$areasStr = ','.implode(',',array_keys($userAreas)).',';
$roleFilter = $u['role'];
if (in_array($u['role'],['manager','mitarbeiter'])) $roleFilter='techniker';
if ($u['role']==='verwaltung') $roleFilter='verwaltung_r';
$osLevel = $u['os_level'] ?? '';
$absSee = canSeeAbsenceDetails($u['id']);
?>
<tr data-user="<?php echo $u['id'];?>" data-we="<?php echo $weOk;?>" class="plan-row <?php if($weOk) echo 'we-allowed';?>" data-name="<?php echo e(mb_strtolower($u['last_name'].' '.$u['first_name']));?>" data-role="<?php echo e($roleFilter);?>" data-realrole="<?php echo e($u['role']);?>" data-areas="<?php echo e($areasStr);?>" data-entry="<?php echo e($u['entry_date']??'');?>" data-exit="<?php echo e($u['exit_date']??'');?>" data-group="<?php echo e($gn);?>">
<td class="sc cn"><?php echo e($u['last_name'].', '.$u['first_name']);?><?php if(isset($lockedUsers[$u['id']])):?><span class="user-locked-badge" title="Konto temporär gesperrt"><svg viewBox="0 0 20 20" fill="currentColor" style="width:11px;height:11px"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg></span><?php endif;?><?php if($u['role']==='teamleiter'):?><span class="role-tag role-tag-tl">TL</span><?php elseif($u['role']==='manager'):?><span class="role-tag role-tag-ma">MA</span><?php endif;?><?php if($weOk):?><span class="we-badge" title="Wochenendarbeit">WE</span><?php endif;?><span class="row-lock-badge" id="lockBadge_<?php echo $u['id'];?>" style="display:none"></span></td>
<td class="sc ca"><?php if($priArea):?><span class="ts" style="background:<?php echo e($priArea['color']);?>;color:<?php echo e($priArea['fg_color']);?>"><?php echo e($priArea['short_code']);?></span><?php endif;?><?php if($osLevel):?><span class="ts os-badge"><?php echo e($osLevel);?></span><?php endif;?><?php if(!empty($u['training_profession'])):?><span class="ts tp-badge"><?php echo e($u['training_profession']);?></span><?php endif;?></td>
<?php if($see):?><td class="sc cr-start <?php echo ($absSee&&vacLeftAtStart($u['id'],$y,$m)<0)?'txt-d':'';?>"><?php echo $absSee?number_format(vacLeftAtStart($u['id'],$y,$m),1,',',''):'·';?></td><?php endif;?>
<?php foreach($days as $d):
$we=isWE($d);$td=($d===date('Y-m-d'));
$hl=isset($hols[$d]); $hlc=''; $isFt=false;
if($hl){$isFt=$hols[$d]['holiday_type']==='feiertag'; $hlc=$isFt?'hl-ft':'hl-fe';}
$cd='';$bg='';$fg='';$ct='';$cv='';$remark='';$isLocked=false;$isVacLock=false;$intRemark='';$isInternalEntry=false;$remarkByUid='';$remarkAtRaw='';
if(isset($abs[$u['id']][$d])){$ab=$abs[$u['id']][$d];$abt=$ab['absence_type'];$ct='absence';$cv=$abt;
  if(!$absSee && isConfidentialAbsence($abt)){$cd='A';$bg='#ef4444';$fg='#fff';$cv='';$remark='';$intRemark='';$isInternalEntry=false;$remarkByUid='';$remarkAtRaw='';}
  else{$cd=absenceLabel($abt);$bg=absenceColor($abt);$fg=absenceFg($abt);$remark=$ab['remarks']??'';$intRemark=$ab['internal_remark']??'';$isInternalEntry=!empty($ab['is_internal']);$remarkByUid=$ab['remark_by']??'';$remarkAtRaw=$ab['remark_at']??'';if($abt==='gesperrt')$isLocked=true;if($abt==='urlaub_genehmigt'&&!empty($ab['is_published']))$isVacLock=true;}
}
elseif(isset($asgn[$u['id']][$d])&&isset($pam[$asgn[$u['id']][$d]['plan_area_id']])){$p=$pam[$asgn[$u['id']][$d]['plan_area_id']];$cd=$p['short_code'];$bg=$p['color'];$fg=$p['fg_color'];$ct='assignment';$cv=$asgn[$u['id']][$d]['plan_area_id'];$remark=$asgn[$u['id']][$d]['remarks']??'';$intRemark=$asgn[$u['id']][$d]['internal_remark']??'';$isInternalEntry=!empty($asgn[$u['id']][$d]['is_internal']);$remarkByUid=$asgn[$u['id']][$d]['remark_by']??'';$remarkAtRaw=$asgn[$u['id']][$d]['remark_at']??'';}
$remarkByName=($remarkByUid && ($remark || ($canInternal && $intRemark)) && isset($_uNames[(int)$remarkByUid]))?$_uNames[(int)$remarkByUid]:'';
$remarkAtFmt=($remarkAtRaw && ($remark || ($canInternal && $intRemark)))?date('d.m. H:i',strtotime($remarkAtRaw)):'';
$blocked=$isFt?1:0; $weBlocked=($we&&!$weOk)?1:0;
$entryBlocked=(!empty($u['entry_date']) && $d < $u['entry_date'])?1:0;
$exitBlocked=(!empty($u['exit_date']) && $d > $u['exit_date'])?1:0;
if($entryBlocked||$exitBlocked){$blocked=1;$cd='';$bg='';$fg='';$ct='';$cv='';}
$dateBlockTitle=$entryBlocked?'Person nicht verfügbar':($exitBlocked?'Person nicht verfügbar':'');
$hasRemarkOrInt = $remark || ($canInternal && $intRemark);
$cellDraft = $draftMap[$u['id']][$d] ?? null;
$isDraftMine = $cellDraft && $cellDraft['by_id'] === $myUid;
$isDraftOther = $cellDraft && $cellDraft['by_id'] !== $myUid;
?><td class="cd pc pe-c <?php if($we)echo'we ';if($we&&$weOk&&$bg)echo'we-filled ';if($we&&$weOk)echo'we-open ';if($td)echo'td ';echo $hlc;if($hasRemarkOrInt)echo' has-remark';if($remark)echo' has-pub-remark';if($canInternal&&$intRemark)echo' has-int-remark';if($isLocked)echo' cell-locked';if($entryBlocked||$exitBlocked)echo' cell-unavailable';if($isInternalEntry&&$canInternal)echo' cell-internal';if($isDraftMine)echo' draft-mine';if($isDraftOther)echo' draft-other';?>"
data-d="<?php echo $d;?>" data-u="<?php echo $u['id'];?>" data-r="<?php echo $cellRevs[$u['id'].'_'.$d] ?? '0';?>" data-t="<?php echo $ct;?>" data-v="<?php echo $cv;?>" data-bl="<?php echo $blocked;?>" data-we="<?php echo $we?1:0;?>" data-webl="<?php echo $weBlocked;?>"<?php if($remark):?> data-remark="<?php echo e($remark);?>"<?php endif;?><?php if($canInternal&&$intRemark):?> data-int-remark="<?php echo e($intRemark);?>"<?php endif;?><?php if($remarkByName):?> data-remark-by="<?php echo e($remarkByName);?>"<?php endif;?><?php if($remarkAtFmt):?> data-remark-at="<?php echo e($remarkAtFmt);?>"<?php endif;?><?php if($isVacLock):?> data-vaclock="1"<?php endif;?><?php if($isDraftOther):?> data-draft-by="<?php echo e($cellDraft['by']);?>"<?php endif;?>
<?php if($bg):?> style="background:<?php echo e($bg);?><?php if($we) echo ' !important';?>;color:<?php echo e($fg);?>"<?php endif;?>><?php echo e($cd);?></td>
<?php endforeach;?>
<?php if($see):?><td class="cr-end <?php echo ($absSee&&vacLeftAtEnd($u['id'],$y,$m)<0)?'txt-d':'';?>"><?php echo $absSee?number_format(vacLeftAtEnd($u['id'],$y,$m),1,',',''):'·';?></td><?php endif;?>
<td class="cw no-print">
<?php if($wotCnt>0):?><span class="wot-cnt"><?php echo $wotCnt;?></span>
<span class="wot-eye" onclick="openWotDetail(<?php echo $u['id'];?>,'<?php echo e($u['last_name'].', '.$u['first_name']);?>')"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg></span>
<?php if(canEdit()):?><span class="wot-edit" onclick="openWotManager(<?php echo $u['id'];?>,'<?php echo e($u['last_name'].', '.$u['first_name']);?>')"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg></span><?php endif;?>
<?php else:?><?php if(canEdit()):?><span class="wot-edit" onclick="openWotManager(<?php echo $u['id'];?>,'<?php echo e($u['last_name'].', '.$u['first_name']);?>')"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg></span><?php endif;?><?php endif;?>
</td>
<td class="ch-col no-print"><input type="text" inputmode="decimal" class="inp-hours" value="<?php echo e($md['hours']??'');?>" data-uid="<?php echo $u['id'];?>" placeholder="–"></td>
<?php if($canErl):?><td class="cs-col no-print"><input type="checkbox" class="inp-snow" data-uid="<?php echo $u['id'];?>" <?php if($md['snow_checked']??0) echo 'checked';?>></td><?php endif;?>
</tr><?php endforeach; endforeach;?>
</tbody></table></div>

<!-- WOT Detail Modal -->
<div class="modal-bg modal-lock" id="wotDetMod"><div class="modal"><div class="mh"><h3 id="wotDetTitle">WOTs</h3><button class="mx" onclick="cMod('wotDetMod')">&times;</button></div><div class="mb"><div id="wotDetList"><p class="muted">Laden...</p></div></div><div class="mf"><button class="btn btn-outline" onclick="cMod('wotDetMod')">Schließen</button></div></div></div>
<!-- WOT Manager Modal -->
<div class="modal-bg modal-lock" id="wotMgrMod"><div class="modal modal-lg"><div class="mh"><h3 id="wotMgrTitle">WOTs verwalten</h3><button class="mx" onclick="cMod('wotMgrMod')">&times;</button></div><div class="mb"><div id="wotMgrList" style="margin-bottom:.75rem"><p class="muted">Laden...</p></div><div style="border-top:1px solid var(--bd);padding-top:.75rem"><h4 style="font-size:.82rem;font-weight:700;margin-bottom:.5rem">Neuen WOT hinzufügen</h4><div class="fg2"><div class="fg"><label>WOT-Nummer</label><input type="text" id="nwNum" placeholder="WOT0123456"></div><div class="fg"><label>Farbe</label><input type="color" id="nwColor" value="#3b82f6"></div></div><div class="fg"><label>Link (optional)</label><input type="url" id="nwLink" placeholder="https://..."></div><div class="fg"><label>Bemerkung (optional)</label><textarea id="nwRem" rows="2" placeholder="z.B. Prio hoch..."></textarea></div><label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;color:var(--mu);margin-bottom:.5rem"><input type="checkbox" id="nwVisible" checked> Für Mitarbeiter sichtbar</label><button class="btn btn-primary" onclick="addWot()">WOT hinzufügen</button></div></div><div class="mf"><button class="btn btn-outline" onclick="cMod('wotMgrMod')">Schließen</button></div></div></div>
<input type="hidden" id="wotMgrUid" value="0">
<!-- Manual Entry Modal -->
<div class="modal-bg" id="entryMod"><div class="modal modal-lg"><div class="mh"><h3>Einsatz / Eintrag erfassen</h3><button class="mx" onclick="cMod('entryMod')">&times;</button></div><div class="mb"><div class="fg"><label>Mitarbeiter <span class="muted" style="font-weight:400">(mehrere möglich – z.B. Onsite-Team)</span></label><input type="text" class="inp-filter sup-search" id="entryUserSearch" placeholder="Mitarbeiter suchen..." oninput="filterEntryUsers()" style="margin-bottom:.4rem"><div class="entry-pick-bar"><button type="button" class="btn btn-xs btn-outline" onclick="entryUsersAll(true)">Alle</button><button type="button" class="btn btn-xs btn-outline" onclick="entryUsersAll(false)">Keine</button><span class="muted" style="font-size:.78rem;margin-left:auto"><b id="entryUserCnt">0</b> ausgewählt</span></div><div class="sup-pick" id="entryUserPick" style="max-height:240px"><?php foreach(planningUsers() as $eu): $epArea=(!empty($eu['primary_area_id'])&&isset($pam[$eu['primary_area_id']]))?$pam[$eu['primary_area_id']]:null;?><label class="sup-pick-item" data-name="<?php echo e(mb_strtolower($eu['last_name'].' '.$eu['first_name']));?>"><input type="checkbox" class="entryUserCb" value="<?php echo $eu['id'];?>" onchange="updateEntryCnt()"><span class="epi-name"><?php echo e($eu['last_name'].', '.$eu['first_name']);?></span><span class="epi-meta"><?php if($epArea):?><span class="epi-tag" style="background:<?php echo e($epArea['color']);?>;color:<?php echo e($epArea['fg_color']);?>" title="Team / Stammbereich"><?php echo e($epArea['short_code']);?></span><?php endif;?><?php if(!empty($eu['os_level'])):?><span class="epi-tag epi-os" title="OS-Level"><?php echo e($eu['os_level']);?></span><?php endif;?><?php if(!empty($eu['training_profession'])):?><span class="epi-tag epi-tp" title="Ausbildungsberuf"><?php echo e($eu['training_profession']);?></span><?php endif;?><span class="epi-tag epi-role"><?php echo e(roleName($eu['role']));?></span></span></label><?php endforeach;?></div></div><div class="fg2"><div class="fg"><label>Datum von</label><input type="date" id="entryFrom" value="<?php echo sprintf('%04d-%02d-01',$y,$m);?>"></div><div class="fg"><label>Datum bis</label><input type="date" id="entryTo" value="<?php echo date('Y-m-t',strtotime(sprintf('%04d-%02d-01',$y,$m)));?>"></div></div><div class="fg"><label>Typ</label><select class="sel" id="entryType"><option value="">– Bitte wählen –</option><optgroup label="Einsatz (Kunden)"><?php foreach(planAreas('kunde') as $p):?><option value="a:<?php echo $p['id'];?>"><?php echo e($p['short_code'].' - '.$p['name']);?></option><?php endforeach;?></optgroup><optgroup label="Einsatz (Allgemein)"><?php foreach(planAreas('allgemein') as $p):?><option value="a:<?php echo $p['id'];?>"><?php echo e($p['short_code'].' - '.$p['name']);?></option><?php endforeach;?></optgroup><optgroup label="Abwesenheit"><option value="x:krank_attest">K – Krank mit Attest</option><option value="x:krank_ganz">K – Krank ohne Attest</option><option value="x:krank_halb">k – Krank (halber Tag)</option><option value="x:urlaub_genehmigt">U – Urlaub genehmigt</option><option value="x:urlaub_ganz">U – Urlaub beantragt</option><option value="x:urlaub_halb">u – Urlaub (halber Tag)</option><option value="x:gleitzeit">GL – Gleitzeit</option><option value="x:gesperrt">— – Gesperrt</option><option value="x:offen">? – Offen / noch zu klären</option></optgroup></select></div><label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;color:var(--mu)"><input type="checkbox" id="entryWe"> Wochenende einschließen</label></div><div class="mf"><button class="btn btn-outline" onclick="cMod('entryMod')">Abbrechen</button><button class="btn btn-primary" onclick="saveEntry()">Prüfen &amp; Speichern</button></div></div></div>
<!-- Dauereinsatz Modal -->
<div class="modal-bg" id="recurMod"><div class="modal modal-lg">
<div class="mh"><h3>Dauereinsatz anlegen</h3><button class="mx" onclick="cMod('recurMod')">&times;</button></div>
<div class="mb">
<!-- Step indicators -->
<div class="recur-steps">
<div class="recur-step active" id="rs1"><span class="recur-step-num">1</span> Mitarbeiter</div>
<div class="recur-step" id="rs2"><span class="recur-step-num">2</span> Zeitraum</div>
<div class="recur-step" id="rs3"><span class="recur-step-num">3</span> Einsatz</div>
</div>

<!-- Step 1: Mitarbeiter -->
<div class="recur-panel" id="rp1">
<div style="display:flex;gap:.5rem;margin-bottom:.5rem">
<input type="text" class="inp-filter" id="recurSearch" placeholder="Suchen..." oninput="filterUserPick(this)" style="flex:1">
<select class="sel sel-sm" id="recurRoleFilter" onchange="filterRecurRole()">
<option value="">Alle Rollen</option>
<option value="techniker">Techniker</option><option value="teamleiter">Teamleiter</option>
<option value="verwaltung">Verwaltung</option><option value="lager">Lager</option>
<option value="azubi">Alle Azubis</option>
<option value="azubi_1">Azubi 1. LJ</option><option value="azubi_2">Azubi 2. LJ</option><option value="azubi_3">Azubi 3. LJ</option>
</select>
</div>
<div class="recur-quick-btns">
<button class="btn btn-xs btn-outline" onclick="recurSelectAll(true)">Alle auswählen</button>
<button class="btn btn-xs btn-outline" onclick="recurSelectAll(false)">Alle abwählen</button>
<button class="btn btn-xs btn-outline" onclick="recurSelectRole('techniker')">Nur Techniker</button>
<button class="btn btn-xs btn-outline" onclick="recurSelectRole('azubi')">Nur Azubis</button>
</div>
<div class="user-pick" id="recurUserPick" style="max-height:260px"><?php foreach(planningUsers() as $eu):
$rn=roleName($eu['role']); $rc=$eu['role'];
?><label class="user-pick-item" data-role="<?php echo e($rc);?>"><input type="checkbox" value="<?php echo $eu['id'];?>" class="recurUserCb"> <span style="flex:1"><?php echo e($eu['last_name'].', '.$eu['first_name']);?></span> <span class="rb r-<?php echo e($rc);?>" style="font-size:.55rem"><?php echo e($rn);?></span></label><?php endforeach;?></div>
<div class="recur-count"><span id="recurUserCnt">0</span> Mitarbeiter ausgewählt</div>
</div>

<!-- Step 2: Zeitraum -->
<div class="recur-panel" id="rp2" style="display:none">
<div class="fg2">
<div class="fg"><label>Startdatum</label><input type="date" id="recurFrom" value="<?php echo sprintf('%04d-%02d-01',$y,$m);?>"></div>
<div class="fg"><label>Enddatum</label><input type="date" id="recurTo" value="<?php echo date('Y-m-t',strtotime(sprintf('%04d-%02d-01',$y,$m)));?>"></div>
</div>
<div class="fg"><label>Wochentage</label>
<div class="wd-checks">
<label class="wd-check"><input type="checkbox" value="1" class="recurDay" checked> Mo</label>
<label class="wd-check"><input type="checkbox" value="2" class="recurDay" checked> Di</label>
<label class="wd-check"><input type="checkbox" value="3" class="recurDay" checked> Mi</label>
<label class="wd-check"><input type="checkbox" value="4" class="recurDay" checked> Do</label>
<label class="wd-check"><input type="checkbox" value="5" class="recurDay" checked> Fr</label>
</div></div>
<label class="recur-opt"><input type="checkbox" id="recurSkipHol" checked> Feiertage überspringen</label>
<div class="recur-preview" id="recurPreview"></div>
</div>

<!-- Step 3: Einsatz -->
<div class="recur-panel" id="rp3" style="display:none">
<div class="fg"><label>Was soll eingetragen werden?</label>
<select class="sel" id="recurType" style="font-size:.9rem">
<option value="">– Bitte wählen –</option>
<optgroup label="Einsatz (Kunden)"><?php foreach(planAreas('kunde') as $p):?>
<option value="a:<?php echo $p['id'];?>" data-c="<?php echo e($p['color']);?>"><?php echo e($p['short_code'].' - '.$p['name']);?></option>
<?php endforeach;?></optgroup>
<optgroup label="Einsatz (Allgemein)"><?php foreach(planAreas('allgemein') as $p):?>
<option value="a:<?php echo $p['id'];?>" data-c="<?php echo e($p['color']);?>"><?php echo e($p['short_code'].' - '.$p['name']);?></option>
<?php endforeach;?></optgroup>
<optgroup label="Abwesenheit">
<option value="x:urlaub_genehmigt">U – Urlaub genehmigt</option>
<option value="x:urlaub_ganz">U – Urlaub beantragt</option>
<option value="x:krank_attest">K – Krank mit Attest</option>
<option value="x:krank_ganz">K – Krank ohne Attest</option>
<option value="x:gleitzeit">GL – Gleitzeit</option>
<option value="x:gesperrt">— – Gesperrt</option>
<option value="x:offen">? – Offen / noch zu klären</option>
</optgroup></select></div>
<div id="recurSummary" class="recur-summary"></div>
</div>
</div>
<div class="mf">
<button class="btn btn-outline" onclick="cMod('recurMod')">Abbrechen</button>
<button class="btn btn-outline" id="recurPrevBtn" onclick="recurStep(-1)" style="display:none">&laquo; Zurück</button>
<button class="btn btn-primary" id="recurNextBtn" onclick="recurStep(1)">Weiter &raquo;</button>
<button class="btn btn-primary" id="recurSaveBtn" onclick="saveRecurring()" style="display:none">Anlegen</button>
</div>
</div></div>

<!-- Leave Warning Modal -->
<div class="modal-bg" id="leaveMod"><div class="modal modal-sm leave-modal">
<div class="leave-modal-body">
<div class="confirm-icon confirm-icon-warn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
<h3>Planung verlassen?</h3>
<p>Wollen Sie die Planung wirklich ohne Veröffentlichung verlassen? Einträge werden dennoch in der Planung gespeichert, aber nicht bereitgestellt.</p>
</div>
<div class="mf mf-confirm">
<button class="btn btn-outline" id="leaveConfirmBtn">Verlassen</button>
<button class="btn btn-primary" id="leavePublishBtn">Meine Einträge veröffentlichen</button>
</div>
</div></div>

<?php require_once __DIR__.'/../includes/legend.php'; ?>
<script>var PY=<?php echo $y;?>,PM=<?php echo $m;?>;
var CAN_INTERNAL=<?php echo $canInternal?'true':'false';?>;
var CAN_UNLOCK_VAC=<?php echo hasPerm('unlock_vacation')?'true':'false';?>;
var CAN_PUBLISH_OWN=<?php echo hasPerm('plan_publish_own')?'true':'false';?>;
var CAN_SEE_DETAILS=<?php echo $see?'true':'false';?>;
var HOLIDAYS=<?php $holDates=[];foreach($hols as $hd=>$hv){if($hv['holiday_type']==='feiertag')$holDates[]=$hd;}echo json_encode($holDates);?>;
var USER_DATES=<?php
$udMap=[];foreach($groups as $gn=>$gu){foreach($gu as $u2){$udMap[$u2['id']]=['entry'=>$u2['entry_date']??'','exit'=>$u2['exit_date']??''];}}
echo json_encode($udMap);?>;
/* Row-level live editing lock system */
var rowLocks={};var ROW_LOCK_DURATION=30;var rowLockTimers={};
function lockRow(targetUid){
    fetch(getApiBase()+'api/live_status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'lock_row',target_uid:targetUid,year:PY,month:PM})}).catch(function(){});
}
function updateRowLocks(editors){
    /* Clear old locks */
    var activeLocks={};
    if(editors&&editors.length>0){
        editors.forEach(function(ed){
            if(ed.target_uid && ed.user_id!=currentUserId()){
                activeLocks[ed.target_uid]={name:ed.first_name+' '+ed.last_name,uid:ed.user_id};
            }
        });
    }
    document.querySelectorAll('.plan-row').forEach(function(r){
        var uid=r.getAttribute('data-user');
        var badge=document.getElementById('lockBadge_'+uid);
        if(activeLocks[uid]){
            r.classList.add('row-locked-by-other');
            if(badge){
                /* Start or update countdown */
                if(!rowLockTimers[uid]){rowLockTimers[uid]=ROW_LOCK_DURATION;}
                badge.innerHTML='<svg viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;vertical-align:middle;margin-right:2px"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>Gesperrt von '+esc(activeLocks[uid].name);
                badge.style.display='';
            }
        } else {
            r.classList.remove('row-locked-by-other');
            if(badge){badge.style.display='none';}
            delete rowLockTimers[uid];
        }
    });
}
<?php if(getSetting('approval_system_enabled','0')==='1'): ?>
/* Load pending change requests overlay */
(function(){
    function loadPendingMP(){
        fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'pending_map',year:PY,month:PM})})
        .then(function(r){return r.json();}).then(function(d){
            if(!d.success||!d.map)return;
            document.querySelectorAll('.cell-pending').forEach(function(c){c.classList.remove('cell-pending');});
            for(var key in d.map){
                var parts=key.split('_');var uid=parts[0],date=parts.slice(1).join('_');
                var cell=document.querySelector('.pe-c[data-u="'+uid+'"][data-d="'+date+'"]');
                if(cell)cell.classList.add('cell-pending');
            }
        }).catch(function(){});
    }
    document.addEventListener('DOMContentLoaded',loadPendingMP);
    setInterval(loadPendingMP,15000);
})();
<?php endif; ?>
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
