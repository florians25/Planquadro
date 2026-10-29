<?php
/**
 * Planquadro – Backup-Scheduler (Dauerprozess, z. B. Compose-Dienst "backup")
 * --------------------------------------------------------------------------
 *   php bin/scheduler.php            Endlosschleife
 *   php bin/scheduler.php once       fällige Aufgaben einmal prüfen/ausführen
 *   php bin/scheduler.php health     Exit 0, wenn der Scheduler lebt (Docker-Healthcheck)
 *
 * Aufgaben (Zeiten in der Zeitzone von PHP, im Image PQ_TIMEZONE):
 *   - tägliches Backup aller Bereiche um PQ_BACKUP_TIME (Standard 02:30)
 *   - wöchentlicher Restore-Test am PQ_RESTORE_TEST_DAY (1 = Mo … 7 = So,
 *     Standard 7) um PQ_RESTORE_TEST_TIME (Standard 04:30) – nur, wenn ein
 *     privater Schlüssel konfiguriert ist (siehe docs/BACKUP.md, „Restore-Test“)
 *   - stündliche Prüfung auf veraltete Backups (> PQ_BACKUP_MAX_AGE_HOURS)
 *     mit Alarm per Webhook/Mail
 *   - stündlich: abgelaufene Sitzungen löschen (nur PQ_SESSION_STORE=db,
 *     alle Mandanten und Plattform-DB; zusätzlich zur GC von PHP)
 *
 *   php bin/scheduler.php sessions-gc   Sitzungen sofort aufräumen (Anzahl je Bereich)
 * Prüfintervall: PQ_SCHEDULER_INTERVAL Sekunden (Standard 60).
 * PQ_BACKUP_ENABLED=0 schaltet die Backups ab (z. B. auf Testsystemen).
 *
 * Ohne Docker: stattdessen cron oder systemd-Timer (deploy/systemd/, docs/BACKUP.md).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/lib/Backup.php';
require_once __DIR__ . '/../app/lib/SessionStore.php';
set_time_limit(0);

$cmd = $argv[1] ?? 'loop';
$interval = max(10, (int)opsEnv('PQ_SCHEDULER_INTERVAL', '60'));
$stateFile = Backup::stateDir() . '/scheduler.json';
$beat = Backup::stateDir() . '/scheduler.heartbeat';

function logLine(string $s): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $s . PHP_EOL); }

function hm(string $v, string $def): array
{
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $m)) { [$h, $mi] = explode(':', $def); return [(int)$h, (int)$mi]; }
    return [(int)$m[1], (int)$m[2]];
}

if ($cmd === 'health') {
    $age = is_file($beat) ? time() - filemtime($beat) : PHP_INT_MAX;
    if ($age > 3 * $interval + 30) { fwrite(STDERR, "Scheduler-Heartbeat fehlt oder ist {$age}s alt\n"); exit(1); }
    exit(0);
}

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) pcntl_signal($sig, function () use (&$stop) { $stop = true; });
}

/** Abgelaufene DB-Sitzungen aller Bereiche löschen. @return array<string,int> */
function sessionsGc(): array
{
    $out = [];
    foreach ((new Backup())->scopes() as $sc) {
        try {
            $out[$sc['scope']] = opsSessionGc(BackupDb::pdo($sc['conn'], $sc['db']));
        } catch (PDOException $e) {
            /* 1146: Tabelle fehlt (Migration noch nicht eingespielt) */
            if (($e->errorInfo[1] ?? 0) !== 1146) logLine("Sitzungen {$sc['scope']}: FEHLER " . $e->getMessage());
        }
    }
    return $out;
}

function tick(string $stateFile): void
{
    $state = is_readable($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
    $now = time();
    $today = date('Y-m-d', $now);
    $b = new Backup();
    $b->log = fn($s) => logLine($s);

    if (opsEnv('PQ_BACKUP_ENABLED', '1') !== '0') {
        [$h, $m] = hm(opsEnv('PQ_BACKUP_TIME', '02:30'), '02:30');
        $due = mktime($h, $m, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        if ($now >= $due && ($state['last_backup_day'] ?? '') !== $today) {
            logLine('Tägliches Backup startet');
            $state['last_backup_day'] = $today;   /* auch bei Fehlern nicht im Minutentakt wiederholen */
            file_put_contents($stateFile, json_encode($state));
            try {
                foreach ($b->run(null, 'scheduled') as $r) logLine("Backup {$r['scope']}: {$r['status']} " . ($r['archive'] ?? $r['error'] ?? ''));
            } catch (Throwable $e) { logLine('Backup FEHLER: ' . $e->getMessage()); }
        }

        $day = max(1, min(7, (int)opsEnv('PQ_RESTORE_TEST_DAY', '7')));
        [$h, $m] = hm(opsEnv('PQ_RESTORE_TEST_TIME', '04:30'), '04:30');
        $due = mktime($h, $m, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        if ((int)date('N', $now) === $day && $now >= $due && ($state['last_restore_test_day'] ?? '') !== $today) {
            $state['last_restore_test_day'] = $today;
            file_put_contents($stateFile, json_encode($state));
            if (opsEnv('PQ_BACKUP_PRIVATE_KEY') === '') {
                logLine('Restore-Test übersprungen: kein privater Schlüssel auf diesem System (empfohlen: Restore-Test auf separatem System, docs/BACKUP.md).');
            } else {
                try {
                    $key = Backup::privateKey();
                    foreach ($b->restoreTest($key) as $r) logLine("Restore-Test {$r['scope']}: {$r['status']} " . ($r['message'] ?? $r['error'] ?? ''));
                } catch (Throwable $e) { logLine('Restore-Test FEHLER: ' . $e->getMessage()); }
            }
        }
    }

    $hour = date('Y-m-d H', $now);
    if (($state['last_stale_check'] ?? '') !== $hour) {
        $state['last_stale_check'] = $hour;
        try { foreach ($b->checkStale() as $s) logLine("Alarm gesendet: Backup veraltet ($s)"); }
        catch (Throwable $e) { logLine('Statusprüfung FEHLER: ' . $e->getMessage()); }
        if (opsSessionStore() === 'db') {
            try { $n = array_sum(sessionsGc()); if ($n > 0) logLine("Abgelaufene Sitzungen gelöscht: $n"); }
            catch (Throwable $e) { logLine('Sitzungs-GC FEHLER: ' . $e->getMessage()); }
        }
    }
    file_put_contents($stateFile, json_encode($state));
}

if ($cmd === 'sessions-gc') {
    foreach (sessionsGc() as $scope => $n) fwrite(STDOUT, "$scope: $n\n");
    exit(0);
}
if ($cmd === 'once') { touch($beat); tick($stateFile); exit(0); }
if ($cmd !== 'loop') { fwrite(STDERR, "Aufruf: php bin/scheduler.php [loop|once|health|sessions-gc]\n"); exit(2); }

logLine(sprintf('Scheduler gestartet (Backup %s, Restore-Test Tag %s %s, Intervall %ds, Zeitzone %s)',
    opsEnv('PQ_BACKUP_TIME', '02:30'), opsEnv('PQ_RESTORE_TEST_DAY', '7'), opsEnv('PQ_RESTORE_TEST_TIME', '04:30'),
    $interval, date_default_timezone_get()));
while (!$stop) {
    touch($beat);
    try { tick($stateFile); } catch (Throwable $e) { logLine('FEHLER: ' . $e->getMessage()); }
    for ($i = 0; $i < $interval && !$stop; $i++) sleep(1);
}
logLine('Scheduler beendet');
