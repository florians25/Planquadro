<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
$u = currentUser(); $today = date('Y-m-d'); $y = (int)date('Y'); $m = (int)date('m'); $cwk = (int)date('W');
$see = canSeeDetails();
$showAbsent = getSetting('dashboard_show_absent','1') !== '0';
$absent = $showAbsent ? todayAbsent() : [];

/* Zwei Arbeitswochen: diese und nächste (Mo–Fr) */
$sow = date('Y-m-d', strtotime('monday this week')); $eow = date('Y-m-d', strtotime('friday this week'));
$nsow = date('Y-m-d', strtotime('next monday', strtotime($eow)));
$neow = date('Y-m-d', strtotime('+4 days', strtotime($nsow)));
$wd = []; for ($d = $sow; $d <= $eow; $d = date('Y-m-d', strtotime($d.' +1 day'))) $wd[] = $d;
$nwd = []; for ($d = $nsow; $d <= $neow; $d = date('Y-m-d', strtotime($d.' +1 day'))) $nwd[] = $d;

/* Eigene veröffentlichte Einsätze und Abwesenheiten beider Wochen */
$s = getDB()->prepare('SELECT a.plan_date, a.plan_area_id, pa.short_code, pa.color, pa.fg_color, pa.name FROM assignments a LEFT JOIN plan_areas pa ON pa.id=a.plan_area_id WHERE a.user_id=? AND a.plan_date BETWEEN ? AND ? AND a.is_published=1');
$s->execute([$u['id'], $sow, $neow]); $ma = []; foreach ($s->fetchAll() as $r) $ma[$r['plan_date']] = $r;
$s2 = getDB()->prepare('SELECT absence_date, absence_type, remarks FROM absences WHERE user_id=? AND absence_date BETWEEN ? AND ? AND is_published=1');
$s2->execute([$u['id'], $sow, $neow]); $mab = []; foreach ($s2->fetchAll() as $r) $mab[$r['absence_date']] = $r;

$wots = userWots($u['id'], $y, $m);
$stats = $see ? teamStats($y, $m) : null;
$upHols = upcomingHolidays($y, 5);
/* Feiertage/Ferien aller Monate, die die beiden Wochen berühren */
$holsAll = [];
foreach (array_unique([substr($sow,0,7), substr($neow,0,7)]) as $ym) $holsAll = array_merge($holsAll, holidays((int)substr($ym,0,4), (int)substr($ym,5,2)));

/* Veröffentlichungsstand aktueller und nächster Monat */
$lp = lastPublished($y, $m);
$nm2 = $m+1; $ny2 = $y; if($nm2>12){$nm2=1;$ny2++;}
$lpNext = lastPublished($ny2, $nm2);

/* Gleitzeit-Tage im Jahr */
$glS=getDB()->prepare("SELECT COUNT(*) c FROM absences WHERE user_id=? AND YEAR(absence_date)=? AND absence_type='gleitzeit' AND is_published=1");$glS->execute([$u['id'],$y]);$glCnt=(int)$glS->fetch()['c'];

/* Ein Tag als Anzeige-Datensatz: Feiertag > Abwesenheit > Einsatz > Ferien > leer */
$dayInfo = function (string $d) use ($holsAll, $ma, $mab): array {
    $hl = $holsAll[$d] ?? null;
    $ft = $hl && $hl['holiday_type'] === 'feiertag';
    $r = ['ft' => $ft, 'fe' => $hl && !$ft, 'hol' => $hl['name'] ?? '', 'code' => '', 'name' => '', 'bg' => '', 'fg' => ''];
    if ($ft) { $r['code'] = 'FT'; $r['name'] = $hl['name']; }
    elseif (isset($mab[$d])) { $t = $mab[$d]['absence_type']; $r = array_merge($r, ['code' => absenceLabel($t), 'name' => absenceName($t), 'bg' => absenceColor($t), 'fg' => absenceFg($t)]); }
    elseif (isset($ma[$d])) { $a = $ma[$d]; $r = array_merge($r, ['code' => (string)$a['short_code'], 'name' => (string)($a['name'] ?? ''), 'bg' => (string)$a['color'], 'fg' => (string)($a['fg_color'] ?? '#fff')]); }
    return $r;
};
$todayInfo = $dayInfo($today);
/* Nächster Arbeitstag (für „Danach“) */
$nextDay = null;
foreach (array_merge($wd, $nwd) as $d) if ($d > $today) { $nextDay = $d; break; }
$nextInfo = $nextDay ? $dayInfo($nextDay) : null;

