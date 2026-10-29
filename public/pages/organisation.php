<?php
/**
 * Planquadro – Organisation (TenantConfig)
 * Branding, Module, Navigation, Dashboard-Widgets, Planungsregeln, Anmeldung (SSO).
 * Zugriff: Recht "Einstellungen verwalten".
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../app/lib/Oidc.php';
requireLogin();
if (!hasPerm('manage_settings')) { showAccessDenied('Sie benötigen die Berechtigung "Einstellungen verwalten".'); }
$db = getDB();

const NAV_LABELS = [
    'dashboard' => 'Dashboard', 'mein_plan' => 'Mein Plan', 'gesamtplan' => 'Gesamtplan', 'monatsplanung' => 'Planung',
    'planungsassistent' => 'Assistent', 'jahresuebersicht' => 'Jahresplan', 'urlaubsplanung' => 'Urlaub',
    'abwesenheiten' => 'Abwesenheiten', 'vertretung' => 'Vertretung', 'genehmigungen' => 'Anträge', 'mitarbeiter' => 'Team',
    'planbereiche' => 'Bereiche', 'stammdaten' => 'Stammdaten', 'feiertage' => 'Feiertage', 'einstellungen' => 'Einstellungen',
    'rollen' => 'Rollen',
];

function orgAudit($action, $details) {
    try { getDB()->prepare('INSERT INTO audit_log(user_id,action,details,ip_address) VALUES(?,?,?,?)')
        ->execute([$_SESSION['user_id'] ?? null, $action, mb_substr($details, 0, 500), opsClientIp()]); } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'save_branding') {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
            $p = opsValidColor($_POST['primary'] ?? ''); $a = opsValidColor($_POST['accent'] ?? '');
            if ($name === '' || !$p || !$a) throw new InvalidArgumentException('Name und gültige Farben (#rrggbb) angeben.');
            tcfgSet(['branding' => ['name' => $name, 'primary' => $p, 'accent' => $a]]);
            orgAudit('tenant_config', "branding: $name $p $a");
            echo json_encode(['success' => true, 'msg' => 'Branding gespeichert.']); exit;
        }
        if ($act === 'upload_logo') {
            $f = $_FILES['logo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Keine Datei empfangen.');
            if ($f['size'] > 512 * 1024) throw new InvalidArgumentException('Das Logo darf höchstens 512 KB groß sein.');
            /* Nur Rastergrafiken – SVG kann Skripte enthalten */
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
            if (!$ext || !@getimagesize($f['tmp_name'])) throw new InvalidArgumentException('Erlaubt sind PNG, JPEG oder WebP.');
            $dir = opsStorageDir('branding');
            foreach (glob($dir . '/logo.*') ?: [] as $old) @unlink($old);
            $target = $dir . '/logo.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $target)) throw new RuntimeException('Logo konnte nicht gespeichert werden.');
            tcfgSet(['branding' => ['logo' => 'logo.' . $ext]]);
            orgAudit('tenant_config', 'logo uploaded');
            echo json_encode(['success' => true, 'msg' => 'Logo gespeichert.']); exit;
        }
        if ($act === 'remove_logo') {
            foreach (glob(opsStorageDir('branding') . '/logo.*') ?: [] as $old) @unlink($old);
            tcfgSet(['branding' => ['logo' => null]]);
            echo json_encode(['success' => true, 'msg' => 'Logo entfernt.']); exit;
        }
        if ($act === 'save_modules') {
            $sel = (array)($_POST['modules'] ?? []);
            $m = [];
            foreach (array_keys(TENANT_MODULES) as $k) $m[$k] = in_array($k, $sel, true);
            tcfgSet(['modules' => $m]);
            orgAudit('tenant_config', 'modules: ' . implode(',', array_keys(array_filter($m))));
            echo json_encode(['success' => true, 'msg' => 'Module gespeichert.']); exit;
        }
        if ($act === 'save_navigation') {
            $order = array_values(array_intersect((array)($_POST['order'] ?? []), array_keys(NAV_LABELS)));
            $hidden = array_values(array_intersect((array)($_POST['hidden'] ?? []), array_keys(NAV_LABELS)));
            /* Ausblenden ist reine Darstellung; die Seiten selbst bleiben rechtegeschützt.
               Dashboard und Einstellungen können nicht ausgeblendet werden. */
            $hidden = array_values(array_diff($hidden, ['dashboard', 'einstellungen']));
            tcfgSet(['navigation' => ['order' => $order, 'hidden' => $hidden]]);
            orgAudit('tenant_config', 'navigation');
            echo json_encode(['success' => true, 'msg' => 'Navigation gespeichert.']); exit;
        }
        if ($act === 'save_widgets') {
            $w = array_values(array_intersect((array)($_POST['widgets'] ?? []), array_keys(DASHBOARD_WIDGETS)));
            tcfgSet(['dashboard' => ['widgets' => $w]]);
            echo json_encode(['success' => true, 'msg' => 'Dashboard gespeichert.']); exit;
        }
        if ($act === 'save_planning') {
            tcfgSet(['planning' => [
                'cross_team_planning'   => !empty($_POST['cross_team_planning']),
                'concurrent_editing'    => !empty($_POST['concurrent_editing']),
                'show_absent_dashboard' => !empty($_POST['show_absent_dashboard']),
            ]]);
            orgAudit('tenant_config', 'planning rules');
            echo json_encode(['success' => true, 'msg' => 'Planungsregeln gespeichert.']); exit;
        }
        /* SSO legt fest, wer sich als wer anmelden darf – nur für Administratoren
           (sonst könnte "Einstellungen verwalten" zur Admin-Übernahme führen). */
        if (($act === 'save_oidc' || $act === 'test_oidc') && !isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Die Anmeldung (SSO) können nur Administratoren einrichten.']); exit;
        }
        if ($act === 'save_oidc') {
            $issuer = rtrim(trim((string)($_POST['issuer'] ?? '')), '/');
            $enabled = !empty($_POST['enabled']);
            if ($enabled && (!preg_match('#^https://#', $issuer) || trim((string)($_POST['client_id'] ?? '')) === '')) {
                throw new InvalidArgumentException('Für SSO sind eine https-Issuer-URL und eine Client-ID nötig.');
            }
            $patch = ['enabled' => $enabled, 'issuer' => $issuer, 'client_id' => trim((string)$_POST['client_id']),
                'claim' => in_array($_POST['claim'] ?? '', ['preferred_username', 'email', 'upn', 'sub'], true) ? $_POST['claim'] : 'preferred_username',
                'label' => mb_substr(trim((string)($_POST['label'] ?? '')), 0, 60) ?: 'Mit Firmenkonto anmelden',
                'allow_password_login' => !empty($_POST['allow_password_login']),
                'allow_admin' => !empty($_POST['allow_admin'])];
            /* Leeres Secret-Feld = unverändert lassen (wird nie an den Browser zurückgegeben) */
            if (($_POST['client_secret'] ?? '') !== '') $patch['client_secret'] = (string)$_POST['client_secret'];
            if (!$patch['allow_password_login'] && !$enabled) $patch['allow_password_login'] = true;
            tcfgSet(['security' => ['oidc' => $patch]]);
            orgAudit('tenant_config', 'oidc ' . ($enabled ? 'on ' . $issuer : 'off'));
            echo json_encode(['success' => true, 'msg' => 'Anmeldung gespeichert.']); exit;
        }
        if ($act === 'test_oidc') {
            $d = oidcDiscovery((string)tcfg('security.oidc.issuer'), true);
            echo json_encode(['success' => true, 'msg' => 'Verbindung erfolgreich: ' . ($d['issuer'] ?? '')]); exit;
        }
        echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion']);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('organisation: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e instanceof OidcException ? $e->getMessage() : 'Speichern fehlgeschlagen.']);
    }
    exit;
}

