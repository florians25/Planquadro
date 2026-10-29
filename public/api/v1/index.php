<?php
/**
 * OpsPilot API v1 – Front-Controller (Vertrag: docs/API.md)
 * Alle Anfragen unter /api/v1/* landen hier (.htaccess / tests/router.php).
 */
define('OPSPILOT_STATELESS', true);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../../app/lib/PlanCells.php';
require_once __DIR__ . '/../../../app/lib/ApiAuth.php';
require_once __DIR__ . '/../../../app/lib/Oidc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
/* Mobile Apps (Capacitor) und PWA senden kein Cookie; CORS nur für konfigurierte Ursprünge */
$allowedOrigins = array_filter(array_map('trim', explode(',', opsEnv('OPSPILOT_API_CORS_ORIGINS', 'capacitor://localhost,http://localhost,https://localhost'))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-OpsPilot-Tenant');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function apiOut(int $code, $data = null): void
{
    http_response_code($code);
    if ($data !== null) echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function apiError(int $code, string $err, string $msg, array $extra = []): void { apiOut($code, ['error' => ['code' => $err, 'message' => $msg] + $extra]); }
function apiBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $j = json_decode($raw, true);
    if (!is_array($j)) apiError(400, 'invalid_request', 'Ungültiges JSON.');
    return $j;
}
function apiDate($v): ?string { return opsValidDate(is_string($v) ? $v : ''); }
function apiIso(?string $ts): ?string { return $ts ? date('c', strtotime($ts)) : null; }
function apiLogo(): ?string { return tcfg('branding.logo') ? oidcBaseUrl() . '/api/branding?logo=1' : null; }

function apiTenantInfo(): array
{
    $t = opsCurrentTenant();
    return ['name' => (string)tcfg('branding.name', 'OpsPilot'), 'slug' => $t['slug'],
        'branding' => ['primary' => tcfg('branding.primary'), 'accent' => tcfg('branding.accent'), 'logo_url' => apiLogo()]];
}
function apiModules(): array
{
    return ['absences' => true, 'approvals' => opsModule('approvals'), 'assistant' => opsModule('assistant'), 'ical' => opsModule('ical')];
}

/** Bearer-Token prüfen und Benutzer setzen */
function apiAuth(): array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) apiError(401, 'invalid_token', 'Anmeldung erforderlich.');
    $r = apiVerifyAccess($m[1]);
    if (is_string($r)) apiError(401, $r, $r === 'token_expired' ? 'Sitzung abgelaufen.' : 'Ungültiges Token.');
    $_SESSION['user_id'] = $r['uid'];
    if (!opsModule('mobile')) apiError(403, 'module_disabled', 'Der App-Zugang ist für Ihre Organisation deaktiviert.');
    if (isMaintenanceMode() && !hasRole('admin', 'manager')) apiError(503, 'maintenance', (string)getSetting('maintenance_message', 'Wartungsarbeiten.'));
    return $r;
}

/** Antwort wie GET me (auch Bestandteil der Login-/Refresh-Antwort, siehe docs/API.md) */
function apiMeJson(): array
{
    $perms = [];
    foreach (['plan_view', 'plan_edit', 'approve_requests', 'view_all_employees', 'manage_absences'] as $p) $perms[$p] = hasPerm($p);
    return ['user' => apiUserJson(currentUser()), 'permissions' => $perms, 'modules' => apiModules(), 'tenant' => apiTenantInfo(), 'server_time' => date('c')];
}

function apiUserJson(array $u): array
{
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'first_name' => $u['first_name'], 'last_name' => $u['last_name'],
        'role' => $u['role'], 'role_label' => roleName($u['role'])];
}

/** Einheitliche Darstellung eines veröffentlichten Eintrags */
function apiEntry(?array $asg, ?array $abs, array $pam, int $uid, bool $withRemarks = true): ?array
{
    if ($asg && isset($pam[$asg['plan_area_id']])) {
        $p = $pam[$asg['plan_area_id']];
        return ['kind' => 'assignment', 'area_id' => (int)$p['id'], 'code' => $p['short_code'], 'label' => $p['name'],
            'color' => $p['color'], 'fg' => $p['fg_color'], 'remarks' => $withRemarks ? (($asg['remarks'] ?? '') ?: null) : null];
    }
    if ($abs) {
        $t = $abs['absence_type'];
        if (isConfidentialAbsence($t) && !canSeeAbsenceDetails($uid)) {
            return ['kind' => 'absence', 'code' => 'A', 'label' => 'Abwesend', 'color' => '#ef4444', 'fg' => '#ffffff', 'confidential' => true];
        }
        return ['kind' => 'absence', 'type' => $t, 'code' => absenceLabel($t), 'label' => absenceName($t),
            'color' => absenceColor($t), 'fg' => absenceFg($t), 'remarks' => $withRemarks ? (($abs['remarks'] ?? '') ?: null) : null];
    }
    return null;
}

