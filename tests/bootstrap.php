<?php
/**
 * Test-Bootstrap
 * --------------------------------------------------------------------------
 * Unit-Tests laufen ohne Datenbank. Integrationstests brauchen eine MariaDB/
 * MySQL-Instanz; Zugang über Umgebungsvariablen:
 *   PQ_TEST_DB_HOST (127.0.0.1), PQ_TEST_DB_PORT (3306),
 *   PQ_TEST_DB_USER (root), PQ_TEST_DB_PASS (''),
 *   PQ_TEST_DB_NAME (planquadro_test) – wird bei jedem Lauf NEU angelegt.
 * Ohne erreichbare Datenbank werden Integrationstests übersprungen.
 */
require __DIR__ . '/../vendor/autoload.php';

$cfg = [
    'host' => getenv('PQ_TEST_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('PQ_TEST_DB_PORT') ?: '3306',
    'user' => getenv('PQ_TEST_DB_USER') ?: 'root',
    'pass' => getenv('PQ_TEST_DB_PASS') !== false ? getenv('PQ_TEST_DB_PASS') : '',
    'name' => getenv('PQ_TEST_DB_NAME') ?: 'planquadro_test',
];
if (!preg_match('/^[a-z0-9_]*test[a-z0-9_]*$/', $cfg['name'])) {
    fwrite(STDERR, "PQ_TEST_DB_NAME muss 'test' enthalten (Schutz vor versehentlichem Löschen).\n");
    exit(1);
}
\Planquadro\Tests\Support\TestEnv::$db = $cfg;
\Planquadro\Tests\Support\TestEnv::$root = dirname(__DIR__);
