<?php
require_once __DIR__ . '/../../app/lib/Security.php';
require_once __DIR__ . '/../../app/lib/Kernel.php';
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/lib/Permissions.php';
/* Request-Kernel: Request-ID (Header X-Request-ID, Log-Zeilen) und globaler
   Fehlerrahmen – technische Fehlertexte gehen nur ins Log (Befund S-20).
   API v1 hat einen eigenen Fehlerrahmen und bekommt nur die Request-ID. */
opsKernelBoot(!(defined('PQ_STATELESS') && PQ_STATELESS));
/* API v1 (Bearer-Token) arbeitet ohne Cookie-Session: kein Session-Start,
   kein CSRF (es gibt kein Cookie, das ein Angreifer ausnutzen könnte). Der
   Benutzer wird nach der Token-Prüfung in $_SESSION['user_id'] gesetzt,
   damit alle Rechte-/Scope-Funktionen unverändert funktionieren. */
if (defined('PQ_STATELESS') && PQ_STATELESS) {
    $_SESSION = [];
} else {
    opsStartSession();
    /* Jeder zustandsändernde Request (POST/PUT/PATCH/DELETE) an Seiten und APIs
       braucht ein gültiges CSRF-Token. app.js/header.php senden es automatisch
       als Header X-CSRF-Token mit. */
    opsEnforceCsrf();
    opsEnforceSessionTimeout();
}

function isLoggedIn(): bool { return isset($_SESSION['user_id']) && empty($_SESSION['must_change_password']); }

/* Für AJAX-Handler: statt Umleitung eine JSON-Antwort 401. Gilt auch,
   solange ein Passwortwechsel erzwungen ist. */
function requireLoginJson(): void {
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'error'=>'Nicht angemeldet']);
        exit;
    }
}

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
/* Früher fest an Rollen gebundene Prüfungen, seit Migration 0006 eigene
   Rechte (app/lib/Permissions.php, role_bound). Standard unverändert:
   Benutzer-/Teamverwaltung und Stammdaten admin, manager, verwaltung. */
function canManageUsers(): bool { return hasPerm('manage_team'); }
/* Stammdaten/Konfiguration (Einsatzbereiche, Feiertage, Stammdaten) */
function canManageConfig(): bool { return hasPerm('manage_config'); }
/* Wartungsmodus-Ausnahme (Standard: admin, manager) */
function canBypassMaintenance(): bool { return hasPerm('bypass_maintenance'); }
/* api/maintenance und api/cleanup (Standard: admin, manager) */
function canManageMaintenance(): bool { return hasPerm('manage_maintenance'); }
/* Changelog-Einträge löschen (Standard: admin, manager) */
function canDeleteChangelog(): bool { return hasPerm('delete_changelog'); }
/* Seite Vertretung (Standard: admin, manager, teamleiter) */
function canManageDeputations(): bool { return hasPerm('manage_deputations'); }
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
    /* 0. Früher rollengebundene Rechte hat der Administrator immer
          (entspricht der bisherigen Prüfung hasRole('admin', …)). */
    if ($role === 'admin' && permissionIsRoleBound($perm)) { $cache[$key] = true; return true; }
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
        /* Fehlende Zeile: verweigert – außer bei früher rollengebundenen
           Rechten, dort gilt der Katalog-Standard (Code vor Migration 0006). */
        $cache[$key] = $r ? (bool)$r['granted'] : (permissionIsRoleBound($perm) && permissionDefault($perm, $role));
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
        $cache[$key] = $legacy[$perm] ?? (permissionIsRoleBound($perm) && permissionDefault($perm, $role));
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

/* Wirksamer Wert eines Rechts für eine Rolle (ohne Benutzer-Overrides) –
   gleiche Regeln wie hasPerm(). Für die Rollen-Seite und das Kopieren von
   Rechten beim Anlegen einer Rolle. */
function roleHasPermission(string $role, string $perm): bool {
    static $maps = [];
    if ($role === 'admin' && permissionIsRoleBound($perm)) return true;
    if (!isset($maps[$role])) $maps[$role] = allPermsForRole($role);
    if (array_key_exists($perm, $maps[$role])) return $maps[$role][$perm];
    return permissionIsRoleBound($perm) && permissionDefault($perm, $role);
}

/* Alle Rechte (Schlüssel => Bezeichnung) aus dem Katalog app/lib/Permissions.php */
function allPermissions(): array {
    return array_map(function ($p) { return $p['label']; }, permissionCatalog());
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
    echo '<div class="access-denied-icon">' . pqIcon('ban', 'pq-ic-denied') . '</div>';
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

function csrfToken(): string { return opsCsrfToken(); }
function verifyCsrf($token): bool { return hash_equals(csrfToken(), $token ?? ""); }

function bp(): string {
    $s = $_SERVER['SCRIPT_FILENAME'] ?? '';
    return (strpos($s, '/pages/') !== false || strpos($s, '/api/') !== false) ? '../' : '';
}