/** Veröffentlichte Einträge eines Benutzers im Zeitraum: [date => [asg, abs]] */
function apiPublishedRange(int $uid, string $from, string $to): array
{
    $db = getDB(); $out = [];
    $s = $db->prepare('SELECT * FROM assignments WHERE user_id=? AND plan_date BETWEEN ? AND ? AND is_published=1');
    $s->execute([$uid, $from, $to]);
    foreach ($s->fetchAll() as $r) $out[$r['plan_date']]['asg'] = $r;
    $s = $db->prepare('SELECT * FROM absences WHERE user_id=? AND absence_date BETWEEN ? AND ? AND is_published=1');
    $s->execute([$uid, $from, $to]);
    foreach ($s->fetchAll() as $r) $out[$r['absence_date']]['abs'] = $r;
    return $out;
}

function apiHolidays(string $from, string $to): array
{
    $s = getDB()->prepare('SELECT holiday_date, name, holiday_type FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date');
    $s->execute([$from, $to]);
    $h = [];
    foreach ($s->fetchAll() as $r) $h[$r['holiday_date']] = ['name' => $r['name'], 'type' => $r['holiday_type']];
    return $h;
}

/* ------------------------------------------------------------------ Routing */
$path = trim((string)preg_replace('#^.*?/api/v1/?#', '', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$db = getDB();

try {
/* ---------- öffentlich ---------- */
if ($path === 'health' && $method === 'GET') apiOut(200, ['status' => 'ok']);

if ($path === 'config' && $method === 'GET') {
    $oidc = null;
    if (oidcEnabled()) {
        $c = oidcConfig();
        try { $d = oidcDiscovery($c['issuer']); $ae = $d['authorization_endpoint']; } catch (Throwable $e) { $ae = null; }
        /* authorize_url enthält client_id bereits (die App ergänzt PKCE, state, redirect_uri) */
        $au = $ae ? $ae . (strpos($ae, '?') === false ? '?' : '&') . http_build_query(['client_id' => $c['client_id']]) : null;
        $oidc = ['enabled' => $ae !== null, 'label' => $c['label'], 'authorize_url' => $au, 'client_id' => $c['client_id'], 'scopes' => $c['scopes'] ?? 'openid profile email'];
    }
    apiOut(200, ['api_version' => 1, 'tenant' => apiTenantInfo(),
        'auth' => ['password' => oidcPasswordLoginAllowed(), 'oidc' => $oidc ?? ['enabled' => false, 'label' => null, 'authorize_url' => null]],
        'modules' => apiModules() + ['mobile' => opsModule('mobile')]]);
}

if ($path === 'auth/login' && $method === 'POST') {
    $b = apiBody();
    $user = trim((string)($b['username'] ?? '')); $pw = (string)($b['password'] ?? '');
    if ($user === '' || $pw === '') apiError(400, 'invalid_request', 'Benutzername und Passwort angeben.');
    if (!opsModule('mobile')) apiError(403, 'module_disabled', 'Der App-Zugang ist für Ihre Organisation deaktiviert.');
    $max = (int)getSetting('max_login_attempts', '5'); $lock = (int)getSetting('lockout_duration_minutes', '15');
    $ip = opsClientIp();
    $cnt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE (username=? OR ip_address=?) AND success=0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
    $cnt->execute([$user, $ip, $lock]);
    if ($max > 0 && (int)$cnt->fetchColumn() >= $max) { header('Retry-After: ' . ($lock * 60)); apiError(403, 'account_locked', "Zu viele Fehlversuche. Bitte $lock Minuten warten."); }
    $s = $db->prepare('SELECT * FROM users WHERE username=? AND active=1');
    $s->execute([$user]);
    $u = $s->fetch();
    $log = $db->prepare('INSERT INTO login_attempts(username, ip_address, success) VALUES(?,?,?)');
    if (!$u || !password_verify($pw, $u['password_hash'])) {
        $log->execute([$user, $ip, 0]);
        apiError(401, 'invalid_credentials', 'Benutzername oder Passwort falsch.');
    }
    if (!oidcPasswordLoginAllowed() && $u['role'] !== 'admin') apiError(403, 'forbidden', 'Bitte mit dem Firmenkonto anmelden.');
    if ((int)$u['must_change_password'] === 1) apiError(403, 'password_change_required', 'Bitte zuerst im Browser ein neues Passwort vergeben.');
    $days = (int)getSetting('password_expiry_days', '30');
    if ($days > 0 && !empty($u['password_changed_at']) && strtotime($u['password_changed_at']) < time() - $days * 86400) {
        apiError(403, 'password_change_required', 'Ihr Passwort ist abgelaufen. Bitte im Browser ein neues vergeben.');
    }
    $log->execute([$user, $ip, 1]);
    $_SESSION['user_id'] = (int)$u['id'];
    $pair = apiIssuePair((int)$u['id'], null, isset($b['device_name']) ? (string)$b['device_name'] : null);
    try { $db->prepare('INSERT INTO audit_log(user_id,action,details,ip_address) VALUES(?,?,?,?)')->execute([$u['id'], 'api_login', 'App-Login', $ip]); } catch (Throwable $e) {}
    apiOut(200, $pair + ['user' => apiMeJson()]);
}

if ($path === 'auth/refresh' && $method === 'POST') {
    $b = apiBody();
    $r = apiRefresh((string)($b['refresh_token'] ?? ''));
    if (is_string($r)) apiError(401, $r, 'Bitte erneut anmelden.');
    $_SESSION['user_id'] = $r['user_id']; unset($r['user_id']);
    apiOut(200, $r + ['user' => apiMeJson()]);
}

if ($path === 'auth/oidc/token' && $method === 'POST') {
    if (!oidcEnabled()) apiError(404, 'not_found', 'SSO ist nicht aktiviert.');
    $b = apiBody();
    $redirect = (string)($b['redirect_uri'] ?? '');
    if (!in_array($redirect, ['opspilot://auth', oidcRedirectUri()], true)) apiError(400, 'invalid_request', 'Unzulässige redirect_uri.');
    try {
        $claims = oidcExchange((string)($b['code'] ?? ''), $redirect, (string)($b['code_verifier'] ?? ''), isset($b['nonce']) ? (string)$b['nonce'] : null);
    } catch (OidcException $e) { apiError(401, 'invalid_credentials', $e->getMessage()); }
    $u = oidcFindUser($claims);
    if (!$u) apiError(403, 'forbidden', 'Für dieses Firmenkonto gibt es keinen aktiven Zugang.');
    $_SESSION['user_id'] = (int)$u['id'];
    apiOut(200, apiIssuePair((int)$u['id'], null, isset($b['device_name']) ? (string)$b['device_name'] : null, 'oidc') + ['user' => apiMeJson()]);
}

/* ---------- ab hier: angemeldet ---------- */
$auth = apiAuth();
$me = currentUser();
$myId = (int)$me['id'];

if ($path === 'auth/logout' && $method === 'POST') {
    apiRevokeFamily($auth['fam']);
    apiOut(204);
}

if ($path === 'me' && $method === 'GET') apiOut(200, apiMeJson());

if ($path === 'plan/my' && $method === 'GET') {
    $from = apiDate($_GET['from'] ?? '') ?? date('Y-m-01');
    $to = apiDate($_GET['to'] ?? '') ?? date('Y-m-t', strtotime($from));
    if ($to < $from || (strtotime($to) - strtotime($from)) / 86400 > 92) apiError(400, 'invalid_request', 'Zeitraum ungültig (max. 93 Tage).');
    $pam = planAreaMap();
    $pub = apiPublishedRange($myId, $from, $to);
    $hol = apiHolidays($from, $to);
    $days = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        $days[] = ['date' => $d, 'weekday' => (int)date('N', $t), 'holiday' => $hol[$d] ?? null,
            'entry' => apiEntry($pub[$d]['asg'] ?? null, $pub[$d]['abs'] ?? null, $pam, $myId)];
    }
    $lp = $db->prepare('SELECT MAX(published_at) FROM publish_log WHERE (year*100+month) BETWEEN ? AND ?');
    $lp->execute([(int)date('Ym', strtotime($from)), (int)date('Ym', strtotime($to))]);
    apiOut(200, ['from' => $from, 'to' => $to, 'published_at' => apiIso($lp->fetchColumn() ?: null), 'days' => $days]);
}

if ($path === 'plan/team' && $method === 'GET') {
    if (!hasPerm('plan_view')) apiError(403, 'forbidden', 'Keine Berechtigung.');
    $y = (int)($_GET['year'] ?? date('Y')); $m = (int)($_GET['month'] ?? date('n'));
    if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) apiError(400, 'invalid_request', 'Monat ungültig.');
    $users = array_values(usersForMonth($y, $m, true));
    $asg = assignments($y, $m, true); $abs = absences($y, $m, true);
    $pam = planAreaMap();
    $days = daysInMonth($y, $m);
    $cells = [];
    foreach ($users as $u) {
        foreach ($days as $d) {
            $e = apiEntry($asg[$u['id']][$d] ?? null, $abs[$u['id']][$d] ?? null, $pam, (int)$u['id'], false);
            if ($e) { unset($e['remarks'], $e['label'], $e['area_id'], $e['type']); $cells[$u['id'] . '_' . $d] = $e; }
        }
    }
    apiOut(200, ['year' => $y, 'month' => $m, 'days' => $days, 'holidays' => apiHolidays($days[0], end($days)),
        'users' => array_map(function ($u) use ($myId) { return ['id' => (int)$u['id'], 'name' => $u['last_name'] . ', ' . $u['first_name'],
            'role_label' => roleName($u['role']), 'is_me' => (int)$u['id'] === $myId]; }, $users),
        'cells' => (object)$cells]);
}

if (preg_match('#^assignments/(\d{4}-\d{2}-\d{2})$#', $path, $mm) && $method === 'GET') {
    $date = apiDate($mm[1]) ?? apiError(400, 'invalid_request', 'Datum ungültig.');
    $uid = (int)($_GET['user_id'] ?? $myId);
    if ($uid !== $myId && !canEditUser($uid)) apiError(403, 'forbidden', 'Keine Berechtigung für diesen Mitarbeiter.');
    $pam = planAreaMap();
    $pub = apiPublishedRange($uid, $date, $date)[$date] ?? [];
    $entry = apiEntry($pub['asg'] ?? null, $pub['abs'] ?? null, $pam, $uid);
    $area = null; $customer = null; $location = null; $colleagues = [];
    if (!empty($pub['asg']) && isset($pam[$pub['asg']['plan_area_id']])) {
        $a = $pam[$pub['asg']['plan_area_id']];
        $area = ['id' => (int)$a['id'], 'code' => $a['short_code'], 'name' => $a['name'], 'type' => $a['area_type']];
        if (!empty($a['customer_id'])) {
            $s = $db->prepare('SELECT name, address, contact_name, phone, email, notes FROM customers WHERE id=? AND active=1'); $s->execute([$a['customer_id']]);
            if ($c = $s->fetch()) $customer = ['name' => $c['name'], 'address' => $c['address'], 'contact' => $c['contact_name'], 'phone' => $c['phone'], 'email' => $c['email'], 'notes' => $c['notes']];
        }
        if (!empty($a['location_id'])) {
            $s = $db->prepare('SELECT name, address, lat, lng FROM locations WHERE id=? AND active=1'); $s->execute([$a['location_id']]);
            if ($l = $s->fetch()) $location = ['name' => $l['name'], 'address' => $l['address'], 'lat' => $l['lat'] !== null ? (float)$l['lat'] : null, 'lng' => $l['lng'] !== null ? (float)$l['lng'] : null];
        }
        $s = $db->prepare('SELECT u.id, u.first_name, u.last_name FROM assignments a JOIN users u ON u.id=a.user_id WHERE a.plan_date=? AND a.plan_area_id=? AND a.is_published=1 AND a.user_id<>? AND u.active=1 ORDER BY u.last_name');
        $s->execute([$date, $a['id'], $uid]);
        $vis = visibleUserIds();
        foreach ($s->fetchAll() as $c) if ($vis === null || in_array((int)$c['id'], array_map('intval', $vis), true)) $colleagues[] = ['id' => (int)$c['id'], 'name' => $c['last_name'] . ', ' . $c['first_name']];
    }
    apiOut(200, ['date' => $date, 'user_id' => $uid, 'entry' => $entry, 'area' => $area, 'customer' => $customer, 'location' => $location, 'colleagues' => $colleagues]);
}

if ($path === 'absences/my' && $method === 'GET') {
    $y = (int)($_GET['year'] ?? date('Y'));
    if ($y < 2000 || $y > 2100) apiError(400, 'invalid_request', 'Jahr ungültig.');
    $s = $db->prepare("SELECT absence_date, absence_type FROM absences WHERE user_id=? AND YEAR(absence_date)=? AND is_published=1 ORDER BY absence_date");
    $s->execute([$myId, $y]);
    $rows = $s->fetchAll();
    $today = date('Y-m-d'); $used = 0.0; $planned = 0.0; $sick = 0.0;
    $items = []; $cur = null;
    foreach ($rows as $r) {
        $t = $r['absence_type']; $v = absenceDayValue($t);
        if (strpos($t, 'urlaub_') === 0) { if ($r['absence_date'] <= $today) $used += $v; else $planned += $v; }
        if (strpos($t, 'krank_') === 0) $sick += $v;
        $contig = $cur && $cur['type'] === $t;
        if ($contig) { /* Wochenende überbrücken wie im Abwesenheitsmodul */
            $gap = (int)round((strtotime($r['absence_date'] . ' 12:00') - strtotime($cur['end'] . ' 12:00')) / 86400);
            $contig = $gap === 1 || ($gap <= 3 && (int)date('N', strtotime($cur['end'])) === 5);
        }
        if ($contig) { $cur['end'] = $r['absence_date']; $cur['days'] += $v; continue; }
        if ($cur) $items[] = $cur;
        $cur = ['start' => $r['absence_date'], 'end' => $r['absence_date'], 'days' => $v, 'type' => $t, 'label' => absenceName($t), 'published' => true];
    }
    if ($cur) $items[] = $cur;
    $rq = $db->prepare("SELECT id, plan_date, absence_type, status, created_at FROM change_requests WHERE user_id=? AND request_type='absence' AND YEAR(plan_date)=? ORDER BY plan_date");
    $rq->execute([$myId, $y]);
    $ent = (float)$me['vacation_days'];
    apiOut(200, ['year' => $y, 'vacation' => ['entitlement' => $ent, 'used' => $used, 'planned' => $planned, 'left' => $ent - $used - $planned],
        'sick_days' => $sick, 'items' => $items,
        'requests' => array_map(function ($r) { return ['id' => (int)$r['id'], 'date' => $r['plan_date'], 'type' => $r['absence_type'], 'status' => $r['status'], 'created_at' => apiIso($r['created_at'])]; }, $rq->fetchAll())]);
}

if ($path === 'absences/requests' && $method === 'POST') {
    if (!opsModule('approvals')) apiError(403, 'module_disabled', 'Anträge sind für Ihre Organisation deaktiviert.');
    $b = apiBody();
    $from = apiDate($b['from'] ?? null); $to = apiDate($b['to'] ?? null);
    $type = (string)($b['type'] ?? '');
    if (!$from || !$to || $to < $from || (strtotime($to) - strtotime($from)) / 86400 > 60) apiError(400, 'invalid_request', 'Zeitraum ungültig (max. 61 Tage).');
    if (!in_array($type, ['urlaub_ganz', 'urlaub_halb', 'gleitzeit'], true)) apiError(400, 'invalid_request', 'Art ungültig.');
    $remarks = mb_substr(trim((string)($b['remarks'] ?? '')), 0, 500);
    $hol = array_filter(apiHolidays($from, $to), function ($h) { return $h['type'] === 'feiertag'; });
    $created = 0; $skipped = 0;
    opsTransaction($db, function (PDO $db) use ($from, $to, $type, $remarks, $hol, $myId, &$created, &$skipped) {
        $chk = $db->prepare("SELECT id FROM change_requests WHERE user_id=? AND plan_date=? AND status='pending'");
        $old = $db->prepare('SELECT (SELECT plan_area_id FROM assignments WHERE user_id=? AND plan_date=? AND is_published=1) a, (SELECT absence_type FROM absences WHERE user_id=? AND absence_date=? AND is_published=1) b');
        $ins = $db->prepare("INSERT INTO change_requests(user_id, plan_date, request_type, absence_type, old_type, old_value, remarks, status, requested_by) VALUES(?,?,'absence',?,?,?,?,'pending',?)");
        for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
            $d = date('Y-m-d', $t);
            if ((int)date('N', $t) >= 6 || isset($hol[$d])) continue;
            $chk->execute([$myId, $d]);
            if ($chk->fetch()) { $skipped++; continue; }
            $old->execute([$myId, $d, $myId, $d]); $o = $old->fetch();
            $ins->execute([$myId, $d, $type, $o['a'] ? 'assignment' : ($o['b'] ? 'absence' : 'empty'), $o['a'] ?: $o['b'], $remarks ?: null, $myId]);
            $created++;
        }
    });
    apiOut(201, ['created' => $created, 'skipped' => $skipped]);
}

