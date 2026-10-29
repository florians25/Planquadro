<?php
namespace OpsPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/Backup.php';

/**
 * Archiv-Verschlüsselung (B-03): Round-Trip, Manipulation, Abschneiden,
 * falscher Schlüssel – alles muss erkannt werden.
 */
final class BackupCryptoTest extends TestCase
{
    private function encrypt(string $plain, string $pk): string
    {
        $fh = fopen('php://memory', 'w+b');
        $w = new \BackupEncWriter($fh, $pk);
        /* in ungleichmäßigen Stücken schreiben, um die Blockbildung zu prüfen */
        for ($i = 0; $i < strlen($plain); $i += 77777) $w->write(substr($plain, $i, 77777));
        $sha = $w->finish();
        rewind($fh);
        $data = stream_get_contents($fh);
        $this->assertSame($sha, hash('sha256', $data));
        $this->assertSame(strlen($data), $w->bytes);
        return $data;
    }

    private function decrypt(string $data, string $kp): string
    {
        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, $data);
        rewind($fh);
        $r = new \BackupDecReader($fh, $kp);
        $out = '';
        while (($c = $r->read()) !== null) $out .= $c;
        return $out;
    }

    private function keys(): array
    {
        $k = \BackupCrypto::keygen();
        return [\BackupCrypto::parsePublic($k['public']), \BackupCrypto::parseSecret($k['secret']), $k];
    }

    public function testRoundTripAcrossChunkBoundaries(): void
    {
        [$pk, $kp] = $this->keys();
        foreach (['', 'kurz', random_bytes(\BackupCrypto::CHUNK), random_bytes(2 * \BackupCrypto::CHUNK + 12345)] as $plain) {
            $enc = $this->encrypt($plain, $pk);
            $this->assertStringStartsWith(\BackupCrypto::MAGIC, $enc);
            if ($plain !== '') $this->assertStringNotContainsString(substr($plain, 0, 32), $enc);
            $this->assertSame($plain, $this->decrypt($enc, $kp));
        }
    }

    public function testKeyFormatAndFingerprint(): void
    {
        [$pk, $kp, $k] = $this->keys();
        $this->assertStringStartsWith('opspilot-backup-pk-v1:', $k['public']);
        $this->assertStringStartsWith('opspilot-backup-sk-v1:', $k['secret']);
        $this->assertSame($pk, \BackupCrypto::publicFromSecret($kp));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', \BackupCrypto::fingerprint($pk));
        $this->expectException(\BackupException::class);
        \BackupCrypto::parsePublic($k['secret']);
    }

    public function testEveryByteFlipIsDetected(): void
    {
        [$pk, $kp] = $this->keys();
        $enc = $this->encrypt(random_bytes(3000), $pk);
        /* Kopf, Sealed Key, Stream-Header, Blocklänge, Chiffrat, MAC */
        foreach ([0, 9, 20, 100, 118, 121, 130, 500, strlen($enc) - 1] as $pos) {
            $t = $enc; $t[$pos] = chr(ord($t[$pos]) ^ 0x01);
            try { $this->decrypt($t, $kp); $this->fail("Manipulation an Position $pos nicht erkannt"); }
            catch (\BackupException $e) { $this->addToAssertionCount(1); }
        }
    }

    public function testTruncationAndAppendedDataAreDetected(): void
    {
        [$pk, $kp] = $this->keys();
        $enc = $this->encrypt(random_bytes(\BackupCrypto::CHUNK + 10), $pk);
        foreach ([50, \BackupCrypto::HEADER_LEN, \BackupCrypto::HEADER_LEN + 4 + \BackupCrypto::CHUNK + 17, strlen($enc) - 1] as $len) {
            try { $this->decrypt(substr($enc, 0, $len), $kp); $this->fail("Abschneiden auf $len Byte nicht erkannt"); }
            catch (\BackupException $e) { $this->assertMatchesRegularExpression('/abgeschnitten|beschädigt/', $e->getMessage()); }
        }
        try { $this->decrypt($enc . 'x', $kp); $this->fail('Angehängte Daten nicht erkannt'); }
        catch (\BackupException $e) { $this->assertStringContainsString('nach der Endmarkierung', $e->getMessage()); }
    }

    public function testWrongKeyIsRejectedWithFingerprintHint(): void
    {
        [$pk] = $this->keys();
        [, $otherKp] = $this->keys();
        $enc = $this->encrypt('geheim', $pk);
        $this->expectException(\BackupException::class);
        $this->expectExceptionMessage('anderen Schlüssel');
        $this->decrypt($enc, $otherKp);
    }

    public function testLegacyEpbakCanBeDecrypted(): void
    {
        $key = bin2hex(random_bytes(32));
        $sql = "CREATE TABLE `x` (id int);\nINSERT INTO `x` VALUES (1);\n";
        $iv = random_bytes(16);
        $dir = sys_get_temp_dir() . '/oplegacy' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents("$dir/backup_manual_2025.epbak", base64_encode($iv . openssl_encrypt($sql, 'aes-256-cbc', hex2bin($key), OPENSSL_RAW_DATA, $iv)));
        file_put_contents("$dir/.backup_key", $key);
        $this->assertSame($sql, \Backup::legacyDecrypt("$dir/backup_manual_2025.epbak", "$dir/.backup_key"));
        file_put_contents("$dir/.backup_key", bin2hex(random_bytes(32)));
        try { \Backup::legacyDecrypt("$dir/backup_manual_2025.epbak", "$dir/.backup_key"); $this->fail('falscher Schlüssel'); }
        catch (\BackupException $e) { $this->addToAssertionCount(1); }
        \Backup::rmTree($dir);
    }
}
