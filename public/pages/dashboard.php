<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
$u = currentUser(); $today = date('Y-m-d'); $y = (int)date('Y'); $m = (int)date('m'); $cwk = (int)date('W');
$see = canSeeDetails();
$showAbsent = getSetting('dashboard_show_absent','1') !== '0';
$absent = $showAbsent ? todayAbsent() : [];

$sow = date('Y-m-d', strtotime('monday this week')); $eow = date('Y-m-d', strtotime('friday this week'));
$wd = []; $d = $sow; while ($d <= $eow) { $wd[] = $d; $d = date('Y-m-d', strtotime($d.' +1 day')); }

$s = getDB()->prepare('SELECT a.plan_date, a.plan_area_id, pa.short_code, pa.color, pa.fg_color, pa.name FROM assignments a LEFT JOIN plan_areas pa ON pa.id=a.plan_area_id WHERE a.user_id=? AND a.plan_date BETWEEN ? AND ? AND a.is_published=1');
$s->execute([$u['id'], $sow, $eow]); $ma = []; foreach ($s->fetchAll() as $r) $ma[$r['plan_date']] = $r;

$s2 = getDB()->prepare('SELECT absence_date, absence_type, remarks FROM absences WHERE user_id=? AND absence_date BETWEEN ? AND ? AND is_published=1');
$s2->execute([$u['id'], $sow, $eow]); $mab = []; foreach ($s2->fetchAll() as $r) $mab[$r['absence_date']] = $r;

$nsow = date('Y-m-d', strtotime('next monday', strtotime($eow)));
$neow = date('Y-m-d', strtotime('+4 days', strtotime($nsow)));
$nwd = []; $nd = $nsow; while ($nd <= $neow) { $nwd[] = $nd; $nd = date('Y-m-d', strtotime($nd.' +1 day')); }
$sn = getDB()->prepare('SELECT a.plan_date, pa.short_code, pa.color, pa.fg_color, pa.name FROM assignments a LEFT JOIN plan_areas pa ON pa.id=a.plan_area_id WHERE a.user_id=? AND a.plan_date BETWEEN ? AND ? AND a.is_published=1');
$sn->execute([$u['id'], $nsow, $neow]); $nma = []; foreach ($sn->fetchAll() as $r) $nma[$r['plan_date']] = $r;
$sn2 = getDB()->prepare('SELECT absence_date, absence_type FROM absences WHERE user_id=? AND absence_date BETWEEN ? AND ? AND is_published=1');
$sn2->execute([$u['id'], $nsow, $neow]); $nmab = []; foreach ($sn2->fetchAll() as $r) $nmab[$r['absence_date']] = $r;

$wots = userWots($u['id'], $y, $m);
$stats = $see ? teamStats($y, $m) : null;
$upHols = upcomingHolidays($y, 5);
$pam4w = planAreaMap();
$holsW = holidays((int)date('Y'),(int)date('m'));
$holsNW = holidays((int)date('Y',strtotime($nsow)),(int)date('m',strtotime($nsow)));
$holsAll = array_merge($holsW, $holsNW);

/* Publication status for current + next month */
$lp = lastPublished($y, $m);
$nm2 = $m+1; $ny2 = $y; if($nm2>12){$nm2=1;$ny2++;}
$lpNext = lastPublished($ny2, $nm2);

/* Today's assignment for hero */
$todayAsgn = null; $todayAbs = null;
if (isset($mab[$today])) $todayAbs = $mab[$today];
elseif (isset($ma[$today])) $todayAsgn = $ma[$today];

/* Gleitzeit count */
$glS=getDB()->prepare("SELECT COUNT(*) c FROM absences WHERE user_id=? AND YEAR(absence_date)=? AND absence_type='gleitzeit' AND is_published=1");$glS->execute([$u['id'],$y]);$glCnt=(int)$glS->fetch()['c'];
?>

<?php $maint = upcomingMaintenance(3); if(!empty($maint)): foreach($maint as $mt): ?>
<div class="maint-banner">
<div class="maint-banner-icon"><svg viewBox="0 0 20 20" fill="currentColor" style="width:20px;height:20px"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg></div>
<div class="maint-banner-body">
<div class="maint-banner-title"><?php echo e($mt['title']);?></div>
<?php if(!empty($mt['description'])):?><div class="maint-banner-desc"><?php echo e($mt['description']);?></div><?php endif;?>
<div class="maint-banner-date"><?php echo date('d.m.Y H:i',strtotime($mt['start_date']));?><?php if(!empty($mt['end_date'])):?> – <?php echo date('d.m.Y H:i',strtotime($mt['end_date']));?><?php endif;?></div>
</div>
</div>
<?php endforeach; endif; ?>

