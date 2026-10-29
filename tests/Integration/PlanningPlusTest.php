<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\IntegrationTestCase;

/**
 * Planungshilfen (api/planning_plus, Abdeckungszeile, Dashboard „Heute zu tun“).
 * Rechte und Vertraulichkeit exakt wie in der Monatsplanung: Team-Scope,
 * Abwesenheitsarten nur mit Detailrecht, interne Einträge nur mit plan_view_internal.
 * Rückgängig/Wochenkopie schreiben ausschließlich über save_bulk mit Revisionen.
 */
final class PlanningPlusTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPlan();
        $this->db()->exec('DELETE FROM plan_change_log');
        $this->db()->exec('DELETE FROM plan_area_targets');
        $this->db()->exec("DELETE FROM app_settings WHERE setting_key='tenant_config'");
    }

    private function info($client, int $uid, string $date)
    {
        return $client->postJson('/api/planning_plus', ['action' => 'cell_info', 'user_id' => $uid, 'date' => $date]);
    }

    private function revs($c, string $date): array
    {
        return $c->postJson('/api/live_status', ['action' => 'cells', 'year' => (int)substr($date, 0, 4), 'month' => (int)substr($date, 5, 2)])->json()['revs'] ?? [];
    }

    public function testCellInfoShowsDraftAndHistoryWithinScope(): void
    {
        $tl = $this->as('tl'); $ma1 = $this->uid('ma1'); $d = $this->workday();
        $k1 = (string)$this->areaId('K1'); $k2 = (string)$this->areaId('K2');
        $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d, 'type' => 'assignment', 'value' => $k1, 'rev' => '0']]]);
        $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d, 'type' => 'assignment', 'value' => $k2, 'rev' => $this->revs($tl, $d)[$ma1 . '_' . $d]]]]);
        $r = $this->info($tl, $ma1, $d);
        $this->assertSame(200, $r->status, $r->body);
        $j = $r->json();
        $this->assertSame('K2', $j['draft']['what']);
        $this->assertNotEmpty($j['draft']['by']);
        $this->assertCount(2, $j['history']);
        $this->assertSame('K1', $j['history'][0]['from']);
        $this->assertSame('K2', $j['history'][0]['to']);
    }

    public function testCellInfoRespectsTeamScopeAndPlanEdit(): void
    {
        $d = $this->workday();
        $this->assertSame(403, $this->info($this->as('tl'), $this->uid('ma2'), $d)->status, 'fremdes Team');
        $this->assertSame(403, $this->info($this->as('lager'), $this->uid('ma1'), $d)->status, 'ohne plan_edit');
        $this->assertSame(403, $this->info($this->as('ma1'), $this->uid('ma1'), $d)->status, 'Mitarbeiter');
        $this->assertSame(400, $this->info($this->as('tl'), $this->uid('ma1'), 'gestern')->status);
    }

    public function testCellInfoMasksConfidentialAbsences(): void
    {
        $mgr = $this->as('mgr'); $ma1 = $this->uid('ma1'); $d = $this->workday(0, 3);
        $r = $mgr->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d, 'type' => 'absence', 'value' => 'krank_attest', 'rev' => '0']]]);
        $this->assertSame(200, $r->status, $r->body);
        /* Verwaltung plant alle, sieht aber keine Gesundheitsdaten */
        $vw = $this->info($this->as('vw'), $ma1, $d);
        $this->assertSame(200, $vw->status, $vw->body);
        $this->assertStringNotContainsStringIgnoringCase('krank', $vw->body);
        $this->assertSame('Abwesend', $vw->json()['draft']['what']);
        $this->assertSame('Abwesend', $vw->json()['history'][0]['to']);
        /* Vorgesetzte Teamleitung sieht die Art */
        $tl = $this->info($this->as('tl'), $ma1, $d);
        $this->assertStringContainsStringIgnoringCase('krank', $tl->json()['draft']['what']);
    }

    public function testCellInfoHidesInternalEntriesWithoutPermission(): void
    {
        $mgr = $this->as('mgr'); $ma1 = $this->uid('ma1'); $d = $this->workday(0, 4);
        $r = $mgr->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d, 'type' => 'assignment', 'value' => (string)$this->areaId('K1'), 'rev' => '0']], 'is_internal' => 1]);
        $this->assertSame(200, $r->status, $r->body);
        $vw = $this->info($this->as('vw'), $ma1, $d)->json();      /* ohne plan_view_internal */
        $this->assertNull($vw['draft']);
        $this->assertSame([], $vw['history']);
        $m = $this->info($mgr, $ma1, $d)->json();
        $this->assertSame('K1', $m['draft']['what']);
        $this->assertSame(1, $m['draft']['internal']);
    }

    public function testTargetsSaveRequiresManageConfigAndValidates(): void
    {
        $k1 = $this->areaId('K1');
        $tl = $this->as('tl');
        $this->assertSame(403, $tl->postJson('/api/planning_plus', ['action' => 'targets_save', 'targets' => [$k1 => [1 => 2]]])->status, 'Teamleitung ohne manage_config');
        $this->assertSame(200, $tl->postJson('/api/planning_plus', ['action' => 'targets'])->status, 'Lesen für Planer');
        $vw = $this->as('vw');
        $this->assertSame(400, $vw->postJson('/api/planning_plus', ['action' => 'targets_save', 'targets' => [$k1 => [8 => 2]]])->status, 'Wochentag 8');
        $this->assertSame(400, $vw->postJson('/api/planning_plus', ['action' => 'targets_save', 'targets' => [999999 => [1 => 2]]])->status, 'unbekannter Bereich');
        $this->assertSame(400, $vw->postJson('/api/planning_plus', ['action' => 'targets_save', 'targets' => [$k1 => [1 => -1]]])->status, 'negativ');
        $this->assertSame(400, $vw->postJson('/api/planning_plus', ['action' => 'targets_save', 'targets' => [$k1 => [1 => '2.5']]])->status, 'keine ganze Zahl');
        $r = $vw->postJson('/api/planning_plus', ['action' => 'targets_save', 'targets' => [$k1 => [1 => 3, 2 => 0, 6 => '']]]);
        $this->assertSame(200, $r->status, $r->body);
        $t = $tl->postJson('/api/planning_plus', ['action' => 'targets'])->json()['targets'];
        $this->assertSame([(string)$k1 => ['1' => 3]], json_decode(json_encode($t), true));
        $this->assertSame(1, (int)$this->db()->query('SELECT COUNT(*) FROM plan_area_targets')->fetchColumn(), '0/leer wird nicht gespeichert');
        $this->assertSame(403, $this->as('ma1')->postJson('/api/planning_plus', ['action' => 'targets'])->status);
    }

    public function testMonthPlanCarriesConfigAndTenantRulesSwitchFeaturesOff(): void
    {
        $k1 = $this->areaId('K1');
        $this->db()->prepare('INSERT INTO plan_area_targets(plan_area_id, weekday, min_staff) VALUES(?,?,?)')->execute([$k1, 1, 2]);
        $html = $this->as('tl')->get('/monatsplanung')->body;
        $cfg = $this->cfg($html);
        $this->assertTrue($cfg['coverage'] && $cfg['history'] && $cfg['copy'] && $cfg['undo']);
        $this->assertFalse($cfg['targetsEdit'], 'Teamleitung pflegt keine Soll-Besetzung');
        $this->assertSame(2, $cfg['targets'][$k1][1]);
        $this->assertStringContainsString('planning-plus.js', $html);
        $this->assertTrue($this->cfg($this->as('mgr')->get('/monatsplanung')->body)['targetsEdit']);
        /* Gesamtplan: nur lesende Hilfen */
        $g = $this->cfg($this->as('ma1')->get('/gesamtplan')->body);
        $this->assertFalse($g['undo'] || $g['copy'] || $g['history'] || $g['edit']);

        $this->db()->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config','{\"planning\":{\"coverage_row\":false,\"cell_history\":false,\"period_copy\":false,\"undo\":false}}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $off = $this->cfg($this->as('tl')->get('/monatsplanung')->body);
        $this->assertFalse($off['coverage'] || $off['history'] || $off['copy'] || $off['undo']);
        /* Ohne Verlauf-Regel liefert der Endpunkt trotzdem nur, was die Rechte erlauben – UI blendet aus */
    }

    public function testOrganisationSavesPlanningRules(): void
    {
        $adm = $this->as('admin');
        $r = $adm->postAjax('/organisation', ['action' => 'save_planning', 'concurrent_editing' => '1', 'coverage_row' => '1', 'undo' => '1']);
        $this->assertSame(200, $r->status, $r->body);
        $j = json_decode((string)$this->db()->query("SELECT setting_value FROM app_settings WHERE setting_key='tenant_config'")->fetchColumn(), true);
        $this->assertTrue($j['planning']['coverage_row']);
        $this->assertTrue($j['planning']['undo']);
        $this->assertFalse($j['planning']['cell_history']);
        $this->assertFalse($j['planning']['period_copy']);
    }

    public function testDashboardTasksOnlyForPlanners(): void
    {
        $this->db()->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config','{\"modules\":{\"approvals\":true}}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $tl = $this->as('tl'); $ma1 = $this->uid('ma1'); $d = $this->workday();
        $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d, 'type' => 'assignment', 'value' => (string)$this->areaId('K1'), 'rev' => '0']]]);
        $html = $tl->get('/dashboard')->body;
        $this->assertStringContainsString('Heute zu tun', $html);
        $this->assertStringContainsString('unveröffentlicht', $html);
        $this->assertStringContainsString('data-pq-publish="' . (int)substr($d, 0, 4) . '-' . (int)substr($d, 5, 2) . '"', $html);
        $this->assertStringContainsString('pq-dash-planner', $html);
        $staff = $this->as('ma1')->get('/dashboard')->body;
        $this->assertStringNotContainsString('Heute zu tun', $staff);
        $this->assertStringContainsString('pq-dash-staff', $staff);
        /* Widget abschaltbar */
        $this->db()->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config','{\"dashboard\":{\"widgets\":[\"kpis\",\"week\"]}}') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $this->assertStringNotContainsString('Heute zu tun', $tl->get('/dashboard')->body);
    }

    public function testDashboardUnderstaffingUsesTargetsAndScope(): void
    {
        $k1 = $this->areaId('K1');
        $ins = $this->db()->prepare('INSERT INTO plan_area_targets(plan_area_id, weekday, min_staff) VALUES(?,?,?)');
        for ($w = 1; $w <= 5; $w++) $ins->execute([$k1, $w, 1]);
        $html = $this->as('tl')->get('/dashboard')->body;
        $this->assertStringContainsString('Unterbesetzt', $html);
        $this->assertStringContainsString('K1', $html);
    }

    /** Rückgängig im Browser = erneuter save_bulk mit der Revision nach dem eigenen Speichern */
    public function testUndoRoundTripUsesRevisionsAndDetectsForeignChanges(): void
    {
        $tl = $this->as('tl'); $mgr = $this->as('mgr'); $ma1 = $this->uid('ma1'); $d = $this->workday(1, 2);
        $k1 = (string)$this->areaId('K1'); $k2 = (string)$this->areaId('K2');
        $r = $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d, 'type' => 'assignment', 'value' => $k1, 'rev' => '0']]]);
        $rev = $r->json()['revs'][$ma1 . '_' . $d];
        /* Rückgängig: vorheriger Zustand (leer) mit eigener Revision */
        $u = $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d, 'type' => 'clear', 'value' => '', 'rev' => $rev]]]);
        $this->assertSame(200, $u->status, $u->body);
        $this->assertSame('clear', $this->draft($ma1, $d)['action']);
        $rev2 = $u->json()['revs'][$ma1 . '_' . $d];
        /* Jemand anderes ändert die Zelle – Wiederholen mit alter Revision → Konflikt, nichts geschrieben */
        $mgr->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d, 'type' => 'assignment', 'value' => $k2, 'rev' => $rev2]]]);
        $x = $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $d, 'type' => 'assignment', 'value' => $k1, 'rev' => $rev2]]]);
        $this->assertSame(409, $x->status, $x->body);
        $this->assertSame($k2, $this->draft($ma1, $d)['change_value']);
    }

    public function testMigrationIsIdempotent(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../migrations/app/0009_staffing_targets.sql');
        $this->db()->exec($sql);
        $this->db()->exec($sql);
        $this->assertSame(1, (int)$this->db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='plan_area_targets'")->fetchColumn());
    }

    private function cfg(string $html): array
    {
        $this->assertSame(1, preg_match('/id="pqPlusCfg" hidden data-cfg="([^"]*)"/', $html, $m), 'Konfiguration fehlt');
        return json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
    }
}
