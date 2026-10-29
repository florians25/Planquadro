<?php
namespace Vireo\Tests\Integration;

use Vireo\Tests\Support\Client;
use Vireo\Tests\Support\IntegrationTestCase;
use Vireo\Tests\Support\TestEnv;

/** Grundfunktionen: Login, Seitenaufrufe je Rolle, Rechte-Gates der Seiten. */
final class SmokeTest extends IntegrationTestCase
{
    public function testLoginAndDashboardForEveryRole(): void
    {
        foreach (array_keys(TestEnv::USERS) as $u) {
            $c = $this->as($u);
            $r = $c->get('/dashboard');
            $this->assertSame(200, $r->status, "Dashboard für $u");
            $this->assertStringContainsString('nav-brand', $r->body, "Navigation für $u");
        }
    }

    public function testWrongPasswordIsRejected(): void
    {
        $c = new Client();
        $r = $c->login('lager', 'falsch');
        $this->assertStringContainsString('Benutzername oder Passwort falsch', $r->body);
        $this->db()->exec("DELETE FROM login_attempts");
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $r = (new Client())->get('/monatsplanung');
        $this->assertSame(302, $r->status);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function pageGates(): array
    {
        return [
            'monatsplanung admin'      => ['admin', '/monatsplanung', true],
            'monatsplanung teamleiter' => ['tl', '/monatsplanung', true],
            'monatsplanung mitarbeiter'=> ['ma1', '/monatsplanung', false],
            'mitarbeiter manager'      => ['mgr', '/mitarbeiter', true],
            'mitarbeiter verwaltung'   => ['vw', '/mitarbeiter', true],
            'mitarbeiter teamleiter'   => ['tl', '/mitarbeiter', false],
            'einstellungen manager'    => ['mgr', '/einstellungen', true],
            'einstellungen teamleiter' => ['tl', '/einstellungen', false],
            'rollen admin'             => ['admin', '/rollen', true],
            'rollen manager'           => ['mgr', '/rollen', false],
            'planbereiche verwaltung'  => ['vw', '/planbereiche', true],
            'planbereiche teamleiter'  => ['tl', '/planbereiche', false],
            'gesamtplan mitarbeiter'   => ['ma1', '/gesamtplan', true],
            'mein_plan azubi'          => ['azubi', '/mein_plan', true],
            'jahresuebersicht lager'   => ['lager', '/jahresuebersicht', true],
        ];
    }

    /** @dataProvider pageGates */
    public function testPageAccess(string $user, string $page, bool $allowed): void
    {
        $r = $this->as($user)->get($page);
        $this->assertSame(200, $r->status);
        $denied = str_contains($r->body, 'access-denied-page');
        $this->assertSame(!$allowed, $denied, "$user auf $page");
    }
}
