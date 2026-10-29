<?php
/**
 * Zentrale Sicherheitsfunktionen (CSRF, Client-IP, Eingabeprüfung).
 * Wird von public/includes/auth.php und public/index.php geladen.
 */

/**
 * Startet die Session mit sicheren Cookie-Parametern. Muss überall statt
 * session_start() verwendet werden – sonst setzt z. B. die Login-Seite das
 * Cookie ohne HttpOnly/SameSite.
 */
function opsStartSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (getenv('OPSPILOT_FORCE_SECURE_COOKIES') === '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** Session-gebundenes CSRF-Token (32 Byte, hex). */
function opsCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

/**
 * Liest das vom Client gesendete Token: Header X-CSRF-Token, Formularfeld
 * _token oder Feld _token im JSON-Body (für navigator.sendBeacon, das keine
 * Header setzen kann).
 */
function opsCsrfFromRequest(): string
{
    $h = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($h !== '') return (string)$h;
    if (isset($_POST['_token'])) return (string)$_POST['_token'];
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false && ($raw[0] === '{' || stripos($ct, 'json') !== false || stripos($ct, 'text/plain') !== false)) {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j['_token']) && is_string($j['_token'])) return $j['_token'];
    }
    return '';
}

/**
 * Bricht zustandsändernde Requests (alles außer GET/HEAD/OPTIONS) ohne
 * gültiges Token mit HTTP 403 ab. Ausnahme nur über die Konstante
 * OPSPILOT_CSRF_EXEMPT (Cron-Aufruf mit eigenem Schlüssel).
 */
function opsEnforceCsrf(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    if (defined('OPSPILOT_CSRF_EXEMPT') && OPSPILOT_CSRF_EXEMPT) return;
    $sent = opsCsrfFromRequest();
    if ($sent !== '' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent)) return;
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Sicherheitstoken ungültig oder abgelaufen. Bitte die Seite neu laden.', 'code' => 'csrf']);
    exit;
}

/**
 * Nach erfolgreicher Anmeldung: neue Session-ID (Schutz vor Session
 * Fixation) und neues CSRF-Token.
 */
function opsRegenerateSession(): void
{
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * IP-Adresse des Clients. X-Forwarded-For wird nur ausgewertet, wenn die
 * direkte Gegenstelle ein vertrauenswürdiger Proxy ist
 * (OPSPILOT_TRUSTED_PROXIES, kommagetrennt, IPs oder CIDR-Bereiche).
 */
function opsClientIp(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = array_filter(array_map('trim', explode(',', (string)getenv('OPSPILOT_TRUSTED_PROXIES'))));
    if (!$trusted || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $remote;
    $isTrusted = false;
    foreach ($trusted as $t) if (opsIpInRange($remote, $t)) { $isTrusted = true; break; }
    if (!$isTrusted) return $remote;
    /* Von rechts nach links: erste Adresse, die kein vertrauenswürdiger Proxy ist */
    $chain = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
    foreach ($chain as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) break;
        $proxy = false;
        foreach ($trusted as $t) if (opsIpInRange($ip, $t)) { $proxy = true; break; }
        if (!$proxy) return $ip;
    }
    return $remote;
}

function opsIpInRange(string $ip, string $range): bool
{
    if (strpos($range, '/') === false) return $ip === $range;
    [$net, $bits] = explode('/', $range, 2);
    $ipBin = @inet_pton($ip); $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8); $rest = $bits % 8;
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    if ($rest === 0) return true;
    $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
}

/** Hex-Farbe (#rgb, #rrggbb, #rrggbbaa) oder null. */
function opsValidColor($v): ?string
{
    $v = is_string($v) ? trim($v) : '';
    return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) ? $v : null;
}

/** http(s)-URL oder leer; alles andere (javascript:, data:) wird verworfen. */
function opsSafeUrl($v): ?string
{
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') return '';
    if (!preg_match('#^https?://#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false) return null;
    return $v;
}

/** Y-m-d oder null */
function opsValidDate($v): ?string
{
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
}
