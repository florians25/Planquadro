<?php
$pageTitle = 'Mein Plan';
require_once __DIR__ . '/../includes/header.php';
$u = currentUser();
$y = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$m = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$view = $_GET['view'] ?? 'month';
$hols = holidays($y, $view==='year' ? 0 : $m);
$pam = planAreaMap();
$glS=getDB()->prepare("SELECT COUNT(*) c FROM absences WHERE user_id=? AND YEAR(absence_date)=? AND absence_type='gleitzeit' AND is_published=1");$glS->execute([$u['id'],$y]);$glCnt=(int)$glS->fetch()['c'];
$approvalEnabled = getSetting('approval_system_enabled','0') === '1';

if ($view === 'year') {
    $allA=[]; $allAb=[];
    for($mm=1;$mm<=12;$mm++){
        foreach(assignments($y,$mm,true) as $uid=>$dd) { if($uid==$u['id']) foreach($dd as $d=>$v) $allA[$d]=$v; }
        foreach(absences($y,$mm,true) as $uid=>$dd) { if($uid==$u['id']) foreach($dd as $d=>$v) $allAb[$d]=$v; }
    }
    $holsY = holidays($y);
} else {
    $days = daysInMonth($y,$m);
    $asgn = assignments($y,$m,true); $abs = absences($y,$m,true);
    $myA = $asgn[$u['id']] ?? []; $myAb = $abs[$u['id']] ?? [];
    $pm=$m-1;$py=$y;if($pm<1){$pm=12;$py--;}
    $nm=$m+1;$ny=$y;if($nm>12){$nm=1;$ny++;}
    $display = $_GET['display'] ?? 'visual';
}
?>
<div class="ph">
<div class="phl"><h1>Mein Plan</h1>
<div class="mp-stats-bar">
<span class="mp-stat-chip" style="background:#dcfce7;color:#16a34a">Resturlaub: <b><?php echo number_format(vacLeft($u['id'],$y),1,',','');?></b></span>
<span class="mp-stat-chip" style="background:#fee2e2;color:#dc2626">Krank: <b><?php echo number_format(sickDays($u['id'],$y),1,',','');?></b></span>
<span class="mp-stat-chip" style="background:#ede9fe;color:#7c3aed">Gleitzeit: <b><?php echo $glCnt;?></b></span>
</div>
</div>
<div class="phr">
<?php if($view==='month'):?>
<?php if(($display??'visual')==='visual'):?>
<a href="?view=month&display=list&year=<?php echo $y;?>&month=<?php echo $m;?>" class="btn btn-outline">Listenansicht</a>
<?php else:?>
<a href="?view=month&display=visual&year=<?php echo $y;?>&month=<?php echo $m;?>" class="btn btn-outline">Kalenderansicht</a>
<?php endif;?>
<a href="?view=year&year=<?php echo $y;?>" class="btn btn-outline desk-only">Jahresansicht</a>
<?php else:?>
<a href="?view=month&year=<?php echo $y;?>&month=<?php echo(int)date('m');?>" class="btn btn-outline">Monatsansicht</a>
<?php endif;?>
<button class="btn btn-outline no-print desk-only" data-action="print">Drucken</button>
</div></div>

<!-- Mein Plan Tabs -->
<?php if($approvalEnabled): ?>
<div class="mp-tabs no-print">
<button class="mp-tab mp-tab-active" data-action="switchMpTab" data-args="[0]">Mein Plan</button>
<button class="mp-tab" data-action="switchMpTab" data-args="[1]" id="mpTabReq">Meine Anträge</button>
</div>
<?php endif; ?>
<div id="mpTabPlan">

<?php if($view==='month'):
  $agEin=0;$agAbw=0;$agFrei=0;
  foreach($days as $_d){ if(isWE($_d)) continue;
    $_ft=isset($hols[$_d])&&$hols[$_d]['holiday_type']==='feiertag';
    if(isset($myAb[$_d])) $agAbw++;
    elseif(isset($myA[$_d])) $agEin++;
    elseif(!$_ft) $agFrei++;
  }