$pageTitle = 'Organisation';
require_once __DIR__ . '/../includes/header.php';
$cfg = tcfgAll(true);
$oidc = $cfg['security']['oidc'];
$redirectUri = oidcRedirectUri();
?>
<div class="ph"><div class="phl"><h1>Organisation</h1><span class="muted" style="font-size:.82rem">Erscheinungsbild, Module und Anmeldung für <?php echo e($cfg['branding']['name']); ?></span></div>
<div class="phr"><a href="einstellungen" class="btn btn-outline">Weitere Einstellungen</a></div></div>

<div class="org-grid">
<section class="card" aria-labelledby="orgBrand">
  <div class="ch" id="orgBrand">Branding</div>
  <div class="cb">
    <form id="fBrand" class="org-form">
      <div class="fg"><label for="bName">Name der Organisation</label><input id="bName" name="name" maxlength="80" required value="<?php echo e($cfg['branding']['name']); ?>"></div>
      <div class="org-row">
        <div class="fg"><label for="bPrimary">Markenfarbe</label><input type="color" id="bPrimary" name="primary" value="<?php echo e($cfg['branding']['primary']); ?>"></div>
        <div class="fg"><label for="bAccent">Akzentfarbe</label><input type="color" id="bAccent" name="accent" value="<?php echo e($cfg['branding']['accent']); ?>"></div>
        <div class="fg"><label>Vorschau</label><div class="org-preview"><span id="pvBtn" class="btn btn-primary btn-sm">Schaltfläche</span> <span id="pvContrast" class="muted" style="font-size:.75rem"></span></div></div>
      </div>
      <button class="btn btn-primary" type="submit">Branding speichern</button>
    </form>
    <hr class="org-hr">
    <form id="fLogo" class="org-form" enctype="multipart/form-data">
      <div class="fg"><label for="bLogo">Logo (PNG, JPEG oder WebP, max. 512 KB)</label><input type="file" id="bLogo" name="logo" accept="image/png,image/jpeg,image/webp"></div>
      <?php if ($cfg['branding']['logo']): ?><p><img src="api/branding?logo=1&amp;v=<?php echo time(); ?>" alt="Aktuelles Logo" style="max-height:48px"></p><?php endif; ?>
      <button class="btn btn-outline" type="submit">Logo hochladen</button>
      <?php if ($cfg['branding']['logo']): ?><button class="btn btn-outline" type="button" id="btnLogoDel">Logo entfernen</button><?php endif; ?>
    </form>
  </div>
