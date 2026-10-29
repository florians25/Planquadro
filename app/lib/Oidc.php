<?php
/**
 * OpsPilot – OpenID Connect (Authorization Code + PKCE)
 * --------------------------------------------------------------------------
 * Optionaler Login über einen Identity-Provider (Keycloak, Entra ID, Authentik,
 * Google Workspace …). Konfiguration in der TenantConfig (security.oidc), das
 * Client-Secret alternativ über OPSPILOT_OIDC_CLIENT_SECRET – im SaaS-Modus nur,
 * wenn OPSPILOT_OIDC_ISSUER denselben Issuer festlegt (sonst könnte ein Mandant
 * das plattformweite Secret an einen eigenen Server schicken lassen).
 *
 * Sicherheit:
 *  - state (CSRF), nonce (Replay) und PKCE (S256) bei jedem Login
 *  - ID-Token-Signatur gegen die JWKS des Providers (RS256/384/512, ES256)
 *  - Prüfung von iss, aud/azp, exp, iat, nonce
 *  - keine automatische Benutzeranlage: der Claim muss einem aktiven
 *    OpsPilot-Benutzer (users.username) entsprechen
 */

require_once __DIR__ . '/PlanCells.php';   /* opsAppKey() */

class OidcException extends RuntimeException {}

function oidcConfig(): array
{
    $c = (array)tcfg('security.oidc', []);
    $env = opsEnv('OPSPILOT_OIDC_CLIENT_SECRET');
    if ($env !== '') {
        $pinned = rtrim(opsEnv('OPSPILOT_OIDC_ISSUER'), '/');
        $issuer = rtrim((string)($c['issuer'] ?? ''), '/');
        if (opsMode() !== 'saas' ? ($pinned === '' || $pinned === $issuer) : ($pinned !== '' && $pinned === $issuer)) $c['client_secret'] = $env;
    }
    return $c;
}

function oidcEnabled(): bool
{
    $c = oidcConfig();
    return !empty($c['enabled']) && !empty($c['issuer']) && !empty($c['client_id']);
}

function oidcPasswordLoginAllowed(): bool
{
    return !oidcEnabled() || !empty(oidcConfig()['allow_password_login']);
}

function oidcBaseUrl(): string
{
    $fixed = opsEnv('OPSPILOT_BASE_URL');
    if ($fixed !== '') return rtrim($fixed, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('OPSPILOT_FORCE_SECURE_COOKIES') === '1';
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function oidcRedirectUri(): string { return oidcBaseUrl() . '/oidc'; }

function oidcHttp(string $method, string $url, ?array $form = null, array $headers = []): array
{
    if (!preg_match('#^https://#', $url) && getenv('OPSPILOT_OIDC_ALLOW_HTTP') !== '1') throw new OidcException('Der Identity-Provider muss per HTTPS erreichbar sein.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_MAXFILESIZE => 1048576]);
    /* SSRF-Schutz: im SaaS-Modus keine internen Ziele; die geprüfte Adresse wird fest
       vorgegeben, damit ein zweiter DNS-Lookup (Rebinding) nichts anderes liefert. */
    if (oidcBlockPrivate()) {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'https://') ? 443 : 80));
        $ip = oidcPublicIp($host);
        if ($ip === null) { curl_close($ch); throw new OidcException('Der Identity-Provider ist nicht erreichbar oder liegt in einem internen Netz.'); }
        curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]);
    }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?? [])); }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new OidcException('Identity-Provider nicht erreichbar: ' . $err);
    $json = json_decode((string)$body, true);
    if (!is_array($json)) throw new OidcException('Ungültige Antwort des Identity-Providers (HTTP ' . $code . ').');
    if ($code >= 400) throw new OidcException('Identity-Provider meldet: ' . ($json['error_description'] ?? $json['error'] ?? ('HTTP ' . $code)));
    return $json;
}

function oidcBlockPrivate(): bool
{
    return opsMode() === 'saas' && getenv('OPSPILOT_OIDC_ALLOW_PRIVATE') !== '1';
}

/** Erste öffentliche Adresse des Hosts – null, wenn er (auch) intern auflöst */
function oidcPublicIp(string $host): ?string
{
    $host = trim($host, '[]');
    if ($host === '') return null;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
        gethostbynamel($host) ?: [],
        array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
    if (!$ips) return null;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
        if (preg_match('/^(::ffff:|64:ff9b::|fe80:|fc|fd)/i', $ip)) return null;
    }
    return $ips[0];
}

