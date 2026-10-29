<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\Response;
use Planquadro\Tests\Support\TestEnv;

/** TenantConfig (Branding, Module, Navigation, Widgets), Stammdaten, OIDC. */
final class PlatformFeaturesTest extends IntegrationTestCase
{
    private static ?string $idp = null;
    private static ?string $app = null;

    protected function tearDown(): void
    {
        $this->db()->exec("DELETE FROM app_settings WHERE setting_key IN ('tenant_config') OR setting_key LIKE 'oidc_cache_%'");
        $this->db()->exec("UPDATE app_settings SET setting_value='Planquadro' WHERE setting_key='company_name'");
        $this->db()->exec("UPDATE app_settings SET setting_value='1' WHERE setting_key='approval_system_enabled'");
    }

    private function org(Client $c, array $fields): array
    {
        return $c->postAjax('/organisation', $fields)->json();
    }

    public function testBrandingIsAppliedAndValidated(): void
    {
        $c = $this->as('admin');
        $this->assertFalse($this->org($c, ['action' => 'save_branding', 'name' => 'X', 'primary' => 'red', 'accent' => '#000000'])['success']);
        $this->assertTrue($this->org($c, ['action' => 'save_branding', 'name' => 'Muster Service GmbH', 'primary' => '#ffcc00', 'accent' => '#0055aa'])['success']);
        $page = $c->get('/dashboard')->body;
        $this->assertStringContainsString('--p:#ffcc00', $page);
        $this->assertStringContainsString('<title>Muster Service GmbH', $page);
        /* Gelb ist als Text auf Weiß zu hell → abgedunkelte Textvariante, dunkle Schrift auf Buttons */
        $this->assertMatchesRegularExpression('/--p-text:#(?!ffcc00)[0-9a-f]{6}/', $page);
        $this->assertStringContainsString('--p-on:#111827', $page);
        /* Name ist zugleich die Alt-Einstellung company_name */
        $this->assertSame('Muster Service GmbH', $this->db()->query("SELECT setting_value FROM app_settings WHERE setting_key='company_name'")->fetchColumn());
        /* Login-Seite zeigt den Namen */
        $this->assertStringContainsString('Muster Service GmbH', (new Client())->get('/')->body);
    }

    public function testOnlySettingsManagersMayChangeConfig(): void
    {
        $r = $this->as('tl')->postAjax('/organisation', ['action' => 'save_modules', 'modules' => []]);
        $this->assertStringContainsString('access-denied-page', $r->body);
        $this->assertNull($this->db()->query("SELECT setting_value FROM app_settings WHERE setting_key='tenant_config'")->fetchColumn() ?: null);
    }

    public function testDisabledModuleIsHiddenAndBlocked(): void
    {
        $c = $this->as('admin');
        $mods = ['assistant', 'vacation', 'deputation', 'annual', 'wots', 'ical', 'masterdata', 'mobile', 'approvals']; // ohne absences
        $this->assertTrue($this->org($c, ['action' => 'save_modules', 'modules' => $mods])['success']);
        $tl = $this->as('tl');
        $this->assertStringNotContainsString('href="abwesenheiten"', $tl->get('/dashboard')->body);
        $this->assertStringContainsString('access-denied-page', $tl->get('/abwesenheiten')->body);
        $r = $tl->postAjax('/abwesenheiten', ['action' => 'list']);
        $this->assertSame(403, $r->status);
        /* Altschlüssel bleibt synchron */
        $this->assertSame('1', $this->db()->query("SELECT setting_value FROM app_settings WHERE setting_key='approval_system_enabled'")->fetchColumn());
    }

    public function testNavigationOrderAndHiddenItems(): void
    {
        $c = $this->as('admin');
        $order = ['gesamtplan', 'dashboard', 'mein_plan'];
        $this->assertTrue($this->org($c, ['action' => 'save_navigation', 'order' => $order, 'hidden' => ['jahresuebersicht', 'dashboard']])['success']);
        $page = $c->get('/mein_plan')->body;
        $this->assertStringContainsString('style="order:0" href="../gesamtplan"', $page);
        $this->assertStringContainsString('style="order:1" href="../dashboard"', $page, 'Dashboard kann nicht ausgeblendet werden');
        $this->assertStringNotContainsString('href="../jahresuebersicht" class="nav-link', $page);
        /* ausgeblendet ≠ gesperrt */
        $this->assertStringNotContainsString('access-denied-page', $c->get('/jahresuebersicht')->body);
    }

