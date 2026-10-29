<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\TestEnv;
use PDO;

/**
 * Mandantenfähigkeit (SaaS-Modus): Auflösung per Host, Isolation von Daten
 * und Sessions, gesperrte/unbekannte Mandanten, Mandanten-CLI und
 * Migration über alle Mandanten.
 */
final class TenancyTest extends IntegrationTestCase
{
    private static ?string $url = null;
    private static array $saasEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$url) return;
        $root = TestEnv::rootPdo();
        $pf = TestEnv::$db['name'] . '_platform';
        $prefix = TestEnv::$db['name'] . '_t_';
        foreach ([$pf, $prefix . 'acme', $prefix . 'beta'] as $d) $root->exec("DROP DATABASE IF EXISTS `$d`");
        $root->exec("CREATE DATABASE `$pf` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        self::$saasEnv = ['PQ_MODE' => 'saas', 'PQ_PLATFORM_DB_NAME' => $pf, 'PQ_TENANT_DB_PREFIX' => $prefix,
            'PQ_PLATFORM_HOST' => 'api.planquadro.test'];
        [$rc, $out] = TestEnv::runCli(['bin/migrate.php', 'up', '--platform'], self::$saasEnv);
        $this->assertSame(0, $rc, $out);
        foreach (['acme' => 'Acme GmbH', 'beta' => 'Beta AG'] as $slug => $name) {
            [$rc, $out] = TestEnv::runCli(['bin/tenant.php', 'create', $slug, $name, "--domain=$slug.planquadro.test"], self::$saasEnv);
            $this->assertSame(0, $rc, $out);
            $this->assertStringContainsString('Einmalpasswort', $out);
        }
        /* Gleicher Benutzername in beiden Mandanten, unterschiedliche Rollen/Passwörter */
        foreach (['acme' => ['admin', 'Acme-Passwort-1'], 'beta' => ['mitarbeiter', 'Beta-Passwort-1']] as $slug => [$role, $pw]) {
            $t = $this->tenantPdo($slug);
            $t->prepare("INSERT INTO users(username,password_hash,first_name,last_name,role,password_changed_at) VALUES('shared',?,'Geteilt',?,?,NOW())")
              ->execute([password_hash($pw, PASSWORD_DEFAULT), ucfirst($slug), $role]);
            $t->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='captcha_enabled'");
        }
        self::$url = TestEnv::startExtraServer(self::$saasEnv);
    }

    private function tenantPdo(string $slug): PDO
    {
        $c = TestEnv::$db;
        return new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']}_t_{$slug};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function client(string $host): Client
    {
        $c = new Client();
        $c->base = self::$url;
        $c->host = $host;
        return $c;
    }

    private function loginOk(Client $c, string $user, string $pw): bool
    {
        $r = $c->login($user, $pw);
        return $r->status === 302 && $r->header('Location') === 'dashboard';
    }

    public function testTenantIsResolvedByHostAndCredentialsAreIsolated(): void
    {
        $this->assertTrue($this->loginOk($this->client('acme.planquadro.test'), 'shared', 'Acme-Passwort-1'));
        $this->assertFalse($this->loginOk($this->client('beta.planquadro.test'), 'shared', 'Acme-Passwort-1'), 'Passwort aus Mandant A gilt nicht in B');
        $this->assertTrue($this->loginOk($this->client('beta.planquadro.test'), 'shared', 'Beta-Passwort-1'));
        $this->tenantPdo('beta')->exec('DELETE FROM login_attempts');
    }

    public function testSessionOfTenantACannotBeUsedOnTenantB(): void
    {
        $c = $this->client('acme.planquadro.test');
        $this->assertTrue($this->loginOk($c, 'shared', 'Acme-Passwort-1'));
        $this->assertSame(200, $c->get('/mitarbeiter')->status);
        /* Gleicher Cookie-Speicher, anderer Mandant */
        $c->host = 'beta.planquadro.test';
        $r = $c->get('/mitarbeiter');
        $this->assertSame(302, $r->status, 'Session darf in einem anderen Mandanten nicht gelten');
        /* Session-Cookie explizit unter fremdem Namen übertragen */
        $c->host = 'acme.planquadro.test';
        $r = $c->get('/dashboard');
        $this->assertSame(200, $r->status, 'ursprüngliche Session bleibt in A gültig');
    }

    public function testDataIsSeparatedPerTenant(): void
    {
        $c = $this->client('acme.planquadro.test');
        $this->assertTrue($this->loginOk($c, 'shared', 'Acme-Passwort-1'));
        $page = $c->get('/mitarbeiter')->body;
        $this->assertStringContainsString('Acme', $page);
        $this->assertStringNotContainsString('Geteilt, Beta', $page);
        $this->assertStringNotContainsString('Beta, Geteilt', $page);
    }

    public function testUnknownAndSuspendedTenants(): void
    {
        $this->assertSame(404, $this->client('unbekannt.planquadro.test')->get('/')->status);
        [$rc, $out] = TestEnv::runCli(['bin/tenant.php', 'suspend', 'beta'], self::$saasEnv);
        $this->assertSame(0, $rc, $out);
        try {
            $this->assertSame(503, $this->client('beta.planquadro.test')->get('/')->status);
        } finally {
            TestEnv::runCli(['bin/tenant.php', 'activate', 'beta'], self::$saasEnv);
        }
        $this->assertSame(200, $this->client('beta.planquadro.test')->get('/')->status);
    }

    public function testTenantHeaderIsOnlyHonouredForApiOnPlatformHost(): void
    {
        /* Web-Seiten auf dem Plattform-Host ignorieren den Header */
        $c = $this->client('api.planquadro.test');
        $r = $c->request('GET', '/', null, ['X-PQ-Tenant: acme']);
        $this->assertSame(404, $r->status);
        /* "/api/v1/" nur im Query-String macht keine API-Anfrage daraus */
        $this->assertSame(404, $c->request('GET', '/?x=/api/v1/', null, ['X-PQ-Tenant: acme'])->status);
        $this->assertSame(404, $c->request('GET', '/mitarbeiter?x=/api/v1/', null, ['X-PQ-Tenant: acme'])->status);
        /* Header auf einem Mandanten-Host kann den Mandanten nicht wechseln */
        $c = $this->client('beta.planquadro.test');
        $this->assertFalse($this->loginOk($c, 'shared', 'Acme-Passwort-1'));
        $this->tenantPdo('beta')->exec('DELETE FROM login_attempts');
    }

    public function testApiTokenIsBoundToTenant(): void
    {
        $c = $this->client('acme.planquadro.test'); $c->sendCsrf = false;
        $r = $c->request('POST', '/api/v1/auth/login', json_encode(['username' => 'shared', 'password' => 'Acme-Passwort-1']), ['Content-Type: application/json']);
        $this->assertSame(200, $r->status, $r->body);
        $tok = $r->json()['access_token'];
        $this->assertSame(200, $c->request('GET', '/api/v1/me', null, ['Authorization: Bearer ' . $tok])->status);
        $b = $this->client('beta.planquadro.test');
        $this->assertSame(401, $b->request('GET', '/api/v1/me', null, ['Authorization: Bearer ' . $tok])->status, 'Token aus Mandant A gilt nicht in B');
        /* Zentraler API-Host mit Mandanten-Header */
        $p = $this->client('api.planquadro.test');
        $this->assertSame(200, $p->request('GET', '/api/v1/me', null, ['Authorization: Bearer ' . $tok, 'X-PQ-Tenant: acme'])->status);
        $this->assertSame(401, $p->request('GET', '/api/v1/me', null, ['Authorization: Bearer ' . $tok, 'X-PQ-Tenant: beta'])->status);
    }

    public function testPlatformAdministration(): void
    {
        [$rc, $out] = TestEnv::runCli(['bin/tenant.php', 'platform-admin', 'betreiber'], self::$saasEnv);
        $this->assertSame(0, $rc, $out);
        preg_match('/Passwort: (\S+)/', $out, $m);
        /* nicht auf Mandanten-Hosts erreichbar */
        $this->assertSame(404, $this->client('acme.planquadro.test')->get('/platform/')->status);
        $c = $this->client('api.planquadro.test');
        $page = $c->get('/platform/')->body;
        preg_match('/name="_token" value="([a-f0-9]+)"/', $page, $t);
        $r = $c->request('POST', '/platform/', http_build_query(['_token' => $t[1], 'action' => 'login', 'username' => 'betreiber', 'password' => 'falsch']), ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertStringContainsString('falsch', $r->body);
        $r = $c->request('POST', '/platform/', http_build_query(['_token' => $t[1], 'action' => 'login', 'username' => 'betreiber', 'password' => $m[1]]), ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertSame(302, $r->status);
        $page = $c->get('/platform/')->body;
        $this->assertStringContainsString('Acme GmbH', $page);
        $this->assertStringContainsString('Beta AG', $page);
        $this->assertStringContainsString('acme.planquadro.test', $page);
        /* Mandanten-Session gilt nicht für die Plattform */
        $tc = $this->client('acme.planquadro.test');
        $this->assertTrue($this->loginOk($tc, 'shared', 'Acme-Passwort-1'));
        $tc->host = 'api.planquadro.test';
        $this->assertStringContainsString('name="password"', $tc->get('/platform/')->body);
    }

    public function testPlatformAdminNeedsConfiguredPlatformHost(): void
    {
        $env = self::$saasEnv; $env['PQ_PLATFORM_HOST'] = '';
        $url = TestEnv::startExtraServer($env);
        foreach (['api.planquadro.test', 'acme.planquadro.test', 'unbekannt.test'] as $h) {
            $c = new Client(); $c->base = $url; $c->host = $h;
            $this->assertSame(404, $c->get('/platform/')->status, $h);
        }
        /* ohne Plattform-Host gibt es auch kein Header-Routing für die API */
        $c = new Client(); $c->base = $url; $c->host = '';
        $this->assertSame(404, $c->request('GET', '/api/v1/config', null, ['X-PQ-Tenant: acme'])->status);
    }

    public function testAdoptExistingInstallation(): void
    {
        /* Die Selfhosted-Testdatenbank (mit Daten) als Mandant übernehmen */
        [$rc, $out] = TestEnv::runCli(['bin/tenant.php', 'adopt', 'bestand', 'Bestand GmbH', TestEnv::$db['name'], '--domain=bestand.planquadro.test'], self::$saasEnv);
        $this->assertSame(0, $rc, $out);
        $this->assertTrue($this->loginOk($this->client('bestand.planquadro.test'), 'lager', TestEnv::PASSWORD), 'Bestandsbenutzer melden sich unverändert an');
        [$rc, $out] = TestEnv::runCli(['bin/tenant.php', 'adopt', 'x2', 'X', TestEnv::$db['name']], self::$saasEnv);
        $this->assertNotSame(0, $rc, 'Datenbank nicht doppelt zuordnen');
        $pf = TestEnv::$db['name'] . '_platform';
        TestEnv::rootPdo()->exec("DELETE FROM `$pf`.tenants WHERE slug='bestand'");
    }

    public function testMigrateAllTenants(): void
    {
        [$rc, $out] = TestEnv::runCli(['bin/migrate.php', 'up', '--all-tenants'], self::$saasEnv);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('== Mandant acme', $out);
        $this->assertStringContainsString('== Mandant beta', $out);
        $this->assertSame(2, substr_count($out, 'Datenbank ist aktuell.'));
    }
}
