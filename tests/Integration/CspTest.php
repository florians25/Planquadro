<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;

/**
 * Strikte Content-Security-Policy (S-21, S-22): Header aus PHP mit Nonce je
 * Request, jedes Inline-Skript trägt genau diese Nonce, kein Inline-Handler im
 * ausgelieferten HTML.
 */
final class CspTest extends IntegrationTestCase
{
    /** Seiten, die noch umgebaut werden (siehe tests/Unit/NoInlineHandlersTest::ALLOW) */
    private const PENDING = [];

    private const PAGES = ['/dashboard', '/mein_plan', '/monatsplanung', '/gesamtplan', '/jahresuebersicht',
        '/urlaubsplanung', '/abwesenheiten', '/genehmigungen', '/mitarbeiter', '/planbereiche', '/feiertage',
        '/stammdaten', '/rollen', '/einstellungen', '/organisation', '/profil', '/vertretung', '/datenschutz', '/planungsassistent'];

    private function assertStrictPage(string $label, string $csp, string $body): void
    {
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)';/", $csp, $label);
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $nonce = $m[1];
        preg_match('/script-src[^;]*/', $csp, $s);
        $this->assertStringNotContainsString('unsafe-inline', $s[0], $label);
        $this->assertStringNotContainsString('unsafe-eval', $csp, $label);
        foreach (["object-src 'none'", "base-uri 'self'", "form-action 'self'"] as $d) $this->assertStringContainsString($d, $csp, $label);

        preg_match_all('/<script\b([^>]*)>/i', $body, $tags);
        foreach ($tags[1] as $attrs) {
            if (preg_match('/\bsrc=/', $attrs)) continue;
            $this->assertStringContainsString('nonce="' . $nonce . '"', $attrs, "$label: Inline-Skript ohne gültige Nonce");
        }
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+\s*=/i', $body, "$label: Inline-Handler im HTML");
    }

    public function testLoginPageHasStrictCsp(): void
    {
        $r = (new Client())->get('/');
        $this->assertStrictPage('Login', (string)$r->header('Content-Security-Policy'), $r->body);
    }

    public function testAllPagesHaveStrictCspAndNoInlineHandlers(): void
    {
        foreach (['admin', 'mgr', 'tl', 'ma1'] as $user) {
            $c = $this->as($user);
            foreach (self::PAGES as $p) {
                $r = $c->get($p);
                if ($r->status !== 200) continue; // Rechte-Gate (SmokeTest)
                $this->assertStrictPage("$user $p", (string)$r->header('Content-Security-Policy'), $r->body);
            }
        }
    }

    public function testNonceChangesPerRequest(): void
    {
        $c = $this->as('admin');
        $a = (string)$c->get('/dashboard')->header('Content-Security-Policy');
        $b = (string)$c->get('/dashboard')->header('Content-Security-Policy');
        $this->assertNotSame($a, $b);
    }

    public function testPendingPagesStillLoad(): void
    {
        $this->assertIsArray(self::PENDING);
        foreach (self::PENDING as $p) {
            $r = $this->as('admin')->get($p);
            $this->assertSame(200, $r->status, $p);
            $csp = (string)$r->header('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self' 'unsafe-inline';", $csp, 'Übergang bis zum Neubau');
            $this->assertStringNotContainsString('nonce-', $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
        }
    }

    /** S-21: Namen mit Apostroph/Anführungszeichen landen unverändert und sicher escaped in data-args */
    public function testActionArgumentsAreEscaped(): void
    {
        $uid = $this->uid('ma1');
        $old = $this->db()->query("SELECT first_name FROM users WHERE id=$uid")->fetchColumn();
        $this->db()->prepare('UPDATE users SET first_name=? WHERE id=?')->execute(["O'Ne\"il<b>", $uid]);
        try {
            $r = $this->as('admin')->get('/mitarbeiter');
            $this->assertSame(200, $r->status);
            $this->assertMatchesRegularExpression('/data-action="deleteUser" data-args="\[' . $uid . ',&quot;[^"]*O&#039;Ne\\\\&quot;il&lt;b&gt;&quot;\]"/', $r->body);
        } finally {
            $this->db()->prepare('UPDATE users SET first_name=? WHERE id=?')->execute([$old, $uid]);
        }
    }
}
