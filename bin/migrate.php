<?php
/**
 * OpsPilot – Datenbank-Migrationen
 * --------------------------------------------------------------------------
 * Aufruf (CLI):
 *   php bin/migrate.php status                 zeigt angewendete/offene Migrationen
 *   php bin/migrate.php up                     wendet alle offenen Migrationen an
 *   php bin/migrate.php baseline v6_9_0        markiert eine BESTEHENDE Installation
 *                                              bis einschließlich dieser Altversion als
 *                                              migriert, ohne SQL auszuführen
 *
 * Quellen in dieser Reihenfolge:
 *   1. database.sql                    Basisschema – nur bei leerer Datenbank
 *   2. migrations/migrate_v*.sql       Alt-Migrationen (Einsatzplanung bis v6.9.0),
 *                                      tolerant: "Spalte/Index existiert schon" wird
 *                                      übersprungen, wie bisher mit "mysql --force"
 *   3. migrations/opspilot/*.sql       neue Migrationen, strikt (Fehler = Abbruch)
 *
 * Warum "baseline": Die Alt-Migrationen sind NICHT gefahrlos wiederholbar
 * (z. B. setzen migrate_v6_3_1/v6_9_0 Rollenrechte zurück, v5_2_7 verschiebt
 * Datensätze). Eine bestehende Installation darf sie deshalb nicht erneut
 * durchlaufen. Ohne Versionstabelle verweigert "up" daher die Arbeit, bis
 * einmalig "baseline" ausgeführt wurde.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/lib/SqlSplitter.php';

$root = dirname(__DIR__);
$cmd  = $argv[1] ?? 'status';
$db   = getDB();
/* Textprotokoll: CREATE PROCEDURE & Co. sind als Prepared Statement nicht erlaubt. */
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

/* Fehlercodes, die bei Alt-Migrationen als "bereits erledigt" gelten. */
const LEGACY_TOLERATED = [
    1050, // Table already exists
    1060, // Duplicate column name
    1061, // Duplicate key name
    1062, // Duplicate entry
    1091, // Can't DROP; check that column/key exists
    1826, // Duplicate foreign key constraint name
    1054, // Unknown column (alte Datenübernahmen aus nie vorhandenen Spalten)
];

function out(string $s): void { fwrite(STDOUT, $s . PHP_EOL); }
function fail(string $s, int $code = 1): void { fwrite(STDERR, $s . PHP_EOL); exit($code); }

function tableExists(PDO $db, string $t): bool {
    $s = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $s->execute([$t]);
    return (int)$s->fetchColumn() > 0;
}

function ensureVersionTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `version`    VARCHAR(190) NOT NULL PRIMARY KEY,
        `kind`       ENUM('base','legacy','opspilot') NOT NULL,
        `checksum`   CHAR(64) NOT NULL,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `executed`   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = per baseline markiert'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Alle Migrationen in Ausführungsreihenfolge: [version => [kind, path]] */
function allMigrations(string $root): array {
    $list = ['0000_base' => ['base', $root . '/database.sql']];
    $legacy = glob($root . '/migrations/migrate_v*.sql') ?: [];
    usort($legacy, function ($a, $b) { return strnatcmp(basename($a), basename($b)); });
    foreach ($legacy as $f) $list[basename($f, '.sql')] = ['legacy', $f];
    $new = glob($root . '/migrations/opspilot/*.sql') ?: [];
    sort($new, SORT_STRING);
    foreach ($new as $f) $list['op_' . basename($f, '.sql')] = ['opspilot', $f];
    return $list;
}

function appliedMigrations(PDO $db): array {
    if (!tableExists($db, 'schema_migrations')) return [];
    $m = [];
    foreach ($db->query('SELECT version, checksum, executed FROM schema_migrations') as $r) $m[$r['version']] = $r;
    return $m;
}

function baseSql(string $sql): string {
    /* Das Basisschema enthält CREATE DATABASE/USE und ein DROP TABLE – auf die
       konfigurierte Datenbank anwenden und niemals Tabellen löschen. */
    $sql = preg_replace('/^\s*CREATE DATABASE[^;]*;/mi', '', $sql);
    $sql = preg_replace('/^\s*USE\s+[^;]*;/mi', '', $sql);
    $sql = preg_replace('/^\s*DROP TABLE[^;]*;/mi', '', $sql);
    return $sql;
}

