<?php
/**
 * Readiness: Datenbank erreichbar und keine offenen Migrationen.
 * GET /api/readyz → 200 {"status":"ready","checks":{…}} oder 503 {"status":"not_ready",…}
 *
 * Selfhosted: prüft OPSPILOT_DB_NAME.
 * SaaS:       prüft die Plattform-Datenbank; gehört der Host zu einem Mandanten
 *             (tenant_domains), zusätzlich dessen Datenbank. Unbekannte Hosts
 *             (z. B. interne Healthchecks des Load Balancers) prüfen nur die Plattform.
 * Bewusst ohne Details (keine Hostnamen, Versionen, Fehlermeldungen); Fehler
 * stehen im Server-Log.
 */
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/lib/Migrations.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function readyPdo(string $host, string $port, string $db, string $user, string $pass): PDO
{
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int)$port, $db), $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_PERSISTENT => false,
    ]);
}

$checks = [];
$ok = true;
$check = function (string $name, callable $fn) use (&$checks, &$ok) {
    try { $r = $fn(); }
    catch (Throwable $e) { error_log("OpsPilot readyz $name: " . $e->getMessage()); $r = 'error'; }
    $checks[$name] = $r;
    if ($r !== 'ok') $ok = false;
    return $r;
};

if (opsMode() === 'saas') {
    $pdb = null;
    $check('platform_database', function () use (&$pdb) {
        $pdb = readyPdo(opsEnv('OPSPILOT_PLATFORM_DB_HOST', DB_HOST), opsEnv('OPSPILOT_PLATFORM_DB_PORT', DB_PORT), opsPlatformDbName(),
            opsEnv('OPSPILOT_PLATFORM_DB_USER', DB_USER), opsEnv('OPSPILOT_PLATFORM_DB_PASS', DB_PASS));
        $pdb->query('SELECT 1');
        return 'ok';
    });
    if ($pdb) {
        $check('platform_migrations', fn() => opsPendingMigrations($pdb, true) ? 'pending' : 'ok');
        $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        $tenant = null;
        try {
            $s = $pdb->prepare('SELECT t.db_name, t.status FROM tenant_domains d JOIN tenants t ON t.id = d.tenant_id WHERE d.host = ?');
            $s->execute([$host]);
            $tenant = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { $check('tenant_lookup', function () use ($e) { throw $e; }); }
        if ($tenant) {
            $tdb = null;
            $check('database', function () use ($tenant, &$tdb) {
                if ($tenant['status'] !== 'active') return 'unavailable';
                $tdb = readyPdo(DB_HOST, DB_PORT, $tenant['db_name'], DB_USER, DB_PASS);
                return 'ok';
            });
            if ($tdb) $check('migrations', fn() => opsPendingMigrations($tdb) ? 'pending' : 'ok');
        }
    }
} else {
    $db = null;
    $check('database', function () use (&$db) { $db = readyPdo(DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS); $db->query('SELECT 1'); return 'ok'; });
    if ($db) $check('migrations', fn() => opsPendingMigrations($db) ? 'pending' : 'ok');
}

http_response_code($ok ? 200 : 503);
echo json_encode(['status' => $ok ? 'ready' : 'not_ready', 'checks' => $checks]);
