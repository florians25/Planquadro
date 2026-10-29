<?php
/**
 * OpsPilot – Backup & Recovery (CLI)
 * --------------------------------------------------------------------------
 *   php bin/backup.php keygen [--out=<ordner>]      Schlüsselpaar erzeugen
 *   php bin/backup.php run [--tenant=<slug>] [--kind=manual]
 *   php bin/backup.php list [--tenant=<slug>] [--json]
 *   php bin/backup.php verify <archiv> [--key=<privater-schlüssel>]
 *   php bin/backup.php restore <archiv> --target-db=<name> [--tenant=<slug>] [--files] [--files-dir=<ordner>]
 *                              [--replace --yes] [--key=<datei>]
 *   php bin/backup.php restore-test [--tenant=<slug>] [--key=<datei>]
 *   php bin/backup.php status [--check]              JSON; --check: Exit 3, wenn nicht gesund
 *   php bin/backup.php prune [--tenant=<slug>]       nur Aufbewahrungsregeln anwenden
 *   php bin/backup.php decrypt <archiv> --out=<datei.tar> [--key=<datei>]
 *   php bin/backup.php legacy-decrypt <datei.epbak> <schlüsseldatei> [--out=<datei.sql>]
 *
 * <archiv> = Pfad oder Archivname (wird in den Zielen gesucht, lokal zuerst).
 * Der private Schlüssel wird nur für verify/restore/restore-test/decrypt
 * gebraucht: --key=<datei> oder OPSPILOT_BACKUP_PRIVATE_KEY_FILE.
 * Ausführliche Beschreibung: docs/BACKUP.md
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$opts = []; $args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true; else $args[] = $a;
}
$cmd = $args[0] ?? 'help';

function say(string $s): void { fwrite(STDOUT, $s . PHP_EOL); }
function bail(string $s, int $c = 1): void { fwrite(STDERR, $s . PHP_EOL); exit($c); }
function opt(array $o, string $k): ?string { return isset($o[$k]) && is_string($o[$k]) && $o[$k] !== '' ? $o[$k] : null; }

/* Befehle ohne Datenbank */
if ($cmd === 'keygen') {
    require_once __DIR__ . '/../app/lib/Backup.php';
    $k = BackupCrypto::keygen();
    if ($dir = opt($opts, 'out')) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) bail("Kann $dir nicht anlegen.");
        foreach (['backup_public.key' => $k['public'], 'backup_private.key' => $k['secret']] as $f => $v) {
            if (file_exists("$dir/$f")) bail("$dir/$f existiert bereits – nicht überschrieben.");
            file_put_contents("$dir/$f", $v . "\n");
            chmod("$dir/$f", $f === 'backup_private.key' ? 0600 : 0644);
        }
        say("Öffentlicher Schlüssel: $dir/backup_public.key   (auf den Server: OPSPILOT_BACKUP_PUBLIC_KEY_FILE)");
        say("Privater Schlüssel:     $dir/backup_private.key  (OFFLINE aufbewahren, NICHT auf dem Server lassen!)");
        say('Fingerprint: ' . BackupCrypto::fingerprint(BackupCrypto::parsePublic($k['public'])));
        exit(0);
    }
    say('# Öffentlicher Schlüssel – auf den Server (OPSPILOT_BACKUP_PUBLIC_KEY bzw. _FILE):');
    say($k['public']);
    say('');
    say('# PRIVATER Schlüssel – nur für Restore. OFFLINE aufbewahren (Passwortmanager, Tresor, Ausdruck),');
    say('# niemals auf dem Server, in Git oder im Backup-Ziel speichern. Ohne ihn sind Backups nicht lesbar.');
    say($k['secret']);
    say('');
    say('# Fingerprint: ' . BackupCrypto::fingerprint(BackupCrypto::parsePublic($k['public'])));
    exit(0);
}

if ($cmd === 'legacy-decrypt') {
    require_once __DIR__ . '/../app/lib/Backup.php';
    if (count($args) < 3) bail('Aufruf: legacy-decrypt <datei.epbak> <schlüsseldatei> [--out=<datei.sql>]');
    try { $sql = Backup::legacyDecrypt($args[1], $args[2]); } catch (BackupException $e) { bail($e->getMessage()); }
    fwrite(STDERR, "Hinweis: Das Alt-Format (AES-CBC ohne MAC) hat keinen Integritätsschutz. Inhalt vor dem Einspielen prüfen.\n");
    if ($out = opt($opts, 'out')) { file_put_contents($out, $sql); chmod($out, 0600); fwrite(STDERR, "SQL geschrieben: $out\n"); }
    else fwrite(STDOUT, $sql);
    exit(0);
}

if ($cmd === 'help' || $cmd === '--help') {
    $doc = file_get_contents(__FILE__);
    preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
    say(preg_replace('/^\s*\* ?/m', '', $m[1]));
    exit(0);
}

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/lib/Backup.php';
@ini_set('memory_limit', '1024M');
set_time_limit(0);

