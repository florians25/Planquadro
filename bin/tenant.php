<?php
/**
 * Planquadro – Mandantenverwaltung (SaaS, PQ_MODE=saas)
 * --------------------------------------------------------------------------
 *   php bin/tenant.php list
 *   php bin/tenant.php create <slug> "<Name>" [--domain=acme.example.org] [--admin=admin]
 *   php bin/tenant.php adopt <slug> "<Name>" <datenbank> [--domain=host]
 *                                    bestehende Einzel-Installation als Mandant übernehmen
 *   php bin/tenant.php suspend <slug> | activate <slug>
 *   php bin/tenant.php domain-add <slug> <host> | domain-rm <host>
 *   php bin/tenant.php platform-admin <username>       legt Plattform-Admin an / setzt Passwort neu
 *
 * "create" legt die Datenbank an, spielt das Schema ein und erzeugt einen
 * ersten Administrator mit Zufallspasswort (wird einmal angezeigt).
 * Mandanten werden nie automatisch gelöscht; "deleted" ist nur eine Markierung.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/config/database.php';

$opts = []; $args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true; else $args[] = $a;
}
$cmd = $args[0] ?? 'list';
function say(string $s): void { fwrite(STDOUT, $s . PHP_EOL); }
function die2(string $s, int $c = 1): void { fwrite(STDERR, $s . PHP_EOL); exit($c); }
function audit(string $action, string $details): void {
    opsPlatformDB()->prepare('INSERT INTO platform_audit(admin, action, details) VALUES(?,?,?)')
        ->execute(['cli:' . (get_current_user() ?: 'unknown'), $action, mb_substr($details, 0, 500)]);
}
function tenantBySlug(string $slug): array {
    $s = opsPlatformDB()->prepare('SELECT * FROM tenants WHERE slug=?'); $s->execute([$slug]);
    $t = $s->fetch(); if (!$t) die2("Mandant '$slug' nicht gefunden."); return $t;
}
function randomPassword(): string { return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '='); }

if (opsMode() !== 'saas') die2('Mandantenverwaltung nur im SaaS-Modus (PQ_MODE=saas).', 2);
$pdb = opsPlatformDB();

switch ($cmd) {
case 'list':
    $rows = $pdb->query("SELECT t.slug, t.name, t.status, t.db_name, GROUP_CONCAT(d.host) hosts FROM tenants t LEFT JOIN tenant_domains d ON d.tenant_id=t.id GROUP BY t.id ORDER BY t.id")->fetchAll();
    foreach ($rows as $r) say(sprintf('%-20s %-10s %-28s %s  [%s]', $r['slug'], $r['status'], $r['db_name'], $r['name'], $r['hosts'] ?? ''));
    exit(0);

case 'create':
    [$slug, $name] = [$args[1] ?? '', $args[2] ?? ''];
    if ($slug === '' || $name === '') die2('Aufruf: create <slug> "<Name>" [--domain=host] [--admin=benutzer]');
    $t = opsCreateTenant($slug, $name, is_string($opts['domain'] ?? null) ? $opts['domain'] : null);
    say("Mandant {$t['slug']} angelegt (Datenbank {$t['db_name']}). Schema wird eingespielt …");
    $p = proc_open([PHP_BINARY, __DIR__ . '/migrate.php', 'up', '--tenant=' . $slug], [1 => STDOUT, 2 => STDERR], $pipes);
    if (proc_close($p) !== 0) die2('Migration fehlgeschlagen – Mandant bleibt angelegt, bitte prüfen.');
    /* Erster Administrator in der Mandanten-DB */
    $tdb = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, (int)DB_PORT, $t['db_name']), DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin = is_string($opts['admin'] ?? null) ? $opts['admin'] : 'admin';
    $pw = randomPassword();
    $tdb->prepare("INSERT INTO users(username,password_hash,first_name,last_name,role,vacation_days,must_change_password) VALUES(?,?,?,?,?,30,1)")
        ->execute([$admin, password_hash($pw, PASSWORD_DEFAULT), 'Mandanten', 'Administrator', 'admin']);
    $tdb->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('company_name',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$name]);
    audit('tenant_create', "$slug ($name)");
    say("Administrator: $admin · Einmalpasswort: $pw  (Wechsel beim ersten Login erzwungen)");
    exit(0);

