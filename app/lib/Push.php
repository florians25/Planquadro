<?php
/**
 * OpsPilot – Benachrichtigungen bei Planänderungen und Push-Versand
 * --------------------------------------------------------------------------
 * planNotifyChanges() wird nach einer Veröffentlichung aufgerufen: Jeder
 * betroffene Mitarbeiter erhält eine In-App-Benachrichtigung (Glocke im Web,
 * Liste in der App) und – falls ein Gerät registriert ist – einen Eintrag in
 * der Push-Warteschlange. Versendet wird asynchron durch bin/push-worker.php
 * (FCM HTTP v1, APNs, ntfy). Nachrichten enthalten KEINE Abwesenheitsgründe.
 */

/**
 * @param array<int, string[]> $userDates  [user_id => [Y-m-d, …]] mit tatsächlich geänderten Tagen
 */
function planNotifyChanges(PDO $db, array $userDates, ?int $exceptUser = null): void
{
    $insN = $db->prepare('INSERT INTO notifications(user_id, title, message, link) VALUES(?,?,?,?)');
    $hasDev = $db->prepare('SELECT COUNT(*) FROM push_devices WHERE user_id=?');
    $insQ = $db->prepare('INSERT INTO push_queue(user_id, title, body, data) VALUES(?,?,?,?)');
    foreach ($userDates as $uid => $dates) {
        $dates = array_values(array_unique($dates));
        if (!$dates || (int)$uid === (int)$exceptUser) continue;
        sort($dates);
        $fmt = array_map(function ($d) { return date('d.m.', strtotime($d)); }, array_slice($dates, 0, 5));
        $body = count($dates) === 1
            ? 'Dein Plan für den ' . $fmt[0] . ' wurde geändert.'
            : 'Dein Plan wurde an ' . count($dates) . ' Tagen geändert (' . implode(', ', $fmt) . (count($dates) > 5 ? ' …' : '') . ').';
        $insN->execute([(int)$uid, 'Plan geändert', $body, 'mein_plan']);
        $hasDev->execute([(int)$uid]);
        if ((int)$hasDev->fetchColumn() > 0) {
            $insQ->execute([(int)$uid, 'Plan geändert', $body, json_encode(['type' => 'plan_changed', 'dates' => implode(',', array_slice($dates, 0, 31))])]);
        }
    }
}

/* ======================= Versand (Worker) ======================= */

final class PushResult
{
    public function __construct(public bool $ok, public bool $invalidToken = false, public string $error = '') {}
}

function pushHttp(string $url, string $body, array $headers, bool $http2 = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5]);
    if ($http2 && defined('CURL_HTTP_VERSION_2_0')) curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $resp === false ? '' : (string)$resp, $err];
}

/** JWT signieren (RS256 für Google, ES256 für Apple) */
function pushJwt(array $header, array $claims, $privateKey, string $alg): string
{
    $b = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
    $input = $b(json_encode($header)) . '.' . $b(json_encode($claims));
    if (!openssl_sign($input, $sig, $privateKey, OPENSSL_ALGO_SHA256)) throw new RuntimeException('JWT-Signatur fehlgeschlagen');
    if ($alg === 'ES256') $sig = pushDerToRaw($sig);
    return $input . '.' . $b($sig);
}

/** ECDSA-DER-Signatur → JOSE r||s (je 32 Byte) */
function pushDerToRaw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

