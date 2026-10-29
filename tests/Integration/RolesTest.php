<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\TestEnv;

/**
 * Rechte-Katalog, eigene Rollen je Mandant (anlegen/umbenennen/löschen),
 * früher rollengebundene Rechte und Rollen aus IdP-Gruppen (OIDC).
 */
final class RolesTest extends IntegrationTestCase
{
    private static ?string $idp = null;
    private static ?string $app = null;

    protected function tearDown(): void
    {
        $db = $this->db();
        $db->exec("DELETE FROM app_settings WHERE setting_key='tenant_config' OR setting_key LIKE 'oidc_cache_%'");
        $db->exec("DELETE FROM role_permissions WHERE role LIKE 'zz\\_%'");
        $db->exec("DELETE FROM custom_roles WHERE role_key LIKE 'zz\\_%'");
        $db->exec("DELETE FROM permission_user_overrides WHERE permission IN ('manage_config','manage_team')");
        foreach (TestEnv::USERS as $key => [$role]) $db->prepare('UPDATE users SET role=? WHERE username=?')->execute([$role, $key]);
        $db->exec('DELETE FROM login_attempts');
        parent::tearDown();
    }

    private function roles(Client $c, array $data): array
    {
        return $c->postJson('/rollen', $data + ['_token' => $c->csrf], ['X-Requested-With: XMLHttpRequest'])->json();
    }

