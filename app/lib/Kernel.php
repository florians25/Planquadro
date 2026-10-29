<?php
/**
 * Request-Kernel (klein, additiv)
 * --------------------------------------------------------------------------
 * Gemeinsamer Rahmen für alle Seiten und die Endpunkte unter public/api/*.php:
 *
 *   - Request-ID: je Request genau eine ID, als Antwort-Header X-Request-ID
 *     und in jeder Log-Zeile über opsLog(). Eine vom Reverse Proxy gesetzte
 *     X-Request-ID wird übernommen, wenn sie unbedenklich ist.
 *   - Einheitliches Fehlerformat: {"success":false,"error":"…","request_id":"…"}.
 *     Bestehende Felder (success, error, msg) und HTTP-Codes der Fachfehler
 *     bleiben unverändert; request_id kommt nur hinzu.
 *   - Globaler Exception-Handler: nicht abgefangene Fehler werden mit allen
 *     Details (Klasse, Meldung, Datei, Zeile) nur ins Log geschrieben. Der
 *     Client erhält eine generische Meldung mit der Request-ID – nie den Text
 *     einer PDOException oder eines anderen technischen Fehlers (Befund S-20).
 *     Bewusst fachliche Meldungen (InvalidArgumentException, OpsUserException)
 *     dürfen weiter angezeigt werden.
 *   - opsAudit(): ein Einstiegspunkt für Einträge im audit_log.
 *
 * Geladen von public/includes/auth.php (opsKernelBoot()). Die API v1
 * (public/api/v1) hat einen eigenen Fehlerrahmen; dort setzt der Kernel nur
 * die Request-ID, installiert aber keine Handler.
 */

/**
 * Fachliche Meldung, die dem Benutzer angezeigt werden darf. Für neue Codepfade
 * gedacht, die Validierungs- oder Ablauffehler per Exception melden.
 */
class OpsUserException extends RuntimeException {}

/** ID des laufenden Requests (16 Hex-Zeichen oder die geprüfte ID des Proxys). */
function opsRequestId(): string
{
    static $id = null;
    if ($id !== null) return $id;
    $in = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    /* Nur unbedenkliche Zeichen übernehmen – die ID landet in Logs und Headern */
    $id = preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $in) ? $in : bin2hex(random_bytes(8));
    return $id;
}

/** Log-Zeile mit Request-ID: „[req <id>] <Text>“. */
function opsLog(string $message): void
{
    error_log('[req ' . opsRequestId() . '] ' . $message);
}

/** Technischen Fehler mit Kontext und Fundstelle protokollieren (nur Server-Log). */
function opsLogThrowable(string $context, Throwable $e): void
{
    opsLog($context . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

/** Darf die Meldung dieser Exception an den Client gehen? */
function opsIsUserFacing(Throwable $e): bool
{
    return $e instanceof OpsUserException || $e instanceof InvalidArgumentException;
}

/** Generische Meldung für technische Fehler (mit Referenz für den Support). */
function opsGenericErrorMessage(): string
{
    return 'Interner Fehler. Bitte erneut versuchen oder die Administration informieren (Referenz: ' . opsRequestId() . ').';
}

/** Meldung für den Client: fachlicher Text oder generische Meldung. */
function opsPublicMessage(Throwable $e): string
{
    return opsIsUserFacing($e) ? $e->getMessage() : opsGenericErrorMessage();
}

/**
 * JSON-Fehlerantwort im einheitlichen Format senden (ohne exit).
 * $body enthält die bisherigen Felder des Endpunkts; request_id wird ergänzt.
 */
function opsJsonError(array $body, int $status = 0): void
{
    if (!headers_sent()) {
        if ($status > 0) http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($body + ['request_id' => opsRequestId()]);
}

/**
 * Abgefangenen technischen Fehler behandeln: Details ins Log, dem Client nur
 * $body (bisherige, generische Meldung des Endpunkts) plus request_id.
 */
function opsApiFail(Throwable $e, string $context, array $body, int $status = 0): void
{
    opsLogThrowable($context, $e);
    opsJsonError($body, $status);
}

/** Erwartet der Client eine JSON-Antwort (API, AJAX-Handler, JSON-Header)? */
function opsExpectsJson(): bool
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (strpos($script, '/api/') !== false) return true;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return true;
    if (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) return true;
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) return true;
    foreach (headers_list() as $h) if (stripos($h, 'content-type:') === 0 && stripos($h, 'json') !== false) return true;
    return false;
}

/** Antwort für einen nicht abgefangenen Fehler (JSON oder schlichte HTML-Seite). */
function opsKernelRespond(int $status, string $message): void
{
    if (opsExpectsJson()) {
        opsJsonError(['success' => false, 'error' => $message], $status);
        return;
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>'
       . '<p style="font-family:sans-serif;max-width:40rem;margin:3rem auto">'
       . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
}

/** Globaler Exception-Handler (set_exception_handler). */
function opsKernelExceptionHandler(Throwable $e): void
{
    try {
        opsLogThrowable('Nicht abgefangener Fehler ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''), $e);
        while (ob_get_level() > 0) @ob_end_clean();
        if (opsIsUserFacing($e)) opsKernelRespond(400, $e->getMessage());
        else opsKernelRespond(500, opsGenericErrorMessage());
    } catch (Throwable $inner) {
        error_log('[req ' . opsRequestId() . '] Fehler im Exception-Handler: ' . $inner->getMessage());
    }
}

/** Fatale PHP-Fehler (Speicher, Syntax in Includes …) ebenfalls generisch beantworten. */
function opsKernelShutdown(): void
{
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    opsLog('Fataler Fehler: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
    if (!headers_sent()) opsKernelRespond(500, opsGenericErrorMessage());
}

/**
 * Kernel für den laufenden Request aktivieren (mehrfacher Aufruf ist harmlos).
 * $handlers=false: nur Request-ID (z. B. API v1 mit eigenem Fehlerrahmen).
 */
function opsKernelBoot(bool $handlers = true): void
{
    static $booted = false;
    if (PHP_SAPI === 'cli') return;
    if (!headers_sent()) header('X-Request-ID: ' . opsRequestId());
    if ($booted || !$handlers) return;
    $booted = true;
    /* Fehlertexte nie in die Antwort schreiben – sie gehen ins Log */
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler('opsKernelExceptionHandler');
    register_shutdown_function('opsKernelShutdown');
}

/**
 * Eintrag im audit_log (Benutzer, Aktion, Details, Client-IP). Fehler beim
 * Schreiben werden nur protokolliert – ein Audit-Eintrag bricht keinen Request ab.
 */
function opsAudit(string $action, string $details = '', ?int $userId = null): void
{
    try {
        $uid = $userId ?? (int)($_SESSION['user_id'] ?? 0);
        getDB()->prepare('INSERT INTO audit_log(user_id,action,details,ip_address) VALUES(?,?,?,?)')
               ->execute([$uid ?: null, mb_substr($action, 0, 100), mb_substr($details, 0, 500), opsClientIp()]);
    } catch (Throwable $e) {
        opsLogThrowable('audit', $e);
    }
}
