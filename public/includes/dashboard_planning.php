<?php
/**
 * Dashboard: „Heute zu tun“ für Planer (Widget planning, Organisation → Dashboard-Widgets).
 * Kacheln mit klarem Handlungsaufruf; Zahlen aus pqPlanningKpis() (Scope wie Monatsplanung).
 * Erwartet aus dashboard.php: $y, $m, $lp, $lpNext, $nm2, $ny2.
 */
require_once __DIR__ . '/../../app/lib/PlanningPlus.php';
if (!canEdit() || !opsWidget('planning')) return;
$__k = pqPlanningKpis(getDB(), (int)currentUser()['id']);
$__ago = function ($ts) {
    if (!$ts) return '';
    $d = (int)floor((time() - strtotime($ts)) / 86400);
    return $d <= 0 ? 'seit heute' : ($d === 1 ? 'seit gestern' : 'seit ' . $d . ' Tagen');
};
$__canApprove = hasPerm('approve_requests') && opsModule('approvals');
$__canPublish = hasPerm('plan_publish_own');
$__tasks = 0;
?>
<section class="card pq-card pq-ds-tasks pq-tasks" aria-labelledby="pqTasksH">
  <div class="pq-card-h"><h2 id="pqTasksH">Heute zu tun</h2><span class="pq-card-sub">Ihre Planungsaufgaben · nächste <?php echo (int)$__k['days']; ?> Tage</span></div>
  <div class="pq-task-grid pq-card-b">
    <?php if ($__canApprove && $__k['pending'] > 0): $__tasks++; ?>
    <a class="pq-task pq-task-warn" href="genehmigungen">
      <span class="pq-task-num"><?php echo (int)$__k['pending']; ?></span>
      <span class="pq-task-txt"><b>Offene Anträge</b><span>von Mitarbeitenden in Ihrem Bereich</span></span>
      <span class="pq-task-cta">Prüfen<?php echo pqIcon('arrow-right'); ?></span>
    </a>
    <?php endif; ?>
    <?php foreach (array_slice($__k['my_months'], 0, 3) as $__mm): $__tasks++; ?>
    <div class="pq-task pq-task-info">
      <span class="pq-task-num"><?php echo (int)$__mm['count']; ?></span>
      <span class="pq-task-txt"><b>Entwurf <?php echo e(monthName($__mm['month']) . ' ' . $__mm['year']); ?> unveröffentlicht</b><span><?php echo $__mm['count'] === 1 ? 'eigene Änderung' : 'eigene Änderungen'; ?> · <?php echo e($__ago($__mm['oldest'])); ?></span></span>
      <span class="pq-task-acts">
        <a class="btn btn-xs btn-outline" href="monatsplanung?year=<?php echo (int)$__mm['year']; ?>&amp;month=<?php echo (int)$__mm['month']; ?>">Ansehen</a>
        <?php if ($__canPublish): ?><button type="button" class="btn btn-xs btn-primary" data-pq-publish="<?php echo (int)$__mm['year'] . '-' . (int)$__mm['month']; ?>">Veröffentlichen</button><?php endif; ?>
      </span>
    </div>
    <?php endforeach; ?>
    <?php if ($__k['under_count'] > 0): $__tasks++; ?>
    <a class="pq-task pq-task-err" href="monatsplanung">
      <span class="pq-task-num"><?php echo (int)$__k['under_count']; ?></span>
      <span class="pq-task-txt"><b>Unterbesetzt</b><span class="pq-task-chips"><?php foreach ($__k['under'] as $__u): ?><span class="pq-chip" style="background:<?php echo e($__u['color']); ?>;color:<?php echo e($__u['fg']); ?>" title="<?php echo e(date('d.m.', strtotime($__u['date'])) . ' ' . $__u['code'] . ': ' . $__u['have'] . ' von ' . $__u['need']); ?>"><?php echo e($__u['code']); ?> <?php echo e(date('d.m.', strtotime($__u['date']))); ?></span><?php endforeach; ?></span></span>
      <span class="pq-task-cta">Planen<?php echo pqIcon('arrow-right'); ?></span>
    </a>
    <?php endif; ?>
    <?php if ($__k['open_days'] > 0): $__tasks++; ?>
    <a class="pq-task" href="monatsplanung">
      <span class="pq-task-num"><?php echo (int)$__k['open_days']; ?></span>
      <span class="pq-task-txt"><b>Unverplante Arbeitstage</b><span>Personentage ohne Einsatz oder Abwesenheit</span></span>
      <span class="pq-task-cta">Planen<?php echo pqIcon('arrow-right'); ?></span>
    </a>
    <?php endif; ?>
    <?php if (!$lpNext && (int)date('j') >= 15): $__tasks++; ?>
    <a class="pq-task" href="monatsplanung?year=<?php echo (int)$ny2; ?>&amp;month=<?php echo (int)$nm2; ?>">
      <span class="pq-task-num pq-task-icon" aria-hidden="true"><?php echo pqIcon('calendar-edit'); ?></span>
      <span class="pq-task-txt"><b><?php echo e(monthName($nm2) . ' ' . $ny2); ?> noch nicht veröffentlicht</b><span>Der nächste Monat braucht einen Plan</span></span>
      <span class="pq-task-cta">Öffnen<?php echo pqIcon('arrow-right'); ?></span>
    </a>
    <?php endif; ?>
    <?php if ($__tasks === 0): ?>
    <div class="pq-task pq-task-ok">
      <span class="pq-task-num pq-task-icon" aria-hidden="true"><?php echo pqIcon('check-circle'); ?></span>
      <span class="pq-task-txt"><b>Alles erledigt</b><span>Keine offenen Anträge, Entwürfe oder Lücken in den nächsten <?php echo (int)$__k['days']; ?> Tagen.</span></span>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($__k['scope_drafts'] > 0): ?>
  <p class="pq-tasks-note muted"><?php echo pqIcon('info'); ?>In Ihrem Bereich gibt es außerdem <?php echo (int)$__k['scope_drafts']; ?> unveröffentlichte Änderung(en) anderer Planer (<?php echo e($__ago($__k['scope_oldest'])); ?>).</p>
  <?php endif; ?>
</section>