/** Discovery-Dokument, 1 h im Mandanten zwischengespeichert */
function oidcDiscovery(string $issuer, bool $force = false): array
{
    $issuer = rtrim($issuer, '/');
    if ($issuer === '') throw new OidcException('Keine Issuer-URL konfiguriert.');
    $cacheKey = 'oidc_cache_' . substr(sha1($issuer), 0, 12);
    if (!$force) {
        $s = getDB()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?'); $s->execute([$cacheKey]);
        $c = json_decode((string)$s->fetchColumn(), true);
        if (is_array($c) && ($c['_at'] ?? 0) > time() - 3600) return $c;
    }
    $d = oidcHttp('GET', $issuer . '/.well-known/openid-configuration');
    if (rtrim((string)($d['issuer'] ?? ''), '/') !== $issuer) throw new OidcException('Issuer im Discovery-Dokument stimmt nicht überein.');
    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) if (empty($d[$k])) throw new OidcException("Discovery: $k fehlt.");
    $d['jwks'] = oidcHttp('GET', $d['jwks_uri']);
    $d['_at'] = time();
    getDB()->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
        ->execute([$cacheKey, json_encode($d)]);
    return $d;
}

function oidcB64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function oidcB64uDecode(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** PKCE-Paar [verifier, challenge] */
function oidcPkce(): array
{
    $v = oidcB64u(random_bytes(32));
    return [$v, oidcB64u(hash('sha256', $v, true))];
}

function oidcAuthorizeUrl(string $redirectUri, string $challenge, string $state, string $nonce): string
{
    $c = oidcConfig();
    $d = oidcDiscovery($c['issuer']);
    return $d['authorization_endpoint'] . (strpos($d['authorization_endpoint'], '?') === false ? '?' : '&') . http_build_query([
        'response_type' => 'code', 'client_id' => $c['client_id'], 'redirect_uri' => $redirectUri,
        'scope' => $c['scopes'] ?? 'openid profile email', 'state' => $state, 'nonce' => $nonce,
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]);
}

/** Code gegen Tokens tauschen und ID-Token prüfen → Claims */
function oidcExchange(string $code, string $redirectUri, string $verifier, ?string $nonce): array
{
    /* PKCE und nonce sind Pflicht – auch für App-Logins über die API */
    if (strlen($verifier) < 43 || $nonce === null || $nonce === '') throw new OidcException('PKCE-Verifier und nonce sind erforderlich.');
    $c = oidcConfig();
    $d = oidcDiscovery($c['issuer']);
    $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $verifier, 'client_id' => $c['client_id']];
    $headers = [];
    if (!empty($c['client_secret'])) $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($c['client_id']) . ':' . rawurlencode($c['client_secret']));
    $tok = oidcHttp('POST', $d['token_endpoint'], $form, $headers);
    if (empty($tok['id_token'])) throw new OidcException('Kein ID-Token erhalten.');
    return oidcVerifyIdToken($tok['id_token'], $d, $c['client_id'], $nonce);
}

function oidcVerifyIdToken(string $jwt, array $disc, string $clientId, ?string $nonce): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new OidcException('ID-Token ungültig.');
    $hdr = json_decode(oidcB64uDecode($parts[0]), true);
    $claims = json_decode(oidcB64uDecode($parts[1]), true);
    if (!is_array($hdr) || !is_array($claims)) throw new OidcException('ID-Token ungültig.');
    $alg = $hdr['alg'] ?? '';
    $algos = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512, 'ES256' => OPENSSL_ALGO_SHA256];
    if (!isset($algos[$alg])) throw new OidcException('Nicht unterstützter Signaturalgorithmus: ' . $alg);
    $jwk = null;
    foreach ((array)($disc['jwks']['keys'] ?? []) as $k) {
        if (($hdr['kid'] ?? null) !== null && ($k['kid'] ?? null) !== $hdr['kid']) continue;
        if (($k['use'] ?? 'sig') !== 'sig') continue;
        $jwk = $k; break;
    }
    if (!$jwk) throw new OidcException('Signaturschlüssel des Providers nicht gefunden.');
    $pem = oidcJwkToPem($jwk);
    $sig = oidcB64uDecode($parts[2]);
    if ($alg === 'ES256') $sig = oidcEcRawToDer($sig);
    if (openssl_verify($parts[0] . '.' . $parts[1], $sig, $pem, $algos[$alg]) !== 1) throw new OidcException('Signatur des ID-Tokens ungültig.');
    $now = time();
    if (rtrim((string)($claims['iss'] ?? ''), '/') !== rtrim((string)$disc['issuer'], '/')) throw new OidcException('ID-Token: falscher Aussteller.');
    $aud = (array)($claims['aud'] ?? []);
    if (!in_array($clientId, $aud, true)) throw new OidcException('ID-Token: falsche Zielgruppe.');
    if (count($aud) > 1 && ($claims['azp'] ?? null) !== $clientId) throw new OidcException('ID-Token: azp fehlt.');
    if ((int)($claims['exp'] ?? 0) < $now - 60) throw new OidcException('ID-Token abgelaufen.');
    if ((int)($claims['iat'] ?? 0) > $now + 120) throw new OidcException('ID-Token aus der Zukunft.');
    if ($nonce !== null && !hash_equals($nonce, (string)($claims['nonce'] ?? ''))) throw new OidcException('ID-Token: nonce stimmt nicht.');
    return $claims;
}