?>
<div class="mp-agenda no-print">
  <div class="mp-ag-nav">
    <a href="?view=month&display=visual&year=<?php echo $py;?>&month=<?php echo $pm;?>" class="mp-ag-navbtn" aria-label="Vorheriger Monat">&lsaquo;</a>
    <span class="mp-ag-title"><?php echo monthName($m).' '.$y;?></span>
    <a href="?view=month&display=visual&year=<?php echo $ny;?>&month=<?php echo $nm;?>" class="mp-ag-navbtn" aria-label="Nächster Monat">&rsaquo;</a>
  </div>
  <a href="?view=month&display=visual&year=<?php echo(int)date('Y');?>&month=<?php echo(int)date('n');?>#mpToday" class="mp-ag-todaybtn" data-action="mpJumpToday" data-return>Heute</a>
  <div class="mp-ag-summary">
    <div class="mp-ag-sum-item"><span class="mp-ag-sum-n"><?php echo $agEin;?></span><span class="mp-ag-sum-l">Einsätze</span></div>
    <div class="mp-ag-sum-item"><span class="mp-ag-sum-n"><?php echo $agAbw;?></span><span class="mp-ag-sum-l">Abwesend</span></div>
    <div class="mp-ag-sum-item"><span class="mp-ag-sum-n"><?php echo $agFrei;?></span><span class="mp-ag-sum-l">Frei</span></div>
  </div>
  <?php $agKw='';
  foreach($days as $d):
    if(isWE($d)) continue;
    $td=($d===date('Y-m-d'));
    $kw=date('W',strtotime($d));
    $isFt=isset($hols[$d])&&$hols[$d]['holiday_type']==='feiertag';
    $isFe=isset($hols[$d])&&!$isFt;
    $bg='';$fg='';$short='';$name='';$remark='';$kind='';
    if(isset($myAb[$d])){ $at=$myAb[$d]['absence_type']; $bg=absenceColor($at);$fg=absenceFg($at);$short=absenceLabel($at);$name=absenceName($at);$remark=$myAb[$d]['remarks']??'';$kind='abw'; }
    elseif(isset($myA[$d])&&isset($pam[$myA[$d]['plan_area_id']])){ $p=$pam[$myA[$d]['plan_area_id']]; $bg=$p['color'];$fg=$p['fg_color'];$short=$p['short_code'];$name=$p['name'];$remark=$myA[$d]['remarks']??'';$kind='ein'; }
    elseif($isFt){ $name=$hols[$d]['name']; $kind='ft'; }
    if($kw!==$agKw): $agKw=$kw; ?>
      <div class="mp-ag-week">Kalenderwoche <?php echo (int)$kw;?></div>
    <?php endif; ?>
    <div class="mp-ag-day<?php if($td) echo ' is-today'; if($isFt) echo ' is-ft'; if(!$bg && !$isFt) echo ' is-free';?>"<?php if($td) echo ' id="mpToday"';?>>
      <div class="mp-ag-date">
        <span class="mp-ag-num"><?php echo (int)date('j',strtotime($d));?></span>
        <span class="mp-ag-wd"><?php echo wdShort($d);?></span>
      </div>
      <div class="mp-ag-content">
        <?php if($kind==='ft'):?>
          <span class="mp-ag-pill mp-ag-pill-ft"><?php echo e($name);?></span>
        <?php elseif($bg):?>
          <span class="mp-ag-pill" style="background:<?php echo e($bg);?>;color:<?php echo e($fg);?>"><?php echo e($short);?> · <?php echo e($name);?></span>
          <?php if($remark):?><div class="mp-ag-remark"><?php echo e($remark);?></div><?php endif;?>
        <?php else:?>
          <span class="mp-ag-free-label">frei</span>
        <?php endif;?>
        <?php if($isFe):?><span class="mp-ag-ferien">Ferien: <?php echo e($hols[$d]['name']);?></span><?php endif;?>
      </div>
      <?php if($td):?><span class="mp-ag-today">Heute</span><?php endif;?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<!-- Professional print header (ALL views) -->
<div class="print-only mp-header">
<div style="display:flex;justify-content:space-between;align-items:baseline;border-bottom:2.5px solid #000;padding-bottom:.4rem;margin-bottom:.5rem">
<h2 style="font-size:14pt;margin:0"><?php echo e($u['first_name'].' '.$u['last_name']);?> – Einsatzplan <?php echo $view==='year'?$y:monthName($m).' '.$y;?></h2>
<span style="font-size:7pt;color:#666">Gedruckt: <?php echo date('d.m.Y H:i:s');?></span>
</div>
<div class="mp-stats">
<span class="mp-stat">Resturlaub: <b><?php echo number_format(vacLeft($u['id'],$y),1,',','');?></b> Tage</span>
<span class="mp-stat">Kranktage: <b><?php echo number_format(sickDays($u['id'],$y),1,',','');?></b></span>
<span class="mp-stat">Gleitzeit: <b><?php echo $glCnt;?></b></span>
</div>
</div>

