<?php
/**
 * OpsPilot – Push-Versand
 *   php bin/push-worker.php            einmal abarbeiten
 *   php bin/push-worker.php --loop     dauerhaft (alle 15 s), z. B. als Docker-Dienst
 *   --all-tenants                      im SaaS-Modus alle Mandanten nacheinander
 * Anbieter: FCM (OPSPILOT_FCM_SERVICE_ACCOUNT_FILE), APNs (OPSPILOT_APNS_KEY_FILE,
 * _KEY_ID, _TEAM_ID, _BUNDLE_ID, _SANDBOX), ntfy (OPSPILOT_NTFY_URL, _TOKEN).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['loop', 'all-tenants', 'tenant:']);
if (!empty($opts['tenant'])) putenv('OPSPILOT_TENANT=' . $opts['tenant']);
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/lib/Push.php';

function runOnce(): void
{
    if (isset($GLOBALS['opts']['all-tenants']) && opsMode() === 'saas') {
        foreach (opsPlatformDB()->query("SELECT slug FROM tenants WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $p = proc_open([PHP_BINARY, __FILE__, '--tenant=' . $slug], [1 => STDOUT, 2 => STDERR], $pipes);
            proc_close($p);
        }
        return;
    }
    [$sent, $failed] = pushProcessQueue(getDB());
    if ($sent || $failed) fwrite(STDOUT, date('c') . " gesendet=$sent fehlgeschlagen=$failed\n");
}

do {
    try { runOnce(); } catch (Throwable $e) { fwrite(STDERR, date('c') . ' Fehler: ' . $e->getMessage() . "\n"); }
    if (isset($opts['loop'])) sleep(15);
} while (isset($opts['loop']));
