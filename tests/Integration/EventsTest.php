<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\Response;
use Planquadro\Tests\Support\TestEnv;

/**
 * Live-Aktualisierung per Server-Sent Events (Outbox live_events, /api/events,
 * /api/v1/events mit Einmal-Ticket). Streams werden mit ?max=1 gelesen – der
 * Server beendet die Verbindung nach einer Sekunde (Testserver mit mehreren Workern).
 */
final class EventsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPlan();
        $this->db()->exec('DELETE FROM live_event_tickets');
        $this->db()->exec('DELETE FROM api_refresh_tokens');
    }

    /** @return array<int, array{id:?string, event:string, data:mixed}> */
    private function parse(string $body): array
    {
        $out = [];
        foreach (preg_split("/\n\n/", str_replace("\r\n", "\n", $body)) as $block) {
            $ev = ['id' => null, 'event' => 'message', 'data' => null];
            $has = false;
            foreach (explode("\n", $block) as $line) {
                if ($line === '' || $line[0] === ':') continue;
                [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
                $v = ltrim($v, ' ');
                if ($k === 'id') { $ev['id'] = $v; $has = true; }
                elseif ($k === 'event') { $ev['event'] = $v; $has = true; }
                elseif ($k === 'data') { $ev['data'] = json_decode($v, true); $has = true; }
            }
            if ($has) $out[] = $ev;
        }
        return $out;
    }

    private function stream(Client $c, string $path, array $headers = []): Response
    {
        return $c->get($path . (strpos($path, '?') === false ? '?' : '&') . 'max=1', $headers);
    }

    /** Nur die fachlichen Ereignisse (ohne ready/bye/reset) */
    private function events(Response $r): array
    {
        return array_values(array_filter($this->parse($r->body), fn($e) => !in_array($e['event'], ['ready', 'bye', 'reset'], true) && $e['data'] !== null));
    }

    private function maxId(): int { return (int)$this->db()->query('SELECT COALESCE(MAX(id),0) FROM live_events')->fetchColumn(); }

    private function save(Client $c, int $uid, string $date, string $type, string $value): void
    {
        $r = $c->postJson('/api/save_bulk', ['changes' => [['user_id' => $uid, 'date' => $date, 'type' => $type, 'value' => $value]]]);
        $this->assertSame(200, $r->status, $r->body);
    }

    private function api(string $method, string $path, ?array $body = null, ?string $token = null): Response
    {
        $c = new Client();
        $c->sendCsrf = false;
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        return $c->request($method, '/api/v1/' . $path, $body === null ? null : json_encode($body), $h);
    }

    private function apiToken(string $user): string
    {
        $r = $this->api('POST', 'auth/login', ['username' => $user, 'password' => TestEnv::PASSWORD]);
        $this->assertSame(200, $r->status, $r->body);
        return $r->json()['access_token'];
    }

    public function testWithoutLoginNoStream(): void
    {
        $this->assertSame(401, (new Client())->get('/api/events?max=1')->status);
        $this->assertSame(401, $this->api('GET', 'events?max=1')->status);
        $this->assertSame(401, $this->api('POST', 'events/ticket')->status);
        $this->assertSame(401, $this->api('GET', 'events?max=1&ticket=pqe.' . str_repeat('A', 43))->status);
    }

    public function testSaveWritesEventAndStreamDeliversItWithId(): void
    {
        $ma = $this->uid('ma1'); $d = $this->workday();
        $tl = $this->as('tl');
        $before = $this->maxId();
        $this->save($tl, $ma, $d, 'assignment', (string)$this->areaId('K1'));
        $row = $this->db()->query('SELECT * FROM live_events ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertGreaterThan($before, (int)$row['id']);
        $this->assertSame('cells_changed', $row['event_type']);
        $this->assertSame('0', (string)$row['is_published']);
        $this->assertSame([[$ma, $d]], json_decode($row['cells'], true));
        $this->assertStringNotContainsString('K1', (string)$row['cells'] . $row['data'], 'keine Inhalte in der Outbox');

        $r = $this->stream($tl, '/api/events?last_event_id=' . $before);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertStringStartsWith('text/event-stream', (string)$r->header('Content-Type'));
        $this->assertSame('no', $r->header('X-Accel-Buffering'));
        $ev = $this->events($r);
        $this->assertCount(1, $ev, $r->body);
        $this->assertSame((string)$row['id'], $ev[0]['id']);
        $this->assertSame('cells_changed', $ev[0]['event']);
        $this->assertSame([[$ma, $d]], $ev[0]['data']['cells']);
        $this->assertSame([substr($d, 0, 7)], $ev[0]['data']['months']);
        $this->assertTrue($ev[0]['data']['own']);
    }

    public function testLastEventIdContinuesAfterLastSeenEvent(): void
    {
        $ma = $this->uid('ma1'); $d1 = $this->workday(); $d2 = $this->workday(0, 3);
        $tl = $this->as('tl');
        /* Ohne Cursor: "ready" mit aktuellem Stand, keine alten Ereignisse */
        $this->save($tl, $ma, $d1, 'assignment', (string)$this->areaId('K1'));
        $first = $this->maxId();
        $r = $this->stream($tl, '/api/events');
        $p = $this->parse($r->body);
        $this->assertSame('ready', $p[0]['event']);
        $this->assertSame((string)$first, $p[0]['id']);
        $this->assertSame([], $this->events($r));

        $this->save($tl, $ma, $d2, 'assignment', (string)$this->areaId('K1'));
        $r = $this->stream($tl, '/api/events', ['Last-Event-ID: ' . $first]);
        $ev = $this->events($r);
        $this->assertCount(1, $ev);
        $this->assertSame((string)$this->maxId(), $ev[0]['id']);
        $this->assertSame([[$ma, $d2]], $ev[0]['data']['cells']);

        /* Unbekannter (zukünftiger) Cursor → reset */
        $r = $this->stream($tl, '/api/events', ['Last-Event-ID: ' . ($this->maxId() + 1000)]);
        $this->assertContains('reset', array_column($this->parse($r->body), 'event'));
    }

    public function testInvisibleCellsAndDraftsAreNotDelivered(): void
    {
        $ma1 = $this->uid('ma1'); $ma2 = $this->uid('ma2'); $d = $this->workday();
        $start = $this->maxId();
        $mgr = $this->as('admin');
        /* Krankmeldung von ma1 und Einsatz von ma2 in einem Schritt (Entwurf) */
        $r = $mgr->postJson('/api/save_bulk', ['changes' => [
            ['user_id' => $ma1, 'date' => $d, 'type' => 'absence', 'value' => 'krank_ganz'],
            ['user_id' => $ma2, 'date' => $d, 'type' => 'assignment', 'value' => (string)$this->areaId('K1')]]]);
        $this->assertSame(200, $r->status, $r->body);

        /* tl2 sieht nur sein Team (ma2) */
        $r = $this->stream($this->as('tl2'), '/api/events?last_event_id=' . $start);
        $ev = $this->events($r);
        $this->assertCount(1, $ev, $r->body);
        $this->assertSame([[$ma2, $d]], $ev[0]['data']['cells']);
        $this->assertStringNotContainsString((string)$ma1 . ',"' . $d, $r->body);
        $this->assertStringNotContainsString('krank', $r->body);

        /* Mitarbeiter sehen keine Entwürfe – auch nicht eigene */
        $this->assertSame([], $this->events($this->stream($this->as('ma1'), '/api/events?last_event_id=' . $start)));

        /* Nach Veröffentlichung: ma1 per App bekommt die eigene Zelle, ohne Abwesenheitsart */
        $afterDraft = $this->maxId();
        $p = $mgr->postJson('/api/publish', ['year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2), 'publish_all' => 1]);
        $this->assertSame(200, $p->status, $p->body);
        $tok = $this->apiToken('ma1');
        $t = $this->api('POST', 'events/ticket', null, $tok)->json();
        $r = $this->api('GET', $t['url'] . '&max=1&last_event_id=' . $start);
        $this->assertSame(200, $r->status, $r->body);
        $ev = $this->events($r);
        $this->assertCount(1, $ev, 'App: keine Entwurfsereignisse, nur die Veröffentlichung');
        $this->assertSame('published', $ev[0]['event']);
        $this->assertGreaterThan($afterDraft, (int)$ev[0]['id']);
        $this->assertTrue($ev[0]['data']['self']);
        $this->assertContains([$ma1, $d], $ev[0]['data']['cells']);
        $this->assertStringNotContainsString('krank', $r->body);

        /* tl2 per App: nur ma2 */
        $t = $this->api('POST', 'events/ticket', null, $this->apiToken('tl2'))->json();
        $ev = $this->events($this->api('GET', $t['url'] . '&max=1&last_event_id=' . $afterDraft));
        $this->assertCount(1, $ev);
        $this->assertSame([[$ma2, $d]], $ev[0]['data']['cells']);
        $this->assertFalse($ev[0]['data']['self']);
    }

    public function testTicketIsSingleUseShortLivedAndTenantBound(): void
    {
        $tok = $this->apiToken('ma1');
        $t = $this->api('POST', 'events/ticket', null, $tok);
        $this->assertSame(200, $t->status, $t->body);
        $j = $t->json();
        $this->assertMatchesRegularExpression('/^pqe\.[A-Za-z0-9_-]{43}$/', $j['ticket']);
        $this->assertLessThanOrEqual(60, $j['expires_in']);
        $this->assertStringNotContainsString($tok, $j['url'], 'Access-Token nie in der URL');
        $row = $this->db()->query('SELECT * FROM live_event_tickets')->fetch();
        $this->assertNotSame($j['ticket'], $row['token_hash'], 'nur Hash gespeichert');

        $r = $this->api('GET', $j['url'] . '&max=1');
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('ready', $this->parse($r->body)[0]['event']);
        $again = $this->api('GET', $j['url'] . '&max=1');
        $this->assertSame(401, $again->status, 'Ticket nur einmal gültig');
        $this->assertSame('invalid_token', $again->json()['error']['code']);

        /* abgelaufen */
        $j2 = $this->api('POST', 'events/ticket', null, $tok)->json();
        $this->db()->exec("UPDATE live_event_tickets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE used_at IS NULL");
        $this->assertSame(401, $this->api('GET', $j2['url'] . '&max=1')->status);

        /* anderer Mandant */
        $j3 = $this->api('POST', 'events/ticket', null, $tok)->json();
        $this->db()->exec("UPDATE live_event_tickets SET tenant='fremd' WHERE used_at IS NULL AND expires_at > NOW()");
        $this->assertSame(401, $this->api('GET', $j3['url'] . '&max=1')->status);

        /* Abmelden widerruft offene Tickets der Token-Familie */
        $j4 = $this->api('POST', 'events/ticket', null, $tok)->json();
        $this->assertSame(204, $this->api('POST', 'auth/logout', [], $tok)->status);
        $this->assertSame(401, $this->api('GET', $j4['url'] . '&max=1')->status);

        /* Access-Token als Query-Parameter wird nicht akzeptiert */
        $this->assertSame(401, $this->api('GET', 'events?max=1&access_token=' . urlencode($this->apiToken('ma1')))->status);
        /* Bearer-Header funktioniert für Clients, die Header setzen können */
        $r = $this->api('GET', 'events?max=1', null, $this->apiToken('ma1'));
        $this->assertSame(200, $r->status);
    }

    public function testPresenceAndRequestEvents(): void
    {
        $start = $this->maxId();
        $d = $this->workday();
        $tl = $this->as('tl');
        $tl->postJson('/api/live_status', ['action' => 'ping', 'page' => 'monatsplanung', 'year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2)]);
        $tl->postJson('/api/live_status', ['action' => 'ping', 'page' => 'monatsplanung', 'year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2)]);
        $n = (int)$this->db()->query("SELECT COUNT(*) FROM live_events WHERE event_type='presence' AND id > $start")->fetchColumn();
        $this->assertSame(1, $n, 'Präsenz nur beim Betreten, nicht bei jedem Ping');
        $ev = $this->events($this->stream($this->as('mgr'), '/api/events?last_event_id=' . $start));
        $this->assertSame('presence', $ev[0]['event']);
        $this->assertSame('join', $ev[0]['data']['action']);
        $this->assertSame([], $this->events($this->stream($this->as('ma1'), '/api/events?last_event_id=' . $start)), 'Präsenz nur für Planer');

        /* Antrag eines Mitarbeiters: Antragsteller und zuständige Vorgesetzte, nicht tl2 */
        $start = $this->maxId();
        $ma = $this->as('ma1');
        $r = $ma->postJson('/api/change_requests', ['action' => 'submit_batch', 'items' => [['user_id' => $this->uid('ma1'), 'date' => $d, 'type' => 'assignment', 'value' => (string)$this->areaId('K1')]]]);
        $this->assertTrue($r->json()['success'] ?? false, $r->body);
        $this->assertSame('request_changed', $this->events($this->stream($ma, '/api/events?last_event_id=' . $start))[0]['event']);
        $this->assertSame('request_changed', $this->events($this->stream($tl, '/api/events?last_event_id=' . $start))[0]['event']);
        $this->assertSame([], $this->events($this->stream($this->as('tl2'), '/api/events?last_event_id=' . $start)));
    }

    public function testStreamDoesNotBlockOtherRequestsOfSameSession(): void
    {
        $tl = $this->as('tl');
        $jar = (new \ReflectionProperty($tl, 'jar'))->getValue($tl);
        $mh = curl_multi_init();
        $ch = curl_init(TestEnv::baseUrl() . '/api/events?max=3');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 10]);
        curl_multi_add_handle($mh, $ch);
        $t0 = microtime(true);
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.05); } while (microtime(true) - $t0 < 0.5);
        $r = $tl->postJson('/api/live_status', ['action' => 'ping', 'page' => 'x', 'year' => 2026, 'month' => 1]);
        $elapsed = microtime(true) - $t0;
        $this->assertSame(200, $r->status);
        $this->assertLessThan(2.5, $elapsed, 'Session-Sperre wird vor dem Streamen freigegeben');
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running);
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);
        $this->assertStringContainsString('event: ready', (string)$body);
    }
}
