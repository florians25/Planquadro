<?php
/**
 * Backup & Restore API v6.1.2
 * - AES-256-CBC encrypted backups
 * - Max 10 backups, oldest gets overwritten
 * - Cron endpoint with secret key
 */
/* Der Cron-Aufruf authentisiert sich über den Backup-Schlüssel, nicht über
   eine Session – daher ohne CSRF-Token. */
if (($_GET['action'] ?? '') === 'cron_backup') define('OPSPILOT_CSRF_EXEMPT', true);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/security.php';
header('Content-Type: application/json; charset=utf-8');

$backupDir = __DIR__ . '/../../app/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);
if (!file_exists($backupDir.'/.htaccess')) file_put_contents($backupDir.'/.htaccess', "Deny from all\n");

$input = secureJsonInput();
$action = $input['action'] ?? $_GET['action'] ?? '';

/* === Encryption helpers === */
function getEncryptionKey() {
    $keyFile = __DIR__ . '/../../app/backups/.backup_key';
    if (file_exists($keyFile)) return trim(file_get_contents($keyFile));
    $key = bin2hex(random_bytes(32));
    file_put_contents($keyFile, $key);
    chmod($keyFile, 0600);
    return $key;
}

function encryptData($data, $key) {
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', hex2bin($key), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

function decryptData($encData, $key) {
    $raw = base64_decode($encData);
    if ($raw === false || strlen($raw) < 17) return false;
    $iv = substr($raw, 0, 16);
    $encrypted = substr($raw, 16);
    return openssl_decrypt($encrypted, 'aes-256-cbc', hex2bin($key), OPENSSL_RAW_DATA, $iv);
}

/* === Rotation: keep max 10 backups === */
function rotateBackups($dir, $max = 10) {
    $files = glob($dir . '/backup_*.epbak');
    if (!$files || count($files) <= $max) return;
    usort($files, function($a,$b){ return filemtime($a) - filemtime($b); });
    $toDelete = array_slice($files, 0, count($files) - $max);
    foreach ($toDelete as $f) unlink($f);
}

/* === Format bytes === */
function fmtBytes($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b/1024, 1) . ' KB';
    return round($b/1048576, 1) . ' MB';
}

/* === Daily cron: no session, auth via key === */
if ($action === 'cron_backup' && ($_GET['action'] ?? '') === 'cron_backup') {
    $cronKey = $_GET['key'] ?? $input['key'] ?? '';
    $expected = ''; try { $expected = getSetting('backup_cron_key', ''); } catch(Exception $e) {}
    if (!$expected || !$cronKey || !hash_equals($expected, $cronKey)) {
        http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
    }
    echo json_encode(performBackup('auto'));
    exit;
}

/* All other actions require login + permission */
if (!isLoggedIn()) { http_response_code(403); echo json_encode(['error'=>'Nicht angemeldet']); exit; }
if (!hasPerm('manage_settings')) { http_response_code(403); echo json_encode(['error'=>'Keine Berechtigung']); exit; }

if ($action === 'create') {
    echo json_encode(performBackup('manual'));
    exit;
}

if ($action === 'list') {
    $files = glob($backupDir . '/backup_*.epbak') ?: [];
    $list = [];
    foreach ($files as $f) {
        $name = basename($f);
        $list[] = [
            'name' => $name,
            'size_fmt' => fmtBytes(filesize($f)),
            'date' => date('d.m.Y H:i:s', filemtime($f)),
            'ts' => filemtime($f),
            'type' => (strpos($name, '_auto_') !== false) ? 'auto' : ((strpos($name, '_pre_restore_') !== false) ? 'safety' : 'manual'),
            'encrypted' => true
        ];
    }
    usort($list, function($a,$b){ return $b['ts'] - $a['ts']; });
    echo json_encode(['success'=>true, 'backups'=>$list, 'max'=>10, 'count'=>count($list)]);
    exit;
}

if ($action === 'download') {
    $name = basename($input['name'] ?? '');
    if (!$name || !preg_match('/^backup_[\w\-]+\.epbak$/', $name)) {
        echo json_encode(['success'=>false,'error'=>'Ungültig']); exit;
    }
    $path = $backupDir . '/' . $name;
    if (!file_exists($path)) { echo json_encode(['success'=>false,'error'=>'Nicht gefunden']); exit; }
    if (filesize($path) > 50 * 1024 * 1024) {
        echo json_encode(['success'=>false,'error'=>'Zu groß für Browser-Download']); exit;
    }
    echo json_encode(['success'=>true,'data'=>base64_encode(file_get_contents($path)),'name'=>$name]);
    exit;
}

