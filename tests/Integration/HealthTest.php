<?php
namespace Vireo\Tests\Integration;

use Vireo\Tests\Support\Client;
use Vireo\Tests\Support\IntegrationTestCase;
use Vireo\Tests\Support\TestEnv;

/** /api/healthz (Liveness) und /api/readyz (Datenbank + Migrationen, mandantenbezogen). */
final class HealthTest extends IntegrationTestCase
{
    private function get(string $base, string $path, ?string $host = null): array
    {
        $c = new Client(); $c->base = $base; $c->host = $host;
        $r = $c->get($path);
        return [$r->status, json_decode($r->body, true), $r];
    }

    public function testHealthzNeedsNoDatabase(): void
    {
        [$s, $j, $r] = $this->get(TestEnv::baseUrl(), '/api/healthz');
        $this->assertSame(200, $s);
        $this->assertSame(['status' => 'ok'], $j);
        $this->assertStringContainsString('no-store', (string)$r->header('Cache-Control'));
        $this->assertNull($r->header('Set-Cookie'), 'keine Session');
        /* auch ohne erreichbare Datenbank */
        $url = TestEnv::startExtraServer(['VIREO_DB_PORT' => '1', 'VIREO_DB_HOST' => '127.0.0.1']);
        $this->assertSame(200, $this->get($url, '/api/healthz')[0]);
    }

    public function testReadyzReportsDatabaseAndMigrations(): void
    {
        [$s, $j, $r] = $this->get(TestEnv::baseUrl(), '/api/readyz');
        $this->assertSame(200, $s, $r->body);
        $this->assertSame(['status' => 'ready', 'checks' => ['database' => 'ok', 'migrations' => 'ok']], $j);
        $this->assertNull($r->header('Set-Cookie'));

        /* Datenbank nicht erreichbar → 503 ohne Details */
        $url = TestEnv::startExtraServer(['VIREO_DB_PORT' => '1', 'VIREO_DB_HOST' => '127.0.0.1']);
        [$s, $j, $r] = $this->get($url, '/api/readyz');
        $this->assertSame(503, $s);
        $this->assertSame(['status' => 'not_ready', 'checks' => ['database' => 'error']], $j);
        $this->assertStringNotContainsString('127.0.0.1', $r->body);
        $this->assertStringNotContainsString('SQLSTATE', $r->body);

        /* leere Datenbank → Migrationen offen */
        $empty = TestEnv::$db['name'] . '_hz_empty';
        TestEnv::rootPdo()->exec("DROP DATABASE IF EXISTS `$empty`");
        TestEnv::rootPdo()->exec("CREATE DATABASE `$empty`");
        try {
            $url = TestEnv::startExtraServer(['VIREO_DB_NAME' => $empty]);
            [$s, $j] = $this->get($url, '/api/readyz');
            $this->assertSame(503, $s);
            $this->assertSame(['database' => 'ok', 'migrations' => 'pending'], $j['checks']);
        } finally {
            TestEnv::rootPdo()->exec("DROP DATABASE IF EXISTS `$empty`");
        }
    }

    public function testReadyzIsTenantAwareInSaasMode(): void
    {
        $root = TestEnv::rootPdo();
        $pf = TestEnv::$db['name'] . '_hz_platform';
        $root->exec("DROP DATABASE IF EXISTS `$pf`");
        $root->exec("CREATE DATABASE `$pf`");
        try {
            $saas = ['VIREO_MODE' => 'saas', 'VIREO_PLATFORM_DB_NAME' => $pf];
            [$rc, $out] = TestEnv::runCli(['bin/migrate.php', 'up', '--platform'], $saas);
            $this->assertSame(0, $rc, $out);
            /* Mandant zeigt auf die (vollständig migrierte) Haupt-Testdatenbank */
            $root->prepare("INSERT INTO `$pf`.tenants(slug, name, db_name, status) VALUES('hz','HZ',?,'active'), ('hzoff','Gesperrt',?,'suspended')")
                 ->execute([TestEnv::$db['name'], TestEnv::$db['name'] . '_hz_off']);
            $root->exec("INSERT INTO `$pf`.tenant_domains(host, tenant_id) SELECT 'hz.vireo.test', id FROM `$pf`.tenants WHERE slug='hz'");
            $root->exec("INSERT INTO `$pf`.tenant_domains(host, tenant_id) SELECT 'hzoff.vireo.test', id FROM `$pf`.tenants WHERE slug='hzoff'");
            $url = TestEnv::startExtraServer($saas);

            [$s, $j] = $this->get($url, '/api/readyz', 'hz.vireo.test');
            $this->assertSame(200, $s);
            $this->assertSame(['platform_database' => 'ok', 'platform_migrations' => 'ok', 'database' => 'ok', 'migrations' => 'ok'], $j['checks']);

            /* Host ohne Mandant (Load-Balancer-Check): nur Plattform */
            [$s, $j] = $this->get($url, '/api/readyz', 'lb.internal');
            $this->assertSame(200, $s);
            $this->assertSame(['platform_database' => 'ok', 'platform_migrations' => 'ok'], $j['checks']);

            [$s, $j] = $this->get($url, '/api/readyz', 'hzoff.vireo.test');
            $this->assertSame(503, $s);
            $this->assertSame('unavailable', $j['checks']['database']);

            /* healthz braucht auch im SaaS-Modus keinen Mandanten */
            $this->assertSame(200, $this->get($url, '/api/healthz', 'unbekannt.example')[0]);
        } finally {
            $root->exec("DROP DATABASE IF EXISTS `$pf`");
        }
    }
}