$wdLong = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
$dateLong = function (string $d) use ($wdLong): string { return $wdLong[(int)date('N', strtotime($d)) - 1] . ', ' . date('j. ', strtotime($d)) . monthName((int)date('n', strtotime($d))); };

/* Schnellzugriffe – gleiche Rechte wie die Navigation (header.php) */
$quick = [];
if (canEdit() && opsNavVisible('monatsplanung')) $quick[] = ['monatsplanung', 'calendar-edit', 'Planung', true];
if ((canEdit() || hasPerm('use_planning_assistant')) && opsNavVisible('planungsassistent')) $quick[] = ['planungsassistent', 'sparkles', 'Assistent', false];
if (opsNavVisible('gesamtplan')) $quick[] = ['gesamtplan', 'grid', 'Gesamtplan', false];
if (opsNavVisible('mein_plan')) $quick[] = ['mein_plan', 'calendar-user', 'Mein Plan', false];
$canVacPage = hasRole('admin','manager','teamleiter') && opsNavVisible('urlaubsplanung');
if ($canVacPage) $quick[] = ['urlaubsplanung', 'sun', 'Urlaub', false];
$approvalsOn = getSetting('approval_system_enabled','0') === '1';
if (canEdit() && $approvalsOn && hasPerm('approve_requests') && opsNavVisible('genehmigungen')) $quick[] = ['genehmigungen', 'clipboard-check', 'Anträge', false];

/* Karten-Kopf mit Icon */
$cardHead = function (string $icon, string $title, string $sub = '', string $action = ''): string {
    return '<div class="pq-card-h"><h2>' . e($title) . '</h2>'
        . ($sub !== '' ? '<span class="pq-card-sub">' . $sub . '</span>' : '') . ($action !== '' ? '<span class="pq-card-act">' . $action . '</span>' : '') . '</div>';
};
?>

<?php $maint = upcomingMaintenance(3); if(!empty($maint)): foreach($maint as $mt): ?>
<div class="maint-banner">
<div class="maint-banner-icon"><?php echo pqIcon('alert'); ?></div>
<div class="maint-banner-body">
<div class="maint-banner-title"><?php echo e($mt['title']);?></div>
<?php if(!empty($mt['description'])):?><div class="maint-banner-desc"><?php echo e($mt['description']);?></div><?php endif;?>
<div class="maint-banner-date"><?php echo date('d.m.Y H:i',strtotime($mt['start_date']));?><?php if(!empty($mt['end_date'])):?> – <?php echo date('d.m.Y H:i',strtotime($mt['end_date']));?><?php endif;?></div>
</div>
</div>
<?php endforeach; endif; ?>

<?php if (hasPerm('manage_settings')) { require_once __DIR__ . '/../../app/lib/Backup.php'; $bkWarn = Backup::dashboardWarning(); if ($bkWarn): ?>
<div class="alert alert-err pq-db-alert" id="backupBanner"><?php echo pqIcon('archive'); ?><span>Backup: <?php echo e($bkWarn); ?> <a href="einstellungen">Einstellungen → Backup</a></span></div>
<?php endif; } ?>

