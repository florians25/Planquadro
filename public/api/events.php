<?php
/**
 * Live-Ereignisse für die Weboberfläche (Server-Sent Events), siehe app/lib/Events.php.
 * GET /api/events  – Session-Anmeldung wie api/live_status; Fortsetzen per Last-Event-ID.
 * Ohne Anmeldung 401. Ist SSE abgeschaltet (PQ_SSE_ENABLED=0), antwortet der Endpunkt
 * mit 204 – EventSource verbindet sich dann nicht erneut, die Seite pollt wie bisher.
 */
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../../app/lib/Events.php';
if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'error'=>'Nicht angemeldet']);
    exit;
}
if (!pqSseEnabled()) { http_response_code(204); exit; }
/* Rechte einmal bestimmen, dann die Session-Sperre freigeben: sonst warten alle
   weiteren Requests desselben Browsers, bis der Stream endet. */
$viewer = pqEventViewer('web');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
try {
    pqSseStream(getDB(), $viewer, pqSseLastEventId(), pqSseMaxSeconds());
} catch (Throwable $e) {
    opsLogThrowable('events', $e);
    if (!headers_sent()) { http_response_code(503); header('Retry-After: 10'); }
}
