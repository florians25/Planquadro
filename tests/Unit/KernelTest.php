<?php
namespace Planquadro\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/Kernel.php';

/** Request-Kernel: welche Meldungen dürfen an den Client (Befund S-20)? */
final class KernelTest extends TestCase
{
    public function testTechnicalErrorsBecomeGeneric(): void
    {
        $pdo = new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.users' doesn't exist");
        foreach ([$pdo, new \RuntimeException('/var/www/app/lib/x.php kaputt'), new \Error('Call to undefined function'), new \TypeError('x')] as $e) {
            $msg = opsPublicMessage($e);
            $this->assertStringStartsWith('Interner Fehler.', $msg);
            $this->assertStringContainsString(opsRequestId(), $msg);
            $this->assertStringNotContainsString('SQLSTATE', $msg);
            $this->assertFalse(opsIsUserFacing($e));
        }
    }

    public function testDomainMessagesPassThrough(): void
    {
        $this->assertSame('Name ist erforderlich.', opsPublicMessage(new \InvalidArgumentException('Name ist erforderlich.')));
        $this->assertSame('Zeitraum ungültig.', opsPublicMessage(new \OpsUserException('Zeitraum ungültig.')));
    }

    public function testRequestIdIsStablePerRequest(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{8,64}$/', opsRequestId());
        $this->assertSame(opsRequestId(), opsRequestId());
    }
}