<!-- Seitenkopf: Name, Datum, heute, Schnellzugriffe -->
<div class="ph pq-dbh">
  <div class="phl">
    <h1><?php echo e($u['first_name'].' '.$u['last_name']); ?></h1>
    <span class="muted pq-dbh-sub"><?php echo e(roleName($u['role'])); ?> · <?php echo e($dateLong($today) . ' ' . $y); ?> · KW <?php echo $cwk; ?></span>
  </div>
  <div class="phr">
    <span class="pq-live" id="pqDashLive" title="Das Dashboard aktualisiert sich automatisch, sobald sich Pläne, Anträge oder Abwesenheiten ändern.">
      <span class="pq-live-dot" aria-hidden="true"></span><span class="pq-live-txt" id="pqDashStamp">Stand <?php echo date('H:i'); ?></span>
      <button type="button" class="pq-live-btn" id="pqDashRefresh" aria-label="Jetzt aktualisieren" title="Jetzt aktualisieren"><?php echo pqIcon('refresh'); ?></button>
    </span>
    <?php foreach ($quick as [$href, $icon, $label, $primary]): ?>
    <a class="btn <?php echo $primary ? 'btn-primary' : 'btn-outline'; ?><?php echo in_array($href, ['monatsplanung','gesamtplan','planungsassistent','urlaubsplanung'], true) ? ' desk-only' : ''; ?>" href="<?php echo $href; ?>"><?php echo pqIcon($icon); ?><?php echo e($label); ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="pq-today" aria-label="Heute und nächster Arbeitstag">
  <div class="pq-today-item">
    <span class="pq-today-lbl">Heute</span>
    <?php if ($todayInfo['code'] !== ''): ?><span class="pq-chip<?php echo $todayInfo['ft'] ? ' pq-chip-hol' : ''; ?>"<?php if ($todayInfo['bg']): ?> style="background:<?php echo e($todayInfo['bg']); ?>;color:<?php echo e($todayInfo['fg']); ?>"<?php endif; ?>><?php echo e($todayInfo['code']); ?></span><b><?php echo e($todayInfo['name']); ?></b>
    <?php else: ?><span class="muted">Kein veröffentlichter Einsatz</span><?php endif; ?>
  </div>
  <?php if ($nextInfo): ?>
  <div class="pq-today-item">
    <span class="pq-today-lbl"><?php echo e($dateLong($nextDay)); ?></span>
    <?php if ($nextInfo['code'] !== ''): ?><span class="pq-chip<?php echo $nextInfo['ft'] ? ' pq-chip-hol' : ''; ?>"<?php if ($nextInfo['bg']): ?> style="background:<?php echo e($nextInfo['bg']); ?>;color:<?php echo e($nextInfo['fg']); ?>"<?php endif; ?>><?php echo e($nextInfo['code']); ?></span><span><?php echo e($nextInfo['name']); ?></span>
    <?php else: ?><span class="muted">noch nichts geplant</span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php /* Reihenfolge je Rolle (planning-plus.css): Planer zuerst ihre Aufgaben, Mitarbeitende zuerst ihre Woche */ ?>
<div class="pq-dash <?php echo canEdit() ? 'pq-dash-planner' : 'pq-dash-staff'; ?>">
<!-- Ankündigungen -->
<div id="dashAnnouncements" class="pq-ds-ann"<?php if (!opsWidget('announcements')) echo ' hidden'; ?>></div>

<div class="pq-db">
<div class="pq-db-main">

<?php require __DIR__ . '/../includes/dashboard_planning.php'; /* Planer: Heute zu tun */ ?>

<?php if (opsWidget('week')): ?>
<!-- Meine Woche: diese und nächste Woche -->
<section class="card pq-card pq-ds-week">
  <?php echo $cardHead('calendar', 'Meine Woche', 'KW ' . $cwk . ' und ' . (int)date('W', strtotime($nsow)), '<a href="mein_plan" class="btn btn-xs btn-outline">Vollständiger Plan</a>'); ?>
  <?php foreach ([[$wd, 'KW ' . $cwk . ' · ' . date('d.m.', strtotime($sow)) . ' – ' . date('d.m.', strtotime($eow))], [$nwd, 'KW ' . (int)date('W', strtotime($nsow)) . ' · ' . date('d.m.', strtotime($nsow)) . ' – ' . date('d.m.', strtotime($neow))]] as $wi => [$days, $wlabel]): ?>
  <div class="pq-week-lbl"><?php echo e($wlabel); ?></div>
  <div class="pq-week<?php echo $wi ? ' pq-week-next' : ''; ?>">
    <?php foreach ($days as $w): $di = $dayInfo($w); $isT = $w === $today; $past = $w < $today; ?>
    <div class="pq-day<?php echo $isT ? ' is-today' : ''; echo $past ? ' is-past' : ''; echo $di['ft'] ? ' is-hol' : ''; echo $di['fe'] ? ' is-fe' : ''; ?>"<?php if ($di['bg']): ?> style="--day-accent:<?php echo e($di['bg']); ?>"<?php endif; ?>>
      <span class="pq-day-h"><b><?php echo wdShort($w); ?></b> <?php echo date('d.m.', strtotime($w)); ?><?php if ($isT): ?><span class="pq-day-today">Heute</span><?php endif; ?></span>
      <?php if ($di['code'] !== ''): ?>
        <span class="pq-chip<?php echo $di['ft'] ? ' pq-chip-hol' : ''; ?>"<?php if ($di['bg']): ?> style="background:<?php echo e($di['bg']); ?>;color:<?php echo e($di['fg']); ?>"<?php endif; ?>><?php echo e($di['code']); ?></span>
        <span class="pq-day-name" title="<?php echo e($di['name']); ?>"><?php echo e($di['name']); ?></span>
      <?php else: ?>
        <span class="pq-day-empty">Kein Einsatz</span>
      <?php endif; ?>
      <?php if ($di['fe'] && $di['hol'] !== ''): ?><span class="pq-day-fe" title="<?php echo e($di['hol']); ?>"><?php echo e($di['hol']); ?></span><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; /* week */ ?>

