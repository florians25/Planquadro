<?php
/**
 * OpsPilot – Migrationsstand (nur lesend)
 * --------------------------------------------------------------------------
 * Liefert die Versionen, die bin/migrate.php kennt, und vergleicht sie mit der
 * Tabelle schema_migrations einer Datenbank. Verwendet von /api/readyz und
 * dem Backup-Manifest. Die Namensregeln entsprechen allMigrations() in
 * bin/migrate.php (0000_base, migrate_v*, op_*, pf_*).
 */

/** @return string[] erwartete Versionen in Ausführungsreihenfolge */
function opsExpectedMigrations(bool $platform = false): array
{
    $root = dirname(__DIR__, 2);
    if ($platform) {
        $files = glob($root . '/migrations/platform/*.sql') ?: [];
        sort($files, SORT_STRING);
        return array_map(fn($f) => 'pf_' . basename($f, '.sql'), $files);
    }
    $list = ['0000_base'];
    $legacy = glob($root . '/migrations/migrate_v*.sql') ?: [];
    usort($legacy, fn($a, $b) => strnatcmp(basename($a), basename($b)));
    foreach ($legacy as $f) $list[] = basename($f, '.sql');
    $new = glob($root . '/migrations/opspilot/*.sql') ?: [];
    sort($new, SORT_STRING);
    foreach ($new as $f) $list[] = 'op_' . basename($f, '.sql');
    return $list;
}

/** Angewendete Versionen (leer, wenn es keine Versionstabelle gibt). */
function opsAppliedMigrations(PDO $db): array
{
    $s = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'");
    if ((int)$s->fetchColumn() === 0) return [];
    return $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
}

/** Offene Migrationen einer Datenbank. */
function opsPendingMigrations(PDO $db, bool $platform = false): array
{
    return array_values(array_diff(opsExpectedMigrations($platform), opsAppliedMigrations($db)));
}
