<?php
namespace Planquadro\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/lib/PlanningAssistant.php';

/**
 * Algorithmus des Planungsassistenten ohne Datenbank (epa_cfg, epa_days,
 * epa_availability, epa_generate, epa_apply_decide).
 */
final class PlanningAssistantTest extends TestCase
{
    private function person(int $id, array $o = []): array
    {
        return array_replace([
            'id' => $id, 'name' => "P$id", 'short' => 'P', 'role' => 'mitarbeiter', 'role_label' => 'Techniker', 'role_color' => '#000',
            'azubi_year' => 0, 'os' => '', 'os_rank' => 0, 'prof' => '', 'area_id' => 0, 'area_code' => '', 'weekend' => false,
            'entry' => null, 'exit' => null, 'bond' => 0.0, 'bond_area' => 0, 'bond_code' => '', 'variety' => 0,
            'exp' => 0, 'exp_last' => null, 'home' => false,
        ], $o);
    }

    private function ctx(array $cfg, array $people, array $state = [], array $holidays = []): array
    {
        $p = [];
        foreach ($people as $x) $p[$x['id']] = $x;
        return ['people' => $p, 'areas' => [1 => ['short_code' => 'K1'], 7 => ['short_code' => 'T']], 'days' => epa_days($cfg, $holidays),
                'state' => $state, 'holidays' => $holidays, 'school' => [], 'roles' => []];
    }

    private function cfg(array $o = []): array
    {
        return epa_cfg(array_replace_recursive(['area_id' => 1, 'start' => '2026-11-02', 'end' => '2026-11-06', 'demand_default' => 2, 'skip_holidays' => true,
            'free_areas' => [], 'azubi' => ['enabled' => true, 'years' => [1, 2, 3]]], $o));
    }

    public function testCfgNormalisesAndCaps(): void
    {
        $c = epa_cfg(['start' => '2026-01-01', 'end' => '2027-06-01', 'weekdays' => [0, 9], 'demand_default' => 500,
                      'rules' => ['overwrite' => 'evil'], 'force_cells' => ['1|2026-01-02', 'x'], 'special_days' => [['date' => 'bad']]]);
        $this->assertSame('2026-05-01', $c['end'], 'höchstens 120 Tage');
        $this->assertSame([1, 2, 3, 4, 5], $c['weekdays']);
        $this->assertSame(99, $c['demand_default']);
        $this->assertSame('none', $c['rules']['overwrite']);
        $this->assertSame(['1|2026-01-02'], $c['force_cells']);
        $this->assertSame([], $c['special_days']);
        $this->assertSame([7], epa_cfg([], ['free_areas' => [7]])['free_areas'], 'Vorgabe aus den Einstellungen');
        $this->assertSame([], epa_cfg(['free_areas' => []], ['free_areas' => [7]])['free_areas'], 'leere Auswahl bleibt leer');
    }

    public function testDaysHonourWeekdaysHolidaysAndSpecialDays(): void
    {
        $cfg = $this->cfg(['end' => '2026-11-08', 'special_days' => [['date' => '2026-11-04', 'count' => 0], ['date' => '2026-11-05', 'count' => 4]]]);
        $days = epa_days($cfg, ['2026-11-03' => ['name' => 'Testtag', 'holiday_type' => 'feiertag']]);
        $by = array_column($days, null, 'date');
        $this->assertSame(2, $by['2026-11-02']['required']);
        $this->assertTrue($by['2026-11-03']['skip']);
        $this->assertSame('Feiertag: Testtag', $by['2026-11-03']['reason']);
        $this->assertTrue($by['2026-11-04']['skip']);
        $this->assertSame(4, $by['2026-11-05']['required']);
        $this->assertSame('Wochentag nicht aktiv', $by['2026-11-07']['reason']);
    }

    public function testGenerateCoversDemandAndPrefersHomeArea(): void
    {
        $cfg = $this->cfg();
        $ctx = $this->ctx($cfg, [$this->person(1), $this->person(2, ['home' => true, 'area_id' => 1]), $this->person(3)]);
        $r = epa_generate($cfg, $ctx, false);
        $this->assertSame(10, $r['stats']['slots_total']);
        $this->assertSame(10, $r['stats']['slots_filled']);
        foreach ($r['plan'] as $uids) $this->assertContains(2, $uids, 'Stammbereich hat Vorrang');
    }