</section>

<section class="card" aria-labelledby="orgMods">
  <div class="ch" id="orgMods">Module</div>
  <div class="cb">
    <form id="fMods" class="org-form">
      <p class="muted" style="font-size:.78rem">Deaktivierte Module verschwinden aus der Navigation und sind auch per Direktaufruf gesperrt. Daten bleiben erhalten.</p>
      <?php foreach (TENANT_MODULES as $k => $label): ?>
      <label class="org-check"><input type="checkbox" name="modules[]" value="<?php echo e($k); ?>" <?php if (!empty($cfg['modules'][$k])) echo 'checked'; ?>> <?php echo e($label); ?></label>
      <?php endforeach; ?>
      <button class="btn btn-primary" type="submit">Module speichern</button>
    </form>
  </div>
</section>

<section class="card" aria-labelledby="orgNav">
  <div class="ch" id="orgNav">Navigation</div>
  <div class="cb">
    <form id="fNav" class="org-form">
      <p class="muted" style="font-size:.78rem">Reihenfolge per Pfeiltasten ändern. Ausblenden betrifft nur die Navigation – der Zugriff bleibt über Rechte geregelt.</p>
      <ol class="org-nav" id="navList">
        <?php foreach ($cfg['navigation']['order'] as $pg): if (!isset(NAV_LABELS[$pg])) continue; ?>
        <li data-page="<?php echo e($pg); ?>">
          <span class="org-nav-name"><?php echo e(NAV_LABELS[$pg]); ?></span>
          <label class="org-check-inline"><input type="checkbox" name="hidden[]" value="<?php echo e($pg); ?>" <?php if (in_array($pg, $cfg['navigation']['hidden'], true)) echo 'checked'; ?> <?php if (in_array($pg, ['dashboard','einstellungen'], true)) echo 'disabled'; ?>> ausblenden</label>
          <button type="button" class="btn btn-xs btn-outline" data-move="-1" aria-label="<?php echo e(NAV_LABELS[$pg]); ?> nach oben">▲</button>
          <button type="button" class="btn btn-xs btn-outline" data-move="1" aria-label="<?php echo e(NAV_LABELS[$pg]); ?> nach unten">▼</button>
        </li>
        <?php endforeach; ?>
      </ol>
      <button class="btn btn-primary" type="submit">Navigation speichern</button>
    </form>
  </div>
</section>

