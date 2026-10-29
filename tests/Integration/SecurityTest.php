<?php
namespace OpsPilot\Tests\Integration;

use OpsPilot\Tests\Support\Client;
use OpsPilot\Tests\Support\IntegrationTestCase;
use OpsPilot\Tests\Support\TestEnv;

/**
 * Regressionstests zu den Sicherheitsbefunden aus docs/OPSPILOT_ROADMAP.md.
 * Die ID im Testnamen verweist auf den Befund.
 */
final class SecurityTest extends IntegrationTestCase
{
    private function role(string $user): string
    {
        $s = $this->db()->prepare('SELECT role FROM users WHERE username=?');
        $s->execute([$user]);
        return (string)$s->fetchColumn();
    }

    private function userForm(string $user, array $over = []): array
    {
        $s = $this->db()->prepare('SELECT * FROM users WHERE username=?');
        $s->execute([$user]);
        $u = $s->fetch();
        return array_merge(['action' => 'save', 'id' => $u['id'], 'username' => $u['username'], 'first_name' => $u['first_name'],
            'last_name' => $u['last_name'], 'role' => $u['role'], 'vacation_days' => $u['vacation_days']], $over);
    }

    /* ---------- S-02: keine Rechteausweitung über die Benutzerverwaltung ---------- */

    public function testS02ManagerCannotMakeHimselfAdmin(): void
    {
        $r = $this->as('mgr')->postAjax('/mitarbeiter', $this->userForm('mgr', ['role' => 'admin']));
        $this->assertFalse($r->json()['success'] ?? true);
        $this->assertSame('manager', $this->role('mgr'));
    }

    public function testS02VerwaltungCannotPromoteOthersToAdmin(): void
    {
        $r = $this->as('vw')->postAjax('/mitarbeiter', $this->userForm('lager', ['role' => 'admin']));
        $this->assertFalse($r->json()['success'] ?? true);
        $this->assertSame('lager', $this->role('lager'));
    }

    public function testS02ManagerCannotTakeOverAdminAccount(): void
    {
        $before = $this->db()->query("SELECT password_hash FROM users WHERE username='admin'")->fetchColumn();
        $c = $this->as('mgr');
        $r1 = $c->postAjax('/mitarbeiter', $this->userForm('admin', ['password' => 'Uebernommen-123']));
        $r2 = $c->postAjax('/mitarbeiter', ['action' => 'reset_pw', 'id' => $this->uid('admin')]);
        $r3 = $c->postAjax('/mitarbeiter', ['action' => 'toggle', 'id' => $this->uid('admin')]);
        foreach ([$r1, $r2, $r3] as $r) $this->assertFalse($r->json()['success'] ?? true);
        $row = $this->db()->query("SELECT password_hash, active FROM users WHERE username='admin'")->fetch();
        $this->assertSame($before, $row['password_hash']);
        $this->assertSame(1, (int)$row['active']);
    }

    public function testS02ManagerCanStillEditNormalEmployees(): void
    {
        $r = $this->as('mgr')->postAjax('/mitarbeiter', $this->userForm('lager', ['first_name' => 'Lagerist']));
        $this->assertTrue($r->json()['success']);
        $this->assertSame('Lagerist', $this->db()->query("SELECT first_name FROM users WHERE username='lager'")->fetchColumn());
        $this->db()->exec("UPDATE users SET first_name='Lager' WHERE username='lager'");
    }

    public function testS02AdminCanStillAssignAdminRole(): void
    {
        $c = $this->as('admin');
        $r = $c->postAjax('/mitarbeiter', $this->userForm('lager', ['role' => 'admin']));
        $this->assertTrue($r->json()['success']);
        $this->assertSame('admin', $this->role('lager'));
        $c->postAjax('/mitarbeiter', $this->userForm('lager', ['role' => 'lager']));
        $this->assertSame('lager', $this->role('lager'));
    }

    public function testS02UnknownRoleIsRejected(): void
    {
        $r = $this->as('admin')->postAjax('/mitarbeiter', $this->userForm('lager', ['role' => 'superuser']));
        $this->assertFalse($r->json()['success'] ?? true);
        $this->assertSame('lager', $this->role('lager'));
    }

    /* ---------- S-03: install.php ---------- */

