<?php
namespace OpsPilot\Tests\Integration;

use OpsPilot\Tests\Support\Client;
use OpsPilot\Tests\Support\IntegrationTestCase;
use OpsPilot\Tests\Support\Response;
use OpsPilot\Tests\Support\TestEnv;

/**
 * Regressionstests zu den Befunden des Sicherheitsreviews (OpsPilot 7.0.0-dev):
 * SSO-Konfiguration nur für Admins, keine Abwesenheitsarten in der Konfliktvorschau,
 * Client-Secret nur an den festgelegten Issuer, App-Sitzungen nach Passwortwechsel,
 * PKCE-Pflicht, Plattform-Host.
 */
final class SecurityReviewTest extends IntegrationTestCase
{
    private static ?string $idp = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->exec('DELETE FROM api_refresh_tokens');
    }

    protected function tearDown(): void
    {
        $this->db()->exec("DELETE FROM app_settings WHERE setting_key='tenant_config' OR setting_key LIKE 'oidc_cache_%'");
        parent::tearDown();
    }

    private function api(string $method, string $path, ?array $body = null, ?string $token = null): Response
    {
        $c = new Client();
        $c->sendCsrf = false;
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        return $c->request($method, '/api/v1/' . $path, $body === null ? null : json_encode($body), $h);
    }

    private function oidcConfig(string $issuer, array $extra = []): void
    {
        $cfg = ['security' => ['oidc' => $extra + ['enabled' => true, 'issuer' => $issuer, 'client_id' => 'opspilot-test',
            'claim' => 'preferred_username', 'allow_password_login' => true, 'scopes' => 'openid']]];
        $this->db()->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute([json_encode($cfg)]);
    }

    private function idp(): string
    {
        return self::$idp ??= TestEnv::startExtraServer(['FAKE_IDP_LOG' => sys_get_temp_dir() . '/opspilot-idp-token.log'], TestEnv::$root . '/tests/fixtures/fake_idp.php');
    }

    /** Kompletter Browser-Login über den Test-IdP; liefert die Callback-Antwort */
    private function oidcLogin(string $app, string $user): Response
    {
        $idp = $this->idp();
        $c = new Client(); $c->base = $app;
        $auth = $c->get('/oidc?start=1')->header('Location');
        $this->assertNotNull($auth);
        $ic = new Client(); $ic->base = $idp;
        $cb = $ic->get(substr($auth, strlen($idp)) . '&login_hint=' . urlencode($user))->header('Location');
        return $c->get(substr($cb, strlen($app)));
    }

    public function testOnlyAdminsMayConfigureSso(): void
    {
        $fields = ['action' => 'save_oidc', 'enabled' => '1', 'issuer' => 'https://idp.example', 'client_id' => 'x', 'claim' => 'preferred_username', 'allow_password_login' => '1'];
        $mgr = $this->as('mgr');
        $this->assertStringNotContainsString('id="fOidc"', $mgr->get('/organisation')->body);
        $r = $mgr->postAjax('/organisation', $fields);
        $this->assertSame(403, $r->status);
        $this->assertFalse($r->json()['success']);
        $this->assertSame(403, $mgr->postAjax('/organisation', ['action' => 'test_oidc'])->status);
        $this->assertFalse($this->db()->query("SELECT setting_value FROM app_settings WHERE setting_key='tenant_config'")->fetchColumn());
        $admin = $this->as('admin');
        $this->assertStringContainsString('id="fOidc"', $admin->get('/organisation')->body);
        $this->assertTrue($admin->postAjax('/organisation', $fields)->json()['success']);
    }

    public function testOidcNeverLogsInAdministratorsByDefault(): void
    {
        $app = TestEnv::startExtraServer(['OPSPILOT_OIDC_ALLOW_HTTP' => '1']);
        $this->oidcConfig($this->idp());
        $r = $this->oidcLogin($app, 'admin');
        $this->assertStringContainsString('url=./', (string)$r->header('Refresh'));
        $this->oidcConfig($this->idp(), ['allow_admin' => true]);
        $this->assertStringContainsString('url=dashboard', (string)$this->oidcLogin($app, 'admin')->header('Refresh'));
    }

    public function testOidcStateLivesInLaxCookieSoStrictSessionIsNotNeeded(): void
    {
        $app = TestEnv::startExtraServer(['OPSPILOT_OIDC_ALLOW_HTTP' => '1']);
        $this->oidcConfig($this->idp());
        $c = new Client(); $c->base = $app;
        $r = $c->get('/oidc?start=1');
        $this->assertMatchesRegularExpression('/^Set-Cookie: OPSOIDC=[^;]+;.*path=\/oidc;.*HttpOnly; SameSite=Lax/mi', $r->headers);
        /* Callback ohne Sitzungs-Cookie (wie bei SameSite=Strict nach Rückkehr vom IdP) */
        preg_match('/^Set-Cookie: OPSOIDC=([^;]+)/mi', $r->headers, $m);
        $auth = $r->header('Location');
        $ic = new Client(); $ic->base = $this->idp();
        $cb = $ic->get(substr($auth, strlen($this->idp())) . '&login_hint=ma1')->header('Location');
        $fresh = new Client(); $fresh->base = $app;
        $done = $fresh->request('GET', substr($cb, strlen($app)), null, ['Cookie: OPSOIDC=' . $m[1]]);
        $this->assertSame(200, $done->status);
        $this->assertStringContainsString('url=dashboard', (string)$done->header('Refresh'));
        $this->assertStringContainsString('Ma1 Test', $fresh->get('/dashboard')->body);
        /* manipuliertes Zwischenstands-Cookie wird abgelehnt */
        $bad = new Client(); $bad->base = $app;
        $r = $bad->request('GET', '/oidc?code=x&state=y', null, ['Cookie: OPSOIDC=' . base64_encode('{"state":"y"}') . '.AAAA']);
        $this->assertStringContainsString('url=./', (string)$r->header('Refresh'));
    }

    public function testEnvClientSecretIsOnlySentToPinnedIssuer(): void
    {
        $log = sys_get_temp_dir() . '/opspilot-idp-token.log';
        $idp = $this->idp();
        $this->oidcConfig($idp);
        /* festgelegter Issuer passt nicht → Secret wird nicht verwendet */
        @unlink($log);
        $app = TestEnv::startExtraServer(['OPSPILOT_OIDC_ALLOW_HTTP' => '1', 'OPSPILOT_OIDC_CLIENT_SECRET' => 'GLOBAL-SECRET', 'OPSPILOT_OIDC_ISSUER' => 'https://login.example.org']);
        $this->oidcLogin($app, 'ma1');
        $this->assertStringContainsString('token auth=-', (string)@file_get_contents($log));
        /* passender Issuer (bzw. Self-Hosting ohne Festlegung) → Secret wird gesendet */
        @unlink($log);
        $app = TestEnv::startExtraServer(['OPSPILOT_OIDC_ALLOW_HTTP' => '1', 'OPSPILOT_OIDC_CLIENT_SECRET' => 'GLOBAL-SECRET', 'OPSPILOT_OIDC_ISSUER' => $idp]);
        $this->oidcLogin($app, 'ma1');
        $this->assertStringContainsString('token auth=Basic ', (string)@file_get_contents($log));
    }

    public function testApiOidcTokenRequiresPkceAndNonce(): void
    {
        $this->oidcConfig('https://idp.invalid');
        $r = $this->api('POST', 'auth/oidc/token', ['code' => 'abc', 'redirect_uri' => 'opspilot://auth']);
        $this->assertSame(401, $r->status);
        $this->assertStringContainsString('PKCE', $r->body);
    }

    public function testConflictPreviewDoesNotRevealAbsenceTypes(): void
    {
        $uid = (int)$this->db()->query("SELECT id FROM users WHERE username='ma2'")->fetchColumn();
        $this->db()->exec("DELETE FROM absences WHERE user_id=$uid AND absence_date IN ('2026-11-03','2026-11-04','2026-11-05')");
        $this->db()->exec("INSERT INTO absences(user_id,absence_date,absence_type,is_published) VALUES($uid,'2026-11-03','krank_attest',1),($uid,'2026-11-04','urlaub_genehmigt',1),($uid,'2026-11-05','urlaub_ganz',1)");
        try {
            $changes = [];
            foreach (['2026-11-03', '2026-11-04', '2026-11-05'] as $d) $changes[] = ['user_id' => $uid, 'date' => $d, 'type' => 'clear'];
            $j = $this->as('vw')->postJson('/api/save_bulk', ['check_conflicts' => 1, 'changes' => $changes])->json();
            $this->assertSame([], $j['sick_locked']);
            $this->assertSame([], $j['vac_locked']);
            $this->assertCount(2, $j['abs_locked']);
            $this->assertSame('Abwesend', $j['overwrites'][0]['current']);
            $body = json_encode($j);
            foreach (['Krank', 'Attest', 'Urlaub'] as $w) $this->assertStringNotContainsString($w, $body);
            /* mit Detailrecht (Admin) wie bisher */
            $j = $this->as('admin')->postJson('/api/save_bulk', ['check_conflicts' => 1, 'changes' => $changes])->json();
            $this->assertSame('Krank mit Attest', $j['sick_locked'][0]['type']);
            $this->assertCount(1, $j['vac_locked']);
        } finally {
            $this->db()->exec("DELETE FROM absences WHERE user_id=$uid AND absence_date IN ('2026-11-03','2026-11-04','2026-11-05')");
        }
    }

    public function testPasswordChangeAndResetRevokeAppSessions(): void
    {
        $login = function (string $u) { $r = $this->api('POST', 'auth/login', ['username' => $u, 'password' => TestEnv::PASSWORD]); $this->assertSame(200, $r->status, $r->body); return $r->json(); };
        /* eigener Passwortwechsel */
        $t = $login('ma1');
        $web = $this->as('ma1');
        $this->assertTrue($web->postJson('/api/change_password', ['old_password' => TestEnv::PASSWORD, 'new_password' => 'Neu-Passwort-2', 'new_password2' => 'Neu-Passwort-2'])->json()['success'] ?? false);
        $this->assertSame(401, $this->api('POST', 'auth/refresh', ['refresh_token' => $t['refresh_token']])->status);
        $this->assertSame(401, $this->api('GET', 'me', null, $t['access_token'])->status);
        $this->db()->prepare("UPDATE users SET password_hash=? WHERE username='ma1'")->execute([password_hash(TestEnv::PASSWORD, PASSWORD_DEFAULT)]);
        /* Passwort muss geändert werden (z. B. nach Reset) → kein Refresh */
        $t = $login('ma2');
        $this->db()->exec("UPDATE users SET must_change_password=1 WHERE username='ma2'");
        $r = $this->api('POST', 'auth/refresh', ['refresh_token' => $t['refresh_token']]);
        $this->assertSame(401, $r->status);
        $this->assertSame('password_change_required', $r->json()['error']['code']);
        $this->db()->exec("UPDATE users SET must_change_password=0 WHERE username='ma2'");
    }

    public function testRefreshRotationKeepsAbsoluteExpiry(): void
    {
        $t = $this->api('POST', 'auth/login', ['username' => 'ma1', 'password' => TestEnv::PASSWORD])->json();
        $this->db()->exec("UPDATE api_refresh_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)");
        $r = $this->api('POST', 'auth/refresh', ['refresh_token' => $t['refresh_token']])->json();
        $this->assertLessThanOrEqual(3600, $r['refresh_expires_in']);
        $max = $this->db()->query('SELECT MAX(TIMESTAMPDIFF(SECOND, NOW(), expires_at)) FROM api_refresh_tokens')->fetchColumn();
        $this->assertLessThanOrEqual(3600, (int)$max);
    }
}
