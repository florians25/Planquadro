<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}
require_once __DIR__ . '/../../app/config/database.php';

function isLoggedIn(): bool { return isset($_SESSION['user_id']) && empty($_SESSION['must_change_password']); }

function requireLogin(): void {
    if (!isset($_SESSION['user_id'])) { header('Location: ' . bp() . './'); exit; }
    if (!empty($_SESSION['must_change_password'])) { header('Location: ' . bp() . './'); exit; }
    /* Check password expiry (30 days default) */
    checkPasswordExpiry();
}

function checkPasswordExpiry(): void {
    if (!empty($_SESSION['pw_expiry_checked'])) return; /* Only check once per session load */
    try {
        $db = getDB();
        $days = 30;
        try { $s = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?'); $s->execute(['password_expiry_days']); $r = $s->fetch(); if ($r) $days = (int)$r['setting_value']; } catch(Exception $e) {}
        if ($days <= 0) { $_SESSION['pw_expiry_checked'] = 1; return; }
        $s = $db->prepare('SELECT password_changed_at FROM users WHERE id=?');
        $s->execute([$_SESSION['user_id']]); $u = $s->fetch();
        if ($u && !empty($u['password_changed_at'])) {
            $changed = strtotime($u['password_changed_at']);
            if ($changed && (time() - $changed) > ($days * 86400)) {
                $_SESSION['must_change_password'] = true;
                $_SESSION['pw_expired'] = true;
                header('Location: ' . bp() . './'); exit;
            }
        }
        $_SESSION['pw_expiry_checked'] = 1;
    } catch(Exception $e) { $_SESSION['pw_expiry_checked'] = 1; }
}

function currentUser(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    static $u = null;
    if ($u === null) {
        $s = getDB()->prepare('SELECT * FROM users WHERE id=?');
        $s->execute([$_SESSION['user_id']]);
        $u = $s->fetch();
    }
    return $u;
}

function hasRole(string ...$r): bool {
    $u = currentUser();
    return $u && in_array($u['role'], $r, true);
}

function isAdmin(): bool { return hasRole('admin'); }
function canEdit(): bool { return hasPerm('plan_edit'); }
function canSeeDetails(): bool { return hasPerm('view_gesamtplan_details'); }
/* Benutzer-/Teamverwaltung ausschließlich für Manager, Verwaltung (Service
   Administration) und Administrator – fest an die Rolle gebunden, damit weder
   veraltete DB-Rechte noch fehlende Migrationen den Zugriff verändern. */
function canManageUsers(): bool { return hasRole('admin','manager','verwaltung'); }
/* Stammdaten/Konfiguration (Einsatzbereiche, Feiertage) – ebenfalls nur
   Manager, Verwaltung (Service Administration) und Administrator. */
function canManageConfig(): bool { return hasRole('admin','manager','verwaltung'); }
function requireConfigManager(): void {
    requireLogin();
    if (!canManageConfig()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['error'=>'Keine Berechtigung']);
            exit;
        }
        showAccessDenied('Dieser Bereich ist nur für Manager, Verwaltung (Service Administration) und Administratoren zugänglich.');
    }
}

/* === Permission System === */
function hasPerm(string $perm): bool {
    $u = currentUser();
    if (!$u) return false;
    $uid = (int)$u['id'];
    $role = $u['role'];
    static $cache = [];
    $key = $uid.'_'.$perm;
    if (isset($cache[$key])) return $cache[$key];
    /* 1. Check user-level override first */
    try {
        $s = getDB()->prepare('SELECT granted FROM permission_user_overrides WHERE user_id=? AND permission=?');
        $s->execute([$uid, $perm]);
        $r = $s->fetch();
        if ($r) { $cache[$key] = (bool)$r['granted']; return $cache[$key]; }
    } catch(Exception $e) { /* table may not exist yet */ }
    /* 2. Check role-level permission */
    try {
        $s = getDB()->prepare('SELECT granted FROM role_permissions WHERE role=? AND permission=?');
        $s->execute([$role, $perm]);
        $r = $s->fetch();
        $cache[$key] = $r ? (bool)$r['granted'] : false;
    } catch(Exception $e) {
        /* Fallback to legacy role checks if table doesn't exist yet */
        $legacy = [
            'plan_view' => true,
            'plan_edit' => in_array($role, ['admin','manager','teamleiter','verwaltung']),
            'plan_publish_own' => in_array($role, ['admin','manager','teamleiter','verwaltung']),
            'plan_publish_all' => in_array($role, ['admin','manager']),
            'plan_publish_year' => in_array($role, ['admin','manager']),
            'plan_view_internal' => in_array($role, ['admin','manager','teamleiter','verwaltung']),
            'view_erl_column' => in_array($role, ['admin','manager','teamleiter','verwaltung']),
            'unlock_vacation' => in_array($role, ['admin','manager']),
            'manage_users' => in_array($role, ['admin','manager','verwaltung']),
            'delete_users' => in_array($role, ['admin']),
            'manage_settings' => in_array($role, ['admin','manager']),
            'manage_roles' => in_array($role, ['admin']),
            'view_gesamtplan_details' => in_array($role, ['admin','manager','verwaltung','teamleiter']),
            'approve_requests' => in_array($role, ['admin','manager','teamleiter']),
            /* Standardmäßig sehen alle Rollen alle Mitarbeiter – nur Manager und
               Teamleiter werden auf ihr eigenes Team eingeschränkt. */
            'view_all_employees' => !in_array($role, ['manager','teamleiter']),
        ];
        $cache[$key] = $legacy[$perm] ?? false;
    }
    return $cache[$key];
}

