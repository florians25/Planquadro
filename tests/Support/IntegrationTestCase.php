<?php
namespace Vireo\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        TestEnv::ensure();
    }

    protected function db(): \PDO { return TestEnv::pdo(); }

    protected function uid(string $key): int { return TestEnv::userId($key); }

    protected function as(string $user): Client { return Client::loggedIn($user); }

    protected function areaId(string $code = 'K1'): int
    {
        $s = $this->db()->prepare('SELECT id FROM plan_areas WHERE short_code=?');
        $s->execute([$code]);
        return (int)$s->fetchColumn();
    }

    /** Ein Werktag in der Zukunft ohne Feiertag (Mo–Fr). */
    protected function workday(int $offsetWeeks = 0, int $dow = 2): string
    {
        $d = new \DateTimeImmutable('monday next week');
        return $d->modify('+' . $offsetWeeks . ' week')->modify('+' . ($dow - 1) . ' day')->format('Y-m-d');
    }

    protected function draft(int $uid, string $date): ?array
    {
        $s = $this->db()->prepare('SELECT * FROM plan_drafts WHERE user_id=? AND plan_date=?');
        $s->execute([$uid, $date]);
        return $s->fetch() ?: null;
    }

    protected function clearPlan(): void
    {
        $this->db()->exec('DELETE FROM plan_drafts');
        $this->db()->exec('DELETE FROM assignments');
        $this->db()->exec('DELETE FROM absences');
        $this->db()->exec('DELETE FROM login_attempts');
    }
}