<?php if (hasPerm('manage_settings')) { require_once __DIR__ . '/../../app/lib/Backup.php'; $bkWarn = Backup::dashboardWarning(); if ($bkWarn): ?>
<div class="alert alert-err" id="backupBanner" style="font-weight:500;font-size:.8rem">Backup: <?php echo e($bkWarn); ?> <a href="einstellungen" style="color:inherit;text-decoration:underline">Einstellungen → Backup</a></div>
<?php endif; } ?>

<!-- Header -->
<div class="ph">
  <div class="phl">
    <h1><?php echo e($u['first_name'].' '.$u['last_name']); ?></h1>
    <span class="muted" style="font-size:.82rem"><?php echo e(roleName($u['role'])); ?> | KW <?php echo $cwk; ?>, <?php echo wdShort($today); ?> <?php echo date('d.',strtotime($today)).monthName($m).' '.$y; ?></span>
    <div class="pq-today" aria-label="Heute">
      <span class="pq-today-lbl">Heute</span>
      <?php if (isset($holsAll[$today]) && $holsAll[$today]['holiday_type'] === 'feiertag'): ?><span class="pq-chip pq-chip-hol"><?php echo e($holsAll[$today]['name']); ?></span>
      <?php elseif ($todayAbs): ?><span class="pq-chip" style="background:<?php echo e(absenceColor($todayAbs['absence_type'])); ?>;color:<?php echo e(absenceFg($todayAbs['absence_type'])); ?>"><?php echo e(absenceLabel($todayAbs['absence_type'])); ?></span> <span><?php echo e(absenceName($todayAbs['absence_type'])); ?></span>
      <?php elseif ($todayAsgn): ?><span class="pq-chip" style="background:<?php echo e($todayAsgn['color']); ?>;color:<?php echo e($todayAsgn['fg_color'] ?? '#fff'); ?>"><?php echo e($todayAsgn['short_code']); ?></span> <span><?php echo e($todayAsgn['name'] ?? ''); ?></span>
      <?php else: ?><span class="muted">Kein veröffentlichter Einsatz</span><?php endif; ?>
    </div>
  </div>
  <div class="phr">
    <?php if (canEdit()): ?>
    <a href="monatsplanung" class="btn btn-primary desk-only">Planung</a>
    <?php endif; ?>
    <a href="gesamtplan" class="btn btn-outline desk-only">Gesamtplan</a>
    <a href="mein_plan" class="btn btn-outline">Mein Plan</a>
  </div>
</div>

<?php /* Reihenfolge je Rolle (planning-plus.css): Planer zuerst ihre Aufgaben, Mitarbeitende zuerst ihre Woche */ ?>
<div class="pq-dash <?php echo canEdit() ? 'pq-dash-planner' : 'pq-dash-staff'; ?>">
<!-- Announcements -->
<div id="dashAnnouncements" class="pq-ds-ann"<?php if (!opsWidget('announcements')) echo ' hidden'; ?>></div>
<?php require __DIR__ . '/../includes/dashboard_planning.php'; /* Planer: Heute zu tun */ ?>

