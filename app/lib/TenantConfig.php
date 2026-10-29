<?php
/**
 * OpsPilot – TenantConfig
 * --------------------------------------------------------------------------
 * Zentrale, typisierte Konfiguration des Mandanten. Gespeichert als JSON in
 * app_settings('tenant_config') der Mandanten-Datenbank. Werte, für die es
 * bereits eine Einstellung aus Einsatzplanung v6.x gibt, werden NICHT doppelt
 * gespeichert, sondern direkt auf den alten Schlüssel abgebildet
 * (LEGACY_KEYS) – so bleiben Einstellungsseite, Altcode und TenantConfig
 * immer konsistent.
 *
 * Zugriff:  tcfg('branding.name')           Wert (mit Standard)
 *           tcfgSet(['modules' => [...]])   Teilbaum speichern
 *           opsModule('absences')           Modul aktiv?
 *           opsWidget('kpis')               Dashboard-Widget aktiv?
 *           opsNavVisible('urlaubsplanung') / opsNavOrder('…')
 */

const TENANT_CONFIG_DEFAULTS = [
    'branding' => [
        'name'    => 'OpsPilot',
        'primary' => '#e2001a',
        'accent'  => '#2563eb',
        'logo'    => null,          /* Dateiname im Mandanten-Speicher */
    ],
    'modules' => [
        'assistant'  => true,   /* Planungsassistent */
        'absences'   => true,   /* Modul Abwesenheiten */
        'approvals'  => false,  /* Änderungsanträge (bisher approval_system_enabled) */
        'vacation'   => true,   /* Urlaubsübersicht */
        'deputation' => true,   /* Vertretungen */
        'annual'     => true,   /* Jahresplan */
        'wots'       => true,   /* WOTs, Stunden, Erl.-Spalte */
        'ical'       => true,   /* Kalender-Abo */
        'masterdata' => true,   /* Kunden, Standorte, Qualifikationen */
        'mobile'     => true,   /* API v1 für Apps */
    ],
    'navigation' => [
        'order'  => ['dashboard','mein_plan','gesamtplan','monatsplanung','planungsassistent','jahresuebersicht',
                     'urlaubsplanung','abwesenheiten','vertretung','genehmigungen','mitarbeiter','planbereiche',
                     'stammdaten','feiertage','einstellungen','rollen'],
        'hidden' => [],
    ],
    'dashboard' => [
        'widgets' => ['announcements','kpis','week','wots','requests','publication','absent_today','distribution'],
    ],
    'planning' => [
        'cross_team_planning'   => false,   /* epa_cross_team */
        'concurrent_editing'    => true,    /* allow_concurrent_edit */
        'show_absent_dashboard' => true,    /* dashboard_show_absent */
    ],
    'security' => [
        'oidc' => [
            'enabled'              => false,
            'issuer'               => '',
            'client_id'            => '',
            'client_secret'        => '',   /* alternativ OPSPILOT_OIDC_CLIENT_SECRET */
            'allow_admin'          => false, /* Admin-Konten per SSO (Standard: nur Passwort) */
            'claim'                => 'preferred_username',
            'label'                => 'Mit Firmenkonto anmelden',
            'allow_password_login' => true,
            'scopes'               => 'openid profile email',
        ],
    ],
];

/** Pfad → [alter Schlüssel, Typ] */
const TENANT_CONFIG_LEGACY_KEYS = [
    'branding.name'                  => ['company_name', 'string'],
    'modules.approvals'              => ['approval_system_enabled', 'bool'],
    'planning.cross_team_planning'   => ['epa_cross_team', 'bool'],
    'planning.concurrent_editing'    => ['allow_concurrent_edit', 'bool'],
    'planning.show_absent_dashboard' => ['dashboard_show_absent', 'bool'],
];

const DASHBOARD_WIDGETS = [
    'announcements' => 'Meldungen', 'kpis' => 'Kennzahlen', 'week' => 'Heute & diese Woche',
    'wots' => 'WOTs des Monats', 'requests' => 'Änderungsanträge', 'publication' => 'Planstatus',
    'absent_today' => 'Heute abwesend & Feiertage', 'distribution' => 'Einsatzverteilung',
];

