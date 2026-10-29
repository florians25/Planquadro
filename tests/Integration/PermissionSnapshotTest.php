<?php
namespace Planquadro\Tests\Integration;

use PDO;
use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\Response;
use Planquadro\Tests\Support\TestEnv;

/**
 * Rechte-Snapshot vor/nach der Rechte-Migration (MIGRATION_PLAN.md Abschnitt 4).
 *
 * Eine eigene Datenbank mit allen Test-Benutzern, zwei eigenen Rollen, einigen
 * Benutzer-Overrides und einer aktiven Vertretung. Für jeden Benutzer werden
 * die effektive Rechtematrix (bin/permissions-snapshot.php) und die Zugriffe
 * auf alle Seiten, Seiten-Aktionen und APIs erfasst und mit dem eingecheckten
 * Snapshot verglichen, der auf dem Code-Stand VOR der Migration erzeugt wurde
 * (tests/fixtures/permissions_snapshot.json).
 *
 * Neu erzeugen (nur auf einem Stand, dessen Rechte als Referenz gelten):
 *   PQ_SNAPSHOT_WRITE=1 vendor/bin/phpunit --filter PermissionSnapshotTest
 */
final class PermissionSnapshotTest extends IntegrationTestCase
{
    private const FIXTURE = '/tests/fixtures/permissions_snapshot.json';

    /** Zusätzliche Benutzer der Snapshot-Datenbank: Schlüssel => [Rolle, Vorgesetzter] */
    private const EXTRA_USERS = [
        'prakt' => ['praktikant', 'tl'],
        'mgr2'  => ['kopie_manager', null],
    ];

    private const ROLE_BOUND = ['manage_team', 'manage_config', 'bypass_maintenance', 'manage_maintenance', 'delete_changelog', 'manage_deputations'];

    private const PAGES = [
        'dashboard', 'mein_plan', 'gesamtplan', 'jahresuebersicht', 'profil', 'datenschutz', 'monatsplanung',
        'planungsassistent', 'abwesenheiten', 'genehmigungen', 'urlaubsplanung', 'vertretung', 'mitarbeiter',
        'planbereiche', 'feiertage', 'einstellungen', 'organisation', 'stammdaten', 'rollen',
    ];

    /** Seiten-AJAX: unbekannte Aktion zeigt, ob die Rechteprüfung davor greift */
    private const PAGE_ACTIONS = [
        'mitarbeiter', 'einstellungen', 'planbereiche', 'feiertage', 'vertretung', 'stammdaten',
        'organisation', 'abwesenheiten', 'planungsassistent',
    ];

    /** JSON-APIs mit Aufrufen ohne Datenänderung (leere Listen, id 0, unbekannte Aktion) */
    private const API_PROBES = [
        'save_bulk'                => ['save_bulk', []],
        'save_remark'              => ['save_remark', []],
        'save_wots.list'           => ['save_wots', ['action' => 'list']],
        'save_month_data'          => ['save_month_data', []],
        'live_status.cells'        => ['live_status', ['action' => 'cells', 'year' => 2000, 'month' => 1]],
        'change_requests.list'     => ['change_requests', ['action' => 'list']],
        'change_requests.approve'  => ['change_requests', ['action' => 'approve_batch', 'ids' => []]],
        'change_requests.reject'   => ['change_requests', ['action' => 'reject_batch', 'ids' => []]],
        'change_requests.week'     => ['change_requests', ['action' => 'week_context']],
        'backup.list'              => ['backup', ['action' => 'list']],
        'announcements.list'       => ['announcements', ['action' => 'list']],
        'announcements.active'     => ['announcements', ['action' => 'active']],
        'notifications.count'      => ['notifications', ['action' => 'count']],
        'feiertage.none'           => ['feiertage', ['action' => 'none']],
        'cleanup.none'             => ['cleanup', ['action' => 'none']],
        'maintenance.none'         => ['maintenance', ['action' => 'none']],
        'changelog.list'           => ['changelog', ['action' => 'list']],
        'changelog.add_empty'      => ['changelog', ['action' => 'add']],
        'changelog.delete_0'       => ['changelog', ['action' => 'delete', 'id' => 0]],
    ];

    private static ?string $server = null;
    private static array $dbCfg = [];
    private static array $tokens = [];

