<?php
/**
 * Planquadro – Container-Start (vom Entrypoint aufgerufen)
 * --------------------------------------------------------------------------
 *   php docker/bootstrap.php wait-db     wartet bis zu PQ_DB_WAIT_SECONDS (120) auf die Datenbank
 *   php docker/bootstrap.php migrate     Migrationen unter einer serverweiten Sperre
 *
 * migrate:
 *   selfhosted  php bin/migrate.php up
 *   saas        php bin/migrate.php up --platform, danach up --all-tenants
 * Die Sperre (GET_LOCK) gilt für die ganze Datenbankinstanz, sodass mehrere
 * gleichzeitig startende Container nicht parallel migrieren.
 *
 * Bestehende Altinstallation ohne Versionstabelle (Einsatzplanung ≤ v6.9.0):
 * Es wird NICHT automatisch "baseline" ausgeführt, weil die Alt-Migrationen
 * nicht wiederholbar sind. Erst wenn PQ_AUTO_BASELINE=<version> (z. B.
 * v6_9_0) ausdrücklich gesetzt ist, wird einmalig baseline <version> ausgeführt.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/config/database.php';

$cmd = $argv[1] ?? '';
$root = dirname(__DIR__);
function info(string $s): void { fwrite(STDOUT, "[planquadro] $s\n"); }
function abort(string $s, int $c = 1): void { fwrite(STDERR, "[planquadro] FEHLER: $s\n"); exit($c); }

function serverPdo(bool $platform = false): PDO
{
    $host = $platform ? opsEnv('PQ_PLATFORM_DB_HOST', DB_HOST) : DB_HOST;
    $port = $platform ? opsEnv('PQ_PLATFORM_DB_PORT', DB_PORT) : DB_PORT;
    $user = $platform ? opsEnv('PQ_PLATFORM_DB_USER', DB_USER) : DB_USER;
    $pass = $platform ? opsEnv('PQ_PLATFORM_DB_PASS', DB_PASS) : DB_PASS;
    $db = $platform ? opsPlatformDbName() : DB_NAME;
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int)$port, $db), $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
}

function migrateCmd(array $args): int
{
    global $root;
    $p = proc_open(array_merge([PHP_BINARY, $root . '/bin/migrate.php'], $args), [1 => STDOUT, 2 => STDERR], $pipes);
    return proc_close($p);
}

if ($cmd === 'wait-db') {
    $limit = max(1, (int)opsEnv('PQ_DB_WAIT_SECONDS', '120'));
    $saas = opsMode() === 'saas';
    $start = time(); $last = '';
    while (true) {
        try { serverPdo($saas)->query('SELECT 1'); info('Datenbank erreichbar.'); exit(0); }
        catch (PDOException $e) {
            $last = $e->getMessage();
            /* Zugangsdaten falsch: Warten hilft nicht */
            if (in_array((int)($e->errorInfo[1] ?? 0), [1044, 1045], true) || strpos($last, '[1045]') !== false) abort('Anmeldung an der Datenbank abgelehnt – Zugangsdaten prüfen (PQ_DB_USER/PQ_DB_PASS[_FILE]).');
        }
        if (time() - $start >= $limit) abort("Datenbank nach {$limit}s nicht erreichbar: $last");
        sleep(2);
    }
}

if ($cmd === 'migrate') {
    $saas = opsMode() === 'saas';
    $pdo = serverPdo($saas);
    $wait = max(10, (int)opsEnv('PQ_MIGRATE_LOCK_SECONDS', '600'));
    info('Warte auf Migrationssperre …');
    if ((int)$pdo->query("SELECT GET_LOCK('planquadro_container_migrate', $wait)")->fetchColumn() !== 1) abort('Migrationssperre nicht erhalten (läuft ein anderer Container noch?).');
    try {
        if ($saas) {
            info('Plattform-Datenbank migrieren');
            if (migrateCmd(['up', '--platform']) !== 0) abort('Migration der Plattform-Datenbank fehlgeschlagen.');
            info('Mandanten-Datenbanken migrieren');
            if (migrateCmd(['up', '--all-tenants']) !== 0) abort('Migration der Mandanten fehlgeschlagen (Details oben).');
        } else {
            $tables = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t))->fetchColumn() > 0;
            if ($tables('users') && !$tables('schema_migrations')) {
                $base = opsEnv('PQ_AUTO_BASELINE');
                if ($base === '') {
                    abort("Bestehende Installation ohne Versionstabelle erkannt (Einsatzplanung ≤ v6.9.0).\n"
                        . "  Die Alt-Migrationen werden nicht automatisch markiert. Vorgehen (docs/MIGRATION_PLAN.md):\n"
                        . "  1. Backup erstellen (Alt-Installation oder: docker compose run --rm --no-deps backup php bin/backup.php run)\n"
                        . "  2. Zuletzt eingespielte Altversion bestimmen (z. B. v6_9_0)\n"
                        . "  3. PQ_AUTO_BASELINE=v6_9_0 setzen und Container neu starten – oder manuell:\n"
                        . "     docker compose run --rm --no-deps app php bin/migrate.php baseline v6_9_0", 3);
                }
                if (!preg_match('/^v\d+(_\d+)*$/', $base)) abort('PQ_AUTO_BASELINE muss eine Version wie v6_9_0 sein.');
                info("PQ_AUTO_BASELINE=$base: markiere Alt-Migrationen bis $base als eingespielt");
                if (migrateCmd(['baseline', $base]) !== 0) abort('baseline fehlgeschlagen.');
            }
            if (migrateCmd(['up']) !== 0) abort('Migration fehlgeschlagen (Details oben). Die Anwendung wird nicht gestartet.');
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('planquadro_container_migrate')");
    }
    exit(0);
}

abort('Aufruf: php docker/bootstrap.php wait-db|migrate', 2);