<!-- KPI Row -->
<?php if (opsWidget('kpis')): ?>
<div class="g pq-ds-kpis" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.65rem;margin-bottom:1rem">
  <?php if ($see): ?>
  <div class="card" style="padding:.75rem 1rem">
    <div style="font-size:.68rem;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.04em">Team</div>
    <div style="font-size:1.5rem;font-weight:800;line-height:1.1;margin-top:.15rem"><?php echo $stats['totalUsers']; ?></div>
    <div style="font-size:.7rem;color:var(--mu)">Mitarbeiter aktiv</div>
  </div>
  <?php endif; ?>
  <div class="card" style="padding:.75rem 1rem">
    <div style="font-size:.68rem;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.04em">Resturlaub</div>
    <div style="font-size:1.5rem;font-weight:800;line-height:1.1;margin-top:.15rem;color:#16a34a"><?php echo number_format(vacLeft($u['id'],$y),1,',',''); ?></div>
    <div style="font-size:.7rem;color:var(--mu)"><?php echo number_format(vacUsed($u['id'],$y),1,',',''); ?> von <?php echo number_format((float)$u['vacation_days'],1,',',''); ?> genommen</div>
    <?php $pct = $u['vacation_days'] > 0 ? min(100, (vacUsed($u['id'],$y) / (float)$u['vacation_days']) * 100) : 0; ?>
    <div style="width:100%;height:4px;background:var(--bd);border-radius:2px;margin-top:.35rem;overflow:hidden"><div style="height:100%;border-radius:2px;width:<?php echo $pct; ?>%;background:<?php echo $pct>90?'#dc2626':($pct>70?'#f59e0b':'#16a34a'); ?>"></div></div>
  </div>
  <div class="card" style="padding:.75rem 1rem">
    <div style="font-size:.68rem;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.04em">Kranktage</div>
    <div style="font-size:1.5rem;font-weight:800;line-height:1.1;margin-top:.15rem;color:#dc2626"><?php echo number_format(sickDays($u['id'],$y),1,',',''); ?></div>
    <div style="font-size:.7rem;color:var(--mu)"><?php echo $y; ?></div>
  </div>
  <div class="card" style="padding:.75rem 1rem">
    <div style="font-size:.68rem;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.04em">Gleitzeit</div>
    <div style="font-size:1.5rem;font-weight:800;line-height:1.1;margin-top:.15rem;color:#7c3aed"><?php echo $glCnt; ?></div>
    <div style="font-size:.7rem;color:var(--mu)"><?php echo $y; ?></div>
  </div>
  <div class="card" style="padding:.75rem 1rem">
    <div style="font-size:.68rem;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.04em">WOTs</div>
    <div style="font-size:1.5rem;font-weight:800;line-height:1.1;margin-top:.15rem;color:#d97706"><?php echo count($wots); ?></div>
    <div style="font-size:.7rem;color:var(--mu)"><?php echo monthName($m).' '.$y; ?></div>
  </div>
</div>

<?php endif; /* kpis */ ?>

