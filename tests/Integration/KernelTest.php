<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\Response;
use Planquadro\Tests\Support\TestEnv;

/**
 * Request-Kernel (app/lib/Kernel.php): Request-ID, einheitliches Fehlerformat,
 * globaler Exception-Handler – und Befund S-20: technische Fehlertexte
 * (SQL, Tabellennamen, Dateipfade) gehen nie an den Client.
 */
final class KernelTest extends IntegrationTestCase
{
    /** Tabelle für die Dauer von $fn umbenennen, um einen echten DB-Fehler zu provozieren. */
    private function withoutTable(string $table, callable $fn): void
    {
        $this->db()->exec("RENAME TABLE `$table` TO `{$table}_kernel_weg`");
        try { $fn(); }
        finally { $this->db()->exec("RENAME TABLE `{$table}_kernel_weg` TO `$table`"); }
    }

    /** Antwort enthält keine technischen Details, aber eine Request-ID (Header = Body). */
    private function assertGenericError(Response $r, string $table): array
    {
        $j = $r->json();
        $this->assertFalse($j['success'] ?? true, $r->body);
        $rid = $r->header('X-Request-ID');
        $this->assertNotEmpty($rid);
        $this->assertSame($rid, $j['request_id'] ?? null, 'request_id im Body = Header');
        foreach (['SQLSTATE', $table, 'kernel_weg', 'doesn\'t exist', '.php', 'PDO'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $r->body, "Antwort verrät „{$leak}“");
        }
        return $j;
    }

    private function serverLogContains(string $needle): bool
    {
        for ($i = 0; $i < 20; $i++) {
            $log = (string)@file_get_contents(sys_get_temp_dir() . '/planquadro-test-server.log');
            if (strpos($log, $needle) !== false) return true;
            usleep(100000);
        }
        return false;
    }

    public function testRequestIdHeaderOnApisAndPages(): void
    {
        $c = $this->as('ma1');
        $a = $c->postJson('/api/notifications', ['action' => 'count']);
        $b = $c->postJson('/api/notifications', ['action' => 'count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string)$a->header('X-Request-ID'));
        $this->assertNotSame($a->header('X-Request-ID'), $b->header('X-Request-ID'), 'je Request eine neue ID');
        $this->assertTrue($a->json()['success'], 'Antwortformat unverändert');
        $this->assertArrayNotHasKey('request_id', $a->json(), 'Erfolgsantworten bleiben unverändert');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string)$c->get('/dashboard')->header('X-Request-ID'));
    }

