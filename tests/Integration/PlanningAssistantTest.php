<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;

/**
 * Planungsassistent (public/pages/planungsassistent.php → app/lib/PlanningAssistant.php):
 * Vorschlag, Probelauf, Übernahme, Rückgängig, Sperren, fremde Entwürfe,
 * Revisionen, Rechte/Scope, gespeicherte Planungen (auch ältere Stände),
 * Vorlagen und die CSP-Tauglichkeit der Seite.
 */
final class PlanningAssistantTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPlan();
        $db = $this->db();
        $db->exec('DELETE FROM planning_runs');
        $db->exec('DELETE FROM planning_profiles');
        $db->exec("DELETE FROM app_settings WHERE setting_key = 'epa_cross_team'");
    }

    /** Montag–Freitag der übernächsten Woche */
    private function week(): array
    {
        $d = [];
        for ($i = 1; $i <= 5; $i++) $d[] = $this->workday(1, $i);
        return $d;
    }

    private function cfg(array $over = []): array
    {
        $w = $this->week();
        return array_replace_recursive([
            'title' => 'Testeinsatz', 'area_id' => $this->areaId('K1'), 'start' => $w[0], 'end' => $w[4],
            'weekdays' => [1, 2, 3, 4, 5], 'skip_holidays' => true, 'demand_default' => 2, 'demand_min' => 1,
            'free_areas' => [], 'azubi' => ['enabled' => true, 'years' => [1, 2, 3], 'max_per_day' => 1, 'supervision_ratio' => 2],
            'rules' => ['max_consecutive' => 10, 'max_days_total' => 20, 'weekend_flag' => true, 'overwrite' => 'none'],
        ], $over);
    }

    private function act(Client $c, string $action, array $fields = []): array
    {
        foreach ($fields as $k => $v) if (is_array($v)) $fields[$k] = json_encode($v);
        return $c->postAjax('/planungsassistent', ['action' => $action] + $fields)->json();
    }

    private function plan(array $byDate): array { return $byDate; }

    private function runIdOf(int $uid, string $date): int
    {
        $d = $this->draft($uid, $date);
        return $d ? (int)$d['planning_run_id'] : 0;
    }

    /* ------------------------------------------------------------------ */

    public function testGenerateStaysWithinTeamScope(): void
    {
        $tl = $this->as('tl');
        $r = $this->act($tl, 'generate', ['config' => $this->cfg()]);
        $this->assertTrue($r['success']);
        $ids = array_map(fn($p) => (int)$p['id'], $r['people']);
        sort($ids);
        $expect = [$this->uid('tl'), $this->uid('ma1'), $this->uid('azubi')];
        sort($expect);
        $this->assertSame($expect, $ids, 'Teamleiter sieht nur sein Team');
        $this->assertSame(10, $r['stats']['slots_total']);
        foreach ($r['plan'] as $date => $uids) foreach ($uids as $u) $this->assertContains((int)$u, $expect);
    }

    public function testCrossTeamOnlyForManagerOrWithSetting(): void
    {
        $cfg = $this->cfg(['cross_team' => true]);
        $tl = $this->as('tl');
        $ids = array_map(fn($p) => (int)$p['id'], $this->act($tl, 'generate', ['config' => $cfg])['people']);
        $this->assertNotContains($this->uid('ma2'), $ids, 'ohne Einstellung bleibt der Teamleiter im eigenen Team');

        $mgr = $this->as('mgr');
        $ids = array_map(fn($p) => (int)$p['id'], $this->act($mgr, 'generate', ['config' => $cfg])['people']);
        $this->assertContains($this->uid('ma2'), $ids, 'Manager planen teamübergreifend (E-1/P-6)');

        $this->db()->exec("INSERT INTO app_settings(setting_key, setting_value) VALUES('epa_cross_team','1')");
        $tl = $this->as('tl');
        $ids = array_map(fn($p) => (int)$p['id'], $this->act($tl, 'generate', ['config' => $cfg])['people']);
        $this->assertContains($this->uid('ma2'), $ids, 'mit Einstellung epa_cross_team darf der Teamleiter teamübergreifend');
    }

    public function testNoPermissionGives403Json(): void
    {
        $r = $this->as('ma1')->postAjax('/planungsassistent', ['action' => 'generate', 'config' => '{}']);
        $this->assertSame(403, $r->status);
        $this->assertFalse($r->json()['success']);
    }

    public function testPreviewWritesNothingAndApplyMatchesIt(): void
    {
        $tl = $this->as('tl');
        $w = $this->week();
        $ma1 = $this->uid('ma1'); $tlId = $this->uid('tl');
        $plan = [$w[0] => [$ma1, $tlId], $w[1] => [$ma1]];
        $pre = $this->act($tl, 'apply_preview', ['config' => $this->cfg(), 'plan' => $plan]);
        $this->assertTrue($pre['success']);
        $this->assertSame(3, $pre['written']);
        $this->assertSame(2, $pre['people']);
        $this->assertSame(0, $pre['skipped_total']);
        $this->assertCount(3, $pre['revs']);
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM plan_drafts')->fetchColumn(), 'Probelauf schreibt nichts');
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM planning_runs')->fetchColumn());

        $res = $this->act($tl, 'apply', ['config' => $this->cfg(['internal' => true]), 'plan' => $plan, 'revs' => $pre['revs']]);
        $this->assertTrue($res['success']);
        $this->assertSame(3, $res['written']);
        $run = (int)$res['run_id'];
        $d = $this->draft($ma1, $w[0]);
        $this->assertSame('assignment', $d['change_type']);
        $this->assertSame((string)$this->areaId('K1'), (string)$d['change_value']);
        $this->assertSame($run, (int)$d['planning_run_id']);
        $this->assertSame(1, (int)$d['is_internal'], 'Teamleiter hat plan_view_internal');
        $this->assertSame('Testeinsatz', $d['remarks']);
        $this->assertSame(3, (int)$this->db()->query("SELECT COUNT(*) FROM plan_change_log WHERE action='assistant_apply'")->fetchColumn());
        $this->assertSame(1, (int)$this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action='planning_assistant_apply'")->fetchColumn());
        $row = $this->db()->query("SELECT status, slots_filled, is_saved FROM planning_runs WHERE id=$run")->fetch();
        $this->assertSame(['uebernommen', 3, 0], [$row['status'], (int)$row['slots_filled'], (int)$row['is_saved']]);
    }

    public function testApplyNeverOverwritesLockedSchoolOrProtectedAbsence(): void
    {
        $db = $this->db();
        $w = $this->week();
        $ma1 = $this->uid('ma1'); $az = $this->uid('azubi');
        $ins = $db->prepare('INSERT INTO absences(user_id, absence_date, absence_type, is_published, created_by) VALUES(?,?,?,1,?)');
        $ins->execute([$ma1, $w[0], 'gesperrt', $ma1]);
        $ins->execute([$ma1, $w[1], 'urlaub_genehmigt', $ma1]);
        $db->prepare('INSERT INTO assignments(user_id, plan_date, plan_area_id, year, month, is_published, created_by) VALUES(?,?,?,?,?,1,?)')
           ->execute([$az, $w[2], $this->areaId('BS'), (int)substr($w[2], 0, 4), (int)substr($w[2], 5, 2), $az]);
        $db->prepare('INSERT INTO assignments(user_id, plan_date, plan_area_id, year, month, is_published, created_by) VALUES(?,?,?,?,?,1,?)')
           ->execute([$ma1, $w[3], $this->areaId('K2'), (int)substr($w[3], 0, 4), (int)substr($w[3], 5, 2), $ma1]);

        $plan = [$w[0] => [$ma1], $w[1] => [$ma1], $w[2] => [$az], $w[3] => [$ma1]];
        /* Selbst „alles überschreiben“ und Einzelfreigaben ändern nichts an Sperrtag und Berufsschule;
           der Teamleiter hat kein unlock_vacation, also bleibt auch der Urlaub stehen. */
        $cfg = $this->cfg(['rules' => ['overwrite' => 'all'], 'force_cells' => ["$ma1|{$w[0]}", "$ma1|{$w[1]}", "$az|{$w[2]}"]]);
        $res = $this->act($this->as('tl'), 'apply', ['config' => $cfg, 'plan' => $plan]);
        $this->assertSame(1, $res['written'], 'nur der fremde Einsatz (Regel: überschreiben) wird ersetzt');
        $this->assertSame(3, $res['skipped_total']);
        $this->assertSame(['gesperrt' => 1, 'abwesend' => 1, 'schule' => 1], array_intersect_key($res['by_reason'], ['gesperrt' => 0, 'abwesend' => 0, 'schule' => 0]));
        $this->assertNull($this->draft($ma1, $w[0]));
        $this->assertNull($this->draft($az, $w[2]));
        $this->assertNotNull($this->draft($ma1, $w[3]));

        /* ohne Überschreib-Regel bleibt der fremde Einsatz unangetastet */
        $db->exec('DELETE FROM plan_drafts');
        $res = $this->act($this->as('tl'), 'apply', ['config' => $this->cfg(), 'plan' => [$w[3] => [$ma1]]]);
        $this->assertSame(0, $res['written']);
        $this->assertSame(1, $res['by_reason']['belegt']);
    }

    public function testManagerMayOverwriteAbsenceOnlyWhenReleased(): void
    {
        $db = $this->db();
        $w = $this->week();
        $ma1 = $this->uid('ma1');
        $db->prepare('INSERT INTO absences(user_id, absence_date, absence_type, is_published, created_by) VALUES(?,?,?,1,?)')
           ->execute([$ma1, $w[0], 'urlaub_ganz', $ma1]);
        $mgr = $this->as('mgr');
        $res = $this->act($mgr, 'apply', ['config' => $this->cfg(), 'plan' => [$w[0] => [$ma1]]]);
        $this->assertSame(0, $res['written'], 'ohne Freigabe bleibt die Abwesenheit');
        $res = $this->act($mgr, 'apply', ['config' => $this->cfg(['force_cells' => ["$ma1|{$w[0]}"]]), 'plan' => [$w[0] => [$ma1]]]);
        $this->assertSame(1, $res['written'], 'Einzelfreigabe + unlock_vacation');
        $this->assertSame(1, $res['kinds']['abwesenheit']);
    }

    public function testForeignOpenDraftsAreKeptUnlessReleased(): void
    {
        $db = $this->db();
        $w = $this->week();
        $ma1 = $this->uid('ma1'); $tlId = $this->uid('tl');
        $ins = $db->prepare("INSERT INTO plan_drafts(user_id, plan_date, year, month, action, change_type, change_value, created_by, created_at) VALUES(?,?,?,?,'set','assignment',?,?,NOW())");
        $ins->execute([$ma1, $w[0], (int)substr($w[0], 0, 4), (int)substr($w[0], 5, 2), (string)$this->areaId('K2'), $this->uid('mgr')]);
        $ins->execute([$tlId, $w[1], (int)substr($w[1], 0, 4), (int)substr($w[1], 5, 2), (string)$this->areaId('K1'), $this->uid('mgr')]);

        $tl = $this->as('tl');
        $gen = $this->act($tl, 'generate', ['config' => $this->cfg()]);
        $state = [];
        foreach ($gen['people'] as $p) $state[(int)$p['id']] = $p['days'];
        $this->assertSame(1, $state[$ma1][$w[0]]['o'] ?? 0, 'fremder Entwurf ist für den Browser gekennzeichnet');

        $res = $this->act($tl, 'apply', ['config' => $this->cfg(['rules' => ['overwrite' => 'assignments']]), 'plan' => [$w[0] => [$ma1], $w[1] => [$tlId]]]);
        $this->assertSame(0, $res['written']);
        $this->assertSame(1, $res['by_reason']['fremder_entwurf'] ?? 0);
        $this->assertSame(1, $res['unchanged'], 'gleichlautender fremder Entwurf zählt nicht als Konflikt');
        $this->assertSame((string)$this->areaId('K2'), (string)$this->draft($ma1, $w[0])['change_value']);

        $res = $this->act($tl, 'apply', ['config' => $this->cfg(['rules' => ['overwrite' => 'assignments'], 'force_cells' => ["$ma1|{$w[0]}"]]), 'plan' => [$w[0] => [$ma1]]]);
        $this->assertSame(1, $res['written'], 'nach ausdrücklicher Freigabe wird ersetzt');
        $this->assertSame((string)$this->areaId('K1'), (string)$this->draft($ma1, $w[0])['change_value']);
    }

    public function testCellChangedSincePreviewIsSkipped(): void
    {
        $w = $this->week();
        $ma1 = $this->uid('ma1');
        $tl = $this->as('tl');
        $cfg = $this->cfg(['rules' => ['overwrite' => 'assignments']]);
        $plan = [$w[0] => [$ma1], $w[1] => [$ma1]];
        $pre = $this->act($tl, 'apply_preview', ['config' => $cfg, 'plan' => $plan]);
        /* Zwischen Prüfung und Übernahme veröffentlicht jemand einen Einsatz */
        $this->db()->prepare('INSERT INTO assignments(user_id, plan_date, plan_area_id, year, month, is_published, created_by) VALUES(?,?,?,?,?,1,?)')
           ->execute([$ma1, $w[1], $this->areaId('K2'), (int)substr($w[1], 0, 4), (int)substr($w[1], 5, 2), $this->uid('mgr')]);
        $res = $this->act($tl, 'apply', ['config' => $cfg, 'plan' => $plan, 'revs' => $pre['revs']]);
        $this->assertSame(1, $res['written']);
        $this->assertSame(1, $res['by_reason']['geaendert'] ?? 0);
        $this->assertNull($this->draft($ma1, $w[1]));
    }

    public function testOutOfScopePeopleAreSkipped(): void
    {
        $w = $this->week();
        $res = $this->act($this->as('tl'), 'apply', ['config' => $this->cfg(), 'plan' => [$w[0] => [$this->uid('ma2')], '1999-01-01' => [$this->uid('ma1')]]]);
        $this->assertSame(0, $res['written']);
        $this->assertSame(1, $res['by_reason']['scope']);
        $this->assertNull($this->draft($this->uid('ma2'), $w[0]));
    }

    public function testUndoRemovesOnlyRemainingRunDraftsAndRespectsOwner(): void
    {
        $w = $this->week();
        $ma1 = $this->uid('ma1'); $tlId = $this->uid('tl');
        $tl = $this->as('tl');
        $res = $this->act($tl, 'apply', ['config' => $this->cfg(), 'plan' => [$w[0] => [$ma1, $tlId], $w[1] => [$ma1]]]);
        $run = (int)$res['run_id'];

        /* Eine Zelle danach von Hand ändern – sie darf beim Rückgängig nicht verschwinden (D-05) */
        $tl->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma1, 'date' => $w[1], 'type' => 'assignment', 'value' => (string)$this->areaId('K2')]]]);
        $this->assertSame(0, $this->runIdOf($ma1, $w[1]));

        $list = $this->act($tl, 'run_list');
        $this->assertSame($run, (int)$list['items'][0]['id']);
        $this->assertSame(2, (int)$list['items'][0]['remaining']);

        $other = $this->act($this->as('tl2'), 'undo', ['run_id' => $run]);
        $this->assertFalse($other['success'], 'fremde Übernahme nicht zurücknehmbar');

        $u = $this->act($tl, 'undo', ['run_id' => $run]);
        $this->assertTrue($u['success']);
        $this->assertSame(2, $u['removed']);
        $this->assertNull($this->draft($ma1, $w[0]));
        $this->assertNotNull($this->draft($ma1, $w[1]), 'manuelle Änderung bleibt');
        $this->assertSame('verworfen', $this->db()->query("SELECT status FROM planning_runs WHERE id=$run")->fetchColumn());
        $this->assertSame(2, (int)$this->db()->query("SELECT COUNT(*) FROM plan_change_log WHERE action='assistant_undo'")->fetchColumn());
        $this->assertSame([], $this->act($tl, 'run_list')['items'], 'zurückgenommene Läufe verschwinden aus der Liste');
    }

    public function testAdminWithManageSettingsMayUndoForeignRun(): void
    {
        $w = $this->week();
        $res = $this->act($this->as('tl'), 'apply', ['config' => $this->cfg(), 'plan' => [$w[0] => [$this->uid('ma1')]]]);
        $u = $this->act($this->as('admin'), 'undo', ['run_id' => (int)$res['run_id']]);
        $this->assertTrue($u['success']);
    }

    public function testSavedPlanRoundTripApplyMarksAndUndoResets(): void
    {
        $w = $this->week();
        $ma1 = $this->uid('ma1');
        $tl = $this->as('tl');
        $plan = [$w[0] => [$ma1], $w[2] => [$ma1]];
        $s = $this->act($tl, 'plan_save', ['name' => 'Kunde Nord', 'shared' => 0, 'config' => $this->cfg(), 'plan' => $plan,
                                           'stats' => ['total' => 10, 'filled' => 2, 'blocks' => 0, 'warns' => 1]]);
        $id = (int)$s['id'];
        $l = $this->act($tl, 'plan_load', ['id' => $id]);
        $this->assertTrue($l['success']);
        $this->assertSame([$ma1], array_map('intval', $l['plan'][$w[0]]), 'gespeicherte Besetzung hat Vorrang');
        $this->assertSame('Kunde Nord', $l['name']);
        $this->assertFalse($this->act($this->as('tl2'), 'plan_load', ['id' => $id])['success'], 'nicht freigegeben');

        $res = $this->act($tl, 'apply', ['config' => $this->cfg(), 'plan' => $plan, 'plan_id' => $id]);
        $this->assertNotNull($this->db()->query("SELECT applied_at FROM planning_runs WHERE id=$id")->fetchColumn());
        $this->assertSame($id, (int)$this->db()->query('SELECT source_run_id FROM planning_runs WHERE id=' . (int)$res['run_id'])->fetchColumn());
        $items = $this->act($tl, 'plan_list')['items'];
        $this->assertNotNull($items[0]['applied_at'], 'Übersicht zeigt „übernommen“');

        $this->act($tl, 'undo', ['run_id' => (int)$res['run_id']]);
        $this->assertNull($this->db()->query("SELECT applied_at FROM planning_runs WHERE id=$id")->fetchColumn());

        $this->assertFalse($this->act($this->as('tl2'), 'plan_delete', ['id' => $id])['success']);
        $this->assertTrue($this->act($tl, 'plan_delete', ['id' => $id])['success']);
        $this->assertSame([], $this->act($tl, 'plan_list')['items']);
    }

    public function testLegacySavedPlanStillLoads(): void
    {
        /* Stand v6.9: Konfiguration ohne neuere Schlüssel (free_areas, min_per_day, …) */
        $w = $this->week();
        $ma1 = $this->uid('ma1');
        $legacyCfg = ['title' => 'Alt', 'area_id' => $this->areaId('K1'), 'start' => $w[0], 'end' => $w[4],
                      'weekdays' => [1, 2, 3, 4, 5], 'demand_default' => 1,
                      'azubi' => ['enabled' => false], 'rules' => ['overwrite' => 'none']];
        $db = $this->db();
        $db->prepare("INSERT INTO planning_runs(title, plan_area_id, start_date, end_date, config_json, result_json, status, is_saved, shared, created_by, created_at)
                      VALUES('Alt', ?, ?, ?, ?, ?, 'entwurf', 1, 1, ?, NOW())")
           ->execute([$this->areaId('K1'), $w[0], $w[4], json_encode($legacyCfg),
                      json_encode(['plan' => [$w[0] => [$ma1]], 'stats' => ['total' => 5, 'filled' => 1]]), $this->uid('tl')]);
        $id = (int)$db->lastInsertId();
        $l = $this->act($this->as('tl'), 'plan_load', ['id' => $id]);
        $this->assertTrue($l['success']);
        $this->assertSame(0, $l['config']['azubi']['min_per_day']);
        $this->assertSame([$ma1], array_map('intval', $l['plan'][$w[0]]));
        $this->assertCount(5, $l['days']);
        /* freigegeben: auch für einen anderen Planer lesbar */
        $this->assertTrue($this->act($this->as('mgr'), 'plan_load', ['id' => $id])['success']);
    }

    public function testProfilesCrudAndPermissions(): void
    {
        $tl = $this->as('tl');
        $p = $this->act($tl, 'profile_save', ['name' => 'Standard K1', 'description' => 'zwei Personen', 'shared' => 1, 'config' => $this->cfg()]);
        $id = (int)$p['id'];
        $this->assertFalse($this->act($tl, 'profile_save', ['name' => '', 'config' => $this->cfg()])['success']);
        $l = $this->act($this->as('tl2'), 'profile_load', ['id' => $id]);
        $this->assertTrue($l['success'], 'freigegebene Vorlage');
        $this->assertSame(2, (int)$l['config']['demand_default']);
        $this->assertSame(1, (int)$this->db()->query("SELECT use_count FROM planning_profiles WHERE id=$id")->fetchColumn());

        $upd = $this->act($this->as('tl2'), 'profile_save', ['id' => $id, 'name' => 'gekapert', 'config' => $this->cfg()]);
        $this->assertFalse($upd['success'], 'fremde Vorlage nicht änderbar');
        $upd = $this->act($tl, 'profile_save', ['id' => $id, 'name' => 'Standard K1 neu', 'shared' => 0, 'config' => $this->cfg(['demand_default' => 3])]);
        $this->assertTrue($upd['success']);
        $this->assertSame($id, (int)$upd['id']);
        $this->assertFalse($this->act($this->as('tl2'), 'profile_load', ['id' => $id])['success'], 'nicht mehr freigegeben');

        $this->assertFalse($this->act($this->as('tl2'), 'profile_delete', ['id' => $id])['success']);
        $this->assertTrue($this->act($tl, 'profile_delete', ['id' => $id])['success']);
    }

    public function testSuggestAndCandidates(): void
    {
        $w = $this->week();
        $tl = $this->as('tl');
        $s = $this->act($tl, 'suggest', ['config' => $this->cfg(), 'date' => $w[0], 'taken' => [$this->uid('tl')]]);
        $this->assertTrue($s['success']);
        $ids = array_map(fn($i) => (int)$i['id'], $s['items']);
        $this->assertNotContains($this->uid('tl'), $ids);
        $this->assertContains($this->uid('ma1'), $ids);
        $this->assertFalse($this->act($tl, 'suggest', ['config' => $this->cfg(), 'date' => '1999-01-01'])['success']);
        $c = $this->act($tl, 'candidates', ['config' => $this->cfg()]);
        $this->assertCount(3, $c['items']);
    }

    public function testPageIsCspCleanAndUnknownActionIsJson(): void
    {
        $r = $this->as('tl')->get('/planungsassistent');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $r->headers, 'strikte CSP ohne unsafe-inline');
        $start = strpos($r->body, '<div class="pqa" id="pqa">');
        $end = strpos($r->body, '<!-- /pqa -->');
        $this->assertNotFalse($start); $this->assertNotFalse($end);
        $page = substr($r->body, $start, $end - $start);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $page, 'keine Inline-Handler');
        $this->assertDoesNotMatchRegularExpression('/style\s*=/i', $page, 'keine Inline-Stile im Markup');
        $this->assertMatchesRegularExpression('#<script type="application/json" id="pqaBoot" nonce="#', $r->body);
        $this->assertStringContainsString('assets/js/assistant.js', $r->body);
        $u = $this->act($this->as('tl'), 'snapshot_probe');
        $this->assertFalse($u['success']);
        $this->assertSame('Unbekannte Aktion.', $u['error']);
    }
}