<!-- Today highlight + Week -->
<?php if (opsWidget('week')): ?>
<div class="card pq-ds-week" style="margin-bottom:1rem">
  <div class="ch" style="display:flex;justify-content:space-between;align-items:center">
    <span>Meine Woche – KW <?php echo $cwk; ?>
      <span class="muted" style="font-size:.72rem;font-weight:400"><?php echo date('d.m.',strtotime($sow)).' – '.date('d.m.',strtotime($eow)); ?></span>
    </span>
    <a href="mein_plan" class="btn btn-xs btn-outline">Vollständiger Plan</a>
  </div>
  <div class="cb">
    <div class="dash-week-grid">
      <?php foreach ($wd as $w):
        $isToday = ($w === $today);
        $hl = isset($holsAll[$w]);
        $isFt = $hl && $holsAll[$w]['holiday_type']==='feiertag';
        $isFe = $hl && !$isFt;
        $hasAsgn = isset($ma[$w]);
        $hasAbs = isset($mab[$w]);
        $accentColor = '';
        if($hasAbs) $accentColor = absenceColor($mab[$w]['absence_type']);
        elseif($hasAsgn) $accentColor = $ma[$w]['color'] ?? '';
        elseif($isFt) $accentColor = '#ef4444';
        elseif($isFe) $accentColor = '#60a5fa';
      ?>
      <div class="dash-week-day <?php if($isToday) echo 'dash-week-today '; if($isFt) echo 'dash-week-hol '; if($isFe) echo 'dash-week-ferien '; ?>"<?php if($accentColor):?> style="border-top:3px solid <?php echo $accentColor;?>"<?php endif;?>>
        <div class="dash-week-head"><?php echo wdShort($w); ?></div>
        <div class="dash-week-date<?php if($isToday) echo ' dash-week-date-today';?>"><?php echo date('d.m.',strtotime($w)); ?></div>
        <?php if($isFt): ?>
          <div class="dash-week-tag"><span class="tag dash-tag-holiday"><?php echo e($holsAll[$w]['name']);?></span></div>
        <?php elseif($hasAbs): ?>
          <div class="dash-week-tag"><span class="tag" style="background:<?php echo absenceColor($mab[$w]['absence_type']); ?>;color:<?php echo absenceFg($mab[$w]['absence_type']); ?>"><?php echo absenceLabel($mab[$w]['absence_type']); ?></span></div>
          <div class="dash-week-name"><?php echo absenceName($mab[$w]['absence_type']); ?></div>
          <?php if($isFe): ?><div class="dash-week-hol-sub"><?php echo e($holsAll[$w]['name']);?></div><?php endif; ?>
        <?php elseif($hasAsgn): ?>
          <div class="dash-week-tag"><span class="tag" style="background:<?php echo e($ma[$w]['color']); ?>;color:<?php echo e($ma[$w]['fg_color']??'#fff'); ?>"><?php echo e($ma[$w]['short_code']); ?></span></div>
          <div class="dash-week-name"><?php echo e($ma[$w]['name']??''); ?></div>
          <?php if($isFe): ?><div class="dash-week-hol-sub"><?php echo e($holsAll[$w]['name']);?></div><?php endif; ?>
        <?php elseif($isFe): ?>
          <div class="dash-week-tag"><span class="tag dash-tag-ferien"><?php echo e($holsAll[$w]['name']);?></span></div>
        <?php else: ?>
          <div class="dash-week-empty"><span class="dash-week-empty-dot"></span>Kein Einsatz</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <!-- Next week preview -->
    <div style="margin-top:.75rem;padding-top:.65rem;border-top:1px solid var(--bd)">
      <div style="font-size:.68rem;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem">Vorschau KW <?php echo $cwk+1; ?>
        <span style="font-weight:400;text-transform:none"><?php echo date('d.m.',strtotime($nsow)).' – '.date('d.m.',strtotime($neow)); ?></span>
      </div>
      <div class="dash-week-grid dash-week-grid-sm">
        <?php foreach ($nwd as $w):
          $hl = isset($holsAll[$w]);
          $isFt = $hl && $holsAll[$w]['holiday_type']==='feiertag';
          $isFe = $hl && !$isFt;
          $nHasAbs = isset($nmab[$w]);
          $nHasAsgn = isset($nma[$w]);
          $nAccent = '';
          if($nHasAbs) $nAccent = absenceColor($nmab[$w]['absence_type']);
          elseif($nHasAsgn) $nAccent = $nma[$w]['color'] ?? '';
          elseif($isFt) $nAccent = '#ef4444';
          elseif($isFe) $nAccent = '#60a5fa';
        ?>
        <div class="dash-week-day dash-week-day-sm <?php if($isFt) echo 'dash-week-hol '; if($isFe) echo 'dash-week-ferien '; ?>"<?php if($nAccent):?> style="border-top:2px solid <?php echo $nAccent;?>"<?php endif;?>>
          <div class="dash-week-head"><?php echo wdShort($w); ?> <span style="font-weight:400;font-size:.62rem"><?php echo date('d.',strtotime($w));?></span></div>
          <?php if($isFt): ?>
            <div class="dash-week-tag"><span class="tag dash-tag-holiday" style="font-size:.58rem"><?php echo e($holsAll[$w]['name']);?></span></div>
          <?php elseif($nHasAbs): ?>
            <div class="dash-week-tag"><span class="tag" style="background:<?php echo absenceColor($nmab[$w]['absence_type']);?>;color:<?php echo absenceFg($nmab[$w]['absence_type']);?>;font-size:.6rem"><?php echo absenceLabel($nmab[$w]['absence_type']);?></span></div>
            <?php if($isFe): ?><div class="dash-week-hol-sub"><?php echo e($holsAll[$w]['name']);?></div><?php endif; ?>
          <?php elseif($nHasAsgn): ?>
            <div class="dash-week-tag"><span class="tag" style="background:<?php echo e($nma[$w]['color']);?>;color:<?php echo e($nma[$w]['fg_color']??'#fff');?>;font-size:.6rem"><?php echo e($nma[$w]['short_code']);?></span></div>
            <?php if($isFe): ?><div class="dash-week-hol-sub"><?php echo e($holsAll[$w]['name']);?></div><?php endif; ?>
          <?php elseif($isFe): ?>
            <div class="dash-week-tag"><span class="tag dash-tag-ferien" style="font-size:.58rem"><?php echo e($holsAll[$w]['name']);?></span></div>
          <?php else: ?>
            <div style="font-size:.62rem;color:var(--mu);text-align:center;margin-top:.1rem">–</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php endif; /* week */ ?>

