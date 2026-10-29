<?php
namespace OpsPilot\Tests\Integration;

use OpsPilot\Tests\Support\Client;
use OpsPilot\Tests\Support\IntegrationTestCase;
use OpsPilot\Tests\Support\TestEnv;

/**
 * Mehrbenutzerbetrieb: Optimistic Concurrency, Konflikte, Sperren,
 * Änderungsprotokoll (Befunde D-01, D-02, D-06).
 */
final class ConcurrencyTest extends IntegrationTestCase
{
    private function revs(Client $c, string $date): array
    {
        $r = $c->postJson('/api/live_status', ['action' => 'cells', 'year' => (int)substr($date, 0, 4), 'month' => (int)substr($date, 5, 2)]);
        return $r->json()['revs'] ?? [];
    }

    private function rev(Client $c, int $uid, string $date): string
    {
        return $this->revs($c, $date)[$uid . '_' . $date] ?? '0';
    }

    private function save(Client $c, int $uid, string $date, string $type, string $value, ?string $rev, bool $overwrite = false)
    {
        $ch = ['user_id' => $uid, 'date' => $date, 'type' => $type, 'value' => $value];
        if ($rev !== null) $ch['rev'] = $rev;
        $p = ['changes' => [$ch]];
        if ($overwrite) $p['overwrite_conflicts'] = 1;
        return $c->postJson('/api/save_bulk', $p);
    }

    private function logActions(int $uid, string $date): array
    {
        $s = $this->db()->prepare('SELECT action FROM plan_change_log WHERE user_id=? AND plan_date=? ORDER BY id');
        $s->execute([$uid, $date]);
        return $s->fetchAll(\PDO::FETCH_COLUMN);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPlan();
        $this->db()->exec('DELETE FROM plan_change_log');
    }

    public function testD01StaleRevisionIsRejectedAndNothingIsOverwritten(): void
    {
        $ma = $this->uid('ma1'); $d = $this->workday();
        $a = $this->as('tl'); $b = $this->as('mgr');
        $revA = $this->rev($a, $ma, $d);
        $revB = $this->rev($b, $ma, $d);
        $this->assertSame('0', $revA);

        $r = $this->save($a, $ma, $d, 'assignment', (string)$this->areaId('K1'), $revA);
        $this->assertSame(200, $r->status, $r->body);
        $newRev = $r->json()['revs'][$ma . '_' . $d];
        $this->assertNotSame('0', $newRev);

        /* B hat noch den alten Stand gesehen */
        $r = $this->save($b, $ma, $d, 'assignment', (string)$this->areaId('K2'), $revB);
        $this->assertSame(409, $r->status);
        $j = $r->json();
        $this->assertSame('conflict', $j['error']);
        $this->assertSame('K1', $j['conflicts'][0]['current']);
        $this->assertSame('Tl Test', $j['conflicts'][0]['by']);
        $this->assertSame($newRev, $j['conflicts'][0]['rev']);

        $draft = $this->draft($ma, $d);
        $this->assertSame((string)$this->areaId('K1'), $draft['change_value'], 'Entwurf von A muss erhalten bleiben');
        $this->assertSame($this->uid('tl'), (int)$draft['created_by']);
        $this->assertSame(['draft_set'], $this->logActions($ma, $d));
    }

