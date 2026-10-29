<?php
namespace Vireo\Tests\Integration;

use Vireo\Tests\Support\IntegrationTestCase;

/** Regressionstests: Datenintegrität und Rechteverwaltung (D-03, D-04, S-15, S-16, X-03). */
final class DataIntegrityTest extends IntegrationTestCase
{
    public function testD03AbsenceModuleKeepsApprovedVacationWithoutUnlockRight(): void
    {
        $this->clearPlan();
        $ma = $this->uid('ma1');
        $d = $this->workday(1, 3);
        $this->db()->prepare("INSERT INTO absences(user_id,absence_date,absence_type,is_published) VALUES(?,?,'urlaub_genehmigt',1)")->execute([$ma, $d]);
        /* Teamleiter hat manage_absences, aber nicht unlock_vacation */
        $r = $this->as('tl')->postAjax('/abwesenheiten', ['action' => 'save', 'user_id' => $ma, 'type' => 'gleitzeit', 'from' => $d, 'to' => $d]);
        $this->assertTrue($r->json()['success'], $r->body);
        $this->assertSame('urlaub_genehmigt', $this->db()->query("SELECT absence_type FROM absences WHERE user_id=$ma AND absence_date='$d'")->fetchColumn());
        $r = $this->as('tl')->postAjax('/abwesenheiten', ['action' => 'delete', 'user_id' => $ma, 'from' => $d, 'to' => $d]);
        $this->assertTrue($r->json()['success']);
        $this->assertSame('urlaub_genehmigt', $this->db()->query("SELECT absence_type FROM absences WHERE user_id=$ma AND absence_date='$d'")->fetchColumn());
        /* Manager (unlock_vacation) darf */
        $r = $this->as('mgr')->postAjax('/abwesenheiten', ['action' => 'delete', 'user_id' => $ma, 'from' => $d, 'to' => $d]);
        $this->assertTrue($r->json()['success']);
        $this->assertFalse($this->db()->query("SELECT absence_type FROM absences WHERE user_id=$ma AND absence_date='$d'")->fetchColumn());
    }

    public function testD03AbsenceModuleRejectsInvalidDates(): void
    {
        $r = $this->as('tl')->postAjax('/abwesenheiten', ['action' => 'save', 'user_id' => $this->uid('ma1'), 'type' => 'gleitzeit', 'from' => 'gestern', 'to' => 'morgen']);
        $this->assertFalse($r->json()['success']);
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM absences WHERE absence_date='1970-01-01'")->fetchColumn());
    }

    public function testD04AreaWithAssignmentsCannotBeDeleted(): void
    {
        $this->clearPlan();
        $area = $this->areaId('K2');
        $this->db()->prepare('INSERT INTO assignments(user_id,plan_date,plan_area_id,year,month,is_published) VALUES(?,?,?,?,?,1)')
            ->execute([$this->uid('ma1'), '2030-01-08', $area, 2030, 1]);
        $r = $this->as('vw')->postAjax('/planbereiche', ['action' => 'del', 'id' => $area]);
        $this->assertFalse($r->json()['success']);
        $this->assertSame(1, (int)$this->db()->query('SELECT COUNT(*) FROM assignments')->fetchColumn());
        $this->assertSame(1, (int)$this->db()->query("SELECT COUNT(*) FROM plan_areas WHERE id=$area")->fetchColumn());
        $this->clearPlan();
    }

    public function testS12AreaColorsMustBeHex(): void
    {
        $r = $this->as('vw')->postAjax('/planbereiche', ['action' => 'save', 'name' => 'X', 'short_code' => 'XX', 'color' => 'red;}</style><script>alert(1)</script>', 'fg_color' => '#fff']);
        $this->assertFalse($r->json()['success']);
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM plan_areas WHERE short_code='XX'")->fetchColumn());
    }

    public function testX03EveryPermissionIsEditableOnRolesPage(): void
    {
        /* Sonst würde „Speichern“ nicht angezeigte Rechte auf 0 setzen */
        $page = $this->as('admin')->get('/rollen')->body;
        foreach (['plan_edit', 'manage_absences', 'use_planning_assistant', 'view_all_employees', 'approve_requests'] as $p) {
            $this->assertStringContainsString('data-perm="' . $p . '"', $page, $p);
        }
    }

    public function testS16AdminRoleKeepsManageRoles(): void
    {
        $c = $this->as('admin');
        $r = $c->postJson('/rollen', ['action' => 'save_perms', 'role' => 'admin', 'perms' => ['plan_view'], '_token' => $c->csrf], ['X-Requested-With: XMLHttpRequest']);
        $this->assertFalse($r->json()['success']);
        $this->assertSame(1, (int)$this->db()->query("SELECT granted FROM role_permissions WHERE role='admin' AND permission='plan_edit'")->fetchColumn());
    }

    public function testS15NobodyExceptOnlyTouchesUsersOfThatRole(): void
    {
        $db = $this->db();
        $db->exec('DELETE FROM permission_user_overrides');
        $lager = $this->uid('lager');
        $db->prepare("INSERT INTO permission_user_overrides(permission,user_id,granted) VALUES('view_erl_column',?,1)")->execute([$lager]);
        $c = $this->as('admin');
        $r = $c->postJson('/rollen', ['action' => 'nobody_except', 'permission' => 'view_erl_column', 'role' => 'teamleiter',
            'allowed_user_ids' => [$this->uid('tl')], '_token' => $c->csrf], ['X-Requested-With: XMLHttpRequest']);
        $this->assertTrue($r->json()['success'], $r->body);
        $this->assertSame(1, (int)$db->query("SELECT granted FROM permission_user_overrides WHERE user_id=$lager AND permission='view_erl_column'")->fetchColumn(),
            'Override eines Benutzers einer anderen Rolle muss erhalten bleiben');
        $this->assertSame(0, (int)$db->query("SELECT granted FROM permission_user_overrides WHERE user_id={$this->uid('tl2')} AND permission='view_erl_column'")->fetchColumn());
        $db->exec('DELETE FROM permission_user_overrides');
    }
}