<section class="card" aria-labelledby="orgDash">
  <div class="ch" id="orgDash">Dashboard-Widgets</div>
  <div class="cb">
    <form id="fWidgets" class="org-form">
      <?php foreach (DASHBOARD_WIDGETS as $k => $label): ?>
      <label class="org-check"><input type="checkbox" name="widgets[]" value="<?php echo e($k); ?>" <?php if (in_array($k, $cfg['dashboard']['widgets'], true)) echo 'checked'; ?>> <?php echo e($label); ?></label>
      <?php endforeach; ?>
      <button class="btn btn-primary" type="submit">Dashboard speichern</button>
    </form>
  </div>
</section>

<section class="card" aria-labelledby="orgPlan">
  <div class="ch" id="orgPlan">Planungsregeln</div>
  <div class="cb">
    <form id="fPlan" class="org-form">
      <label class="org-check"><input type="checkbox" name="concurrent_editing" value="1" <?php if ($cfg['planning']['concurrent_editing']) echo 'checked'; ?>> Gleichzeitiges Bearbeiten erlauben (Konflikte werden erkannt und angezeigt)</label>
      <label class="org-check"><input type="checkbox" name="cross_team_planning" value="1" <?php if ($cfg['planning']['cross_team_planning']) echo 'checked'; ?>> Teamleiter dürfen im Assistenten teamübergreifend planen</label>
      <label class="org-check"><input type="checkbox" name="show_absent_dashboard" value="1" <?php if ($cfg['planning']['show_absent_dashboard']) echo 'checked'; ?>> „Heute abwesend“ im Dashboard anzeigen</label>
      <p class="muted" style="font-size:.78rem">Einsatzcodes pflegen Sie unter <a href="planbereiche">Bereiche</a>, Kunden, Standorte und Qualifikationen unter <a href="stammdaten">Stammdaten</a>, weitere Assistenten-Regeln unter <a href="einstellungen">Einstellungen</a>.</p>
      <button class="btn btn-primary" type="submit">Regeln speichern</button>
    </form>
  </div>
</section>

