<?php
require_once __DIR__ . '/functions.php';
requireLogin();

/* --------------------------------------------------------------------------
   Wartungsmodus
   Administratoren und Manager arbeiten weiter – sie sollen die Anwendung
   waehrend der Wartung ja gerade pruefen koennen. Alle anderen sehen eine
   eigene Seite, die sich selbst aktualisiert, sobald die Wartung endet.
   -------------------------------------------------------------------------- */
if (isMaintenanceMode() && !hasRole('admin', 'manager')) {
    $_mUser = currentUser();
    $_mMsg  = getSetting('maintenance_message', 'Das System wird gewartet. Bitte versuchen Sie es später erneut.');
    $_mCo   = getSetting('company_name', 'Planquadro');

    /* Geplantes Ende anzeigen, falls eine Wartung hinterlegt ist. */
    $_mEnd = null;
    foreach (upcomingMaintenance(1) as $_m) {
        if (!empty($_m['end_date'])) { $_mEnd = $_m['end_date']; break; }
    }
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="60">
<title>Wartung – <?php echo e($_mCo); ?> Einsatzplanung</title>
<link rel="stylesheet" href="<?php echo bp(); ?>assets/css/style.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/style.css'); ?>">
</head>
<body class="login-bg">
<div class="login-wrap">
  <div class="login-box maint-page">
    <div class="maint-page-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/>
      </svg>
    </div>
    <h1>Wartungsarbeiten</h1>
    <p class="maint-page-msg"><?php echo nl2br(e($_mMsg)); ?></p>

    <?php if ($_mEnd): ?>
      <div class="maint-page-eta">
        <span>Voraussichtlich bis</span>
        <b><?php echo date('d.m.Y, H:i', strtotime($_mEnd)); ?> Uhr</b>
      </div>
    <?php endif; ?>

    <div class="maint-page-hint">
      Diese Seite aktualisiert sich alle 60&nbsp;Sekunden von selbst. Sobald die Wartung
      beendet ist, geht es automatisch weiter – Sie müssen nichts tun.
    </div>

    <div class="maint-page-acts">
      <a href="<?php echo bp(); ?>dashboard" class="btn btn-primary">Erneut versuchen</a>
      <?php if ($_mUser): ?>
        <a href="<?php echo bp(); ?>logout" class="btn btn-outline">Abmelden</a>
      <?php endif; ?>
    </div>

    <?php if ($_mUser): ?>
      <div class="maint-page-user">
        Angemeldet als <b><?php echo e($_mUser['first_name'].' '.$_mUser['last_name']); ?></b>
        (<?php echo e(roleName($_mUser['role'])); ?>)
      </div>
    <?php endif; ?>
  </div>
  <div class="maint-page-foot"><?php echo e($_mCo); ?> Einsatzplanung &middot; &copy; <?php echo date('Y'); ?> Florian-Fynn Scheibel</div>
</div>
</body>
</html><?php
    exit;
}