<?php if (opsWidget('distribution')): ?>
<!-- Einsatzverteilung -->
<section class="card pq-card pq-ds-dist">
  <?php
  $teamDist = $see && hasRole('manager','admin');
  $rows = []; $distTotal = 0;
  if ($teamDist) {
      $distTotal = $stats['assignedDays'] + $stats['absentDays'] + $stats['unplannedDays'];
      foreach (array_slice($stats['areaStats'], 0, 10, true) as $code => $as) $rows[] = ['code' => $code, 'n' => $as['count'], 'bg' => $as['color'], 'fg' => $as['fg']];
      $hasData = $stats['assignedDays'] + $stats['absentDays'] > 0;
  } else {
      $myAsgn = assignments($y,$m,true)[$u['id']] ?? [];
      $myAbs = absences($y,$m,true)[$u['id']] ?? [];
      $myPam = planAreaMap(); $myAreaStats = [];
      foreach ($myAsgn as $dd => $a) { $aid = $a['plan_area_id']; if (isset($myPam[$aid])) { $k = $myPam[$aid]['short_code']; if (!isset($myAreaStats[$k])) $myAreaStats[$k] = ['count'=>0,'color'=>$myPam[$aid]['color'],'fg'=>$myPam[$aid]['fg_color']]; $myAreaStats[$k]['count']++; } }
      uasort($myAreaStats, function ($a, $b) { return $b['count'] <=> $a['count']; });
      foreach ($myAreaStats as $code => $as) $rows[] = ['code' => $code, 'n' => $as['count'], 'bg' => $as['color'], 'fg' => $as['fg']];
      $distTotal = count($myAsgn) + count($myAbs);
      $hasData = $distTotal > 0;
  }
  $maxN = 1; foreach ($rows as $r) $maxN = max($maxN, $r['n']);
  echo $cardHead('chart', $teamDist ? 'Einsatz-Verteilung' : 'Meine Einsätze', e(monthName($m) . ' ' . $y) . ($teamDist ? ' · ' . (int)$stats['totalWorkdays'] . ' Arbeitstage' : ''));
  ?>
  <div class="pq-card-b">
    <?php if ($hasData && $rows): ?>
    <ul class="pq-bars">
      <?php foreach ($rows as $r): $pct = $distTotal > 0 ? round($r['n'] / $distTotal * 100, 1) : 0; ?>
      <li><span class="pq-chip" style="background:<?php echo e($r['bg']); ?>;color:<?php echo e($r['fg']); ?>"><?php echo e($r['code']); ?></span>
        <span class="pq-bar"><span style="width:<?php echo round($r['n'] / $maxN * 100, 1); ?>%;background:<?php echo e($r['bg']); ?>"></span></span>
        <span class="pq-bar-n"><b><?php echo (int)$r['n']; ?></b> <span class="muted"><?php echo str_replace('.', ',', (string)$pct); ?> %</span></span></li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="pq-empty"><?php echo pqIcon('chart'); ?>Noch keine Daten in diesem Monat</p><?php endif; ?>
  </div>
