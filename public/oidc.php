<?php
/**
 * OIDC-Login: /oidc?start=1 leitet zum Identity-Provider, /oidc?code=…&state=…
 * ist die Rückkehr-URL (Redirect-URI).
 */
require_once __DIR__ . '/../app/lib/Security.php';
require_once __DIR__ . '/../app/config/database.php';
opsStartSession();
require_once __DIR__ . '/../app/lib/TenantConfig.php';
require_once __DIR__ . '/../app/lib/Oidc.php';

function oidcFail(string $msg): void
{
    $_SESSION['oidc_error'] = $msg;
    header('Location: ./');
    exit;
}
function oidcAudit($uid, $action, $details) {
    try { getDB()->prepare('INSERT INTO audit_log(user_id,action,details,ip_address) VALUES(?,?,?,?)')->execute([$uid, $action, mb_substr($details, 0, 500), opsClientIp()]); } catch (Throwable $e) {}
}

if (!oidcEnabled()) { http_response_code(404); exit('Not Found'); }

try {
    if (isset($_GET['start'])) {
        [$verifier, $challenge] = oidcPkce();
        $state = bin2hex(random_bytes(16)); $nonce = bin2hex(random_bytes(16));
        $_SESSION['oidc'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'at' => time()];
        header('Location: ' . oidcAuthorizeUrl(oidcRedirectUri(), $challenge, $state, $nonce));
        exit;
    }
    if (isset($_GET['error'])) oidcFail('Anmeldung abgebrochen: ' . mb_substr((string)($_GET['error_description'] ?? $_GET['error']), 0, 200));
    $pending = $_SESSION['oidc'] ?? null;
    unset($_SESSION['oidc']);
    if (!$pending || empty($_GET['state']) || !hash_equals($pending['state'], (string)$_GET['state']) || time() - $pending['at'] > 600) {
        oidcFail('Die Anmeldung ist abgelaufen oder ungültig. Bitte erneut versuchen.');
    }
    $claims = oidcExchange((string)($_GET['code'] ?? ''), oidcRedirectUri(), $pending['verifier'], $pending['nonce']);
    $user = oidcFindUser($claims);
    if (!$user) {
        oidcAudit(null, 'oidc_unknown_user', 'Kein aktives Konto für ' . substr(hash('sha256', (string)($claims[oidcConfig()['claim']] ?? '')), 0, 12));
        oidcFail('Für Ihr Firmenkonto gibt es keinen aktiven OpsPilot-Zugang. Bitte wenden Sie sich an die Administration.');
    }
    opsRegenerateSession();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['login_time'] = $_SESSION['last_activity'] = time();
    $_SESSION['auth_method'] = 'oidc';
    /* Passwortablauf gilt nicht für SSO-Anmeldungen */
    $_SESSION['pw_expiry_checked'] = 1;
    oidcAudit((int)$user['id'], 'login_success', 'OIDC-Login');
    header('Location: dashboard');
    exit;
} catch (OidcException $e) {
    error_log('OIDC: ' . $e->getMessage());
    oidcFail('Anmeldung über den Identity-Provider fehlgeschlagen: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('OIDC: ' . $e->getMessage());
    oidcFail('Anmeldung über den Identity-Provider fehlgeschlagen.');
}