const TENANT_MODULES = [
    'assistant' => 'Planungsassistent', 'absences' => 'Abwesenheiten (Zeiträume erfassen)',
    'approvals' => 'Änderungsanträge von Mitarbeitern', 'vacation' => 'Urlaubsübersicht',
    'deputation' => 'Vertretungen', 'annual' => 'Jahresplan', 'wots' => 'WOTs, Stunden, Erl.-Spalte',
    'ical' => 'Kalender-Abo (iCal)', 'masterdata' => 'Stammdaten (Kunden, Standorte, Qualifikationen)',
    'mobile' => 'Mobile Apps (API)',
];

/** Seite → Modul (Seite ist nur bei aktivem Modul erreichbar) */
const PAGE_MODULES = [
    'planungsassistent' => 'assistant', 'abwesenheiten' => 'absences', 'genehmigungen' => 'approvals',
    'urlaubsplanung' => 'vacation', 'vertretung' => 'deputation', 'jahresuebersicht' => 'annual',
    'stammdaten' => 'masterdata',
];

function tcfgAll(bool $reload = false): array
{
    static $cfg = null;
    if ($cfg !== null && !$reload) return $cfg;
    $stored = [];
    $legacy = [];
    try {
        $db = getDB();
        $s = $db->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('tenant_config','company_name','approval_system_enabled','epa_cross_team','allow_concurrent_edit','dashboard_show_absent')");
        $s->execute();
        foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            if ($k === 'tenant_config') { $j = json_decode((string)$v, true); if (is_array($j)) $stored = $j; }
            else $legacy[$k] = $v;
        }
    } catch (Throwable $e) { /* ohne DB: Standardwerte */ }
    $cfg = tcfgMerge(TENANT_CONFIG_DEFAULTS, $stored);
    foreach (TENANT_CONFIG_LEGACY_KEYS as $path => [$key, $type]) {
        if (!array_key_exists($key, $legacy)) continue;
        $v = $legacy[$key];
        tcfgPut($cfg, $path, $type === 'bool' ? ($v === '1') : (string)$v);
    }
    /* Navigation: neue Seiten aus den Standards anhängen, unbekannte entfernen */
    $known = TENANT_CONFIG_DEFAULTS['navigation']['order'];
    $order = array_values(array_intersect((array)$cfg['navigation']['order'], $known));
    foreach ($known as $p) if (!in_array($p, $order, true)) $order[] = $p;
    $cfg['navigation']['order'] = $order;
    return $cfg;
}

