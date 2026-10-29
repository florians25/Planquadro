<?php
/* !! AJAX HANDLER FIRST !! */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!hasPerm('manage_settings')) { showAccessDenied('Sie benötigen die Berechtigung "Einstellungen verwalten".'); }
$db = getDB();

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'] ?? 'save_settings';
    try {
        if ($act === 'save_settings' || $act === '') {
            $keys = ['maintenance_mode','maintenance_message','company_name','allow_concurrent_edit',
                     'approval_system_enabled','password_expiry_days','captcha_enabled','max_login_attempts',
                     'lockout_duration_minutes','min_password_length','session_timeout_minutes',
                     'login_log_enabled','password_require_uppercase','password_require_number',
                     'password_require_special','dashboard_show_absent',
                     'dp_controller','dp_officer','dp_works_agreement','dp_retention',
                     /* Planungsassistent */
                     'epa_free_areas','epa_prefer_free','epa_respect_bond','epa_home_mode',
                     'epa_demand_default','epa_demand_min','epa_max_consecutive','epa_max_days_total',
                     'epa_azubi_years','epa_azubi_max','epa_azubi_ratio','epa_exp_months','epa_bond_months',
                     'epa_cross_team'];
            foreach ($keys as $k) { if (isset($_POST[$k])) setSetting($k, $_POST[$k]); }
            echo json_encode(['success'=>true,'msg'=>'Einstellungen gespeichert.']);
        } elseif ($act === 'import_holidays') {
            $json = $_POST['holidays_json'] ?? '';
            $data = json_decode($json, true);
            if (!$data || !is_array($data)) { echo json_encode(['success'=>false,'error'=>'Ungültiges JSON']); exit; }
            $saved = 0;
            foreach ($data as $h) {
                $name = trim($h['name'] ?? ''); $date = $h['date'] ?? ''; $type = $h['type'] ?? 'feiertag';
                if (!$name || !$date) continue;
                if (!in_array($type, ['feiertag','ferien'])) $type = 'feiertag';
                $db->prepare('INSERT INTO holidays(name,holiday_date,holiday_type,source) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),holiday_type=VALUES(holiday_type),source=VALUES(source)')
                   ->execute([$name, $date, $type, 'api_import']);
                $saved++;
            }
            echo json_encode(['success'=>true,'msg'=>$saved.' Feiertage/Ferien importiert.']);
        } elseif ($act === 'get_audit_log') {
            $page = max(0,(int)($_POST['page']??0)); $limit=30; $offset=$page*$limit;
            $filter = $_POST['filter'] ?? ''; $where=''; $params=[];
            if ($filter) { $where=' WHERE a.action LIKE ?'; $params[]="%$filter%"; }
            $total=$db->prepare("SELECT COUNT(*) c FROM audit_log a $where");$total->execute($params);$total=(int)$total->fetch()['c'];
            $s=$db->prepare("SELECT a.id, a.action, a.created_at FROM audit_log a $where ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset");
            $s->execute($params);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'items'=>$items,'total'=>$total,'pages'=>ceil($total/$limit),'page'=>$page]);
        } elseif ($act === 'clear_audit_log') {
            $db->exec("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            echo json_encode(['success'=>true,'msg'=>'Audit-Log bereinigt.']);
        } elseif ($act === 'get_login_stats') {
            $today=$db->query("SELECT COUNT(*) c FROM login_attempts WHERE success=1 AND DATE(attempted_at)=CURDATE()")->fetch()['c'];
            $failed7=$db->query("SELECT COUNT(*) c FROM login_attempts WHERE success=0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch()['c'];
            $lockedCount=$db->query("SELECT COUNT(DISTINCT username) c FROM login_attempts WHERE success=0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) GROUP BY username HAVING COUNT(*)>=5")->fetchAll(PDO::FETCH_ASSOC);
            $recentCounts=$db->query("SELECT DATE(attempted_at) d, SUM(success) ok, SUM(1-success) fail FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(attempted_at) ORDER BY d DESC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'today'=>$today,'failed7'=>$failed7,'locked_count'=>count($lockedCount),'daily'=>$recentCounts]);
        } elseif ($act === 'clear_lockouts') {
            $db->exec("DELETE FROM login_attempts WHERE success=0");
            echo json_encode(['success'=>true,'msg'=>'Alle Lockouts aufgehoben.']);
        }
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit;
}

$pageTitle = 'Einstellungen';
require_once __DIR__ . '/../includes/header.php';
$uc=$db->query('SELECT COUNT(*) c FROM users WHERE active=1')->fetch()['c'];
$uic=$db->query('SELECT COUNT(*) c FROM users WHERE active=0')->fetch()['c'];
$ac=$db->query('SELECT COUNT(*) c FROM plan_areas')->fetch()['c'];
$hc=$db->query('SELECT COUNT(*) c FROM holidays')->fetch()['c'];
$asc=$db->query('SELECT COUNT(*) c FROM assignments')->fetch()['c'];
$absc=$db->query('SELECT COUNT(*) c FROM absences')->fetch()['c'];
try{$crc=$db->query('SELECT COUNT(*) c FROM change_requests')->fetch()['c'];}catch(Exception $e){$crc=0;}
try{$auditc=$db->query('SELECT COUNT(*) c FROM audit_log')->fetch()['c'];}catch(Exception $e){$auditc=0;}
$dbVer=$db->query('SELECT VERSION() as v')->fetch()['v']; $phpVer=phpversion();
?>
<style>
/* Sidebar Layout */
.st-wrap{display:grid;grid-template-columns:230px 1fr;gap:0;min-height:calc(100vh - 160px);background:var(--card);border:1px solid var(--bd);border-radius:var(--r2);box-shadow:var(--sh);overflow:hidden}
.st-side{background:#f8fafc;border-right:1px solid var(--bd);padding:.5rem 0;overflow-y:auto}
.st-nav{display:flex;align-items:center;gap:.5rem;padding:.55rem 1rem;font-size:.82rem;font-weight:600;color:var(--mu);cursor:pointer;border:none;background:none;width:100%;text-align:left;border-left:3px solid transparent}
.st-nav:hover{background:rgba(59,130,246,.04);color:var(--tx)}
.st-nav.active{color:var(--p);background:var(--pbg);border-left-color:var(--p)}
.st-nav svg{width:16px;height:16px;flex-shrink:0}
.st-sep{height:1px;background:var(--bd);margin:.35rem .75rem}
.st-main{padding:1.5rem 1.75rem;overflow-y:auto;max-height:calc(100vh - 160px)}
.st-sec{display:none}.st-sec.active{display:block}
.st-h{font-size:1.15rem;font-weight:800;margin-bottom:.15rem;letter-spacing:-.01em}
.st-sub{font-size:.78rem;color:var(--mu);margin-bottom:1.15rem}
/* Setting rows */
.st-card{background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:.85rem 1rem;margin-bottom:.85rem}
.st-card-h{font-size:.8rem;font-weight:700;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;color:var(--tx)}
.st-card-h svg{width:15px;height:15px;color:var(--mu)}
.st-row{display:flex;align-items:center;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid var(--bd);gap:1rem}
.st-row:last-child{border-bottom:none}
.st-row-l{flex:1;min-width:0}
.st-row-t{font-size:.84rem;font-weight:600}
.st-row-d{font-size:.72rem;color:var(--mu);margin-top:.05rem}
.st-row-r{flex-shrink:0;display:flex;align-items:center;gap:.5rem}
/* Toggle */
.st-tog{position:relative;width:42px;height:24px;cursor:pointer;display:inline-block}
.st-tog input{opacity:0;width:0;height:0}
.st-tog .sl{position:absolute;inset:0;background:#d1d5db;border-radius:12px}
.st-tog .sl:before{content:'';position:absolute;width:18px;height:18px;background:#fff;border-radius:50%;left:3px;top:3px;box-shadow:0 1px 3px rgba(0,0,0,.15)}
.st-tog input:checked+.sl{background:var(--p)}
.st-tog input:checked+.sl:before{transform:translateX(18px)}
.st-inp{padding:.35rem .55rem;border:1px solid var(--bd);border-radius:var(--r);font-size:.82rem;width:80px;text-align:center}
.st-inp:focus{outline:none;border-color:var(--pl);box-shadow:0 0 0 2px rgba(226,0,26,.06)}
/* Stats grid */
.st-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(115px,1fr));gap:.55rem;margin-bottom:1rem}
.st-stat{text-align:center;padding:.55rem .4rem;background:var(--card);border:1px solid var(--bd);border-radius:var(--r)}
.st-stat-n{font-size:1.2rem;font-weight:800;line-height:1}
.st-stat-l{font-size:.62rem;color:var(--mu);font-weight:600;margin-top:.15rem}
/* List items */
.st-li{display:flex;justify-content:space-between;align-items:center;padding:.5rem .65rem;border:1px solid var(--bd);border-radius:var(--r);margin-bottom:.4rem;background:var(--card)}
.st-li:hover{border-color:var(--bd2)}
.st-li-info b{font-size:.82rem}
.st-li-info span{font-size:.72rem;color:var(--mu);display:block;margin-top:.05rem}
.st-li-danger{border-color:var(--err);background:#fffbfb}
/* Badges */
.st-badge{display:inline-block;padding:.1rem .3rem;border-radius:3px;font-size:.62rem;font-weight:700}
.st-b-ok{background:#dcfce7;color:#16a34a}.st-b-fail{background:#fef2f2;color:#dc2626}
.st-b-info{background:#e0f2fe;color:#0284c7}.st-b-warn{background:#fef3c7;color:#d97706}
/* Audit table */
.st-atbl{width:100%;border-collapse:collapse;font-size:.76rem}
.st-atbl th,.st-atbl td{padding:.35rem .4rem;text-align:left;border-bottom:1px solid var(--bd)}
.st-atbl th{font-size:.65rem;text-transform:uppercase;letter-spacing:.04em;color:var(--mu);font-weight:700;background:#f8fafc}
.st-atbl tr:hover td{background:rgba(59,130,246,.02)}
/* Login rows */
.st-login{display:flex;align-items:center;gap:.4rem;padding:.3rem 0;border-bottom:1px solid var(--bd);font-size:.76rem}
.st-login:last-child{border-bottom:none}
/* Responsive */
@media screen and (max-width:768px){
.st-wrap{grid-template-columns:1fr;min-height:auto}
.st-side{display:flex;overflow-x:auto;border-right:none;border-bottom:1px solid var(--bd);padding:0}
.st-nav{white-space:nowrap;padding:.5rem .7rem;border-left:none;border-bottom:2px solid transparent;font-size:.75rem}
.st-nav.active{border-left:none;border-bottom-color:var(--p)}
.st-nav svg{display:none}.st-sep{display:none}
.st-main{max-height:none;padding:1rem}
}
</style>

<div class="ph"><h1>Systemeinstellungen</h1><div class="phr"><a class="btn btn-outline" href="organisation">Organisation &amp; Branding</a> <button class="btn btn-primary" onclick="saveSettings()">Speichern</button></div></div>

<div class="st-wrap">
<!-- ===== SIDEBAR ===== -->
<div class="st-side">
<button class="st-nav active" onclick="stNav(0)"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>Allgemein</button>
<button class="st-nav" onclick="stNav(1)"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>Planung</button>
<button class="st-nav" onclick="stNav(2)"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>Sicherheit</button>
<button class="st-nav" onclick="stNav(3)"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>Login &amp; Zugriff</button>
<div class="st-sep"></div>
<button class="st-nav" onclick="stNav(4)"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>Wartung</button>
<button class="st-nav" onclick="stNav(5)"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z"/></svg>Datenimport</button>
<button class="st-nav" onclick="stNav(6)"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1z" clip-rule="evenodd"/></svg>Changelog</button>
<div class="st-sep"></div>
<button class="st-nav" onclick="stNav(7)"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2h-2.22l.123.489.804.804A1 1 0 0113 18H7a1 1 0 01-.707-1.707l.804-.804L7.22 15H5a2 2 0 01-2-2V5zm5.771 7H5V5h10v7H8.771z" clip-rule="evenodd"/></svg>System</button>
<button class="st-nav" onclick="stNav(8)"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/></svg>Audit-Log</button>
<button class="st-nav" onclick="stNav(9)"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L6.382 6H3a1 1 0 000 2h.341l.764 8.336A2 2 0 006.098 18h7.804a2 2 0 001.993-1.664L16.659 8H17a1 1 0 100-2h-3.382l-1.724-3.447A1 1 0 0011 2H9zm1 4l-1-2h2l-1 2z" clip-rule="evenodd"/></svg>Bereinigung</button>
<button class="st-nav" onclick="stNav(10)"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z"/><path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd"/></svg>Backup</button>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="st-main">

<!-- 0: Allgemein -->
<div class="st-sec active" id="sP0">
<div class="st-h">Allgemein</div><div class="st-sub">Grundlegende Systemkonfiguration</div>
<div class="st-card">
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Firmenname</div><div class="st-row-d">Wird in Kopfzeile und Druck angezeigt</div></div><div class="st-row-r"><input type="text" id="sCompany" value="<?php echo e(getSetting('company_name','OpsPilot'));?>" class="st-inp" style="width:180px"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">App-Version</div></div><div class="st-row-r"><span class="st-badge st-b-info">v6.7.0</span></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Heute Abwesend anzeigen</div><div class="st-row-d">Abwesenheits-Liste auf dem Dashboard anzeigen</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sShowAbsent" <?php echo getSetting('dashboard_show_absent','1')!=='0'?'checked':'';?>><span class="sl"></span></label></div></div>
</div>
<div class="st-h" style="margin-top:1rem">Datenschutz</div><div class="st-sub">Angaben für die <a href="<?php echo $_bp;?>datenschutz">Datenschutz-Seite</a> (DSGVO / Betriebsrat)</div>
<div class="st-card">
<div class="st-row" style="flex-direction:column;align-items:stretch"><div class="st-row-l"><div class="st-row-t">Verantwortliche Stelle</div><div class="st-row-d">Name &amp; Anschrift</div></div><textarea id="sDpController" rows="3" style="width:100%;margin-top:.35rem;padding:.4rem .55rem;border:1px solid var(--bd);border-radius:var(--r);font-size:.82rem;resize:vertical"><?php echo e(getSetting('dp_controller',''));?></textarea></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Datenschutzbeauftragte:r</div><div class="st-row-d">Name / Kontakt</div></div><div class="st-row-r"><input type="text" id="sDpOfficer" value="<?php echo e(getSetting('dp_officer',''));?>" class="st-inp" style="width:220px"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Betriebsvereinbarung</div><div class="st-row-d">Bezeichnung / Datum</div></div><div class="st-row-r"><input type="text" id="sDpAgreement" value="<?php echo e(getSetting('dp_works_agreement',''));?>" class="st-inp" style="width:220px"></div></div>
<div class="st-row" style="flex-direction:column;align-items:stretch"><div class="st-row-l"><div class="st-row-t">Aufbewahrung / Löschfristen</div><div class="st-row-d">Optional – sonst Standardtext</div></div><textarea id="sDpRetention" rows="2" style="width:100%;margin-top:.35rem;padding:.4rem .55rem;border:1px solid var(--bd);border-radius:var(--r);font-size:.82rem;resize:vertical"><?php echo e(getSetting('dp_retention',''));?></textarea></div>
</div>
</div>

<!-- 1: Planung -->
<div class="st-sec" id="sP1">
<div class="st-h">Planung</div><div class="st-sub">Optionen für Einsatz- und Monatsplanung</div>
<div class="st-card">
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Gleichzeitige Bearbeitung</div><div class="st-row-d">Warnung bei gleichzeitigem Editieren</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sConcurrent" <?php echo getSetting('allow_concurrent_edit','1')!=='0'?'checked':'';?>><span class="sl"></span></label></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Beantragungssystem</div><div class="st-row-d">Mitarbeiter können Änderungen vorschlagen</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sApproval" <?php echo getSetting('approval_system_enabled','0')==='1'?'checked':'';?>><span class="sl"></span></label></div></div>
</div>

<?php
/* ---------------------------------------------------------------------------
   Planungsassistent – Vorgaben. Damit laesst sich die Anwendung an den eigenen
   Betrieb anpassen, ohne im Code etwas zu aendern.
   --------------------------------------------------------------------------- */
$epaAreas = [];
try {
    $epaAreas = $db->query("SELECT id, short_code, name, area_type FROM plan_areas
                             ORDER BY area_type, sort_order, short_code")->fetchAll();
} catch (Exception $e) {}
$epaFree = array_filter(array_map('intval', explode(',', (string)getSetting('epa_free_areas', ''))));
$epaYears = array_filter(array_map('intval', explode(',', (string)getSetting('epa_azubi_years', '2,3'))));
?>
<div class="st-card">
<div class="st-card-h">Planungsassistent</div>
<div class="st-row" style="flex-direction:column;align-items:stretch">
  <div class="st-row-l"><div class="st-row-t">Einsatzbereiche mit freier Kapazität</div>
    <div class="st-row-d">Wer an einem solchen Tag eingetragen ist (typischerweise Technik oder Innendienst),
      gilt für den Assistenten als verfügbar und wird beim Übernehmen umgetragen.
      Berufsschule, Urlaub und Sperrtage bleiben in jedem Fall geschützt.</div></div>
  <div class="wd-checks" id="sEpaFree" style="margin-top:.5rem">
    <?php foreach ($epaAreas as $a): ?>
      <label class="wd-check" title="<?php echo e($a['area_type']==='kunde'?'Kundenbereich':'Allgemeiner Bereich'); ?>">
        <input type="checkbox" data-area="<?php echo (int)$a['id']; ?>" <?php echo in_array((int)$a['id'], $epaFree, true)?'checked':''; ?>>
        <?php echo e($a['short_code']); ?> <span class="muted" style="font-weight:400"><?php echo e($a['name']); ?></span>
      </label>
    <?php endforeach; ?>
    <?php if (!$epaAreas): ?><span class="muted">Keine Planbereiche vorhanden.</span><?php endif; ?>
  </div>
</div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Freie Kapazität aktiv auflösen</div><div class="st-row-d">Solche Tage werden bevorzugt zum Kunden umgeplant</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sEpaPrefer" <?php echo getSetting('epa_prefer_free','1')!=='0'?'checked':'';?>><span class="sl"></span></label></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Kundenbindung beachten</div><div class="st-row-d">Wer überwiegend bei einem Kunden ist, bleibt dort</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sEpaBond" <?php echo getSetting('epa_respect_bond','1')!=='0'?'checked':'';?>><span class="sl"></span></label></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Stammbereich</div><div class="st-row-d">Voreinstellung für neue Planungen</div></div><div class="st-row-r">
  <select id="sEpaHome" class="st-inp" style="width:auto">
    <?php $hm = getSetting('epa_home_mode','prefer'); ?>
    <option value="prefer" <?php echo $hm==='prefer'?'selected':'';?>>bevorzugen</option>
    <option value="only" <?php echo $hm==='only'?'selected':'';?>>ausschließlich</option>
    <option value="off" <?php echo $hm==='off'?'selected':'';?>>nicht beachten</option>
  </select></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Regelbedarf pro Tag</div><div class="st-row-d">Voreinstellung, im Assistenten änderbar</div></div><div class="st-row-r"><input type="number" id="sEpaDemand" class="st-inp" min="1" max="99" value="<?php echo e(getSetting('epa_demand_default','2'));?>"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Mindestbesetzung</div><div class="st-row-d">Darunter meldet die Prüfung einen Blocker</div></div><div class="st-row-r"><input type="number" id="sEpaDemandMin" class="st-inp" min="0" max="99" value="<?php echo e(getSetting('epa_demand_min','1'));?>"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Tage am Stück</div><div class="st-row-d">Obergrenze je Person</div></div><div class="st-row-r"><input type="number" id="sEpaStreak" class="st-inp" min="1" max="60" value="<?php echo e(getSetting('epa_max_consecutive','10'));?>"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Tage im Zeitraum</div><div class="st-row-d">Obergrenze je Person und Lauf</div></div><div class="st-row-r"><input type="number" id="sEpaTotal" class="st-inp" min="1" max="120" value="<?php echo e(getSetting('epa_max_days_total','20'));?>"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Azubis: Lehrjahre</div><div class="st-row-d">Voreinstellung, welche Lehrjahre eingeplant werden</div></div><div class="st-row-r">
  <span class="wd-checks" id="sEpaYears">
    <?php foreach ([1,2,3] as $y): ?>
      <label class="wd-check"><input type="checkbox" data-year="<?php echo $y; ?>" <?php echo in_array($y,$epaYears,true)?'checked':''; ?>> <?php echo $y; ?>. LJ</label>
    <?php endforeach; ?>
  </span></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Azubis pro Tag</div><div class="st-row-d">Höchstzahl</div></div><div class="st-row-r"><input type="number" id="sEpaAzMax" class="st-inp" min="0" max="20" value="<?php echo e(getSetting('epa_azubi_max','1'));?>"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Betreuungsschlüssel</div><div class="st-row-d">Azubis je Fachkraft</div></div><div class="st-row-r"><input type="number" id="sEpaAzRatio" class="st-inp" min="1" max="10" value="<?php echo e(getSetting('epa_azubi_ratio','2'));?>"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Kundenerfahrung: Zeitraum</div><div class="st-row-d">Monate, aus denen frühere Einsätze zählen</div></div><div class="st-row-r"><input type="number" id="sEpaExp" class="st-inp" min="1" max="60" value="<?php echo e(getSetting('epa_exp_months','12'));?>"></div></div>
<div class="st-row" style="flex-direction:column;align-items:stretch">
  <div class="st-row-l"><div class="st-row-t">Teamleiter dürfen teamübergreifend planen</div>
    <div class="st-row-d">Aus: Teamleiter planen nur ihr eigenes Team. An: Sie können im Assistenten zusätzlich
      Mitarbeitende anderer Teams einbeziehen – mit Warnhinweis und Protokolleintrag.
      Administratoren und Manager können das ohnehin.
      <br>Abwesenheitsgründe richten sich unabhängig davon nach den Regeln der Monatsplanung:
      Administrator immer, Manager und Teamleiter für das eigene Team, alle übrigen sehen nur „Abwesend“.</div></div>
  <div class="st-row-r" style="margin-top:.4rem"><label class="st-tog"><input type="checkbox" id="sEpaCross" <?php echo getSetting('epa_cross_team','0')==='1'?'checked':'';?>><span class="sl"></span></label></div>
</div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Kundenbindung: Zeitraum</div><div class="st-row-d">Monate für die Auswertung, wie fest jemand gebunden ist</div></div><div class="st-row-r"><input type="number" id="sEpaBondM" class="st-inp" min="1" max="60" value="<?php echo e(getSetting('epa_bond_months','6'));?>"></div></div>
</div>
</div>

<!-- 2: Sicherheit -->
<div class="st-sec" id="sP2">
<div class="st-h">Sicherheit</div><div class="st-sub">Passwort-Richtlinien, CAPTCHA und Login-Schutz</div>
<div class="st-card">
<div class="st-card-h">Passwort-Richtlinien</div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Ablauf (Tage)</div><div class="st-row-d">0 = kein Ablauf</div></div><div class="st-row-r"><input type="number" id="sPwExpiry" value="<?php echo e(getSetting('password_expiry_days','30'));?>" min="0" max="365" class="st-inp"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Mindestlänge</div></div><div class="st-row-r"><input type="number" id="sMinPwLen" value="<?php echo e(getSetting('min_password_length','6'));?>" min="4" max="32" class="st-inp"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Großbuchstabe</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sPwUpper" <?php echo getSetting('password_require_uppercase','0')==='1'?'checked':'';?>><span class="sl"></span></label></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Zahl erforderlich</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sPwNum" <?php echo getSetting('password_require_number','0')==='1'?'checked':'';?>><span class="sl"></span></label></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Sonderzeichen</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sPwSpec" <?php echo getSetting('password_require_special','0')==='1'?'checked':'';?>><span class="sl"></span></label></div></div>
</div>
<div class="st-card">
<div class="st-card-h">Login-Schutz</div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">CAPTCHA</div><div class="st-row-d">Mathe-Aufgabe nach 2 Fehlversuchen</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sCaptcha" <?php echo getSetting('captcha_enabled','1')==='1'?'checked':'';?>><span class="sl"></span></label></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Max. Versuche</div></div><div class="st-row-r"><input type="number" id="sMaxAttempts" value="<?php echo e(getSetting('max_login_attempts','5'));?>" min="0" max="20" class="st-inp"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Sperrdauer (Min.)</div></div><div class="st-row-r"><input type="number" id="sLockoutMin" value="<?php echo e(getSetting('lockout_duration_minutes','15'));?>" min="1" max="60" class="st-inp"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Session-Timeout (Min.)</div><div class="st-row-d">0 = kein Timeout</div></div><div class="st-row-r"><input type="number" id="sSessionTimeout" value="<?php echo e(getSetting('session_timeout_minutes','480'));?>" min="0" max="1440" class="st-inp"></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Login-Protokoll</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sLoginLog" <?php echo getSetting('login_log_enabled','1')==='1'?'checked':'';?>><span class="sl"></span></label></div></div>
</div>
</div>

<!-- 3: Login & Zugriff -->
<div class="st-sec" id="sP3">
<div class="st-h">Login &amp; Zugriff</div><div class="st-sub">Login-Aktivitäten und gesperrte Konten</div>
<div class="st-stats" id="loginStats">
<div class="st-stat"><div class="st-stat-n" id="lsToday" style="color:var(--ok)">–</div><div class="st-stat-l">Logins heute</div></div>
<div class="st-stat"><div class="st-stat-n" id="lsFailed" style="color:var(--err)">–</div><div class="st-stat-l">Fehlversuche (7d)</div></div>
<div class="st-stat"><div class="st-stat-n" id="lsLocked" style="color:var(--wn)">–</div><div class="st-stat-l">Gesperrt</div></div>
</div>
<div style="display:flex;gap:.4rem;margin-bottom:.75rem"><button class="btn btn-xs btn-outline" onclick="loadLoginStats()">Aktualisieren</button><button class="btn btn-xs btn-outline btn-danger-text" onclick="clearLockouts()">Alle Sperren aufheben</button></div>
<div class="st-card"><div class="st-card-h">Login-Statistik (Tagesübersicht)</div><div id="loginLogList"><p class="muted">Laden...</p></div></div>
</div>

<!-- 4: Wartung -->
<div class="st-sec" id="sP4">
<div class="st-h">Wartung</div><div class="st-sub">Wartungsmodus und geplante Wartungsarbeiten</div>
<div class="st-card">
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Wartungsmodus</div><div class="st-row-d">Nur Administratoren und Manager können weiterarbeiten</div></div><div class="st-row-r"><label class="st-tog"><input type="checkbox" id="sMaint" <?php echo getSetting('maintenance_mode','0')==='1'?'checked':'';?>><span class="sl"></span></label></div></div>
<div class="st-row" style="flex-direction:column;align-items:stretch"><div class="st-row-l"><div class="st-row-t">Wartungsnachricht</div></div><textarea id="sMsg" rows="2" style="width:100%;margin-top:.35rem;padding:.4rem .55rem;border:1px solid var(--bd);border-radius:var(--r);font-size:.82rem;resize:vertical"><?php echo e(getSetting('maintenance_message','Das System wird gewartet.'));?></textarea></div>
</div>
<div class="st-card">
<div class="st-card-h" style="justify-content:space-between">Geplante Wartungen <button class="btn btn-xs btn-primary" onclick="oMod('modAddMaint')">+ Neue Wartung</button></div>
<div id="maintList"><p class="muted">Laden...</p></div>
</div>

<div class="st-card" style="margin-top:1rem">
<div class="st-card-h" style="justify-content:space-between">Meldungen (Dashboard) <button class="btn btn-xs btn-primary" onclick="oMod('modAddAnn')">+ Neue Meldung</button></div>
<div id="annList"><p class="muted">Laden...</p></div>
</div>
</div>

<!-- 5: Datenimport -->
<div class="st-sec" id="sP5">
<div class="st-h">Datenimport</div><div class="st-sub">Feiertage und Ferien per JSON importieren</div>
<div class="st-card">
<p class="muted" style="font-size:.78rem;margin-bottom:.5rem">Format: <code>[{"name":"...","date":"YYYY-MM-DD","type":"feiertag|ferien"}]</code></p>
<div style="font-size:.72rem;margin-bottom:.5rem;padding:.35rem .5rem;background:#f8fafc;border-radius:var(--r);border:1px solid var(--bd)">
Feiertag: <code>{"name":"Neujahr","date":"2026-01-01","type":"feiertag"}</code><br>
Ferien: <code>{"name":"Osterferien","date":"2026-03-30","type":"ferien"}</code>
</div>
<div class="fg"><label>JSON-Daten</label><textarea id="importJson" rows="8" placeholder='[{"name":"Neujahr","date":"2026-01-01","type":"feiertag"}]' style="font-family:monospace;font-size:.8rem"></textarea></div>
<button class="btn btn-sm btn-primary" onclick="importHolidays()">Importieren</button>
</div>
</div>

<!-- 6: Changelog -->
<div class="st-sec" id="sP6">
<div class="st-h">Changelog</div><div class="st-sub">Änderungsprotokoll verwalten</div>
<div style="margin-bottom:.75rem"><button class="btn btn-sm btn-primary" onclick="oMod('modAddCl')">+ Neuer Eintrag</button></div>
<div class="st-card"><div id="clList"><p class="muted">Laden...</p></div></div>
</div>

<!-- 7: System -->
<div class="st-sec" id="sP7">
<div class="st-h">System</div><div class="st-sub">Datenbank-Statistiken und Serverumgebung</div>
<div class="st-stats">
<div class="st-stat"><div class="st-stat-n" style="color:var(--ok)"><?php echo $uc;?></div><div class="st-stat-l">Aktive User</div></div>
<div class="st-stat"><div class="st-stat-n" style="color:var(--mu)"><?php echo $uic;?></div><div class="st-stat-l">Inaktiv</div></div>
<div class="st-stat"><div class="st-stat-n" style="color:var(--inf)"><?php echo $ac;?></div><div class="st-stat-l">Bereiche</div></div>
<div class="st-stat"><div class="st-stat-n"><?php echo $hc;?></div><div class="st-stat-l">Feiertage</div></div>
<div class="st-stat"><div class="st-stat-n"><?php echo number_format($asc);?></div><div class="st-stat-l">Einsätze</div></div>
<div class="st-stat"><div class="st-stat-n"><?php echo number_format($absc);?></div><div class="st-stat-l">Abwesenh.</div></div>
<div class="st-stat"><div class="st-stat-n"><?php echo $crc;?></div><div class="st-stat-l">Anträge</div></div>
<div class="st-stat"><div class="st-stat-n"><?php echo number_format($auditc);?></div><div class="st-stat-l">Audit</div></div>
</div>
<div class="st-card">
<div class="st-row"><div class="st-row-l"><div class="st-row-t">PHP</div></div><div class="st-row-r"><span class="st-badge st-b-info"><?php echo $phpVer;?></span></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">MySQL</div></div><div class="st-row-r"><span class="st-badge st-b-info"><?php echo $dbVer;?></span></div></div>
<div class="st-row"><div class="st-row-l"><div class="st-row-t">Server</div></div><div class="st-row-r"><span class="muted" style="font-size:.75rem"><?php echo e(php_uname('s').' '.php_uname('r'));?></span></div></div>
</div>
</div>

<!-- 8: Audit-Log -->
<div class="st-sec" id="sP8">
<div class="st-h">Audit-Log</div><div class="st-sub">Sicherheitsprotokoll aller Systemaktionen</div>
<div style="display:flex;gap:.4rem;margin-bottom:.75rem;align-items:center">
<select id="auditFilter" class="sel sel-sm" onchange="loadAudit(0)" style="width:auto"><option value="">Alle</option><option value="login_success">Login OK</option><option value="login_failed">Login Fail</option><option value="password_changed">Passwort</option></select>
<button class="btn btn-xs btn-outline" onclick="loadAudit(0)">Aktualisieren</button>
<button class="btn btn-xs btn-outline btn-danger-text" onclick="clearAuditLog()">Ältere löschen (>90d)</button>
</div>
<div id="auditLogBody"><p class="muted">Laden...</p></div>
</div>

<!-- 9: Bereinigung -->
<div class="st-sec" id="sP9">
<div class="st-h">Bereinigung</div><div class="st-sub">Alte Daten löschen und Datenbank optimieren</div>
<div class="st-li"><div class="st-li-info"><b>Alte Benachrichtigungen</b><span>Gelesene, älter als 30 Tage</span></div><button class="btn btn-xs btn-outline btn-danger-text" onclick="dbCleanup('old_notifications')">Bereinigen</button></div>
<div class="st-li"><div class="st-li-info"><b>Bearbeitungsstatus</b><span>Live-Editing-Locks entfernen</span></div><button class="btn btn-xs btn-outline btn-danger-text" onclick="dbCleanup('editing_status')">Bereinigen</button></div>
<div class="st-li"><div class="st-li-info"><b>Erledigte Anträge</b><span>Genehmigte/abgelehnte älter als 90 Tage</span></div><button class="btn btn-xs btn-outline btn-danger-text" onclick="dbCleanup('old_requests')">Bereinigen</button></div>
<div class="st-li"><div class="st-li-info"><b>Login-Protokoll</b><span>Einträge älter als 30 Tage</span></div><button class="btn btn-xs btn-outline btn-danger-text" onclick="dbCleanup('old_login_attempts')">Bereinigen</button></div>
<div class="st-li"><div class="st-li-info"><b>Feiertags-Einsätze</b><span>Einsätze an Feiertagen entfernen</span></div><div style="display:flex;gap:.3rem;align-items:center"><input type="number" id="cleanupHolYear" value="<?php echo date('Y');?>" class="st-inp" min="2020" max="2040"><button class="btn btn-xs btn-outline btn-danger-text" onclick="dbCleanup('holiday_entries',document.getElementById('cleanupHolYear').value)">Los</button></div></div>
<div class="st-li"><div class="st-li-info"><b>Publish-Log</b><span>Auf 100 Einträge kürzen</span></div><button class="btn btn-xs btn-outline btn-danger-text" onclick="dbCleanup('publish_log')">Bereinigen</button></div>
<div class="st-li st-li-danger"><div class="st-li-info"><b style="color:var(--err)">Jahres-Daten löschen</b><span>Unwiderruflich!</span></div><div style="display:flex;gap:.3rem;align-items:center"><input type="number" id="cleanupYear" placeholder="Jahr" class="st-inp" min="2020" max="2040"><button class="btn btn-xs btn-danger" onclick="dbCleanup('delete_year')">Löschen</button></div></div>
</div>

<!-- 10: Backup & Restore -->
<div class="st-sec" id="sP10">
<div class="st-h">Backup &amp; Restore</div><div class="st-sub">Konsistente, mit einem öffentlichen Schlüssel verschlüsselte Backups (libsodium). Der private Schlüssel zum Wiederherstellen liegt nicht auf dem Server.</div>

<div id="backupState" style="margin-bottom:.75rem"><p class="muted" style="padding:.5rem">Laden...</p></div>

<div style="display:flex;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap">
<button class="btn btn-primary" id="backupCreateBtn" onclick="createBackup()">
<svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;margin-right:.3rem;vertical-align:middle"><path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z"/><path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd"/></svg>
Backup jetzt erstellen</button>
<button class="btn btn-outline" onclick="loadBackups()">Aktualisieren</button>
</div>

<div class="st-card">
<div class="st-card-h">Backups <span class="muted" style="font-weight:400;font-size:.72rem" id="backupCounter"></span></div>
<div id="backupList"><p class="muted" style="padding:.5rem">Laden...</p></div>
</div>

<div class="st-card" id="legacyBackupCard" style="display:none">
<div class="st-card-h">Alt-Backups (Version 6.x, .epbak)</div>
<p class="muted" style="font-size:.72rem;padding:0 .5rem .4rem">Nur noch per Kommandozeile wiederherstellbar: <code>php bin/backup.php legacy-decrypt &lt;datei&gt; app/backups/.backup_key</code> (siehe docs/BACKUP.md). Schlüsseldatei anschließend offline sichern und vom Server entfernen.</p>
<div id="legacyBackupList"></div>
</div>

<p class="muted" style="font-size:.7rem">Wiederherstellen: <code>php bin/backup.php restore &lt;archiv&gt; --target-db=&lt;neue_db&gt;</code> mit dem offline verwahrten privaten Schlüssel – Anleitung in docs/BACKUP.md und docs/DISASTER_RECOVERY.md.</p>
</div>

</div><!-- /st-main -->
</div><!-- /st-wrap -->

<!-- ===== MODALS (für Erfassung/Bearbeitung) ===== -->

<!-- Modal: Neue Wartung -->
<div class="modal-bg" id="modAddMaint"><div class="modal"><div class="mh"><h3>Neue Wartung planen</h3><button class="mx" onclick="cMod('modAddMaint')">&times;</button></div><div class="mb">
<div class="fg"><label>Titel</label><input type="text" id="maintTitle" placeholder="z.B. Server-Update"></div>
<div class="fg"><label>Beschreibung (optional)</label><input type="text" id="maintDesc" placeholder="Details zur Wartung"></div>
<div class="fg2"><div class="fg"><label>Start</label><input type="datetime-local" id="maintStart"></div><div class="fg"><label>Ende (optional)</label><input type="datetime-local" id="maintEnd"></div></div>
</div><div class="mf"><button class="btn btn-outline" onclick="cMod('modAddMaint')">Abbrechen</button><button class="btn btn-primary" onclick="addMaint()">Wartung planen</button></div></div></div>

<!-- Modal: Neuer Changelog-Eintrag -->
<div class="modal-bg" id="modAddCl"><div class="modal modal-sm"><div class="mh"><h3>Changelog-Eintrag erstellen</h3><button class="mx" onclick="cMod('modAddCl')">&times;</button></div><div class="mb" style="max-height:60vh;overflow-y:auto">
<div class="fg2"><div class="fg"><label>Version</label><input type="text" id="clVer" placeholder="z.B. 6.1.2"></div><div class="fg"><label>Datum</label><input type="date" id="clDate" value="<?php echo date('Y-m-d');?>"></div></div>
<div class="fg"><label>Typ</label><select class="sel" id="clType"><option value="feature">Feature</option><option value="bugfix">Bugfix</option><option value="improvement">Verbesserung</option><option value="security">Sicherheit</option><option value="breaking">Breaking Change</option></select></div>
<div class="fg"><label>Inhalt</label><textarea id="clContent" rows="10" placeholder="Was ist neu?&#10;&#10;Zeilenumbrüche und Aufzählungen möglich:&#10;- Feature 1&#10;- Feature 2" style="font-family:monospace;font-size:.8rem;resize:vertical"></textarea></div>
</div><div class="mf"><button class="btn btn-outline" onclick="cMod('modAddCl')">Abbrechen</button><button class="btn btn-primary" onclick="addCl()">Speichern</button></div></div></div>

<!-- Modal: Neue Meldung -->
<div class="modal-bg" id="modAddAnn"><div class="modal modal-sm"><div class="mh"><h3>Neue Meldung</h3><button class="mx" onclick="cMod('modAddAnn')">&times;</button></div><div class="mb">
<div class="fg"><label>Titel</label><input type="text" id="annTitle" placeholder="z.B. Serverumzug am Wochenende"></div>
<div class="fg"><label>Inhalt</label><textarea id="annContent" rows="4" placeholder="Details zur Meldung..." style="resize:vertical"></textarea></div>
<div class="fg"><label>Priorität</label>
<div style="display:flex;gap:.4rem">
<label class="ann-prio-opt" onclick="document.getElementById('annPrio').value='info'" id="annPrioInfo"><span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-size:.72rem;font-weight:700">Info</span></label>
<label class="ann-prio-opt" onclick="document.getElementById('annPrio').value='warning'" id="annPrioWarn"><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;font-size:.72rem;font-weight:700">Warnung</span></label>
<label class="ann-prio-opt" onclick="document.getElementById('annPrio').value='critical'" id="annPrioCrit"><span style="background:#fef2f2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:.72rem;font-weight:700">Kritisch</span></label>
</div>
<input type="hidden" id="annPrio" value="info">
</div>
<div class="fg2">
<div class="fg"><label>Sichtbar ab (optional)</label><input type="date" id="annFrom"></div>
<div class="fg"><label>Sichtbar bis (optional)</label><input type="date" id="annUntil"></div>
</div>
</div><div class="mf"><button class="btn btn-outline" onclick="cMod('modAddAnn')">Abbrechen</button><button class="btn btn-primary" onclick="saveAnn()">Speichern</button></div></div></div>

<!-- Modal: Datenimport (JSON) -->
<div class="modal-bg" id="modImport"><div class="modal modal-lg"><div class="mh"><h3>Feiertage/Ferien importieren</h3><button class="mx" onclick="cMod('modImport')">&times;</button></div><div class="mb">
<div class="fg"><label>JSON-Daten</label><textarea id="importJsonMod" rows="12" placeholder='[{"name":"Neujahr","date":"2026-01-01","type":"feiertag"}]' style="font-family:monospace;font-size:.8rem"></textarea></div>
</div><div class="mf"><button class="btn btn-outline" onclick="cMod('modImport')">Abbrechen</button><button class="btn btn-primary" onclick="importHolidaysMod()">Importieren</button></div></div></div>

<script>
/* === Sidebar Navigation === */
function stNav(idx){
    document.querySelectorAll('.st-nav').forEach(function(n,i){n.classList.toggle('active',i===idx);});
    document.querySelectorAll('.st-sec').forEach(function(s,i){s.classList.toggle('active',i===idx);});
    if(idx===3)loadLoginStats();
    if(idx===4)loadAnnList();
    if(idx===6)loadClList();
    if(idx===8)loadAudit(0);
    if(idx===10)loadBackups();
}

/* === Save Settings === */
function saveSettings(){
    var fd=new FormData();fd.append('action','save_settings');
    fd.append('company_name',document.getElementById('sCompany').value);
    fd.append('allow_concurrent_edit',document.getElementById('sConcurrent').checked?'1':'0');
    fd.append('approval_system_enabled',document.getElementById('sApproval').checked?'1':'0');
    fd.append('password_expiry_days',document.getElementById('sPwExpiry').value);
    fd.append('min_password_length',document.getElementById('sMinPwLen').value);
    fd.append('password_require_uppercase',document.getElementById('sPwUpper').checked?'1':'0');
    fd.append('password_require_number',document.getElementById('sPwNum').checked?'1':'0');
    fd.append('password_require_special',document.getElementById('sPwSpec').checked?'1':'0');
    fd.append('captcha_enabled',document.getElementById('sCaptcha').checked?'1':'0');
    fd.append('max_login_attempts',document.getElementById('sMaxAttempts').value);
    fd.append('lockout_duration_minutes',document.getElementById('sLockoutMin').value);
    fd.append('session_timeout_minutes',document.getElementById('sSessionTimeout').value);
    fd.append('login_log_enabled',document.getElementById('sLoginLog').checked?'1':'0');
    fd.append('maintenance_mode',document.getElementById('sMaint').checked?'1':'0');
    fd.append('maintenance_message',document.getElementById('sMsg').value);
    fd.append('dashboard_show_absent',document.getElementById('sShowAbsent').checked?'1':'0');
    fd.append('dp_controller',document.getElementById('sDpController').value);
    fd.append('dp_officer',document.getElementById('sDpOfficer').value);
    fd.append('dp_works_agreement',document.getElementById('sDpAgreement').value);
    fd.append('dp_retention',document.getElementById('sDpRetention').value);
    /* Planungsassistent */
    var epaAreas=[];document.querySelectorAll('#sEpaFree [data-area]').forEach(function(c){if(c.checked)epaAreas.push(c.dataset.area);});
    var epaYears=[];document.querySelectorAll('#sEpaYears [data-year]').forEach(function(c){if(c.checked)epaYears.push(c.dataset.year);});
    fd.append('epa_free_areas',epaAreas.join(','));
    fd.append('epa_azubi_years',epaYears.join(','));
    fd.append('epa_prefer_free',document.getElementById('sEpaPrefer').checked?'1':'0');
    fd.append('epa_respect_bond',document.getElementById('sEpaBond').checked?'1':'0');
    fd.append('epa_home_mode',document.getElementById('sEpaHome').value);
    fd.append('epa_demand_default',document.getElementById('sEpaDemand').value);
    fd.append('epa_demand_min',document.getElementById('sEpaDemandMin').value);
    fd.append('epa_max_consecutive',document.getElementById('sEpaStreak').value);
    fd.append('epa_max_days_total',document.getElementById('sEpaTotal').value);
    fd.append('epa_azubi_max',document.getElementById('sEpaAzMax').value);
    fd.append('epa_azubi_ratio',document.getElementById('sEpaAzRatio').value);
    fd.append('epa_exp_months',document.getElementById('sEpaExp').value);
    fd.append('epa_bond_months',document.getElementById('sEpaBondM').value);
    fd.append('epa_cross_team',document.getElementById('sEpaCross').checked?'1':'0');
    ajaxPost('einstellungen',fd);
}

/* === Cleanup === */
function dbCleanup(type,yO){
    var d={action:'cleanup',type:type};
    if(type==='delete_year'){var yr=document.getElementById('cleanupYear').value;if(!yr){toast('Jahr eingeben','warning');return;}d.year=parseInt(yr);}
    if(type==='holiday_entries')d.year=parseInt(yO||document.getElementById('cleanupHolYear').value||new Date().getFullYear());
    var m=type==='delete_year'?'ACHTUNG: Alle Daten für '+d.year+' löschen?':'Bereinigung durchführen?';
    epConfirm(m,function(){fetch('../api/cleanup',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)}).then(function(r){return r.json();}).then(function(d){if(d.success)toast(d.msg,'success');else toast(d.error||'Fehler','error');}).catch(function(e){toast(e.message,'error');});});
}

/* === Changelog (list in sidebar, add via modal) === */
function loadClList(){
    var el=document.getElementById('clList');el.innerHTML='<p class="muted">Laden...</p>';
    fetch('../api/changelog',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success||!d.entries||!d.entries.length){el.innerHTML='<p class="muted" style="font-size:.82rem">Keine Eintr\u00e4ge vorhanden.</p>';return;}
        var typeBg={feature:'#dcfce7',bugfix:'#fef2f2',improvement:'#e0f2fe',security:'#fef3c7',breaking:'#fce7f3'};
        var typeCol={feature:'#16a34a',bugfix:'#dc2626',improvement:'#0369a1',security:'#92400e',breaking:'#be185d'};
        var typeLabel={feature:'Feature',bugfix:'Bugfix',improvement:'Verbesserung',security:'Sicherheit',breaking:'Breaking'};
        var h='';d.entries.forEach(function(e){
            var et=e.entry_type||'feature';
            h+='<div style="display:flex;justify-content:space-between;align-items:flex-start;padding:.45rem 0;border-bottom:1px solid var(--bd)">';
            h+='<div><span class="st-badge st-b-info">v'+esc(e.version)+'</span> ';
            h+='<span class="badge" style="background:'+(typeBg[et]||typeBg.feature)+';color:'+(typeCol[et]||typeCol.feature)+';font-size:.58rem">'+(typeLabel[et]||et)+'</span> ';
            h+='<span class="muted" style="font-size:.7rem">'+esc(e.release_date||'')+'</span>';
            h+='<div style="font-size:.76rem;color:var(--tx2);margin-top:.15rem;white-space:pre-wrap">'+esc(e.content).substring(0,200)+'</div></div>';
            h+='<button class="btn btn-xs btn-outline btn-danger-text" onclick="delCl('+e.id+')" style="flex-shrink:0;margin-left:.5rem">Entf.</button></div>';
        });el.innerHTML=h;
    }).catch(function(){el.innerHTML='<p class="muted">Fehler beim Laden</p>';});
}
function addCl(){
    var v=document.getElementById('clVer').value.trim(),c=document.getElementById('clContent').value.trim(),t=document.getElementById('clType').value;
    if(!v||!c){toast('Version + Inhalt eingeben','warning');return;}
    fetch('../api/changelog',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',version:v,release_date:document.getElementById('clDate').value,content:c,entry_type:t})})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){toast(d.msg,'success');document.getElementById('clVer').value='';document.getElementById('clContent').value='';cMod('modAddCl');loadClList();}
        else toast(d.error||'Fehler','error');
    }).catch(function(e){toast(e.message,'error');});
}
function delCl(id){epConfirm('Eintrag löschen?',function(){fetch('../api/changelog',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id:id})}).then(function(r){return r.json();}).then(function(){loadClList();}).catch(function(){});});}

