<?php
namespace Vireo\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/Backup.php';

/** Namen aus der Entwicklungsphase (Arbeitstitel OpsPilot) bleiben gültig. */
final class LegacyNamesTest extends TestCase
{
    private function runPhp(string $code, array $env): string
    {
        $p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        return $out;
    }

    public function testOldEnvironmentVariablesStillWork(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/app/lib/Env.php', true) . '; echo getenv("VIREO_MODE"), "|", getenv("VIREO_DB_NAME");';
        $this->assertSame('saas|neu', $this->runPhp($code, ['OPSPILOT_MODE' => 'saas', 'OPSPILOT_DB_NAME' => 'alt', 'VIREO_DB_NAME' => 'neu']),
            'alter Name greift nur, wenn der neue fehlt');
    }

    public function testOldBackupKeysAreAccepted(): void
    {
        $k = \BackupCrypto::keygen();
        $this->assertStringStartsWith('vireo-backup-pk-v1:', $k['public']);
        $oldPub = 'opspilot-backup-pk-v1:' . substr($k['public'], strlen('vireo-backup-pk-v1:'));
        $oldSec = 'opspilot-backup-sk-v1:' . substr($k['secret'], strlen('vireo-backup-sk-v1:'));
        $this->assertSame(\BackupCrypto::parsePublic($k['public']), \BackupCrypto::parsePublic($oldPub));
        $this->assertSame(\BackupCrypto::publicFromSecret(\BackupCrypto::parseSecret($oldSec)), \BackupCrypto::parsePublic($k['public']));
    }
}