if ($path === 'holidays' && $method === 'GET') {
    $y = (int)($_GET['year'] ?? date('Y'));
    $items = [];
    foreach (apiHolidays("$y-01-01", "$y-12-31") as $d => $h) $items[] = ['date' => $d] + $h;
    apiOut(200, ['items' => $items]);
}

if ($path === 'notifications' && $method === 'GET') {
    $lim = min(100, max(1, (int)($_GET['limit'] ?? 30)));
    $s = $db->prepare('SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC, id DESC LIMIT ' . $lim);
    $s->execute([$myId]);
    $c = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0'); $c->execute([$myId]);
    apiOut(200, ['unread' => (int)$c->fetchColumn(), 'items' => array_map(function ($n) {
        return ['id' => (int)$n['id'], 'title' => $n['title'], 'message' => $n['message'], 'link' => $n['link'], 'is_read' => (bool)$n['is_read'], 'created_at' => apiIso($n['created_at'])];
    }, $s->fetchAll())]);
}
if (preg_match('#^notifications/(\d+)/read$#', $path, $mm) && $method === 'POST') {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$mm[1], $myId]);
    apiOut(204);
}
if ($path === 'notifications/read-all' && $method === 'POST') {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0')->execute([$myId]);
    apiOut(204);
}

if ($path === 'sync' && $method === 'GET') {
    $max = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM plan_change_log')->fetchColumn();
    if (!isset($_GET['since']) || $_GET['since'] === '') apiOut(200, ['cursor' => (string)$max, 'changed_dates' => [], 'full_resync' => false]);
    $since = (int)$_GET['since'];
    $min = (int)$db->query('SELECT COALESCE(MIN(id),0) FROM plan_change_log')->fetchColumn();
    /* Cursor aus der Zukunft oder bereits bereinigtes Protokoll → komplett neu laden */
    if ($since > $max || ($min > 0 && $since < $min - 1)) apiOut(200, ['cursor' => (string)$max, 'changed_dates' => [], 'full_resync' => true]);
    $s = $db->prepare("SELECT DISTINCT plan_date FROM plan_change_log WHERE id > ? AND id <= ? AND user_id = ? AND action IN ('publish','approve','absence_module') ORDER BY plan_date");
    $s->execute([$since, $max, $myId]);
    apiOut(200, ['cursor' => (string)$max, 'changed_dates' => $s->fetchAll(PDO::FETCH_COLUMN), 'full_resync' => false]);
}

