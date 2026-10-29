<?php
/**
 * OpsPilot – Token-Authentifizierung für die API v1
 * --------------------------------------------------------------------------
 * Access-Token: zustandslos, HMAC-SHA256 mit dem App-Schlüssel, 15 Minuten,
 *   Inhalt {sub, tid (Mandant), fam (Token-Familie), exp}. Bei jeder Anfrage
 *   wird zusätzlich geprüft, dass der Benutzer aktiv und die Familie nicht
 *   widerrufen ist – Abmelden wirkt damit sofort.
 * Refresh-Token: 256 Bit Zufall, nur als SHA-256 gespeichert, 30 Tage,
 *   rotierend. Wird ein bereits verwendetes Refresh-Token erneut vorgelegt
 *   (Diebstahl-Indiz), wird die gesamte Familie gesperrt.
 */

const API_ACCESS_TTL = 900;
const API_REFRESH_TTL = 2592000;

function apiB64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function apiB64uDec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function apiSign(string $data): string
{
    return apiB64u(hash_hmac('sha256', 'api-access|' . $data, opsAppKey(getDB()), true));
}

function apiIssueAccess(int $uid, string $family): array
{
    $payload = apiB64u(json_encode(['sub' => $uid, 'tid' => opsCurrentTenant()['slug'], 'fam' => $family, 'exp' => time() + API_ACCESS_TTL]));
    return ['token' => 'opa.' . $payload . '.' . apiSign($payload), 'expires_in' => API_ACCESS_TTL];
}

/**
 * Prüft ein Access-Token.
 * @return array{uid:int, fam:string}|string  Fehlercode bei ungültigem Token
 */
function apiVerifyAccess(string $token)
{
    $p = explode('.', $token);
    if (count($p) !== 3 || $p[0] !== 'opa' || !hash_equals(apiSign($p[1]), $p[2])) return 'invalid_token';
    $c = json_decode(apiB64uDec($p[1]), true);
    if (!is_array($c) || !isset($c['sub'], $c['tid'], $c['fam'], $c['exp'])) return 'invalid_token';
    if ($c['tid'] !== opsCurrentTenant()['slug']) return 'invalid_token';
    if ((int)$c['exp'] < time()) return 'token_expired';
    $s = getDB()->prepare('SELECT u.active, (SELECT COUNT(*) FROM api_refresh_tokens t WHERE t.family_id=? AND t.revoked_at IS NULL) fam
                             FROM users u WHERE u.id=?');
    $s->execute([$c['fam'], (int)$c['sub']]);
    $r = $s->fetch();
    if (!$r || !(int)$r['active'] || (int)$r['fam'] === 0) return 'invalid_token';
    return ['uid' => (int)$c['sub'], 'fam' => (string)$c['fam']];
}

/** Neues Token-Paar (neue Familie oder Rotation innerhalb einer Familie) */
function apiIssuePair(int $uid, ?string $family = null, ?string $device = null, string $method = 'password', ?string $expiresAt = null): array
{
    $db = getDB();
    $family = $family ?? bin2hex(random_bytes(16));
    $refresh = 'opr.' . apiB64u(random_bytes(32));
    /* Rotation übernimmt das ursprüngliche Ablaufdatum der Familie (absolute Laufzeit) */
    $expiresAt = $expiresAt ?? date('Y-m-d H:i:s', time() + API_REFRESH_TTL);
    $db->prepare('INSERT INTO api_refresh_tokens(user_id, family_id, token_hash, device_name, auth_method, expires_at) VALUES(?,?,?,?,?,?)')
       ->execute([$uid, $family, hash('sha256', $refresh), $device !== null ? mb_substr($device, 0, 100) : null, $method, $expiresAt]);
    $acc = apiIssueAccess($uid, $family);
    return ['access_token' => $acc['token'], 'token_type' => 'Bearer', 'expires_in' => $acc['expires_in'],
            'refresh_token' => $refresh, 'refresh_expires_in' => max(0, strtotime($expiresAt) - time())];
}

/**
 * Refresh mit Rotation und Wiederverwendungserkennung.
 * @return array|string  Token-Paar + user_id oder Fehlercode
 */
function apiRefresh(string $refresh)
{
    $db = getDB();
    return opsTransaction($db, function (PDO $db) use ($refresh) {
        $s = $db->prepare('SELECT * FROM api_refresh_tokens WHERE token_hash=? FOR UPDATE');
        $s->execute([hash('sha256', $refresh)]);
        $t = $s->fetch();
        if (!$t) return 'invalid_token';
        if ($t['used_at'] !== null || $t['revoked_at'] !== null) {
            /* Wiederverwendung → ganze Familie sperren */
            $db->prepare('UPDATE api_refresh_tokens SET revoked_at=NOW() WHERE family_id=? AND revoked_at IS NULL')->execute([$t['family_id']]);
            return 'invalid_token';
        }
        if (strtotime($t['expires_at']) < time()) return 'token_expired';
        $u = $db->prepare('SELECT active, role, must_change_password FROM users WHERE id=?');
        $u->execute([$t['user_id']]);
        $user = $u->fetch();
        if (!$user || !(int)$user['active']) return 'invalid_token';
        /* Anmelderegeln gelten auch für bestehende App-Sitzungen */
        $pwOnlySso = $t['auth_method'] === 'password' && function_exists('oidcPasswordLoginAllowed') && !oidcPasswordLoginAllowed() && $user['role'] !== 'admin';
        if ((int)$user['must_change_password'] === 1 || $pwOnlySso) {
            $db->prepare('UPDATE api_refresh_tokens SET revoked_at=NOW() WHERE family_id=? AND revoked_at IS NULL')->execute([$t['family_id']]);
            return (int)$user['must_change_password'] === 1 ? 'password_change_required' : 'invalid_token';
        }
        $db->prepare('UPDATE api_refresh_tokens SET used_at=NOW() WHERE id=?')->execute([$t['id']]);
        return apiIssuePair((int)$t['user_id'], $t['family_id'], $t['device_name'], $t['auth_method'], $t['expires_at']) + ['user_id' => (int)$t['user_id']];
    });
}

function apiRevokeFamily(string $family): void
{
    getDB()->prepare('UPDATE api_refresh_tokens SET revoked_at=NOW() WHERE family_id=? AND revoked_at IS NULL')->execute([$family]);
}