$b = new Backup();
$b->log = function (string $s) { fwrite(STDERR, $s . PHP_EOL); };
$tenant = opt($opts, 'tenant');
$keyFile = opt($opts, 'key');

try {
    switch ($cmd) {
    case 'run':
        $kind = opt($opts, 'kind') ?? 'scheduled';
        if (!in_array($kind, ['scheduled', 'manual'], true)) bail('--kind=scheduled|manual');
        $res = $b->run($tenant, $kind);
        $failed = 0;
        foreach ($res as $r) {
            if ($r['status'] === 'failed') $failed++;
            say(sprintf('%-20s %-8s %s%s', $r['scope'], strtoupper($r['status']), $r['archive'] ?? ($r['error'] ?? ''),
                !empty($r['targets']) ? '  [' . implode(', ', $r['targets']) . ']' : ''));
            foreach ($r['warnings'] ?? [] as $w) say("    Warnung: $w");
        }
        exit($failed ? 1 : 0);

    case 'list':
        $all = $b->listArchives($tenant);
        if (!empty($opts['json'])) { say(json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); exit(0); }
        foreach ($all as $target => $rows) {
            say("== $target");
            if (isset($rows['error'])) { say('   FEHLER: ' . $rows['error']); continue; }
            if (!$rows) say('   (keine Archive)');
            foreach ($rows as $r) say(sprintf('   %-62s %10s  %s', $r['key'], Backup::fmtBytes($r['size']), gmdate('Y-m-d H:i', $r['time']) . 'Z'));
        }
        exit(0);

    case 'verify':
        if (empty($args[1])) bail('Aufruf: verify <archiv> [--key=<datei>]');
        $m = $b->verify($args[1], Backup::privateKey($keyFile));
        say('OK – Archiv authentisch und vollständig.');
        say(sprintf('Bereich %s, erstellt %s, Datenbank %s, %d Tabellen, %d Zeilen, %d Dateien, Dump: %s',
            $m['scope'], $m['created_at'], $m['database']['name'], count($m['database']['tables']), array_sum($m['database']['tables']),
            $m['files']['count'] ?? 0, $m['database']['dump_method']));
        exit(0);

    case 'decrypt':
        if (empty($args[1]) || !($out = opt($opts, 'out'))) bail('Aufruf: decrypt <archiv> --out=<datei.tar>');
        [$path, $temp] = $b->fetchArchive($args[1]);
        $in = fopen($path, 'rb'); $fo = fopen($out, 'xb');
        if (!$fo) bail("$out existiert bereits oder ist nicht beschreibbar.");
        chmod($out, 0600);
        $r = new BackupDecReader($in, Backup::privateKey($keyFile));
        while (($c = $r->read()) !== null) fwrite($fo, $c);
        fclose($fo); fclose($in);
        if ($temp) unlink($path);
        say("Entschlüsselt (tar mit manifest.json, db.sql.gz, files.tar.gz): $out");
        exit(0);

    case 'restore':
        if (empty($args[1])) bail('Aufruf: restore <archiv> --target-db=<name> [--tenant=<slug>] [--files] [--replace --yes]');
        $r = $b->restore($args[1], Backup::privateKey($keyFile), [
            'target_db' => opt($opts, 'target-db') ?? '', 'tenant' => $tenant, 'files' => !empty($opts['files']),
            'files_dir' => opt($opts, 'files-dir'), 'replace' => !empty($opts['replace']), 'yes' => !empty($opts['yes']),
        ]);
        say(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (empty($r['row_check']['ok'])) bail('WARNUNG: Zeilenzahlen weichen vom Manifest ab.', 1);
        say('Restore abgeschlossen.');
        exit(0);

    case 'restore-test':
        $res = $b->restoreTest(Backup::privateKey($keyFile), $tenant);
        $failed = 0;
        foreach ($res as $r) {
            if ($r['status'] !== 'ok') $failed++;
            say(sprintf('%-20s %-6s %s', $r['scope'], strtoupper($r['status']), $r['message'] ?? $r['error'] ?? ''));
        }
        exit($failed ? 1 : 0);

    case 'status':
        $st = $b->status();
        if (!empty($opts['alert'])) $st['alerts_sent'] = $b->checkStale();
        say(json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        exit(!empty($opts['check']) && !$st['healthy'] ? 3 : 0);

    case 'prune':
        foreach ($b->scopes() as $s) {
            if ($tenant !== null && $s['scope'] !== $tenant) continue;
            foreach ($b->targets() as $t) {
                $del = $b->applyRetention($t, $s['scope']);
                say(sprintf('%-20s %-7s %d gelöscht', $s['scope'], $t->name(), count($del)));
            }
        }
        exit(0);

    default:
        bail("Unbekannter Befehl: $cmd (php bin/backup.php help)");
    }
} catch (BackupException $e) {
    bail('FEHLER: ' . $e->getMessage());
} catch (PDOException $e) {
    bail('Datenbankfehler: ' . $e->getMessage());
}