function runFile(PDO $db, string $version, string $kind, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) fail("Kann $path nicht lesen");
    if ($kind === 'base') $sql = baseSql($sql);
    $skipped = 0; $n = 0;
    foreach (SqlSplitter::split($sql) as $stmt) {
        $n++;
        try {
            /* Manche Statements (OPTIMIZE, CALL) liefern Ergebnismengen, die
               vollständig gelesen werden müssen. */
            $st = $db->query($stmt);
            if ($st) { do { try { $st->fetchAll(); } catch (PDOException $e) {} } while ($st->nextRowset()); $st->closeCursor(); }
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if ($kind === 'legacy' && in_array($code, LEGACY_TOLERATED, true)) { $skipped++; continue; }
            fail("FEHLER in $version, Statement $n (MySQL $code): " . $e->getMessage() . "\n" . mb_substr($stmt, 0, 400));
        }
    }
    $db->prepare('INSERT INTO schema_migrations(version, kind, checksum, executed) VALUES(?,?,?,1)')
       ->execute([$version, $kind, hash_file('sha256', $path)]);
    out(sprintf('  angewendet: %-40s %d Statements%s', $version, $n, $skipped ? " ($skipped bereits vorhanden)" : ''));
}

$all = allMigrations($root);

switch ($cmd) {
case 'status':
    $applied = appliedMigrations($db);
    if (!$applied && tableExists($db, 'users')) {
        out('Bestehende Installation ohne Versionstabelle. Zuerst einmalig ausführen:');
        out('  php bin/migrate.php baseline <letzte eingespielte Altversion, z. B. v6_9_0>');
    }
    foreach ($all as $v => [$kind, $path]) {
        $st = isset($applied[$v]) ? ($applied[$v]['executed'] ? 'angewendet' : 'baseline') : 'OFFEN';
        if (isset($applied[$v]) && $applied[$v]['executed'] && $applied[$v]['checksum'] !== hash_file('sha256', $path) && $kind === 'opspilot') {
            $st .= ' (DATEI GEÄNDERT!)';
        }
        out(sprintf('%-12s %-9s %s', $st, $kind, $v));
    }
    exit(0);

case 'baseline':
    $upTo = $argv[2] ?? '';
    if (!preg_match('/^v\d+(_\d+)*$/', $upTo)) fail('Aufruf: php bin/migrate.php baseline v6_9_0');
    if (!tableExists($db, 'users')) fail('Leere Datenbank – "baseline" ist nur für bestehende Installationen. Stattdessen "up" verwenden.');
    ensureVersionTable($db);
    $applied = appliedMigrations($db);
    $ins = $db->prepare('INSERT IGNORE INTO schema_migrations(version, kind, checksum, executed) VALUES(?,?,?,0)');
    $count = 0;
    foreach ($all as $v => [$kind, $path]) {
        if ($kind === 'opspilot') continue;
        if ($kind === 'legacy') {
            $ver = preg_replace('/^migrate_(v[\d_]+?)(_[a-z].*)?$/', '$1', $v);
            if (strnatcmp($ver, $upTo) > 0) continue;
        }
        if (isset($applied[$v])) continue;
        $ins->execute([$v, $kind, hash_file('sha256', $path)]);
        $count++;
    }
    out("$count Migrationen als bereits eingespielt markiert (bis einschließlich $upTo).");
    out('Weiter mit: php bin/migrate.php up');
    exit(0);

case 'up':
    $fresh = !tableExists($db, 'users');
    if (!$fresh && !tableExists($db, 'schema_migrations')) {
        fail("Bestehende Installation ohne Versionstabelle erkannt.\n"
           . "Die Alt-Migrationen dürfen NICHT erneut laufen (sie setzen u. a. Rollenrechte zurück).\n"
           . "Bitte zuerst ein Backup erstellen und dann einmalig ausführen:\n"
           . "  php bin/migrate.php baseline v6_9_0\n"
           . "(Version = zuletzt eingespielte migrate_v*.sql)", 2);
    }
    ensureVersionTable($db);
    $applied = appliedMigrations($db);
    $pending = array_diff_key($all, $applied);
    if (!$fresh) unset($pending['0000_base']);
    if (!$pending) { out('Datenbank ist aktuell.'); exit(0); }
    $lock = $db->query("SELECT GET_LOCK('opspilot_migrate', 30)")->fetchColumn();
    if ((int)$lock !== 1) fail('Eine andere Migration läuft bereits.');
    out(count($pending) . ' offene Migration(en):');
    foreach ($pending as $v => [$kind, $path]) runFile($db, $v, $kind, $path);
    $db->query("SELECT RELEASE_LOCK('opspilot_migrate')");
    out('Fertig.');
    exit(0);

default:
    fail("Unbekannter Befehl: $cmd (status | up | baseline <version>)");
}
