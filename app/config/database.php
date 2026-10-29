<?php
require_once __DIR__ . '/version.php';
/**
 * Planquadro – Datenbank-Konfiguration
 * --------------------------------------------------------------------------
 * In dieser Datei stehen KEINE Zugangsdaten. Sie werden in dieser Reihenfolge
 * gelesen (erste gefundene Quelle gewinnt):
 *   1. Umgebungsvariablen  PQ_DB_HOST, PQ_DB_PORT, PQ_DB_NAME,
 *                          PQ_DB_USER, PQ_DB_PASS (jeweils auch als
 *                          *_FILE für Docker-Secrets)
 *      (Docker/Compose, systemd, SetEnv im Webserver, Hosting-Panel)
 *   2. app/config/secrets.php  (Vorlage: secrets.sample.php, nie committen)
 *   3. Legacy-Variablen der Vorversion  CCKOE_DB_HOST/NAME/USER/PASS
 *      (nur damit bestehende Installationen beim Update weiterlaufen)
 *
 * Der Ordner app/ liegt außerhalb von public/ und ist nicht über das Web
 * erreichbar.
 */

require_once __DIR__ . '/../lib/Tenancy.php';

$__secrets = [];
if (is_readable(__DIR__ . '/secrets.php')) {
    $__secrets = require __DIR__ . '/secrets.php';
    if (!is_array($__secrets)) $__secrets = [];
}
$__cfg = function (string $env, string $key, string $fallback = '') use ($__secrets): string {
    $v = opsEnv('PQ_' . $env);   /* inkl. *_FILE (Docker-Secrets) */
    if ($v !== '') return $v;
    if (isset($__secrets[$key]) && $__secrets[$key] !== '') return (string)$__secrets[$key];
    $v = getenv('CCKOE_' . $env);
    if ($v !== false && $v !== '') return $v;
    return $fallback;
};

if (!defined('DB_HOST')) {
    define('DB_HOST',    $__cfg('DB_HOST', 'host', 'localhost'));
    define('DB_PORT',    $__cfg('DB_PORT', 'port', '3306'));
    define('DB_NAME',    $__cfg('DB_NAME', 'name', 'planquadro'));
    define('DB_USER',    $__cfg('DB_USER', 'user'));
    define('DB_PASS',    $__cfg('DB_PASS', 'pass'));
    define('DB_CHARSET', 'utf8mb4');
}
unset($__secrets, $__cfg);

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            /* Datenbank des aktuellen Mandanten (selfhosted: DB_NAME) */
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, (int)DB_PORT, opsTenantDbName(), DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                /* Keine persistenten Verbindungen: offene Transaktionen oder
                   Sperren eines abgebrochenen Requests dürfen nicht in den
                   nächsten Request desselben Workers hinüberwandern. */
                PDO::ATTR_PERSISTENT         => false,
            ]);
        } catch (PDOException $e) {
            /* Fehlermeldung nur ins Server-Log – sie kann Hostnamen und
               Benutzernamen enthalten. */
            if (function_exists('opsLog')) opsLog('Planquadro DB connection failed: ' . $e->getMessage());
            else error_log('Planquadro DB connection failed: ' . $e->getMessage());
            if (PHP_SAPI === 'cli') throw $e;
            /* JSON-Endpunkte (außer API v1 mit eigenem Format): einheitliches Fehlerformat */
            if (function_exists('opsExpectsJson') && opsExpectsJson() && !(defined('PQ_STATELESS') && PQ_STATELESS)) {
                opsJsonError(['success' => false, 'error' => 'Datenbankverbindung fehlgeschlagen.'], 503);
                exit;
            }
            http_response_code(503);
            die('<div style="font-family:sans-serif;padding:2rem;text-align:center">
                 <h2>Datenbankverbindung fehlgeschlagen</h2>
                 <p>Bitte die Datenbank-Konfiguration prüfen (siehe <code>docs/INSTALLATION.md</code>).</p></div>');
        }
    }
    return $pdo;
}