    private function perm(string $role, string $perm): ?int
    {
        $s = $this->db()->prepare('SELECT granted FROM role_permissions WHERE role=? AND permission=?');
        $s->execute([$role, $perm]);
        $v = $s->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    public function testRolesPageShowsCatalogGroupsAndNewPermissions(): void
    {
        $page = $this->as('admin')->get('/rollen')->body;
        foreach (['Team &amp; Abwesenheiten', 'Betrieb', 'Bereiche, Feiertage &amp; Stammdaten pflegen', 'Im Wartungsmodus weiterarbeiten'] as $t) {
            $this->assertStringContainsString($t, $page);
        }
        /* Administrator: früher rollengebundene Rechte und manage_roles fest erteilt */
        $this->assertMatchesRegularExpression('/data-role="admin" data-perm="manage_team" checked disabled data-locked="1"/', $page);
        $this->assertMatchesRegularExpression('/data-role="admin" data-perm="manage_roles" checked disabled data-locked="1"/', $page);
        $this->assertMatchesRegularExpression('/data-role="verwaltung" data-perm="manage_config" checked>/', $page);
        $this->assertMatchesRegularExpression('/data-role="teamleiter" data-perm="manage_config" >/', $page);
    }

    public function testAdminKeepsRoleBoundPermissionsAndOverridesCannotRemoveThem(): void
    {
        require_once TestEnv::$root . '/app/lib/Permissions.php';
        $c = $this->as('admin');
        $r = $this->roles($c, ['action' => 'save_perms', 'role' => 'admin', 'perms' => array_values(array_diff(array_keys(\permissionCatalog()), ['manage_team', 'manage_config']))]);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame(1, $this->perm('admin', 'manage_team'));
        $this->assertSame(1, $this->perm('admin', 'manage_config'));
        /* Benutzer-Override "verweigert" wirkt beim Administrator nicht (wie früher hasRole) */
        $this->db()->prepare('INSERT INTO permission_user_overrides(permission,user_id,granted) VALUES(?,?,0)')->execute(['manage_config', $this->uid('admin')]);
        $this->assertSame(200, $this->as('admin')->get('/planbereiche')->status);
        $this->assertStringNotContainsString('<h2>Kein Zugriff</h2>', $this->as('admin')->get('/planbereiche')->body);
    }

    public function testRoleBoundPermissionsAreConfigurableForOtherRoles(): void
    {
        $this->assertStringContainsString('<h2>Kein Zugriff</h2>', $this->as('tl')->get('/planbereiche')->body);
        /* Einzelner Teamleiter erhält das Recht per Override */
        $c = $this->as('admin');
        $this->assertTrue($this->roles($c, ['action' => 'add_override', 'user_id' => $this->uid('tl'), 'permission' => 'manage_config', 'granted' => 1])['success']);
        $this->assertStringNotContainsString('<h2>Kein Zugriff</h2>', $this->as('tl')->get('/planbereiche')->body);
        /* Verwaltung verliert es per Override */
        $this->assertTrue($this->roles($c, ['action' => 'add_override', 'user_id' => $this->uid('vw'), 'permission' => 'manage_team', 'granted' => 0])['success']);
        $this->assertStringContainsString('<h2>Kein Zugriff</h2>', $this->as('vw')->get('/mitarbeiter')->body);
    }

    public function testRoleBoundDefaultsApplyWhenRowIsMissing(): void
    {
        /* Code vor Migration 0006: keine Zeilen → bisherige Rollenbindung */
        $this->db()->exec("DELETE FROM role_permissions WHERE permission='manage_team' AND role IN ('manager','lager')");
        try {
            $this->assertStringNotContainsString('<h2>Kein Zugriff</h2>', $this->as('mgr')->get('/mitarbeiter')->body);
            $this->assertStringContainsString('<h2>Kein Zugriff</h2>', $this->as('lager')->get('/mitarbeiter')->body);
            /* Rollen-Seite zeigt den wirksamen Wert – Speichern entzieht nichts */
            $page = $this->as('admin')->get('/rollen')->body;
            $this->assertMatchesRegularExpression('/data-role="manager" data-perm="manage_team" checked>/', $page);
        } finally {
            $this->db()->exec("INSERT IGNORE INTO role_permissions(role,permission,granted) VALUES('manager','manage_team',1),('lager','manage_team',0)");
        }
    }

    public function testCustomRolesCanBeCreatedRenamedAndDeleted(): void
    {
        $c = $this->as('admin');
        $r = $this->roles($c, ['action' => 'create_role', 'role_key' => 'zz_disponent', 'role_label' => 'Disponent', 'color' => '#336699', 'base_role' => 'verwaltung']);
        $this->assertTrue($r['success'], json_encode($r));
        /* Rechte der Basisrolle kopiert, inkl. früher rollengebundener */
        $this->assertSame(1, $this->perm('zz_disponent', 'plan_edit'));
        $this->assertSame(1, $this->perm('zz_disponent', 'manage_team'));
        $this->assertSame(0, $this->perm('zz_disponent', 'manage_roles'));
        $this->assertSame(0, $this->perm('zz_disponent', 'bypass_maintenance'));
        /* Doppelte oder unbekannte Schlüssel */
        $this->assertFalse($this->roles($c, ['action' => 'create_role', 'role_key' => 'manager', 'role_label' => 'X', 'base_role' => 'mitarbeiter'])['success']);
        $this->assertFalse($this->roles($c, ['action' => 'create_role', 'role_key' => 'zz_x', 'role_label' => 'X', 'base_role' => 'gibtsnicht'])['success']);
        /* Umbenennen: nur eigene Rollen */
        $r = $this->roles($c, ['action' => 'rename_role', 'role_key' => 'zz_disponent', 'role_label' => 'Disposition', 'color' => '#112233']);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame('Disposition', $this->db()->query("SELECT role_label FROM custom_roles WHERE role_key='zz_disponent'")->fetchColumn());
        $this->assertFalse($this->roles($c, ['action' => 'rename_role', 'role_key' => 'admin', 'role_label' => 'Chef'])['success']);
        $this->assertFalse($this->roles($c, ['action' => 'rename_role', 'role_key' => 'manager', 'role_label' => 'Chef'])['success']);
        $this->assertStringContainsString('Disposition', $c->get('/rollen')->body);
        /* Nicht-Admins ohne manage_roles: kein Zugriff */
        $this->assertFalse($this->roles($this->as('mgr'), ['action' => 'rename_role', 'role_key' => 'zz_disponent', 'role_label' => 'X'])['success']);
        /* Löschen nur ohne zugeordnete Benutzer; System-Rollen nie */
        $this->db()->exec("UPDATE users SET role='zz_disponent' WHERE username='ma2'");
        $this->assertFalse($this->roles($c, ['action' => 'delete_role', 'role_key' => 'zz_disponent'])['success']);
        $this->db()->exec("UPDATE users SET role='mitarbeiter' WHERE username='ma2'");
        $this->assertFalse($this->roles($c, ['action' => 'delete_role', 'role_key' => 'lager'])['success']);
        $this->assertTrue($this->roles($c, ['action' => 'delete_role', 'role_key' => 'zz_disponent'])['success']);
        $this->assertNull($this->perm('zz_disponent', 'plan_edit'));
    }

    public function testSnapshotCliReportsDifferences(): void
    {
        $file = sys_get_temp_dir() . '/pq-snapshot-' . getmypid() . '.json';
        [$rc, $out] = TestEnv::runCli([TestEnv::$root . '/bin/permissions-snapshot.php']);
        $this->assertSame(0, $rc, $out);
        file_put_contents($file, $out);
        try {
            [$rc, $out] = TestEnv::runCli([TestEnv::$root . '/bin/permissions-snapshot.php', '--diff=' . $file]);
            $this->assertSame(0, $rc, $out);
            $this->assertStringContainsString('Keine Abweichung', $out);
            $this->db()->prepare('INSERT INTO permission_user_overrides(permission,user_id,granted) VALUES(?,?,1)')->execute(['manage_team', $this->uid('tl')]);
            [$rc, $out] = TestEnv::runCli([TestEnv::$root . '/bin/permissions-snapshot.php', '--diff=' . $file]);
            $this->assertSame(1, $rc, $out);
            $this->assertStringContainsString('tl: perms.manage_team false → true', $out);
            $this->assertStringContainsString('tl: checks.can_manage_users false → true', $out);
        } finally { @unlink($file); }
    }

    /* ----------------------- OIDC: Gruppen → Rollen ----------------------- */

    private function oidc(array $extra = []): void
    {
        if (!self::$idp) {
            self::$idp = TestEnv::startExtraServer([], TestEnv::$root . '/tests/fixtures/fake_idp.php');
            self::$app = TestEnv::startExtraServer(['PQ_OIDC_ALLOW_HTTP' => '1']);
        }
        $cfg = ['security' => ['oidc' => $extra + ['enabled' => true, 'issuer' => self::$idp, 'client_id' => 'planquadro-test',
            'claim' => 'preferred_username', 'allow_password_login' => true, 'scopes' => 'openid']]];
        $this->db()->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute([json_encode($cfg)]);
    }

    private function sso(string $user, string $groups, string $claim = 'groups'): string
    {
        $c = new Client(); $c->base = self::$app;
        $auth = $c->get('/oidc?start=1')->header('Location');
        $this->assertNotNull($auth);
        $ic = new Client(); $ic->base = self::$idp;
        $cb = $ic->get(substr($auth, strlen(self::$idp)) . '&login_hint=' . urlencode($user) . '&groups=' . urlencode($groups) . '&groups_claim=' . urlencode($claim))->header('Location');
        return (string)$c->get(substr($cb, strlen(self::$app)))->header('Refresh');
    }

    private function role(string $user): string
    {
        $s = $this->db()->prepare('SELECT role FROM users WHERE username=?'); $s->execute([$user]);
        return (string)$s->fetchColumn();
    }

    public function testGroupMappingIsOffByDefault(): void
    {
        $this->oidc(['group_roles' => [['group' => 'planer', 'role' => 'teamleiter']]]);
        $this->assertStringContainsString('url=dashboard', $this->sso('ma1', 'planer'));
        $this->assertSame('mitarbeiter', $this->role('ma1'), 'ohne aktivierte Option bleibt die Rolle');
    }

    public function testGroupMappingAssignsFirstMatchingRole(): void
    {
        $map = [['group' => 'lager-team', 'role' => 'lager'], ['group' => 'planer', 'role' => 'teamleiter']];
        $this->oidc(['group_roles_enabled' => true, 'groups_claim' => 'roles', 'group_roles' => $map]);
        $this->db()->exec("DELETE FROM audit_log WHERE action='oidc_role_mapped'");
        $this->assertStringContainsString('url=dashboard', $this->sso('ma1', 'x,planer,lager-team', 'roles'));
        $this->assertSame('lager', $this->role('ma1'), 'erste passende Zeile');
        $this->assertSame(1, (int)$this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action='oidc_role_mapped' AND user_id=" . $this->uid('ma1'))->fetchColumn());
        /* Kein Treffer bzw. falscher Claim: Rolle bleibt */
        $this->sso('ma1', 'andere', 'roles');
        $this->assertSame('lager', $this->role('ma1'));
        $this->sso('ma1', 'planer', 'groups');
        $this->assertSame('lager', $this->role('ma1'));
    }

    public function testGroupMappingNeverGrantsOrTouchesAdministration(): void
    {
        /* Direkt gespeicherte (manipulierte) Zuordnung auf admin / Rolle mit manage_roles wird ignoriert */
        $this->db()->exec("INSERT INTO custom_roles(role_key,role_label,is_system) VALUES('zz_root','Root',0)");
        $this->db()->exec("INSERT INTO role_permissions(role,permission,granted) VALUES('zz_root','manage_roles',1)");
        $this->oidc(['group_roles_enabled' => true, 'allow_admin' => true, 'group_roles' => [
            ['group' => 'g-admin', 'role' => 'admin'], ['group' => 'g-root', 'role' => 'zz_root'], ['group' => 'g-tl', 'role' => 'teamleiter']]]);
        $this->sso('ma1', 'g-admin');
        $this->assertSame('mitarbeiter', $this->role('ma1'));
        $this->sso('ma1', 'g-root,g-tl');
        $this->assertSame('mitarbeiter', $this->role('ma1'), 'erste passende Zeile unzulässig → Rolle bleibt');
        /* Administrator-Konto wird nie über den IdP geändert */
        $this->assertStringContainsString('url=dashboard', $this->sso('admin', 'g-tl'));
        $this->assertSame('admin', $this->role('admin'));
    }

    public function testOrganisationPageValidatesGroupMapping(): void
    {
        $admin = $this->as('admin');
        $base = ['action' => 'save_oidc', 'enabled' => '1', 'issuer' => 'https://idp.example', 'client_id' => 'x', 'claim' => 'preferred_username',
            'allow_password_login' => '1', 'group_roles_enabled' => '1', 'groups_claim' => 'groups'];
        $r = $admin->postAjax('/organisation', $base + ['group_roles_group' => ['chefs'], 'group_roles_role' => ['admin']])->json();
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('Administrator-Rechte', $r['error']);
        $r = $admin->postAjax('/organisation', $base + ['group_roles_group' => ['planer', ''], 'group_roles_role' => ['teamleiter', '']])->json();
        $this->assertTrue($r['success'], json_encode($r));
        $cfg = json_decode((string)$this->db()->query("SELECT setting_value FROM app_settings WHERE setting_key='tenant_config'")->fetchColumn(), true);
        $this->assertSame([['group' => 'planer', 'role' => 'teamleiter']], $cfg['security']['oidc']['group_roles']);
        $this->assertTrue($cfg['security']['oidc']['group_roles_enabled']);
        $page = $admin->get('/organisation')->body;
        $this->assertStringContainsString('name="group_roles_enabled" value="1" checked', $page);
        $this->assertStringNotContainsString('<option value="admin"', $page);
        /* Manager (Einstellungen verwalten, kein Admin) darf SSO weiterhin nicht ändern */
        $this->assertSame(403, $this->as('mgr')->postAjax('/organisation', $base + ['group_roles_group' => ['planer'], 'group_roles_role' => ['manager']])->status);
    }
}
