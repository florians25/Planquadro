<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\Response;
use Planquadro\Tests\Support\TestEnv;

/** API v1 gemäß docs/API.md: Auth, Rechte, Vertraulichkeit, Sync, Push. */
final class ApiV1Test extends IntegrationTestCase
{
    private function api(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = []): Response
    {
        $c = new Client();
        $c->sendCsrf = false;
        $h = array_merge(['Content-Type: application/json'], $headers);
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        return $c->request($method, '/api/v1/' . $path, $body === null ? null : json_encode($body), $h);
    }

    private function login(string $user): array
    {
        $r = $this->api('POST', 'auth/login', ['username' => $user, 'password' => TestEnv::PASSWORD, 'device_name' => 'Test']);
        $this->assertSame(200, $r->status, $r->body);
        return $r->json();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPlan();
        $this->db()->exec('DELETE FROM api_refresh_tokens');
        $this->db()->exec('DELETE FROM push_queue');
        $this->db()->exec('DELETE FROM push_devices');
        $this->db()->exec('DELETE FROM notifications');
    }

    public function testPublicConfigAndHealth(): void
    {
        $this->assertSame(['status' => 'ok'], $this->api('GET', 'health')->json());
        $c = $this->api('GET', 'config')->json();
        $this->assertSame(1, $c['api_version']);
        $this->assertArrayHasKey('primary', $c['tenant']['branding']);
        $this->assertFalse($c['auth']['oidc']['enabled']);
    }

    public function testLoginErrorsAndLockout(): void
    {
        $r = $this->api('POST', 'auth/login', ['username' => 'lager', 'password' => 'falsch']);
        $this->assertSame(401, $r->status);
        $this->assertSame('invalid_credentials', $r->json()['error']['code']);
        $this->db()->exec("UPDATE users SET must_change_password=1 WHERE username='azubi'");
        $r = $this->api('POST', 'auth/login', ['username' => 'azubi', 'password' => TestEnv::PASSWORD]);
        $this->assertSame('password_change_required', $r->json()['error']['code']);
        $this->db()->exec("UPDATE users SET must_change_password=0 WHERE username='azubi'");
        for ($i = 0; $i < 5; $i++) $this->api('POST', 'auth/login', ['username' => 'lager', 'password' => 'falsch']);
        $r = $this->api('POST', 'auth/login', ['username' => 'lager', 'password' => TestEnv::PASSWORD]);
        $this->assertSame(403, $r->status);
        $this->assertSame('account_locked', $r->json()['error']['code']);
        $this->db()->exec('DELETE FROM login_attempts');
    }

    public function testProtectedEndpointsRequireValidToken(): void
    {
        $this->assertSame(401, $this->api('GET', 'me')->status);
        $this->assertSame('invalid_token', $this->api('GET', 'me', null, 'pqa.manipuliert.xyz')->json()['error']['code']);
        $t = $this->login('ma1');
        $this->assertSame('ma1', $t['user']['user']['username'], 'Login liefert das me-Objekt (docs/API.md)');
        $this->assertArrayHasKey('permissions', $t['user']);
        $me = $this->api('GET', 'me', null, $t['access_token'])->json();
        $this->assertSame('ma1', $me['user']['username']);
        $this->assertFalse($me['permissions']['plan_edit']);
        /* Token-Signatur ist an den Inhalt gebunden */
        [$p, $payload, $sig] = explode('.', $t['access_token']);
        $evil = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $evil['sub'] = $this->uid('admin');
        $forged = 'pqa.' . rtrim(strtr(base64_encode(json_encode($evil)), '+/', '-_'), '=') . '.' . $sig;
        $this->assertSame(401, $this->api('GET', 'me', null, $forged)->status);
    }

