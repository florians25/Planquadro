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
          || (getenv('PQ_FORCE_SECURE_COOKIES') === '1');
    /* Je Mandant eigener Cookie-Name – zusätzlich zur Bindung in opsBindSessionToTenant() */
    if (function_exists('opsCurrentTenant') && opsMode() === 'saas') session_name('PQSID_' . preg_replace('/[^a-z0-9]/', '', opsCurrentTenant()['slug']));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    /* PQ_SESSION_STORE=db: Sitzungen in der (Mandanten-)Datenbank, für mehrere App-Container */
    $dbStore = opsStartSessionStore('tenant');
    if (!session_start() && $dbStore) opsSessionStoreUnavailable();
    if (function_exists('opsBindSessionToTenant')) opsBindSessionToTenant();
}

/** Lädt und registriert den DB-Session-Handler (nur bei PQ_SESSION_STORE=db). */
function opsStartSessionStore(string $target): bool
{
    if (!function_exists('opsEnv') || !defined('DB_HOST')) return false;
    require_once __DIR__ . '/SessionStore.php';
    return opsUseSessionStore($target);
}

/** Sitzungsspeicher nicht erreichbar: lieber 503 als ein Request ohne Sitzung. */
function opsSessionStoreUnavailable(): void
{
    error_log('Planquadro: Sitzungsspeicher (PQ_SESSION_STORE=db) nicht verfügbar – Datenbank und Migration 0008 prüfen.');
    http_response_code(503);
    header('Retry-After: 5');
    exit('Dienst vorübergehend nicht verfügbar.');
}

/**
 * Leerlauf-Timeout aus der Einstellung session_timeout_minutes (0 = aus).
 * Die Einstellung existierte bis v6.9.0, wurde aber nie durchgesetzt.
 */
function opsEnforceSessionTimeout(): void
{
    if (empty($_SESSION['user_id']) || !function_exists('getDB')) return;
    $now = time();
    $last = (int)($_SESSION['last_activity'] ?? $now);
    $limit = $_SESSION['timeout_min'] ?? null;
    if ($limit === null || ($now - (int)($_SESSION['timeout_checked'] ?? 0)) > 300) {
        $limit = 480;
        try {
            $s = getDB()->prepare("SELECT setting_value FROM app_settings WHERE setting_key='session_timeout_minutes'");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v !== false && $v !== '') $limit = (int)$v;
        } catch (Throwable $e) {}
        $_SESSION['timeout_min'] = $limit;
        $_SESSION['timeout_checked'] = $now;
    }
    if ($limit > 0 && ($now - $last) > $limit * 60) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['session_expired'] = 1;
        return;
    }
    /* Polling (Live-Status) und der Ereignisstrom zählen nicht als Aktivität */
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, 'live_status') === false && strpos($uri, 'notifications') === false && strpos($uri, '/api/events') === false) $_SESSION['last_activity'] = $now;
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
 * PQ_CSRF_EXEMPT (Cron-Aufruf mit eigenem Schlüssel).
 */