<!-- WOTs directly under week (if any) -->
<?php if (!empty($wots) && opsWidget('wots') && opsModule('wots')): ?>
<div class="card pq-ds-wots" style="margin-bottom:1rem;border-left:3px solid #d97706">
  <div class="ch" style="display:flex;justify-content:space-between;align-items:center">
    <span>WOTs – <?php echo monthName($m).' '.$y; ?>
      <span class="muted" style="font-size:.7rem;font-weight:400"><?php echo count($wots); ?> Einsatz<?php echo count($wots)!==1?'e':''; ?></span>
    </span>
  </div>
  <div class="cb p0">
    <table class="tbl" style="font-size:.82rem;margin:0">
      <thead><tr><th style="width:100px">WOT-Nr.</th><th>Bemerkung</th><?php if(count($wots)>0 && ($wots[0]['link_url']??'')):?><th style="width:80px">Link</th><?php endif;?></tr></thead>
      <tbody>
      <?php foreach ($wots as $ww): ?>
      <tr>
        <td><span style="display:inline-flex;align-items:center;gap:.3rem"><span style="width:10px;height:10px;border-radius:2px;background:<?php echo e($ww['color']??'#3b82f6');?>;flex-shrink:0"></span><b><?php echo e($ww['wot_number']); ?></b></span></td>
        <td style="color:var(--tx2)"><?php echo e($ww['remarks'] ?? '–'); ?></td>
        <?php if($ww['link_url']??''):?><td><a href="<?php echo e(opsSafeUrl($ww['link_url']) ?: '#');?>" target="_blank" rel="noopener noreferrer" style="font-size:.75rem">Öffnen</a></td><?php endif;?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Antraege (if approval system active) -->
<?php if(getSetting('approval_system_enabled','0')==='1' && opsWidget('requests')): ?>
<div class="card mb pq-ds-req" id="dashApprovalCard" style="display:none;border-left:3px solid var(--p)">
<div class="ch">Änderungsanträge</div>
<div class="cb">
<div class="dash-approval-grid" id="dashApprovalGrid"></div>
</div>
</div>
<script>
(function(){
    var base=window.location.pathname;base=(base.indexOf('/pages/')!==-1||base.indexOf('/api/')!==-1)?'../':'';
    fetch(base+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'dashboard_stats'})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success)return;
        var show=d.my_pending>0||d.my_approved_week>0||d.my_rejected_week>0||d.to_approve>0;
        if(!show)return;
        var card=document.getElementById('dashApprovalCard');card.style.display='';
        var html='';
        if(d.my_pending>0) html+='<div class="dash-approval-item"><div class="dash-approval-num" style="color:var(--wn)">'+d.my_pending+'</div><div class="dash-approval-label">Meine offenen</div></div>';
        if(d.my_approved_week>0) html+='<div class="dash-approval-item"><div class="dash-approval-num" style="color:var(--ok)">'+d.my_approved_week+'</div><div class="dash-approval-label">Genehmigt (7d)</div></div>';
        if(d.my_rejected_week>0) html+='<div class="dash-approval-item"><div class="dash-approval-num" style="color:var(--err)">'+d.my_rejected_week+'</div><div class="dash-approval-label">Abgelehnt (7d)</div></div>';
        if(d.to_approve>0) html+='<div class="dash-approval-item" style="border:2px solid var(--p)"><div class="dash-approval-num" style="color:var(--p)">'+d.to_approve+'</div><div class="dash-approval-label">Zu genehmigen</div></div>';
        document.getElementById('dashApprovalGrid').innerHTML=html;
    }).catch(function(){});
})();
</script>
<?php endif; ?>