    public function testRefreshRotationAndReuseDetection(): void
    {
        $t = $this->login('ma1');
        $r1 = $this->api('POST', 'auth/refresh', ['refresh_token' => $t['refresh_token']])->json();
        $this->assertNotSame($t['refresh_token'], $r1['refresh_token']);
        /* altes Refresh-Token erneut → Familie gesperrt, auch das neue Access-Token */
        $this->assertSame(401, $this->api('POST', 'auth/refresh', ['refresh_token' => $t['refresh_token']])->status);
        $this->assertSame(401, $this->api('POST', 'auth/refresh', ['refresh_token' => $r1['refresh_token']])->status);
        $this->assertSame(401, $this->api('GET', 'me', null, $r1['access_token'])->status);
    }

    public function testLogoutRevokesImmediately(): void
    {
        $t = $this->login('ma1');
        $this->assertSame(204, $this->api('POST', 'auth/logout', [], $t['access_token'])->status);
        $this->assertSame(401, $this->api('GET', 'me', null, $t['access_token'])->status);
        $this->assertSame(401, $this->api('POST', 'auth/refresh', ['refresh_token' => $t['refresh_token']])->status);
    }

    public function testDeactivatedUserLosesAccess(): void
    {
        $t = $this->login('lager');
        $this->db()->exec("UPDATE users SET active=0 WHERE username='lager'");
        try {
            $this->assertSame(401, $this->api('GET', 'me', null, $t['access_token'])->status);
        } finally {
            $this->db()->exec("UPDATE users SET active=1 WHERE username='lager'");
        }
    }

    public function testMyPlanShowsOnlyPublishedEntries(): void
    {
        $ma = $this->uid('ma1'); $d1 = $this->workday(); $d2 = $this->workday(0, 3);
        $y = (int)substr($d1, 0, 4); $m = (int)substr($d1, 5, 2);
        $this->db()->prepare('INSERT INTO assignments(user_id,plan_date,plan_area_id,year,month,is_published,remarks) VALUES(?,?,?,?,?,1,?)')->execute([$ma, $d1, $this->areaId('K1'), $y, $m, 'Rollout']);
        $this->db()->prepare("INSERT INTO plan_drafts(user_id,plan_date,year,month,action,change_type,change_value,created_by) VALUES(?,?,?,?,'set','assignment',?,?)")
            ->execute([$ma, $d2, (int)substr($d2, 0, 4), (int)substr($d2, 5, 2), $this->areaId('K2'), $this->uid('tl')]);
        $t = $this->login('ma1');
        $j = $this->api('GET', "plan/my?from=$d1&to=$d2", null, $t['access_token'])->json();
        $days = array_column($j['days'], null, 'date');
        $this->assertSame('K1', $days[$d1]['entry']['code']);
        $this->assertSame('Rollout', $days[$d1]['entry']['remarks']);
        $this->assertNull($days[$d2]['entry'], 'Entwürfe sind für Mitarbeiter unsichtbar');
        $this->assertSame(400, $this->api('GET', 'plan/my?from=2026-01-01&to=2026-12-31', null, $t['access_token'])->status);
    }

    public function testTeamViewMasksConfidentialAbsencesAndRespectsScope(): void
    {
        $d = $this->workday();
        $y = (int)substr($d, 0, 4); $m = (int)substr($d, 5, 2);
        $this->db()->prepare("INSERT INTO absences(user_id,absence_date,absence_type,is_published) VALUES(?,?,'krank_attest',1)")->execute([$this->uid('ma2'), $d]);
        /* Kollege sieht nur "A" */
        $j = $this->api('GET', "plan/team?year=$y&month=$m", null, $this->login('ma1')['access_token'])->json();
        $cell = $j['cells'][$this->uid('ma2') . '_' . $d];
        $this->assertSame('A', $cell['code']);
        $this->assertTrue($cell['confidential']);
        $this->assertStringNotContainsString('krank', json_encode($j));
        /* Teamleiter tl sieht nur sein Team (ma2 gehört zu tl2) */
        $ids = array_column($this->api('GET', "plan/team?year=$y&month=$m", null, $this->login('tl')['access_token'])->json()['users'], 'id');
        $this->assertContains($this->uid('ma1'), $ids);
        $this->assertNotContains($this->uid('ma2'), $ids);
        /* Vorgesetzte tl2 sieht den Grund */
        $j = $this->api('GET', "plan/team?year=$y&month=$m", null, $this->login('tl2')['access_token'])->json();
        $this->assertSame('K', $j['cells'][$this->uid('ma2') . '_' . $d]['code']);
    }