<?php if($view==='year'):?>
<div class="plan-nav no-print">
<a href="?view=year&year=<?php echo $y-1;?>" class="btn btn-outline">&laquo; <?php echo $y-1;?></a>
<span class="plan-nav-title"><?php echo $y;?></span>
<a href="?view=year&year=<?php echo $y+1;?>" class="btn btn-outline"><?php echo $y+1;?> &raquo;</a>
</div>
<div class="ps ps-yr"><table class="pt pt-yr" id="planTbl">
<thead>
<tr class="yr-month-row">
<th class="sc cn yr-corner" rowspan="2"><?php echo e($u['last_name'].', '.$u['first_name']);?></th>
<?php for($mm=1;$mm<=12;$mm++){$dc=count(daysInMonth($y,$mm));$isCur=($y===(int)date('Y')&&$mm===(int)date('m'));?>
<th colspan="<?php echo $dc;?>" class="yr-ml <?php echo $isCur?'yr-ml-cur':'';?>"><?php echo monthName($mm);?></th>
<?php }?>
</tr>
<tr class="yr-day-row">
<?php for($mm=1;$mm<=12;$mm++){$dd=daysInMonth($y,$mm);foreach($dd as $d){
$dn=(int)date('j',strtotime($d));$we=isWE($d);$td=($d===date('Y-m-d'));
$hl=isset($holsY[$d]);$isFt=$hl&&$holsY[$d]['holiday_type']==='feiertag';
$hlc='';if($isFt)$hlc='hl-ft';elseif($hl)$hlc='hl-fe';$f=($dn===1);
echo '<th class="cy';if($we)echo ' we';if($td)echo ' td';if($hlc)echo ' '.$hlc;if($f)echo ' xb';
echo '">'.$dn.'</th>';}}?>
</tr>
</thead>
<tbody><tr class="plan-row">
<td class="sc cn"><?php echo e($u['last_name'].', '.$u['first_name']);?></td>
<?php for($mm=1;$mm<=12;$mm++){$dd=daysInMonth($y,$mm);foreach($dd as $d){
$we=isWE($d);$td=($d===date('Y-m-d'));
$hl=isset($holsY[$d]);$isFt=$hl&&$holsY[$d]['holiday_type']==='feiertag';
$hlc='';if($isFt)$hlc='hl-ft';elseif($hl)$hlc='hl-fe';$f=((int)date('j',strtotime($d))===1);
$bg='';$fg='';$label='';
if(isset($allAb[$d])){$bg=absenceColor($allAb[$d]['absence_type']);$fg=absenceFg($allAb[$d]['absence_type']);$label=absenceLabel($allAb[$d]['absence_type']);}
elseif(isset($allA[$d])&&isset($pam[$allA[$d]['plan_area_id']])){$bg=$pam[$allA[$d]['plan_area_id']]['color'];$fg=$pam[$allA[$d]['plan_area_id']]['fg_color'];$label=$pam[$allA[$d]['plan_area_id']]['short_code'];}
echo '<td class="cy';if($we)echo ' we';if($td)echo ' td';if($hlc)echo ' '.$hlc;if($f)echo ' xb';
echo '"';if($bg)echo ' style="background:'.$bg.';color:'.($fg?:'#fff').'"';
echo ' title="'.(int)date('j',strtotime($d)).'. '.monthName((int)date('m',strtotime($d))).($label?' – '.$label:'').'">'.$label.'</td>';}}?>
</tr></tbody></table></div>

<?php elseif(($display??'visual')==='visual'):?>
<div class="plan-nav no-print">
<a href="?view=month&display=visual&year=<?php echo $py;?>&month=<?php echo $pm;?>" class="btn btn-outline">&laquo; <?php echo monthName($pm);?></a>
<div class="plan-bar-center"><span class="plan-bar-title"><?php echo monthName($m).' '.$y;?></span><a href="?view=month&display=visual&year=<?php echo(int)date('Y');?>&month=<?php echo(int)date('m');?>" class="btn-today">Heute</a></div>
<a href="?view=month&display=visual&year=<?php echo $ny;?>&month=<?php echo $nm;?>" class="btn btn-outline"><?php echo monthName($nm);?> &raquo;</a>
</div>

