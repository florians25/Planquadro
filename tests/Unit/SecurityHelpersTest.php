<?php
namespace Planquadro\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/Security.php';

final class SecurityHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PQ_TRUSTED_PROXIES');
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testForwardedForIsIgnoredWithoutTrustedProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $this->assertSame('203.0.113.9', opsClientIp());
    }

    public function testForwardedForIsUsedBehindTrustedProxy(): void
    {
        putenv('PQ_TRUSTED_PROXIES=10.0.0.0/8,172.16.0.1');
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.7, 172.16.0.1';
        $this->assertSame('198.51.100.7', opsClientIp(), 'rechteste nicht vertrauenswürdige Adresse');
    }

    public function testIpRange(): void
    {
        $this->assertTrue(opsIpInRange('192.168.1.77', '192.168.1.0/24'));
        $this->assertFalse(opsIpInRange('192.168.2.1', '192.168.1.0/24'));
        $this->assertTrue(opsIpInRange('fd00::1', 'fd00::/8'));
        $this->assertFalse(opsIpInRange('10.0.0.1', 'fd00::/8'));
    }

    public function testValidators(): void
    {
        $this->assertSame('#aabbcc', opsValidColor('#aabbcc'));
        $this->assertNull(opsValidColor('red'));
        $this->assertNull(opsSafeUrl('javascript:alert(1)'));
        $this->assertSame('', opsSafeUrl(''));
        $this->assertSame('https://x.example/a', opsSafeUrl('https://x.example/a'));
        $this->assertSame('2026-02-28', opsValidDate('2026-02-28'));
        $this->assertNull(opsValidDate('2026-02-30'));
        $this->assertNull(opsValidDate('morgen'));
    }
}