    public function testAssignmentDetailsWithCustomerAndScope(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO customers(name,address,phone,notes) VALUES('Kunde Nord GmbH','Musterstr. 1','+49 1','Tor 3')");
        $cid = (int)$db->lastInsertId();
        $area = $this->areaId('K1');
        $db->exec("UPDATE plan_areas SET customer_id=$cid WHERE id=$area");
        $d = $this->workday();
        foreach (['ma1', 'azubi'] as $u) $db->prepare('INSERT INTO assignments(user_id,plan_date,plan_area_id,year,month,is_published) VALUES(?,?,?,?,?,1)')
            ->execute([$this->uid($u), $d, $area, (int)substr($d, 0, 4), (int)substr($d, 5, 2)]);
        try {
            $t = $this->login('ma1')['access_token'];
            $j = $this->api('GET', "assignments/$d", null, $t)->json();
            $this->assertSame('Kunde Nord GmbH', $j['customer']['name']);
            $this->assertSame('Tor 3', $j['customer']['notes']);
            $this->assertSame(['Test, Azubi'], array_column($j['colleagues'], 'name'));
            /* fremder Mitarbeiter außerhalb des Scopes: tl darf ma2 nicht abfragen */
            $this->assertSame(403, $this->api('GET', "assignments/$d?user_id=" . $this->uid('ma2'), null, $this->login('tl')['access_token'])->status);
        } finally {
            $db->exec('UPDATE plan_areas SET customer_id=NULL'); $db->exec('DELETE FROM customers');
        }
    }

    public function testAbsencesAndVacationRequests(): void
    {
        $ma = $this->uid('ma1');
        $y = (int)date('Y') + 1;
        foreach (["$y-07-20", "$y-07-21", "$y-07-22"] as $d) $this->db()->prepare("INSERT INTO absences(user_id,absence_date,absence_type,is_published) VALUES(?,?,'urlaub_genehmigt',1)")->execute([$ma, $d]);
        $t = $this->login('ma1')['access_token'];
        $j = $this->api('GET', "absences/my?year=$y", null, $t)->json();
        $this->assertSame(3.0, (float)$j['vacation']['planned']);
        $this->assertSame(27.0, (float)$j['vacation']['left']);
        $this->assertCount(1, $j['items']);
        /* Antrag Mo–Fr inkl. Wochenende → nur Werktage */
        $mon = date('Y-m-d', strtotime("monday next week +2 week"));
        $sun = date('Y-m-d', strtotime("$mon +6 day"));
        $r = $this->api('POST', 'absences/requests', ['from' => $mon, 'to' => $sun, 'type' => 'urlaub_ganz', 'remarks' => 'Test'], $t);
        $this->assertSame(201, $r->status, $r->body);
        $this->assertSame(5 - count(array_filter(range(0, 4), fn($i) => (bool)$this->db()->query("SELECT 1 FROM holidays WHERE holiday_type='feiertag' AND holiday_date='" . date('Y-m-d', strtotime("$mon +$i day")) . "'")->fetchColumn())), $r->json()['created']);
        $this->assertSame(0, $this->api('POST', 'absences/requests', ['from' => $mon, 'to' => $mon, 'type' => 'urlaub_ganz'], $t)->json()['created'], 'kein doppelter Antrag');
        $this->assertSame(400, $this->api('POST', 'absences/requests', ['from' => $mon, 'to' => $mon, 'type' => 'krank_ganz'], $t)->status);
        $this->db()->exec('DELETE FROM change_requests');
    }