<!-- Visual Calendar (screen + print) -->
<div class="card mp-cal-card">
<div class="cb">
<div class="vis-week-header"><span>Mo</span><span>Di</span><span>Mi</span><span>Do</span><span>Fr</span></div>
<div class="vis-month">
<?php
$firstDow = (int)date('N', strtotime($days[0]));
if($firstDow > 5) $firstDow = 1;
for($pad=1;$pad<$firstDow;$pad++) echo '<div class="vis-day vis-day-outside"></div>';
foreach($days as $d):
$we=isWE($d); if($we) continue;
$td=($d===date('Y-m-d'));
$hl=isset($hols[$d]);$isFt=$hl&&$hols[$d]['holiday_type']==='feiertag';
$bg='';$fg='';$label='';$shortLabel='';$remark='';
if(isset($myAb[$d])){$bg=absenceColor($myAb[$d]['absence_type']);$fg=absenceFg($myAb[$d]['absence_type']);$label=absenceName($myAb[$d]['absence_type']);$shortLabel=absenceLabel($myAb[$d]['absence_type']);$remark=$myAb[$d]['remarks']??'';}
elseif(isset($myA[$d])&&isset($pam[$myA[$d]['plan_area_id']])){$p=$pam[$myA[$d]['plan_area_id']];$shortLabel=$p['short_code'];$label=$p['name'];$bg=$p['color'];$fg=$p['fg_color'];$remark=$myA[$d]['remarks']??'';}
elseif($isFt){$label=$hols[$d]['name'];$bg='#fecaca';$fg='#991b1b';}
elseif($hl){$label=$hols[$d]['name'];}
?>
<?php
$isFe = $hl && !$isFt;
?>
<div class="vis-day <?php if($td) echo 'vis-day-today '; if($isFt) echo 'vis-day-hol ';if($isFe) echo 'vis-day-ferien ';if($bg && !$isFt) echo 'vis-day-filled ';?>"<?php if($bg && !$isFt):?> style="border-left:4px solid <?php echo e($bg);?>;background:<?php echo e($bg);?>18"<?php endif;?>>
<div style="display:flex;justify-content:space-between;align-items:baseline">
<span class="vis-day-num"><?php echo (int)date('j',strtotime($d));?></span>
<span class="vis-day-wd"><?php echo wdShort($d);?></span>
</div>
<?php if($isFt):?>
<div class="vis-day-tag"><span class="tag vis-tag-holiday"><?php echo e($label);?></span></div>
<?php elseif($bg):?>
<div class="vis-day-tag"><span class="tag" style="background:<?php echo e($bg);?>;color:<?php echo e($fg);?>"><?php echo e($shortLabel ?: $label);?></span></div>
<?php if($label && $shortLabel):?><div class="vis-day-remark"><?php echo e($label);?></div><?php endif;?>
<?php elseif($label):?>
<div class="vis-day-tag"><span class="tag vis-tag-ferien"><?php echo e($label);?></span></div>
<?php endif;?>
<?php if($remark):?><div class="vis-day-remark" style="font-style:italic"><?php echo e(mb_substr($remark,0,40));?></div><?php endif;?>
<?php if($isFe && $bg):?><div class="vis-day-ferien-label"><?php echo e($hols[$d]['name']);?></div><?php endif;?>
</div>
<?php endforeach;?>
</div>
</div></div>
<?php /* fall through to print table below */ endif;
if($view==='month'):
/* Always render the print-friendly list table */
$showListTable = (($display??'visual')!=='visual');
?>
<?php if($showListTable): ?>
<div class="card mp-listcard">
<div class="plan-nav no-print">
<a href="?view=month&display=list&year=<?php echo $py;?>&month=<?php echo $pm;?>" class="btn btn-outline">&laquo; <?php echo monthName($pm);?></a>
<div class="plan-bar-center"><span class="plan-bar-title"><?php echo monthName($m).' '.$y;?></span><a href="?view=month&display=list&year=<?php echo(int)date('Y');?>&month=<?php echo(int)date('m');?>" class="btn-today">Heute</a></div>
<a href="?view=month&display=list&year=<?php echo $ny;?>&month=<?php echo $nm;?>" class="btn btn-outline"><?php echo monthName($nm);?> &raquo;</a>
</div>
<div class="cb p0">
<table class="tbl my-plan-tbl my-plan-tbl-v2">
<thead><tr><th style="width:90px">Datum</th><th style="width:36px">Tag</th><th style="width:36px">KW</th><th>Einsatz</th><th>Bemerkung</th></tr></thead>
<tbody>
<?php $lastKw=''; foreach($days as $d):
$we=isWE($d);$td=($d===date('Y-m-d'));
$hl=isset($hols[$d]);$isFt=$hl&&$hols[$d]['holiday_type']==='feiertag';$isFe=$hl&&!$isFt;
$bg='';$fg='';$label='';$remark='';
if(isset($myAb[$d])){$bg=absenceColor($myAb[$d]['absence_type']);$fg=absenceFg($myAb[$d]['absence_type']);$label=absenceName($myAb[$d]['absence_type']);$remark=$myAb[$d]['remarks']??'';}
elseif(isset($myA[$d])&&isset($pam[$myA[$d]['plan_area_id']])){$p=$pam[$myA[$d]['plan_area_id']];$label=$p['short_code'].' – '.$p['name'];$bg=$p['color'];$fg=$p['fg_color'];$remark=$myA[$d]['remarks']??'';}
elseif($isFt){$label=$hols[$d]['name'];$bg='#fecaca';$fg='#991b1b';}
elseif($isFe){$label=$hols[$d]['name'];$bg='#dbeafe';$fg='#1d4ed8';}
$curKw=kw($d);$kwChanged=($curKw!==$lastKw);$lastKw=$curKw;
?>
<tr class="<?php if($we) echo 'my-plan-we '; if($td) echo 'my-plan-today '; if($isFt) echo 'my-plan-hol '; if($isFe) echo 'my-plan-ferien '; if($kwChanged && !$we) echo 'my-plan-kw-sep ';?>">
<td style="font-weight:600"><?php echo date('d.m.Y',strtotime($d));?></td>
<td class="my-plan-wd"><?php echo wdShort($d);?></td>
<td class="my-plan-kw"><?php echo $curKw;?></td>
<td><?php if($bg):?><span class="tag" style="background:<?php echo e($bg);?>;color:<?php echo e($fg);?>"><?php echo e($label);?></span><?php elseif(!$we):?><span class="muted">–</span><?php endif;?></td>
<td class="muted" style="font-size:.82rem"><?php echo e($remark);?></td>
</tr>
<?php endforeach;?>
</tbody></table>
</div></div>
<?php endif;?>
<?php endif;?>
</div><!-- /mpTabPlan -->