function opsEnforceCsrf(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    if (defined('PQ_CSRF_EXEMPT') && PQ_CSRF_EXEMPT) return;
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
 * (PQ_TRUSTED_PROXIES, kommagetrennt, IPs oder CIDR-Bereiche).
 */
function opsClientIp(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = array_filter(array_map('trim', explode(',', (string)getenv('PQ_TRUSTED_PROXIES'))));
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


/**
 * Widerruft alle App-Sitzungen (Refresh-Token-Familien) eines Benutzers –
 * nach Passwortwechsel oder -reset. Fehlt die Tabelle (Migration 0005 noch
 * nicht eingespielt), passiert nichts.
 */
function opsRevokeUserTokens(PDO $db, int $userId): void
{
    try {
        $db->prepare('UPDATE api_refresh_tokens SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([$userId]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
    }
}

/** Fester Vergleichs-Hash, damit password_verify() auch für unbekannte Benutzer Zeit kostet */
function opsDummyHash(): string
{
    static $h = null;
    return $h ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
}

/* --------------------------------------------------------------------------
   Content-Security-Policy (Befunde S-21, S-22)
   script-src ohne 'unsafe-inline'/'unsafe-eval': Inline-Skriptblöcke tragen
   die Nonce dieses Requests, Inline-Handler (onclick=…) gibt es nicht mehr –
   Elemente verwenden data-action (public/assets/js/actions.js, opsActionAttr()).
   style-src behält 'unsafe-inline' (dynamische Farben im Planungsraster).
   -------------------------------------------------------------------------- */

/** Nonce für Inline-Skripte, je Request einmal erzeugt (base64, 128 Bit). */
function opsCspNonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

/**
 * Seiten, die noch Inline-Handler enthalten und gerade neu gebaut werden.
 * Nur für sie gilt übergangsweise script-src 'self' 'unsafe-inline' (ohne Nonce,
 * sonst ignorierten Browser 'unsafe-inline'). Eintrag entfernen, sobald die Seite
 * CSP-konform ist (tests/Unit/NoInlineHandlersTest prüft die Liste mit).
 */
const OPS_CSP_LEGACY_PAGES = [];

/** Policy-Text; $frameAncestors z. B. "'none'" für die Plattform-Administration. */
function opsContentSecurityPolicy(string $frameAncestors = "'self'", bool $legacyInline = false): string
{
    return "default-src 'self'; "
         . ($legacyInline ? "script-src 'self' 'unsafe-inline'; " : "script-src 'self' 'nonce-" . opsCspNonce() . "'; ")
         . "style-src 'self' 'unsafe-inline'; "
         . "img-src 'self' data:; "
         . "font-src 'self'; "
         . "connect-src 'self'; "
         . "object-src 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         . "frame-ancestors " . $frameAncestors;
}

/**
 * Setzt den CSP-Header (ersetzt einen zuvor gesetzten). Aufgerufen von
 * opsKernelBoot() und der Login-Seite. Strengere Policies einzelner Endpunkte
 * (z. B. api/branding: sandbox) werden danach gesetzt und ersetzen diese.
 */
function opsSendCsp(string $frameAncestors = "'self'", bool $legacyInline = false): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('Content-Security-Policy: ' . opsContentSecurityPolicy($frameAncestors, $legacyInline));
}

/**
 * Attribute für die Ereignis-Delegation (Ersatz für Inline-Handler), korrekt
 * HTML-escaped (Befund S-21). Beispiel:
 *   <button<?php echo opsActionAttr('openWotDetail', [$id, $name]); ?>>
 * ergibt  data-action="openWotDetail" data-args="[12,&quot;Muster, Max&quot;]".
 * $event: 'click' (Standard), 'change' oder 'input'.
 * Zeichenketten, die mit "$" beginnen, werden als "$$…" kodiert, damit sie nicht
 * als Platzhalter gelesen werden; Platzhalter selbst mit opsActionToken('this').
 */
function opsActionAttr(string $action, array $args = [], string $event = 'click'): string
{
    $attr = $event === 'click' ? 'data-action' : 'data-action-' . preg_replace('/[^a-z]/', '', $event);
    $out = ' ' . $attr . '="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '"';
    if ($args) {
        $args = array_map(static fn($v) => $v instanceof OpsActionToken ? $v->placeholder()
            : (is_string($v) && $v !== '' && $v[0] === '$' ? '$' . $v : $v), array_values($args));
        $json = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $out .= ' data-args="' . htmlspecialchars((string)$json, ENT_QUOTES, 'UTF-8') . '"';
    }
    return $out;
}

/** Platzhalter-Argument für opsActionAttr(): this (Element), event, value, checked. */
final class OpsActionToken
{
    private function __construct(private string $name) {}
    public static function of(string $name): self
    {
        if (!in_array($name, ['this', 'event', 'value', 'checked'], true)) throw new InvalidArgumentException('Unbekannter Platzhalter: ' . $name);
        return new self($name);
    }
    public function placeholder(): string { return '$' . $this->name; }
}
function opsActionToken(string $name): OpsActionToken { return OpsActionToken::of($name); }
