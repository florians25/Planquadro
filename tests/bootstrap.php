<?php
/**
 * Test-Bootstrap
 * --------------------------------------------------------------------------
 * Unit-Tests laufen ohne Datenbank. Integrationstests brauchen eine MariaDB/
 * MySQL-Instanz; Zugang über Umgebungsvariablen:
 *   VIREO_TEST_DB_HOST (127.0.0.1), VIREO_TEST_DB_PORT (3306),
 *   VIREO_TEST_DB_USER (root), VIREO_TEST_DB_PASS (''),
 *   VIREO_TEST_DB_NAME (vireo_test) – wird bei jedem Lauf NEU angelegt.
 * Ohne erreichbare Datenbank werden Integrationstests übersprungen.
 */
require_once __DIR__ . '/../app/lib/Env.php';   /* OPSPILOT_* → VIREO_* */
require __DIR__ . '/../vendor/autoload.php';

$cfg = [
    'host' => getenv('VIREO_TEST_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('VIREO_TEST_DB_PORT') ?: '3306',
    'user' => getenv('VIREO_TEST_DB_USER') ?: 'root',
    'pass' => getenv('VIREO_TEST_DB_PASS') !== false ? getenv('VIREO_TEST_DB_PASS') : '',
    'name' => getenv('VIREO_TEST_DB_NAME') ?: 'vireo_test',
];
if (!preg_match('/^[a-z0-9_]*test[a-z0-9_]*$/', $cfg['name'])) {
    fwrite(STDERR, "VIREO_TEST_DB_NAME muss 'test' enthalten (Schutz vor versehentlichem Löschen).\n");
    exit(1);
}
\Vireo\Tests\Support\TestEnv::$db = $cfg;
\Vireo\Tests\Support\TestEnv::$root = dirname(__DIR__);
