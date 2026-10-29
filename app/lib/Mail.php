<?php
/**
 * Planquadro – Mailversand (Alarme)
 * --------------------------------------------------------------------------
 * Schlanker SMTP-Client ohne Abhängigkeiten (fsockopen + OpenSSL von PHP).
 * Gewählt statt eines sendmail-Ersatzes (msmtp) im Image: funktioniert
 * identisch im Container, auf eigenen Servern und auf Shared Hosting, braucht
 * keine Konfigurationsdatei mit Passwort auf der Platte, meldet Fehler mit
 * SMTP-Antwort ins Log und ist ohne Image-Build testbar.
 *
 *   PQ_SMTP_HOST          Server; leer = PHP mail() (nur mit funktionierendem
 *                         sendmail), sonst wird Mail übersprungen (Warnung im Log)
 *   PQ_SMTP_PORT          Standard je nach PQ_SMTP_TLS: 587 / 465 / 25
 *   PQ_SMTP_TLS           starttls (Standard, Pflicht – kein Rückfall auf
 *                         Klartext), tls (implizit, Port 465), none
 *   PQ_SMTP_USER          optional; PQ_SMTP_PASS bzw. PQ_SMTP_PASS_FILE
 *   PQ_SMTP_FROM          Absender (Standard PQ_ALERT_FROM, sonst planquadro@<host>)
 *   PQ_SMTP_HELO          Name im EHLO (Standard: Hostname)
 *   PQ_SMTP_TIMEOUT       Sekunden (Standard 15)
 *   PQ_SMTP_VERIFY_PEER   1 (Standard) = Zertifikat und Hostnamen prüfen
 *   PQ_SMTP_CA_FILE       optional eigene CA (PEM)
 */

final class MailException extends RuntimeException {}

/** Absender für Alarme. */
function opsMailFrom(): string
{
    $f = opsEnv('PQ_SMTP_FROM', opsEnv('PQ_ALERT_FROM'));
    return $f !== '' ? $f : 'planquadro@' . (gethostname() ?: 'localhost');
}

/** Gültige Empfänger aus einer komma-/semikolongetrennten Liste. @return string[] */
function opsMailRecipients(string $list): array
{
    $out = [];
    foreach (preg_split('/[,;\s]+/', $list) ?: [] as $a) {
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) $out[] = $a;
    }
    return array_values(array_unique($out));
}

/** Ist mail() benutzbar (sendmail_path zeigt auf ein ausführbares Programm)? */
function opsSendmailAvailable(): bool
{
    $p = trim((string)ini_get('sendmail_path'));
    if ($p === '') return false;
    $bin = strtok($p, " \t");
    return $bin !== false && is_file($bin) && is_executable($bin);
}

/** Vollständige Nachricht (Kopf + Text, CRLF, Base64-kodierter UTF-8-Text). */
function opsMailMessage(string $from, array $to, string $subject, string $body): string
{
    $clean = fn(string $s) => trim(str_replace(["\r", "\n"], ' ', $s));
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $h = [
        'Date: ' . date('r'),
        'From: ' . $clean($from),
        'To: ' . implode(', ', array_map($clean, $to)),
        'Subject: =?UTF-8?B?' . base64_encode($clean($subject)) . '?=',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $clean($domain) . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
        'X-Mailer: Planquadro',
    ];
    return implode("\r\n", $h) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
}

/**
 * Sendet eine Text-Mail. Liefert ['ok' => bool, 'method' => smtp|mail|none, 'error' => ?string].
 * Wirft nie; Fehler stehen in 'error' (ohne Passwort).
 */
