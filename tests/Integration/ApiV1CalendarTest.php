<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\Response;
use Planquadro\Tests\Support\TestEnv;

/** API v1: Kalender-Abo (GET/POST calendar) für die Apple-App, siehe docs/API.md. */
final class ApiV1CalendarTest extends IntegrationTestCase
{
    private function api(string $method, string $path, ?string $token = null): Response
    {
        $c = new Client();
        $c->sendCsrf = false;
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        return $c->request($method, '/api/v1/' . $path, $method === 'POST' ? '{}' : null, $h);
    }

    private function token(string $user): string
    {
        $c = new Client();
        $c->sendCsrf = false;
        $r = $c->request('POST', '/api/v1/auth/login', json_encode(['username' => $user, 'password' => TestEnv::PASSWORD, 'device_name' => 'Test']), ['Content-Type: application/json']);
        $this->assertSame(200, $r->status, $r->body);
        return $r->json()['access_token'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->exec('DELETE FROM api_refresh_tokens');
        $this->db()->exec('UPDATE users SET cal_token=NULL');
    }

    public function testCreateOnceThenStableAndFeedReachable(): void
    {
        $this->assertSame(401, $this->api('GET', 'calendar')->status);
        $t = $this->token('ma1');
        $r = $this->api('GET', 'calendar', $t);
        $this->assertSame(200, $r->status);
        $this->assertSame(['enabled' => false, 'url' => null], $r->json());

        $r = $this->api('POST', 'calendar', $t);
        $this->assertSame(201, $r->status, $r->body);
        $url = $r->json()['url'];
        $this->assertTrue($r->json()['enabled']);
        $this->assertMatchesRegularExpression('#/api/ical\?token=[0-9a-f]{64}$#', $url);

        /* zweites POST erzeugt keinen neuen Link; GET liefert denselben */
        $r = $this->api('POST', 'calendar', $t);
        $this->assertSame(200, $r->status);
        $this->assertSame($url, $r->json()['url']);
        $this->assertSame($url, $this->api('GET', 'calendar', $t)->json()['url']);

        /* Token gehört genau diesem Benutzer, der Feed liefert iCal */
        $tok = substr($url, strpos($url, 'token=') + 6);
        $s = $this->db()->prepare('SELECT username FROM users WHERE cal_token=?');
        $s->execute([$tok]);
        $this->assertSame('ma1', $s->fetchColumn());
        $feed = (new Client())->request('GET', '/api/ical?token=' . $tok, null);
        $this->assertSame(200, $feed->status);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $feed->body);

        /* anderer Benutzer sieht seinen eigenen (noch leeren) Zustand */
        $this->assertFalse($this->api('GET', 'calendar', $this->token('ma2'))->json()['enabled']);
    }

    public function testModuleSwitch(): void
    {
        $t = $this->token('ma1');
        $this->db()->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config','{\"modules\":{\"ical\":false}}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        try {
            $r = $this->api('POST', 'calendar', $t);
            $this->assertSame(403, $r->status);
            $this->assertSame('module_disabled', $r->json()['error']['code']);
            $this->assertNull($this->db()->query("SELECT cal_token FROM users WHERE username='ma1'")->fetchColumn());
        } finally {
            $this->db()->exec("DELETE FROM app_settings WHERE setting_key='tenant_config'");
        }
    }
}