$_cu = currentUser();
$_pg = basename($_SERVER['SCRIPT_FILENAME'], '.php');
$_fl = getFlash();
$_bp = bp();
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>/* Farbschema vor dem ersten Rendern setzen (kein Aufblitzen) */(function(){try{var t=localStorage.getItem('op-theme')||'system';var d=t==='dark'||(t==='system'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');document.documentElement.setAttribute('data-theme-pref',t);}catch(e){}})();</script>
<title><?php echo e(tcfg('branding.name','Planquadro')); ?><?php echo isset($pageTitle) ? ' – '.e($pageTitle) : ''; ?></title>
<link rel="stylesheet" href="<?php echo $_bp; ?>assets/css/style.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/style.css'); ?>">
<link rel="stylesheet" href="<?php echo $_bp; ?>assets/css/planquadro-theme.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/planquadro-theme.css'); ?>">
<meta name="color-scheme" content="light dark">
<meta name="csrf-token" content="<?php echo csrfToken(); ?>">
<style><?php echo opsBrandCss(); ?></style>
<script>
/* CSRF: jede zustandsändernde Anfrage an den eigenen Server trägt das
   Session-Token im Header X-CSRF-Token (geprüft in includes/auth.php). */
(function(){
  var T=document.querySelector('meta[name="csrf-token"]').content;
  window.EP_CSRF=T;
  var of=window.fetch;
  window.fetch=function(input,init){
    init=init||{};
    var m=(init.method||(input&&input.method)||'GET').toUpperCase();
    var url=typeof input==='string'?input:(input&&input.url)||'';
    var same=!/^[a-z]+:\/\//i.test(url)||url.indexOf(location.origin)===0;
    if(same&&m!=='GET'&&m!=='HEAD'){
      var h=new Headers(init.headers||(typeof input!=='string'&&input.headers)||{});
      if(!h.has('X-CSRF-Token'))h.set('X-CSRF-Token',T);
      init.headers=h;
    }
    return of.call(this,input,init);
  };
  if(navigator.sendBeacon){
    var ob=navigator.sendBeacon.bind(navigator);
    navigator.sendBeacon=function(url,data){
      if(typeof data==='string'&&data.charAt(0)==='{'){try{var o=JSON.parse(data);o._token=T;data=JSON.stringify(o);}catch(e){}}
      return ob(url,data);
    };
  }
})();
</script>
</head>
<body data-uid="<?php echo $_cu['id']??0; ?>" data-csrf="<?php echo csrfToken(); ?>">
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<div id="appLoader"><div class="loader-dots"><span></span><span></span><span></span></div><div class="loader-text"><?php echo e(tcfg('branding.name','Planquadro')); ?></div></div>
<div id="toasts" class="toast-wrap"></div>
<nav class="nav">
  <a href="<?php echo $_bp; ?>dashboard" class="nav-brand" aria-label="<?php echo e(tcfg('branding.name','Planquadro')); ?> – Dashboard"><?php if (tcfg('branding.logo')): ?><img src="<?php echo $_bp; ?>api/branding?logo=1" alt="" class="nav-brand-logo"><?php else: ?><span class="nav-brand-icon"><svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" width="16" height="16"><rect x="3" y="6" width="26" height="23" rx="3" stroke="#fff" stroke-width="2.5"/><path d="M3 12h26" stroke="#fff" stroke-width="2.5"/><path d="M9 3v5M23 3v5" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/><rect x="7" y="15.5" width="4" height="3" rx=".8" fill="#fff" opacity=".9"/><rect x="14" y="15.5" width="4" height="3" rx=".8" fill="#fff" opacity=".5"/><rect x="21" y="15.5" width="4" height="3" rx=".8" fill="#fff" opacity=".5"/><rect x="7" y="21.5" width="4" height="3" rx=".8" fill="#fff" opacity=".5"/><rect x="14" y="21.5" width="4" height="3" rx=".8" fill="#fff" opacity=".9"/><rect x="21" y="21.5" width="4" height="3" rx=".8" fill="#fff" opacity=".3"/></svg></span><?php endif; ?></a>
  <div class="nav-links" id="navLinks">
    <?php if (opsNavVisible('dashboard')): ?><a style="order:<?php echo opsNavOrder('dashboard'); ?>" href="<?php echo $_bp; ?>dashboard" class="nav-link mob-ok<?php echo $_pg==='dashboard'?' active':''; ?>"<?php if($_pg==='dashboard') echo ' aria-current="page"'; ?> title="Dashboard" aria-label="Dashboard">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
      <span>Dashboard</span></a><?php endif; ?>
    <?php if (opsNavVisible('mein_plan')): ?><a style="order:<?php echo opsNavOrder('mein_plan'); ?>" href="<?php echo $_bp; ?>mein_plan" class="nav-link mob-ok<?php echo $_pg==='mein_plan'?' active':''; ?>"<?php if($_pg==='mein_plan') echo ' aria-current="page"'; ?> title="Mein Plan" aria-label="Mein Plan">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
      <span>Mein Plan</span></a><?php endif; ?>
    <span class="nav-div" style="order:<?php echo opsNavOrder('gesamtplan'); ?>"></span>
    <?php if (opsNavVisible('gesamtplan')): ?><a style="order:<?php echo opsNavOrder('gesamtplan'); ?>" href="<?php echo $_bp; ?>gesamtplan" class="nav-link desk-only<?php echo $_pg==='gesamtplan'?' active':''; ?>"<?php if($_pg==='gesamtplan') echo ' aria-current="page"'; ?> title="Gesamtplan" aria-label="Gesamtplan">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm5 6a1 1 0 10-2 0v3.586l-1.293-1.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V8z"/></svg>
      <span>Gesamtplan</span></a><?php endif; ?>
    <?php if (canEdit()): ?>
    <?php if (opsNavVisible('monatsplanung')): ?><a style="order:<?php echo opsNavOrder('monatsplanung'); ?>" href="<?php echo $_bp; ?>monatsplanung" class="nav-link<?php echo $_pg==='monatsplanung'?' active':''; ?>"<?php if($_pg==='monatsplanung') echo ' aria-current="page"'; ?> title="Planung" aria-label="Planung">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
      <span>Planung</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (canEdit() || hasPerm('use_planning_assistant')): ?>
    <?php if (opsNavVisible('planungsassistent')): ?><a style="order:<?php echo opsNavOrder('planungsassistent'); ?>" href="<?php echo $_bp; ?>planungsassistent" class="nav-link nav-link-assistant<?php echo $_pg==='planungsassistent'?' active':''; ?>"<?php if($_pg==='planungsassistent') echo ' aria-current="page"'; ?> title="Planungsassistent – komplexe Einsätze und Kundenprojekte" aria-label="Planungsassistent – komplexe Einsätze und Kundenprojekte">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm7.707 8.707a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
      <span>Assistent</span><span class="nav-beta">Beta</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (opsNavVisible('jahresuebersicht')): ?><a style="order:<?php echo opsNavOrder('jahresuebersicht'); ?>" href="<?php echo $_bp; ?>jahresuebersicht" class="nav-link<?php echo $_pg==='jahresuebersicht'?' active':''; ?>"<?php if($_pg==='jahresuebersicht') echo ' aria-current="page"'; ?> title="Jahresplan" aria-label="Jahresplan">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 4a3 3 0 00-3 3v6a3 3 0 003 3h10a3 3 0 003-3V7a3 3 0 00-3-3H5zm-1 9v-1h5v2H5a1 1 0 01-1-1zm7 1h4a1 1 0 001-1v-1h-5v2zm0-4h5V8h-5v2zM9 8H4v2h5V8z" clip-rule="evenodd"/></svg>
      <span>Jahresplan</span></a><?php endif; ?>
    <?php if (hasRole('admin','manager','teamleiter')): ?>
    <?php if (opsNavVisible('urlaubsplanung')): ?><a style="order:<?php echo opsNavOrder('urlaubsplanung'); ?>" href="<?php echo $_bp; ?>urlaubsplanung" class="nav-link<?php echo $_pg==='urlaubsplanung'?' active':''; ?>"<?php if($_pg==='urlaubsplanung') echo ' aria-current="page"'; ?> title="Urlaub" aria-label="Urlaub">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM4.332 8.027a6.012 6.012 0 011.912-2.706C6.512 5.73 6.974 6 7.5 6A1.5 1.5 0 019 7.5V8a2 2 0 004 0 2 2 0 011.523-1.943A5.977 5.977 0 0116 10c0 .34-.028.675-.083 1H15a2 2 0 00-2 2v2.197A5.973 5.973 0 0110 16v-2a2 2 0 00-2-2 2 2 0 01-2-2 2 2 0 00-1.668-1.973z" clip-rule="evenodd"/></svg>
      <span>Urlaub</span></a><?php endif; ?>
    <?php if (hasPerm('manage_absences') || canEdit()): ?>
    <?php if (opsNavVisible('abwesenheiten')): ?><a style="order:<?php echo opsNavOrder('abwesenheiten'); ?>" href="<?php echo $_bp; ?>abwesenheiten" class="nav-link<?php echo $_pg==='abwesenheiten'?' active':''; ?>"<?php if($_pg==='abwesenheiten') echo ' aria-current="page"'; ?> title="Abwesenheiten erfassen" aria-label="Abwesenheiten erfassen">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm5.707 9.707a1 1 0 00-1.414-1.414L10 10.586l-.293-.293a1 1 0 00-1.414 1.414l.293.293-.293.293a1 1 0 101.414 1.414l.293-.293.293.293a1 1 0 001.414-1.414l-.293-.293.293-.293z" clip-rule="evenodd"/></svg>
      <span>Abwesenheiten</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (opsNavVisible('vertretung')): ?><a style="order:<?php echo opsNavOrder('vertretung'); ?>" href="<?php echo $_bp; ?>vertretung" class="nav-link<?php echo $_pg==='vertretung'?' active':''; ?>"<?php if($_pg==='vertretung') echo ' aria-current="page"'; ?> title="Vertretung" aria-label="Vertretung">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7 2a1 1 0 011 1v1h4V3a1 1 0 112 0v1h1a2 2 0 012 2v1H3V6a2 2 0 012-2h1V3a1 1 0 011-1zM3 9h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V9zm4.707 2.293a1 1 0 00-1.414 1.414L7.586 14l-1.293 1.293a1 1 0 101.414 1.414L9 15.414l1.293 1.293a1 1 0 001.414-1.414L10.414 14l1.293-1.293a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293z" clip-rule="evenodd"/></svg>
      <span>Vertretung</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (canEdit()): ?>
    <span class="nav-div" style="order:<?php echo opsNavOrder('genehmigungen'); ?>"></span>
    <?php if (getSetting('approval_system_enabled','0')==='1' && hasPerm('approve_requests')): ?>
    <?php if (opsNavVisible('genehmigungen')): ?><a style="order:<?php echo opsNavOrder('genehmigungen'); ?>" href="<?php echo $_bp; ?>genehmigungen" class="nav-link<?php echo $_pg==='genehmigungen'?' active':''; ?> nav-link-approval"<?php if($_pg==='genehmigungen') echo ' aria-current="page"'; ?> title="Anträge" aria-label="Anträge">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
      <span>Anträge</span><span class="approval-pill" id="approvalBadge" style="display:none">0</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (canManageUsers()): ?>
    <?php if (opsNavVisible('mitarbeiter')): ?><a style="order:<?php echo opsNavOrder('mitarbeiter'); ?>" href="<?php echo $_bp; ?>mitarbeiter" class="nav-link<?php echo $_pg==='mitarbeiter'?' active':''; ?>"<?php if($_pg==='mitarbeiter') echo ' aria-current="page"'; ?> title="Team" aria-label="Team">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
      <span>Team</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (canManageConfig()): ?>
    <?php if (opsNavVisible('planbereiche')): ?><a style="order:<?php echo opsNavOrder('planbereiche'); ?>" href="<?php echo $_bp; ?>planbereiche" class="nav-link<?php echo $_pg==='planbereiche'?' active':''; ?>"<?php if($_pg==='planbereiche') echo ' aria-current="page"'; ?> title="Bereiche" aria-label="Bereiche">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
      <span>Bereiche</span></a><?php endif; ?>
    <?php if (opsNavVisible('stammdaten')): ?><a style="order:<?php echo opsNavOrder('stammdaten'); ?>" href="<?php echo $_bp; ?>stammdaten" class="nav-link<?php echo $_pg==='stammdaten'?' active':''; ?>"<?php if($_pg==='stammdaten') echo ' aria-current="page"'; ?> title="Stammdaten" aria-label="Stammdaten">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm0 6a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zm11-1a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/></svg>
      <span>Stammdaten</span></a><?php endif; ?>
    <?php if (opsNavVisible('feiertage')): ?><a style="order:<?php echo opsNavOrder('feiertage'); ?>" href="<?php echo $_bp; ?>feiertage" class="nav-link<?php echo $_pg==='feiertage'?' active':''; ?>"<?php if($_pg==='feiertage') echo ' aria-current="page"'; ?> title="Feiertage" aria-label="Feiertage">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
      <span>Feiertage</span></a><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (hasPerm('manage_settings')): ?>
    <span class="nav-div" style="order:<?php echo opsNavOrder('einstellungen'); ?>"></span>
    <?php if (opsNavVisible('einstellungen')): ?><a style="order:<?php echo opsNavOrder('einstellungen'); ?>" href="<?php echo $_bp; ?>einstellungen" class="nav-link<?php echo $_pg==='einstellungen'?' active':''; ?>"<?php if($_pg==='einstellungen') echo ' aria-current="page"'; ?> title="Einstellungen" aria-label="Einstellungen">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
      <span>Einstellungen</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (hasPerm('manage_roles')): ?>
    <?php if (opsNavVisible('rollen')): ?><a style="order:<?php echo opsNavOrder('rollen'); ?>" href="<?php echo $_bp; ?>rollen" class="nav-link<?php echo $_pg==='rollen'?' active':''; ?>"<?php if($_pg==='rollen') echo ' aria-current="page"'; ?> title="Rollen & Rechte" aria-label="Rollen & Rechte">
      <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
      <span>Rollen</span></a><?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="nav-right">
    <div class="notif-wrap" id="notifWrap">
      <button class="notif-bell" onclick="toggleNotif()" title="Benachrichtigungen">
        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
        <span class="notif-count" id="notifCount" style="display:none">0</span>
      </button>
      <div class="notif-panel" id="notifPanel">
        <div class="notif-panel-head"><b>Benachrichtigungen</b><button class="btn btn-xs btn-outline" onclick="markAllRead()">Alle gelesen</button></div>
        <div class="notif-panel-body" id="notifList"><p class="muted" style="padding:.5rem;text-align:center">Keine</p></div>
      </div>
    </div>
    <div class="nav-user-menu" id="userMenu">
      <button class="nav-user-btn" onclick="document.getElementById('userMenu').classList.toggle('open')">
        <span class="nav-avatar"><?php echo mb_strtoupper(mb_substr($_cu['first_name'],0,1).mb_substr($_cu['last_name'],0,1)); ?></span>
        <span class="nav-user-info">
          <span class="nav-user-name"><?php echo e($_cu['first_name'].' '.$_cu['last_name']); ?></span>
          <span class="nav-badge r-<?php echo e($_cu['role']); ?>"><?php echo e(roleName($_cu['role'])); ?></span>
        </span>
        <svg class="nav-chevron" viewBox="0 0 20 20" fill="currentColor" width="12" height="12"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
      </button>
      <div class="nav-dropdown">
        <div class="nav-drop-header">
          <span class="nav-drop-name"><?php echo e($_cu['first_name'].' '.$_cu['last_name']); ?></span>
          <span class="nav-drop-role"><?php echo e(roleName($_cu['role'])); ?></span>
        </div>
        <a href="<?php echo $_bp; ?>profil" class="nav-drop-item">
          <svg viewBox="0 0 20 20" fill="currentColor" class="nav-drop-icon"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
          Mein Profil
        </a>
        <a href="#" class="nav-drop-item" onclick="event.preventDefault();oMod('changelogMod');">
          <svg viewBox="0 0 20 20" fill="currentColor" class="nav-drop-icon"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
          Was gibt es Neues?
        </a>
        <a href="#" class="nav-drop-item" onclick="event.preventDefault();oMod('pwMod');">
          <svg viewBox="0 0 20 20" fill="currentColor" class="nav-drop-icon"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
          Passwort ändern
        </a>
        <a href="<?php echo $_bp; ?>datenschutz" class="nav-drop-item">
          <svg viewBox="0 0 20 20" fill="currentColor" class="nav-drop-icon"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
          Datenschutz
        </a>
        <button type="button" class="nav-drop-item op-theme-toggle" onclick="opCycleTheme()" aria-live="polite">
          <svg viewBox="0 0 20 20" fill="currentColor" class="nav-drop-icon" aria-hidden="true"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/></svg>
          <span>Darstellung: <b id="opThemeLabel">System</b></span>
        </button>
        <a href="<?php echo $_bp; ?>logout" class="nav-drop-item nav-drop-logout">
          <svg viewBox="0 0 20 20" fill="currentColor" class="nav-drop-icon"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/></svg>
          Abmelden
        </a>
      </div>
    </div>
  </div>
</nav>

<!-- Passwort-Ändern Modal -->
<div class="modal-bg" id="pwMod"><div class="modal modal-sm">
<div class="mh"><h3>Passwort ändern</h3><button class="mx" onclick="cMod('pwMod')">&times;</button></div>
<div class="mb">
<div class="fg"><label>Aktuelles Passwort</label><input type="password" id="pwOld" required></div>
<div class="fg"><label>Neues Passwort</label><input type="password" id="pwNew" required minlength="6" placeholder="Mind. 6 Zeichen"></div>
<div class="fg"><label>Neues Passwort bestätigen</label><input type="password" id="pwNew2" required minlength="6"></div>
</div>
<div class="mf"><button class="btn btn-outline" onclick="cMod('pwMod')">Abbrechen</button><button class="btn btn-primary" onclick="changePassword()">Speichern</button></div>
</div></div>

<!-- Changelog Modal -->
<div class="modal-bg" id="changelogMod"><div class="modal modal-lg" style="max-height:80vh;display:flex;flex-direction:column">
<div class="mh"><h3>Was gibt es Neues?</h3><button class="mx" onclick="cMod('changelogMod')">&times;</button></div>
<div class="mb" id="changelogBody" style="overflow-y:auto;flex:1;max-height:60vh"><p class="muted">Laden...</p></div>
<div class="mf"><button class="btn btn-outline" onclick="cMod('changelogMod')">Schließen</button></div>
</div></div>

<!-- Mobile: desktop-only page warning -->
<?php $mobPages=['dashboard','mein_plan','profil','genehmigungen']; $isMobPage=in_array($_pg,$mobPages); ?>
<?php if(!$isMobPage):?>
<div class="mob-desk-only-warn">
<svg viewBox="0 0 20 20" fill="currentColor" style="width:32px;height:32px;color:var(--mu);margin-bottom:.5rem"><path fill-rule="evenodd" d="M3 5a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2h-2.22l.123.489.804.804A1 1 0 0113 18H7a1 1 0 01-.707-1.707l.804-.804L7.22 15H5a2 2 0 01-2-2V5zm5.771 7H5V5h10v7H8.771z" clip-rule="evenodd"/></svg>
<b>Diese Seite ist für Desktop optimiert</b>
<span style="font-size:.78rem;color:var(--mu)">Bitte verwende einen Computer für diese Funktion.</span>
<a href="<?php echo $_bp;?>dashboard" class="btn btn-primary btn-sm" style="margin-top:.5rem">Zum Dashboard</a>
<button type="button" class="btn btn-outline btn-sm" style="margin-top:.35rem" onclick="document.documentElement.classList.add('mob-force-desktop');try{sessionStorage.setItem('op-force-desktop','1')}catch(e){}">Trotzdem anzeigen</button>
</div>
<script>try{if(sessionStorage.getItem('op-force-desktop')==='1')document.documentElement.classList.add('mob-force-desktop');}catch(e){}</script>
<?php endif;?>

<!-- Mobile Bottom Nav -->
<nav class="mob-nav">
<a href="<?php echo $_bp;?>dashboard" class="mob-nav-item<?php echo $_pg==='dashboard'?' active':'';?>">
<svg viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
<span>Home</span></a>
<a href="<?php echo $_bp;?>mein_plan" class="mob-nav-item<?php echo $_pg==='mein_plan'?' active':'';?>">
<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
<span>Mein Plan</span></a>
<a href="<?php echo $_bp;?>profil" class="mob-nav-item<?php echo $_pg==='profil'?' active':'';?>">
<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
<span>Profil</span></a>
<a href="<?php echo $_bp;?>logout" class="mob-nav-item">
<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/></svg>
<span>Abmelden</span></a>
</nav>

<?php if (isMaintenanceMode()): ?>
<div class="maint-active no-print">
  <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
  <span><b>Wartungsmodus aktiv.</b> Für alle übrigen Rollen ist die Anwendung gesperrt – Sie arbeiten weiter, weil Sie Administrator oder Manager sind.</span>
  <?php if (hasPerm('manage_settings')): ?><a href="<?php echo $_bp; ?>einstellungen">Beenden</a><?php endif; ?>
</div>
<?php endif; ?>
<main id="main" tabindex="-1" class="content <?php if(!$isMobPage) echo 'mob-hide-content';?>">
<?php if (function_exists('hasActiveDeputation') && hasActiveDeputation()): $depNames = activeDeputationNames(); ?>
<div class="deputy-banner no-print">
  <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M7.707 3.293a1 1 0 010 1.414L5.414 7H11a5 5 0 010 10h-1a1 1 0 110-2h1a3 3 0 100-6H5.414l2.293 2.293a1 1 0 11-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
  <span>Sie vertreten aktuell: <b><?php echo e(implode(', ', $depNames)); ?></b> – Sie können für dessen Team planen und Anträge genehmigen.</span>
</div>
<?php endif; ?>
<?php if ($_fl): ?>
<script>document.addEventListener('DOMContentLoaded',function(){toast('<?php echo addslashes(e($_fl['msg'])); ?>','<?php echo e($_fl['type']); ?>');});</script>
<?php endif; ?>
<?php if (canEdit() && getSetting('approval_system_enabled','0')==='1'): ?>
<script>
(function(){
    function refreshBadge(){
        fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'pending_count'})})
        .then(function(r){return r.json();}).then(function(d){
            var b=document.getElementById('approvalBadge');
            if(b){if(d.count>0){b.textContent=d.count;b.style.display='';}else{b.style.display='none';}}
        }).catch(function(){});
    }
    document.addEventListener('DOMContentLoaded',refreshBadge);
    setInterval(refreshBadge,30000);
})();
</script>
<?php endif; ?>
<script>
/* Ensure getApiBase is available before app.js loads */
if(typeof getApiBase==='undefined'){window.getApiBase=function(){var p=window.location.pathname;return(p.indexOf('/pages/')!==-1||p.indexOf('/api/')!==-1)?'../':'';};window.esc=function(s){if(!s)return '';var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;};}
/* Notification Bell */
(function(){
    var panel=false;
    window.toggleNotif=function(){
        var p=document.getElementById('notifPanel');
        panel=!panel;p.style.display=panel?'block':'none';
        if(panel)loadNotifs();
    };
    window.markAllRead=function(){
        fetch(getApiBase()+'api/notifications',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'mark_read'})})
        .then(function(r){return r.json();}).then(function(){refreshNotifCount();loadNotifs();}).catch(function(){});
    };
    function loadNotifs(){
        var el=document.getElementById('notifList');
        fetch(getApiBase()+'api/notifications',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list',limit:15})})
        .then(function(r){return r.json();}).then(function(d){
            if(!d.success||!d.items||d.items.length===0){el.innerHTML='<p class="muted" style="padding:.75rem;text-align:center;font-size:.82rem">Keine Benachrichtigungen</p>';return;}
            var h='';d.items.forEach(function(n){
                var cls=n.is_read==='0'||n.is_read===0?'notif-item notif-unread':'notif-item';
                h+='<div class="'+cls+'" onclick="notifClick('+n.id+',\''+(n.link||'')+'\')"><div class="notif-title">'+esc(n.title)+'</div>';
                if(n.message)h+='<div class="notif-msg">'+esc(n.message)+'</div>';
                var dt=new Date(n.created_at);h+='<div class="notif-time">'+('0'+dt.getDate()).slice(-2)+'.'+('0'+(dt.getMonth()+1)).slice(-2)+'. '+('0'+dt.getHours()).slice(-2)+':'+('0'+dt.getMinutes()).slice(-2)+'</div></div>';
            });el.innerHTML=h;
        }).catch(function(){});
    }
    window.notifClick=function(id,link){
        fetch(getApiBase()+'api/notifications',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'mark_read',id:id})})
        .then(function(){refreshNotifCount();}).catch(function(){});
        if(link)window.location.href=link;
        else{document.getElementById('notifPanel').style.display='none';panel=false;}
    };
    function refreshNotifCount(){
        fetch(getApiBase()+'api/notifications',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'count'})})
        .then(function(r){return r.json();}).then(function(d){
            var c=document.getElementById('notifCount');
            if(c){if(d.count>0){c.textContent=d.count;c.style.display='';}else{c.style.display='none';}}
        }).catch(function(){});
    }
    document.addEventListener('click',function(e){var w=document.getElementById('notifWrap');if(panel&&w&&!w.contains(e.target)){document.getElementById('notifPanel').style.display='none';panel=false;}});
    document.addEventListener('DOMContentLoaded',refreshNotifCount);
    setInterval(refreshNotifCount,20000);
})();
/* Changelog loader */
(function(){
    window.loadChangelog=function(){
        var el=document.getElementById('changelogBody');
        fetch(getApiBase()+'api/changelog',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
        .then(function(r){return r.json();}).then(function(d){
            if(!d.success||!d.entries||d.entries.length===0){el.innerHTML='<p class="muted" style="text-align:center;padding:1.5rem">Noch keine Einträge vorhanden.</p>';return;}
            var h='';d.entries.forEach(function(e){
                var dateStr='';
                if(e.release_date){var dp=e.release_date.split('-');dateStr=dp[2]+'.'+dp[1]+'.'+dp[0];}
                var typeBadge='';var et=e.entry_type||'feature';
                if(et==='bugfix') typeBadge='<span style="display:inline-block;font-size:.6rem;font-weight:700;padding:.1rem .4rem;border-radius:4px;background:#fef2f2;color:#dc2626;margin-left:.35rem">Bug-Fix</span>';
                else if(et==='improvement') typeBadge='<span style="display:inline-block;font-size:.6rem;font-weight:700;padding:.1rem .4rem;border-radius:4px;background:#fefce8;color:#a16207;margin-left:.35rem">Verbesserung</span>';
                else if(et==='security') typeBadge='<span style="display:inline-block;font-size:.6rem;font-weight:700;padding:.1rem .4rem;border-radius:4px;background:#fef3c7;color:#92400e;margin-left:.35rem">Sicherheit</span>';
                else if(et==='breaking') typeBadge='<span style="display:inline-block;font-size:.6rem;font-weight:700;padding:.1rem .4rem;border-radius:4px;background:#fee2e2;color:#991b1b;margin-left:.35rem">Breaking Change</span>';
                else typeBadge='<span style="display:inline-block;font-size:.6rem;font-weight:700;padding:.1rem .4rem;border-radius:4px;background:#ecfdf5;color:#059669;margin-left:.35rem">Feature</span>';
                h+='<div class="changelog-entry"><div class="changelog-ver"><span class="badge bi" style="font-size:.75rem;padding:.15rem .5rem">v'+esc(e.version)+'</span>';
                h+=typeBadge;
                if(dateStr)h+=' <span class="muted" style="font-size:.75rem">'+dateStr+'</span>';
                h+='</div><div class="changelog-text">'+esc(e.content).replace(/\n/g,'<br>')+'</div></div>';
            });el.innerHTML=h;
        }).catch(function(){el.innerHTML='<p class="muted" style="text-align:center">Changelog nicht verfügbar.</p>';});
    };
    document.addEventListener('DOMContentLoaded',function(){
        var btn=document.querySelector('[onclick*="changelogMod"]');
        if(btn)btn.addEventListener('click',function(){loadChangelog();});
    });
})();
</script>
