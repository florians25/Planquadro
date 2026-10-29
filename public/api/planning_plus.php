<?php
/**
 * Planungshilfen der Monatsplanung (siehe app/lib/PlanningPlus.php)
 *   POST {action:'cell_info', user_id, date}   Entwurf/Änderungen einer Zelle   – plan_edit + Team-Scope
 *   POST {action:'targets'}                    Soll-Besetzung lesen             – plan_edit
 *   POST {action:'targets_save', targets:{…}}  Soll-Besetzung speichern         – manage_config
 * Planzellen schreibt dieser Endpunkt nie (dafür bleibt api/save_bulk).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../app/lib/PlanningPlus.php';
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Nicht angemeldet']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Nur POST']); exit; }
if (!canEdit()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']); exit; }
/* Eigener Zähler – apiRateLimit() teilt sein Budget mit save_bulk, das Überfahren
   von Zellen darf das Speichern nie ausbremsen. */
$rk = 'pq_plus_calls_' . date('YmdHi');
foreach (array_keys($_SESSION) as $k) if (strpos((string)$k, 'pq_plus_calls_') === 0 && $k !== $rk) unset($_SESSION[$k]);
$_SESSION[$rk] = ($_SESSION[$rk] ?? 0) + 1;
if ($_SESSION[$rk] > 300) { http_response_code(429); echo json_encode(['success' => false, 'error' => 'Zu viele Anfragen. Bitte warten.']); exit; }
$in = secureJsonInput();
$act = (string)($in['action'] ?? '');
$db = getDB();

try {
    if ($act === 'cell_info') {
        $uid = cleanInt($in['user_id'] ?? 0); $date = cleanDate($in['date'] ?? '');
        if (!$uid || !$date) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Ungültige Daten']); exit; }
        if (!canEditUser($uid)) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']); exit; }
        echo json_encode(['success' => true] + pqCellInfo($db, $uid, $date));
        exit;
    }
    if ($act === 'targets') {
        echo json_encode(['success' => true, 'targets' => (object)pqTargetsAll($db), 'can_edit' => canManageConfig()]);
        exit;
    }
    if ($act === 'targets_save') {
        if (!canManageConfig()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']); exit; }
        $norm = pqTargetsNormalize($in['targets'] ?? null, array_keys(planAreaMap()));
        if (is_string($norm)) { http_response_code(400); echo json_encode(['success' => false, 'error' => $norm]); exit; }
        pqTargetsSave($db, $norm, (int)$_SESSION['user_id']);
        if (function_exists('opsAudit')) opsAudit('plan_area_targets', count($norm) . ' Bereich(e) mit Soll-Besetzung');
        echo json_encode(['success' => true, 'targets' => (object)$norm, 'msg' => 'Soll-Besetzung gespeichert.']);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion']);
} catch (Throwable $e) {
    opsApiFail($e, 'planning_plus', ['success' => false, 'error' => 'Fehler bei den Planungshilfen'], 500);
}