<?php if($approvalEnabled): ?>
<div id="mpTabRequests" style="display:none">

<style>
/* Meine Anträge – Complete Rework */
.ma-hero{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap}
.ma-stat{flex:1;min-width:90px;text-align:center;padding:.55rem .5rem;border-radius:var(--r2);border:1.5px solid var(--bd);background:var(--card);cursor:pointer}
.ma-stat:hover{transform:translateY(-1px);box-shadow:var(--sh2)}
.ma-stat.active{box-shadow:0 0 0 2px rgba(59,130,246,.18);background:#fafbff}
.ma-stat-n{font-size:1.4rem;font-weight:800;line-height:1}
.ma-stat-l{font-size:.62rem;font-weight:600;color:var(--mu);margin-top:.12rem;text-transform:uppercase;letter-spacing:.03em}
.ma-stat-pending .ma-stat-n{color:#d97706}
.ma-stat-pending{border-color:#fbbf24}
.ma-stat-ok .ma-stat-n{color:#16a34a}
.ma-stat-ok{border-color:#86efac}
.ma-stat-rej .ma-stat-n{color:#dc2626}
.ma-stat-rej{border-color:#fca5a5}
/* Filter bar */
.ma-bar{display:flex;align-items:center;gap:.45rem;margin-bottom:.85rem;flex-wrap:wrap}
.ma-filter-btn{padding:.3rem .7rem;font-size:.75rem;font-weight:600;border:1px solid var(--bd);border-radius:20px;background:var(--card);color:var(--mu);cursor:pointer}
.ma-filter-btn:hover{border-color:var(--pl);color:var(--tx)}
.ma-filter-btn.active{background:var(--p);color:#fff;border-color:var(--p)}
/* Timeline card */
.ma-card{background:var(--card);border:1px solid var(--bd);border-radius:var(--r2);margin-bottom:.65rem;overflow:hidden}
.ma-card:hover{box-shadow:var(--sh2);border-color:var(--bd2)}
.ma-card-head{display:flex;align-items:center;gap:.55rem;padding:.65rem .85rem;cursor:pointer}
.ma-card-line{width:4px;height:36px;border-radius:2px;flex-shrink:0}
.ma-card-body{flex:1;min-width:0}
.ma-card-top{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.ma-card-label{display:inline-block;padding:.12rem .4rem;border-radius:4px;font-size:.7rem;font-weight:700}
.ma-card-date{font-size:.84rem;font-weight:700}
.ma-card-cnt{font-size:.68rem;color:var(--mu)}
.ma-card-sub{display:flex;align-items:center;gap:.5rem;margin-top:.2rem;font-size:.72rem;color:var(--mu)}
.ma-card-status{display:inline-flex;align-items:center;gap:.2rem;padding:.12rem .4rem;border-radius:4px;font-size:.65rem;font-weight:700}
.ma-st-pending{background:#fef3c7;color:#92400e}
.ma-st-approved{background:#dcfce7;color:#16a34a}
.ma-st-rejected{background:#fef2f2;color:#dc2626}
.ma-card-right{display:flex;align-items:center;gap:.4rem;margin-left:auto;flex-shrink:0}
/* Expanded detail inside card */
.ma-card-detail{display:none;border-top:1px solid var(--bd);padding:.55rem .85rem;background:#fafbfc}
.ma-card.expanded .ma-card-detail{display:block}
.ma-day{display:flex;align-items:center;gap:.4rem;padding:.3rem 0;border-bottom:1px solid var(--bd);font-size:.78rem}
.ma-day:last-child{border-bottom:none}
.ma-day-wd{font-weight:600;color:var(--mu);min-width:22px;font-size:.7rem}
.ma-day-d{font-weight:600;min-width:70px}
.ma-day-tag{display:inline-block;padding:.05rem .3rem;border-radius:3px;font-size:.6rem;font-weight:700}
/* Rejection reason */
.ma-rej-box{margin-top:.35rem;padding:.35rem .55rem;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--r);font-size:.75rem;color:var(--err)}
.ma-rej-box b{font-weight:700}
/* Remark */
.ma-remark{font-size:.72rem;color:var(--mu);font-style:italic;margin-top:.15rem}
/* Bearbeiter */
.ma-approver{font-size:.68rem;color:var(--mu);margin-top:.2rem;display:flex;align-items:center;gap:.25rem}
.ma-approver svg{width:12px;height:12px}
/* Empty */
.ma-empty{text-align:center;padding:3rem 1rem}
.ma-empty svg{width:52px;height:52px;color:var(--bd2);margin-bottom:.5rem}
.ma-empty-title{font-size:.95rem;font-weight:600;margin-bottom:.2rem}
.ma-empty-sub{font-size:.78rem;color:var(--mu)}
/* Pagination */
.ma-pag{display:flex;justify-content:center;align-items:center;gap:.5rem;padding:.65rem 0}
/* Chevron in card header */
.ma-chevron{width:16px;height:16px;color:var(--mu);flex-shrink:0}
.ma-card.expanded .ma-chevron{transform:rotate(180deg)}
</style>

<!-- Stats -->
<div class="ma-hero">
<div class="ma-stat ma-stat-pending active" data-action="mrFilter" data-args='["pending"]'><div class="ma-stat-n" id="mrSP">–</div><div class="ma-stat-l">Offen</div></div>
<div class="ma-stat ma-stat-ok" data-action="mrFilter" data-args='["approved"]'><div class="ma-stat-n" id="mrSA">–</div><div class="ma-stat-l">Genehmigt</div></div>
<div class="ma-stat ma-stat-rej" data-action="mrFilter" data-args='["rejected"]'><div class="ma-stat-n" id="mrSR">–</div><div class="ma-stat-l">Abgelehnt</div></div>
</div>

<!-- Filter bar with pill buttons -->
<div class="ma-bar">
<button class="ma-filter-btn" data-v="" data-action="mrSetFilter" data-args='["$this"]'>Alle</button>
<button class="ma-filter-btn active" data-v="pending" data-action="mrSetFilter" data-args='["$this"]'>Offen</button>
<button class="ma-filter-btn" data-v="approved" data-action="mrSetFilter" data-args='["$this"]'>Genehmigt</button>
<button class="ma-filter-btn" data-v="rejected" data-action="mrSetFilter" data-args='["$this"]'>Abgelehnt</button>
<div style="margin-left:auto;display:flex;gap:.3rem">
<button class="ma-filter-btn active" id="mrTabCur2" data-action="mrSetTab2" data-args='["current"]'>Aktuell</button>
<button class="ma-filter-btn" id="mrTabArc2" data-action="mrSetTab2" data-args='["archive"]'>Archiv</button>
</div>
</div>

<div id="myReqList"><p class="muted" style="padding:2rem;text-align:center">Laden...</p></div>

<!-- Hidden select for compatibility -->
<select id="myReqFilter" style="display:none">
<option value="">Alle</option><option value="pending"<?php echo ($u['role']==='mitarbeiter')?' selected':'';?>>Offen</option><option value="approved">Genehmigt</option><option value="rejected">Abgelehnt</option>
</select>

<script nonce="<?php echo opsCspNonce(); ?>">
/* Mobile-Agenda: zu "Heute" springen. Ist heute im aktuell gezeigten Monat
   sichtbar, sanft dorthin scrollen; sonst dem Link in den aktuellen Monat folgen. */
function mpJumpToday(){
  var t=document.getElementById('mpToday');
  if(t){ t.scrollIntoView({behavior:'smooth',block:'center'}); t.classList.add('mp-ag-flash'); setTimeout(function(){t.classList.remove('mp-ag-flash');},1400); return false; }
  return true;
}
document.addEventListener('DOMContentLoaded',function(){
  if(window.location.hash==='#mpToday'){ var t=document.getElementById('mpToday'); if(t){ setTimeout(function(){ t.scrollIntoView({block:'center'}); },60); } }
});
var MR_AREAS = <?php echo json_encode($pam); ?>;
var mrAN={krank_halb:'Krank \u00bd',krank_ganz:'Krank o.A.',krank_attest:'Krank m.A.',urlaub_halb:'Urlaub \u00bd',urlaub_ganz:'Urlaub (b.)',urlaub_genehmigt:'Urlaub (g.)',gleitzeit:'Gleitzeit',gesperrt:'Gesperrt'};
var mrAC={krank_halb:'#ef4444',krank_ganz:'#dc2626',krank_attest:'#16a34a',urlaub_halb:'#fbbf24',urlaub_ganz:'#dc2626',urlaub_genehmigt:'#16a34a',gleitzeit:'#8b5cf6',gesperrt:'#94a3b8'};
var mrAF={urlaub_halb:'#000'};
var mrPage=0,mrPageSize=20,mrAllReqs=[],mrTab='current',mrStatus='<?php echo ($u['role']==='mitarbeiter')?'pending':'';?>';

function mrFilter(s){
    mrStatus=s;
    document.querySelectorAll('.ma-filter-btn[data-v]').forEach(function(b){b.classList.toggle('active',b.dataset.v===s);});
    document.querySelectorAll('.ma-stat').forEach(function(s){s.classList.remove('active');});
    var m={'pending':'.ma-stat-pending','approved':'.ma-stat-ok','rejected':'.ma-stat-rej'};
    if(m[s]){var el=document.querySelector(m[s]);if(el)el.classList.add('active');}
    document.getElementById('myReqFilter').value=s;
    loadMyRequests();
}
function mrSetFilter(btn){mrFilter(btn.dataset.v);}
function mrSetTab2(tab){
    mrTab=tab;mrPage=0;
    document.getElementById('mrTabCur2').classList.toggle('active',tab==='current');
    document.getElementById('mrTabArc2').classList.toggle('active',tab==='archive');
    loadMyRequests();
}
function loadMyRequests(){
    var status=mrStatus;
    var el=document.getElementById('myReqList');
    el.innerHTML='<p class="muted" style="padding:2rem;text-align:center">Laden...</p>';
    mrPage=0;
    /* Stats */
    fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'dashboard_stats'})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success)return;
        var e1=document.getElementById('mrSP'),e2=document.getElementById('mrSA'),e3=document.getElementById('mrSR');
        if(e1)e1.textContent=d.my_pending||0;if(e2)e2.textContent=d.my_approved_week||0;if(e3)e3.textContent=d.my_rejected_week||0;
    }).catch(function(){});
    /* Data */
    fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'my_requests',status:status})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success||!d.requests||!d.requests.length){
            var emptyIcon=mrTab==='archive'?'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4':'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z';
            el.innerHTML='<div class="ma-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="'+emptyIcon+'"/></svg><div class="ma-empty-title">'+(mrTab==='current'?'Keine aktuellen Antr\u00e4ge':'Kein Archiv')+'</div><div class="ma-empty-sub">'+(status==='pending'?'Alle Antr\u00e4ge wurden bearbeitet':status==='rejected'?'Keine abgelehnten Antr\u00e4ge':'Neue Antr\u00e4ge k\u00f6nnen \u00fcber den Gesamtplan eingereicht werden')+'</div></div>';
            return;
        }
        mrAllReqs=d.requests;
        var now=new Date(),weekAgo=new Date(now.getTime()-7*864e5);
        if(mrTab==='current')mrAllReqs=mrAllReqs.filter(function(r){return new Date(r.created_at)>=weekAgo;});
        else mrAllReqs=mrAllReqs.filter(function(r){return new Date(r.created_at)<weekAgo;});
        renderMR();
    }).catch(function(e){el.innerHTML='<p class="txt-d" style="padding:1rem">'+esc(e.message)+'</p>';});
}

pqAction({mrPrevPage:function(){mrPage--;renderMR();},mrNextPage:function(){mrPage++;renderMR();}});
function renderMR(){
    var el=document.getElementById('myReqList'),reqs=mrAllReqs,total=reqs.length;
    if(!total){
        el.innerHTML='<div class="ma-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><div class="ma-empty-title">'+(mrTab==='current'?'Keine aktuellen Antr\u00e4ge':'Kein Archiv')+'</div><div class="ma-empty-sub">Antr\u00e4ge der letzten 7 Tage erscheinen unter Aktuell</div></div>';
        return;
    }
    var start=mrPage*mrPageSize,end=Math.min(start+mrPageSize,total);
    var ranges=mrMerge(reqs.slice(start,end));
    var html='';
    ranges.forEach(function(rng,idx){
        var r0=rng.items[0],rL=rng.items[rng.items.length-1];
        var stCls=r0.status==='pending'?'ma-st-pending':r0.status==='approved'?'ma-st-approved':'ma-st-rejected';
        var stLbl=r0.status==='pending'?'Offen':r0.status==='approved'?'Genehmigt':'Abgelehnt';
        var stIcon='';
        var ds=mrFd(r0.plan_date);if(rng.items.length>1)ds=mrFd(r0.plan_date)+' \u2013 '+mrFd(rL.plan_date);

        html+='<div class="ma-card" id="mac'+idx+'">';
        /* Clickable header */
        html+='<div class="ma-card-head" data-action="toggleMaCard" data-args="['+idx+']">';
        html+='<div class="ma-card-line" style="background:'+rng.bg+'"></div>';
        html+='<div class="ma-card-body">';
        html+='<div class="ma-card-top">';
        html+='<span class="ma-card-label" style="background:'+rng.bg+';color:'+rng.fg+'">'+esc(rng.label)+'</span>';
        html+='<span class="ma-card-date">'+ds+'</span>';
        html+='<span class="ma-card-cnt">'+rng.items.length+' Tag(e)</span>';
        html+='</div>';
        html+='<div class="ma-card-sub">';
        html+='<span class="ma-card-status '+stCls+'">'+stIcon+' '+stLbl+'</span>';
        /* Created date */
        if(r0.created_at){var cd=new Date(r0.created_at);html+='<span>Eingereicht: '+('0'+cd.getDate()).slice(-2)+'.'+('0'+(cd.getMonth()+1)).slice(-2)+'.'+cd.getFullYear()+'</span>';}
        html+='</div>';
        html+='</div>';
        html+='<div class="ma-card-right">';
        html+='<svg class="ma-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';
        html+='</div>';
        html+='</div>';

        /* Expandable detail */
        html+='<div class="ma-card-detail">';
        /* Individual days */
        rng.items.forEach(function(item){
            html+='<div class="ma-day">';
            html+='<span class="ma-day-wd">'+mrGwd(item.plan_date)+'</span>';
            html+='<span class="ma-day-d">'+mrFd(item.plan_date)+'</span>';
            html+='<span class="ma-day-tag" style="background:'+rng.bg+';color:'+rng.fg+'">'+esc(rng.label)+'</span>';
            if(item.remarks)html+='<span class="muted" style="font-size:.7rem;margin-left:auto;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(item.remarks)+'">'+esc(item.remarks)+'</span>';
            html+='</div>';
        });
        /* Rejection reason */
        if(r0.status==='rejected'&&r0.rejection_reason){
            html+='<div class="ma-rej-box"><b>Ablehnungsgrund:</b> '+esc(r0.rejection_reason)+'</div>';
        }
        /* Remark */
        if(r0.remarks){
            html+='<div class="ma-remark">'+esc(r0.remarks)+'</div>';
        }
        /* Approver */
        if(r0.app_first){
            html+='<div class="ma-approver"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>Bearbeitet von '+esc(r0.app_first+' '+(r0.app_last||''))+'</div>';
        }
        html+='</div>';
        html+='</div>';
    });

    /* Pagination */
    var tp=Math.ceil(total/mrPageSize);
    if(tp>1){
        html+='<div class="ma-pag">';
        if(mrPage>0)html+='<button class="btn btn-xs btn-outline" data-action="mrPrevPage">&laquo; Zurück</button>';
        html+='<span class="muted" style="font-size:.75rem">Seite '+(mrPage+1)+' von '+tp+'</span>';
        if(mrPage<tp-1)html+='<button class="btn btn-xs btn-outline" data-action="mrNextPage">Weiter &raquo;</button>';
        html+='</div>';
    }
    el.innerHTML=html;
}

function toggleMaCard(idx){
    var card=document.getElementById('mac'+idx);
    if(card)card.classList.toggle('expanded');
}

function mrMerge(items){
    items.sort(function(a,b){return a.plan_date.localeCompare(b.plan_date);});
    var ranges=[],cur=null;
    items.forEach(function(r){
        var key=(r.area_code||'')+'|'+(r.absence_type||'')+'|'+(r.status||'');
        var lb='',bg='',fg='#fff';
        if(r.area_code){lb=r.area_code+(r.area_name?' \u2013 '+r.area_name:'');bg=r.area_color||'';fg=r.area_fg||'#fff';}
        else if(r.absence_type){lb=mrAN[r.absence_type]||r.absence_type;bg=mrAC[r.absence_type]||'#ef4444';fg=mrAF[r.absence_type]||'#fff';}
        if(cur&&cur.key===key&&mrIsNext(cur.last,r.plan_date)){cur.items.push(r);cur.last=r.plan_date;}
        else{if(cur)ranges.push(cur);cur={key:key,label:lb,bg:bg,fg:fg,items:[r],last:r.plan_date};}
    });if(cur)ranges.push(cur);return ranges;
}
function mrIsNext(d1,d2){var a=new Date(d1+'T12:00:00'),b=new Date(d2+'T12:00:00');return(b-a)/864e5>=1&&(b-a)/864e5<=3;}
function mrFd(d){var p=d.split('-');return p[2]+'.'+p[1]+'.'+p[0];}
function mrGwd(d){return['So','Mo','Di','Mi','Do','Fr','Sa'][new Date(d+'T12:00:00').getDay()];}

document.addEventListener('DOMContentLoaded',function(){
    /* Set initial filter pill active */
    var initStatus='<?php echo ($u['role']==='mitarbeiter')?'pending':'';?>';
    if(initStatus){
        document.querySelectorAll('.ma-filter-btn[data-v]').forEach(function(b){b.classList.toggle('active',b.dataset.v===initStatus);});
    }
    loadMyRequests();
});

function switchMpTab(idx){
    document.querySelectorAll('.mp-tab').forEach(function(t,i){t.classList.toggle('mp-tab-active',i===idx);});
    var plan=document.getElementById('mpTabPlan'),reqs=document.getElementById('mpTabRequests');
    if(idx===0){if(plan)plan.style.display='';if(reqs)reqs.style.display='none';}
    else{if(plan)plan.style.display='none';if(reqs)reqs.style.display='';loadMyRequests();}
}
</script>
</div><!-- /mpTabRequests -->
<?php endif; ?>
<?php require_once __DIR__.'/../includes/legend.php';?>
<?php require_once __DIR__.'/../includes/footer.php';?>
