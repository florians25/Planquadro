<?php
namespace Vireo\Tests\Integration;

use Vireo\Tests\Support\Client;
use Vireo\Tests\Support\IntegrationTestCase;
use Vireo\Tests\Support\TestEnv;

require_once __DIR__ . '/../../app/lib/Backup.php';

/**
 * Backup-System (B-01…B-05): bin/backup.php gegen die Testdatenbank –
 * Manifest, Round-Trip in eine frische Datenbank, Schutz der Zieldatenbank,
 * Restore-Test, Status, Web-API und Dashboard-Hinweis, SaaS-Bereiche.
 */
final class BackupTest extends IntegrationTestCase
{
    private static ?array $env = null;
    private static string $dir = '';
    private static array $dropDbs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$env) return;
        self::$dir = sys_get_temp_dir() . '/vireo-backup-test-' . bin2hex(random_bytes(4));
        mkdir(self::$dir . '/storage/default/branding', 0700, true);
        file_put_contents(self::$dir . '/storage/default/branding/logo.png', random_bytes(2048));
        file_put_contents(self::$dir . '/storage/default/notiz.txt', "Mandantendatei\n");
        $k = \BackupCrypto::keygen();
        self::$env = [
            'VIREO_STORAGE_DIR' => self::$dir . '/storage', 'VIREO_BACKUP_DIR' => self::$dir . '/backups',
            'VIREO_BACKUP_PUBLIC_KEY' => $k['public'], 'VIREO_BACKUP_PRIVATE_KEY' => $k['secret'],
            'VIREO_BACKUP_TEMP_DB_PREFIX' => TestEnv::$db['name'] . '_rt_', 'VIREO_BACKUP_TMP_DIR' => self::$dir . '/tmp',
        ];
        register_shutdown_function(function () {
            try { $r = TestEnv::rootPdo(); foreach (self::$dropDbs as $d) $r->exec("DROP DATABASE IF EXISTS `$d`"); } catch (\Throwable $e) {}
            \Backup::rmTree(self::$dir);
        });
    }

    private function cli(array $args, array $env = []): array
    {
        return TestEnv::runCli(array_merge(['bin/backup.php'], $args), array_merge(self::$env, $env));
    }

    private function counts(string $db): array
    {
        $c = TestEnv::$db;
        $r = \BackupDb::rowCounts(\BackupDb::pdo(['host' => $c['host'], 'port' => $c['port'], 'user' => $c['user'], 'pass' => $c['pass']], $db));
        ksort($r);
        return $r;
    }

    private function newDbName(string $tag): string
    {
        $n = TestEnv::$db['name'] . '_' . $tag . '_' . bin2hex(random_bytes(3));
        self::$dropDbs[] = $n;
        return $n;
    }

    private function latestArchive(): string
    {
        $f = glob(self::$dir . '/backups/default/*.opbak');
        sort($f);
        $this->assertNotEmpty($f, 'kein Archiv');
        return end($f);
    }

    public static function dumpers(): array
    {
        return ['php' => ['php'], 'native' => ['native']];
    }

    /** @dataProvider dumpers */
    public function testBackupRestoreRoundTripPreservesEveryRow(string $dumper): void
    {
        if ($dumper === 'native' && !\BackupDumper::nativeBinary((string)$this->db()->query('SELECT VERSION()')->fetchColumn())) {
            $this->markTestSkipped('kein zum Server passendes mariadb-dump/mysqldump installiert (Auto-Modus nutzt dann den PHP-Dumper)');
        }
        /* Daten mit Sonderzeichen, Binär- und NULL-Werten */
        $this->db()->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('backup_test_$dumper', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
             ->execute(["Zeile1\nZeile2 'quote' \\backslash; (),( ümlaut 🚀 \0 null"]);
        $env = ['VIREO_BACKUP_DUMPER' => $dumper];
        [$rc, $out] = $this->cli(['run', '--kind=manual'], $env);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('3-2-1', $out, 'Warnung ohne externes Ziel');
        $archive = $this->latestArchive();
        $this->assertStringNotContainsString('backup_test_', (string)file_get_contents($archive), 'Archiv ist verschlüsselt');

        /* Manifest */
        $tmp = self::$dir . '/m-' . bin2hex(random_bytes(3));
        $m = (new \Backup())->decryptTo($archive, \BackupCrypto::parseSecret(self::$env['VIREO_BACKUP_PRIVATE_KEY']), $tmp);
        \Backup::rmTree($tmp);
        $this->assertSame('vireo-backup', $m['format']);
        $this->assertSame(1, $m['version']);
        $this->assertSame('default', $m['scope']);
        $this->assertSame(TestEnv::$db['name'], $m['database']['name']);
        $this->assertSame($dumper === 'php' ? 'php' : true, $dumper === 'php' ? $m['database']['dump_method'] : in_array($m['database']['dump_method'], ['mariadb-dump', 'mysqldump'], true));
        $this->assertSame($this->counts(TestEnv::$db['name']), $m['database']['tables'], 'Zeilenzahlen im Manifest = Quelle');
        $this->assertContains('op_0004_master_data', $m['database']['schema_migrations']);
        $this->assertSame(['included' => true, 'count' => 2, 'bytes' => 2048 + 15], $m['files']);
        $this->assertSame(['db.sql.gz', 'files.tar.gz'], array_column($m['parts'], 'name'));
        foreach ($m['parts'] as $p) $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $p['sha256']);
        $this->assertSame(\BackupCrypto::SCHEME, $m['encryption']['scheme']);

        /* verify */
        [$rc, $out] = $this->cli(['verify', basename($archive)]);
        $this->assertSame(0, $rc, $out);

        /* Restore in eine neue Datenbank, inkl. Dateien */
        $target = $this->newDbName('rst');
        $filesDir = self::$dir . '/restored-' . $dumper;
        [$rc, $out] = $this->cli(['restore', $archive, "--target-db=$target", '--files', "--files-dir=$filesDir"], $env);
        $this->assertSame(0, $rc, $out);
        $this->assertSame($this->counts(TestEnv::$db['name']), $this->counts($target), 'alle Tabellen mit gleicher Zeilenzahl');
        $c = TestEnv::$db;
        $tp = new \PDO("mysql:host={$c['host']};port={$c['port']};dbname=$target;charset=utf8mb4", $c['user'], $c['pass']);
        $s = $tp->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
        $s->execute(["backup_test_$dumper"]);
        $this->assertSame("Zeile1\nZeile2 'quote' \\backslash; (),( ümlaut 🚀 \0 null", $s->fetchColumn(), 'Werte bitgenau');
        $this->assertSame(file_get_contents(self::$dir . '/storage/default/branding/logo.png'), file_get_contents("$filesDir/branding/logo.png"));
        $this->db()->exec("DELETE FROM app_settings WHERE setting_key LIKE 'backup_test_%'");
    }

    public function testRestoreRefusesNonEmptyTargetWithoutReplace(): void
    {
        [$rc, $out] = $this->cli(['run']);
        $this->assertSame(0, $rc, $out);
        $archive = $this->latestArchive();
        $before = $this->counts(TestEnv::$db['name']);
        /* Produktivdatenbank als Ziel: abgelehnt, nichts verändert */
        [$rc, $out] = $this->cli(['restore', $archive, '--target-db=' . TestEnv::$db['name']]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('nicht leer', $out);
        [$rc, $out] = $this->cli(['restore', $archive, '--target-db=' . TestEnv::$db['name'], '--replace']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('--yes', $out);
        $this->assertSame($before, $this->counts(TestEnv::$db['name']));
        /* ohne privaten Schlüssel kein Restore */
        [$rc, $out] = $this->cli(['restore', $archive, '--target-db=x'], ['VIREO_BACKUP_PRIVATE_KEY' => '']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('Privater Schlüssel fehlt', $out);
        /* manipuliertes Archiv: Abbruch vor jeder Änderung */
        $bad = self::$dir . '/' . basename($archive);
        $data = file_get_contents($archive);
        $data[(int)(strlen($data) / 2)] = chr(ord($data[(int)(strlen($data) / 2)]) ^ 0x40);
        file_put_contents($bad, $data);
        $target = $this->newDbName('bad');
        [$rc, $out] = $this->cli(['restore', $bad, "--target-db=$target"]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('manipuliert', $out);
        $this->assertFalse(\BackupDb::exists(TestEnv::rootPdo(), $target));
    }

    public function testReplaceSwapsAtomicallyAfterSafetyBackup(): void
    {
        /* Kopie der Testdatenbank als "Produktion", damit andere Tests unberührt bleiben */
        $prod = $this->newDbName('prod');
        $root = TestEnv::rootPdo();
        $root->exec("CREATE DATABASE `$prod`");
        [$rc, $out] = $this->cli(['run']);
        $this->assertSame(0, $rc, $out);
        $archive = $this->latestArchive();
        [$rc, $out] = $this->cli(['restore', $archive, "--target-db=$prod"]);
        $this->assertSame(0, $rc, $out);
        $root->exec("DELETE FROM `$prod`.app_settings");
        $root->exec("CREATE TABLE `$prod`.zz_extra (id INT)");
        [$rc, $out] = $this->cli(['restore', $archive, "--target-db=$prod", '--replace', '--yes']);
        $this->assertSame(0, $rc, $out);
        $res = json_decode(substr($out, strpos($out, "{\n"), strrpos($out, "}") - strpos($out, "{\n") + 1), true);
        $this->assertNotEmpty($res['safety_backup'], $out);
        $this->assertFileExists(self::$dir . '/backups/' . $res['safety_backup']);
        $this->assertSame($this->counts(TestEnv::$db['name']), $this->counts($prod));
        self::$dropDbs[] = $res['previous_db'];
        $this->assertArrayHasKey('zz_extra', $this->counts($res['previous_db']), 'alter Stand bleibt zum Zurückrollen erhalten');
        $this->assertSame(0, $this->counts($res['previous_db'])['app_settings']);
        $root->exec("DROP DATABASE `{$res['previous_db']}`");
    }

    public function testRestoreTestAndStatus(): void
    {
        [$rc, $out] = $this->cli(['run']);
        $this->assertSame(0, $rc, $out);
        [$rc, $out] = $this->cli(['restore-test']);
        $this->assertSame(0, $rc, $out);
        $this->assertMatchesRegularExpression('/default\s+OK .*Zeilen identisch, Migrationen identisch, 2 Dateien ok/', $out);
        $left = TestEnv::rootPdo()->query("SHOW DATABASES LIKE '" . TestEnv::$db['name'] . "\\_rt\\_%'")->fetchAll();
        $this->assertSame([], $left, 'Wegwerf-Datenbank wurde gelöscht');

        [$rc, $out] = $this->cli(['status']);
        $this->assertSame(0, $rc, $out);
        $st = json_decode($out, true);
        $this->assertTrue($st['encryption']);
        $this->assertFalse($st['external_target']);
        $this->assertStringContainsString('3-2-1', implode(' ', $st['warnings']));
        $s = $st['scopes']['default'];
        $this->assertTrue($s['healthy'], $out);
        $this->assertLessThan(1, $s['age_hours']);
        $this->assertSame('ok', $s['last_restore_test']['status']);
        $this->assertFileExists(self::$dir . '/storage/_backup/status.json');

        /* ohne Schlüssel: kein unverschlüsseltes Backup, Lauf als Fehler protokolliert */
        [$rc, $out] = $this->cli(['run'], ['VIREO_BACKUP_PUBLIC_KEY' => '']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('Unverschlüsselte Backups werden nicht erstellt', $out);
        [$rc, $out] = $this->cli(['status', '--check'], ['VIREO_BACKUP_PUBLIC_KEY' => '']);
        $this->assertSame(3, $rc);
        $st = json_decode($out, true);
        $this->assertSame('failed', $st['scopes']['default']['last_run']['status']);
    }

    public function testFailureTriggersWebhookAlert(): void
    {
        $hookLog = self::$dir . '/hook.log';
        $hookDir = self::$dir . '/hook';
        @mkdir($hookDir);
        file_put_contents("$hookDir/index.php", '<?php file_put_contents(' . var_export($hookLog, true) . ', file_get_contents("php://input") . "\n", FILE_APPEND); echo "ok";');
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $hookDir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
        try {
            $env = ['VIREO_ALERT_WEBHOOK' => "http://127.0.0.1:$port/", 'VIREO_DB_NAME' => TestEnv::$db['name'] . '_gibt_es_nicht', 'no_proxy' => '*'];
            [$rc] = $this->cli(['run'], $env);
            $this->assertSame(1, $rc);
            [$rc] = $this->cli(['run'], $env);   /* zweiter Fehler: kein zweiter Alarm innerhalb der Wiederholsperre */
            $lines = array_filter(explode("\n", (string)@file_get_contents($hookLog)));
            $this->assertCount(1, $lines);
            $a = json_decode(reset($lines), true);
            $this->assertSame('failed:default', $a['event']);
            $this->assertSame('Backup fehlgeschlagen', $a['subject']);
        } finally {
            proc_terminate($p); proc_close($p);
            @unlink(self::$dir . '/storage/_backup/alerts.json');
        }
    }

    public function testWebApiAndDashboardBanner(): void
    {
        $url = TestEnv::startExtraServer(array_merge(self::$env, ['VIREO_BACKUP_DUMPER' => 'php']));
        $admin = new Client(); $admin->base = $url;
        $admin->login('admin', TestEnv::PASSWORD);
        $dash = $admin->get('/dashboard');
        $admin->csrf = preg_match('/name="csrf-token" content="([^"]+)"/', $dash->body, $m) ? $m[1] : null;

        $r = $admin->postJson('/api/backup', ['action' => 'create']);
        $this->assertSame(200, $r->status, $r->body);
        $d = json_decode($r->body, true);
        $this->assertTrue($d['success'], $r->body);
        $this->assertSame(['local'], $d['targets']);

        $r = $admin->postJson('/api/backup', ['action' => 'status']);
        $d = json_decode($r->body, true);
        $this->assertTrue($d['success']);
        $this->assertNotNull($d['last_success']);
        $this->assertFalse($d['external_target']);
        $this->assertNotEmpty($d['backups']);
        $name = $d['backups'][0]['name'];
        $this->assertStringNotContainsString('PRIVATE', $r->body);
        $this->assertStringNotContainsString('vireo-backup-sk', $r->body);

        $r = $admin->get('/api/backup?action=download&name=' . rawurlencode($name));
        $this->assertSame(200, $r->status);
        $this->assertStringStartsWith(\BackupCrypto::MAGIC, $r->body);
        $this->assertSame(404, $admin->get('/api/backup?action=download&name=vireo_default_20000101-000000Z_manual.opbak')->status);
        $this->assertSame(400, $admin->get('/api/backup?action=download&name=..%2F..%2Fapp%2Fconfig%2Fsecrets.php')->status);

        /* Entfernte, unsichere Funktionen */
        foreach (['get_key', 'setup_cron', 'restore', 'delete'] as $a) {
            $this->assertSame(410, $admin->postJson('/api/backup', ['action' => $a, 'name' => $name])->status, $a);
        }
        $anon = new Client(); $anon->base = $url;
        $this->assertSame(403, $anon->get('/api/backup?action=cron_backup&key=x')->status);
        /* CSRF weiterhin Pflicht */
        $admin->sendCsrf = false;
        $this->assertSame(403, $admin->postJson('/api/backup', ['action' => 'create'])->status);
        $admin->sendCsrf = true;
        /* Recht manage_settings */
        $ma = new Client(); $ma->base = $url; $ma->login('ma1', TestEnv::PASSWORD); $ma->get('/dashboard');
        $this->assertSame(403, $ma->postJson('/api/backup', ['action' => 'status'])->status);

        /* Dashboard: aktuell → kein Hinweis; Einstellungen zeigen den neuen Backup-Bereich */
        $this->assertStringNotContainsString('id="backupBanner"', $admin->get('/dashboard')->body);
        $this->assertStringContainsString('backupState', $admin->get('/einstellungen')->body);
        $this->assertStringNotContainsString('id="backupBanner"', $ma->get('/dashboard')->body);

        /* Hinweis, wenn das letzte Backup zu alt ist */
        $old = TestEnv::startExtraServer(array_merge(self::$env, ['VIREO_BACKUP_MAX_AGE_HOURS' => '1', 'VIREO_STORAGE_DIR' => self::$dir . '/storage-old']));
        @mkdir(self::$dir . '/storage-old/_backup', 0700, true);
        file_put_contents(self::$dir . '/storage-old/_backup/status.json', json_encode(['runs' => [[
            'id' => 'x', 'scope' => 'default', 'kind' => 'scheduled', 'status' => 'ok', 'started_at' => gmdate('c', time() - 30 * 3600),
            'finished_at' => gmdate('c', time() - 30 * 3600), 'archive' => 'default/x.opbak']]]));
        $a2 = new Client(); $a2->base = $old; $a2->login('admin', TestEnv::PASSWORD);
        $body = $a2->get('/dashboard')->body;
        $this->assertStringContainsString('id="backupBanner"', $body);
        $this->assertStringContainsString('30 Stunden alt', $body);
    }

    public function testSaasBacksUpEveryTenantAndPlatformAndRecordsRuns(): void
    {
        $root = TestEnv::rootPdo();
        $pf = TestEnv::$db['name'] . '_bkpf';
        $prefix = TestEnv::$db['name'] . '_bkt_';
        foreach ([$pf, $prefix . 'one'] as $d) { $root->exec("DROP DATABASE IF EXISTS `$d`"); self::$dropDbs[] = $d; }
        $root->exec("CREATE DATABASE `$pf`");
        $saas = ['VIREO_MODE' => 'saas', 'VIREO_PLATFORM_DB_NAME' => $pf, 'VIREO_TENANT_DB_PREFIX' => $prefix];
        [$rc, $out] = TestEnv::runCli(['bin/migrate.php', 'up', '--platform'], $saas);
        $this->assertSame(0, $rc, $out);
        [$rc, $out] = TestEnv::runCli(['bin/tenant.php', 'create', 'one', 'Eins GmbH'], $saas);
        $this->assertSame(0, $rc, $out);
        $env = $saas + ['VIREO_BACKUP_DUMPER' => 'php', 'VIREO_BACKUP_DIR' => self::$dir . '/saas-backups'];
        [$rc, $out] = $this->cli(['run'], $env);
        $this->assertSame(0, $rc, $out);
        $this->assertMatchesRegularExpression('/^one\s+WARNING\s+one\/vireo_one_/m', $out);
        $this->assertMatchesRegularExpression('/^_platform\s+WARNING\s+_platform\/vireo__platform_/m', $out);
        $runs = $root->query("SELECT scope, kind, status, archive, sha256 FROM `$pf`.backup_runs ORDER BY id")->fetchAll();
        $this->assertSame(['one', '_platform'], array_column($runs, 'scope'));
        $this->assertSame(['warning', 'warning'], array_column($runs, 'status'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $runs[0]['sha256']);
        /* Restore genau eines Mandanten in eine neue Datenbank */
        $target = $this->newDbName('one_rst');
        [$rc, $out] = $this->cli(['restore', basename($runs[0]['archive']), "--target-db=$target", '--tenant=one'], $env);
        $this->assertSame(0, $rc, $out);
        $this->assertSame($this->counts($prefix . 'one'), $this->counts($target));
        [$rc, $out] = $this->cli(['restore', basename($runs[1]['archive']), "--target-db=$target", '--tenant=one'], $env);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString("gehört zu '_platform'", $out);
        [$rc, $out] = $this->cli(['restore-test', '--tenant=one'], $env);
        $this->assertSame(0, $rc, $out);
        $this->assertSame('ok', $root->query("SELECT status FROM `$pf`.backup_runs WHERE kind='restore_test' ORDER BY id DESC LIMIT 1")->fetchColumn());
    }
}