    public function testS03InstallIsLockedByDefault(): void
    {
        $this->db()->exec("UPDATE users SET username='admin_renamed' WHERE username='admin'");
        try {
            $r = (new Client())->get('/install');
            $this->assertContains($r->status, [403, 404]);
            $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM users WHERE username='admin'")->fetchColumn());
        } finally {
            $this->db()->exec("DELETE FROM users WHERE username='admin'");
            $this->db()->exec("UPDATE users SET username='admin' WHERE username='admin_renamed'");
        }
    }

    /* ---------- S-04 / S-08: keine Hashes / Einmalpasswörter im HTML ---------- */

    public function testS04TeamPageDoesNotLeakSecrets(): void
    {
        $c = $this->as('admin');
        $r = $c->postAjax('/mitarbeiter', ['action' => 'reset_pw', 'id' => $this->uid('lager')]);
        $otp = $r->json()['otp'];
        $page = $c->get('/mitarbeiter')->body;
        $this->assertStringNotContainsString('$2y$', $page);
        $this->assertStringNotContainsString('password_hash', $page);
        $this->assertStringNotContainsString($otp, $page);
        $this->assertNull($this->db()->query("SELECT initial_password FROM users WHERE username='lager'")->fetchColumn() ?: null,
            'Einmalpasswort darf nicht im Klartext gespeichert werden');
        $this->db()->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE username=?')
            ->execute([password_hash(TestEnv::PASSWORD, PASSWORD_DEFAULT), 'lager']);
    }

    /* ---------- S-06: CSRF ---------- */

    public function testS06ApiRejectsRequestsWithoutCsrfToken(): void
    {
        $c = $this->as('tl');
        $c->sendCsrf = false;
        $r = $c->postJson('/api/save_bulk', ['changes' => [['user_id' => $this->uid('ma1'), 'date' => $this->workday(), 'type' => 'assignment', 'value' => (string)$this->areaId()]]]);
        $this->assertSame(403, $r->status);
        $this->assertNull($this->draft($this->uid('ma1'), $this->workday()));
    }

    public function testS06PageActionsRejectRequestsWithoutCsrfToken(): void
    {
        $c = $this->as('admin');
        $c->sendCsrf = false;
        $r = $c->postAjax('/mitarbeiter', $this->userForm('lager', ['first_name' => 'Csrf']));
        $this->assertSame(403, $r->status);
        $this->assertSame('Lager', $this->db()->query("SELECT first_name FROM users WHERE username='lager'")->fetchColumn());
    }