    public function testD01ExplicitOverwriteIsAllowedAndLogged(): void
    {
        $ma = $this->uid('ma1'); $d = $this->workday();
        $a = $this->as('tl'); $b = $this->as('mgr');
        $this->save($a, $ma, $d, 'assignment', (string)$this->areaId('K1'), '0');
        $r = $this->save($b, $ma, $d, 'assignment', (string)$this->areaId('K2'), '0');
        $this->assertSame(409, $r->status);
        $current = $r->json()['conflicts'][0]['rev'];
        $r = $this->save($b, $ma, $d, 'assignment', (string)$this->areaId('K2'), '0', true);
        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->json()['overwritten']);
        $this->assertSame((string)$this->areaId('K2'), $this->draft($ma, $d)['change_value']);
        $this->assertSame(['draft_set', 'overwrite'], $this->logActions($ma, $d));
        /* Mit aktueller Revision ist es kein Konflikt */
        $r = $this->save($a, $ma, $d, 'clear', '', $this->rev($a, $ma, $d));
        $this->assertSame(200, $r->status, $r->body);
    }

    public function testOwnUnconfirmedDraftIsNoConflict(): void
    {
        $ma = $this->uid('ma1'); $d = $this->workday();
        $a = $this->as('tl');
        $this->save($a, $ma, $d, 'assignment', (string)$this->areaId('K1'), '0');
        /* zweiter schneller Klick mit noch alter Revision */
        $r = $this->save($a, $ma, $d, 'assignment', (string)$this->areaId('K3'), '0');
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame((string)$this->areaId('K3'), $this->draft($ma, $d)['change_value']);
    }

    public function testRequestWithoutRevisionKeepsLegacyBehaviour(): void
    {
        $ma = $this->uid('ma1'); $d = $this->workday();
        $this->save($this->as('tl'), $ma, $d, 'assignment', (string)$this->areaId('K1'), null);
        $r = $this->save($this->as('mgr'), $ma, $d, 'absence', 'gleitzeit', null);
        $this->assertSame(200, $r->status);
        $this->assertSame('gleitzeit', $this->draft($ma, $d)['change_value']);
    }

    public function testParallelWritesOnSameCellNeverLoseAnUpdate(): void
    {
        $ma = $this->uid('ma1'); $d = $this->workday(2);
        $users = ['tl', 'mgr', 'tl', 'mgr', 'tl', 'mgr'];
        $clients = array_map(fn($u) => $this->as($u), $users);
        $mh = curl_multi_init(); $handles = [];
        foreach ($clients as $i => $c) {
            $body = json_encode(['changes' => [['user_id' => $ma, 'date' => $d, 'type' => 'assignment', 'value' => (string)$this->areaId($i % 2 ? 'K2' : 'K1'), 'rev' => '0']]]);
            $ch = curl_init(TestEnv::baseUrl() . '/api/save_bulk');
            $jar = (new \ReflectionProperty($c, 'jar'))->getValue($c);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar,
                CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $c->csrf]]);
            curl_multi_add_handle($mh, $ch); $handles[$i] = $ch;
        }
        do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running);
        $status = [];
        foreach ($handles as $i => $ch) { $status[$i] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_multi_remove_handle($mh, $ch); }
        curl_multi_close($mh);

        $this->assertNotContains(500, $status, 'kein Serverfehler bei parallelen Schreibzugriffen');
        $draft = $this->draft($ma, $d);
        $winnerKey = (int)$draft['created_by'] === $this->uid('tl') ? 'tl' : 'mgr';
        foreach ($users as $i => $u) {
            if ($u === $winnerKey) $this->assertSame(200, $status[$i], "Anfrage $i ($u)");
            else $this->assertSame(409, $status[$i], "Anfrage $i ($u) muss als Konflikt abgewiesen werden");
        }
        /* Jede gespeicherte Änderung ist protokolliert */
        $this->assertCount(count(array_filter($status, fn($s) => $s === 200)), $this->logActions($ma, $d));
    }

    public function testD02PublishAppliesOwnDraftsAndLogsThem(): void
    {
        $ma = $this->uid('ma1'); $ma2 = $this->uid('azubi'); $d = $this->workday();
        $tl = $this->as('tl'); $mgr = $this->as('mgr');
        $this->save($tl, $ma, $d, 'assignment', (string)$this->areaId('K1'), '0');
        $this->save($mgr, $ma2, $d, 'absence', 'gleitzeit', '0');
        $r = $tl->postJson('/api/publish', ['year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2), 'publish_all' => 0]);
        $j = $r->json();
        $this->assertTrue($j['success'], $r->body);
        $this->assertSame(1, $j['applied']);
        $this->assertSame(1, $j['other_drafts']);
        $this->assertNull($this->draft($ma, $d));
        $this->assertNotNull($this->draft($ma2, $d), 'Entwurf eines anderen bleibt bestehen');
        $this->assertSame($this->areaId('K1'), (int)$this->db()->query("SELECT plan_area_id FROM assignments WHERE user_id=$ma AND plan_date='$d'")->fetchColumn());
        $this->assertSame(['draft_set', 'publish'], $this->logActions($ma, $d));
        /* Veröffentlichung ändert die Revision → alter Stand ist veraltet */
        $r = $this->save($mgr, $ma, $d, 'clear', '', '0');
        $this->assertSame(409, $r->status);
    }

    public function testRevisionDoesNotRevealConfidentialAbsenceType(): void
    {
        $ma = $this->uid('ma1'); $d = $this->workday();
        $this->db()->prepare("INSERT INTO absences(user_id,absence_date,absence_type,is_published) VALUES(?,?,'krank_attest',1)")->execute([$ma, $d]);
        $id = (int)$this->db()->lastInsertId();
        $rev = $this->rev($this->as('vw'), $ma, $d);
        /* Ohne Schlüssel lässt sich die Revision nicht nachrechnen */
        foreach (['krank_attest', 'krank_ganz', 'urlaub_genehmigt'] as $t) {
            $plain = json_encode(['b' => [$id, $t, '', '', 0]]);
            $this->assertNotSame(substr(hash('sha256', $plain), 0, 16), $rev);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $rev);
    }
}