/* === Maintenance (list in sidebar, add via modal) === */
function loadMaintList(){
    fetch('../api/maintenance',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
    .then(function(r){return r.json();}).then(function(d){
        var el=document.getElementById('maintList');
        if(!d.success||!d.items||!d.items.length){el.innerHTML='<p class="muted" style="font-size:.82rem">Keine geplanten Wartungen</p>';return;}
        var h='';d.items.forEach(function(m){
            h+='<div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--bd)">';
            h+='<div><b style="font-size:.82rem">'+esc(m.title)+'</b> <span class="muted" style="font-size:.7rem">'+esc(m.start_date)+(m.end_date?' \u2013 '+esc(m.end_date):'')+'</span></div>';
            h+='<button class="btn btn-xs btn-outline btn-danger-text" onclick="delMaint('+m.id+')">Entf.</button></div>';
        });el.innerHTML=h;
    }).catch(function(){});
}
function addMaint(){
    var t=document.getElementById('maintTitle').value.trim();if(!t){toast('Titel eingeben','warning');return;}
    fetch('../api/maintenance',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',title:t,description:document.getElementById('maintDesc').value.trim(),start_date:document.getElementById('maintStart').value,end_date:document.getElementById('maintEnd').value||null})})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){toast(d.msg,'success');document.getElementById('maintTitle').value='';document.getElementById('maintDesc').value='';cMod('modAddMaint');loadMaintList();}
        else toast(d.error||'Fehler','error');
    }).catch(function(e){toast(e.message,'error');});
}
function delMaint(id){epConfirm('Wartung löschen?',function(){fetch('../api/maintenance',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id:id})}).then(function(r){return r.json();}).then(function(){loadMaintList();}).catch(function(){});});}

