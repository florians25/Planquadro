<?php
/**
 * Test-Bootstrap
 * --------------------------------------------------------------------------
 * Unit-Tests laufen ohne Datenbank. Integrationstests brauchen eine MariaDB/
 * MySQL-Instanz; Zugang über Umgebungsvariablen:
 *   OPSPILOT_TEST_DB_HOST (127.0.0.1), OPSPILOT_TEST_DB_PORT (3306),
 *   OPSPILOT_TEST_DB_USER (root), OPSPILOT_TEST_DB_PASS (''),
 *   OPSPILOT_TEST_DB_NAME (opspilot_test) – wird bei jedem Lauf NEU angelegt.
 * Ohne erreichbare Datenbank werden Integrationstests übersprungen.
 */
require __DIR__ . '/../vendor/autoload.php';

$cfg = [
    'host' => getenv('OPSPILOT_TEST_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('OPSPILOT_TEST_DB_PORT') ?: '3306',
    'user' => getenv('OPSPILOT_TEST_DB_USER') ?: 'root',
    'pass' => getenv('OPSPILOT_TEST_DB_PASS') !== false ? getenv('OPSPILOT_TEST_DB_PASS') : '',
    'name' => getenv('OPSPILOT_TEST_DB_NAME') ?: 'opspilot_test',
];
if (!preg_match('/^[a-z0-9_]*test[a-z0-9_]*$/', $cfg['name'])) {
    fwrite(STDERR, "OPSPILOT_TEST_DB_NAME muss 'test' enthalten (Schutz vor versehentlichem Löschen).\n");
    exit(1);
}
\OpsPilot\Tests\Support\TestEnv::$db = $cfg;
\OpsPilot\Tests\Support\TestEnv::$root = dirname(__DIR__);