    public function testPublishNotifiesAndSyncReportsChangedDates(): void
    {
        $ma = $this->uid('ma1'); $d = $this->workday();
        $t = $this->login('ma1')['access_token'];
        $dev = $this->api('POST', 'devices', ['platform' => 'android', 'provider' => 'ntfy', 'token' => 'planquadro-test-topic-1', 'app_version' => '1.0.0'], $t);
        $this->assertSame(201, $dev->status, $dev->body);
        $cursor = $this->api('GET', 'sync', null, $t)->json()['cursor'];
        $tl = $this->as('tl');
        $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma, 'date' => $d, 'type' => 'assignment', 'value' => (string)$this->areaId('K1')]]]);
        $r = $tl->postJson('/api/publish', ['year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2)]);
        $this->assertTrue($r->json()['success']);
        $sync = $this->api('GET', 'sync?since=' . $cursor, null, $t)->json();
        $this->assertSame([$d], $sync['changed_dates']);
        $this->assertFalse($sync['full_resync']);
        $this->assertSame([], $this->api('GET', 'sync?since=' . $sync['cursor'], null, $t)->json()['changed_dates']);
        $this->assertTrue($this->api('GET', 'sync?since=999999999', null, $t)->json()['full_resync']);
        $n = $this->api('GET', 'notifications', null, $t)->json();
        $this->assertSame(1, $n['unread']);
        $this->assertStringContainsString(date('d.m.', strtotime($d)), $n['items'][0]['message']);
        $this->assertSame(204, $this->api('POST', 'notifications/read-all', [], $t)->status);
        /* Push-Warteschlange → Worker → ntfy */
        $q = $this->db()->query('SELECT * FROM push_queue')->fetchAll();
        $this->assertCount(1, $q);
        $this->assertStringNotContainsString('krank', $q[0]['data']);
        $log = sys_get_temp_dir() . '/planquadro-fake-ntfy.jsonl';
        @unlink($log);
        $ntfy = TestEnv::startExtraServer([], TestEnv::$root . '/tests/fixtures/fake_ntfy.php');
        [$rc, $out] = TestEnv::runCli(['bin/push-worker.php'], ['PQ_NTFY_URL' => $ntfy]);
        $this->assertSame(0, $rc, $out);
        $msg = json_decode((string)file_get_contents($log), true);
        $this->assertSame('planquadro-test-topic-1', $msg['topic']);
        $this->assertSame('Plan geändert', $msg['title']);
        $this->assertNotNull($this->db()->query('SELECT sent_at FROM push_queue')->fetchColumn());
    }

    public function testPlanChangeByPublisherDoesNotNotifyUnchangedCells(): void
    {
        $ma = $this->uid('ma1'); $d = $this->workday();
        $tl = $this->as('tl');
        $this->db()->prepare('INSERT INTO assignments(user_id,plan_date,plan_area_id,year,month,is_published) VALUES(?,?,?,?,?,1)')->execute([$ma, $d, $this->areaId('K1'), (int)substr($d, 0, 4), (int)substr($d, 5, 2)]);
        /* nur Bemerkung geändert → Einsatz gleich → keine Planänderungs-Meldung */
        $tl->postJson('/api/save_remark', ['user_id' => $ma, 'date' => $d, 'remarks' => 'Hinweis']);
        $tl->postJson('/api/publish', ['year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2)]);
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM notifications WHERE user_id=$ma")->fetchColumn());
    }

    public function testCorsOnlyForConfiguredOrigins(): void
    {
        $r = $this->api('GET', 'health', null, null, ['Origin: capacitor://localhost']);
        $this->assertSame('capacitor://localhost', $r->header('Access-Control-Allow-Origin'));
        $r = $this->api('GET', 'health', null, null, ['Origin: https://evil.example']);
        $this->assertNull($r->header('Access-Control-Allow-Origin'));
    }

    public function testUnknownEndpointAndModuleSwitch(): void
    {
        $t = $this->login('ma1')['access_token'];
        $this->assertSame(404, $this->api('GET', 'gibtsnicht', null, $t)->status);
        $this->db()->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config','{\"modules\":{\"mobile\":false}}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        try {
            $this->assertSame('module_disabled', $this->api('GET', 'me', null, $t)->json()['error']['code']);
            $this->assertSame(403, $this->api('POST', 'auth/login', ['username' => 'ma1', 'password' => TestEnv::PASSWORD])->status);
        } finally {
            $this->db()->exec("DELETE FROM app_settings WHERE setting_key='tenant_config'");
        }
    }
}
