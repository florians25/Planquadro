<?php
/**
 * Minimaler OpenID-Connect-Provider NUR für Tests (php -S … fake_idp.php).
 * /authorize akzeptiert login_hint=<benutzername> statt eines Login-Formulars;
 * tamper=1 erzeugt ein ID-Token mit ungültiger Signatur.
 */
$dir = sys_get_temp_dir() . '/opspilot-fake-idp';
if (!is_dir($dir)) mkdir($dir, 0700, true);
$keyFile = $dir . '/key.pem';
if (!is_file($keyFile)) { $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($k, $pem); file_put_contents($keyFile, $pem); }
$key = openssl_pkey_get_private(file_get_contents($keyFile));
$iss = 'http://' . $_SERVER['HTTP_HOST'];
$b64u = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/.well-known/openid-configuration') {
    echo json_encode(['issuer' => $iss, 'authorization_endpoint' => "$iss/authorize", 'token_endpoint' => "$iss/token", 'jwks_uri' => "$iss/jwks"]);
} elseif ($path === '/jwks') {
    $d = openssl_pkey_get_details($key)['rsa'];
    echo json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64u($d['n']), 'e' => $b64u($d['e'])]]]);
} elseif ($path === '/authorize') {
    $code = bin2hex(random_bytes(8));
    file_put_contents("$dir/$code.json", json_encode(['nonce' => $_GET['nonce'] ?? '', 'challenge' => $_GET['code_challenge'] ?? '',
        'user' => $_GET['login_hint'] ?? '', 'client_id' => $_GET['client_id'] ?? '', 'tamper' => !empty($_GET['tamper'])]));
    header('Location: ' . $_GET['redirect_uri'] . '?' . http_build_query(['code' => $code, 'state' => $_GET['state'] ?? '']));
    http_response_code(302);
} elseif ($path === '/token') {
    $f = "$dir/" . preg_replace('/[^a-f0-9]/', '', $_POST['code'] ?? '') . '.json';
    $c = is_file($f) ? json_decode(file_get_contents($f), true) : null;
    @unlink($f);
    if (!$c || $b64u(hash('sha256', $_POST['code_verifier'] ?? '', true)) !== $c['challenge']) { http_response_code(400); echo json_encode(['error' => 'invalid_grant']); exit; }
    $h = $b64u(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']));
    $p = $b64u(json_encode(['iss' => $iss, 'aud' => $c['client_id'], 'sub' => 'sub-' . $c['user'], 'preferred_username' => $c['user'],
        'nonce' => $c['nonce'], 'iat' => time(), 'exp' => time() + 300]));
    openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
    if ($c['tamper']) $p = $b64u(json_encode(['iss' => $iss, 'aud' => $c['client_id'], 'preferred_username' => 'admin', 'nonce' => $c['nonce'], 'iat' => time(), 'exp' => time() + 300]));
    echo json_encode(['access_token' => 'x', 'token_type' => 'Bearer', 'id_token' => "$h.$p." . $b64u($sig)]);
} else {
    http_response_code(404); echo '{}';
}
return true;
