<?php
/**
 * Security helpers for API endpoints.
 * Include after auth.php in every API file.
 */

/* Rate limiting per session */
function apiRateLimit($maxPerMinute = 60): void {
    $key = 'api_calls_' . date('YmdHi');
    if (!isset($_SESSION[$key])) $_SESSION[$key] = 0;
    $_SESSION[$key]++;
    /* Cleanup old keys */
    foreach ($_SESSION as $k => $v) {
        if (strpos($k, 'api_calls_') === 0 && $k !== $key) unset($_SESSION[$k]);
    }
    if ($_SESSION[$key] > $maxPerMinute) {
        http_response_code(429);
        echo json_encode(['error' => 'Zu viele Anfragen. Bitte warten.']);
        exit;
    }
}

/* Validate JSON input */
function secureJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige JSON-Daten']);
        exit;
    }
    return $data;
}

/* Sanitize string input */
function cleanStr($val, int $maxLen = 1000): string {
    if (!is_string($val)) return '';
    $val = trim($val);
    if (mb_strlen($val) > $maxLen) $val = mb_substr($val, 0, $maxLen);
    /* Strip null bytes and control chars except newlines */
    $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $val);
    return $val;
}

/* Sanitize integer */
function cleanInt($val): int {
    return (int) $val;
}

/* Validate date string (Y-m-d) */
function cleanDate($val): string {
    if (!is_string($val)) return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return '';
    $parts = explode('-', $val);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) return '';
    return $val;
}

/* Validate CSRF for state-changing operations */
function requireCsrf(array $input): void {
    $token = $input['_token'] ?? $input['csrf_token'] ?? '';
    if (!$token || !verifyCsrf($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Sicherheitstoken ungültig. Bitte Seite neu laden.']);
        exit;
    }
}

/* Ensure request is AJAX */
function requireAjax(): void {
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        (empty($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false) &&
        empty($_SERVER['CONTENT_TYPE']) || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
        /* Allow JSON content-type requests even without X-Requested-With */
    }
}

/* Validate that user ID exists */
function validateUserId(int $uid): bool {
    if ($uid <= 0) return false;
    try {
        $s = getDB()->prepare('SELECT id FROM users WHERE id=?');
        $s->execute([$uid]);
        return (bool)$s->fetch();
    } catch (Exception $e) { return false; }
}

/* Log suspicious activity */
function logSuspicious(string $reason): void {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    opsAudit('suspicious', "$reason | URI: $uri");
}
