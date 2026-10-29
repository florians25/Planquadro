<?php
require_once __DIR__ . '/../app/lib/Security.php';
require_once __DIR__ . '/../app/config/database.php';
opsStartSession();
require_once __DIR__ . '/../app/lib/TenantConfig.php';
require_once __DIR__ . '/../app/lib/Oidc.php';
$oidcOn = oidcEnabled();
$pwAllowed = oidcPasswordLoginAllowed();
$brandName = (string)tcfg('branding.name', 'OpsPilot');
if (isset($_SESSION['user_id']) && empty($_SESSION['must_change_password'])) { header('Location: dashboard'); exit; }

$err = ''; $showChange = !empty($_SESSION['must_change_password']); $pwExpired = !empty($_SESSION['pw_expired']);
$showCaptcha = false; $attemptsLeft = null; $lockoutMsg = '';

/* --- Security helpers --- */
function getSecSetting($key, $default) {
    try { $s = getDB()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?'); $s->execute([$key]); $r = $s->fetch(); return $r ? $r['setting_value'] : $default; }
    catch(Exception $e) { return $default; }
}
function getClientIP() { return opsClientIp(); }
function cleanOldAttempts() {
    try { getDB()->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"); } catch(Exception $e) {}
}
function countRecentAttempts($username, $minutes = 15) {
    try {
        $s = getDB()->prepare('SELECT COUNT(*) c FROM login_attempts WHERE (username=? OR ip_address=?) AND success=0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
        $s->execute([$username, getClientIP(), $minutes]);
        return (int)$s->fetch()['c'];
    } catch(Exception $e) { return 0; }
}
function logAttempt($username, $success) {
    try { getDB()->prepare('INSERT INTO login_attempts(username,ip_address,success) VALUES(?,?,?)')->execute([$username, getClientIP(), $success ? 1 : 0]); } catch(Exception $e) {}
}
function logAudit($userId, $action, $details = '') {
    try { getDB()->prepare('INSERT INTO audit_log(user_id,action,details,ip_address) VALUES(?,?,?,?)')->execute([$userId, $action, $details, getClientIP()]); } catch(Exception $e) {}
}
function generateCaptcha() {
    $ops = ['+', '-', '×'];
    $op = $ops[array_rand($ops)];
    if ($op === '+') { $a = rand(1,20); $b = rand(1,20); $answer = $a + $b; }
    elseif ($op === '-') { $a = rand(5,25); $b = rand(1,$a); $answer = $a - $b; }
    else { $a = rand(2,9); $b = rand(2,9); $answer = $a * $b; }
    $_SESSION['captcha_answer'] = $answer;
    $_SESSION['captcha_time'] = time();
    return "$a $op $b = ?";
}

/* --- Settings --- */
$captchaEnabled = '1'; $maxAttempts = 5; $lockoutMin = 15;
try {
    $captchaEnabled = getSecSetting('captcha_enabled', '1');
    $maxAttempts = (int)getSecSetting('max_login_attempts', '5');
    $lockoutMin = (int)getSecSetting('lockout_duration_minutes', '15');
} catch(Exception $e) {}

cleanOldAttempts();

/* Beide Formulare (Login, Passwortwechsel) sind CSRF-geschützt. Bei
   ungültigem Token wird nichts verarbeitet und das Formular neu angezeigt. */
$csrfOk = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)($_POST['_token'] ?? ''));
    if (!$csrfOk) { $err = 'Die Sitzung ist abgelaufen. Bitte erneut versuchen.'; }
}