/* === Import (inline in sidebar section) === */
function importHolidays(){
    var j=document.getElementById('importJson').value.trim();if(!j){toast('JSON eingeben','warning');return;}
    var fd=new FormData();fd.append('action','import_holidays');fd.append('holidays_json',j);
    ajaxPost('einstellungen',fd,function(){document.getElementById('importJson').value='';});
}

/* === Login Stats === */
function loadLoginStats(){
    var fd=new FormData();fd.append('action','get_login_stats');
    fetch('einstellungen',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success)return;
        document.getElementById('lsToday').textContent=d.today||0;
        document.getElementById('lsFailed').textContent=d.failed7||0;
        document.getElementById('lsLocked').textContent=d.locked_count||0;
        var el=document.getElementById('loginLogList');
        if(!d.daily||!d.daily.length){el.innerHTML='<p class="muted" style="font-size:.78rem">Keine Eintr\u00e4ge</p>';return;}
        var h='<table class="st-atbl"><thead><tr><th>Datum</th><th>Erfolgreiche Logins</th><th>Fehlversuche</th></tr></thead><tbody>';
        d.daily.forEach(function(r){
            var dp=r.d.split('-');var ds=dp[2]+'.'+dp[1]+'.'+dp[0];
            h+='<tr><td>'+ds+'</td><td style="color:var(--ok)">'+r.ok+'</td><td style="color:'+(parseInt(r.fail)>0?'var(--err)':'var(--mu)')+'">'+r.fail+'</td></tr>';
        });h+='</tbody></table>';el.innerHTML=h;
    }).catch(function(){});
}
function clearLockouts(){epConfirm('Alle Sperren aufheben?',function(){var fd=new FormData();fd.append('action','clear_lockouts');fetch('einstellungen',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(function(r){return r.json();}).then(function(d){if(d.success){toast(d.msg,'success');loadLoginStats();}}).catch(function(){});});}

/* === Audit Log === */
var auditPage=0;
function loadAudit(p){
    auditPage=p||0;var fd=new FormData();fd.append('action','get_audit_log');fd.append('page',auditPage);fd.append('filter',document.getElementById('auditFilter').value);
    fetch('einstellungen',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(function(r){return r.json();}).then(function(d){
        var el=document.getElementById('auditLogBody');
        if(!d.success||!d.items||!d.items.length){el.innerHTML='<p class="muted" style="font-size:.78rem">Keine Eintr\u00e4ge</p>';return;}
        var h='<table class="st-atbl"><thead><tr><th>Zeit</th><th>Ereignis</th></tr></thead><tbody>';
        d.items.forEach(function(a){
            var dt=new Date(a.created_at);var ts=('0'+dt.getDate()).slice(-2)+'.'+('0'+(dt.getMonth()+1)).slice(-2)+'.'+dt.getFullYear()+' '+('0'+dt.getHours()).slice(-2)+':'+('0'+dt.getMinutes()).slice(-2);
            var cls='st-b-info';if(a.action.indexOf('fail')!==-1)cls='st-b-fail';else if(a.action.indexOf('success')!==-1)cls='st-b-ok';else if(a.action.indexOf('changed')!==-1)cls='st-b-warn';
            var label={'login_success':'Anmeldung erfolgreich','login_failed':'Anmeldung fehlgeschlagen','password_changed':'Passwort ge\u00e4ndert','suspicious':'Verd\u00e4chtige Aktivit\u00e4t'}[a.action]||a.action;
            h+='<tr><td>'+ts+'</td><td><span class="st-badge '+cls+'">'+esc(label)+'</span></td></tr>';
        });h+='</tbody></table>';
        if(d.pages>1){
            h+='<div style="display:flex;justify-content:center;gap:.4rem;padding:.5rem 0">';
            if(auditPage>0)h+='<button class="btn btn-xs btn-outline" onclick="loadAudit('+(auditPage-1)+')">&laquo;</button>';
            h+='<span class="muted" style="font-size:.72rem">'+(auditPage+1)+'/'+d.pages+'</span>';
            if(auditPage<d.pages-1)h+='<button class="btn btn-xs btn-outline" onclick="loadAudit('+(auditPage+1)+')">&raquo;</button>';
            h+='</div>';
        }
        el.innerHTML=h;
    }).catch(function(){document.getElementById('auditLogBody').innerHTML='<p class="muted">Fehler</p>';});
}
function clearAuditLog(){epConfirm('Audit-Log bereinigen (>90 Tage)?',function(){var fd=new FormData();fd.append('action','clear_audit_log');fetch('einstellungen',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(function(r){return r.json();}).then(function(d){if(d.success){toast(d.msg,'success');loadAudit(0);}}).catch(function(){});});}

document.addEventListener('DOMContentLoaded',loadMaintList);

/* === Backup & Restore === */
function createBackup(){
    var btn=document.getElementById('backupCreateBtn');if(btn)btn.disabled=true;
    toast('Backup wird erstellt und verschlüsselt...','info');
    fetch(getApiBase()+'api/backup',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'create'})})
    .then(function(r){return r.json();}).then(function(d){
        if(btn)btn.disabled=false;
        if(d.success){toast(d.msg+' ('+d.size+')'+(d.warnings&&d.warnings.length?' – mit Warnungen':''),d.status==='ok'?'success':'info');loadBackups();}
        else{toast(d.error||'Fehler','error');loadBackups();}
    }).catch(function(e){if(btn)btn.disabled=false;toast(e.message,'error');});
}
function backupBadge(st){
    if(st==='ok')return '<span class="badge" style="background:#dcfce7;color:#16a34a;font-size:.6rem">OK</span>';
    if(st==='warning')return '<span class="badge" style="background:#fef3c7;color:#92400e;font-size:.6rem">Warnung</span>';
    return '<span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:.6rem">Fehler</span>';
}
function loadBackups(){
    var el=document.getElementById('backupList'),stEl=document.getElementById('backupState'),cnt=document.getElementById('backupCounter');
    if(!el)return;
    el.innerHTML='<p class="muted" style="padding:.5rem">Laden...</p>';
    fetch(getApiBase()+'api/backup',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'status'})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success){el.innerHTML='<p class="muted txt-d">'+esc(d.error||'Fehler')+'</p>';return;}
        var warn=d.warnings&&d.warnings.length;
        var box=!d.healthy?'#fecaca;background:#fef2f2':(warn?'#fde68a;background:#fffbeb':'#bbf7d0;background:#f0fdf4');
        var h='<div style="padding:.65rem;border-radius:var(--r);font-size:.78rem;border:1px solid '+box+'">';
        h+='<b>'+(!d.healthy?'Backups prüfen':(warn?'Backups aktuell – mit Hinweisen':'Backups aktuell'))+'</b><br>';
        h+='Letztes erfolgreiches Backup: '+(d.last_success?esc(d.last_success.date)+' ('+esc(String(d.age_hours))+' h alt, '+esc(d.last_success.size_fmt||'')+')':'<b>keines</b>')+'<br>';
        if(d.last_run)h+='Letzter Lauf: '+esc(d.last_run.date)+' '+backupBadge(d.last_run.status)+'<br>';
        h+='Ziele: '+esc(d.targets.join(', '))+(d.external_target?'':' <b style="color:var(--err)">(kein externes Ziel)</b>')+'<br>';
        h+='Aufbewahrung: '+d.retention.daily+' täglich, '+d.retention.weekly+' wöchentlich, '+d.retention.monthly+' monatlich<br>';
        h+='Letzter Restore-Test: '+(d.last_restore_test?esc(d.last_restore_test.date)+' '+backupBadge(d.last_restore_test.status):'noch keiner');
        if(warn){h+='<ul style="margin:.4rem 0 0 1rem;color:'+(d.healthy?'#92400e':'var(--err)')+'">';d.warnings.forEach(function(w){h+='<li>'+esc(w)+'</li>';});h+='</ul>';}
        h+='</div>';stEl.innerHTML=h;
        if(cnt)cnt.textContent='('+d.backups.length+')';
        if(!d.backups.length){el.innerHTML='<p class="muted" style="padding:.75rem;text-align:center">Keine Backups vorhanden</p>';}
        else{
            var t='<table style="width:100%;font-size:.78rem"><thead><tr style="border-bottom:2px solid var(--bd)"><th style="text-align:left;padding:.3rem .5rem">Datei</th><th>Typ</th><th>Größe</th><th>Datum</th><th style="text-align:right;padding-right:.5rem">Aktion</th></tr></thead><tbody>';
            d.backups.forEach(function(b){
                var typ=b.kind==='scheduled'?'Auto':(b.kind==='prerestore'?'Sicherung':'Manuell');
                t+='<tr style="border-bottom:1px solid #f3f4f6"><td style="padding:.4rem .5rem;font-family:monospace;font-size:.65rem">'+esc(b.name)+'</td><td style="text-align:center">'+esc(typ)+'</td><td style="text-align:center">'+esc(b.size_fmt)+'</td><td style="text-align:center;font-size:.72rem">'+esc(b.date)+'</td>';
                t+='<td style="text-align:right;padding-right:.5rem"><a class="btn btn-xs btn-outline" href="'+getApiBase()+'api/backup?action=download&amp;name='+encodeURIComponent(b.name)+'">Download</a></td></tr>';
            });
            el.innerHTML=t+'</tbody></table>';
        }
        var lc=document.getElementById('legacyBackupCard'),ll=document.getElementById('legacyBackupList');
        if(lc&&d.legacy&&d.legacy.length){
            lc.style.display='';var l='<table style="width:100%;font-size:.75rem"><tbody>';
            d.legacy.forEach(function(b){l+='<tr><td style="padding:.3rem .5rem;font-family:monospace;font-size:.65rem">'+esc(b.name)+'</td><td>'+esc(b.size_fmt)+'</td><td>'+esc(b.date)+'</td><td style="text-align:right;padding-right:.5rem"><a class="btn btn-xs btn-outline" href="'+getApiBase()+'api/backup?action=download&amp;name='+encodeURIComponent(b.name)+'">Download</a></td></tr>';});
            ll.innerHTML=l+'</tbody></table>';
        }
    }).catch(function(){el.innerHTML='<p class="muted txt-d">Fehler</p>';});
}

