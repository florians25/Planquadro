<?php
/**
 * Planquadro – Rechte-Snapshot (effektive Rechtematrix aller aktiven Benutzer)
 * --------------------------------------------------------------------------
 * Aufruf (CLI):
 *   php bin/permissions-snapshot.php > before.json            Snapshot erzeugen
 *   php bin/permissions-snapshot.php --diff=before.json       mit früherem Snapshot vergleichen
 *                                                             (Exit 1 bei jeder Abweichung)
 *   Optionen: --tenant=<slug> (SaaS), --user=<id> (nur ein Benutzer, intern)
 *
 * Je Benutzer: Rolle, jedes Recht laut hasPerm() (Override > Rolle > Standard),
 * die fest an Rollen gebundenen bzw. daraus abgeleiteten Prüfungen, der
 * Team-Scope (sichtbare Benutzer), die unterstellten Mitarbeiter und für wen
 * Krankheits-/Urlaubsdetails sichtbar sind. Benutzer werden über ihren
 * Benutzernamen identifiziert, damit Snapshots verschiedener Datenbanken
 * (Kopie, Staging) vergleichbar sind.
 *
 * Vergleich (--diff): Ein Recht, das nur im neuen Snapshot vorkommt, ist eine
 * Ergänzung (Hinweis, kein Fehler) – seine Wirkung wird über die Prüfungen
 * unter "checks" verglichen. Jede andere Abweichung bricht ab.
 *
 * Jeder Benutzer wird in einem eigenen PHP-Prozess ausgewertet, weil die
 * Rechtefunktionen ihre Ergebnisse je Request zwischenspeichern.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}
if (!empty($opts['tenant'])) putenv('PQ_TENANT=' . $opts['tenant']);

/** Rechte, die mindestens geprüft werden (Stand v6.9.0 + Phase 2/3) */
const SNAPSHOT_BASE_PERMS = [
    'plan_view', 'plan_edit', 'plan_publish_own', 'plan_publish_all', 'plan_publish_year', 'plan_view_internal',
    'view_erl_column', 'unlock_vacation', 'manage_users', 'delete_users', 'manage_settings', 'manage_roles',
    'view_gesamtplan_details', 'approve_requests', 'view_all_employees', 'manage_absences',
    'view_absence_details_all', 'use_planning_assistant',
];