/* Handle password change */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfOk && isset($_POST['change_pw'])) {
    $np = $_POST['new_password'] ?? ''; $np2 = $_POST['new_password2'] ?? '';
    $minLen = (int)getSecSetting('min_password_length', '6');
    $reqUpper = getSecSetting('password_require_uppercase', '0') === '1';
    $reqNum = getSecSetting('password_require_number', '0') === '1';
    $reqSpec = getSecSetting('password_require_special', '0') === '1';

    if (strlen($np) < $minLen) { $err = "Passwort muss mindestens $minLen Zeichen haben."; $showChange = true; }
    elseif ($np !== $np2) { $err = 'Passwörter stimmen nicht überein.'; $showChange = true; }
    elseif ($reqUpper && !preg_match('/[A-Z]/', $np)) { $err = 'Passwort muss mindestens einen Großbuchstaben enthalten.'; $showChange = true; }
    elseif ($reqNum && !preg_match('/[0-9]/', $np)) { $err = 'Passwort muss mindestens eine Zahl enthalten.'; $showChange = true; }
    elseif ($reqSpec && !preg_match('/[^a-zA-Z0-9]/', $np)) { $err = 'Passwort muss mindestens ein Sonderzeichen enthalten.'; $showChange = true; }
    else {
        $db = getDB();
        $db->prepare('UPDATE users SET password_hash=?, must_change_password=0, initial_password=NULL, password_changed_at=NOW() WHERE id=?')
           ->execute([password_hash($np, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        unset($_SESSION['must_change_password'], $_SESSION['pw_expired'], $_SESSION['pw_expiry_checked']);
        logAudit($_SESSION['user_id'], 'password_changed', 'Password changed via login');
        header('Location: dashboard'); exit;
    }
}

/* Handle login */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfOk && !isset($_POST['change_pw'])) {
    $u = trim($_POST['username'] ?? ''); $p = $_POST['password'] ?? '';

    /* Check lockout */
    $recentFails = countRecentAttempts($u, $lockoutMin);
    if ($maxAttempts > 0 && $recentFails >= $maxAttempts) {
        $err = "Zu viele Fehlversuche. Bitte warten Sie $lockoutMin Minuten.";
        $lockoutMsg = $err;
    }
    /* Verify CAPTCHA if enabled and user has 2+ recent fails */
    elseif ($captchaEnabled === '1' && $recentFails >= 2) {
        $captchaInput = trim($_POST['captcha_answer'] ?? '');
        $correctAnswer = $_SESSION['captcha_answer'] ?? null;
        $captchaTime = $_SESSION['captcha_time'] ?? 0;
        if (!$captchaInput || $correctAnswer === null || (int)$captchaInput !== (int)$correctAnswer || (time() - $captchaTime) > 300) {
            $err = 'Sicherheitsabfrage falsch. Bitte versuchen Sie es erneut.';
            logAttempt($u, false);
        } else {
            unset($_SESSION['captcha_answer'], $_SESSION['captcha_time']);
            goto do_login;
        }
    } else {
        do_login:
        if (!$err && $u && $p) {
            try {
                $s = getDB()->prepare('SELECT * FROM users WHERE username=? AND active=1');
                $s->execute([$u]); $row = $s->fetch();
                if ($row && password_verify($p, $row['password_hash']) && !$pwAllowed && $row['role'] !== 'admin') {
                    /* Nur SSO erlaubt – Administratoren behalten den Passwort-Zugang als Notfallweg */
                    $err = 'Bitte melden Sie sich über Ihr Firmenkonto an.';
                } elseif ($row && password_verify($p, $row['password_hash'])) {
                    logAttempt($u, true);
                    logAudit($row['id'], 'login_success', 'Login from ' . getClientIP());
                    opsRegenerateSession();
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();
                    if ($row['must_change_password'] ?? 0) {
                        $_SESSION['must_change_password'] = true;
                        $showChange = true;
                    } else { header('Location: dashboard'); exit; }
                } else {
                    logAttempt($u, false);
                    $remaining = $maxAttempts > 0 ? max(0, $maxAttempts - countRecentAttempts($u, $lockoutMin)) : null;
                    $err = 'Benutzername oder Passwort falsch.';
                    if ($remaining !== null && $remaining <= 3 && $remaining > 0) $err .= " Noch $remaining Versuch(e).";
                    elseif ($remaining === 0) $err = "Konto gesperrt für $lockoutMin Minuten.";
                    logAudit(null, 'login_failed', "Failed login for '$u' from " . getClientIP());
                }
            } catch (Exception $e) { $err = 'Datenbank nicht erreichbar.'; }
        } elseif (!$err) { $err = 'Bitte alle Felder ausfüllen.'; }
    }
}

/* Determine if CAPTCHA should be shown – only after 2+ recent fails */
$needCaptcha = false;
if ($captchaEnabled === '1' && !$showChange) {
    $uCheck = trim($_POST['username'] ?? '');
    if ($uCheck) $needCaptcha = countRecentAttempts($uCheck, $lockoutMin) >= 2;
}
$captchaQuestion = $needCaptcha ? generateCaptcha() : '';

/* Password requirements for display */
$minLen = (int)getSecSetting('min_password_length', '6');
$reqUpper = getSecSetting('password_require_uppercase', '0') === '1';
$reqNum = getSecSetting('password_require_number', '0') === '1';
$reqSpec = getSecSetting('password_require_special', '0') === '1';
$pwReqs = [];
$pwReqs[] = "Mind. $minLen Zeichen";
if ($reqUpper) $pwReqs[] = 'Ein Großbuchstabe';
if ($reqNum) $pwReqs[] = 'Eine Zahl';
if ($reqSpec) $pwReqs[] = 'Ein Sonderzeichen';
?><!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($brandName); ?> – Login</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,Roboto,sans-serif;font-size:14px;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f172a;color:#111827;overflow:hidden}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 30% 20%,rgba(226,0,26,.08) 0%,transparent 50%),radial-gradient(ellipse at 70% 80%,rgba(59,130,246,.06) 0%,transparent 50%);pointer-events:none}
.lw{width:100%;max-width:420px;padding:1rem;position:relative;z-index:1}
.lb{background:#fff;border-radius:16px;padding:2.5rem 2rem 2rem;box-shadow:0 25px 80px rgba(0,0,0,.4),0 8px 32px rgba(0,0,0,.2),0 0 0 1px rgba(255,255,255,.05);position:relative;overflow:hidden}
.lh{text-align:center;margin-bottom:1.75rem}
.logo{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:13px;background:linear-gradient(135deg,#e2001a,#ff1a33);color:#fff;font-size:1.15rem;font-weight:900;margin-bottom:.75rem;box-shadow:0 6px 20px rgba(226,0,26,.35)}
.lh h1{font-size:1.35rem;font-weight:800;letter-spacing:-.02em;color:#0f172a}
.lh .sub{font-size:.82rem;color:#6b7280;margin-top:.25rem}
.fg{margin-bottom:.85rem}
.fg label{display:block;font-size:.7rem;font-weight:700;color:#6b7280;margin-bottom:.25rem;text-transform:uppercase;letter-spacing:.04em}
.fg input,.fg select{width:100%;padding:.6rem .75rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.9rem;background:#fff;color:#111827}
.fg input:focus{outline:none;border-color:#e2001a;box-shadow:0 0 0 3px rgba(226,0,26,.08)}
.btn{display:block;width:100%;padding:.65rem;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;text-align:center;margin-top:.5rem}
.btn-p{background:linear-gradient(135deg,#e2001a,#cc0017);color:#fff;box-shadow:0 2px 8px rgba(226,0,26,.25)}
.btn-p:hover{background:linear-gradient(135deg,#cc0017,#b30014);box-shadow:0 4px 16px rgba(226,0,26,.35)}
.btn-p:disabled{opacity:.5;cursor:not-allowed}
.alert{padding:.55rem .75rem;border-radius:8px;font-size:.82rem;font-weight:600;margin-bottom:.85rem}
.alert-e{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.alert-w{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.alert-i{background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd}
.alert-s{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.lf{text-align:center;margin-top:1.25rem;font-size:.68rem;color:rgba(255,255,255,.25);letter-spacing:.02em}
.deco{position:fixed;border-radius:50%;pointer-events:none}
.d1{width:500px;height:500px;border:1.5px solid rgba(255,255,255,.04);top:-120px;right:-120px}
.d2{width:350px;height:350px;border:1.5px solid rgba(226,0,26,.06);bottom:-100px;left:-100px}
.d3{width:200px;height:200px;background:radial-gradient(circle,rgba(226,0,26,.04) 0%,transparent 70%);top:30%;left:10%}
.d4{width:250px;height:250px;background:radial-gradient(circle,rgba(59,130,246,.03) 0%,transparent 70%);bottom:20%;right:15%}
.d5{width:600px;height:600px;border:1px solid rgba(255,255,255,.02);top:50%;left:50%;transform:translate(-50%,-50%)}
.d6{width:80px;height:80px;background:rgba(226,0,26,.03);border-radius:16px;transform:rotate(45deg);top:15%;right:25%}
.d7{width:60px;height:60px;background:rgba(59,130,246,.03);border-radius:12px;transform:rotate(30deg);bottom:25%;left:20%}
/* CAPTCHA */
.captcha-box{background:#f8fafc;border:1.5px solid #e5e7eb;border-radius:8px;padding:.65rem .75rem;margin-bottom:.85rem;display:flex;align-items:center;gap:.6rem}
.captcha-q{font-size:1.05rem;font-weight:800;color:#0f172a;letter-spacing:.04em;min-width:110px;text-align:center;background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:.35rem .5rem}
.captcha-input{flex:1}
.captcha-input input{width:100%;padding:.5rem .6rem;border:1.5px solid #e5e7eb;border-radius:6px;font-size:.9rem;text-align:center;font-weight:700}
.captcha-input input:focus{outline:none;border-color:#e2001a;box-shadow:0 0 0 3px rgba(226,0,26,.08)}
.captcha-label{font-size:.68rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.25rem;display:flex;align-items:center;gap:.3rem}
.captcha-label svg{width:14px;height:14px}
.captcha-refresh{background:none;border:none;cursor:pointer;color:#6b7280;padding:2px;border-radius:4px;display:flex;align-items:center}
.captcha-refresh:hover{color:#e2001a;background:rgba(226,0,26,.05)}
.pw-reqs{font-size:.72rem;color:#6b7280;margin-top:.25rem;padding:.35rem .55rem;background:#f8fafc;border-radius:6px;border:1px solid #f0f0f0}
.pw-reqs span{display:inline-block;margin-right:.5rem}
.sec-info{font-size:.68rem;color:#9ca3af;text-align:center;margin-top:.65rem;display:flex;align-items:center;justify-content:center;gap:.3rem}
.sec-info svg{width:12px;height:12px}
</style>
<style><?php echo opsBrandCss(); ?>.btn-p{background:var(--p)!important;color:var(--p-on)!important}.logo{background:var(--p)!important}</style>
</head>
<body>
<div class="deco d1"></div><div class="deco d2"></div><div class="deco d3"></div><div class="deco d4"></div><div class="deco d5"></div><div class="deco d6"></div><div class="deco d7"></div>
<div class="lw">
  <div class="lb">
    <div class="lh">
      <div class="logo"><svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" width="28" height="28"><rect x="3" y="6" width="26" height="23" rx="3" stroke="#fff" stroke-width="2"/><path d="M3 12h26" stroke="#fff" stroke-width="2"/><path d="M9 3v5M23 3v5" stroke="#fff" stroke-width="2" stroke-linecap="round"/><rect x="7" y="15.5" width="4" height="3" rx=".8" fill="#fff" opacity=".9"/><rect x="14" y="15.5" width="4" height="3" rx=".8" fill="#fff" opacity=".5"/><rect x="21" y="15.5" width="4" height="3" rx=".8" fill="#fff" opacity=".5"/><rect x="7" y="21.5" width="4" height="3" rx=".8" fill="#fff" opacity=".5"/><rect x="14" y="21.5" width="4" height="3" rx=".8" fill="#fff" opacity=".9"/><rect x="21" y="21.5" width="4" height="3" rx=".8" fill="#fff" opacity=".3"/></svg></div>
      <h1><?php echo htmlspecialchars($brandName); ?></h1>
      <p class="sub"><?php echo $showChange ? ($pwExpired ? 'Passwort abgelaufen' : 'Neues Passwort vergeben') : 'Willkommen zurück'; ?></p>
    </div>
    <?php if(!empty($_GET['logged_out'])): ?><div class="alert alert-s" id="logoutMsg"><svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;vertical-align:middle;margin-right:4px"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>Sie wurden erfolgreich abgemeldet.</div><?php endif; ?>
    <?php if(!empty($_SESSION['session_expired']) && !$err): unset($_SESSION['session_expired']); ?><div class="alert alert-w">Ihre Sitzung ist wegen Inaktivität abgelaufen. Bitte erneut anmelden.</div><?php endif; ?>
    <?php if(!empty($_SESSION['oidc_error'])): ?><div class="alert alert-e"><?php echo htmlspecialchars($_SESSION['oidc_error']); unset($_SESSION['oidc_error']); ?></div><?php endif; ?>
    <?php if($err): ?><div class="alert alert-e"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
    <?php if($showChange): ?>
    <form method="POST" autocomplete="off">
      <input type="hidden" name="change_pw" value="1">
      <input type="hidden" name="_token" value="<?php echo opsCsrfToken(); ?>">
      <?php if($pwExpired): ?><div class="alert alert-w">Ihr Passwort ist abgelaufen. Bitte ein neues vergeben.</div>
      <?php else: ?><div class="alert alert-w">Bitte vergeben Sie jetzt Ihr persönliches Passwort.</div><?php endif; ?>
      <div class="fg"><label>Neues Passwort</label><input type="password" name="new_password" required autofocus minlength="<?php echo $minLen;?>" placeholder="Neues Passwort" id="npw">
      <div class="pw-reqs"><?php foreach($pwReqs as $r): ?><span>• <?php echo htmlspecialchars($r);?></span><?php endforeach;?></div></div>
      <div class="fg"><label>Passwort wiederholen</label><input type="password" name="new_password2" required minlength="<?php echo $minLen;?>" placeholder="Nochmal eingeben"></div>
      <button type="submit" class="btn btn-p">Passwort setzen</button>
    </form>
    <?php else: ?>
    <?php if($oidcOn): ?>
    <a class="btn btn-p" href="oidc?start=1" style="text-decoration:none;margin-bottom:.85rem"><?php echo htmlspecialchars(oidcConfig()['label'] ?? 'Mit Firmenkonto anmelden'); ?></a>
    <?php if($pwAllowed): ?><div class="sec-info" style="margin:0 0 .85rem">oder mit Benutzername und Passwort</div><?php endif; ?>
    <?php endif; ?>
    <?php if(!$pwAllowed && empty($_GET['password'])): ?>
    <div class="sec-info"><a href="?password=1" style="color:#6b7280">Notfall-Anmeldung für Administratoren</a></div>
    <?php else: ?>
    <form method="POST" autocomplete="off" id="loginForm">
      <input type="hidden" name="_token" value="<?php echo opsCsrfToken(); ?>">
      <div class="fg"><label>Benutzername</label><input type="text" name="username" required autofocus value="<?php echo htmlspecialchars($_POST['username']??'');?>" placeholder="Benutzername" id="loginUser"></div>
      <div class="fg"><label>Passwort</label><input type="password" name="password" required placeholder="Passwort" id="loginPw"></div>
      <?php if($needCaptcha): ?>
      <div class="captcha-label">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
        Sicherheitsabfrage
        <button type="button" class="captcha-refresh" onclick="location.reload()" title="Neue Aufgabe">
          <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg>
        </button>
      </div>
      <div class="captcha-box">
        <div class="captcha-q"><?php echo htmlspecialchars($captchaQuestion);?></div>
        <div class="captcha-input"><input type="text" name="captcha_answer" placeholder="Ergebnis" required autocomplete="off" inputmode="numeric"></div>
      </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-p" id="loginBtn">Anmelden</button>
      <div class="sec-info">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
        Verschlüsselte Verbindung
      </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="lf"><?php echo htmlspecialchars($brandName); ?> · OpsPilot</div>
</div>
<script>
/* Prevent double-submit */
document.getElementById('loginForm')&&document.getElementById('loginForm').addEventListener('submit',function(){
    var b=document.getElementById('loginBtn');if(b){b.disabled=true;b.textContent='Wird geprüft...';}
});
var lm=document.getElementById('logoutMsg');if(lm)setTimeout(function(){lm.style.display='none';},4000);
</script>
</body></html>