/** FCM HTTP v1 (Service-Account-JSON in OPSPILOT_FCM_SERVICE_ACCOUNT_FILE) */
function pushSendFcm(string $token, string $title, string $body, array $data): PushResult
{
    static $access = null; static $exp = 0; static $project = null;
    $file = opsEnv('OPSPILOT_FCM_SERVICE_ACCOUNT_FILE');
    if ($file === '' || !is_readable($file)) return new PushResult(false, false, 'FCM nicht konfiguriert');
    if (!$access || $exp < time() + 60) {
        $sa = json_decode((string)file_get_contents($file), true);
        $project = $sa['project_id'] ?? null;
        $jwt = pushJwt(['alg' => 'RS256', 'typ' => 'JWT'], ['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600], $sa['private_key'], 'RS256');
        [$code, $resp] = pushHttp('https://oauth2.googleapis.com/token', http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
            ['Content-Type: application/x-www-form-urlencoded']);
        $j = json_decode($resp, true);
        if ($code !== 200 || empty($j['access_token'])) return new PushResult(false, false, 'FCM-Anmeldung fehlgeschlagen (' . $code . ')');
        $access = $j['access_token']; $exp = time() + (int)($j['expires_in'] ?? 3600);
    }
    $msg = ['message' => ['token' => $token, 'notification' => ['title' => $title, 'body' => $body],
        'data' => array_map('strval', $data), 'android' => ['priority' => 'high']]];
    [$code, $resp] = pushHttp("https://fcm.googleapis.com/v1/projects/$project/messages:send", json_encode($msg),
        ['Authorization: Bearer ' . $access, 'Content-Type: application/json']);
    if ($code === 200) return new PushResult(true);
    $invalid = $code === 404 || strpos($resp, 'UNREGISTERED') !== false || strpos($resp, 'INVALID_ARGUMENT') !== false;
    return new PushResult(false, $invalid, 'FCM ' . $code);
}

/** APNs mit Token-Authentifizierung (.p8-Schlüssel) */
function pushSendApns(string $token, string $title, string $body, array $data): PushResult
{
    $keyFile = opsEnv('OPSPILOT_APNS_KEY_FILE');
    $keyId = opsEnv('OPSPILOT_APNS_KEY_ID'); $team = opsEnv('OPSPILOT_APNS_TEAM_ID');
    $topic = opsEnv('OPSPILOT_APNS_BUNDLE_ID', 'de.opspilot.app');
    if ($keyFile === '' || !is_readable($keyFile) || $keyId === '' || $team === '') return new PushResult(false, false, 'APNs nicht konfiguriert');
    if (!preg_match('/^[0-9a-fA-F]{32,200}$/', $token)) return new PushResult(false, true, 'ungültiges Geräte-Token');
    static $jwt = null; static $at = 0;
    if (!$jwt || $at < time() - 2400) {
        $jwt = pushJwt(['alg' => 'ES256', 'kid' => $keyId], ['iss' => $team, 'iat' => time()], file_get_contents($keyFile), 'ES256');
        $at = time();
    }
    $host = opsEnv('OPSPILOT_APNS_SANDBOX') === '1' ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
    $payload = ['aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default']] + $data;
    [$code, $resp] = pushHttp("https://$host/3/device/$token", json_encode($payload),
        ['authorization: bearer ' . $jwt, 'apns-topic: ' . $topic, 'apns-push-type: alert', 'content-type: application/json'], true);
    if ($code === 200) return new PushResult(true);
    return new PushResult(false, $code === 410 || strpos($resp, 'BadDeviceToken') !== false, 'APNs ' . $code);
}

/** ntfy (selbst gehostet, ohne Cloud): Token = Topic-Name */
function pushSendNtfy(string $topic, string $title, string $body, array $data): PushResult
{
    $base = rtrim(opsEnv('OPSPILOT_NTFY_URL'), '/');
    if ($base === '') return new PushResult(false, false, 'ntfy nicht konfiguriert');
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $topic)) return new PushResult(false, true, 'ungültiges Topic');
    $headers = ['Title: ' . str_replace(["\r", "\n"], '', $title), 'Tags: calendar'];
    if (($t = opsEnv('OPSPILOT_NTFY_TOKEN')) !== '') $headers[] = 'Authorization: Bearer ' . $t;
    [$code] = pushHttp("$base/$topic", $body, $headers);
    return $code >= 200 && $code < 300 ? new PushResult(true) : new PushResult(false, false, 'ntfy ' . $code);
}

/** Arbeitet die Warteschlange ab; liefert [gesendet, fehlgeschlagen] */
function pushProcessQueue(PDO $db, int $limit = 100): array
{
    $rows = $db->query('SELECT * FROM push_queue WHERE sent_at IS NULL AND attempts < 5 AND (next_try_at IS NULL OR next_try_at <= NOW()) ORDER BY id LIMIT ' . (int)$limit)->fetchAll();
    $devs = $db->prepare('SELECT * FROM push_devices WHERE user_id=?');
    $done = $db->prepare('UPDATE push_queue SET sent_at=NOW(), attempts=attempts+1, last_error=? WHERE id=?');
    $retry = $db->prepare('UPDATE push_queue SET attempts=attempts+1, last_error=?, next_try_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?');
    $drop = $db->prepare('DELETE FROM push_devices WHERE id=?');
    $sent = 0; $failed = 0;
    foreach ($rows as $q) {
        $devs->execute([$q['user_id']]);
        $data = json_decode((string)$q['data'], true) ?: [];
        $anyOk = false; $errors = [];
        foreach ($devs->fetchAll() as $d) {
            $r = match ($d['provider']) {
                'fcm' => pushSendFcm($d['token'], $q['title'], $q['body'], $data),
                'apns' => pushSendApns($d['token'], $q['title'], $q['body'], $data),
                'ntfy' => pushSendNtfy($d['token'], $q['title'], $q['body'], $data),
                default => new PushResult(false, true, 'unbekannter Anbieter'),
            };
            if ($r->ok) $anyOk = true;
            else { $errors[] = $r->error; if ($r->invalidToken) $drop->execute([$d['id']]); }
        }
        if ($anyOk || !$errors) { $done->execute([$errors ? mb_substr(implode('; ', $errors), 0, 300) : null, $q['id']]); $sent++; }
        else { $retry->execute([mb_substr(implode('; ', $errors), 0, 300), min(3600, 60 * (2 ** (int)$q['attempts'])), $q['id']]); $failed++; }
    }
    return [$sent, $failed];
}
