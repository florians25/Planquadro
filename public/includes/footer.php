</main>
<footer class="app-footer">
  <span><?php echo e(getSetting('company_name','Planquadro')); ?> Einsatzplanung</span>
  <span class="app-footer-sep">·</span>
  <span>&copy; <?php echo date('Y'); ?> Florian-Fynn Scheibel</span>
  <span class="app-footer-sep">·</span>
  <a href="<?php echo bp(); ?>datenschutz">Datenschutz</a>
</footer>
<script defer src="<?php echo bp(); ?>assets/js/app.js?v=<?php echo @filemtime(__DIR__.'/../assets/js/app.js'); ?>"></script>
<script defer src="<?php echo bp(); ?>assets/js/planning-plus.js?v=<?php echo @filemtime(__DIR__.'/../assets/js/planning-plus.js'); ?>"></script>
<script defer src="<?php echo bp(); ?>assets/js/plan-scroll.js?v=<?php echo @filemtime(__DIR__.'/../assets/js/plan-scroll.js'); ?>"></script>
<?php if (!empty($pqDashboardLive)): ?><script defer src="<?php echo bp(); ?>assets/js/dashboard.js?v=<?php echo @filemtime(__DIR__.'/../assets/js/dashboard.js'); ?>"></script><?php endif; ?>
<script nonce="<?php echo opsCspNonce(); ?>">document.addEventListener('DOMContentLoaded',function(){var l=document.getElementById('appLoader');if(l){l.classList.add('loaded');setTimeout(function(){l.remove();},400);}});</script>
</body>
</html>