</section>
<?php endif; /* distribution */ ?>

</div><!-- /.pq-db-main -->
<aside class="pq-db-side" aria-label="Kennzahlen und Status">

<?php if (opsWidget('kpis')): ?>
<?php
  $vUsed = vacUsed($u['id'],$y); $vTot = (float)$u['vacation_days']; $vLeft = vacLeft($u['id'],$y);
  $vPct = $vTot > 0 ? min(100, ($vUsed / $vTot) * 100) : 0;
  $vacHref = $canVacPage ? 'urlaubsplanung' : 'mein_plan';
?>
<section class="card pq-card pq-ds-kpis">
  <?php echo $cardHead('target', 'Meine Zahlen', (string)$y); ?>
  <table class="pq-stats">
    <tr><th scope="row"><a href="<?php echo $vacHref; ?>">Resturlaub</a></th>
      <td><b><?php echo number_format($vLeft,1,',',''); ?></b> Tage<span class="pq-stats-sub"><?php echo number_format($vUsed,1,',',''); ?> von <?php echo number_format($vTot,1,',',''); ?> genommen</span>
        <span class="pq-kpi-bar" role="presentation"><span class="<?php echo $vPct>90?'is-err':($vPct>70?'is-warn':'is-ok'); ?>" style="width:<?php echo round($vPct,1); ?>%"></span></span></td></tr>
    <tr><th scope="row"><a href="mein_plan">Kranktage</a></th><td><b><?php echo number_format(sickDays($u['id'],$y),1,',',''); ?></b></td></tr>
    <tr><th scope="row"><a href="mein_plan">Gleitzeit</a></th><td><b><?php echo $glCnt; ?></b></td></tr>
    <tr><th scope="row"><a href="mein_plan">WOTs <?php echo e(monthName($m)); ?></a></th><td><b><?php echo count($wots); ?></b></td></tr>
    <?php if ($see): ?><tr><th scope="row"><a href="gesamtplan">Mitarbeitende aktiv</a></th><td><b><?php echo (int)$stats['totalUsers']; ?></b></td></tr><?php endif; ?>
  </table>
</section>
<?php endif; /* kpis */ ?>

<?php if ($approvalsOn && opsWidget('requests')): ?>
<!-- Änderungsanträge (Zahlen aus api/change_requests, dashboard.js) -->
<section class="card pq-card pq-ds-req" id="dashApprovalCard" style="display:none">
  <?php echo $cardHead('clipboard-check', 'Änderungsanträge', '', (canEdit() && hasPerm('approve_requests') && opsNavVisible('genehmigungen')) ? '<a href="genehmigungen" class="btn btn-xs btn-outline">Öffnen</a>' : ''); ?>
  <div class="pq-card-b"><div class="dash-approval-grid" id="dashApprovalGrid"></div></div>
</section>
<?php endif; ?>

<?php if (canEdit() && opsWidget('publication')): ?>
<!-- Planstatus -->
<section class="card pq-card pq-ds-pub">
  <?php echo $cardHead('send', 'Planstatus'); ?>
  <div class="pq-card-b">
    <ul class="pq-status">
      <?php foreach ([[$y, $m, $lp, 'Aktueller Monat', 'Entwurf'], [$ny2, $nm2, $lpNext, 'Nächster Monat', 'Nicht veröffentlicht']] as [$sy, $sm, $slp, $slbl, $sdraft]): ?>
      <li>
        <a href="monatsplanung?year=<?php echo (int)$sy; ?>&amp;month=<?php echo (int)$sm; ?>" class="pq-status-a">
          <span class="pq-status-t"><b><?php echo e(monthName($sm) . ' ' . $sy); ?></b><span><?php echo e($slbl); ?></span></span>
          <?php if ($slp): ?><span class="badge bs">Veröffentlicht <?php echo date('d.m. H:i', strtotime($slp)); ?></span><?php else: ?><span class="badge bw"><?php echo e($sdraft); ?></span><?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($see):
      $planned = $stats['assignedDays'] + $stats['absentDays'];
      $total = $planned + $stats['unplannedDays'];
      $qPct = $total > 0 ? round(($planned / $total) * 100) : 0; ?>
    <div class="pq-quota">
      <div class="pq-quota-h"><span>Planungsquote <?php echo e(monthName($m)); ?></span><b><?php echo $qPct; ?> %</b></div>
      <span class="pq-kpi-bar pq-quota-bar" role="progressbar" aria-valuenow="<?php echo $qPct; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Planungsquote"><span class="<?php echo $qPct>80?'is-ok':($qPct>50?'is-warn':'is-err'); ?>" style="width:<?php echo $qPct; ?>%"></span></span>
      <div class="pq-quota-legend">
        <span><i class="is-ok"></i>Geplant <?php echo (int)$stats['assignedDays']; ?></span>
        <span><i class="is-abs"></i>Abwesend <?php echo (int)$stats['absentDays']; ?></span>
        <span><i class="is-open"></i>Offen <?php echo (int)$stats['unplannedDays']; ?></span>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($showAbsent && $see && opsWidget('absent_today')): ?>