<!-- Two-column grid: Status + Team OR Abwesend + Feiertage -->
<div class="g dash-two pq-ds-more" style="margin-bottom:1rem">
  <!-- Publication Status -->
  <?php if (canEdit() && opsWidget('publication')): ?>
  <div class="card">
    <div class="ch">Planstatus</div>
    <div class="cb">
      <div style="display:flex;flex-direction:column;gap:.55rem">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.45rem .6rem;background:var(--bg);border-radius:var(--r)">
          <div><div style="font-size:.82rem;font-weight:600"><?php echo monthName($m).' '.$y; ?></div><div style="font-size:.7rem;color:var(--mu)">Aktueller Monat</div></div>
          <?php if($lp):?><span class="badge bs"><?php echo date('d.m. H:i',strtotime($lp));?></span><?php else:?><span class="badge bw">Entwurf</span><?php endif;?>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.45rem .6rem;background:var(--bg);border-radius:var(--r)">
          <div><div style="font-size:.82rem;font-weight:600"><?php echo monthName($nm2).' '.$ny2; ?></div><div style="font-size:.7rem;color:var(--mu)">Nächster Monat</div></div>
          <?php if($lpNext):?><span class="badge bs"><?php echo date('d.m. H:i',strtotime($lpNext));?></span><?php else:?><span class="badge bm">Nicht erstellt</span><?php endif;?>
        </div>
      </div>
      <?php if($see):?>
      <div style="margin-top:.65rem;padding-top:.55rem;border-top:1px solid var(--bd)">
        <div style="font-size:.68rem;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem">Planungsquote <?php echo monthName($m);?></div>
        <?php
        $planned = $stats['assignedDays'] + $stats['absentDays'];
        $total = $planned + $stats['unplannedDays'];
        $qPct = $total > 0 ? round(($planned / $total) * 100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:.5rem">
          <div style="flex:1;height:8px;background:var(--bd);border-radius:4px;overflow:hidden"><div style="height:100%;border-radius:4px;background:<?php echo $qPct>80?'#16a34a':($qPct>50?'#f59e0b':'#dc2626');?>;width:<?php echo $qPct;?>%"></div></div>
          <span style="font-size:.82rem;font-weight:700;min-width:40px"><?php echo $qPct;?>%</span>
        </div>
        <div style="display:flex;gap:.5rem;margin-top:.35rem;flex-wrap:wrap">
          <span class="badge bs" style="font-size:.62rem">Geplant: <?php echo $stats['assignedDays'];?></span>
          <span class="badge bd" style="font-size:.62rem">Abwesend: <?php echo $stats['absentDays'];?></span>
          <span class="badge bm" style="font-size:.62rem">Offen: <?php echo $stats['unplannedDays'];?></span>
        </div>
      </div>
      <?php endif;?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Heute Abwesend / Feiertage -->
  <?php if ($showAbsent && $see && opsWidget('absent_today')): ?>
  <div class="card">
    <div class="ch">
      Heute Abwesend
      <span class="badge <?php echo count($absent)?'bd':'bs'; ?>" style="font-size:.62rem"><?php echo count($absent); ?></span>
    </div>
    <div class="cb">
      <?php if (empty($absent)): ?>
        <span class="muted" style="font-size:.82rem">Alle Kollegen anwesend</span>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.3rem">
          <?php foreach ($absent as $a): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--bd);font-size:.82rem">
            <span style="font-weight:600"><?php echo e($a['last_name'].', '.$a['first_name']); ?></span>
            <span class="tag" style="background:<?php echo absenceColor($a['absence_type']);?>;color:<?php echo absenceFg($a['absence_type']);?>;font-size:.62rem"><?php echo absenceLabel($a['absence_type']); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($upHols)): ?>
  <div class="card">
    <div class="ch">Kommende Feiertage</div>
    <div class="cb">
      <ul class="dash-hol-list">
        <?php foreach ($upHols as $h): ?>
        <li>
          <span class="dash-hol-date"><?php echo date('d.m.Y', strtotime($h['holiday_date'])); ?></span>
          <span class="dash-hol-name"><?php echo e($h['name']); ?></span>
          <span class="dash-hol-day"><?php echo wdShort($h['holiday_date']); ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <!-- Einsatzverteilung -->
  <?php if (opsWidget('distribution')): ?>
  <div class="card">
    <?php if ($see && hasRole('manager','admin')): ?>
    <div class="ch">Einsatz-Verteilung – <?php echo monthName($m).' '.$y; ?>
      <span class="muted" style="font-size:.68rem;font-weight:400"><?php echo $stats['totalWorkdays']; ?> AT</span>
    </div>
    <div class="cb">
      <?php if ($stats['assignedDays'] + $stats['absentDays'] > 0): ?>
      <div class="dash-area-grid">
        <?php $total = $stats['assignedDays'] + $stats['absentDays'] + $stats['unplannedDays'];
        foreach (array_slice($stats['areaStats'], 0, 10, true) as $code => $as):
            $w = $total > 0 ? round(($as['count'] / $total) * 100, 1) : 0; ?>
        <div class="dash-area-item">
          <div class="dash-area-bar-header">
            <span class="tag" style="background:<?php echo e($as['color']); ?>;color:<?php echo e($as['fg']); ?>;font-size:.62rem"><?php echo e($code); ?></span>
            <span class="muted" style="font-size:.7rem"><?php echo $as['count']; ?> (<?php echo $w; ?>%)</span>
          </div>
          <div class="dash-area-bar"><div class="dash-area-bar-fill" style="width:<?php echo min(100,$w*2.5); ?>%;background:<?php echo e($as['color']); ?>"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?><span class="muted" style="font-size:.82rem">Noch keine Daten</span><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="ch">Meine Einsätze – <?php echo monthName($m).' '.$y; ?></div>
    <div class="cb">
      <?php
      $myAsgn = assignments($y,$m,true)[$u['id']] ?? [];
      $myAbs = absences($y,$m,true)[$u['id']] ?? [];
      $myPam = planAreaMap(); $myAreaStats = []; $myAbsDays = 0;
      foreach($myAsgn as $dd=>$a) { $aid=$a['plan_area_id']; if(isset($myPam[$aid])){$k=$myPam[$aid]['short_code'];if(!isset($myAreaStats[$k]))$myAreaStats[$k]=['count'=>0,'color'=>$myPam[$aid]['color'],'fg'=>$myPam[$aid]['fg_color']];$myAreaStats[$k]['count']++;}}
      foreach($myAbs as $dd=>$a) $myAbsDays++;
      arsort($myAreaStats);
      $myTotal = count($myAsgn) + $myAbsDays;
      if ($myTotal > 0): ?>
      <div class="dash-area-grid">
        <?php foreach($myAreaStats as $code=>$as): $w=$myTotal>0?round(($as['count']/$myTotal)*100,1):0; ?>
        <div class="dash-area-item">
          <div class="dash-area-bar-header">
            <span class="tag" style="background:<?php echo e($as['color']);?>;color:<?php echo e($as['fg']);?>;font-size:.62rem"><?php echo e($code);?></span>
            <span class="muted" style="font-size:.7rem"><?php echo $as['count'];?> (<?php echo $w;?>%)</span>
          </div>
          <div class="dash-area-bar"><div class="dash-area-bar-fill" style="width:<?php echo min(100,$w*2.5);?>%;background:<?php echo e($as['color']);?>"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?><span class="muted" style="font-size:.82rem">Noch keine Daten</span><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; /* distribution */ ?>
