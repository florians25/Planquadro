<div class="legend no-print">
<div class="leg-sec"><div class="leg-h">Kunden-Einsätze</div><div class="leg-row">
<?php foreach(planAreas('kunde') as $p): ?>
<span class="leg-i" data-area="<?php echo (int)$p['id'];?>"><span class="leg-c" style="background:<?php echo e($p['color']);?>;color:<?php echo e($p['fg_color']);?>"><?php echo e($p['short_code']);?></span><span class="leg-n"><?php echo e($p['name']);?></span></span>
<?php endforeach; ?></div></div>
<div class="leg-sec"><div class="leg-h">Allgemein</div><div class="leg-row">
<?php foreach(planAreas('allgemein') as $p): ?>
<span class="leg-i" data-area="<?php echo (int)$p['id'];?>"><span class="leg-c" style="background:<?php echo e($p['color']);?>;color:<?php echo e($p['fg_color']);?>"><?php echo e($p['short_code']);?></span><span class="leg-n"><?php echo e($p['name']);?></span></span>
<?php endforeach; ?></div></div>
<div class="leg-sec"><div class="leg-h">Abwesenheit</div><div class="leg-row">
<?php if(canSeeDetails()): ?>
<span class="leg-i"><span class="leg-c" style="background:#16a34a;color:#fff">K</span><span class="leg-n">Krank (Attest)</span></span>
<span class="leg-i"><span class="leg-c" style="background:#dc2626;color:#fff">K</span><span class="leg-n">Krank (ohne)</span></span>
<span class="leg-i"><span class="leg-c" style="background:#ef4444;color:#fff">k</span><span class="leg-n">Krank ½ Tag</span></span>
<span class="leg-i"><span class="leg-c" style="background:#16a34a;color:#fff">U</span><span class="leg-n">Urlaub (gen.)</span></span>
<span class="leg-i"><span class="leg-c" style="background:#dc2626;color:#fff">U</span><span class="leg-n">Urlaub (beantr.)</span></span>
<span class="leg-i"><span class="leg-c" style="background:#fbbf24;color:#000">u</span><span class="leg-n">Urlaub ½ Tag</span></span>
<span class="leg-i"><span class="leg-c" style="background:#8b5cf6;color:#fff">GL</span><span class="leg-n">Gleitzeit</span></span>
<span class="leg-i"><span class="leg-c" style="background:#94a3b8;color:#fff">—</span><span class="leg-n">Gesperrt</span></span>
<span class="leg-i"><span class="leg-c" style="background:#475569;color:#fff">?</span><span class="leg-n">Offen / noch zu klären</span></span>
<?php else: ?>
<span class="leg-i"><span class="leg-c" style="background:#ef4444;color:#fff">A</span><span class="leg-n">Abwesend</span></span>
<?php endif; ?></div></div>
<div class="leg-sec"><div class="leg-h">Tage</div><div class="leg-row">
<span class="leg-i"><span class="leg-c hol-c">FT</span><span class="leg-n">Feiertag</span></span>
<span class="leg-i"><span class="leg-c fer-c">FE</span><span class="leg-n">Ferien</span></span>
<span class="leg-i"><span class="leg-c we-c">&nbsp;</span><span class="leg-n">Wochenende</span></span>
</div></div>
<div class="leg-sec"><div class="leg-h">Status</div><div class="leg-row">
<span class="leg-i"><span class="leg-c" style="box-shadow:inset 0 -3px 0 #3b82f6;background:#f8fafc">&nbsp;</span><span class="leg-n">Eigener Entwurf</span></span>
<span class="leg-i"><span class="leg-c" style="box-shadow:inset 0 -3px 0 #f59e0b;background:#f8fafc">&nbsp;</span><span class="leg-n">Entwurf (anderer)</span></span>
<span class="leg-i"><span class="leg-c" style="outline:2.5px dashed #c8b400;outline-offset:-2px;background:#f8fafc">&nbsp;</span><span class="leg-n">Nicht veröff.</span></span>
<span class="leg-i"><span class="leg-c" style="position:relative;background:#f8fafc"><span style="position:absolute;top:0;left:0;border-style:solid;border-width:5px 5px 0 0;border-color:#ef4444 transparent transparent transparent"></span>&nbsp;</span><span class="leg-n">Int. Bemerkung</span></span>
<span class="leg-i"><span class="leg-c" style="position:relative;background:#f8fafc"><span style="position:absolute;top:0;right:0;border-style:solid;border-width:0 5px 5px 0;border-color:transparent #f97316 transparent transparent"></span>&nbsp;</span><span class="leg-n">Bemerkung</span></span>
</div></div>
</div>
