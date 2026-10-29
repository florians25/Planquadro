<?php
namespace Vireo\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/Oidc.php';

/** SSRF-Schutz für Identity-Provider-Adressen (SaaS-Modus) */
final class OidcSsrfTest extends TestCase
{
    public function testInternalAddressesAreRejected(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.5', '192.168.1.1', '169.254.169.254', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', '[::1]', 'localhost', ''] as $h) {
            $this->assertNull(oidcPublicIp($h), $h);
        }
    }

    public function testPublicAddressesPass(): void
    {
        $this->assertSame('8.8.8.8', oidcPublicIp('8.8.8.8'));
        $this->assertSame('2001:4860:4860::8888', oidcPublicIp('2001:4860:4860::8888'));
    }
}