</div>
</div><!-- /.pq-dash -->

<script>
(function(){
    var base=window.location.pathname;base=(base.indexOf('/pages/')!==-1||base.indexOf('/api/')!==-1)?'../':'';
    fetch(base+'api/announcements',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'active'})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success||!d.items||d.items.length===0)return;
        var el=document.getElementById('dashAnnouncements');if(!el)return;
        var colors={info:['#e0f2fe','#0369a1','#bae6fd'],warning:['#fef3c7','#92400e','#fde68a'],critical:['#fef2f2','#991b1b','#fecaca']};
        var icons={info:'M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z',warning:'M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z',critical:'M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z'};
        var h='';
        d.items.forEach(function(a){
            var c=colors[a.priority]||colors.info;
            var ic=icons[a.priority]||icons.info;
            h+='<div class="ann-card" style="background:'+c[0]+';border:1px solid '+c[2]+';color:'+c[1]+'">';
            h+='<svg viewBox="0 0 20 20" fill="currentColor" style="width:18px;height:18px;flex-shrink:0"><path fill-rule="evenodd" d="'+ic+'" clip-rule="evenodd"/></svg>';
            h+='<div><b>'+esc(a.title)+'</b>';
            if(a.content) h+='<div style="font-size:.78rem;margin-top:.15rem;opacity:.85">'+esc(a.content)+'</div>';
            h+='</div></div>';
        });
        el.innerHTML=h;
    }).catch(function(){});
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