if ($action === 'restore') {
    $name = basename($input['name'] ?? '');
    if (!$name || !preg_match('/^backup_[\w\-]+\.epbak$/', $name)) {
        echo json_encode(['success'=>false,'error'=>'Ungültig']); exit;
    }
    $path = $backupDir . '/' . $name;
    if (!file_exists($path)) { echo json_encode(['success'=>false,'error'=>'Nicht gefunden']); exit; }

    /* Safety backup first */
    performBackup('pre_restore');

    try {
        $key = getEncryptionKey();
        $encData = file_get_contents($path);
        $sql = decryptData($encData, $key);
        if ($sql === false) {
            echo json_encode(['success'=>false,'error'=>'Entschlüsselung fehlgeschlagen. Falscher Schlüssel?']); exit;
        }

        $db = getDB();
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
        $ok = 0;
        foreach ($statements as $stmt) {
            if (empty($stmt) || substr($stmt, 0, 2) === '--') continue;
            try { $db->exec($stmt); $ok++; } catch(Exception $e) {}
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        echo json_encode(['success'=>true,'msg'=>'Restore abgeschlossen ('.$ok.' Statements). Sicherheits-Backup erstellt.']);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'error'=>'Restore fehlgeschlagen']);
    }
    exit;
}

if ($action === 'delete') {
    $name = basename($input['name'] ?? '');
    if (!$name || !preg_match('/^backup_[\w\-]+\.epbak$/', $name)) {
        echo json_encode(['success'=>false,'error'=>'Ungültig']); exit;
    }
    $path = $backupDir . '/' . $name;
    if (file_exists($path)) unlink($path);
    echo json_encode(['success'=>true,'msg'=>'Backup gelöscht']);
    exit;
}

if ($action === 'setup_cron') {
    $cronSecret = bin2hex(random_bytes(16));
    setSetting('backup_cron_key', $cronSecret);
    $base = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https://':'http://') . ($_SERVER['HTTP_HOST']??'localhost');
    $cronUrl = $base . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/api/backup?action=cron_backup&key=' . $cronSecret;
    echo json_encode(['success'=>true,'url'=>$cronUrl,'msg'=>'Cron-URL generiert']);
    exit;
}

if ($action === 'get_key') {
    $key = getEncryptionKey();
    echo json_encode(['success'=>true,'key'=>$key,'msg'=>'Diesen Schlüssel sicher aufbewahren! Ohne ihn können Backups nicht wiederhergestellt werden.']);
    exit;
}

echo json_encode(['error'=>'Unbekannte Aktion']);

/* ============================================================
   PERFORM BACKUP
   ============================================================ */
function performBackup($type = 'manual') {
    global $backupDir;
    if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);

    $key = getEncryptionKey();
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "backup_{$type}_{$timestamp}.epbak";
    $filepath = "{$backupDir}/{$filename}";

    try {
        $db = getDB();
        $tables = [];
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) $tables[] = $row[0];

        $sql = "-- Einsatzplanung Encrypted Backup\n";
        $sql .= "-- Type: {$type} | Date: " . date('Y-m-d H:i:s') . " | DB: " . DB_NAME . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $create[1] . ";\n\n";

            $colsQ = $db->query("SHOW COLUMNS FROM `{$table}`");
            $cols = [];
            foreach ($colsQ->fetchAll(PDO::FETCH_ASSOC) as $col) $cols[] = '`'.$col['Field'].'`';
            $colStr = implode(',', $cols);

            $rows = $db->query("SELECT * FROM `{$table}`");
            $batch = [];
            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $k => $v) {
                    if (is_numeric($k)) continue;
                    $vals[] = ($v === null) ? 'NULL' : $db->quote($v);
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 100) {
                    $sql .= "INSERT INTO `{$table}` ({$colStr}) VALUES\n" . implode(",\n", $batch) . ";\n";
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                $sql .= "INSERT INTO `{$table}` ({$colStr}) VALUES\n" . implode(",\n", $batch) . ";\n";
            }
            $sql .= "\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        /* Encrypt and save */
        $encrypted = encryptData($sql, $key);
        file_put_contents($filepath, $encrypted);

        /* Rotation: keep max 10 */
        rotateBackups($backupDir, 10);

        return [
            'success' => true,
            'msg' => 'Backup erstellt: ' . $filename,
            'filename' => $filename,
            'size' => fmtBytes(filesize($filepath)),
            'encrypted' => true
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Backup fehlgeschlagen'];
    }
}