if ($path === 'devices' && $method === 'POST') {
    $b = apiBody();
    $platform = (string)($b['platform'] ?? ''); $provider = (string)($b['provider'] ?? ''); $token = trim((string)($b['token'] ?? ''));
    if (!in_array($platform, ['ios', 'android', 'web'], true) || !in_array($provider, ['fcm', 'apns', 'ntfy'], true) || $token === '' || strlen($token) > 512) {
        apiError(400, 'invalid_request', 'Geräteangaben ungültig.');
    }
    $hash = hash('sha256', $token);
    /* Ein Gerät gehört immer nur dem zuletzt angemeldeten Benutzer */
    $db->prepare('INSERT INTO push_devices(user_id, platform, provider, token, token_hash, app_version) VALUES(?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), platform=VALUES(platform), app_version=VALUES(app_version), last_seen=NOW()')
       ->execute([$myId, $platform, $provider, $token, $hash, mb_substr((string)($b['app_version'] ?? ''), 0, 30) ?: null]);
    $id = $db->prepare('SELECT id FROM push_devices WHERE provider=? AND token_hash=?'); $id->execute([$provider, $hash]);
    apiOut(201, ['id' => (int)$id->fetchColumn()]);
}
if (preg_match('#^devices/(\d+)$#', $path, $mm) && $method === 'DELETE') {
    $db->prepare('DELETE FROM push_devices WHERE id=? AND user_id=?')->execute([(int)$mm[1], $myId]);
    apiOut(204);
}

apiError(404, 'not_found', 'Unbekannter Endpunkt.');
} catch (Throwable $e) {
    error_log('api v1: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    apiError(500, 'server_error', 'Interner Fehler.');
}