function tcfgMerge(array $base, array $over): array
{
    foreach ($over as $k => $v) {
        if (!array_key_exists($k, $base)) continue;               /* nur bekannte Schlüssel */
        if (is_array($base[$k]) && is_array($v) && $base[$k] !== [] && !array_is_list($base[$k])) {
            $base[$k] = tcfgMerge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function tcfgPut(array &$arr, string $path, $value): void
{
    $ref = &$arr;
    foreach (explode('.', $path) as $p) { if (!isset($ref[$p]) || !is_array($ref[$p])) $ref[$p] = $ref[$p] ?? []; $ref = &$ref[$p]; }
    $ref = $value;
}

function tcfg(string $path, $default = null)
{
    $v = tcfgAll();
    foreach (explode('.', $path) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) return $default;
        $v = $v[$p];
    }
    return $v;
}

/**
 * Speichert einen Teilbaum. Werte mit Altschlüssel landen in app_settings,
 * der Rest im JSON. Unbekannte Schlüssel werden verworfen.
 */
function tcfgSet(array $patch): void
{
    $db = getDB();
    $cur = tcfgAll(true);
    $new = tcfgMerge($cur, $patch);
    $json = $new;
    foreach (TENANT_CONFIG_LEGACY_KEYS as $path => [$key, $type]) {
        $parts = explode('.', $path);
        $v = $new; foreach ($parts as $p) $v = $v[$p];
        $db->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
           ->execute([$key, $type === 'bool' ? ($v ? '1' : '0') : (string)$v]);
        /* nicht doppelt speichern */
        $ref = &$json; $last = array_pop($parts);
        foreach ($parts as $p) $ref = &$ref[$p];
        unset($ref[$last]); unset($ref);
    }
    $db->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('tenant_config', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
       ->execute([json_encode($json, JSON_UNESCAPED_UNICODE)]);
    tcfgAll(true);
}

function opsModule(string $m): bool { return (bool)tcfg('modules.' . $m, false); }

function opsWidget(string $w): bool { return in_array($w, (array)tcfg('dashboard.widgets', []), true); }

function opsNavVisible(string $page): bool
{
    if (in_array($page, (array)tcfg('navigation.hidden', []), true)) return false;
    $mod = PAGE_MODULES[$page] ?? null;
    return $mod === null || opsModule($mod);
}

/** CSS-order-Wert der Navigationsposition (Navigation ist ein Flex-Container) */
function opsNavOrder(string $page): int
{
    $i = array_search($page, (array)tcfg('navigation.order', []), true);
    return $i === false ? 99 : (int)$i;
}

/** Bricht ab, wenn das Modul der aktuellen Seite deaktiviert ist. */
function requireModule(string $m): void
{
    if (opsModule($m)) return;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Dieses Modul ist deaktiviert.', 'code' => 'module_disabled']);
        exit;
    }
    showAccessDenied('Dieses Modul ist für Ihre Organisation deaktiviert.');
}

/* ---------- Farben ---------- */

function opsHexToRgb(string $hex): array
{
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

function opsMix(string $hex, string $with, float $t): string
{
    [$r, $g, $b] = opsHexToRgb($hex); [$r2, $g2, $b2] = opsHexToRgb($with);
    return sprintf('#%02x%02x%02x', round($r + ($r2 - $r) * $t), round($g + ($g2 - $g) * $t), round($b + ($b2 - $b) * $t));
}

/** Relative Leuchtdichte nach WCAG 2.x */
function opsLuminance(string $hex): float
{
    $c = array_map(function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, opsHexToRgb($hex));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function opsContrast(string $a, string $b): float
{
    $l1 = opsLuminance($a); $l2 = opsLuminance($b);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

/** Schriftfarbe mit dem besseren Kontrast auf $bg */
function opsReadableOn(string $bg): string
{
    return opsContrast($bg, '#ffffff') >= opsContrast($bg, '#111827') ? '#ffffff' : '#111827';
}

/**
 * CSS-Variablen aus dem Branding. Ist die Markenfarbe als Textfarbe auf Weiß
 * zu hell (< 4.5:1), wird für Text/Links eine abgedunkelte Variante genutzt.
 */
function opsBrandCss(): string
{
    $p = opsValidColor((string)tcfg('branding.primary')) ?? '#e2001a';
    $a = opsValidColor((string)tcfg('branding.accent')) ?? '#2563eb';
    if (strlen($p) === 4) $p = '#' . $p[1] . $p[1] . $p[2] . $p[2] . $p[3] . $p[3];
    if (strlen($a) === 4) $a = '#' . $a[1] . $a[1] . $a[2] . $a[2] . $a[3] . $a[3];
    $p = substr($p, 0, 7); $a = substr($a, 0, 7);
    $text = $p; $i = 0;
    while (opsContrast($text, '#ffffff') < 4.5 && $i++ < 10) $text = opsMix($text, '#000000', 0.12);
    return ':root{--p:' . $p . ';--pl:' . opsMix($p, '#ffffff', 0.15) . ';--ph:' . opsMix($p, '#000000', 0.2)
         . ';--pbg:' . opsMix($p, '#ffffff', 0.9) . ';--p-on:' . opsReadableOn($p) . ';--p-text:' . $text
         . ';--acc:' . $a . ';--acc-on:' . opsReadableOn($a) . '}';
}

/* ---------- Logo-Speicher ---------- */

/** Speicherordner des Mandanten (Volume /data im Container) */
function opsStorageDir(string $sub = ''): string
{
    $base = rtrim(opsEnv('OPSPILOT_STORAGE_DIR', dirname(__DIR__) . '/storage'), '/');
    $dir = $base . '/' . opsCurrentTenant()['slug'] . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}