function opsSendMail(string $toList, string $subject, string $body, ?string $from = null): array
{
    $to = opsMailRecipients($toList);
    $from = $from ?? opsMailFrom();
    if (!$to) return ['ok' => false, 'method' => 'none', 'error' => 'keine gültige Empfängeradresse'];
    $smtp = opsEnv('PQ_SMTP_HOST') !== '';
    if (!$smtp && !opsSendmailAvailable()) {
        return ['ok' => false, 'method' => 'none', 'error' => 'weder PQ_SMTP_HOST gesetzt noch sendmail vorhanden – Mail übersprungen (SMTP konfigurieren, docs/CONFIGURATION.md)'];
    }
    /* bewusst locker (Hostname ohne Domain wie planquadro@app ist erlaubt), aber ohne Steuerzeichen */
    if (!preg_match('/^[^@\s<>"]+@[A-Za-z0-9.\-]+$/', $from)) return ['ok' => false, 'method' => 'none', 'error' => 'ungültiger Absender (PQ_SMTP_FROM/PQ_ALERT_FROM)'];
    if ($smtp) {
        try {
            (new PqSmtp())->send($from, $to, opsMailMessage($from, $to, $subject, $body));
            return ['ok' => true, 'method' => 'smtp', 'error' => null];
        } catch (MailException $e) {
            return ['ok' => false, 'method' => 'smtp', 'error' => $e->getMessage()];
        }
    }
    $msg = opsMailMessage($from, $to, $subject, $body);
    [$head, $text] = explode("\r\n\r\n", $msg, 2);
    $head = preg_replace('/^(To|Subject):.*\r\n/m', '', $head . "\r\n");
    $ok = @mail(implode(', ', $to), '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $subject)) . '?=', $text, rtrim($head));
    return ['ok' => $ok, 'method' => 'mail', 'error' => $ok ? null : 'mail() fehlgeschlagen'];
}

final class PqSmtp
{
    private $fp = null;
    private string $host;
    private int $port;
    private string $tls;
    private int $timeout;

    public function __construct()
    {
        $this->host = opsEnv('PQ_SMTP_HOST');
        $tls = strtolower(opsEnv('PQ_SMTP_TLS', 'starttls'));
        $this->tls = in_array($tls, ['starttls', 'tls', 'none'], true) ? $tls : 'starttls';
        $this->port = (int)opsEnv('PQ_SMTP_PORT', $this->tls === 'tls' ? '465' : ($this->tls === 'none' ? '25' : '587'));
        $this->timeout = max(1, (int)opsEnv('PQ_SMTP_TIMEOUT', '15'));
    }

    private function sslOptions(): array
    {
        $verify = opsEnv('PQ_SMTP_VERIFY_PEER', '1') !== '0';
        $o = ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'peer_name' => $this->host, 'SNI_enabled' => true];
        if (($ca = opsEnv('PQ_SMTP_CA_FILE')) !== '') $o['cafile'] = $ca;
        return $o;
    }

    /** @param string[] $to */
    public function send(string $from, array $to, string $message): void
    {
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $this->host)) throw new MailException('PQ_SMTP_HOST ungültig');
        $ctx = stream_context_create(['ssl' => $this->sslOptions()]);
        $uri = ($this->tls === 'tls' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $fp = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new MailException("SMTP-Verbindung zu {$this->host}:{$this->port} fehlgeschlagen: $errstr");
        $this->fp = $fp;
        stream_set_timeout($fp, $this->timeout);
        try {
            $this->expect(220);
            $helo = preg_replace('/[^A-Za-z0-9.\-]/', '', opsEnv('PQ_SMTP_HELO', gethostname() ?: 'localhost')) ?: 'localhost';
            $caps = $this->ehlo($helo);
            if ($this->tls === 'starttls') {
                if (!isset($caps['STARTTLS'])) throw new MailException('Server bietet kein STARTTLS an (PQ_SMTP_TLS=starttls verlangt Verschlüsselung)');
                $this->cmd('STARTTLS', 220);
                foreach ($this->sslOptions() as $k => $v) stream_context_set_option($fp, 'ssl', $k, $v);
                $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if ($ok !== true) throw new MailException('STARTTLS-Handshake fehlgeschlagen (Zertifikat?)');
                $caps = $this->ehlo($helo);
            }
            $user = opsEnv('PQ_SMTP_USER');
            if ($user !== '') {
                $pass = opsEnv('PQ_SMTP_PASS');
                $auth = strtoupper($caps['AUTH'] ?? '');
                if (preg_match('/\bPLAIN\b/', $auth)) {
                    $this->cmd('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), 235, 'AUTH PLAIN');
                } elseif (preg_match('/\bLOGIN\b/', $auth)) {
                    $this->cmd('AUTH LOGIN', 334);
                    $this->cmd(base64_encode($user), 334, 'AUTH LOGIN (Benutzer)');
                    $this->cmd(base64_encode($pass), 235, 'AUTH LOGIN (Passwort)');
                } else {
                    throw new MailException('Server bietet weder AUTH PLAIN noch AUTH LOGIN an');
                }
            }
            $this->cmd('MAIL FROM:<' . $from . '>', 250);
            foreach ($to as $rcpt) $this->cmd('RCPT TO:<' . $rcpt . '>', [250, 251]);
            $this->cmd('DATA', 354);
            /* Dot-Stuffing; der Text ist Base64, Kopfzeilen beginnen nie mit "." – trotzdem korrekt */
            $data = preg_replace('/^\./m', '..', $message);
            $this->write(rtrim($data, "\r\n") . "\r\n.\r\n");
            $this->expect(250);
            try { $this->cmd('QUIT', 221); } catch (MailException $e) {}
        } finally {
            fclose($fp);
            $this->fp = null;
        }
    }

    /** @return array<string,string> Fähigkeiten aus der EHLO-Antwort */
    private function ehlo(string $helo): array
    {
        $lines = $this->cmd('EHLO ' . $helo, 250);
        $caps = [];
        foreach (array_slice($lines, 1) as $l) {
            $parts = explode(' ', strtoupper(trim($l)), 2);
            $caps[$parts[0]] = $parts[1] ?? '';
        }
        return $caps;
    }

    private function write(string $s): void
    {
        if (@fwrite($this->fp, $s) === false) throw new MailException('SMTP: Schreiben fehlgeschlagen');
    }

    /** @param int|int[] $code */
    private function cmd(string $line, int|array $code, ?string $label = null): array
    {
        $this->write($line . "\r\n");
        return $this->expect($code, $label ?? strtok($line, ' '));
    }

    /** Liest eine (ggf. mehrzeilige) Antwort; @return string[] Textzeilen */
    private function expect(int|array $code, string $after = 'Verbindung'): array
    {
        $codes = (array)$code;
        $lines = [];
        while (true) {
            $l = fgets($this->fp, 2048);
            if ($l === false) {
                $meta = stream_get_meta_data($this->fp);
                throw new MailException('SMTP: keine Antwort nach ' . $after . (!empty($meta['timed_out']) ? ' (Zeitüberschreitung)' : ''));
            }
            $lines[] = substr(rtrim($l, "\r\n"), 4);
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        $got = (int)substr($l, 0, 3);
        if (!in_array($got, $codes, true)) {
            throw new MailException("SMTP: $after abgelehnt: " . mb_substr(rtrim($l), 0, 200));
        }
        return $lines;
    }
}