/** JWK (RSA oder EC P-256) → PEM (SubjectPublicKeyInfo) */
function oidcJwkToPem(array $k): string
{
    $len = function (int $n): string {
        if ($n < 0x80) return chr($n);
        $b = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($b)) . $b;
    };
    $tlv = function (int $tag, string $v) use ($len): string { return chr($tag) . $len(strlen($v)) . $v; };
    if (($k['kty'] ?? '') === 'RSA') {
        $int = function (string $b) use ($tlv) { if (ord($b[0]) > 0x7f) $b = "\0" . $b; return $tlv(0x02, $b); };
        $rsaKey = $tlv(0x30, $int(oidcB64uDecode($k['n'])) . $int(oidcB64uDecode($k['e'])));
        $algo = $tlv(0x30, $tlv(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
        $spki = $tlv(0x30, $algo . $tlv(0x03, "\0" . $rsaKey));
    } elseif (($k['kty'] ?? '') === 'EC' && ($k['crv'] ?? '') === 'P-256') {
        $point = "\x04" . str_pad(oidcB64uDecode($k['x']), 32, "\0", STR_PAD_LEFT) . str_pad(oidcB64uDecode($k['y']), 32, "\0", STR_PAD_LEFT);
        $algo = $tlv(0x30, $tlv(0x06, "\x2a\x86\x48\xce\x3d\x02\x01") . $tlv(0x06, "\x2a\x86\x48\xce\x3d\x03\x01\x07"));
        $spki = $tlv(0x30, $algo . $tlv(0x03, "\0" . $point));
    } else {
        throw new OidcException('Nicht unterstützter Schlüsseltyp.');
    }
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** ES256: JWS-Signatur (r||s, je 32 Byte) → DER für openssl_verify */
function oidcEcRawToDer(string $raw): string
{
    if (strlen($raw) !== 64) throw new OidcException('ES256-Signatur ungültig.');
    $enc = function (string $i): string { $i = ltrim($i, "\0"); if ($i === '' || ord($i[0]) > 0x7f) $i = "\0" . $i; return "\x02" . chr(strlen($i)) . $i; };
    $seq = $enc(substr($raw, 0, 32)) . $enc(substr($raw, 32));
    return "\x30" . chr(strlen($seq)) . $seq;
}

/** Aktiven OpsPilot-Benutzer zum Claim finden (kein Auto-Provisioning) */
function oidcFindUser(array $claims): ?array
{
    $claim = oidcConfig()['claim'] ?? 'preferred_username';
    $val = trim((string)($claims[$claim] ?? ''));
    if ($val === '') return null;
    if ($claim === 'email' && isset($claims['email_verified']) && $claims['email_verified'] === false) return null;
    $s = getDB()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
    $s->execute([$val]);
    $u = $s->fetch() ?: null;
    /* Administratoren melden sich standardmäßig nur per Passwort an (Notfallzugang);
       ein kompromittierter oder falsch konfigurierter IdP erhält so keine Admin-Rechte. */
    if ($u && $u['role'] === 'admin' && empty(oidcConfig()['allow_admin'])) return null;
    return $u;
}

/* ---- Zwischenstand des Logins (state/nonce/PKCE) ----
   Eigenes Cookie mit SameSite=Lax: Das Sitzungs-Cookie ist SameSite=Strict und
   wird bei der Rückkehr vom Identity-Provider (seitenübergreifende Navigation)
   vom Browser nicht mitgeschickt. Inhalt ist per HMAC gegen Manipulation gesichert. */
function oidcPendingCookieName(): string
{
    return 'OPSOIDC' . (opsMode() === 'saas' && function_exists('opsCurrentTenant') ? '_' . preg_replace('/[^a-z0-9]/', '', opsCurrentTenant()['slug']) : '');
}

function oidcPendingSet(array $p): void
{
    $data = oidcB64u(json_encode($p));
    $val = $data . '.' . oidcB64u(hash_hmac('sha256', 'oidc-pending|' . $data, opsAppKey(getDB()), true));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('OPSPILOT_FORCE_SECURE_COOKIES') === '1';
    setcookie(oidcPendingCookieName(), $val, ['expires' => time() + 600, 'path' => '/oidc', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}

/** Liest und verwirft den Zwischenstand (einmalig verwendbar) */
function oidcPendingTake(): ?array
{
    $name = oidcPendingCookieName();
    $val = (string)($_COOKIE[$name] ?? '');
    if ($val !== '') setcookie($name, '', ['expires' => 1, 'path' => '/oidc', 'httponly' => true, 'samesite' => 'Lax']);
    $parts = explode('.', $val);
    if (count($parts) !== 2) return null;
    $mac = oidcB64u(hash_hmac('sha256', 'oidc-pending|' . $parts[0], opsAppKey(getDB()), true));
    if (!hash_equals($mac, $parts[1])) return null;
    $p = json_decode(oidcB64uDecode($parts[0]), true);
    return is_array($p) ? $p : null;
}
