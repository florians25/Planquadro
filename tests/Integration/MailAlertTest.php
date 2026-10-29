<?php
namespace Planquadro\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Planquadro\Tests\Support\TestEnv;

/**
 * Mail-Alarme über den eingebauten SMTP-Client (app/lib/Mail.php) gegen einen
 * lokalen Test-SMTP-Server; ohne Konfiguration wird Mail mit Warnung
 * übersprungen. Braucht keine Datenbank.
 */
final class MailAlertTest extends TestCase
{
    private static string $dir = '';
    private array $procs = [];

    protected function setUp(): void
    {
        self::$dir = sys_get_temp_dir() . '/pq-mail-' . getmypid();
        @mkdir(self::$dir . '/storage', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) { proc_terminate($p); proc_close($p); }
        $this->procs = [];
        foreach (glob(self::$dir . '/*.jsonl') ?: [] as $f) @unlink($f);
    }

    /** @return array{int,string} Port und Ausgabedatei */
    private function smtp(array $opts = []): array
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $out = self::$dir . "/smtp-$port.jsonl";
        $this->procs[] = proc_open(array_merge([PHP_BINARY, TestEnv::$root . '/tests/fixtures/fake_smtp.php', (string)$port, $out], $opts),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && !($fp = @fsockopen('127.0.0.1', $port)); $i++) usleep(50000);
        if (!empty($fp)) fclose($fp);
        return [$port, $out];
    }

    private function alertTest(array $env): array
    {
        return TestEnv::runCli(['-d', 'sendmail_path=/nonexistent/sendmail -t', 'bin/backup.php', 'alert-test'],
            $env + ['PQ_STORAGE_DIR' => self::$dir . '/storage', 'PQ_ALERT_WEBHOOK' => '']);
    }

    private function messages(string $file): array
    {
        return array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents($file))));
    }

    public function testSmtpWithAuthPlain(): void
    {
        [$port, $out] = $this->smtp();
        file_put_contents(self::$dir . '/pass', "Geheim-1\n");
        [$rc, $log] = $this->alertTest(['PQ_ALERT_EMAIL' => 'ops@example.de, zweite@example.de, kaputt', 'PQ_SMTP_HOST' => '127.0.0.1',
            'PQ_SMTP_PORT' => (string)$port, 'PQ_SMTP_TLS' => 'none', 'PQ_SMTP_USER' => 'alarm', 'PQ_SMTP_PASS_FILE' => self::$dir . '/pass',
            'PQ_SMTP_FROM' => 'planquadro@example.de']);
        $this->assertSame(0, $rc, $log);
        $this->assertMatchesRegularExpression('/^mail\s+OK$/m', $log);
        $m = $this->messages($out);
        $this->assertCount(1, $m);
        $this->assertSame('planquadro@example.de', $m[0]['from']);
        $this->assertSame(['ops@example.de', 'zweite@example.de'], $m[0]['rcpt']);
        $this->assertSame("PLAIN:\0alarm\0Geheim-1", $m[0]['auth']);
        [$head, $body] = explode("\r\n\r\n", $m[0]['data'], 2);
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('[Planquadro] Testalarm') . '?=', $head);
        $this->assertStringContainsString('Auto-Submitted: auto-generated', $head);
        $this->assertStringContainsString('die Alarmierung funktioniert', base64_decode($body));
    }

    public function testSmtpWithAuthLoginAndRejectedAuth(): void
    {
        [$port, $out] = $this->smtp(['--auth=LOGIN']);
        $env = ['PQ_ALERT_EMAIL' => 'ops@example.de', 'PQ_SMTP_HOST' => '127.0.0.1', 'PQ_SMTP_PORT' => (string)$port, 'PQ_SMTP_TLS' => 'none',
            'PQ_SMTP_USER' => 'alarm', 'PQ_SMTP_PASS' => 'pw', 'PQ_SMTP_FROM' => 'planquadro@example.de'];
        [$rc, $log] = $this->alertTest($env);
        $this->assertSame(0, $rc, $log);
        $this->assertSame('LOGIN:alarm:pw', $this->messages($out)[0]['auth']);

        [$port] = $this->smtp(['--reject-auth']);
        [$rc, $log] = $this->alertTest(['PQ_SMTP_PORT' => (string)$port] + $env);
        $this->assertSame(1, $rc, $log);
        $this->assertStringContainsString('AUTH PLAIN abgelehnt', $log);
        $this->assertStringNotContainsString(base64_encode("\0alarm\0pw"), $log, 'kein Passwort (auch nicht kodiert) im Log');
    }

    public function testStarttlsIsRequiredByDefault(): void
    {
        [$port, $out] = $this->smtp();
        [$rc, $log] = $this->alertTest(['PQ_ALERT_EMAIL' => 'ops@example.de', 'PQ_SMTP_HOST' => '127.0.0.1', 'PQ_SMTP_PORT' => (string)$port,
            'PQ_SMTP_USER' => 'alarm', 'PQ_SMTP_PASS' => 'pw', 'PQ_SMTP_FROM' => 'planquadro@example.de']);
        $this->assertSame(1, $rc, $log);
        $this->assertStringContainsString('kein STARTTLS', $log);
        $this->assertSame([], $this->messages($out), 'kein Rückfall auf Klartext');

        [$port, $out] = $this->smtp(['--starttls']);
        [$rc, $log] = $this->alertTest(['PQ_ALERT_EMAIL' => 'ops@example.de', 'PQ_SMTP_HOST' => '127.0.0.1', 'PQ_SMTP_PORT' => (string)$port,
            'PQ_SMTP_FROM' => 'planquadro@example.de']);
        $this->assertSame(1, $rc, $log);
        $this->assertStringContainsString('STARTTLS abgelehnt', $log);
        $this->assertSame([], $this->messages($out));
    }

    public function testWithoutSmtpAndSendmailMailIsSkippedButWebhookStillWorks(): void
    {
        [$rc, $log] = $this->alertTest(['PQ_ALERT_EMAIL' => 'ops@example.de']);
        $this->assertSame(1, $rc, $log);
        $this->assertStringContainsString('Mail-Alarm übersprungen', $log);
        $this->assertStringContainsString('PQ_SMTP_HOST', $log);

        $hookDir = self::$dir . '/hook';
        @mkdir($hookDir);
        $hookLog = self::$dir . '/hook.jsonl';
        file_put_contents("$hookDir/index.php", '<?php file_put_contents(' . var_export($hookLog, true) . ', file_get_contents("php://input") . "\n", FILE_APPEND);');
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $this->procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $hookDir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && !($fp = @fsockopen('127.0.0.1', $port)); $i++) usleep(50000);
        [$rc, $log] = TestEnv::runCli(['-d', 'sendmail_path=/nonexistent/sendmail -t', 'bin/backup.php', 'alert-test'],
            ['PQ_STORAGE_DIR' => self::$dir . '/storage', 'PQ_ALERT_WEBHOOK' => "http://127.0.0.1:$port/", 'PQ_ALERT_EMAIL' => 'ops@example.de', 'no_proxy' => '*']);
        $this->assertSame(1, $rc, 'Mail fehlt weiterhin');
        $this->assertMatchesRegularExpression('/^webhook\s+OK$/m', $log);
        $this->assertSame('test', $this->messages($hookLog)[0]['event']);
    }

    public function testNoChannelConfigured(): void
    {
        [$rc, $log] = $this->alertTest(['PQ_ALERT_EMAIL' => '']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('Kein Alarmkanal', $log);
    }

    public function testHeaderInjectionIsNeutralised(): void
    {
        require_once TestEnv::$root . '/app/lib/Tenancy.php';
        require_once TestEnv::$root . '/app/lib/Mail.php';
        $msg = \opsMailMessage('a@example.de', ['b@example.de'], "Betreff\r\nBcc: boese@example.de", "Text\r\n.\r\n");
        $this->assertStringNotContainsString("\r\nBcc:", $msg);
        $this->assertSame(['x@example.de'], \opsMailRecipients("x@example.de\r\nBcc: y"));
    }
}