case 'adopt':
    [$slug, $name, $dbName] = [$args[1] ?? '', $args[2] ?? '', $args[3] ?? ''];
    if (!opsValidSlug($slug) || $name === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) die2('Aufruf: adopt <slug> "<Name>" <datenbank> [--domain=host]');
    $chk = $pdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name IN ('users','assignments','plan_areas','schema_migrations')");
    $chk->execute([$dbName]);
    $n = (int)$chk->fetchColumn();
    if ($n < 3) die2("Datenbank $dbName sieht nicht wie eine Planquadro-/Einsatzplanung-Datenbank aus.");
    if ($n < 4) die2("Datenbank $dbName hat keine Versionstabelle. Zuerst im Self-Hosting-Modus: php bin/migrate.php baseline v6_9_0 && php bin/migrate.php up");
    $s = $pdb->prepare('SELECT COUNT(*) FROM tenants WHERE slug=? OR db_name=?'); $s->execute([$slug, $dbName]);
    if ((int)$s->fetchColumn() > 0) die2('Slug oder Datenbank ist bereits einem Mandanten zugeordnet.');
    $pdb->prepare("INSERT INTO tenants(slug, name, db_name, status) VALUES(?,?,?,'active')")->execute([$slug, $name, $dbName]);
    $id = (int)$pdb->lastInsertId();
    if (is_string($opts['domain'] ?? null)) $pdb->prepare('INSERT INTO tenant_domains(host, tenant_id) VALUES(?,?)')->execute([strtolower($opts['domain']), $id]);
    audit('tenant_adopt', "$slug ← $dbName");
    say("Datenbank $dbName ist jetzt Mandant $slug. Daten und Benutzer bleiben unverändert.");
    exit(0);

case 'suspend':
case 'activate':
    $t = tenantBySlug($args[1] ?? '');
    $pdb->prepare('UPDATE tenants SET status=? WHERE id=?')->execute([$cmd === 'suspend' ? 'suspended' : 'active', $t['id']]);
    audit('tenant_' . $cmd, $t['slug']);
    say("Mandant {$t['slug']}: " . ($cmd === 'suspend' ? 'gesperrt' : 'aktiv'));
    exit(0);

case 'domain-add':
    $t = tenantBySlug($args[1] ?? '');
    $host = strtolower($args[2] ?? '');
    if (!preg_match('/^[a-z0-9.-]+$/', $host)) die2('Ungültiger Hostname');
    $pdb->prepare('INSERT INTO tenant_domains(host, tenant_id) VALUES(?,?)')->execute([$host, $t['id']]);
    audit('domain_add', "$host → {$t['slug']}");
    say("$host → {$t['slug']}");
    exit(0);

case 'domain-rm':
    $pdb->prepare('DELETE FROM tenant_domains WHERE host=?')->execute([strtolower($args[1] ?? '')]);
    audit('domain_rm', (string)($args[1] ?? ''));
    say('entfernt');
    exit(0);

case 'platform-admin':
    $u = $args[1] ?? '';
    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $u)) die2('Aufruf: platform-admin <benutzername>');
    $pw = randomPassword();
    $pdb->prepare('INSERT INTO platform_admins(username,password_hash) VALUES(?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), active=1')
        ->execute([$u, password_hash($pw, PASSWORD_DEFAULT)]);
    audit('platform_admin_set', $u);
    say("Plattform-Admin $u · Passwort: $pw");
    exit(0);

default:
    die2("Unbekannter Befehl: $cmd");
}
