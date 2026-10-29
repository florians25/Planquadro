<?php
namespace OpsPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/Push.php';

final class PushCryptoTest extends TestCase
{
    public function testEs256JwtHasRawSignatureThatVerifies(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $jwt = pushJwt(['alg' => 'ES256', 'kid' => 'ABC'], ['iss' => 'TEAM', 'iat' => 1], $pem, 'ES256');
        [$h, $c, $s] = explode('.', $jwt);
        $raw = base64_decode(strtr($s, '-_', '+/'));
        $this->assertSame(64, strlen($raw), 'JOSE-Signatur r||s');
        /* zurück nach DER und mit öffentlichem Schlüssel prüfen */
        $enc = function ($i) { $i = ltrim($i, "\0"); if (ord($i[0]) > 0x7f) $i = "\0" . $i; return "\x02" . chr(strlen($i)) . $i; };
        $seq = $enc(substr($raw, 0, 32)) . $enc(substr($raw, 32));
        $der = "\x30" . chr(strlen($seq)) . $seq;
        $pub = openssl_pkey_get_details($key)['key'];
        $this->assertSame(1, openssl_verify("$h.$c", $der, $pub, OPENSSL_ALGO_SHA256));
    }
}