<?php if (isAdmin()): ?>
<section class="card" aria-labelledby="orgSso">
  <div class="ch" id="orgSso">Anmeldung &amp; Single Sign-on (OIDC)</div>
  <div class="cb">
    <form id="fOidc" class="org-form" autocomplete="off">
      <label class="org-check"><input type="checkbox" name="enabled" value="1" <?php if ($oidc['enabled']) echo 'checked'; ?>> Anmeldung über OpenID Connect aktivieren</label>
      <div class="fg"><label for="oIss">Issuer-URL</label><input id="oIss" name="issuer" placeholder="https://login.example.com/realms/firma" value="<?php echo e($oidc['issuer']); ?>"></div>
      <div class="org-row">
        <div class="fg"><label for="oCid">Client-ID</label><input id="oCid" name="client_id" value="<?php echo e($oidc['client_id']); ?>"></div>
        <div class="fg"><label for="oSec">Client-Secret</label><input id="oSec" name="client_secret" type="password" placeholder="<?php echo ($oidc['client_secret'] || getenv('PQ_OIDC_CLIENT_SECRET')) ? '•••••••• (unverändert)' : 'leer = öffentlicher Client'; ?>"></div>
      </div>
      <div class="org-row">
        <div class="fg"><label for="oClaim">Zuordnung über</label><select id="oClaim" name="claim">
          <?php foreach (['preferred_username' => 'preferred_username = Benutzername', 'email' => 'email = Benutzername', 'upn' => 'upn = Benutzername', 'sub' => 'sub = Benutzername'] as $k => $l): ?>
          <option value="<?php echo $k; ?>" <?php if ($oidc['claim'] === $k) echo 'selected'; ?>><?php echo e($l); ?></option><?php endforeach; ?>
        </select></div>
        <div class="fg"><label for="oLabel">Beschriftung</label><input id="oLabel" name="label" value="<?php echo e($oidc['label']); ?>"></div>
      </div>
      <label class="org-check"><input type="checkbox" name="allow_password_login" value="1" <?php if ($oidc['allow_password_login']) echo 'checked'; ?>> Anmeldung mit Passwort weiterhin erlauben</label>
      <label class="org-check"><input type="checkbox" name="allow_admin" value="1" <?php if (!empty($oidc['allow_admin'])) echo 'checked'; ?>> Administratoren dürfen sich ebenfalls per SSO anmelden (Standard: nur Passwort als Notfallzugang)</label>
      <p class="muted" style="font-size:.78rem">Redirect-URI beim Identity-Provider eintragen: <code><?php echo e($redirectUri); ?></code> (Apps: <code>planquadro://auth</code>). Benutzer werden nicht automatisch angelegt – das Konto muss in Planquadro existieren und aktiv sein.</p>
      <button class="btn btn-primary" type="submit">Anmeldung speichern</button>
      <button class="btn btn-outline" type="button" id="btnOidcTest">Verbindung testen</button>
    </form>
  </div>
</section>
<?php endif; ?>
</div>

<style>
.org-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:1rem}
.org-form{display:flex;flex-direction:column;gap:.55rem}
.org-row{display:flex;gap:.75rem;flex-wrap:wrap}.org-row .fg{flex:1;min-width:140px}
.org-check{display:flex;gap:.5rem;align-items:flex-start;font-size:.85rem;min-height:28px}
.org-check-inline{font-size:.75rem;display:inline-flex;gap:.3rem;align-items:center;margin-left:auto}
.org-hr{border:0;border-top:1px solid var(--bd);margin:1rem 0}
.org-nav{list-style:none;margin:0;padding:0;border:1px solid var(--bd);border-radius:var(--r)}
.org-nav li{display:flex;gap:.4rem;align-items:center;padding:.3rem .5rem;border-bottom:1px solid var(--bd)}
.org-nav li:last-child{border-bottom:0}.org-nav-name{font-weight:600;font-size:.85rem}
.org-preview{display:flex;align-items:center;gap:.5rem;min-height:34px}
</style>
<script>
(function(){
  function send(form, action, extra){
    var fd = form ? new FormData(form) : new FormData();
    fd.append('action', action);
    if (extra) extra(fd);
    ajaxPost('organisation', fd, function(){ if (action !== 'test_oidc') setTimeout(function(){ location.reload(); }, 700); });
  }
  function bind(id, action, extra){ var f=document.getElementById(id); if(f) f.addEventListener('submit', function(e){ e.preventDefault(); send(f, action, extra); }); }
  bind('fBrand','save_branding'); bind('fLogo','upload_logo'); bind('fMods','save_modules');
  bind('fWidgets','save_widgets'); bind('fPlan','save_planning'); bind('fOidc','save_oidc');
  bind('fNav','save_navigation', function(fd){ document.querySelectorAll('#navList li').forEach(function(li){ fd.append('order[]', li.dataset.page); }); });
  var del=document.getElementById('btnLogoDel'); if(del) del.addEventListener('click', function(){ send(null,'remove_logo'); });
  var ot=document.getElementById('btnOidcTest'); if(ot) ot.addEventListener('click', function(){ send(null,'test_oidc'); });
  document.getElementById('navList').addEventListener('click', function(e){
    var b=e.target.closest('[data-move]'); if(!b) return;
    var li=b.closest('li'), dir=parseInt(b.dataset.move,10);
    if(dir<0 && li.previousElementSibling) li.parentNode.insertBefore(li, li.previousElementSibling);
    if(dir>0 && li.nextElementSibling) li.parentNode.insertBefore(li.nextElementSibling, li);
    b.focus();
  });
  /* Kontrastvorschau (WCAG): Schrift auf Markenfarbe */
  function lum(h){h=h.replace('#','');var c=[0,2,4].map(function(i){var v=parseInt(h.substr(i,2),16)/255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4);});return .2126*c[0]+.7152*c[1]+.0722*c[2];}
  function ratio(a,b){var x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
  function upd(){var p=document.getElementById('bPrimary').value;var w=ratio(p,'#ffffff'),d=ratio(p,'#111827');var fg=w>=d?'#ffffff':'#111827';
    var b=document.getElementById('pvBtn');b.style.background=p;b.style.borderColor=p;b.style.color=fg;
    document.getElementById('pvContrast').textContent='Kontrast '+Math.max(w,d).toFixed(1)+':1'+(Math.max(w,d)<4.5?' – zu gering, Text wird automatisch angepasst':' ✓');}
  document.getElementById('bPrimary').addEventListener('input',upd); upd();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
