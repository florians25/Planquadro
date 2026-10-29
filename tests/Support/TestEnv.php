<?php
namespace Planquadro\Tests\Support;

use PDO;

/**
 * Gemeinsame Testumgebung: legt einmal pro Lauf die Testdatenbank an
 * (Basisschema + alle Migrationen über bin/migrate.php), spielt die
 * Testdaten ein und startet den eingebauten PHP-Webserver mit dem
 * Rewrite-Router.
 */
final class TestEnv
{
    public static array $db = [];
    public static string $root = '';
    private static ?PDO $pdo = null;
    private static $server = null;
    private static ?string $baseUrl = null;
    private static ?string $skipReason = null;
    private static bool $ready = false;

    public const PASSWORD = 'Test-Passwort-1';

    /** Feste Testbenutzer: Schlüssel => [Rolle, Vorgesetzter] */
    public const USERS = [
        'admin'   => ['admin', null],
        'mgr'     => ['manager', null],
        'tl'      => ['teamleiter', 'mgr'],
        'tl2'     => ['teamleiter', null],
        'vw'      => ['verwaltung', null],
        'ma1'     => ['mitarbeiter', 'tl'],
        'ma2'     => ['mitarbeiter', 'tl2'],
        'azubi'   => ['azubi_1', 'tl'],
        'lager'   => ['lager', null],
    ];

    public static function ensure(): void
    {
        if (self::$ready) return;
        if (self::$skipReason !== null) \PHPUnit\Framework\Assert::markTestSkipped(self::$skipReason);
        try {
            self::createDatabase();
        } catch (\PDOException $e) {
            self::$skipReason = 'Keine Test-Datenbank erreichbar: ' . $e->getMessage();
            \PHPUnit\Framework\Assert::markTestSkipped(self::$skipReason);
        }
        self::migrate();
        self::seed();
        self::startServer();
        self::$ready = true;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            $c = self::$db;
            self::$pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$pdo;
    }

    public static function baseUrl(): string { return self::$baseUrl; }

    public static function userId(string $key): int
    {
        $s = self::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$key]);
        return (int)$s->fetchColumn();
    }

    public static function env(): array
    {
        $c = self::$db;
        return [
            'PQ_DB_HOST' => $c['host'], 'PQ_DB_PORT' => (string)$c['port'],
            'PQ_DB_USER' => $c['user'], 'PQ_DB_PASS' => $c['pass'],
            'PQ_DB_NAME' => $c['name'], 'PQ_APP_KEY' => str_repeat('k', 64),
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];
    }

    private static function createDatabase(): void
    {
        $c = self::$db;
        $root = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $root->exec("DROP DATABASE IF EXISTS `{$c['name']}`");
        $root->exec("CREATE DATABASE `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private static function migrate(): void
    {
        $cmd = [PHP_BINARY, self::$root . '/bin/migrate.php', 'up'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$root, self::env());
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (proc_close($p) !== 0) throw new \RuntimeException("Migration fehlgeschlagen:\n$out");
    }

    private static function seed(): void
    {
        $db = self::pdo();
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $ins = $db->prepare('INSERT INTO users(username,password_hash,first_name,last_name,role,vacation_days,password_changed_at,active) VALUES(?,?,?,?,?,30,NOW(),1)');
        foreach (self::USERS as $key => [$role, $sup]) {
            $ins->execute([$key, $hash, ucfirst($key), 'Test', $role]);
        }
        $sup = $db->prepare('INSERT INTO user_supervisors(user_id,supervisor_id) VALUES(?,?)');
        foreach (self::USERS as $key => [$role, $boss]) {
            if ($boss) $sup->execute([self::userId($key), self::userId($boss)]);
        }
        /* Captcha aus, damit Tests mit Fehlversuchen deterministisch bleiben */
        $db->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='captcha_enabled'");
        $db->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('approval_system_enabled','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    }

    /** Startet einen weiteren Testserver mit eigener Umgebung (z. B. SaaS-Modus). */
    public static function startExtraServer(array $envOverrides, ?string $router = null): string
    {
        return self::spawnServer(self::env() + ['PHP_CLI_SERVER_WORKERS' => '2'], $envOverrides, $router);
    }

    private static array $extra = [];

    private static function spawnServer(array $env, array $over, ?string $router = null): string
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $log = sys_get_temp_dir() . '/planquadro-test-server-' . $port . '.log';
        $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', '-S', "127.0.0.1:$port", '-t', self::$root . '/public', $router ?? self::$root . '/tests/router.php'];
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, self::$root, array_merge($env, $over));
        self::$extra[] = $proc;
        for ($i = 0; $i < 100; $i++) { $fp = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($fp) { fclose($fp); break; } usleep(50000); }
        static $registered = false;
        if (!$registered) {
            $registered = true;
            register_shutdown_function(function () { foreach (self::$extra as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); } });
        }
        return "http://127.0.0.1:$port";
    }

    /** Root-Verbindung ohne Datenbank (zum Anlegen weiterer Test-DBs). */
    public static function rootPdo(): PDO
    {
        $c = self::$db;
        return new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    /** CLI-Skript mit Umgebung ausführen; liefert [exitcode, output]. */
    public static function runCli(array $args, array $envOverrides = []): array
    {
        $p = proc_open(array_merge([PHP_BINARY], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$root, array_merge(self::env(), $envOverrides));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }

    private static function startServer(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $log = sys_get_temp_dir() . '/planquadro-test-server.log';
        $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=1', '-S', "127.0.0.1:$port", '-t', self::$root . '/public', self::$root . '/tests/router.php'];
        $env = self::env() + ['PHP_CLI_SERVER_WORKERS' => '4'];
        self::$server = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, self::$root, $env);
        self::$baseUrl = "http://127.0.0.1:$port";
        for ($i = 0; $i < 100; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($fp) { fclose($fp); break; }
            usleep(50000);
        }
        register_shutdown_function(function () {
            if (is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
        });
    }
}
