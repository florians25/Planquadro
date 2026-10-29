<?php
/**
 * Backup-API der Einstellungsseite (Recht manage_settings)
 * --------------------------------------------------------------------------
 *   POST {action:"status"}    Status des Mandanten: letzter Lauf, Alter, Ziele, Warnungen,
 *                             vorhandene Archive (lokal) und Alt-Backups (.epbak)
 *   POST {action:"create"}    Backup jetzt erstellen (konsistent, mit dem öffentlichen
 *                             Schlüssel verschlüsselt, an alle Ziele) – app/lib/Backup.php
 *   GET  ?action=download&name=<archiv>   Archiv herunterladen (bleibt verschlüsselt)
 *
 * Entfernt (Befunde B-01…B-05): Cron-Aufruf mit Schlüssel in der URL, Anzeige des
 * Schlüssels, Restore und Löschen über den Browser. Restore erfolgt mit dem offline
 * verwahrten privaten Schlüssel per CLI (php bin/backup.php restore, docs/BACKUP.md);
 * regelmäßige Backups übernimmt bin/scheduler.php bzw. cron/systemd.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../app/lib/Backup.php';

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? secureJsonInput() : [];
$action = (string)($input['action'] ?? $_GET['action'] ?? '');

function backupJson(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn()) backupJson(['success' => false, 'error' => 'Nicht angemeldet'], 403);
if (!hasPerm('manage_settings')) backupJson(['success' => false, 'error' => 'Keine Berechtigung'], 403);

$b = new Backup();
$scope = $b->currentScope();
$legacyDir = dirname(__DIR__, 2) . '/app/backups';

/** Alt-Backups der Vorversion (nur Self-Hosting, nur Anzeige/Download). */
function legacyBackups(string $dir): array
{
    if (opsMode() === 'saas') return [];
    $out = [];
    foreach (glob($dir . '/backup_*.epbak') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size_fmt' => Backup::fmtBytes((int)filesize($f)), 'date' => date('d.m.Y H:i', (int)filemtime($f)), 'ts' => (int)filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $out;
}

function fmtRun(?array $r): ?array
{
    if (!$r) return null;
    $t = strtotime($r['finished_at'] ?? $r['started_at']);
    return ['status' => $r['status'], 'kind' => $r['kind'], 'archive' => $r['archive'] ? basename($r['archive']) : null,
            'size_fmt' => isset($r['size_bytes']) ? Backup::fmtBytes((int)$r['size_bytes']) : null,
            'targets' => $r['targets'] ?? [], 'date' => date('d.m.Y H:i', $t), 'ts' => $t,
            'message' => isset($r['message']) ? mb_substr((string)$r['message'], 0, 500) : null];
}

switch ($action) {
case 'status':
case 'list':
    $st = $b->status([$scope['scope']]);
    $s = $st['scopes'][$scope['scope']];
    $archives = [];
    foreach ($b->local()->list($scope['scope']) as $o) {
        $p = Backup::parseName($o['key']);
        if (!$p || $p['scope'] !== $scope['scope']) continue;
        $archives[] = ['name' => basename($o['key']), 'kind' => $p['kind'], 'size_fmt' => Backup::fmtBytes($o['size']),
                       'date' => date('d.m.Y H:i', $p['time']), 'ts' => $p['time']];
    }
    usort($archives, fn($a, $c) => $c['ts'] <=> $a['ts']);
    backupJson(['success' => true, 'healthy' => $st['healthy'] && $s['healthy'], 'age_hours' => $s['age_hours'],
        'max_age_hours' => $st['max_age_hours'], 'targets' => $st['targets'], 'external_target' => $st['external_target'],
        'encryption' => $st['encryption'], 'retention' => $st['retention'],
        'warnings' => array_values(array_merge($st['warnings'], $s['warnings'])),
        'last_run' => fmtRun($s['last_run']), 'last_success' => fmtRun($s['last_success']), 'last_restore_test' => fmtRun($s['last_restore_test']),
        'backups' => $archives, 'legacy' => legacyBackups($legacyDir)]);

case 'create':
    @set_time_limit(0);
    try { $r = $b->withLock(fn() => $b->runScope($scope, 'manual')); }
    catch (BackupException $e) { backupJson(['success' => false, 'error' => $e->getMessage()], 409); }
    if ($r['status'] === 'failed') backupJson(['success' => false, 'error' => 'Backup fehlgeschlagen: ' . $r['error']]);
    backupJson(['success' => true, 'status' => $r['status'], 'msg' => 'Backup erstellt: ' . basename($r['archive']),
        'size' => Backup::fmtBytes($r['size']), 'targets' => $r['targets'], 'warnings' => $r['warnings']]);

case 'download':
    $name = basename((string)($_GET['name'] ?? $input['name'] ?? ''));
    $p = Backup::parseName($name);
    if ($p && $p['scope'] === $scope['scope']) $path = $b->local()->path($scope['scope'] . '/' . $name);
    elseif (opsMode() !== 'saas' && preg_match('/^backup_[\w\-]+\.epbak$/', $name)) $path = $legacyDir . '/' . $name;
    else backupJson(['success' => false, 'error' => 'Ungültiger Name'], 400);
    if (!is_file($path)) backupJson(['success' => false, 'error' => 'Nicht gefunden'], 404);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;

case 'cron_backup': case 'setup_cron': case 'get_key': case 'restore': case 'delete':
    backupJson(['success' => false, 'error' => 'Diese Funktion wurde aus Sicherheitsgründen entfernt. Regelmäßige Backups: bin/scheduler.php bzw. cron; Restore: php bin/backup.php restore (siehe docs/BACKUP.md).'], 410);

default:
    backupJson(['success' => false, 'error' => 'Unbekannte Aktion'], 400);
}