    public function testS06LoginFormRequiresToken(): void
    {
        $c = new Client();
        $r = $c->request('POST', '/', http_build_query(['username' => 'lager', 'password' => TestEnv::PASSWORD]), ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertNotSame('dashboard', $r->header('Location'));
    }

    /* ---------- S-07: Session Fixation ---------- */

    public function testS07SessionIdChangesOnLogin(): void
    {
        $c = new Client();
        $r1 = $c->get('/');
        preg_match('/PHPSESSID=([^;]+)/', $r1->headers, $m1);
        $r2 = $c->login('lager', TestEnv::PASSWORD);
        preg_match('/PHPSESSID=([^;]+)/', $r2->headers, $m2);
        $this->assertNotEmpty($m2[1] ?? null, 'Nach dem Login muss ein neues Session-Cookie gesetzt werden');
        $this->assertNotSame($m1[1] ?? '', $m2[1]);
    }

    /* ---------- S-09: Team-Scope ---------- */

    public function testS09TeamleaderCannotWriteWotsOrHoursOfForeignTeam(): void
    {
        $c = $this->as('tl');
        $foreign = $this->uid('ma2');
        $r = $c->postJson('/api/save_wots', ['action' => 'add', 'user_id' => $foreign, 'year' => 2030, 'month' => 1, 'wot_number' => 'WOT-X']);
        $this->assertSame(403, $r->status);
        $r = $c->postJson('/api/save_month_data', ['user_id' => $foreign, 'year' => 2030, 'month' => 1, 'hours' => '99']);
        $this->assertSame(403, $r->status);
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM user_wots WHERE user_id=$foreign")->fetchColumn());
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM user_month_data WHERE user_id=$foreign")->fetchColumn());
    }

    public function testS09TeamleaderCanWriteWotsOfOwnTeam(): void
    {
        $own = $this->uid('ma1');
        $r = $this->as('tl')->postJson('/api/save_wots', ['action' => 'add', 'user_id' => $own, 'year' => 2030, 'month' => 1, 'wot_number' => 'WOT-1', 'color' => '#123456']);
        $this->assertTrue($r->json()['success']);
        $this->db()->exec("DELETE FROM user_wots");
    }

    public function testS09WeekContextRespectsScope(): void
    {
        $r = $this->as('tl')->postJson('/api/change_requests', ['action' => 'week_context', 'user_id' => $this->uid('ma2'), 'date' => $this->workday()]);
        $this->assertFalse($r->json()['success'] ?? true);
    }

    /* ---------- S-10: Genehmigen nur mit approve_requests ---------- */

    public function testS10VerwaltungCannotApproveRequests(): void
    {
        $this->clearPlan();
        $ma = $this->uid('ma1');
        $date = $this->workday();
        $this->db()->prepare("INSERT INTO change_requests(user_id,plan_date,request_type,plan_area_id,status,requested_by) VALUES(?,?,'assignment',?,'pending',?)")
            ->execute([$ma, $date, $this->areaId(), $ma]);
        $id = (int)$this->db()->lastInsertId();
        $r = $this->as('vw')->postJson('/api/change_requests', ['action' => 'approve_batch', 'ids' => [$id]]);
        $this->assertFalse($r->json()['success'] ?? true);
        $this->assertSame('pending', $this->db()->query("SELECT status FROM change_requests WHERE id=$id")->fetchColumn());
        $r = $this->as('tl')->postJson('/api/change_requests', ['action' => 'approve_batch', 'ids' => [$id]]);
        $this->assertTrue($r->json()['success']);
        $this->assertSame('approved', $this->db()->query("SELECT status FROM change_requests WHERE id=$id")->fetchColumn());
        $this->clearPlan();
    }

    /* ---------- S-11: Vertraulichkeit im Abwesenheitsmodul ---------- */

    public function testS11AbsenceListDoesNotLeakConfidentialType(): void
    {
        $this->clearPlan();
        $date = $this->workday();
        $this->db()->prepare("INSERT INTO absences(user_id,absence_date,absence_type,is_published) VALUES(?,?,'krank_attest',1)")
            ->execute([$this->uid('ma1'), $date]);
        /* Verwaltung sieht alle Mitarbeiter, ist aber nicht Vorgesetzte */
        $r = $this->as('vw')->postAjax('/abwesenheiten', ['action' => 'list', 'from' => $date, 'to' => $date]);
        $this->assertStringNotContainsString('krank', $r->body);
        $this->assertStringContainsString('vertraulich', $r->body);
        /* Teamleiter sieht den Grund */
        $r = $this->as('tl')->postAjax('/abwesenheiten', ['action' => 'list', 'from' => $date, 'to' => $date]);
        $this->assertStringContainsString('krank_attest', $r->body);
        $this->clearPlan();
    }

    /* ---------- S-14: kein Zugriff während erzwungenem Passwortwechsel ---------- */

    public function testS14ForcedPasswordChangeBlocksProfileActions(): void
    {
        $this->db()->exec("UPDATE users SET must_change_password=1 WHERE username='azubi'");
        try {
            $c = new Client();
            $c->login('azubi', TestEnv::PASSWORD);
            $r = $c->postJson('/profil', ['action' => 'generate_cal_token'], ['X-Requested-With: XMLHttpRequest']);
            $this->assertNull($this->db()->query("SELECT cal_token FROM users WHERE username='azubi'")->fetchColumn() ?: null);
            $this->assertNotSame(200, $r->status === 200 && str_contains($r->body, '"success":true') ? 200 : 0);
        } finally {
            $this->db()->exec("UPDATE users SET must_change_password=0, cal_token=NULL WHERE username='azubi'");
        }
    }

    /* ---------- S-19: Meldungen nur angemeldet ---------- */

    public function testS19AnnouncementsRequireLogin(): void
    {
        $r = (new Client())->postJson('/api/announcements', ['action' => 'active']);
        $this->assertContains($r->status, [401, 403]);
    }

    /* ---------- S-12: Farbwerte werden validiert ---------- */

    public function testS12WotColorAndLinkAreValidated(): void
    {
        $own = $this->uid('ma1');
        $r = $this->as('tl')->postJson('/api/save_wots', ['action' => 'add', 'user_id' => $own, 'year' => 2030, 'month' => 2,
            'wot_number' => 'W', 'color' => 'x" onclick="alert(1)', 'link_url' => 'javascript:alert(1)']);
        $this->assertFalse($r->json()['success'] ?? true);
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM user_wots")->fetchColumn());
    }
}
