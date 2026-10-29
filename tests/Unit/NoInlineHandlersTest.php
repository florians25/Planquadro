<?php
namespace Planquadro\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/Security.php';

/**
 * Strikte CSP (Befunde S-21, S-22): keine Inline-Handler, Inline-Skripte nur mit
 * Nonce, jede data-action verweist auf eine vorhandene Funktion.
 */
final class NoInlineHandlersTest extends TestCase
{
    /**
     * Begründete Ausnahmen (Pfad relativ zur Repo-Wurzel => Grund).
     * Eine Ausnahme schaltet alle drei Prüfungen für die Datei ab.
     */
    private const ALLOW = [
        'public/pages/planungsassistent.php' => 'wird neu gebaut (eigener Umbau, direkt CSP-konform)',
    ];

    /** Bekannte, schon vor dem Umbau undefinierte Aktionen (Verhalten unverändert gelassen). */
    private const UNDEFINED_OK = [
        'importHolidaysMod' => 'einstellungen: Dialog modImport wird nirgends geöffnet, die Funktion gab es nie',
    ];

    private static function root(): string { return dirname(__DIR__, 2); }

    /** @return array<string,string> Pfad => Inhalt */
    private static function sources(): array
    {
        $root = self::root();
        $out = [];
        foreach (['public', 'app'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!preg_match('/\.(php|js|html?)$/', $f->getFilename())) continue;
                $rel = substr($f->getPathname(), strlen($root) + 1);
                if (isset(self::ALLOW[$rel])) continue;
                $out[$rel] = (string)file_get_contents($f->getPathname());
            }
        }
        ksort($out);
        return $out;
    }

    public function testLegacyCspPagesMatchAllowlist(): void
    {
        $pages = array_map(static fn($p) => 'public/pages/' . $p . '.php', OPS_CSP_LEGACY_PAGES);
        $this->assertSame(array_keys(self::ALLOW), $pages, 'gelockerte CSP nur für Seiten auf der Ausnahmeliste – und umgekehrt');
    }

    public function testAllowlistEntriesExist(): void
    {
        foreach (self::ALLOW as $rel => $why) {
            $this->assertFileExists(self::root() . '/' . $rel, "Ausnahme veraltet: $rel");
            $this->assertNotSame('', $why);
        }
    }

    public function testNoInlineEventHandlers(): void
    {
        $hits = [];
        foreach (self::sources() as $rel => $src) {
            /* Attribut on<ereignis>= in Templates und in per JS erzeugtem Markup;
               Eigenschaften wie el.onclick = … sind CSP-konform und bleiben erlaubt */
            if (preg_match_all('/(?<![\w.$-])on[a-z]{3,}\s*=\s*(?:\\\\?["\']|[A-Za-z_(<])/i', $src, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$txt, $off]) {
                    $line = substr_count($src, "\n", 0, $off) + 1;
                    $hits[] = "$rel:$line  " . trim($txt);
                }
            }
        }
        $this->assertSame([], $hits, "Inline-Handler gefunden – data-action verwenden (docs/DEVELOPMENT.md):\n" . implode("\n", $hits));
    }

    public function testNoJavascriptUrls(): void
    {
        $hits = [];
        foreach (self::sources() as $rel => $src) {
            if (preg_match_all('/(?:href|src|action)\s*=\s*\\\\?["\']\s*javascript:/i', $src, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$txt, $off]) $hits[] = $rel . ':' . (substr_count($src, "\n", 0, $off) + 1);
            }
        }
        $this->assertSame([], $hits, 'javascript:-URLs werden von der CSP blockiert');
    }

    public function testInlineScriptsCarryNonce(): void
    {
        $hits = [];
        foreach (self::sources() as $rel => $src) {
            if (!str_ends_with($rel, '.php')) continue;
            if (preg_match_all('/<script\b([^>]*)>/i', $src, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as [$attrs, $off]) {
                    if (preg_match('/\bsrc\s*=/', $attrs) || str_contains($attrs, 'opsCspNonce()')
                        || preg_match('#type\s*=\s*["\']application/(ld\+)?json#', $attrs)) continue;
                    $hits[] = $rel . ':' . (substr_count($src, "\n", 0, $off) + 1);
                }
            }
        }
        $this->assertSame([], $hits, "Inline-<script> ohne nonce=\"<?php echo opsCspNonce(); ?>\":\n" . implode("\n", $hits));
    }

    /** Statische data-action-Namen müssen als Funktion/Aktion definiert sein (Tippfehler fallen sofort auf). */
    public function testStaticActionsAreDefined(): void
    {
        $all = self::sources();
        $code = implode("\n", $all);
        $missing = [];
        foreach ($all as $rel => $src) {
            if (!preg_match_all('/data-action(?:-[a-z]+)?=\\\\?["\']([A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*)\\\\?["\']/', $src, $m)) continue;
            foreach (array_unique($m[1]) as $name) {
                if ($rel === 'public/assets/js/actions.js') continue; // Dokumentationsbeispiele
                $seg = preg_quote(substr($name, (int)strrpos('.' . $name, '.')), '/');
                $defined = preg_match('/function\s+' . $seg . '\s*\(|[\s,{.]' . $seg . '\s*[:=]\s*function\b|pqAction\(\s*[\'"]' . $seg . '[\'"]|[{,]\s*' . $seg . '\s*:/', $code);
                if (!$defined && !isset(self::UNDEFINED_OK[$name])) $missing[] = "$rel: $name";
            }
        }
        $this->assertSame([], array_values(array_unique($missing)), 'data-action ohne Definition');
    }

    public function testActionAttrEscapesValues(): void
    {
        $attr = opsActionAttr('deleteUser', [12, "O'Brien \"<x>\" & Co", '$this', opsActionToken('this')]);
        $this->assertStringNotContainsString("'", $attr);
        $this->assertStringNotContainsString('<', $attr);
        $this->assertTrue((bool)preg_match('/data-args="([^"]*)"/', $attr, $m));
        $args = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $this->assertSame([12, "O'Brien \"<x>\" & Co", '$$this', '$this'], $args, 'Werte bleiben exakt erhalten (S-21)');
        $this->assertSame(' data-action-change="filterTeam"', opsActionAttr('filterTeam', [], 'change'));
    }

    public function testPolicyIsStrict(): void
    {
        $p = opsContentSecurityPolicy();
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/]{22}==';/", $p);
        $this->assertStringNotContainsString("'unsafe-eval'", $p);
        preg_match('/script-src[^;]*/', $p, $m);
        $this->assertStringNotContainsString('unsafe-inline', $m[0]);
        foreach (["object-src 'none'", "base-uri 'self'", "frame-ancestors 'self'", "form-action 'self'"] as $d) $this->assertStringContainsString($d, $p);
        $this->assertSame(opsCspNonce(), opsCspNonce(), 'eine Nonce je Request');
        $this->assertStringContainsString("frame-ancestors 'none'", opsContentSecurityPolicy("'none'"));
    }
}