    public function testBlockersAreNeverPicked(): void
    {
        $cfg = $this->cfg(['demand_default' => 3, 'rules' => ['overwrite' => 'all'], 'force_cells' => ['1|2026-11-02']]);
        $state = [1 => ['2026-11-02' => ['t' => 'gesperrt', 'l' => 'Gesperrt', 'a' => 0]],
                  2 => ['2026-11-02' => ['t' => 'schule', 'l' => 'BS', 'a' => 8]],
                  3 => ['2026-11-02' => ['t' => 'abwesend', 'l' => 'Urlaub', 'a' => 0]]];
        $ctx = $this->ctx($cfg, [$this->person(1), $this->person(2), $this->person(3)], $state);
        $r = epa_generate($cfg, $ctx, false);
        $this->assertSame([], $r['plan']['2026-11-02'], 'Sperrtag, Berufsschule, Urlaub ohne Recht');
        $this->assertSame('block', $r['conflicts'][0]['level']);
        $r = epa_generate($cfg, $ctx, true);
        $this->assertSame([3], $r['plan']['2026-11-02'], 'Urlaub nur mit unlock_vacation und Regel „alles“');
    }

    public function testAzubiNeverAloneAndSupervisionRatio(): void
    {
        $cfg = $this->cfg(['demand_default' => 3, 'azubi' => ['max_per_day' => 3, 'max_share' => 100, 'supervision_ratio' => 1]]);
        $ctx = $this->ctx($cfg, [$this->person(1), $this->person(2, ['azubi_year' => 1]), $this->person(3, ['azubi_year' => 2])]);
        $r = epa_generate($cfg, $ctx, false);
        foreach ($r['plan'] as $uids) {
            $az = count(array_intersect($uids, [2, 3]));
            $this->assertLessThanOrEqual(1, $az, 'Schlüssel 1:1 bei einer Fachkraft');
            $this->assertContains(1, $uids);
        }
    }

    public function testLimitsAreRelaxedOnlyAsLastResortAndReported(): void
    {
        $cfg = $this->cfg(['demand_default' => 1, 'rules' => ['max_consecutive' => 2, 'max_days_total' => 3]]);
        $ctx = $this->ctx($cfg, [$this->person(1)]);
        $r = epa_generate($cfg, $ctx, false);
        $this->assertSame(5, $r['stats']['slots_filled'], 'Bedarf wird trotzdem gedeckt');
        $codes = array_column($r['conflicts'], 'code');
        $this->assertContains('grenze_gelockert', $codes);
        $this->assertContains('max_serie', $codes);
    }

    public function testApplyDecision(): void
    {
        $cfg = $this->cfg(['free_areas' => [7]]);
        $state = [1 => ['2026-11-02' => ['t' => 'einsatz', 'l' => 'T', 'a' => 7], '2026-11-03' => ['t' => 'einsatz', 'l' => 'K2', 'a' => 2]]];
        $ctx = $this->ctx($cfg, [$this->person(1)], $state);
        $this->assertSame('intern', epa_apply_decide($cfg, $ctx, 1, '2026-11-02', null, 5, false)['kind']);
        $this->assertSame('belegt', epa_apply_decide($cfg, $ctx, 1, '2026-11-03', null, 5, false)['code']);
        $this->assertSame('scope', epa_apply_decide($cfg, $ctx, 9, '2026-11-03', null, 5, false)['code']);
        $foreign = ['asg' => null, 'abs' => null, 'draft' => ['action' => 'set', 'change_type' => 'assignment', 'change_value' => '2', 'created_by' => 6, 'planning_run_id' => null]];
        $this->assertSame('fremder_entwurf', epa_apply_decide($cfg, $ctx, 1, '2026-11-04', $foreign, 5, false)['code']);
        $foreign['draft']['change_value'] = '1';
        $this->assertSame('unveraendert', epa_apply_decide($cfg, $ctx, 1, '2026-11-04', $foreign, 5, false)['code']);
        $foreign['draft']['planning_run_id'] = 12;
        $this->assertTrue(epa_apply_decide($cfg, $ctx, 1, '2026-11-04', $foreign, 5, false)['ok'], 'Assistenten-Entwürfe dürfen ersetzt werden');
        $this->assertSame('geaendert', epa_apply_decide($cfg, $ctx, 1, '2026-11-05', null, 5, false, 'aaaa', 'bbbb')['code']);
        $this->assertTrue(epa_apply_decide($cfg, $ctx, 1, '2026-11-05', null, 5, false, 'aaaa', 'aaaa')['ok']);
    }
}