if (!empty($opts['user'])) {
    /* ---- ein Benutzer: Auswertung im eigenen Prozess ---- */
    define('PQ_STATELESS', true);
    require_once __DIR__ . '/../public/includes/functions.php';
    $_SESSION['user_id'] = (int)$opts['user'];
    $me = currentUser();
    if (!$me) { fwrite(STDERR, "Benutzer nicht gefunden\n"); exit(2); }
    $names = [];
    foreach (getDB()->query('SELECT id, username, active FROM users') as $r) $names[(int)$r['id']] = $r;
    $toNames = function (array $ids) use ($names): array {
        $out = [];
        foreach ($ids as $id) if (isset($names[(int)$id])) $out[] = $names[(int)$id]['username'];
        sort($out, SORT_STRING);
        return array_values(array_unique($out));
    };
    $perms = [];
    $keys = array_unique(array_merge(SNAPSHOT_BASE_PERMS, array_keys(allPermissions())));
    sort($keys, SORT_STRING);
    foreach ($keys as $p) $perms[$p] = hasPerm($p);
    /* Abgeleitete Prüfungen. Wo eine Prüfung in eine eigene Funktion überführt
       wurde, wird diese verwendet, sonst der bisherige Ausdruck. */
    $f = function (string $fn, callable $legacy): bool { return function_exists($fn) ? (bool)$fn() : (bool)$legacy(); };
    $checks = [
        'is_admin'               => isAdmin(),
        'can_edit'               => canEdit(),
        'can_see_details'        => canSeeDetails(),
        'can_manage_users'       => canManageUsers(),
        'can_manage_config'      => canManageConfig(),
        'can_view_all_employees' => canViewAllEmployees(),
        'maintenance_bypass'     => $f('canBypassMaintenance', function () { return hasRole('admin', 'manager'); }),
        'maintenance_manage'     => $f('canManageMaintenance', function () { return hasRole('admin', 'manager'); }),
        'changelog_delete'       => $f('canDeleteChangelog', function () { return hasRole('admin', 'manager'); }),
        'deputation_manage'      => $f('canManageDeputations', function () { return hasRole('admin', 'manager', 'teamleiter'); }),
        'vacation_overview'      => hasRole('admin', 'manager', 'teamleiter'),
        'mine_filter'            => hasRole('manager', 'teamleiter'),
        'assistant_cross_team'   => hasRole('admin', 'manager') || hasPerm('view_all_employees'),
        'active_deputation'      => hasActiveDeputation(),
    ];
    ksort($checks);
    $scope = visibleUserIds();
    $activeIds = array_keys(array_filter($names, function ($r) { return (int)$r['active'] === 1; }));
    $absence = [];
    foreach ($activeIds as $id) if (canSeeAbsenceDetails($id)) $absence[] = $id;
    echo json_encode([
        'role' => $me['role'],
        'perms' => $perms,
        'checks' => $checks,
        'scope' => $scope === null ? 'all' : $toNames($scope),
        'supervised' => $toNames(supervisedIdSet()),
        'absence_details' => $toNames($absence),
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}

/* ---- alle aktiven Benutzer ---- */
require_once __DIR__ . '/../app/config/database.php';
$users = getDB()->query('SELECT id, username FROM users WHERE active = 1 ORDER BY username')->fetchAll(PDO::FETCH_KEY_PAIR);
$snap = [];
foreach ($users as $id => $username) {
    $cmd = [PHP_BINARY, __FILE__, '--user=' . $id];
    if (!empty($opts['tenant'])) $cmd[] = '--tenant=' . $opts['tenant'];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    $rc = proc_close($p);
    $row = json_decode((string)$out, true);
    if ($rc !== 0 || !is_array($row)) { fwrite(STDERR, "Fehler bei Benutzer $username: $err$out\n"); exit(2); }
    $snap[$username] = $row;
}
ksort($snap, SORT_STRING);

if (empty($opts['diff'])) {
    echo json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

/* ---- Vergleich ---- */
$before = json_decode((string)@file_get_contents((string)$opts['diff']), true);
if (!is_array($before)) { fwrite(STDERR, "Snapshot {$opts['diff']} nicht lesbar\n"); exit(2); }
[$errors, $notes] = permissionSnapshotDiff($before, $snap);
foreach ($notes as $n) fwrite(STDOUT, "Hinweis: $n\n");
foreach ($errors as $e) fwrite(STDOUT, "ABWEICHUNG: $e\n");
fwrite(STDOUT, $errors ? count($errors) . " Abweichung(en) – Rechte haben sich verändert.\n" : "Keine Abweichung.\n");
exit($errors ? 1 : 0);

/** [Fehler, Hinweise] zwischen zwei Snapshots */
function permissionSnapshotDiff(array $before, array $after): array
{
    $errors = []; $notes = [];
    foreach ($before as $user => $b) {
        if (!isset($after[$user])) { $errors[] = "$user fehlt im neuen Snapshot"; continue; }
        $a = $after[$user];
        foreach ($b as $field => $val) {
            if (in_array($field, ['perms', 'checks'], true)) {
                foreach ($val as $k => $v) {
                    if (!array_key_exists($k, $a[$field] ?? [])) $errors[] = "$user: $field.$k fehlt";
                    elseif ($a[$field][$k] !== $v) $errors[] = "$user: $field.$k " . json_encode($v) . ' → ' . json_encode($a[$field][$k]);
                }
                foreach (array_diff_key($a[$field] ?? [], $val) as $k => $v) $notes["$field.$k"] = "neu: $field.$k";
            } elseif (($a[$field] ?? null) !== $val) {
                $errors[] = "$user: $field " . json_encode($val, JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($a[$field] ?? null, JSON_UNESCAPED_UNICODE);
            }
        }
    }
    foreach (array_diff_key($after, $before) as $user => $_) $errors[] = "$user ist neu";
    return [$errors, array_values($notes)];
}
