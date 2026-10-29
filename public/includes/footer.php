</main>
<footer class="app-footer">
  <span><?php echo e(getSetting('company_name','OpsPilot')); ?> Einsatzplanung</span>
  <span class="app-footer-sep">·</span>
  <span>&copy; <?php echo date('Y'); ?> Florian-Fynn Scheibel</span>
  <span class="app-footer-sep">·</span>
  <a href="<?php echo bp(); ?>datenschutz">Datenschutz</a>
</footer>
<script defer src="<?php echo bp(); ?>assets/js/app.js?v=<?php echo @filemtime(__DIR__.'/../assets/js/app.js'); ?>"></script>
<script>document.addEventListener('DOMContentLoaded',function(){var l=document.getElementById('appLoader');if(l){l.classList.add('loaded');setTimeout(function(){l.remove();},400);}});</script>
</body>
</html>