<!-- Heute abwesend -->
<section class="card pq-card pq-ds-absent">
  <?php echo $cardHead('user-x', 'Heute abwesend', '<span class="badge ' . (count($absent) ? 'bd' : 'bs') . '">' . count($absent) . '</span>'); ?>
  <div class="pq-card-b">
    <?php if (empty($absent)): ?>
      <p class="pq-empty pq-empty-ok"><?php echo pqIcon('check-circle'); ?>Alle Kolleginnen und Kollegen sind da</p>
    <?php else: ?>
    <ul class="pq-people">
      <?php foreach ($absent as $a): ?>
      <li><span class="pq-people-n"><?php echo e($a['last_name'].', '.$a['first_name']); ?></span>
        <span class="pq-chip" style="background:<?php echo e(absenceColor($a['absence_type'])); ?>;color:<?php echo e(absenceFg($a['absence_type'])); ?>" title="<?php echo e(absenceName($a['absence_type'])); ?>"><?php echo e(absenceLabel($a['absence_type'])); ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($wots) && opsWidget('wots') && opsModule('wots')): ?>
<!-- WOTs im Monat -->
<section class="card pq-card pq-ds-wots">
  <?php echo $cardHead('hash', 'WOTs', e(monthName($m)) . ' · ' . count($wots) . ' Einsatz' . (count($wots) !== 1 ? 'e' : '')); ?>
  <ul class="pq-wots">
    <?php foreach ($wots as $ww): $wl = opsSafeUrl($ww['link_url'] ?? ''); ?>
    <li><span class="pq-wot-dot" style="background:<?php echo e($ww['color'] ?? '#3b82f6'); ?>"></span>
      <span class="pq-wot-t"><b><?php echo e($ww['wot_number']); ?></b><span><?php echo e($ww['remarks'] ?? '–'); ?></span></span>
      <?php if ($wl): ?><a href="<?php echo e($wl); ?>" target="_blank" rel="noopener noreferrer" class="pq-wot-link" aria-label="WOT <?php echo e($ww['wot_number']); ?> öffnen" title="Öffnen"><?php echo pqIcon('external'); ?></a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if (!empty($upHols)): ?>
<!-- Kommende Feiertage -->
<section class="card pq-card pq-ds-hols">
  <?php echo $cardHead('flag', 'Kommende Feiertage'); ?>
  <table class="pq-stats pq-hols">
    <?php foreach ($upHols as $h): $ht = strtotime($h['holiday_date']); $inDays = (int)round((strtotime(date('Y-m-d', $ht)) - strtotime($today)) / 86400); ?>
    <tr><th scope="row"><?php echo e($h['name']); ?></th><td><?php echo wdShort($h['holiday_date']); ?> <?php echo date('d.m.Y', $ht); ?><span class="pq-stats-sub"><?php echo $inDays === 0 ? 'heute' : ($inDays === 1 ? 'morgen' : 'in ' . $inDays . ' Tagen'); ?></span></td></tr>
    <?php endforeach; ?>
  </table>
</section>
<?php endif; ?>

</aside>
</div><!-- /.pq-db -->
</div><!-- /.pq-dash -->

<?php $pqDashboardLive = true; require_once __DIR__ . '/../includes/footer.php'; ?>