function allPermsForRole(string $role): array {
    try {
        $s = getDB()->prepare('SELECT permission, granted FROM role_permissions WHERE role=?');
        $s->execute([$role]);
        $map = [];
        foreach ($s->fetchAll() as $r) $map[$r['permission']] = (bool)$r['granted'];
        return $map;
    } catch(Exception $e) { return []; }
}

function allPermissions(): array {
    return [
        'plan_view' => 'Plan ansehen',
        'plan_edit' => 'Plan bearbeiten',
        'plan_publish_own' => 'Eigene Änderungen veröffentlichen',
        'plan_publish_all' => 'Alle Änderungen veröffentlichen',
        'plan_publish_year' => 'Jahr veröffentlichen',
        'plan_view_internal' => 'Interne Einträge sehen',
        'view_erl_column' => 'Erl.-Spalte sehen',
        'unlock_vacation' => 'Genehmigten Urlaub entsperren',
        'manage_users' => 'Mitarbeiter verwalten',
        'delete_users' => 'Mitarbeiter löschen',
        'manage_settings' => 'Einstellungen verwalten',
        'manage_roles' => 'Rollen & Rechte verwalten',
        'view_gesamtplan_details' => 'Gesamtplan Details sehen',
        'approve_requests' => 'Anträge genehmigen',
        'view_all_employees' => 'Alle Mitarbeiter sehen (sonst nur eigenes Team)',
    ];
}

/* User-level permission overrides */
function getUserOverrides(string $perm): array {
    try {
        $s = getDB()->prepare('SELECT o.user_id, o.granted, u.first_name, u.last_name FROM permission_user_overrides o JOIN users u ON u.id=o.user_id WHERE o.permission=? ORDER BY u.last_name');
        $s->execute([$perm]);
        return $s->fetchAll();
    } catch(Exception $e) { return []; }
}

function setUserOverride(int $userId, string $perm, bool $granted): void {
    getDB()->prepare('INSERT INTO permission_user_overrides(permission,user_id,granted,created_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE granted=?,created_by=?')
           ->execute([$perm, $userId, $granted?1:0, $_SESSION['user_id']??0, $granted?1:0, $_SESSION['user_id']??0]);
}

function removeUserOverride(int $userId, string $perm): void {
    getDB()->prepare('DELETE FROM permission_user_overrides WHERE user_id=? AND permission=?')
           ->execute([$userId, $perm]);
}

function requireEditor(): void {
    requireLogin();
    if (!canEdit()) {
        showAccessDenied('Diese Seite ist nur für berechtigte Benutzer (Teamleiter, Manager, Verwaltung) zugänglich.');
    }
}

function requireAccess(string ...$r): void {
    requireLogin();
    if (!hasRole(...$r)) {
        showAccessDenied('Sie haben keine Berechtigung, diese Seite aufzurufen.');
    }
}

function showAccessDenied($msg = 'Sie haben keine Berechtigung, diese Seite aufzurufen.'): void {
    $pageTitle = 'Kein Zugriff';
    require_once __DIR__ . '/functions.php';
    require_once __DIR__ . '/header.php';
    $cu = currentUser();
    echo '<div class="access-denied-page">';
    echo '<div class="access-denied-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:64px;height:64px;color:var(--err)"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div>';
    echo '<h2>Kein Zugriff</h2>';
    echo '<p class="muted" style="max-width:400px;margin:.5rem auto">'.htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</p>';
    if ($cu) {
        echo '<p class="muted" style="font-size:.78rem;margin-top:.5rem">Angemeldet als: <b>'.htmlspecialchars($cu['first_name'].' '.$cu['last_name'], ENT_QUOTES, 'UTF-8').'</b> ('.htmlspecialchars(roleName($cu['role']), ENT_QUOTES, 'UTF-8').')</p>';
    }
    echo '<a href="'.bp().'dashboard" class="btn btn-primary" style="margin-top:1rem">Zum Dashboard</a>';
    echo '</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

function csrfToken(): string {
    if (empty($_SESSION["csrf_token"])) $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    return $_SESSION["csrf_token"];
}
function verifyCsrf($token): bool { return hash_equals(csrfToken(), $token ?? ""); }

function bp(): string {
    $s = $_SERVER['SCRIPT_FILENAME'] ?? '';
    return (strpos($s, '/pages/') !== false || strpos($s, '/api/') !== false) ? '../' : '';
}