    public function testRequestIdFromProxyIsKeptOnlyWhenSafe(): void
    {
        $c = $this->as('ma1');
        $r = $c->postJson('/api/notifications', ['action' => 'count'], ['X-Request-ID: proxy-4711.abcdef']);
        $this->assertSame('proxy-4711.abcdef', $r->header('X-Request-ID'));
        foreach (['kurz', 'mit leerzeichen und so', 'x<script>alert(1)</script>', str_repeat('a', 65)] as $bad) {
            $r = $c->postJson('/api/notifications', ['action' => 'count'], ['X-Request-ID: ' . $bad]);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string)$r->header('X-Request-ID'), $bad);
        }
    }

    /** Abgefangener DB-Fehler: früher wurde $e->getMessage() (SQL-Text) ausgegeben. */
    public function testCaughtDbErrorIsGenericWithRequestId(): void
    {
        $c = $this->as('ma1');
        $this->withoutTable('notifications', function () use ($c) {
            $r = $c->postJson('/api/notifications', ['action' => 'list']);
            $this->assertSame(200, $r->status, 'HTTP-Code des Endpunkts unverändert');
            $j = $this->assertGenericError($r, 'notifications');
            $this->assertStringContainsString($j['request_id'], $j['error'], 'Meldung nennt die Referenz');
            $this->assertTrue($this->serverLogContains('[req ' . $j['request_id'] . '] notifications: PDOException'), 'Details mit Request-ID im Log');
        });
    }

    public function testCleanupDbErrorIsGeneric(): void
    {
        $c = $this->as('admin');
        $this->withoutTable('notifications', function () use ($c) {
            $r = $c->postJson('/api/cleanup', ['action' => 'cleanup', 'type' => 'old_notifications']);
            $this->assertGenericError($r, 'notifications');
        });
    }

    /** Nicht abgefangener DB-Fehler in einem API-Endpunkt → globaler Handler: 500, JSON, generisch. */
    public function testUncaughtDbErrorInApiGoesThroughKernel(): void
    {
        $c = $this->as('admin');
        $this->withoutTable('planned_maintenance', function () use ($c) {
            $r = $c->postJson('/api/maintenance', ['action' => 'list']);
            $this->assertSame(500, $r->status);
            $this->assertStringContainsString('application/json', (string)$r->header('Content-Type'));
            $j = $this->assertGenericError($r, 'planned_maintenance');
            $this->assertTrue($this->serverLogContains('[req ' . $j['request_id'] . '] Nicht abgefangener Fehler POST /api/maintenance'));
        });
        /* danach wieder normal */
        $this->assertTrue($c->postJson('/api/maintenance', ['action' => 'list'])->json()['success']);
    }

    /** Fehler in bestehenden catch-Blöcken: bisherige Meldung und HTTP-Code bleiben, request_id kommt hinzu. */
    public function testExistingGenericMessagesKeepFormat(): void
    {
        $c = $this->as('admin');
        $this->withoutTable('user_month_data', function () use ($c) {
            $r = $c->postJson('/api/save_month_data', ['user_id' => $this->uid('ma1'), 'year' => 2030, 'month' => 1, 'hours' => '8']);
            $this->assertSame(500, $r->status);
            $j = $r->json();
            $this->assertSame('Datenbankfehler', $j['error']);
            $this->assertSame($r->header('X-Request-ID'), $j['request_id']);
            $this->assertStringNotContainsString('user_month_data', $r->body);
        });
    }

    /** Seiten-AJAX-Handler (früher getMessage()) */
    public function testPageAjaxDbErrorIsGeneric(): void
    {
        $c = $this->as('vw');
        $this->withoutTable('holidays', function () use ($c) {
            $r = $c->postAjax('/feiertage', ['action' => 'del', 'id' => 1]);
            $this->assertGenericError($r, 'holidays');
        });
        $c = $this->as('admin');
        $this->withoutTable('audit_log', function () use ($c) {
            $r = $c->postAjax('/einstellungen', ['action' => 'get_audit_log']);
            $this->assertGenericError($r, 'audit_log');
        });
    }

    /** Fachmeldungen (InvalidArgumentException bzw. fachliche Exception) kommen weiter an. */
    public function testDomainMessagesStillReachTheUser(): void
    {
        $vw = $this->as('vw');
        $r = $vw->postAjax('/stammdaten', ['action' => 'save_customer', 'name' => ''])->json();
        $this->assertSame(['success' => false, 'error' => 'Name ist erforderlich.'], $r);
        $r = $this->as('tl')->postAjax('/abwesenheiten', ['action' => 'impact', 'user_id' => 0, 'from' => '', 'to' => ''])->json();
        $this->assertSame('Bitte Mitarbeiter und Zeitraum wählen.', $r['error']);
    }

    /** Datenbank nicht erreichbar: JSON-Endpunkt antwortet im einheitlichen Format ohne Details. */
    public function testDatabaseDownGivesJsonForApis(): void
    {
        $url = TestEnv::startExtraServer(['PQ_DB_PORT' => '1', 'PQ_DB_HOST' => '127.0.0.1']);
        $c = new Client(); $c->base = $url;
        $r = $c->get('/api/announcements');
        $this->assertSame(503, $r->status, $r->body);
        $j = $r->json();
        $this->assertFalse($j['success']);
        $this->assertSame($r->header('X-Request-ID'), $j['request_id']);
        $this->assertStringNotContainsString('127.0.0.1', $r->body);
        $this->assertStringNotContainsString('SQLSTATE', $r->body);
    }
}
