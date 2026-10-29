<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\TestEnv;
use PDO;

/**
 * Sitzungen in der Datenbank (PQ_SESSION_STORE=db): zwei Testserver-Prozesse
 * mit derselben Datenbank simulieren zwei App-Container hinter einem Load
 * Balancer. Geprüft werden Login über beide Prozesse, Regenerate/Logout,
 * Strict Mode, Ablauf und Aufräumen, Sperren (Polling blockiert nicht),
 * gehashte IDs sowie die Mandantentrennung im SaaS-Modus inkl. Plattform-Admin.
 */
final class SessionStoreTest extends IntegrationTestCase
{
    private const ENV = ['PQ_SESSION_STORE' => 'db', 'PQ_SESSION_LOCK_TIMEOUT' => '2', 'PQ_SESSION_POLL_LOCK_TIMEOUT' => '0'];
    private static array $servers = [];
    private static array $saas = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$servers) {
            self::$servers = [TestEnv::startExtraServer(self::ENV), TestEnv::startExtraServer(self::ENV)];
        }
        $this->db()->exec('DELETE FROM login_attempts');
    }

    private function client(int $server = 0): Client
    {
        $c = new Client();
        $c->base = self::$servers[$server];
        return $c;
    }

    private function row(PDO $db, string $sid): ?array
    {
        $s = $db->prepare('SELECT * FROM sessions WHERE id_hash = ?');
        $s->execute([hash('sha256', $sid)]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function loginOk(Client $c, string $user, string $pw = TestEnv::PASSWORD): bool
    {
        $r = $c->login($user, $pw);
        return $r->status === 302 && $r->header('Location') === 'dashboard';
    }

    public function testDefaultStoreStaysFiles(): void
    {
        $before = (int)$this->db()->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        $c = Client::loggedIn('ma1');   /* Hauptserver ohne PQ_SESSION_STORE */
        $this->assertSame(200, $c->get('/dashboard')->status);
        $this->assertNull($this->row($this->db(), (string)$c->cookie('PHPSESSID')));
        $this->assertSame($before, (int)$this->db()->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
    }

    public function testSessionIsSharedBetweenTwoServerProcesses(): void
    {
        $c = $this->client(0);
        $this->assertTrue($this->loginOk($c, 'admin'));
        $this->assertSame(200, $c->get('/dashboard')->status);
        $sid = (string)$c->cookie('PHPSESSID');
        $this->assertNotSame('', $sid);
        $this->assertNotNull($this->row($this->db(), $sid), 'Sitzung liegt in der Datenbank');

        /* Zweiter "Container": gleiche Sitzung, CSRF-Token gilt dort ebenso */
        $c->base = self::$servers[1];
        $r = $c->get('/mitarbeiter');
        $this->assertSame(200, $r->status, 'Sitzung gilt im zweiten Prozess');
        $r = $c->postJson('/api/live_status', ['action' => 'ping']);
        $this->assertNotSame(403, $r->status, $r->body);
        $c->base = self::$servers[0];
        $this->assertSame(200, $c->get('/dashboard')->status);
    }

    public function testOnlyHashesAreStored(): void
    {
        $c = $this->client(1);
        $this->assertTrue($this->loginOk($c, 'mgr'));
        $sid = (string)$c->cookie('PHPSESSID');
        $rows = $this->db()->query('SELECT id_hash, data FROM sessions')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $r['id_hash']);
            $this->assertNotSame($sid, $r['id_hash']);
            $this->assertStringNotContainsString($sid, (string)$r['data']);
        }
        $this->assertNotNull($this->row($this->db(), $sid));
    }

    public function testRegenerateAndLogoutDeleteSessions(): void
    {
        $c = $this->client(0);
        $c->get('/');
        $pre = (string)$c->cookie('PHPSESSID');
        $this->assertNotNull($this->row($this->db(), $pre), 'Login-Seite legt eine Sitzung an (CSRF-Token)');
        $this->assertTrue($this->loginOk($c, 'tl'));
        $sid = (string)$c->cookie('PHPSESSID');
        $this->assertNotSame($pre, $sid, 'neue ID nach Login (Session Fixation)');
        $this->assertNull($this->row($this->db(), $pre), 'alte Sitzung gelöscht');
        $this->assertNotNull($this->row($this->db(), $sid));

        $c->base = self::$servers[1];
        $this->assertSame(302, $c->get('/logout')->status);
        $this->assertNull($this->row($this->db(), $sid), 'Logout löscht die Sitzung');

        /* Strict Mode: abgemeldete ID wird nicht wieder angenommen und nicht neu angelegt */
        $x = $this->client(0);
        $r = $x->get('/dashboard', ['Cookie: PHPSESSID=' . $sid]);
        $this->assertSame(302, $r->status);
        $this->assertNull($this->row($this->db(), $sid));
        $new = $x->cookie('PHPSESSID');
        $this->assertNotNull($new);
        $this->assertNotSame($sid, $new);
    }

    public function testUnknownIdIsReplacedInStrictMode(): void
    {
        $forged = str_repeat('a', 32);
        $x = $this->client(1);
        $x->get('/', ['Cookie: PHPSESSID=' . $forged]);
        $this->assertNull($this->row($this->db(), $forged), 'vom Client vorgegebene ID wird nicht übernommen');
        $this->assertNotSame($forged, $x->cookie('PHPSESSID'));
    }

    public function testExpiredSessionsAreRejectedAndCollected(): void
    {
        $c = $this->client(0);
        $this->assertTrue($this->loginOk($c, 'vw'));
        $sid = (string)$c->cookie('PHPSESSID');
        $old = time() - 90000;   /* älter als PQ_SESSION_LIFETIME (86400) */
        $this->db()->prepare('UPDATE sessions SET last_activity = ? WHERE id_hash = ?')->execute([$old, hash('sha256', $sid)]);
        $r = $c->get('/dashboard');
        $this->assertSame(302, $r->status, 'abgelaufene Sitzung gilt nicht');

        $this->db()->prepare('INSERT INTO sessions (id_hash, data, last_activity, created_at) VALUES (?, ?, ?, ?)')
            ->execute([hash('sha256', 'abgelaufen-' . microtime(true)), 'x|i:1;', $old, $old]);
        $fresh = Client::loggedIn('ma2');
        [$rc, $out] = TestEnv::runCli(['bin/scheduler.php', 'sessions-gc'], self::ENV);
        $this->assertSame(0, $rc, $out);
        $this->assertMatchesRegularExpression('/^default: [1-9]\d*$/m', $out);
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM sessions WHERE last_activity < " . (time() - 86400))->fetchColumn());
        $this->assertGreaterThan(0, (int)$this->db()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 'aktuelle Sitzungen bleiben');
        unset($fresh);
    }

    public function testLockSerialisesRequestsButPollingDoesNotBlock(): void
    {
        $c = $this->client(0);
        $this->assertTrue($this->loginOk($c, 'admin'));
        $c->get('/dashboard');
        $sid = (string)$c->cookie('PHPSESSID');
        $hash = hash('sha256', $sid);
        $lock = 'pq_sess_' . substr(hash('sha256', TestEnv::$db['name'] . ':' . $hash), 0, 48);
        $past = time() - 1000;
        $this->db()->prepare('UPDATE sessions SET last_activity = ? WHERE id_hash = ?')->execute([$past, $hash]);

        /* Ein "langer Request" hält die Sperre dieser Sitzung */
        $holder = TestEnv::pdo();
        $this->assertSame(1, (int)$holder->query('SELECT GET_LOCK(' . $holder->quote($lock) . ', 0)')->fetchColumn());
        try {
            $t = microtime(true);
            $r = $c->postJson('/api/live_status', ['action' => 'ping']);
            $this->assertLessThan(1.5, microtime(true) - $t, 'Polling wartet nicht auf die Sperre');
            $this->assertNotSame(403, $r->status, 'Polling liest die Sitzung trotzdem');

            $t = microtime(true);
            $r = $c->get('/dashboard');
            $waited = microtime(true) - $t;
            $this->assertGreaterThanOrEqual(1.8, $waited, 'normaler Request wartet auf die Sperre');
            $this->assertSame(200, $r->status, 'danach nur lesend weiter');
            $this->assertSame($past, (int)$this->row($this->db(), $sid)['last_activity'], 'ohne Sperre wird nicht zurückgeschrieben');
        } finally {
            $holder->query('SELECT RELEASE_LOCK(' . $holder->quote($lock) . ')')->fetchColumn();
        }
        $this->assertSame(200, $c->get('/dashboard')->status);
        $this->assertGreaterThan($past, (int)$this->row($this->db(), $sid)['last_activity']);
    }

    /* ---------------- SaaS ---------------- */

    private function saas(): array
    {
        if (self::$saas) return self::$saas;
        $root = TestEnv::rootPdo();
        $pf = TestEnv::$db['name'] . '_sst_platform';
        $prefix = TestEnv::$db['name'] . '_sst_';
        foreach ([$pf, $prefix . 'acme', $prefix . 'beta'] as $d) $root->exec("DROP DATABASE IF EXISTS `$d`");
        $root->exec("CREATE DATABASE `$pf` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $env = ['PQ_MODE' => 'saas', 'PQ_PLATFORM_DB_NAME' => $pf, 'PQ_TENANT_DB_PREFIX' => $prefix, 'PQ_PLATFORM_HOST' => 'api.sst.test'];
        [$rc, $out] = TestEnv::runCli(['bin/migrate.php', 'up', '--platform'], $env);
        $this->assertSame(0, $rc, $out);
        $pdo = fn(string $db) => new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', TestEnv::$db['host'], TestEnv::$db['port'], $db),
            TestEnv::$db['user'], TestEnv::$db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $dbs = ['platform' => $pdo($pf)];
        foreach (['acme', 'beta'] as $slug) {
            [$rc, $out] = TestEnv::runCli(['bin/tenant.php', 'create', $slug, ucfirst($slug), "--domain=$slug.sst.test"], $env);
            $this->assertSame(0, $rc, $out);
            $dbs[$slug] = $pdo($prefix . $slug);
            $dbs[$slug]->prepare("INSERT INTO users(username,password_hash,first_name,last_name,role,password_changed_at) VALUES('shared',?,'Geteilt',?,'admin',NOW())")
                ->execute([password_hash(TestEnv::PASSWORD, PASSWORD_DEFAULT), ucfirst($slug)]);
            $dbs[$slug]->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='captcha_enabled'");
        }
        $dbs['platform']->prepare('INSERT INTO platform_admins(username,password_hash) VALUES(?,?)')->execute(['pfadmin', password_hash(TestEnv::PASSWORD, PASSWORD_DEFAULT)]);
        $env += self::ENV;
        return self::$saas = ['env' => $env, 'db' => $dbs, 'url' => [TestEnv::startExtraServer($env), TestEnv::startExtraServer($env)]];
    }

    private function saasClient(string $host, int $server): Client
    {
        $c = new Client();
        $c->base = $this->saas()['url'][$server];
        $c->host = $host;
        return $c;
    }

    public function testSaasSessionsLiveInTenantDatabase(): void
    {
        $s = $this->saas();
        $c = $this->saasClient('acme.sst.test', 0);
        $this->assertTrue($this->loginOk($c, 'shared'));
        $sid = (string)$c->cookie('PQSID_acme');
        $this->assertNotSame('', $sid);
        $this->assertNotNull($this->row($s['db']['acme'], $sid), 'Sitzung in der Mandanten-DB');
        $this->assertNull($this->row($s['db']['beta'], $sid));
        $this->assertNull($this->row($s['db']['platform'], $sid));

        /* zweiter Prozess, gleicher Mandant */
        $c->base = $s['url'][1];
        $this->assertSame(200, $c->get('/mitarbeiter')->status);

        /* fremder Mandant: weder unter eigenem noch unter fremdem Cookie-Namen gültig */
        $c->host = 'beta.sst.test';
        $this->assertSame(302, $c->get('/mitarbeiter')->status);
        $b = $this->saasClient('beta.sst.test', 1);
        $r = $b->get('/mitarbeiter', ['Cookie: PQSID_beta=' . $sid]);
        $this->assertSame(302, $r->status, 'ID aus Mandant A gilt in B nicht');
        $this->assertNull($this->row($s['db']['beta'], $sid), 'und wird in B nicht angelegt');

        /* Aufräumen über alle Bereiche */
        [$rc, $out] = TestEnv::runCli(['bin/scheduler.php', 'sessions-gc'], $s['env']);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('acme: ', $out);
        $this->assertStringContainsString('beta: ', $out);
        $this->assertStringContainsString('_platform: ', $out);
        $this->assertNotNull($this->row($s['db']['acme'], $sid), 'gültige Sitzung bleibt');
    }

    public function testPlatformAdminSessionsLiveInPlatformDatabase(): void
    {
        $s = $this->saas();
        $c = $this->saasClient('api.sst.test', 0);
        $page = $c->get('/platform/');
        $this->assertSame(200, $page->status, $page->body);
        $r = $c->request('POST', '/platform/', http_build_query(['action' => 'login', 'username' => 'pfadmin', 'password' => TestEnv::PASSWORD, '_token' => $c->csrf]),
            ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertSame(302, $r->status, $r->body);
        $sid = (string)$c->cookie('PQPLATFORM');
        $this->assertNotSame('', $sid);
        $this->assertNotNull($this->row($s['db']['platform'], $sid), 'Plattform-Sitzung in der Plattform-DB');
        $this->assertNull($this->row($s['db']['acme'], $sid));

        $c->base = $s['url'][1];
        $page = $c->get('/platform/');
        $this->assertStringContainsString('?logout', $page->body, 'im zweiten Prozess angemeldet');
        $c->get('/platform/?logout=1');
        $this->assertNull($this->row($s['db']['platform'], $sid), 'Abmeldung löscht die Sitzung');
    }
}
