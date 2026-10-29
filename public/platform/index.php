<?php
/**
 * Vireo – Plattform-Administration (nur SaaS)
 * --------------------------------------------------------------------------
 * Erreichbar unter /platform/ auf dem Plattform-Host (VIREO_PLATFORM_HOST).
 * Eigene Anmeldung (platform_admins, anlegen per `php bin/tenant.php
 * platform-admin <name>`), eigene Session, keinerlei Zugriff auf
 * Mandanten-Sessions. Mandanten-Fachdaten werden nur gezählt, nie angezeigt.
 */
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/lib/Security.php';

$host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
/* Nur im SaaS-Modus und nur auf dem ausdrücklich konfigurierten Plattform-Host */
if (opsMode() !== 'saas' || opsEnv('VIREO_PLATFORM_HOST') === '' || $host !== strtolower(opsEnv('VIREO_PLATFORM_HOST'))) {
    http_response_code(404); exit('Not Found');
}

session_name('VIREOPLATFORM');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('VIREO_FORCE_SECURE_COOKIES') === '1';
session_set_cookie_params(['lifetime' => 0, 'path' => '/platform/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
ini_set('session.use_strict_mode', '1');
session_start();
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'");

$pdb = opsPlatformDB();
$ipHash = substr(hash('sha256', opsClientIp() . '|' . opsAppKeyPlatform()), 0, 16);
function opsAppKeyPlatform(): string { return opsEnv('VIREO_APP_KEY', 'vireo-platform'); }
function pfAudit(string $action, string $details = ''): void {
    global $pdb, $ipHash;
    $pdb->prepare('INSERT INTO platform_audit(admin, action, details, ip_hash) VALUES(?,?,?,?)')->execute([$_SESSION['pf_admin'] ?? null, $action, mb_substr($details, 0, 500), $ipHash]);
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = ''; $err = '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals(opsCsrfToken(), (string)($_POST['_token'] ?? ''))) { $err = 'Sitzung abgelaufen – bitte erneut versuchen.'; $post = false; }

/* ---------------- Anmeldung ---------------- */
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
if ($post && ($_POST['action'] ?? '') === 'login') {
    $f = $pdb->prepare("SELECT COUNT(*) FROM platform_audit WHERE action='login_failed' AND ip_hash=? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $f->execute([$ipHash]);
    if ((int)$f->fetchColumn() >= 5) { $err = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.'; }
    else {
        $s = $pdb->prepare('SELECT * FROM platform_admins WHERE username=? AND active=1');
        $s->execute([trim((string)($_POST['username'] ?? ''))]);
        $a = $s->fetch();
        if (password_verify((string)($_POST['password'] ?? ''), $a ? (string)$a['password_hash'] : opsDummyHash()) && $a) {
            session_regenerate_id(true);
            $_SESSION = ['pf_admin' => $a['username'], 'csrf_token' => bin2hex(random_bytes(32)), 'pf_at' => time()];
            $pdb->prepare('UPDATE platform_admins SET last_login=NOW() WHERE id=?')->execute([$a['id']]);
            pfAudit('login_success');
            header('Location: ./'); exit;
        }
        usleep(400000);
        pfAudit('login_failed', mb_substr((string)($_POST['username'] ?? ''), 0, 60));
        $err = 'Benutzername oder Passwort falsch.';
    }
}
$admin = $_SESSION['pf_admin'] ?? null;
if ($admin && time() - (int)($_SESSION['pf_at'] ?? 0) > 3600) { $_SESSION = []; $admin = null; $err = 'Sitzung abgelaufen.'; }
if ($admin) $_SESSION['pf_at'] = time();

/* ---------------- Aktionen ---------------- */
if ($admin && $post) {
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'create') {
            $slug = strtolower(trim((string)$_POST['slug'])); $name = trim((string)$_POST['name']); $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
            if ($name === '') throw new InvalidArgumentException('Name fehlt.');
            if ($domain !== '' && !preg_match('/^[a-z0-9.-]+$/', $domain)) throw new InvalidArgumentException('Domain ungültig.');
            $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../../bin/tenant.php', 'create', $slug, $name], $domain !== '' ? ['--domain=' . $domain] : []),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            if (proc_close($p) !== 0) throw new RuntimeException(trim(strrchr(trim($out), "\n") ?: $out));
            preg_match('/Administrator: (\S+) · Einmalpasswort: (\S+)/', $out, $m);
            pfAudit('tenant_create', "$slug ($name)");
            $msg = "Mandant „{$name}“ angelegt." . ($m ? " Erster Administrator: {$m[1]} · Einmalpasswort: {$m[2]} (nur jetzt sichtbar)" : '');
        } elseif ($act === 'status') {
            $st = in_array($_POST['status'] ?? '', ['active', 'suspended'], true) ? $_POST['status'] : null;
            if (!$st) throw new InvalidArgumentException('Status ungültig.');
            $pdb->prepare('UPDATE tenants SET status=? WHERE id=?')->execute([$st, (int)$_POST['id']]);
            pfAudit('tenant_' . $st, (string)(int)$_POST['id']);
            $msg = 'Status geändert.';
        } elseif ($act === 'domain') {
            $d = strtolower(trim((string)$_POST['domain']));
            if (!preg_match('/^[a-z0-9.-]+$/', $d)) throw new InvalidArgumentException('Domain ungültig.');
            $pdb->prepare('INSERT INTO tenant_domains(host, tenant_id) VALUES(?,?)')->execute([$d, (int)$_POST['id']]);
            pfAudit('domain_add', $d);
            $msg = 'Domain hinzugefügt.';
        }
    } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    catch (Throwable $e) { error_log('platform: ' . $e->getMessage()); $err = 'Aktion fehlgeschlagen: ' . mb_substr($e->getMessage(), 0, 200); }
}

$tenants = [];
if ($admin) {
    $tenants = $pdb->query("SELECT t.*, GROUP_CONCAT(d.host ORDER BY d.host SEPARATOR ', ') hosts FROM tenants t LEFT JOIN tenant_domains d ON d.tenant_id=t.id WHERE t.status<>'deleted' GROUP BY t.id ORDER BY t.name")->fetchAll();
    $lastBackup = [];
    try {
        foreach ($pdb->query("SELECT scope, status, finished_at, started_at FROM backup_runs b WHERE id IN (SELECT MAX(id) FROM backup_runs GROUP BY scope)")->fetchAll() as $b) $lastBackup[$b['scope']] = $b;
    } catch (Throwable $e) {}
    foreach ($tenants as &$t) {
        $t['users'] = null; $t['schema'] = null;
        try {
            $db = preg_replace('/[^A-Za-z0-9_]/', '', $t['db_name']);
            $t['users'] = (int)$pdb->query("SELECT COUNT(*) FROM `$db`.users WHERE active=1")->fetchColumn();
            $t['schema'] = (string)$pdb->query("SELECT MAX(version) FROM `$db`.schema_migrations WHERE kind='opspilot'")->fetchColumn();
        } catch (Throwable $e) {}
        $t['backup'] = $lastBackup[$t['slug']] ?? null;
    }
    unset($t);
    $audit = $pdb->query('SELECT * FROM platform_audit ORDER BY id DESC LIMIT 15')->fetchAll();
}
$token = opsCsrfToken();
?><!DOCTYPE html>
<html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vireo – Plattform</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
body{background:var(--bg)}.pf{max-width:1200px;margin:0 auto;padding:1.25rem}
.pf-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}
.pf-head h1{font-size:1.3rem}.pf-grid{display:grid;grid-template-columns:2fr 1fr;gap:1rem}
@media(max-width:900px){.pf-grid{grid-template-columns:1fr}}
.pf form.inline{display:inline}.pf input{padding:.4rem .55rem;border:1px solid var(--bd2);border-radius:var(--r);background:var(--card);color:var(--tx)}
.pf .st-active{color:var(--ok);font-weight:700}.pf .st-suspended{color:var(--err);font-weight:700}
.pf-login{max-width:360px;margin:12vh auto}.pf-login .fg{margin-bottom:.75rem}.pf-login input{width:100%}
</style></head><body>
<main class="pf">
<?php if (!$admin): ?>
  <div class="card pf-login"><div class="cb">
    <h1 style="font-size:1.2rem;margin-bottom:1rem">Vireo Plattform</h1>
    <?php if ($err): ?><div class="alert alert-err" role="alert"><?php echo h($err); ?></div><?php endif; ?>
    <form method="post"><input type="hidden" name="_token" value="<?php echo h($token); ?>"><input type="hidden" name="action" value="login">
      <div class="fg"><label for="u">Benutzername</label><input id="u" name="username" autocomplete="username" required autofocus></div>
      <div class="fg"><label for="p">Passwort</label><input id="p" name="password" type="password" autocomplete="current-password" required></div>
      <button class="btn btn-primary" style="width:100%">Anmelden</button></form>
  </div></div>
<?php else: ?>
  <div class="pf-head"><h1>Plattform-Administration</h1><div><span class="muted"><?php echo h($admin); ?></span> · <a href="?logout=1">Abmelden</a></div></div>
  <?php if ($msg): ?><div class="alert alert-ok" role="status"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-err" role="alert"><?php echo h($err); ?></div><?php endif; ?>
  <div class="pf-grid">
  <section class="card"><div class="ch">Mandanten (<?php echo count($tenants); ?>)</div><div class="cb p0">
    <table class="tbl"><thead><tr><th>Mandant</th><th>Domains</th><th>Aktive Benutzer</th><th>Schema</th><th>Letztes Backup</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($tenants as $t): $b = $t['backup']; $stale = !$b || $b['status'] !== 'ok' || strtotime((string)$b['finished_at']) < time() - 26 * 3600; ?>
    <tr><td><b><?php echo h($t['name']); ?></b><br><span class="muted" style="font-size:.75rem"><?php echo h($t['slug']); ?> · <?php echo h($t['db_name']); ?></span></td>
      <td style="font-size:.8rem"><?php echo h($t['hosts'] ?: '–'); ?>
        <form method="post" class="inline"><input type="hidden" name="_token" value="<?php echo h($token); ?>"><input type="hidden" name="action" value="domain"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>"><input name="domain" placeholder="weitere Domain" aria-label="Domain hinzufügen" size="14"></form></td>
      <td><?php echo $t['users'] ?? '?'; ?></td>
      <td style="font-size:.75rem"><?php echo h(str_replace('op_', '', (string)$t['schema']) ?: '–'); ?></td>
      <td style="font-size:.78rem;<?php if ($stale) echo 'color:var(--err);font-weight:600'; ?>"><?php echo $b ? h($b['status'] . ' · ' . date('d.m. H:i', strtotime((string)($b['finished_at'] ?: $b['started_at'])))) : 'keins'; ?></td>
      <td class="st-<?php echo h($t['status']); ?>"><?php echo $t['status'] === 'active' ? 'aktiv' : 'gesperrt'; ?></td>
      <td><form method="post" class="inline"><input type="hidden" name="_token" value="<?php echo h($token); ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
        <input type="hidden" name="status" value="<?php echo $t['status'] === 'active' ? 'suspended' : 'active'; ?>">
        <button class="btn btn-xs btn-outline"><?php echo $t['status'] === 'active' ? 'Sperren' : 'Aktivieren'; ?></button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></section>
  <div>
    <section class="card" style="margin-bottom:1rem"><div class="ch">Neuer Mandant</div><div class="cb">
      <form method="post"><input type="hidden" name="_token" value="<?php echo h($token); ?>"><input type="hidden" name="action" value="create">
        <div class="fg"><label for="ns">Kürzel (Slug)</label><input id="ns" name="slug" pattern="[a-z0-9][a-z0-9-]{0,38}[a-z0-9]" required placeholder="acme"></div>
        <div class="fg"><label for="nn">Name</label><input id="nn" name="name" required placeholder="Acme GmbH"></div>
        <div class="fg"><label for="nd">Domain</label><input id="nd" name="domain" placeholder="acme.vireo.example"></div>
        <button class="btn btn-primary">Anlegen</button></form>
      <p class="muted" style="font-size:.75rem;margin-top:.5rem">Legt Datenbank und Schema an und erzeugt einen ersten Administrator mit Einmalpasswort.</p>
    </div></section>
    <section class="card"><div class="ch">Protokoll</div><div class="cb" style="font-size:.75rem">
      <?php foreach ($audit as $a): ?><div><?php echo h(date('d.m. H:i', strtotime($a['created_at'])) . ' · ' . ($a['admin'] ?? '–') . ' · ' . $a['action'] . ($a['details'] ? ' · ' . $a['details'] : '')); ?></div><?php endforeach; ?>
    </div></section>
  </div></div>
<?php endif; ?>
</main></body></html>