    public function testDashboardWidgets(): void
    {
        $c = $this->as('admin');
        $this->assertTrue($this->org($c, ['action' => 'save_widgets', 'widgets' => ['announcements']])['success']);
        $page = $c->get('/dashboard')->body;
        $this->assertStringNotContainsString('Planstatus', $page);
        $this->assertStringNotContainsString('Einsatz-Verteilung', $page);
    }

    public function testLogoUploadAcceptsOnlyRasterImages(): void
    {
        $c = $this->as('admin');
        $svg = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $r = $this->upload($c, $svg, 'logo.svg', 'image/svg+xml');
        $this->assertFalse($r['success']);
        $png = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='));
        $r = $this->upload($c, $png, 'logo.png', 'image/png');
        $this->assertTrue($r['success'], json_encode($r));
        $logo = (new Client())->get('/api/branding?logo=1');
        $this->assertSame(200, $logo->status);
        $this->assertSame('image/png', $logo->header('Content-Type'));
        $this->assertStringContainsString('sandbox', (string)$logo->header('Content-Security-Policy'));
        $this->assertTrue($this->org($c, ['action' => 'remove_logo'])['success']);
        $this->assertSame(404, (new Client())->get('/api/branding?logo=1')->status);
    }

    private function upload(Client $c, string $file, string $name, string $mime): array
    {
        $ch = curl_init(TestEnv::baseUrl() . '/organisation');
        $jar = (new \ReflectionProperty($c, 'jar'))->getValue($c);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest', 'X-CSRF-Token: ' . $c->csrf],
            CURLOPT_POSTFIELDS => ['action' => 'upload_logo', 'logo' => new \CURLFile($file, $mime, $name)]]);
        $out = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        return $out ?: ['success' => false];
    }

    public function testMasterDataCrudAndAreaLink(): void
    {
        $vw = $this->as('vw');
        $r = $vw->postAjax('/stammdaten', ['action' => 'save_customer', 'name' => 'Kunde Nord GmbH', 'address' => 'Musterstr. 1', 'phone' => '+49 221 0', 'email' => 'kein-mail', 'active' => 1])->json();
        $this->assertFalse($r['success'], 'ungültige E-Mail');
        $r = $vw->postAjax('/stammdaten', ['action' => 'save_customer', 'name' => 'Kunde Nord GmbH', 'address' => 'Musterstr. 1', 'notes' => 'Tor 3', 'active' => 1])->json();
        $this->assertTrue($r['success']);
        $cid = (int)$this->db()->query("SELECT id FROM customers WHERE name='Kunde Nord GmbH'")->fetchColumn();
        $r = $vw->postAjax('/stammdaten', ['action' => 'save_location', 'name' => 'Köln Nord', 'customer_id' => $cid, 'lat' => '50,95', 'lng' => '6.95', 'active' => 1])->json();
        $this->assertTrue($r['success']);
        $lid = (int)$this->db()->query("SELECT id FROM locations WHERE name='Köln Nord'")->fetchColumn();
        $area = $this->areaId('K1');
        $r = $vw->postAjax('/stammdaten', ['action' => 'save_area_links', "area[$area][customer]" => $cid, "area[$area][location]" => $lid])->json();
        $this->assertTrue($r['success']);
        $this->assertSame([$cid, $lid], array_map('intval', array_values($this->db()->query("SELECT customer_id, location_id FROM plan_areas WHERE id=$area")->fetch())));
        $r = $vw->postAjax('/stammdaten', ['action' => 'save_qualification', 'name' => 'Elektrofachkraft', 'color' => '#123456'])->json();
        $qid = (int)$this->db()->query("SELECT id FROM qualifications WHERE name='Elektrofachkraft'")->fetchColumn();
        $r = $vw->postAjax('/stammdaten', ['action' => 'save_holders', 'id' => $qid, 'users' => [$this->uid('ma1'), $this->uid('ma2')]])->json();
        $this->assertTrue($r['success']);
        $this->assertSame(2, (int)$this->db()->query("SELECT COUNT(*) FROM user_qualifications WHERE qualification_id=$qid")->fetchColumn());
        /* Teamleiter haben keinen Zugriff */
        $this->assertStringContainsString('access-denied-page', $this->as('tl')->get('/stammdaten')->body);
        /* Löschen eines Kunden löst die Zuordnung */
        $this->assertTrue($vw->postAjax('/stammdaten', ['action' => 'delete', 'type' => 'customer', 'id' => $cid])->json()['success']);
        $this->assertNull($this->db()->query("SELECT customer_id FROM plan_areas WHERE id=$area")->fetchColumn() ?: null);
        $this->db()->exec('DELETE FROM locations'); $this->db()->exec('DELETE FROM qualifications'); $this->db()->exec('UPDATE plan_areas SET customer_id=NULL, location_id=NULL');
    }

    /* ------------------------------ OIDC ------------------------------ */

    private function oidcSetup(bool $allowPassword = true): array
    {
        if (!self::$idp) {
            self::$idp = TestEnv::startExtraServer([], TestEnv::$root . '/tests/fixtures/fake_idp.php');
            self::$app = TestEnv::startExtraServer(['PQ_OIDC_ALLOW_HTTP' => '1']);
        }
        /* self::$app: gleiche DB, eigener Server mit HTTP-Freigabe für den Test-IdP */
        $cfg = ['security' => ['oidc' => ['enabled' => true, 'issuer' => self::$idp, 'client_id' => 'planquadro-test', 'claim' => 'preferred_username',
            'label' => 'Mit Testkonto', 'allow_password_login' => $allowPassword, 'client_secret' => '', 'scopes' => 'openid']]];
        $this->db()->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([json_encode($cfg)]);
        return [self::$app, self::$idp];
    }

    private function oidcLogin(string $user, bool $tamper = false, ?Client $c = null): array
    {
        $c = $c ?? new Client();
        $c->base = self::$app;
        $r = $c->get('/oidc?start=1');
        $this->assertSame(302, $r->status, $r->body);
        $auth = $r->header('Location');
        $this->assertStringStartsWith(self::$idp . '/authorize?', $auth);
        $this->assertStringContainsString('code_challenge_method=S256', $auth);
        $idpClient = new Client(); $idpClient->base = self::$idp;
        $idpResp = $idpClient->get(substr($auth, strlen(self::$idp)) . '&login_hint=' . urlencode($user) . ($tamper ? '&tamper=1' : ''));
        $cb = $idpResp->header('Location');
        $this->assertStringStartsWith(self::$app . '/oidc?', $cb);
        $r = $c->get(substr($cb, strlen(self::$app)));
        return [$c, $r];
    }

    /** Ziel der Weiterleitung nach dem Callback (HTML-Refresh wegen SameSite=Strict, siehe oidc.php) */
    private function oidcTarget(Response $r): ?string
    {
        if ($r->header('Location')) return $r->header('Location');
        $ref = $r->header('Refresh');
        return $ref && preg_match('/url=(.*)$/', $ref, $m) ? $m[1] : null;
    }

    public function testOidcLoginMapsToExistingActiveUser(): void
    {
        $this->oidcSetup();
        $this->assertStringContainsString('Mit Testkonto', (new Client())->get('/')->body);
        [$c, $r] = $this->oidcLogin('ma1');
        $this->assertSame('dashboard', $this->oidcTarget($r));
        $this->assertStringContainsString('Ma1 Test', $c->get('/dashboard')->body);
    }

    public function testOidcRejectsUnknownUserTamperedTokenAndReplayedState(): void
    {
        $this->oidcSetup();
        [, $r] = $this->oidcLogin('gibtsnicht');
        $this->assertSame('./', $this->oidcTarget($r));
        [$c, $r] = $this->oidcLogin('ma1', true);
        $this->assertSame('./', $this->oidcTarget($r), 'manipuliertes ID-Token');
        $this->assertSame(302, $c->get('/dashboard')->status);
        /* Callback ohne vorherigen Start (fremder state) */
        $c2 = new Client(); $c2->base = self::$app;
        $this->assertSame('./', $this->oidcTarget($c2->get('/oidc?code=abc&state=def')));
    }

    public function testPasswordLoginCanBeRestrictedToAdmins(): void
    {
        $this->oidcSetup(false);
        $c = new Client(); $c->base = self::$app;
        $this->assertStringNotContainsString('id="loginForm"', $c->get('/')->body, 'Passwortformular nur über den Notfall-Link');
        $c->get('/?password=1');
        $r = $c->login('ma1', TestEnv::PASSWORD);
        $this->assertStringContainsString('Bitte melden Sie sich über Ihr Firmenkonto an.', $r->body);
        /* gleiche Antwort bei falschem Passwort (kein Orakel) */
        $c3 = new Client(); $c3->base = self::$app; $c3->get('/?password=1');
        $this->assertStringContainsString('Bitte melden Sie sich über Ihr Firmenkonto an.', $c3->login('ma1', 'falsch-falsch')->body);
        $c = new Client(); $c->base = self::$app;
        $c->get('/?password=1');
        $r = $c->login('admin', TestEnv::PASSWORD);
        $this->assertSame('dashboard', $this->oidcTarget($r), 'Notfallzugang für Administratoren');
    }
}
