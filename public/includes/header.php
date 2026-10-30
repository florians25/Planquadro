<?php
require_once __DIR__ . '/functions.php';
requireLogin();

/* --------------------------------------------------------------------------
   Wartungsmodus
   Wer das Recht bypass_maintenance hat (Standard: Administratoren und
   Manager), arbeitet weiter – sie sollen die Anwendung
   waehrend der Wartung ja gerade pruefen koennen. Alle anderen sehen eine
   eigene Seite, die sich selbst aktualisiert, sobald die Wartung endet.
   -------------------------------------------------------------------------- */
if (isMaintenanceMode() && !canBypassMaintenance()) {
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
<link rel="icon" href="<?php echo bp(); ?>assets/brand/favicon.svg" type="image/svg+xml"><link rel="icon" href="<?php echo bp(); ?>assets/brand/favicon-32.png" sizes="32x32" type="image/png"><link rel="apple-touch-icon" href="<?php echo bp(); ?>assets/brand/apple-touch-icon.png"><meta name="theme-color" content="#14213D">
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
/* Übergang: Seiten im Umbau behalten Inline-Skripte (OPS_CSP_LEGACY_PAGES) */
if (in_array($_pg, OPS_CSP_LEGACY_PAGES, true)) opsSendCsp("'self'", true);
$_fl = getFlash();
$_bp = bp();
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="<?php echo $_bp; ?>assets/js/actions.js?v=<?php echo @filemtime(__DIR__.'/../assets/js/actions.js'); ?>"></script>
<script nonce="<?php echo opsCspNonce(); ?>">/* Farbschema vor dem ersten Rendern setzen (kein Aufblitzen) */(function(){try{var t=localStorage.getItem('pq-theme')||'system';var d=t==='dark'||(t==='system'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');document.documentElement.setAttribute('data-theme-pref',t);}catch(e){}})();</script>
<title><?php echo e(tcfg('branding.name','Planquadro')); ?><?php echo isset($pageTitle) ? ' – '.e($pageTitle) : ''; ?></title>
<link rel="stylesheet" href="<?php echo $_bp; ?>assets/css/style.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/style.css'); ?>">
<link rel="stylesheet" href="<?php echo $_bp; ?>assets/css/planquadro-theme.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/planquadro-theme.css'); ?>">
<script src="<?php echo $_bp; ?>assets/js/nav.js?v=<?php echo @filemtime(__DIR__.'/../assets/js/nav.js'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo $_bp; ?>assets/css/planning-plus.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/planning-plus.css'); ?>">
<?php if (($_pg ?? '') === 'dashboard'): ?><link rel="stylesheet" href="<?php echo $_bp; ?>assets/css/dashboard.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/dashboard.css'); ?>"><?php endif; ?>
<link rel="icon" href="<?php echo $_bp; ?>assets/brand/favicon.svg" type="image/svg+xml"><link rel="icon" href="<?php echo $_bp; ?>assets/brand/favicon-32.png" sizes="32x32" type="image/png"><link rel="apple-touch-icon" href="<?php echo $_bp; ?>assets/brand/apple-touch-icon.png"><meta name="theme-color" content="#14213D">
<meta name="color-scheme" content="light dark">
<meta name="csrf-token" content="<?php echo csrfToken(); ?>">
<meta name="pq-icons" content="<?php echo e(pqIconUrl()); ?>">
<script src="<?php echo $_bp; ?>assets/js/icons.js?v=<?php echo @filemtime(__DIR__.'/../assets/js/icons.js'); ?>" defer></script>
<style><?php echo opsBrandCss(); ?></style>
<script nonce="<?php echo opsCspNonce(); ?>">
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
  <a href="<?php echo $_bp; ?>dashboard" class="nav-brand" aria-label="<?php echo e(tcfg('branding.name','Planquadro')); ?> – Dashboard"><?php if (tcfg('branding.logo')): ?><img src="<?php echo $_bp; ?>api/branding?logo=1" alt="" class="nav-brand-logo"><?php else: ?><span class="nav-brand-icon"><svg viewBox="0 0 64 64" width="22" height="22" aria-hidden="true" focusable="false"><rect x="4" y="4" width="16" height="16" rx="4" fill="#fff"/><rect x="24" y="4" width="16" height="16" rx="4" fill="#fff" fill-opacity=".28"/><rect x="44" y="4" width="16" height="16" rx="4" fill="#fff"/><rect x="4" y="24" width="16" height="16" rx="4" fill="#fff" fill-opacity=".28"/><rect x="24" y="24" width="16" height="16" rx="4" fill="#fff"/><rect x="44" y="24" width="16" height="16" rx="4" fill="#FF7A1A"/><rect x="4" y="44" width="16" height="16" rx="4" fill="#fff"/><rect x="24" y="44" width="16" height="16" rx="4" fill="#fff" fill-opacity=".28"/><rect x="44" y="44" width="16" height="16" rx="4" fill="#fff" fill-opacity=".28"/></svg></span><?php endif; ?></a>
  <div class="nav-links" id="navLinks">
    <?php if (opsNavVisible('dashboard')): ?><a style="order:<?php echo opsNavOrder('dashboard'); ?>" href="<?php echo $_bp; ?>dashboard" class="nav-link mob-ok<?php echo $_pg==='dashboard'?' active':''; ?>"<?php if($_pg==='dashboard') echo ' aria-current="page"'; ?> title="Dashboard" aria-label="Dashboard">
      <?php echo pqIcon('home','nav-icon'); ?>
      <span>Dashboard</span></a><?php endif; ?>
    <?php if (opsNavVisible('mein_plan')): ?><a style="order:<?php echo opsNavOrder('mein_plan'); ?>" href="<?php echo $_bp; ?>mein_plan" class="nav-link mob-ok<?php echo $_pg==='mein_plan'?' active':''; ?>"<?php if($_pg==='mein_plan') echo ' aria-current="page"'; ?> title="Mein Plan" aria-label="Mein Plan">
      <?php echo pqIcon('calendar-user','nav-icon'); ?>
      <span>Mein Plan</span></a><?php endif; ?>
    <span class="nav-div" style="order:<?php echo opsNavOrder('gesamtplan'); ?>"></span>
    <?php if (opsNavVisible('gesamtplan')): ?><a style="order:<?php echo opsNavOrder('gesamtplan'); ?>" href="<?php echo $_bp; ?>gesamtplan" class="nav-link desk-only<?php echo $_pg==='gesamtplan'?' active':''; ?>"<?php if($_pg==='gesamtplan') echo ' aria-current="page"'; ?> title="Gesamtplan" aria-label="Gesamtplan">
      <?php echo pqIcon('grid','nav-icon'); ?>
      <span>Gesamtplan</span></a><?php endif; ?>
    <?php if (canEdit()): ?>
    <?php if (opsNavVisible('monatsplanung')): ?><a style="order:<?php echo opsNavOrder('monatsplanung'); ?>" href="<?php echo $_bp; ?>monatsplanung" class="nav-link<?php echo $_pg==='monatsplanung'?' active':''; ?>"<?php if($_pg==='monatsplanung') echo ' aria-current="page"'; ?> title="Planung" aria-label="Planung">
      <?php echo pqIcon('calendar-edit','nav-icon'); ?>
      <span>Planung</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (canEdit() || hasPerm('use_planning_assistant')): ?>
    <?php if (opsNavVisible('planungsassistent')): ?><a style="order:<?php echo opsNavOrder('planungsassistent'); ?>" href="<?php echo $_bp; ?>planungsassistent" class="nav-link nav-link-assistant<?php echo $_pg==='planungsassistent'?' active':''; ?>"<?php if($_pg==='planungsassistent') echo ' aria-current="page"'; ?> title="Planungsassistent – komplexe Einsätze und Kundenprojekte" aria-label="Planungsassistent – komplexe Einsätze und Kundenprojekte">
      <?php echo pqIcon('sparkles','nav-icon'); ?>
      <span>Assistent</span><span class="nav-beta">Beta</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (opsNavVisible('jahresuebersicht')): ?><a style="order:<?php echo opsNavOrder('jahresuebersicht'); ?>" href="<?php echo $_bp; ?>jahresuebersicht" class="nav-link<?php echo $_pg==='jahresuebersicht'?' active':''; ?>"<?php if($_pg==='jahresuebersicht') echo ' aria-current="page"'; ?> title="Jahresplan" aria-label="Jahresplan">
      <?php echo pqIcon('calendar-range','nav-icon'); ?>
      <span>Jahresplan</span></a><?php endif; ?>
    <?php if (hasRole('admin','manager','teamleiter')): ?>
    <?php if (opsNavVisible('urlaubsplanung')): ?><a style="order:<?php echo opsNavOrder('urlaubsplanung'); ?>" href="<?php echo $_bp; ?>urlaubsplanung" class="nav-link<?php echo $_pg==='urlaubsplanung'?' active':''; ?>"<?php if($_pg==='urlaubsplanung') echo ' aria-current="page"'; ?> title="Urlaub" aria-label="Urlaub">
      <?php echo pqIcon('sun','nav-icon'); ?>
      <span>Urlaub</span></a><?php endif; ?>
    <?php if (hasPerm('manage_absences') || canEdit()): ?>
    <?php if (opsNavVisible('abwesenheiten')): ?><a style="order:<?php echo opsNavOrder('abwesenheiten'); ?>" href="<?php echo $_bp; ?>abwesenheiten" class="nav-link<?php echo $_pg==='abwesenheiten'?' active':''; ?>"<?php if($_pg==='abwesenheiten') echo ' aria-current="page"'; ?> title="Abwesenheiten erfassen" aria-label="Abwesenheiten erfassen">
      <?php echo pqIcon('calendar-x','nav-icon'); ?>
      <span>Abwesenheiten</span></a><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (canManageDeputations()): ?>
    <?php if (opsNavVisible('vertretung')): ?><a style="order:<?php echo opsNavOrder('vertretung'); ?>" href="<?php echo $_bp; ?>vertretung" class="nav-link<?php echo $_pg==='vertretung'?' active':''; ?>"<?php if($_pg==='vertretung') echo ' aria-current="page"'; ?> title="Vertretung" aria-label="Vertretung">
      <?php echo pqIcon('swap','nav-icon'); ?>
      <span>Vertretung</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (canEdit()): ?>
    <span class="nav-div" style="order:<?php echo opsNavOrder('genehmigungen'); ?>"></span>
    <?php if (getSetting('approval_system_enabled','0')==='1' && hasPerm('approve_requests')): ?>
    <?php if (opsNavVisible('genehmigungen')): ?><a style="order:<?php echo opsNavOrder('genehmigungen'); ?>" href="<?php echo $_bp; ?>genehmigungen" class="nav-link<?php echo $_pg==='genehmigungen'?' active':''; ?> nav-link-approval"<?php if($_pg==='genehmigungen') echo ' aria-current="page"'; ?> title="Anträge" aria-label="Anträge">
      <?php echo pqIcon('clipboard-check','nav-icon'); ?>
      <span>Anträge</span><span class="approval-pill" id="approvalBadge" style="display:none">0</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (canManageUsers()): ?>
    <?php if (opsNavVisible('mitarbeiter')): ?><a style="order:<?php echo opsNavOrder('mitarbeiter'); ?>" href="<?php echo $_bp; ?>mitarbeiter" class="nav-link<?php echo $_pg==='mitarbeiter'?' active':''; ?>"<?php if($_pg==='mitarbeiter') echo ' aria-current="page"'; ?> title="Team" aria-label="Team">
      <?php echo pqIcon('users','nav-icon'); ?>
      <span>Team</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (canManageConfig()): ?>
    <?php if (opsNavVisible('planbereiche')): ?><a style="order:<?php echo opsNavOrder('planbereiche'); ?>" href="<?php echo $_bp; ?>planbereiche" class="nav-link<?php echo $_pg==='planbereiche'?' active':''; ?>"<?php if($_pg==='planbereiche') echo ' aria-current="page"'; ?> title="Bereiche" aria-label="Bereiche">
      <?php echo pqIcon('tag','nav-icon'); ?>
      <span>Bereiche</span></a><?php endif; ?>
    <?php if (opsNavVisible('stammdaten')): ?><a style="order:<?php echo opsNavOrder('stammdaten'); ?>" href="<?php echo $_bp; ?>stammdaten" class="nav-link<?php echo $_pg==='stammdaten'?' active':''; ?>"<?php if($_pg==='stammdaten') echo ' aria-current="page"'; ?> title="Stammdaten" aria-label="Stammdaten">
      <?php echo pqIcon('database','nav-icon'); ?>
      <span>Stammdaten</span></a><?php endif; ?>
    <?php if (opsNavVisible('feiertage')): ?><a style="order:<?php echo opsNavOrder('feiertage'); ?>" href="<?php echo $_bp; ?>feiertage" class="nav-link<?php echo $_pg==='feiertage'?' active':''; ?>"<?php if($_pg==='feiertage') echo ' aria-current="page"'; ?> title="Feiertage" aria-label="Feiertage">
      <?php echo pqIcon('flag','nav-icon'); ?>
      <span>Feiertage</span></a><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (hasPerm('manage_settings')): ?>
    <span class="nav-div" style="order:<?php echo opsNavOrder('einstellungen'); ?>"></span>
    <?php if (opsNavVisible('einstellungen')): ?><a style="order:<?php echo opsNavOrder('einstellungen'); ?>" href="<?php echo $_bp; ?>einstellungen" class="nav-link<?php echo $_pg==='einstellungen'?' active':''; ?>"<?php if($_pg==='einstellungen') echo ' aria-current="page"'; ?> title="Einstellungen" aria-label="Einstellungen">
      <?php echo pqIcon('settings','nav-icon'); ?>
      <span>Einstellungen</span></a><?php endif; ?>
    <?php endif; ?>
    <?php if (hasPerm('manage_roles')): ?>
    <?php if (opsNavVisible('rollen')): ?><a style="order:<?php echo opsNavOrder('rollen'); ?>" href="<?php echo $_bp; ?>rollen" class="nav-link<?php echo $_pg==='rollen'?' active':''; ?>"<?php if($_pg==='rollen') echo ' aria-current="page"'; ?> title="Rollen & Rechte" aria-label="Rollen & Rechte">
      <?php echo pqIcon('shield','nav-icon'); ?>
      <span>Rollen</span></a><?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="nav-right">
    <div class="notif-wrap" id="notifWrap">
      <button class="notif-bell" data-action="toggleNotif" title="Benachrichtigungen">
        <?php echo pqIcon('bell'); ?>
        <span class="notif-count" id="notifCount" style="display:none">0</span>
      </button>
      <div class="notif-panel" id="notifPanel">
        <div class="notif-panel-head"><b>Benachrichtigungen</b><button class="btn btn-xs btn-outline" data-action="markAllRead">Alle gelesen</button></div>
        <div class="notif-panel-body" id="notifList"><p class="muted" style="padding:.5rem;text-align:center">Keine</p></div>
      </div>
    </div>
    <div class="nav-user-menu" id="userMenu">
      <button class="nav-user-btn" data-action="toggleClass" data-args='["userMenu","open"]'>
        <span class="nav-avatar"><?php echo mb_strtoupper(mb_substr($_cu['first_name'],0,1).mb_substr($_cu['last_name'],0,1)); ?></span>
        <span class="nav-user-info">
          <span class="nav-user-name"><?php echo e($_cu['first_name'].' '.$_cu['last_name']); ?></span>
          <span class="nav-badge r-<?php echo e($_cu['role']); ?>"><?php echo e(roleName($_cu['role'])); ?></span>
        </span>
        <?php echo pqIcon('chevron-down','nav-chevron'); ?>
      </button>
      <div class="nav-dropdown">
        <div class="nav-drop-header">
          <span class="nav-drop-name"><?php echo e($_cu['first_name'].' '.$_cu['last_name']); ?></span>
          <span class="nav-drop-role"><?php echo e(roleName($_cu['role'])); ?></span>
        </div>
        <a href="<?php echo $_bp; ?>profil" class="nav-drop-item">
          <?php echo pqIcon('user','nav-drop-icon'); ?>
          Mein Profil
        </a>
        <a href="#" class="nav-drop-item" data-action="oMod" data-args='["changelogMod"]' data-prevent>
          <?php echo pqIcon('info','nav-drop-icon'); ?>
          Was gibt es Neues?
        </a>
        <a href="#" class="nav-drop-item" data-action="oMod" data-args='["pwMod"]' data-prevent>
          <?php echo pqIcon('key','nav-drop-icon'); ?>
          Passwort ändern
        </a>
        <a href="<?php echo $_bp; ?>datenschutz" class="nav-drop-item">
          <?php echo pqIcon('shield','nav-drop-icon'); ?>
          Datenschutz
        </a>
        <button type="button" class="nav-drop-item pq-theme-toggle" data-action="pqCycleTheme" aria-live="polite">
          <?php echo pqIcon('moon','nav-drop-icon'); ?>
          <span>Darstellung: <b id="pqThemeLabel">System</b></span>
        </button>
        <a href="<?php echo $_bp; ?>logout" class="nav-drop-item nav-drop-logout">
          <?php echo pqIcon('logout','nav-drop-icon'); ?>
          Abmelden
        </a>
      </div>
    </div>
  </div>
</nav>

<!-- Passwort-Ändern Modal -->
<div class="modal-bg" id="pwMod"><div class="modal modal-sm">
<div class="mh"><h3>Passwort ändern</h3><button class="mx" data-action="cMod" data-args='["pwMod"]'>&times;</button></div>
<div class="mb">
<div class="fg"><label>Aktuelles Passwort</label><input type="password" id="pwOld" required></div>
<div class="fg"><label>Neues Passwort</label><input type="password" id="pwNew" required minlength="6" placeholder="Mind. 6 Zeichen"></div>
<div class="fg"><label>Neues Passwort bestätigen</label><input type="password" id="pwNew2" required minlength="6"></div>
</div>
<div class="mf"><button class="btn btn-outline" data-action="cMod" data-args='["pwMod"]'>Abbrechen</button><button class="btn btn-primary" data-action="changePassword">Speichern</button></div>
</div></div>

<!-- Changelog Modal -->
<div class="modal-bg" id="changelogMod"><div class="modal modal-lg" style="max-height:80vh;display:flex;flex-direction:column">
<div class="mh"><h3>Was gibt es Neues?</h3><button class="mx" data-action="cMod" data-args='["changelogMod"]'>&times;</button></div>
<div class="mb" id="changelogBody" style="overflow-y:auto;flex:1;max-height:60vh"><p class="muted">Laden...</p></div>
<div class="mf"><button class="btn btn-outline" data-action="cMod" data-args='["changelogMod"]'>Schließen</button></div>
</div></div>

<!-- Mobile: desktop-only page warning -->
<?php $mobPages=['dashboard','mein_plan','profil','genehmigungen']; $isMobPage=in_array($_pg,$mobPages); ?>
<?php if(!$isMobPage):?>
<div class="mob-desk-only-warn">
<?php echo pqIcon('monitor','pq-i-xl'); ?>
<b>Diese Seite ist für Desktop optimiert</b>
<span style="font-size:.78rem;color:var(--mu)">Bitte verwende einen Computer für diese Funktion.</span>
<a href="<?php echo $_bp;?>dashboard" class="btn btn-primary btn-sm" style="margin-top:.5rem">Zum Dashboard</a>
<button type="button" class="btn btn-outline btn-sm" style="margin-top:.35rem" data-action="pqForceDesktop">Trotzdem anzeigen</button>
</div>
<script nonce="<?php echo opsCspNonce(); ?>">try{if(sessionStorage.getItem('pq-force-desktop')==='1')document.documentElement.classList.add('mob-force-desktop');}catch(e){}
pqAction('pqForceDesktop',function(){document.documentElement.classList.add('mob-force-desktop');try{sessionStorage.setItem('pq-force-desktop','1')}catch(e){}});</script>
<?php endif;?>

<!-- Mobile Bottom Nav -->
<nav class="mob-nav">
<a href="<?php echo $_bp;?>dashboard" class="mob-nav-item<?php echo $_pg==='dashboard'?' active':'';?>">
<?php echo pqIcon('home'); ?>
<span>Home</span></a>
<a href="<?php echo $_bp;?>mein_plan" class="mob-nav-item<?php echo $_pg==='mein_plan'?' active':'';?>">
<?php echo pqIcon('calendar-user'); ?>
<span>Mein Plan</span></a>
<a href="<?php echo $_bp;?>profil" class="mob-nav-item<?php echo $_pg==='profil'?' active':'';?>">
<?php echo pqIcon('user'); ?>
<span>Profil</span></a>
<a href="<?php echo $_bp;?>logout" class="mob-nav-item">
<?php echo pqIcon('logout'); ?>
<span>Abmelden</span></a>
</nav>

<?php if (isMaintenanceMode()): ?>
<div class="maint-active no-print">
  <?php echo pqIcon('alert'); ?>
  <span><b>Wartungsmodus aktiv.</b> Für alle übrigen Rollen ist die Anwendung gesperrt – Sie arbeiten weiter, weil Sie Administrator oder Manager sind.</span>
  <?php if (hasPerm('manage_settings')): ?><a href="<?php echo $_bp; ?>einstellungen">Beenden</a><?php endif; ?>
</div>
<?php endif; ?>
<main id="main" tabindex="-1" class="content <?php if(!$isMobPage) echo 'mob-hide-content';?>">
<?php if (function_exists('hasActiveDeputation') && hasActiveDeputation()): $depNames = activeDeputationNames(); ?>
<div class="deputy-banner no-print">
  <?php echo pqIcon('swap'); ?>
  <span>Sie vertreten aktuell: <b><?php echo e(implode(', ', $depNames)); ?></b> – Sie können für dessen Team planen und Anträge genehmigen.</span>
</div>
<?php endif; ?>
<?php if ($_fl): ?>
<script nonce="<?php echo opsCspNonce(); ?>">document.addEventListener('DOMContentLoaded',function(){toast('<?php echo addslashes(e($_fl['msg'])); ?>','<?php echo e($_fl['type']); ?>');});</script>
<?php endif; ?>
<?php if (canEdit() && getSetting('approval_system_enabled','0')==='1'): ?>
<script nonce="<?php echo opsCspNonce(); ?>">
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
<script nonce="<?php echo opsCspNonce(); ?>">
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
                h+='<div class="'+cls+'" '+pqActAttr('notifClick',[+n.id,n.link||''])+'><div class="notif-title">'+esc(n.title)+'</div>';
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
