<?php
/**
 * Minimaler SMTP-Server für Tests (ohne TLS).
 *   php fake_smtp.php <port> <ausgabedatei> [--starttls] [--auth=PLAIN|LOGIN] [--reject-auth]
 * Schreibt je empfangener Nachricht eine JSON-Zeile (from, rcpt, auth, data)
 * in die Ausgabedatei. --starttls bietet STARTTLS nur an (Handshake wird
 * nicht unterstützt) – genügt, um "kein Rückfall auf Klartext" zu prüfen.
 */
[$port, $out] = [(int)$argv[1], $argv[2]];
$opts = array_slice($argv, 3);
$authMech = 'PLAIN LOGIN';
foreach ($opts as $o) if (strncmp($o, '--auth=', 7) === 0) $authMech = substr($o, 7);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) { fwrite(STDERR, "$es\n"); exit(1); }
while ($c = @stream_socket_accept($srv, -1)) {
    $w = fn(string $s) => fwrite($c, $s . "\r\n");
    $w('220 fake.smtp.test ESMTP');
    $msg = ['from' => null, 'rcpt' => [], 'auth' => null, 'data' => ''];
    while (($l = fgets($c)) !== false) {
        $l = rtrim($l, "\r\n");
        $u = strtoupper($l);
        if (strncmp($u, 'EHLO', 4) === 0) {
            fwrite($c, "250-fake.smtp.test\r\n" . (in_array('--starttls', $opts, true) ? "250-STARTTLS\r\n" : '') . "250-AUTH $authMech\r\n250 8BITMIME\r\n");
        } elseif (strncmp($u, 'AUTH PLAIN ', 11) === 0) {
            $msg['auth'] = 'PLAIN:' . base64_decode(substr($l, 11));
            $w(in_array('--reject-auth', $opts, true) ? '535 5.7.8 Authentication failed' : '235 2.7.0 OK');
        } elseif ($u === 'AUTH LOGIN') {
            $w('334 VXNlcm5hbWU6'); $user = base64_decode(rtrim((string)fgets($c)));
            $w('334 UGFzc3dvcmQ6'); $pass = base64_decode(rtrim((string)fgets($c)));
            $msg['auth'] = "LOGIN:$user:$pass";
            $w(in_array('--reject-auth', $opts, true) ? '535 5.7.8 Authentication failed' : '235 2.7.0 OK');
        } elseif ($u === 'STARTTLS') {
            $w('454 4.7.0 TLS not available');
        } elseif (strncmp($u, 'MAIL FROM:', 10) === 0) {
            $msg['from'] = trim(substr($l, 10), '<> '); $w('250 OK');
        } elseif (strncmp($u, 'RCPT TO:', 8) === 0) {
            $msg['rcpt'][] = trim(substr($l, 8), '<> '); $w('250 OK');
        } elseif ($u === 'DATA') {
            $w('354 End data with <CR><LF>.<CR><LF>');
            while (($d = fgets($c)) !== false && rtrim($d, "\r\n") !== '.') $msg['data'] .= $d;
            file_put_contents($out, json_encode($msg) . "\n", FILE_APPEND);
            $w('250 OK queued');
        } elseif ($u === 'QUIT') {
            $w('221 Bye'); break;
        } else {
            $w('500 unknown');
        }
    }
    fclose($c);
}