/* === Announcements / Meldungen === */
function loadAnnList(){
    var el=document.getElementById('annList');if(!el)return;
    el.innerHTML='<p class="muted">Laden...</p>';
    fetch(getApiBase()+'api/announcements',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'list'})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success||!d.items||d.items.length===0){el.innerHTML='<p class="muted" style="padding:.5rem;text-align:center">Keine Meldungen</p>';return;}
        var pColors={info:'#0369a1',warning:'#92400e',critical:'#991b1b'};
        var pLabels={info:'Info',warning:'Warnung',critical:'Kritisch'};
        var pBg={info:'#e0f2fe',warning:'#fef3c7',critical:'#fef2f2'};
        var h='';
        d.items.forEach(function(a){
            var active=a.active==='1'||a.active===1;
            var dates='';
            if(a.visible_from)dates+='ab '+a.visible_from;
            if(a.visible_until)dates+=(dates?' ':'')+'bis '+a.visible_until;
            if(!dates)dates='Unbegrenzt';
            h+='<div class="st-li" style="opacity:'+(active?'1':'.5')+'">';
            h+='<div class="st-li-info">';
            h+='<b>'+esc(a.title)+' <span class="badge" style="background:'+pBg[a.priority]+';color:'+pColors[a.priority]+';font-size:.6rem">'+pLabels[a.priority]+'</span></b>';
            h+='<span style="font-size:.72rem">'+esc(a.content?a.content.substring(0,80):'')+(a.content&&a.content.length>80?'...':'')+'</span>';
            h+='<span class="muted" style="font-size:.65rem">'+dates+(a.first_name?' \u00b7 '+esc(a.first_name+' '+a.last_name):'')+'</span>';
            h+='</div>';
            h+='<div style="display:flex;gap:.3rem;align-items:center">';
            h+='<button class="btn btn-xs btn-outline" onclick="toggleAnn('+a.id+')">'+(active?'Deaktivieren':'Aktivieren')+'</button>';
            h+='<button class="btn btn-xs btn-outline btn-danger-text" onclick="deleteAnn('+a.id+')">L\u00f6schen</button>';
            h+='</div></div>';
        });
        el.innerHTML=h;
    }).catch(function(){el.innerHTML='<p class="muted">Fehler</p>';});
}
function saveAnn(){
    var t=document.getElementById('annTitle').value.trim();
    var c=document.getElementById('annContent').value.trim();
    var p=document.getElementById('annPrio').value||'info';
    var f=document.getElementById('annFrom').value||null;
    var u=document.getElementById('annUntil').value||null;
    if(!t){toast('Titel eingeben','warning');return;}
    fetch(getApiBase()+'api/announcements',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'create',title:t,content:c,priority:p,visible_from:f,visible_until:u})})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){toast(d.msg,'success');cMod('modAddAnn');document.getElementById('annTitle').value='';document.getElementById('annContent').value='';loadAnnList();}
        else toast(d.error||'Fehler','error');
    }).catch(function(e){toast(e.message,'error');});
}
function toggleAnn(id){
    fetch(getApiBase()+'api/announcements',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'toggle',id:id})})
    .then(function(r){return r.json();}).then(function(d){if(d.success){toast(d.msg,'success');loadAnnList();}else toast(d.error||'Fehler','error');}).catch(function(e){toast(e.message,'error');});
}
function deleteAnn(id){
    epConfirm('Meldung endg\u00fcltig l\u00f6schen?',function(){
        fetch(getApiBase()+'api/announcements',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'delete',id:id})})
        .then(function(r){return r.json();}).then(function(d){if(d.success){toast(d.msg,'success');loadAnnList();}}).catch(function(e){toast(e.message,'error');});
    });
}
</script>
<?php require_once __DIR__.'/../includes/footer.php';?>
