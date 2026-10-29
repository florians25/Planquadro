<?php
namespace Planquadro\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/Events.php';

/** Filterregeln für Live-Ereignisse (pqEventForViewer, rein). */
final class EventFilterTest extends TestCase
{
    private function row(array $o = []): array
    {
        return $o + ['event_type' => 'cells_changed', 'is_published' => 0, 'is_internal' => 0, 'months' => '["2026-11"]',
            'cells' => '[[5,"2026-11-02"],[6,"2026-11-02"],[7,"2026-12-01"]]', 'data' => null, 'actor_id' => 9];
    }

    private function viewer(array $o = []): array
    {
        return $o + ['id' => 5, 'channel' => 'web', 'drafts' => true, 'team' => true, 'internal' => true, 'approve' => false, 'visible' => null];
    }

    public function testPlannerSeesVisibleCellsOnly(): void
    {
        $e = pqEventForViewer($this->row(), $this->viewer(['visible' => [5 => 0, 7 => 1]]));
        $this->assertSame([[5, '2026-11-02'], [7, '2026-12-01']], $e['cells']);
        $this->assertSame(['2026-11', '2026-12'], $e['months']);
        $this->assertTrue($e['self']);
        $this->assertFalse($e['own']);
    }

    public function testDraftsNotForEmployeesOrApp(): void
    {
        $this->assertNull(pqEventForViewer($this->row(), $this->viewer(['drafts' => false])));
        $this->assertNull(pqEventForViewer($this->row(), $this->viewer(['channel' => 'app', 'drafts' => false])));
    }

    public function testPublishedOwnCellForEmployeeWithoutTeamView(): void
    {
        $e = pqEventForViewer($this->row(['is_published' => 1, 'event_type' => 'published']), $this->viewer(['drafts' => false, 'team' => false]));
        $this->assertSame([[5, '2026-11-02']], $e['cells']);
        $this->assertNull(pqEventForViewer($this->row(['is_published' => 1, 'cells' => '[[6,"2026-11-02"]]']), $this->viewer(['drafts' => false, 'team' => false])));
    }

    public function testInternalOnlyWithPermission(): void
    {
        $this->assertNull(pqEventForViewer($this->row(['is_internal' => 1]), $this->viewer(['internal' => false])));
        $this->assertNotNull(pqEventForViewer($this->row(['is_internal' => 1]), $this->viewer()));
    }

    public function testRequestsForRequesterAndApprover(): void
    {
        $req = $this->row(['event_type' => 'request_changed', 'cells' => '[[6,"2026-11-02"]]']);
        $this->assertNotNull(pqEventForViewer($req, $this->viewer(['id' => 6, 'drafts' => false, 'team' => false, 'channel' => 'app'])));
        $this->assertNotNull(pqEventForViewer($req, $this->viewer(['drafts' => false, 'approve' => true])));
        $this->assertNull(pqEventForViewer($req, $this->viewer(['drafts' => false, 'approve' => true, 'visible' => [5 => 0]])));
        $this->assertNull(pqEventForViewer($req, $this->viewer(['drafts' => false, 'team' => true])));
    }

    public function testPresenceOnlyForPlannersOnWeb(): void
    {
        $p = $this->row(['event_type' => 'presence', 'cells' => null, 'data' => '{"page":"monatsplanung","action":"join"}']);
        $this->assertSame('join', pqEventForViewer($p, $this->viewer())['action']);
        $this->assertNull(pqEventForViewer($p, $this->viewer(['drafts' => false])));
        $this->assertNull(pqEventForViewer($p, $this->viewer(['channel' => 'app'])));
    }

    public function testWholeMonthEventCarriesNoCells(): void
    {
        $e = pqEventForViewer($this->row(['is_published' => 1, 'cells' => null]), $this->viewer(['drafts' => false, 'team' => false]));
        $this->assertNull($e['cells']);
        $this->assertSame(['2026-11'], $e['months']);
        $this->assertNull(pqEventForViewer($this->row(['cells' => null]), $this->viewer(['drafts' => false])));
    }
}