    public function testEffectivePermissionsAndAccessMatchSnapshotBeforeMigration(): void
    {
        $this->setUpSnapshotDb();
        $snap = $this->snapshot();
        $file = TestEnv::$root . self::FIXTURE;
        if (getenv('PQ_SNAPSHOT_WRITE') === '1') {
            file_put_contents($file, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        $this->assertFileExists($file, 'Referenz-Snapshot fehlt');
        $expected = json_decode((string)file_get_contents($file), true);
        $this->assertSnapshotEquals($expected, $snap, 'nach allen Migrationen');

        /* Rückweg laut MIGRATION_PLAN.md (Zeilen von 0006 entfernen): neuer Code
           auf einer Datenbank ohne 0006 – Katalog-Standard greift, gleiches Ergebnis. */
        $db = $this->snapDb();
        $in = "'" . implode("','", self::ROLE_BOUND) . "'";
        $db->exec("DELETE FROM role_permissions WHERE permission IN ($in)");
        $db->exec("DELETE FROM permission_user_overrides WHERE permission IN ($in)");
        $db->exec("DELETE FROM schema_migrations WHERE version = 'pq_0006_role_bound_permissions'");
        $this->assertSnapshotEquals($expected, $this->snapshot(), 'Datenbank ohne 0006 (Rückweg / Code vor Migration)');

        /* Erneut migrieren und die Datei ein zweites Mal direkt ausführen (idempotent) */
        $this->migrateSnap();
        $count = (int)$db->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn();
        $db->exec((string)file_get_contents(TestEnv::$root . '/migrations/app/0006_role_bound_permissions.sql'));
        $this->assertSame($count, (int)$db->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn(), '0006 ist idempotent');
        $this->assertSnapshotEquals($expected, $this->snapshot(), 'nach erneuter Migration');

        /* Eigene Rollen erhalten die neuen Rechte nicht – auch nicht mit Basisrolle manager */
        $granted = $db->query("SELECT COUNT(*) FROM role_permissions WHERE role IN ('praktikant','kopie_manager') AND permission IN ($in) AND granted=1")->fetchColumn();
        $this->assertSame(0, (int)$granted);
        $this->assertSame(12, (int)$db->query("SELECT COUNT(*) FROM role_permissions WHERE role IN ('praktikant','kopie_manager') AND permission IN ($in)")->fetchColumn());
    }

    /** Standardwerte im Katalog = Rechte einer Neuinstallation (alle Migrationen) */
    public function testCatalogDefaultsMatchFreshInstallation(): void
    {
        require_once TestEnv::$root . '/app/lib/Permissions.php';
        $rows = $this->db()->query('SELECT role, permission, granted FROM role_permissions')->fetchAll();
        $db = [];
        foreach ($rows as $r) if (in_array($r['role'], \SYSTEM_ROLES, true)) $db[$r['permission']][$r['role']] = (bool)$r['granted'];
        $diff = [];
        foreach (\permissionCatalog() as $perm => $p) {
            foreach (\SYSTEM_ROLES as $role) {
                $actual = $db[$perm][$role] ?? false;
                if ($actual !== in_array($role, $p['roles'], true)) $diff[] = "$perm/$role";
            }
        }
        $this->assertSame([], $diff);
        $this->assertSame([], array_values(array_diff(array_keys($db), array_keys(\permissionCatalog()))), 'Rechte in der DB, die der Katalog nicht kennt');
        foreach (self::ROLE_BOUND as $perm) $this->assertTrue(\permissionIsRoleBound($perm), $perm);
    }

    /* ------------------------------------------------------------------ */

    /** Vergleich: Rechte, die nur im neuen Stand existieren, sind Ergänzungen. */
    private function assertSnapshotEquals(array $expected, array $actual, string $phase): void
    {
        $this->assertSame(array_keys($expected['access']), array_keys($actual['access']), "$phase: Benutzer");
        $diff = [];
        foreach ($expected['access'] as $user => $rows) {
            foreach ($rows as $k => $v) {
                $got = $actual['access'][$user][$k] ?? '(fehlt)';
                if ($got !== $v) $diff[] = "$user $k: " . json_encode($v) . ' → ' . json_encode($got);
            }
        }
        foreach ($expected['matrix'] as $user => $m) {
            foreach ($m as $field => $val) {
                if (in_array($field, ['perms', 'checks'], true)) {
                    foreach ($val as $k => $v) {
                        $got = $actual['matrix'][$user][$field][$k] ?? '(fehlt)';
                        if ($got !== $v) $diff[] = "$user $field.$k: " . json_encode($v) . ' → ' . json_encode($got);
                    }
                } elseif (($actual['matrix'][$user][$field] ?? null) !== $val) {
                    $diff[] = "$user $field: " . json_encode($val) . ' → ' . json_encode($actual['matrix'][$user][$field] ?? null);
                }
            }
        }
        $this->assertSame([], $diff, "$phase: Rechte-Snapshot weicht ab");
    }

    private function setUpSnapshotDb(): void
    {
        self::$dbCfg = TestEnv::$db;
        $name = TestEnv::$db['name'] . '_snap';
        self::$dbCfg['name'] = $name;
        $root = TestEnv::rootPdo();
        $root->exec("DROP DATABASE IF EXISTS `$name`");
        $root->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->migrateSnap();
        $db = $this->snapDb();
        $hash = password_hash(TestEnv::PASSWORD, PASSWORD_DEFAULT);
        /* Eigene Rollen, wie sie VOR der Rechte-Migration über "Rollen & Rechte"
           angelegt wurden (Rechte der Basisrolle kopiert – nur die damals
           vorhandenen; 0006 ergänzt die neuen danach als "nicht erteilt"). */
        foreach (['praktikant' => ['Praktikant', 'mitarbeiter'], 'kopie_manager' => ['Kopie Manager', 'manager']] as $key => [$label, $base]) {
            $db->prepare('INSERT INTO custom_roles(role_key,role_label,color,base_role,is_system,sort_order) VALUES(?,?,?,?,0,100)')->execute([$key, $label, '#123456', $base]);
            $db->prepare("INSERT INTO role_permissions(role,permission,granted) SELECT ?, permission, granted FROM role_permissions WHERE role=? AND permission NOT IN ('" . implode("','", self::ROLE_BOUND) . "')")->execute([$key, $base]);
        }
        /* … und die Rechte-Migration lief danach (Reihenfolge wie bei einer Bestandsinstallation) */
        $m6 = TestEnv::$root . '/migrations/app/0006_role_bound_permissions.sql';
        if (is_file($m6)) $db->exec((string)file_get_contents($m6));
        $ins = $db->prepare('INSERT INTO users(username,password_hash,first_name,last_name,role,vacation_days,password_changed_at,active) VALUES(?,?,?,?,?,30,NOW(),1)');
        $all = TestEnv::USERS + self::EXTRA_USERS;
        foreach ($all as $key => [$role, $sup]) $ins->execute([$key, $hash, ucfirst($key), 'Test', $role]);
        $id = function (string $u) use ($db): int { $s = $db->prepare('SELECT id FROM users WHERE username=?'); $s->execute([$u]); return (int)$s->fetchColumn(); };
        $sup = $db->prepare('INSERT INTO user_supervisors(user_id,supervisor_id) VALUES(?,?)');
        foreach ($all as $key => [$role, $boss]) if ($boss) $sup->execute([$id($key), $id($boss)]);
        /* Benutzer-Overrides (müssen 1:1 erhalten bleiben) */
        $ov = $db->prepare('INSERT INTO permission_user_overrides(permission,user_id,granted,created_by) VALUES(?,?,?,?)');
        $ov->execute(['approve_requests', $id('vw'), 1, $id('admin')]);
        $ov->execute(['plan_edit', $id('ma2'), 1, $id('admin')]);
        $ov->execute(['view_all_employees', $id('lager'), 0, $id('admin')]);
        $ov->execute(['manage_users', $id('tl'), 1, $id('admin')]);
        $ov->execute(['manage_settings', $id('mgr'), 0, $id('admin')]);
        /* Aktive Vertretung: tl2 vertritt tl */
        $db->prepare('INSERT INTO deputations(delegator_id,deputy_id,start_date,end_date,created_by) VALUES(?,?,?,?,?)')
           ->execute([$id('tl'), $id('tl2'), date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+30 days')), $id('tl')]);
        /* Alle Module an, damit jede Rechteprüfung erreicht wird */
        $db->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='captcha_enabled'");
        $db->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('approval_system_enabled','1') ON DUPLICATE KEY UPDATE setting_value='1'");
        $mods = ['assistant', 'absences', 'approvals', 'vacation', 'deputation', 'annual', 'wots', 'ical', 'masterdata', 'mobile'];
        $db->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
           ->execute([json_encode(['modules' => array_fill_keys($mods, true)])]);
        if (!self::$server) self::$server = TestEnv::startExtraServer(['PQ_DB_NAME' => $name]);
    }

    private function migrateSnap(): void
    {
        [$rc, $out] = TestEnv::runCli([TestEnv::$root . '/bin/migrate.php', 'up'], ['PQ_DB_NAME' => self::$dbCfg['name']]);
        $this->assertSame(0, $rc, $out);
    }

    private function snapDb(): PDO
    {
        $c = self::$dbCfg;
        return new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    private function snapshot(): array
    {
        [$rc, $out] = TestEnv::runCli([TestEnv::$root . '/bin/permissions-snapshot.php'], ['PQ_DB_NAME' => self::$dbCfg['name']]);
        $this->assertSame(0, $rc, $out);
        $matrix = json_decode($out, true);
        $this->assertIsArray($matrix, $out);
        $access = [];
        foreach (array_keys(TestEnv::USERS + self::EXTRA_USERS) as $u) $access[$u] = $this->access($u);
        ksort($access, SORT_STRING);
        /* Wartungsmodus: wer arbeitet weiter? */
        $db = $this->snapDb();
        $db->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('maintenance_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
        try {
            foreach (array_keys($access) as $u) {
                $c = $this->client($u);
                $access[$u]['maintenance:dashboard'] = $this->classify($c->get('/dashboard'));
                $v1 = new Client(); $v1->base = self::$server; $v1->sendCsrf = false;
                $r = $v1->request('GET', '/api/v1/me', null, ['Authorization: Bearer ' . (self::$tokens[$u] ?? '-')]);
                $access[$u]['maintenance:v1_me'] = $r->status === 503 ? 'maintenance' : $this->classify($r);
            }
        } finally {
            $db->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='maintenance_mode'");
        }
        $db->exec('DELETE FROM login_attempts');
        return ['matrix' => $matrix, 'access' => $access];
    }

    private function client(string $user): Client
    {
        $c = new Client();
        $c->base = self::$server;
        $c->login($user, TestEnv::PASSWORD);
        return $c;
    }

    /** Zugriffe eines Benutzers auf Seiten, Seiten-Aktionen, APIs und API v1 */
    private function access(string $user): array
    {
        $c = $this->client($user);
        $dash = $c->get('/dashboard');
        $row = ['nav' => $this->navLinks($dash->body)];
        foreach (self::PAGES as $p) $row["page:$p"] = $this->classify($c->get('/' . $p));
        $row['organisation:sso_form'] = strpos($c->get('/organisation')->body, 'id="fOidc"') !== false;
        foreach (self::PAGE_ACTIONS as $p) $row["ajax:$p"] = $this->classify($c->postAjax('/' . $p, ['action' => 'snapshot_probe']));
        $row['ajax:organisation.test_oidc'] = $this->classify($c->postAjax('/organisation', ['action' => 'test_oidc']));
        $row['ajax:rollen'] = $this->classify($c->postJson('/rollen', ['action' => 'snapshot_probe', '_token' => $c->csrf], ['X-Requested-With: XMLHttpRequest']));
        foreach (self::API_PROBES as $k => [$api, $body]) $row["api:$k"] = $this->classify($c->postJson('/api/' . $api, $body));
        /* API v1 (Bearer-Token) */
        $v1 = new Client(); $v1->base = self::$server; $v1->sendCsrf = false;
        $tok = $v1->request('POST', '/api/v1/auth/login', json_encode(['username' => $user, 'password' => TestEnv::PASSWORD, 'device_name' => 'Snapshot']), ['Content-Type: application/json']);
        $token = json_decode($tok->body, true)['access_token'] ?? null;
        $row['v1:login'] = $token ? 'ok' : $this->classify($tok);
        self::$tokens[$user] = $token;
        if ($token) {
            $h = ['Authorization: Bearer ' . $token];
            $me = json_decode($v1->request('GET', '/api/v1/me', null, $h)->body, true);
            $row['v1:me.permissions'] = $me['permissions'] ?? null;
            $row['v1:plan_team'] = $this->classify($v1->request('GET', '/api/v1/plan/team?year=2000&month=1', null, $h));
            $team = json_decode($v1->request('GET', '/api/v1/plan/team?year=' . date('Y') . '&month=' . date('n'), null, $h)->body, true);
            $names = array_map(function ($u) { return $u['name']; }, $team['users'] ?? []);
            sort($names, SORT_STRING);
            $row['v1:plan_team.users'] = $names;
        }
        ksort($row, SORT_STRING);
        return $row;
    }

    private function navLinks(string $html): array
    {
        if (!preg_match('#<div class="nav-links" id="navLinks">(.*?)<div class="nav-right">#s', $html, $m)) return [];
        preg_match_all('#href="(?:\.\./)?([a-z_]+)"#', $m[1], $mm);
        $links = array_values(array_unique($mm[1]));
        sort($links, SORT_STRING);
        return $links;
    }

    /** ok | denied | redirect | http_<code> – unabhängig von Texten der Oberfläche */
    private function classify(Response $r): string
    {
        if (in_array($r->status, [401, 403], true)) return 'denied';
        if ($r->status >= 300 && $r->status < 400) return 'redirect';
        if (strpos($r->body, '<h2>Kein Zugriff</h2>') !== false) return 'denied';
        if (strpos($r->body, 'class="login-box maint-page"') !== false) return 'maintenance';
        $j = json_decode($r->body, true);
        if (is_array($j)) {
            $err = (string)($j['error'] ?? '');
            if ($err !== '' && preg_match('/Berechtigung|Nicht angemeldet|nur (für|von)? ?.*Administrator|zugänglich/u', $err)) return 'denied';
            return 'ok';
        }
        return $r->status === 200 ? 'ok' : 'http_' . $r->status;
    }
}
