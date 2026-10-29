<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;

/**
 * Planungsmatrix: Drag & Drop nutzt den bestehenden Schreibweg save_bulk
 * (Ziel setzen + Quelle leeren in EINEM Aufruf, mit Revisionen) und die
 * gemeinsame Filterleiste von Monats- und Gesamtplan.
 */
final class MatrixDndTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPlan();
    }

    private function revs(Client $c, string $date): array
    {
        $r = $c->postJson('/api/live_status', ['action' => 'cells', 'year' => (int)substr($date, 0, 4), 'month' => (int)substr($date, 5, 2)]);
        return $r->json()['revs'] ?? [];
    }

    /** Nutzlast wie pqDnd.changes() im Browser */
    private function move(Client $c, array $src, array $tgt, string $type, string $value, array $revs, bool $copy = false)
    {
        $ch = [['user_id' => $tgt[0], 'date' => $tgt[1], 'type' => $type, 'value' => $value, 'rev' => $revs[$tgt[0] . '_' . $tgt[1]] ?? '0']];
        if (!$copy) $ch[] = ['user_id' => $src[0], 'date' => $src[1], 'type' => 'clear', 'value' => '', 'rev' => $revs[$src[0] . '_' . $src[1]] ?? '0'];
        return $c->postJson('/api/save_bulk', ['changes' => $ch]);
    }

    public function testMoveSetsTargetAndClearsSourceInOneSave(): void
    {
        $tl = $this->as('tl'); $ma1 = $this->uid('ma1'); $az = $this->uid('azubi');
        $d1 = $this->workday(); $d2 = $this->workday(0, 3); $k1 = (string)$this->areaId('K1');
        $r = $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d1, 'type' => 'assignment', 'value' => $k1, 'rev' => '0']]]);
        $this->assertSame(200, $r->status, $r->body);

        $r = $this->move($tl, [$ma1, $d1], [$az, $d2], 'assignment', $k1, $this->revs($tl, $d1));
        $this->assertSame(200, $r->status, $r->body);
        $j = $r->json();
        $this->assertSame(2, $j['count']);
        $this->assertArrayHasKey($az . '_' . $d2, $j['revs']);
        $this->assertSame($k1, $this->draft($az, $d2)['change_value']);
        $this->assertSame('clear', $this->draft($ma1, $d1)['action']);
    }

    public function testCopyKeepsSource(): void
    {
        $tl = $this->as('tl'); $ma1 = $this->uid('ma1');
        $d1 = $this->workday(); $d2 = $this->workday(0, 4);
        $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d1, 'type' => 'absence', 'value' => 'gleitzeit', 'rev' => '0']]]);
        $r = $this->move($tl, [$ma1, $d1], [$ma1, $d2], 'absence', 'gleitzeit', $this->revs($tl, $d1), true);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('gleitzeit', $this->draft($ma1, $d1)['change_value']);
        $this->assertSame('gleitzeit', $this->draft($ma1, $d2)['change_value']);
    }

    public function testStaleTargetRevisionGivesConflictAndChangesNothing(): void
    {
        $tl = $this->as('tl'); $mgr = $this->as('mgr'); $ma1 = $this->uid('ma1');
        $d1 = $this->workday(); $d2 = $this->workday(0, 3);
        $k1 = (string)$this->areaId('K1'); $k2 = (string)$this->areaId('K2');
        $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d1, 'type' => 'assignment', 'value' => $k1, 'rev' => '0']]]);
        $seen = $this->revs($tl, $d1);
        /* Jemand anderes belegt das Ziel, nachdem der Plan geladen wurde */
        $r = $mgr->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d2, 'type' => 'assignment', 'value' => $k2, 'rev' => '0']]]);
        $this->assertSame(200, $r->status, $r->body);

        $r = $this->move($tl, [$ma1, $d1], [$ma1, $d2], 'assignment', $k1, $seen);
        $this->assertSame(409, $r->status, $r->body);
        $this->assertSame($d2, $r->json()['conflicts'][0]['iso']);
        $this->assertSame($k2, $this->draft($ma1, $d2)['change_value'], 'Ziel unverändert');
        $this->assertSame($k1, $this->draft($ma1, $d1)['change_value'], 'Quelle unverändert (alles oder nichts)');
    }

    public function testMoveOutsideOwnTeamIsIgnoredByServer(): void
    {
        /* tl ist nicht für ma2 zuständig (Vorgesetzter tl2) – der Server verwirft das Ziel */
        $tl = $this->as('tl'); $ma1 = $this->uid('ma1'); $ma2 = $this->uid('ma2');
        $d1 = $this->workday(); $k1 = (string)$this->areaId('K1');
        $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d1, 'type' => 'assignment', 'value' => $k1, 'rev' => '0']]]);
        $r = $this->move($tl, [$ma1, $d1], [$ma2, $d1], 'assignment', $k1, $this->revs($tl, $d1));
        $this->assertSame(200, $r->status, $r->body);
        $this->assertNull($this->draft($ma2, $d1));
    }

    public function testFilterBarIsTheSameOnBothPlans(): void
    {
        $tl = $this->as('tl');
        foreach (['/monatsplanung', '/gesamtplan'] as $p) {
            $html = $tl->get($p)->body;
            $this->assertStringContainsString('id="filterName"', $html, $p);
            $this->assertStringContainsString('id="filterCount"', $html, $p);
            $this->assertStringContainsString('data-pq-filter="mine"', $html, $p . ': „Meine Mitarbeiter“ für Teamleiter');
            $this->assertMatchesRegularExpression('/class="plan-row[^"]*"[^>]*data-mine="1"/', $html, $p);
            $this->assertStringNotContainsString('oninput="applyFilters()"', $html, $p);
        }
        $html = $this->as('admin')->get('/monatsplanung')->body;
        $this->assertStringNotContainsString('data-pq-filter="mine"', $html, 'Admin hat keinen Team-Schalter');
    }
}
