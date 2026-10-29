<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;
use Planquadro\Tests\Support\TestEnv;

/** Umsetzung der Produktentscheidungen E-1 … E-4 und weiterer Phase-2-Reste. */
final class DecisionsTest extends IntegrationTestCase
{
    public function testS18PlanAllOverrideDoesNotRevealHealthData(): void
    {
        $this->clearPlan();
        $db = $this->db();
        $ma = $this->uid('ma1'); $d = $this->workday();
        $db->prepare("INSERT INTO absences(user_id,absence_date,absence_type,is_published) VALUES(?,?,'krank_attest',1)")->execute([$ma, $d]);
        /* tl2 darf per Ausnahme alle planen, ist aber nicht Vorgesetzte von ma1 */
        $db->prepare("INSERT INTO permission_user_overrides(permission,user_id,granted) VALUES('view_all_employees',?,1)")->execute([$this->uid('tl2')]);
        try {
            $cells = $this->as('tl2')->postJson('/api/live_status', ['action' => 'cells', 'year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2)])->json()['cells'];
            $this->assertSame('A', $cells[$ma . '_' . $d]['label']);
            $this->assertSame(1, $cells[$ma . '_' . $d]['confidential'] ?? 0);
            /* tl (echte Vorgesetzte) sieht den Grund weiterhin */
            $cells = $this->as('tl')->postJson('/api/live_status', ['action' => 'cells', 'year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2)])->json()['cells'];
            $this->assertSame('krank_attest', $cells[$ma . '_' . $d]['value']);
            /* ausdrückliches Recht öffnet die Details */
            $db->prepare("INSERT INTO permission_user_overrides(permission,user_id,granted) VALUES('view_absence_details_all',?,1)")->execute([$this->uid('tl2')]);
            $cells = $this->as('tl2')->postJson('/api/live_status', ['action' => 'cells', 'year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2)])->json()['cells'];
            $this->assertSame('krank_attest', $cells[$ma . '_' . $d]['value']);
        } finally {
            $db->exec('DELETE FROM permission_user_overrides');
            $this->clearPlan();
        }
    }

    public function testSessionTimeoutIsEnforced(): void
    {
        $db = $this->db();
        $db->exec("UPDATE app_settings SET setting_value='1' WHERE setting_key='session_timeout_minutes'");
        try {
            $c = $this->as('lager');
            $this->assertSame(200, $c->get('/dashboard')->status);
            /* Letzte Aktivität künstlich 2 Minuten zurückdatieren */
            $sid = null;
            foreach (file((new \ReflectionProperty($c, 'jar'))->getValue($c)) as $line) {
                if (preg_match('/PHPSESSID\s+(\S+)/', $line, $m)) $sid = $m[1];
            }
            $this->assertNotNull($sid);
            $file = session_save_path() ?: sys_get_temp_dir();
            $path = rtrim($file, '/') . '/sess_' . $sid;
            if (!is_file($path)) $this->markTestSkipped('Session-Datei nicht zugreifbar');
            $data = file_get_contents($path);
            $data = preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . (time() - 120) . ';', $data);
            $data = preg_replace('/timeout_checked\|i:\d+;/', 'timeout_checked|i:0;', $data);
            file_put_contents($path, $data);
            $this->assertSame(302, $c->get('/dashboard')->status, 'nach Ablauf zurück zum Login');
        } finally {
            $db->exec("UPDATE app_settings SET setting_value='480' WHERE setting_key='session_timeout_minutes'");
        }
    }

    public function testRemarkWithStaleRevisionIsRejected(): void
    {
        $this->clearPlan();
        $ma = $this->uid('ma1'); $d = $this->workday();
        $this->db()->prepare('INSERT INTO assignments(user_id,plan_date,plan_area_id,year,month,is_published) VALUES(?,?,?,?,?,1)')
            ->execute([$ma, $d, $this->areaId(), (int)substr($d, 0, 4), (int)substr($d, 5, 2)]);
        $tl = $this->as('tl'); $mgr = $this->as('mgr');
        $r = $mgr->postJson('/api/save_bulk', ['changes' => [['user_id' => $ma, 'date' => $d, 'type' => 'clear', 'value' => '']]]);
        $this->assertSame(200, $r->status);
        $r = $tl->postJson('/api/save_remark', ['user_id' => $ma, 'date' => $d, 'remarks' => 'veraltet', 'rev' => 'deadbeefdeadbeef']);
        $this->assertSame(409, $r->status);
        $this->assertNull($this->draft($ma, $d)['remarks']);
        $this->clearPlan();
    }
}
