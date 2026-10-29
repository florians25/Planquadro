<?php
/**
 * Planquadro – Backup & Recovery
 * --------------------------------------------------------------------------
 * Bausteine (alle in dieser Datei, ohne externe Abhängigkeiten außer ext-sodium,
 * ext-zlib, ext-curl und optional mariadb-dump/mysqldump, mariadb/mysql, rclone):
 *
 *   BackupCrypto     Public-Key-Verschlüsselung (libsodium): Sealed Box für einen
 *                    zufälligen Datenschlüssel + secretstream XChaCha20-Poly1305
 *                    für den Inhalt (authentisiert, gestückelt, Abschneiden wird erkannt)
 *   BackupTar        minimaler tar-Writer/-Reader (GNU-Format, nur Dateien/Ordner)
 *   BackupDumper     konsistenter SQL-Dump (mariadb-dump/mysqldump --single-transaction
 *                    oder reiner PHP-Dumper in START TRANSACTION WITH CONSISTENT SNAPSHOT)
 *   BackupSqlLoader  Einspielen eines Dumps; bricht beim ersten Fehler ab
 *   BackupLocalTarget / BackupS3Target / BackupRcloneTarget   Ablageziele
 *   BackupRetention  GFS-Auswahl (täglich/wöchentlich/monatlich)
 *   BackupStatus*    Protokoll der Läufe (Plattform-DB backup_runs bzw. status.json)
 *   BackupAlerter    Webhook/Mail bei Fehlern oder veralteten Backups
 *   Backup           Ablauf: run, list, verify, restore, restore-test, status
 *
 * Archivformat: siehe docs/BACKUP.md („Archivformat“).
 */

require_once __DIR__ . '/Tenancy.php';
require_once __DIR__ . '/SqlSplitter.php';
require_once __DIR__ . '/Migrations.php';

final class BackupException extends RuntimeException {}

/* ======================================================================
   Verschlüsselung
   ====================================================================== */

final class BackupCrypto
{
    public const MAGIC = "PQBAK\x00\x01\n";          /* 8 Byte, Version 1 */
    public const CHUNK = 1048576;                   /* Klartext je Block */
    public const PUB_PREFIX = 'planquadro-backup-pk-v1:';
    public const SEC_PREFIX = 'planquadro-backup-sk-v1:';
    public const HEADER_LEN = 8 + 8 + 80 + 24;      /* Magic, Fingerprint, Sealed Key, Stream-Header */
    public const SCHEME = 'sodium-sealedbox-x25519+secretstream-xchacha20poly1305';

    /** @return array{public:string, secret:string} */
    public static function keygen(): array
    {
        $kp = sodium_crypto_box_keypair();
        $pk = sodium_crypto_box_publickey($kp);
        $sk = sodium_crypto_box_secretkey($kp);
        $r = ['public' => self::PUB_PREFIX . base64_encode($pk), 'secret' => self::SEC_PREFIX . base64_encode($sk)];
        sodium_memzero($kp); sodium_memzero($sk);
        return $r;
    }

    public static function parsePublic(string $s): string
    {
        $s = trim($s);
        if (strpos($s, self::PUB_PREFIX) !== 0) throw new BackupException('Öffentlicher Backup-Schlüssel hat ein unbekanntes Format (erwartet ' . self::PUB_PREFIX . '…).');
        $pk = base64_decode(substr($s, strlen(self::PUB_PREFIX)), true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) throw new BackupException('Öffentlicher Backup-Schlüssel ist ungültig.');
        return $pk;
    }

    /** @return string Schlüsselpaar (secret||public) für sealed_box_open */
    public static function parseSecret(string $s): string
    {
        $s = trim($s);
        if (strpos($s, self::SEC_PREFIX) !== 0) throw new BackupException('Privater Backup-Schlüssel hat ein unbekanntes Format (erwartet ' . self::SEC_PREFIX . '…).');
        $sk = base64_decode(substr($s, strlen(self::SEC_PREFIX)), true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) throw new BackupException('Privater Backup-Schlüssel ist ungültig.');
        $pk = sodium_crypto_box_publickey_from_secretkey($sk);
        $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($sk, $pk);
        sodium_memzero($sk);
        return $kp;
    }

    public static function fingerprint(string $pk): string { return bin2hex(substr(hash('sha256', $pk, true), 0, 8)); }

    public static function publicFromSecret(string $keypair): string { return sodium_crypto_box_publickey($keypair); }
}

/** Schreibt einen verschlüsselten Strom in ein Datei-Handle. */
final class BackupEncWriter
{
    private $fh;
    private string $state;
    private string $ad;
    private string $buf = '';
    private $hash;
    public int $bytes = 0;
    private bool $done = false;

    public function __construct($fh, string $publicKey)
    {
        $this->fh = $fh;
        $this->hash = hash_init('sha256');
        $dataKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $sealed = sodium_crypto_box_seal($dataKey, $publicKey);
        [$this->state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dataKey);
        sodium_memzero($dataKey);
        $this->ad = BackupCrypto::MAGIC . substr(hash('sha256', $publicKey, true), 0, 8) . $sealed;
        $this->out($this->ad . $streamHeader);
    }

    private function out(string $d): void
    {
        $len = strlen($d);
        for ($off = 0; $off < $len;) {
            $w = fwrite($this->fh, $off === 0 ? $d : substr($d, $off));
            if ($w === false || $w === 0) throw new BackupException('Schreiben des Archivs fehlgeschlagen (Speicherplatz?).');
            $off += $w;
        }
        hash_update($this->hash, $d);
        $this->bytes += $len;
    }

    private function push(string $plain, int $tag): void
    {
        $c = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, $this->ad, $tag);
        $this->out(pack('N', strlen($c)) . $c);
    }

    public function write(string $data): void
    {
        $this->buf .= $data;
        while (strlen($this->buf) >= BackupCrypto::CHUNK) {
            $this->push(substr($this->buf, 0, BackupCrypto::CHUNK), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $this->buf = (string)substr($this->buf, BackupCrypto::CHUNK);
        }
    }

    /** @return string sha256 des Chiffrats (Hex) */
    public function finish(): string
    {
        if ($this->done) throw new BackupException('Archiv bereits abgeschlossen.');
        $this->push($this->buf, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $this->buf = '';
        $this->done = true;
        sodium_memzero($this->state);
        return hash_final($this->hash);
    }
}

/** Liest einen verschlüsselten Strom; jede Manipulation führt zu einer Ausnahme. */
final class BackupDecReader
{
    private $fh;
    private string $state;
    private string $ad;
    private bool $final = false;
    public string $fingerprint;

    public function __construct($fh, string $keypair)
    {
        $this->fh = $fh;
        $head = self::readExact($fh, BackupCrypto::HEADER_LEN, 'Archivkopf');
        if (substr($head, 0, 8) !== BackupCrypto::MAGIC) {
            throw new BackupException('Keine Planquadro-Backup-Datei (unbekannte Kennung). Alt-Backups (.epbak): php bin/backup.php legacy-decrypt');
        }
        $pk = BackupCrypto::publicFromSecret($keypair);
        $this->fingerprint = bin2hex(substr($head, 8, 8));
        if (!hash_equals(substr(hash('sha256', $pk, true), 0, 8), substr($head, 8, 8))) {
            throw new BackupException('Archiv wurde für einen anderen Schlüssel erstellt (Fingerprint ' . $this->fingerprint . ', Ihr Schlüssel ' . BackupCrypto::fingerprint($pk) . ').');
        }
        $sealed = substr($head, 16, 80);
        $dataKey = sodium_crypto_box_seal_open($sealed, $keypair);
        if ($dataKey === false) throw new BackupException('Datenschlüssel lässt sich nicht entschlüsseln (Archiv beschädigt oder falscher Schlüssel).');
        $this->ad = substr($head, 0, 96);
        $this->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($head, 96, 24), $dataKey);
        sodium_memzero($dataKey);
    }

    private static function readExact($fh, int $n, string $what): string
    {
        $d = '';
        while (strlen($d) < $n) {
            $c = fread($fh, $n - strlen($d));
            if ($c === false || $c === '') throw new BackupException("Archiv abgeschnitten ($what unvollständig).");
            $d .= $c;
        }
        return $d;
    }

    /** Nächster Klartextblock oder null am (authentisierten) Ende. */
    public function read(): ?string
    {
        if ($this->final) return null;
        $lenRaw = fread($this->fh, 4);
        if ($lenRaw === false || $lenRaw === '') throw new BackupException('Archiv abgeschnitten (Endmarkierung fehlt).');
        if (strlen($lenRaw) < 4) $lenRaw .= self::readExact($this->fh, 4 - strlen($lenRaw), 'Blocklänge');
        $len = unpack('N', $lenRaw)[1];
        if ($len < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $len > BackupCrypto::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
            throw new BackupException('Archiv beschädigt (ungültige Blocklänge).');
        }
        $c = self::readExact($this->fh, $len, 'Datenblock');
        $r = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $c, $this->ad);
        if ($r === false) throw new BackupException('Archiv beschädigt oder manipuliert (Authentisierung fehlgeschlagen).');
        [$plain, $tag] = $r;
        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            $this->final = true;
            if (fread($this->fh, 1) !== '') throw new BackupException('Archiv beschädigt (Daten nach der Endmarkierung).');
        }
        return $plain;
    }
}

/* ======================================================================
   tar (GNU-Format, nur reguläre Dateien und Ordner)
   ====================================================================== */

final class BackupTar
{
    /** @var callable(string):void */
    private $sink;
    public int $files = 0;
    public int $bytes = 0;

    public function __construct(callable $sink) { $this->sink = $sink; }

    private static function header(string $name, int $size, string $type, int $mode, int $mtime): string
    {
        $h = str_pad(substr($name, 0, 100), 100, "\0")
           . sprintf("%07o\0", $mode) . sprintf("%07o\0", 0) . sprintf("%07o\0", 0)
           . sprintf("%011o\0", $size) . sprintf("%011o\0", $mtime)
           . '        ' . $type . str_repeat("\0", 100)
           . "ustar  \0" . str_pad('planquadro', 32, "\0") . str_pad('planquadro', 32, "\0")
           . str_repeat("\0", 8) . str_repeat("\0", 8) . str_repeat("\0", 155) . str_repeat("\0", 12);
        $sum = 0;
        for ($i = 0; $i < 512; $i++) $sum += ord($h[$i]);
        return substr_replace($h, sprintf("%06o\0 ", $sum), 148, 8);
    }

    private function emit(string $d): void { ($this->sink)($d); }

    private function entryHeader(string $name, int $size, string $type, int $mode, int $mtime): void
    {
        if (strlen($name) > 100) {
            $ln = $name . "\0";
            $this->emit(self::header('././@LongLink', strlen($ln), 'L', 0644, 0));
            $this->emit($ln . str_repeat("\0", (512 - strlen($ln) % 512) % 512));
        }
        $this->emit(self::header($name, $size, $type, $mode, $mtime));
    }

    public function addString(string $name, string $data, int $mtime = 0): void
    {
        $this->entryHeader($name, strlen($data), '0', 0640, $mtime ?: time());
        $this->emit($data . str_repeat("\0", (512 - strlen($data) % 512) % 512));
        $this->files++; $this->bytes += strlen($data);
    }

    public function addFile(string $name, string $path): void
    {
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        if ($fh === false || $size === false) throw new BackupException("Datei nicht lesbar: $name");
        $this->entryHeader($name, $size, '0', 0640, (int)filemtime($path));
        $n = 0;
        while (!feof($fh)) {
            $c = fread($fh, 262144);
            if ($c === false) throw new BackupException("Lesefehler: $name");
            if ($c === '') break;
            $n += strlen($c);
            if ($n > $size) throw new BackupException("Datei hat sich während der Sicherung verändert: $name");
            $this->emit($c);
        }
        fclose($fh);
        if ($n !== $size) throw new BackupException("Datei hat sich während der Sicherung verändert: $name");
        $this->emit(str_repeat("\0", (512 - $size % 512) % 512));
        $this->files++; $this->bytes += $size;
    }

    public function addDir(string $name): void { $this->entryHeader(rtrim($name, '/') . '/', 0, '5', 0750, time()); }

    /** Ordner rekursiv (ohne Symlinks; $exclude = absolute Pfade, die ausgelassen werden). */
    public function addTree(string $base, string $prefix = '', array $exclude = []): array
    {
        $warnings = [];
        $base = rtrim($base, '/');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        $entries = [];
        foreach ($it as $f) $entries[] = $f;
        usort($entries, fn($a, $b) => strcmp($a->getPathname(), $b->getPathname()));
        foreach ($entries as $f) {
            $path = $f->getPathname();
            foreach ($exclude as $ex) if ($ex !== '' && ($path === $ex || strpos($path, rtrim($ex, '/') . '/') === 0)) continue 2;
            $rel = $prefix . substr($path, strlen($base) + 1);
            if ($f->isLink()) { $warnings[] = "Symlink ausgelassen: $rel"; continue; }
            if ($f->isDir()) $this->addDir($rel);
            elseif ($f->isFile()) $this->addFile($rel, $path);
        }
        return $warnings;
    }

    public function finish(): void { $this->emit(str_repeat("\0", 1024)); }

    /**
     * Entpackt einen tar-Strom nach $dest. $source liefert Blöcke (string) oder null am Ende.
     * Lehnt absolute Pfade, „..“ und andere Eintragstypen als Datei/Ordner ab.
     * @return array<string,int> Name => Größe der entpackten Dateien
     */
    public static function extract(callable $source, string $dest): array
    {
        $buf = ''; $eof = false;
        $need = function (int $n) use (&$buf, &$eof, $source): string {
            while (strlen($buf) < $n && !$eof) {
                $c = $source();
                if ($c === null) { $eof = true; break; }
                $buf .= $c;
            }
            if (strlen($buf) < $n) throw new BackupException('tar-Daten abgeschnitten.');
            $r = substr($buf, 0, $n); $buf = (string)substr($buf, $n);
            return $r;
        };
        $out = []; $longName = null;
        if (!is_dir($dest) && !mkdir($dest, 0700, true)) throw new BackupException("Kann $dest nicht anlegen.");
        while (true) {
            $h = $need(512);
            if ($h === str_repeat("\0", 512)) break;
            $sum = 0;
            for ($i = 0; $i < 512; $i++) $sum += ($i >= 148 && $i < 156) ? 32 : ord($h[$i]);
            if ($sum !== octdec(trim(substr($h, 148, 8), "\0 "))) throw new BackupException('tar-Kopf beschädigt (Prüfsumme).');
            $name = rtrim(substr($h, 0, 100), "\0");
            $size = octdec(trim(substr($h, 124, 12), "\0 "));
            $type = $h[156];
            $padded = $size + (512 - $size % 512) % 512;
            if ($type === 'L') { $longName = rtrim($need($padded), "\0"); continue; }
            if ($longName !== null) { $name = $longName; $longName = null; }
            $clean = trim($name, '/');
            if ($clean === '' || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $clean) || strpos($clean, "\0") !== false) {
                throw new BackupException('Unzulässiger Pfad im Archiv: ' . $name);
            }
            $target = $dest . '/' . $clean;
            if ($type === '5') {
                if (!is_dir($target) && !mkdir($target, 0750, true)) throw new BackupException("Kann $clean nicht anlegen.");
                continue;
            }
            if ($type !== '0' && $type !== "\0") throw new BackupException("Nicht unterstützter Eintragstyp im Archiv: $clean");
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0750, true);
            $fh = fopen($target, 'xb');
            if ($fh === false) throw new BackupException("Kann $clean nicht schreiben (existiert bereits?).");
            $left = $size;
            while ($left > 0) {
                $n = min($left, 262144);
                if (fwrite($fh, $need($n)) !== $n) throw new BackupException("Schreibfehler: $clean");
                $left -= $n;
            }
            fclose($fh);
            if ($padded > $size) $need($padded - $size);
            $out[$clean] = $size;
        }
        return $out;
    }
}

/* ======================================================================
   Datenbank: Verbindung, Dump, Einspielen
   ====================================================================== */

final class BackupDb
{
    /** @param array{host:string,port:string|int,user:string,pass:string} $c */
    public static function pdo(array $c, ?string $db = null, array $extra = []): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;%scharset=utf8mb4', $c['host'], (int)$c['port'], $db !== null ? 'dbname=' . $db . ';' : '');
        return new PDO($dsn, $c['user'], $c['pass'], $extra + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
    }

    public static function validName(string $db): bool { return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $db); }

    public static function exists(PDO $root, string $db): bool
    {
        $s = $root->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $s->execute([$db]);
        return (int)$s->fetchColumn() > 0;
    }

    public static function tableCount(PDO $root, string $db): int
    {
        $s = $root->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $s->execute([$db]);
        $n = (int)$s->fetchColumn();
        $s = $root->prepare('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?');
        $s->execute([$db]);
        return $n + (int)$s->fetchColumn();
    }

    /** Exakte Zeilenzahlen aller Basistabellen. */
    public static function rowCounts(PDO $pdo): array
    {
        $out = [];
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) $out[$t] = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn();
        ksort($out, SORT_STRING);
        return $out;
    }

    /** Temporäre Optionsdatei, damit das Passwort nicht in der Prozessliste steht. */
    public static function defaultsFile(array $c): string
    {
        $q = fn($v) => '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string)$v) . '"';
        $f = tempnam(Backup::tmpBase(), 'pqmy');
        chmod($f, 0600);
        $ini = "[client]\nhost=" . $q($c['host']) . "\nport=" . (int)$c['port'] . "\nuser=" . $q($c['user']) . "\npassword=" . $q($c['pass']) . "\n";
        /* MariaDB-Clients ab 11.4 erzwingen TLS standardmäßig; die Anwendung (PDO) verbindet
           ohne TLS. Gleiches Verhalten, außer PQ_DB_SSL=1. Eigene Gruppe, damit
           MySQL-Clients die Option nicht als unbekannt ablehnen. */
        if (getenv('PQ_DB_SSL') !== '1') $ini .= "\n[client-mariadb]\nskip-ssl\n";
        file_put_contents($f, $ini);
        return $f;
    }

    public static function findBinary(array $names): ?string
    {
        foreach ($names as $n) {
            foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
                if ($dir !== '' && is_file("$dir/$n") && is_executable("$dir/$n")) return "$dir/$n";
            }
        }
        return null;
    }
}

final class BackupDumper
{
    /**
     * Schreibt einen konsistenten Dump von $db gzip-komprimiert nach $outGz.
     * @return array{method:string, server_version:string, tables:array<string,int>}
     */
    public static function dump(array $conn, string $db, string $outGz): array
    {
        if (!BackupDb::validName($db)) throw new BackupException("Ungültiger Datenbankname: $db");
        $mode = strtolower(opsEnv('PQ_BACKUP_DUMPER', 'auto'));
        $pdo = BackupDb::pdo($conn, $db);
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $pdo = null;
        $bin = $mode === 'php' ? null : self::nativeBinary($version, $mode === 'native');
        if ($mode === 'native' && !$bin) throw new BackupException('PQ_BACKUP_DUMPER=native, aber weder mariadb-dump noch mysqldump gefunden.');
        $r = $bin ? self::dumpNative($bin, $conn, $db, $outGz) : self::dumpPhp($conn, $db, $outGz);
        ksort($r['tables'], SORT_STRING);
        return $r + ['server_version' => $version];
    }

    private static function clientIsMaria(string $bin): bool
    {
        static $cache = [];
        return $cache[$bin] ??= stripos((string)shell_exec(escapeshellarg($bin) . ' --version 2>&1'), 'mariadb') !== false;
    }

    /**
     * Dump-Programm, das zum Server passt. mysqldump 8 scheitert an MariaDB-Servern
     * (information_schema_stats_expiry), daher im Auto-Modus nur gleiche Familie;
     * sonst PHP-Dumper. $any = jedes gefundene Programm (PQ_BACKUP_DUMPER=native).
     */
    public static function nativeBinary(string $serverVersion, bool $any = false): ?string
    {
        $bin = BackupDb::findBinary(['mariadb-dump', 'mysqldump']);
        if (!$bin || $any) return $bin;
        return self::clientIsMaria($bin) === (stripos($serverVersion, 'mariadb') !== false) ? $bin : null;
    }

    private static function dumpNative(string $bin, array $conn, string $db, string $outGz): array
    {
        $isMaria = self::clientIsMaria($bin);
        $def = BackupDb::defaultsFile($conn);
        $args = [$bin, '--defaults-extra-file=' . $def, '--single-transaction', '--quick', '--routines', '--triggers',
                 '--hex-blob', '--no-tablespaces', '--skip-add-locks', '--default-character-set=utf8mb4', '--skip-dump-date', '--add-drop-table'];
        if (!$isMaria) { $args[] = '--column-statistics=0'; $args[] = '--set-gtid-purged=OFF'; }
        $args[] = $db;
        $errFile = tempnam(Backup::tmpBase(), 'operr');
        $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($p)) { @unlink($def); throw new BackupException("$bin konnte nicht gestartet werden."); }
        $gz = gzopen($outGz, 'wb6');
        $counter = new BackupRowCounter();
        $tail = '';
        try {
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 262144);
                if ($chunk === false) break;
                if ($chunk === '') continue;
                if (gzwrite($gz, $chunk) === false) throw new BackupException('Schreiben des Dumps fehlgeschlagen.');
                $tail = $counter->feed($tail . $chunk);
            }
            $counter->feed($tail . "\n");
        } finally {
            fclose($pipes[1]);
            $rc = proc_close($p);
            gzclose($gz);
            @unlink($def);
        }
        $err = trim((string)file_get_contents($errFile));
        @unlink($errFile);
        if ($rc !== 0) throw new BackupException(basename($bin) . " fehlgeschlagen (Exit $rc): " . mb_substr($err, 0, 500));
        if (!$counter->sawEnd) throw new BackupException(basename($bin) . ' lieferte einen unvollständigen Dump (Endmarkierung fehlt).');
        return ['method' => basename($bin), 'tables' => $counter->tables];
    }

    private static function dumpPhp(array $conn, string $db, string $outGz): array
    {
        $pdo = BackupDb::pdo($conn, $db);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $gz = gzopen($outGz, 'wb6');
        $w = function (string $s) use ($gz) { if (gzwrite($gz, $s) === false) throw new BackupException('Schreiben des Dumps fehlgeschlagen.'); };
        $q = fn(string $n) => '`' . str_replace('`', '``', $n) . '`';
        $counts = [];
        try {
            $w("-- Planquadro SQL-Dump (PHP, konsistenter Snapshot)\n-- Datenbank: $db\n\n"
             . "SET NAMES utf8mb4;\nSET @OPS_OLD_TZ=@@TIME_ZONE, TIME_ZONE='+00:00';\n"
             . "SET @OPS_OLD_FK=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\nSET @OPS_OLD_UC=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;\n"
             . "SET @OPS_OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
            $objs = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll();
            $views = [];
            foreach ($objs as $o) {
                $t = $o['TABLE_NAME'];
                if ($o['TABLE_TYPE'] === 'VIEW') { $views[] = $t; continue; }
                $create = $pdo->query('SHOW CREATE TABLE ' . $q($t))->fetch(PDO::FETCH_NUM)[1];
                $w("DROP TABLE IF EXISTS {$q($t)};\n$create;\n");
                $cols = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
                $cols->execute([$t]);
                $names = []; $bin = [];
                foreach ($cols->fetchAll() as $c) {
                    if (stripos((string)$c['EXTRA'], 'GENERATED') !== false || in_array(strtoupper((string)$c['EXTRA']), ['VIRTUAL', 'PERSISTENT', 'STORED'], true)) continue;
                    $names[] = $q($c['COLUMN_NAME']);
                    $bin[] = (bool)preg_match('/blob|binary|^bit$|geometry|point|linestring|polygon/i', $c['DATA_TYPE']);
                }
                $counts[$t] = 0;
                if (!$names) continue;
                $prefix = 'INSERT INTO ' . $q($t) . ' (' . implode(',', $names) . ') VALUES ';
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $st = $pdo->query('SELECT ' . implode(',', $names) . ' FROM ' . $q($t));
                $batch = ''; $n = 0;
                while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                    $vals = [];
                    foreach ($row as $i => $v) {
                        if ($v === null) $vals[] = 'NULL';
                        elseif ($bin[$i]) $vals[] = $v === '' ? "''" : '0x' . bin2hex($v);
                        else $vals[] = $pdo->quote((string)$v);
                    }
                    $batch .= ($batch === '' ? '' : ',') . '(' . implode(',', $vals) . ')';
                    $n++;
                    if (strlen($batch) > 524288) { $w($prefix . $batch . ";\n"); $batch = ''; }
                }
                $st->closeCursor();
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                if ($batch !== '') $w($prefix . $batch . ";\n");
                $counts[$t] = $n;
                $w("\n");
            }
            $strip = fn(string $s) => preg_replace('/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\S+)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $s);
            foreach ($views as $v) {
                $create = $pdo->query('SHOW CREATE VIEW ' . $q($v))->fetch(PDO::FETCH_NUM)[1];
                $create = preg_replace('/\s+SQL SECURITY DEFINER/i', '', $strip($create));
                $w("DROP VIEW IF EXISTS {$q($v)};\n$create;\n\n");
            }
            foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_NUM) as $tr) {
                $create = $pdo->query('SHOW CREATE TRIGGER ' . $q($tr[0]))->fetch(PDO::FETCH_NUM)[2];
                $w("DELIMITER ;;\n" . $strip($create) . ";;\nDELIMITER ;\n\n");
            }
            $routines = $pdo->query('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_TYPE, ROUTINE_NAME')->fetchAll();
            foreach ($routines as $r) {
                $type = $r['ROUTINE_TYPE'] === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
                $row = $pdo->query("SHOW CREATE $type " . $q($r['ROUTINE_NAME']))->fetch(PDO::FETCH_NUM);
                if (empty($row[2])) throw new BackupException("Keine Berechtigung, $type {$r['ROUTINE_NAME']} zu lesen.");
                $w("DROP $type IF EXISTS {$q($r['ROUTINE_NAME'])};\nDELIMITER ;;\n" . $strip($row[2]) . ";;\nDELIMITER ;\n\n");
            }
            $w("SET SQL_MODE=@OPS_OLD_SQL_MODE;\nSET FOREIGN_KEY_CHECKS=@OPS_OLD_FK;\nSET UNIQUE_CHECKS=@OPS_OLD_UC;\nSET TIME_ZONE=@OPS_OLD_TZ;\n-- Dump completed\n");
            $pdo->exec('COMMIT');
        } finally {
            gzclose($gz);
        }
        return ['method' => 'php', 'tables' => $counts];
    }
}

/**
 * Zählt Zeilen in mysqldump-/mariadb-dump-Ausgabe. Beide Formate werden
 * unterstützt: ein erweitertes INSERT je Zeile (MySQL, ältere MariaDB) und
 * ein Tupel je Zeile (MariaDB ≥ 10.11). Zeilenumbrüche in Werten sind im
 * Dump immer als \n maskiert, Zeichenketten reichen also nie über eine Zeile.
 * feed() verarbeitet vollständige Zeilen und gibt den unvollständigen Rest zurück.
 */
final class BackupRowCounter
{
    public array $tables = [];
    public bool $sawEnd = false;
    private ?string $current = null;

    public function feed(string $data): string
    {
        $start = 0;
        while (($nl = strpos($data, "\n", $start)) !== false) {
            $this->line(substr($data, $start, $nl - $start));
            $start = $nl + 1;
        }
        return (string)substr($data, $start);
    }

    private function line(string $l): void
    {
        if ($this->current !== null) {
            $this->tables[$this->current] += self::countTuples($l, 0);
            if (substr(rtrim($l), -1) === ';') $this->current = null;
            return;
        }
        if (strncmp($l, 'CREATE TABLE `', 14) === 0 && preg_match('/^CREATE TABLE `((?:[^`]|``)+)`/', $l, $m)) {
            $this->tables[str_replace('``', '`', $m[1])] ??= 0;
            return;
        }
        if (strncmp($l, '-- Dump completed', 17) === 0) { $this->sawEnd = true; return; }
        if (strncmp($l, 'INSERT INTO `', 13) !== 0) return;
        if (!preg_match('/^INSERT INTO `((?:[^`]|``)+)`(?: \([^)]*\))? VALUES ?/', $l, $m)) return;
        $t = str_replace('``', '`', $m[1]);
        $this->tables[$t] = ($this->tables[$t] ?? 0) + self::countTuples($l, strlen($m[0]));
        if (substr(rtrim($l), -1) !== ';') $this->current = $t;
    }

    /** Anzahl der Tupel (…),(…) ab $pos; beachtet '…'-Zeichenketten mit \-Escapes. */
    public static function countTuples(string $l, int $pos): int
    {
        $len = strlen($l); $depth = 0; $rows = 0; $inStr = false;
        while ($pos < $len) {
            if ($inStr) {
                $pos += strcspn($l, "\\'", $pos);
                if ($pos >= $len) break;
                if ($l[$pos] === '\\') { $pos += 2; continue; }
                if ($pos + 1 < $len && $l[$pos + 1] === "'") { $pos += 2; continue; }
                $inStr = false; $pos++;
                continue;
            }
            $pos += strcspn($l, "'()", $pos);
            if ($pos >= $len) break;
            $c = $l[$pos];
            if ($c === "'") $inStr = true;
            elseif ($c === '(') { if ($depth === 0) $rows++; $depth++; }
            else $depth--;
            $pos++;
        }
        return $rows;
    }
}

final class BackupSqlLoader
{
    /** Entfernt DEFINER-Klauseln (Restore ohne SUPER-Recht, anderer Benutzer). */
    public static function filterLine(string $line): string
    {
        if (strncmp($line, 'INSERT INTO', 11) === 0 || stripos($line, 'DEFINER') === false) return $line;
        $line = preg_replace('#/\*!50017 DEFINER=`[^`]*`@`[^`]*`\s*\*/\s?#', '', $line);
        $line = preg_replace('#/\*!50013 DEFINER=`[^`]*`@`[^`]*` SQL SECURITY DEFINER \*/#', '', $line);
        return preg_replace('/\bDEFINER\s*=\s*`[^`]*`@`[^`]*`\s?/', '', $line);
    }

    /** Prüft einen Dump auf Anweisungen, die eine andere Datenbank ansprechen würden. */
    public static function assertSafe(string $sqlGz): void
    {
        $gz = gzopen($sqlGz, 'rb');
        if (!$gz) throw new BackupException('Dump nicht lesbar.');
        try {
            while (($l = gzgets($gz, 1 << 20)) !== false) {
                if ($l === '' || $l[0] === 'I') continue;
                if (preg_match('/^\s*(USE\s|CREATE\s+(DATABASE|SCHEMA)\b|DROP\s+(DATABASE|SCHEMA)\b|\\\\u\s|\\\\!|system\s)/i', $l)) {
                    throw new BackupException('Dump enthält unzulässige Anweisung: ' . mb_substr(trim($l), 0, 80));
                }
            }
            if (!gzeof($gz)) throw new BackupException('Dump ist beschädigt (gzip).');
        } finally { gzclose($gz); }
    }

    /** Spielt den Dump in $db ein; Ausnahme beim ersten Fehler. */
    public static function load(array $conn, string $db, string $sqlGz): string
    {
        if (!BackupDb::validName($db)) throw new BackupException("Ungültiger Datenbankname: $db");
        self::assertSafe($sqlGz);
        $mode = strtolower(opsEnv('PQ_BACKUP_DUMPER', 'auto'));
        $bin = $mode === 'php' ? null : BackupDb::findBinary(['mariadb', 'mysql']);
        return $bin ? self::loadClient($bin, $conn, $db, $sqlGz) : self::loadPhp($conn, $db, $sqlGz);
    }

    private static function loadClient(string $bin, array $conn, string $db, string $sqlGz): string
    {
        $def = BackupDb::defaultsFile($conn);
        $errFile = tempnam(Backup::tmpBase(), 'operr');
        $p = proc_open([$bin, '--defaults-extra-file=' . $def, '--default-character-set=utf8mb4', '--binary-mode', '--batch', $db],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($p)) { @unlink($def); throw new BackupException("$bin konnte nicht gestartet werden."); }
        $gz = gzopen($sqlGz, 'rb');
        $broken = false;
        try {
            while (($l = gzgets($gz, 1 << 24)) !== false) {
                $l = self::filterLine($l);
                if (@fwrite($pipes[0], $l) === false) { $broken = true; break; }
            }
        } finally {
            gzclose($gz);
            @fclose($pipes[0]);
            $rc = proc_close($p);
            @unlink($def);
        }
        $err = trim((string)file_get_contents($errFile));
        @unlink($errFile);
        if ($rc !== 0 || $broken) throw new BackupException('Restore abgebrochen (' . basename($bin) . " Exit $rc): " . mb_substr($err, 0, 800));
        return basename($bin);
    }

    private static function loadPhp(array $conn, string $db, string $sqlGz): string
    {
        $sql = '';
        $gz = gzopen($sqlGz, 'rb');
        while (($l = gzgets($gz, 1 << 24)) !== false) $sql .= self::filterLine($l);
        gzclose($gz);
        $pdo = BackupDb::pdo($conn, $db, [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
        $n = 0;
        foreach (SqlSplitter::split($sql) as $stmt) {
            $n++;
            try {
                $st = $pdo->query($stmt);
                if ($st) { do { try { $st->fetchAll(); } catch (PDOException $e) {} } while ($st->nextRowset()); $st->closeCursor(); }
            } catch (PDOException $e) {
                throw new BackupException("Restore abgebrochen bei Anweisung $n: " . $e->getMessage() . "\n" . mb_substr($stmt, 0, 200));
            }
        }
        return 'php';
    }
}

/* ======================================================================
   Ablageziele
   ====================================================================== */

interface BackupTarget
{
    public function name(): string;
    /** Lädt $local als $key hoch und prüft das Ergebnis; Ausnahme bei Fehler. */
    public function put(string $local, string $key, int $size, string $sha256): void;
    /** @return array<int,array{key:string,size:int,mtime:int}> */
    public function list(string $prefix = ''): array;
    public function delete(string $key): void;
    public function get(string $key, string $local): void;
    public function external(): bool;
}

final class BackupLocalTarget implements BackupTarget
{
    public function __construct(public string $dir) {}
    public function name(): string { return 'local'; }
    public function external(): bool { return false; }

    public function path(string $key): string { return rtrim($this->dir, '/') . '/' . $key; }

    public function put(string $local, string $key, int $size, string $sha256): void
    {
        $dst = $this->path($key);
        if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0750, true)) throw new BackupException('Backup-Verzeichnis nicht beschreibbar: ' . dirname($dst));
        if (realpath($local) !== realpath($dst) || !file_exists($dst)) {
            $tmp = dirname($dst) . '/.' . basename($dst) . '.part';
            if (!copy($local, $tmp)) throw new BackupException('Kopieren ins Backup-Verzeichnis fehlgeschlagen.');
            rename($tmp, $dst);
        }
        clearstatcache(true, $dst);
        if (filesize($dst) !== $size || hash_file('sha256', $dst) !== $sha256) throw new BackupException('Prüfung der lokalen Kopie fehlgeschlagen.');
    }

    public function list(string $prefix = ''): array
    {
        $base = rtrim($this->dir, '/');
        $out = [];
        foreach (glob($base . '/' . ($prefix !== '' ? rtrim($prefix, '/') : '*') . '/*.pqbak') ?: [] as $f) {
            $out[] = ['key' => substr($f, strlen($base) + 1), 'size' => (int)filesize($f), 'mtime' => (int)filemtime($f)];
        }
        return $out;
    }

    public function delete(string $key): void { if (is_file($this->path($key)) && !unlink($this->path($key))) throw new BackupException("Löschen fehlgeschlagen: $key"); }

    public function get(string $key, string $local): void { if (!copy($this->path($key), $local)) throw new BackupException("Lesen fehlgeschlagen: $key"); }
}

/** S3-kompatibler Objektspeicher (AWS, MinIO, Hetzner, Wasabi …), AWS Signature V4 per curl. */
final class BackupS3Target implements BackupTarget
{
    private string $scheme; private string $host; private ?int $port; private string $basePath;

    public function __construct(private string $endpoint, private string $region, private string $bucket, private string $prefix,
                                private string $accessKey, private string $secretKey, private bool $pathStyle, private ?int $fixedTime = null)
    {
        $u = parse_url($endpoint);
        if (empty($u['host'])) throw new BackupException('PQ_BACKUP_S3_ENDPOINT ist ungültig.');
        $this->scheme = $u['scheme'] ?? 'https';
        $this->host = $u['host'];
        $this->port = $u['port'] ?? null;
        $this->basePath = rtrim($u['path'] ?? '', '/');
        $this->prefix = trim($prefix, '/');
    }

    public function name(): string { return 's3'; }
    public function external(): bool { return true; }

    private function objectKey(string $key): string { return ($this->prefix !== '' ? $this->prefix . '/' : '') . ltrim($key, '/'); }

    /** @return array{0:string,1:string} [Host, Pfad] */
    private function location(string $objectKey): array
    {
        $host = $this->pathStyle ? $this->host : $this->bucket . '.' . $this->host;
        $path = $this->basePath . ($this->pathStyle ? '/' . $this->bucket : '') . '/' . $objectKey;
        return [$host, $path];
    }

    public static function uriEncodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Erzeugt die Signatur-Header (AWS SigV4). Öffentlich für Tests mit den
     * Beispielwerten aus der AWS-Dokumentation.
     * @return array<string,string> Header (lowercase name => value) inkl. authorization
     */
    public function sign(string $method, string $host, string $path, array $query, array $headers, string $payloadHash): array
    {
        $t = $this->fixedTime ?? time();
        $amzDate = gmdate('Ymd\THis\Z', $t);
        $date = substr($amzDate, 0, 8);
        $hostHeader = $host . ($this->port && !in_array($this->port, [80, 443], true) ? ':' . $this->port : '');
        $h = ['host' => $hostHeader, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        foreach ($headers as $k => $v) $h[strtolower($k)] = trim((string)$v);
        ksort($h);
        ksort($query);
        $qs = [];
        foreach ($query as $k => $v) $qs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        $canonHeaders = '';
        foreach ($h as $k => $v) $canonHeaders .= $k . ':' . preg_replace('/\s+/', ' ', $v) . "\n";
        $signed = implode(';', array_keys($h));
        $canon = $method . "\n" . self::uriEncodePath($path) . "\n" . implode('&', $qs) . "\n" . $canonHeaders . "\n" . $signed . "\n" . $payloadHash;
        $scope = "$date/{$this->region}/s3/aws4_request";
        $sts = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canon);
        $k = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', 's3', $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $sig = hash_hmac('sha256', $sts, $k);
        $h['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signed, Signature=$sig";
        return $h;
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    private function request(string $method, string $objectKey, array $query = [], ?string $inFile = null, ?string $payloadHash = null, ?string $outFile = null): array
    {
        [$host, $path] = $this->location($objectKey);
        $payloadHash ??= hash('sha256', '');
        $h = $this->sign($method, $host, $path, $query, [], $payloadHash);
        $hostForUrl = $host . ($this->port ? ':' . $this->port : '');
        $qs = $query ? '?' . implode('&', array_map(fn($k, $v) => rawurlencode((string)$k) . '=' . rawurlencode((string)$v), array_keys($query), $query)) : '';
        $url = $this->scheme . '://' . $hostForUrl . self::uriEncodePath($path) . $qs;
        $ch = curl_init($url);
        $respHeaders = [];
        $hdr = [];
        foreach ($h as $k => $v) if ($k !== 'host') $hdr[] = "$k: $v";
        $hdr[] = 'Expect:';
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $hdr, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 3600,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $respHeaders[strtolower(trim($k))] = trim($v); }
                return strlen($line);
            }];
        $in = $out = null;
        if ($inFile !== null) {
            $in = fopen($inFile, 'rb');
            $opts += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => filesize($inFile)];
        }
        if ($method === 'HEAD') $opts[CURLOPT_NOBODY] = true;
        if ($outFile !== null) { $out = fopen($outFile, 'wb'); $opts[CURLOPT_FILE] = $out; }
        else $opts[CURLOPT_RETURNTRANSFER] = true;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($in) fclose($in);
        if ($out) fclose($out);
        if ($body === false) throw new BackupException("S3 $method fehlgeschlagen: $err");
        if ($status >= 300) {
            $msg = is_string($body) && preg_match('#<Code>([^<]+)</Code>#', $body, $m) ? $m[1] : '';
            if ($outFile) @unlink($outFile);
            throw new BackupException("S3 $method $objectKey: HTTP $status $msg");
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => is_string($body) ? $body : ''];
    }

    public function put(string $local, string $key, int $size, string $sha256): void
    {
        $ok = $this->objectKey($key);
        $r = $this->request('PUT', $ok, [], $local, $sha256);
        $etag = trim($r['headers']['etag'] ?? '', '"');
        if ($etag !== '' && strpos($etag, '-') === false && strlen($etag) === 32 && !hash_equals($etag, md5_file($local))) {
            throw new BackupException("S3: ETag stimmt nicht mit der hochgeladenen Datei überein ($key).");
        }
        $head = $this->request('HEAD', $ok);
        if ((int)($head['headers']['content-length'] ?? -1) !== $size) throw new BackupException("S3: Größe nach Upload stimmt nicht ($key).");
    }

    public function list(string $prefix = ''): array
    {
        $out = []; $token = null;
        $full = $this->objectKey($prefix);
        do {
            $q = ['list-type' => '2', 'prefix' => $full === '' ? '' : rtrim($full, '/') . '/'];
            if ($token) $q['continuation-token'] = $token;
            [$host, $path] = $this->location('');
            $r = $this->request('GET', '', $q);
            $xml = @simplexml_load_string($r['body']);
            if ($xml === false) throw new BackupException('S3: ungültige LIST-Antwort.');
            foreach ($xml->Contents as $c) {
                $k = (string)$c->Key;
                if (substr($k, -6) !== '.pqbak') continue;
                $rel = $this->prefix !== '' ? substr($k, strlen($this->prefix) + 1) : $k;
                $out[] = ['key' => $rel, 'size' => (int)$c->Size, 'mtime' => (int)strtotime((string)$c->LastModified)];
            }
            $token = ((string)$xml->IsTruncated === 'true') ? (string)$xml->NextContinuationToken : null;
        } while ($token);
        return $out;
    }

    public function delete(string $key): void { $this->request('DELETE', $this->objectKey($key)); }

    public function get(string $key, string $local): void { $this->request('GET', $this->objectKey($key), [], null, null, $local); }
}

/** Beliebiges rclone-Ziel (SFTP, WebDAV, Nextcloud, zweiter Server …): PQ_BACKUP_RCLONE_REMOTE=name:pfad */
final class BackupRcloneTarget implements BackupTarget
{
    public function __construct(private string $remote, private string $bin = 'rclone') {}
    public function name(): string { return 'rclone'; }
    public function external(): bool { return true; }

    private function path(string $key): string { return rtrim($this->remote, '/') . '/' . ltrim($key, '/'); }

    private function run(array $args): string
    {
        $errFile = tempnam(Backup::tmpBase(), 'operr');
        $p = proc_open(array_merge([$this->bin], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($p)) throw new BackupException('rclone konnte nicht gestartet werden.');
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $rc = proc_close($p);
        $err = trim((string)file_get_contents($errFile));
        @unlink($errFile);
        if ($rc !== 0) throw new BackupException("rclone {$args[0]} fehlgeschlagen (Exit $rc): " . mb_substr($err, 0, 400));
        return (string)$out;
    }

    public function put(string $local, string $key, int $size, string $sha256): void
    {
        $this->run(['copyto', $local, $this->path($key)]);
        $info = json_decode($this->run(['lsjson', '--files-only', $this->path($key)]), true);
        if (!is_array($info) || count($info) !== 1 || (int)$info[0]['Size'] !== $size) throw new BackupException("rclone: Größe nach Upload stimmt nicht ($key).");
    }

    public function list(string $prefix = ''): array
    {
        try { $rows = json_decode($this->run(['lsjson', '--files-only', '--recursive', $this->path($prefix)]), true); }
        catch (BackupException $e) { if (stripos($e->getMessage(), 'directory not found') !== false) return []; throw $e; }
        $out = [];
        foreach ((array)$rows as $r) {
            if (substr($r['Path'], -6) !== '.pqbak') continue;
            $out[] = ['key' => ($prefix !== '' ? rtrim($prefix, '/') . '/' : '') . $r['Path'], 'size' => (int)$r['Size'], 'mtime' => (int)strtotime($r['ModTime'])];
        }
        return $out;
    }

    public function delete(string $key): void { $this->run(['deletefile', $this->path($key)]); }

    public function get(string $key, string $local): void { $this->run(['copyto', $this->path($key), $local]); }
}

/* ======================================================================
   Aufbewahrung (GFS)
   ====================================================================== */

final class BackupRetention
{
    /**
     * @param array<string,int> $items Schlüssel => Unix-Zeit
     * @param int $last die jüngsten $last Archive immer behalten (mindestens 1)
     * @return string[] zu behaltende Schlüssel
     */
    public static function keep(array $items, int $daily, int $weekly, int $monthly, int $last = 1): array
    {
        arsort($items);
        $keep = [];
        foreach (array_slice(array_keys($items), 0, max(1, $last)) as $k) $keep[$k] = true;
        $rules = [[$daily, 'Y-m-d'], [$weekly, 'o-W'], [$monthly, 'Y-m']];
        foreach ($rules as [$n, $fmt]) {
            $seen = [];
            foreach ($items as $k => $t) {
                if (count($seen) >= $n) break;
                $b = gmdate($fmt, $t);
                if (isset($seen[$b])) continue;
                $seen[$b] = true;
                $keep[$k] = true;
            }
        }
        return array_keys($keep);
    }
}

/* ======================================================================
   Statusprotokoll und Alarme
   ====================================================================== */

interface BackupStatusStore
{
    public function start(string $scope, string $kind): string;
    public function finish(string $id, string $status, array $data): void;
    /** @return array<int,array> jüngste Läufe zuerst */
    public function runs(?string $scope = null, int $limit = 50): array;
}

final class BackupFileStatus implements BackupStatusStore
{
    public function __construct(private string $file) {}

    private function mutate(callable $fn): mixed
    {
        if (!is_dir(dirname($this->file))) mkdir(dirname($this->file), 0750, true);
        $fh = fopen($this->file, 'c+');
        if (!$fh) throw new BackupException('Statusdatei nicht beschreibbar: ' . $this->file);
        flock($fh, LOCK_EX);
        $data = json_decode((string)stream_get_contents($fh), true) ?: ['runs' => []];
        $r = $fn($data);
        $data['runs'] = array_slice($data['runs'], 0, 300);
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fh); flock($fh, LOCK_UN); fclose($fh);
        return $r;
    }

    public function start(string $scope, string $kind): string
    {
        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $this->mutate(function (&$d) use ($id, $scope, $kind) {
            array_unshift($d['runs'], ['id' => $id, 'scope' => $scope, 'kind' => $kind, 'status' => 'running', 'started_at' => gmdate('c'), 'finished_at' => null]);
        });
        return $id;
    }

    public function finish(string $id, string $status, array $data): void
    {
        $this->mutate(function (&$d) use ($id, $status, $data) {
            foreach ($d['runs'] as &$r) if ($r['id'] === $id) { $r = array_merge($r, $data, ['status' => $status, 'finished_at' => gmdate('c')]); break; }
        });
    }

    public function runs(?string $scope = null, int $limit = 50): array
    {
        if (!is_readable($this->file)) return [];
        $d = json_decode((string)file_get_contents($this->file), true) ?: ['runs' => []];
        $runs = array_values(array_filter($d['runs'], fn($r) => $scope === null || $r['scope'] === $scope));
        return array_slice($runs, 0, $limit);
    }
}

final class BackupDbStatus implements BackupStatusStore
{
    public function __construct(private PDO $db) {}

    public function start(string $scope, string $kind): string
    {
        $this->db->prepare("INSERT INTO backup_runs(scope, kind, status) VALUES(?,?, 'running')")->execute([$scope, $kind]);
        return (string)$this->db->lastInsertId();
    }

    public function finish(string $id, string $status, array $data): void
    {
        $this->db->prepare('UPDATE backup_runs SET status=?, archive=?, size_bytes=?, sha256=?, targets=?, message=?, finished_at=NOW() WHERE id=?')
            ->execute([$status, $data['archive'] ?? null, $data['size_bytes'] ?? null, $data['sha256'] ?? null,
                       isset($data['targets']) ? mb_substr(implode(',', (array)$data['targets']), 0, 500) : null,
                       $data['message'] ?? null, $id]);
    }

    public function runs(?string $scope = null, int $limit = 50): array
    {
        $sql = 'SELECT id, scope, kind, status, archive, size_bytes, sha256, targets, message, started_at, finished_at FROM backup_runs'
             . ($scope !== null ? ' WHERE scope = ?' : '') . ' ORDER BY id DESC LIMIT ' . (int)$limit;
        $s = $this->db->prepare($sql);
        $s->execute($scope !== null ? [$scope] : []);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $r['targets'] = $r['targets'] !== null && $r['targets'] !== '' ? explode(',', $r['targets']) : [];
            $r['size_bytes'] = $r['size_bytes'] !== null ? (int)$r['size_bytes'] : null;
            foreach (['started_at', 'finished_at'] as $k) if ($r[$k]) $r[$k] = gmdate('c', strtotime($r[$k] . ' UTC'));
            $out[] = $r;
        }
        return $out;
    }
}

final class BackupAlerter
{
    public function __construct(private string $stateFile) {}

    /** Sendet höchstens einmal je $repeatHours und Schlüssel. */
    public function alert(string $key, string $subject, string $message, array $details = []): bool
    {
        if (opsEnv('PQ_ALERT_WEBHOOK') === '' && opsEnv('PQ_ALERT_EMAIL') === '') return false;
        $state = is_readable($this->stateFile) ? (json_decode((string)file_get_contents($this->stateFile), true) ?: []) : [];
        $repeat = max(1, (int)opsEnv('PQ_ALERT_REPEAT_HOURS', '24')) * 3600;
        if (isset($state[$key]) && time() - (int)$state[$key] < $repeat) return false;
        $sent = in_array(true, $this->send($key, $subject, $message, $details), true);
        if ($sent) { $state[$key] = time(); @file_put_contents($this->stateFile, json_encode($state)); }
        return $sent;
    }

    /**
     * Versendet sofort über alle konfigurierten Kanäle (ohne Wiederholsperre).
     * @return array<string,bool> Kanal (webhook, mail) => Erfolg
     */
    public function send(string $key, string $subject, string $message, array $details = []): array
    {
        $hook = opsEnv('PQ_ALERT_WEBHOOK');
        $mail = opsEnv('PQ_ALERT_EMAIL');
        $host = opsEnv('PQ_BASE_URL', gethostname() ?: 'planquadro');
        $payload = ['source' => 'planquadro-backup', 'event' => $key, 'subject' => $subject, 'message' => $message,
                    'host' => $host, 'time' => gmdate('c'), 'details' => $details, 'text' => "[Planquadro] $subject: $message"];
        $res = [];
        if ($hook !== '') {
            $ch = curl_init($hook);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5]);
            $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) < 300;
            curl_close($ch);
            if (!$ok) error_log('Planquadro backup: Webhook-Alarm fehlgeschlagen');
            $res['webhook'] = $ok;
        }
        if ($mail !== '') {
            require_once __DIR__ . '/Mail.php';
            $body = "$message\n\n" . json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\nHost: $host\n";
            $r = opsSendMail($mail, "[Planquadro] $subject", $body);
            if (!$r['ok']) error_log('Planquadro backup: Mail-Alarm ' . ($r['method'] === 'none' ? 'übersprungen' : 'fehlgeschlagen') . ': ' . $r['error']);
            $res['mail'] = $r['ok'];
        }
        return $res;
    }

    public function clear(string $key): void
    {
        if (!is_readable($this->stateFile)) return;
        $state = json_decode((string)file_get_contents($this->stateFile), true) ?: [];
        if (isset($state[$key])) { unset($state[$key]); @file_put_contents($this->stateFile, json_encode($state)); }
    }
}

/* ======================================================================
   Ablauf
   ====================================================================== */

final class Backup
{
    public const NAME_RE = '/^planquadro_(_platform|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)_(\d{8}-\d{6})Z_([a-z]+)\.pqbak$/';
    public const KINDS = ['scheduled', 'manual', 'prerestore'];

    /** @var callable(string):void|null */
    public $log = null;
    private ?BackupStatusStore $status = null;
    private ?array $targets = null;

    public static function tmpBase(): string
    {
        $d = opsEnv('PQ_BACKUP_TMP_DIR', sys_get_temp_dir());
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d;
    }

    public static function storageBase(): string { return rtrim(opsEnv('PQ_STORAGE_DIR', dirname(__DIR__) . '/storage'), '/'); }

    public static function stateDir(): string
    {
        $d = self::storageBase() . '/_backup';
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    public static function backupDir(): string
    {
        $d = opsEnv('PQ_BACKUP_DIR');
        if ($d !== '') return rtrim($d, '/');
        return is_dir('/data') && is_writable('/data') ? '/data/backups' : dirname(__DIR__) . '/backups';
    }

    public static function maxAgeHours(): int { return max(1, (int)opsEnv('PQ_BACKUP_MAX_AGE_HOURS', '26')); }

    private function say(string $s): void { if ($this->log) ($this->log)($s); }

    public static function mainConn(): array { return ['host' => DB_HOST, 'port' => DB_PORT, 'user' => DB_USER, 'pass' => DB_PASS]; }

    public static function platformConn(): array
    {
        return ['host' => opsEnv('PQ_PLATFORM_DB_HOST', DB_HOST), 'port' => opsEnv('PQ_PLATFORM_DB_PORT', DB_PORT),
                'user' => opsEnv('PQ_PLATFORM_DB_USER', DB_USER), 'pass' => opsEnv('PQ_PLATFORM_DB_PASS', DB_PASS)];
    }

    /** Alle zu sichernden Bereiche. */
    public function scopes(): array
    {
        if (opsMode() !== 'saas') return [$this->tenantScope('default', DB_NAME, '')];
        $out = [];
        foreach (opsPlatformDB()->query("SELECT slug, name, db_name FROM tenants WHERE status <> 'deleted' ORDER BY id")->fetchAll() as $t) {
            $out[] = $this->tenantScope($t['slug'], $t['db_name'], $t['name']);
        }
        $out[] = ['scope' => '_platform', 'db' => opsPlatformDbName(), 'conn' => self::platformConn(), 'files' => null, 'platform' => true, 'tenant' => null];
        return $out;
    }

    public function tenantScope(string $slug, string $db, string $name): array
    {
        return ['scope' => $slug, 'db' => $db, 'conn' => self::mainConn(), 'files' => self::storageBase() . '/' . $slug,
                'platform' => false, 'tenant' => ['slug' => $slug, 'name' => $name]];
    }

    public function scope(string $scope): array
    {
        foreach ($this->scopes() as $s) if ($s['scope'] === $scope) return $s;
        throw new BackupException("Unbekannter Bereich/Mandant: $scope");
    }

    /** Bereich des aktuellen Web-Requests. */
    public function currentScope(): array
    {
        $t = opsCurrentTenant();
        return $this->tenantScope($t['slug'], $t['db_name'], (string)($t['name'] ?? ''));
    }

    public function statusStore(): BackupStatusStore
    {
        if ($this->status) return $this->status;
        return $this->status = opsMode() === 'saas' ? new BackupDbStatus(opsPlatformDB()) : new BackupFileStatus(self::stateDir() . '/status.json');
    }

    public function setStatusStore(BackupStatusStore $s): void { $this->status = $s; }

    public function alerter(): BackupAlerter { return new BackupAlerter(self::stateDir() . '/alerts.json'); }

    /** @return BackupTarget[] lokales Ziel zuerst */
    public function targets(): array
    {
        if ($this->targets !== null) return $this->targets;
        $t = [new BackupLocalTarget(self::backupDir())];
        if (($ep = opsEnv('PQ_BACKUP_S3_ENDPOINT')) !== '' && ($bucket = opsEnv('PQ_BACKUP_S3_BUCKET')) !== '') {
            $ps = opsEnv('PQ_BACKUP_S3_PATH_STYLE', 'auto');
            $pathStyle = $ps === 'auto' ? stripos($ep, 'amazonaws.com') === false : $ps === '1' || $ps === 'true';
            $t[] = new BackupS3Target($ep, opsEnv('PQ_BACKUP_S3_REGION', 'us-east-1'), $bucket, opsEnv('PQ_BACKUP_S3_PREFIX', 'planquadro'),
                opsEnv('PQ_BACKUP_S3_ACCESS_KEY'), opsEnv('PQ_BACKUP_S3_SECRET_KEY'), $pathStyle);
        }
        if (($rc = opsEnv('PQ_BACKUP_RCLONE_REMOTE')) !== '') $t[] = new BackupRcloneTarget($rc, opsEnv('PQ_BACKUP_RCLONE_BIN', 'rclone'));
        return $this->targets = $t;
    }

    public function setTargets(array $t): void { $this->targets = $t; }

    public function local(): BackupLocalTarget { return $this->targets()[0]; }

    public static function publicKey(): ?string
    {
        $k = opsEnv('PQ_BACKUP_PUBLIC_KEY');
        return $k === '' ? null : BackupCrypto::parsePublic($k);
    }

    public static function privateKey(?string $file = null): string
    {
        if ($file === '-') $k = (string)stream_get_contents(STDIN);
        elseif ($file !== null) {
            if (!is_readable($file)) throw new BackupException("Schlüsseldatei $file ist nicht vorhanden oder nicht lesbar (Rechte des ausführenden Benutzers prüfen).");
            $k = (string)file_get_contents($file);
        } else $k = opsEnv('PQ_BACKUP_PRIVATE_KEY');
        if (trim($k) === '') throw new BackupException('Privater Schlüssel fehlt (--key=<datei>, --key=- für die Standardeingabe oder PQ_BACKUP_PRIVATE_KEY_FILE). Er wird nur für verify/restore benötigt.');
        return BackupCrypto::parseSecret($k);
    }

    public static function archiveName(string $scope, string $kind, ?int $t = null): string
    {
        return sprintf('planquadro_%s_%sZ_%s.pqbak', $scope, gmdate('Ymd-His', $t ?? time()), $kind);
    }

    /** @return array{scope:string,time:int,kind:string}|null */
    public static function parseName(string $name): ?array
    {
        if (!preg_match(self::NAME_RE, basename($name), $m)) return null;
        $dt = DateTimeImmutable::createFromFormat('!Ymd-His', $m[2], new DateTimeZone('UTC'));
        return $dt ? ['scope' => $m[1], 'time' => $dt->getTimestamp(), 'kind' => $m[3]] : null;
    }

    private static function mkTmp(string $tag): string
    {
        $d = self::tmpBase() . '/planquadro-' . $tag . '-' . bin2hex(random_bytes(6));
        if (!mkdir($d, 0700, true)) throw new BackupException('Temporäres Verzeichnis nicht anlegbar.');
        return $d;
    }

    public static function rmTree(string $d): void
    {
        if (!is_dir($d)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { if ($f->isDir() && !$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname()); }
        rmdir($d);
    }

    /** Exklusive Sperre gegen parallele Läufe (Scheduler, Web, CLI). */
    public function withLock(callable $fn, bool $wait = false): mixed
    {
        $fh = fopen(self::stateDir() . '/backup.lock', 'c');
        if (!$fh || !flock($fh, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) throw new BackupException('Ein anderer Backup-/Restore-Vorgang läuft bereits.');
        try { return $fn(); } finally { flock($fh, LOCK_UN); fclose($fh); }
    }

    /* ------------------------------------------------------------ run */

    /** Sichert alle (oder einen) Bereiche. @return array<int,array> Ergebnisse je Bereich */
    public function run(?string $only = null, string $kind = 'scheduled'): array
    {
        return $this->withLock(function () use ($only, $kind) {
            $res = [];
            foreach ($this->scopes() as $s) {
                if ($only !== null && $s['scope'] !== $only) continue;
                $res[] = $this->runScope($s, $kind);
            }
            if ($only !== null && !$res) throw new BackupException("Unbekannter Bereich/Mandant: $only");
            return $res;
        });
    }

    /** Ein Backup eines Bereichs; wirft nie, Ergebnis enthält status ok|warning|failed. */
    public function runScope(array $s, string $kind = 'scheduled'): array
    {
        $store = $this->statusStore();
        $id = $store->start($s['scope'], $kind);
        $tmp = null; $warnings = []; $done = [];
        try {
            $pk = self::publicKey();
            if ($pk === null) throw new BackupException('Kein öffentlicher Backup-Schlüssel konfiguriert (PQ_BACKUP_PUBLIC_KEY[_FILE]). Unverschlüsselte Backups werden nicht erstellt. Schlüssel erzeugen: php bin/backup.php keygen');
            $tmp = self::mkTmp('run');
            $this->say("[{$s['scope']}] Dump von {$s['db']} …");
            $dump = BackupDumper::dump($s['conn'], $s['db'], "$tmp/db.sql.gz");
            $migrations = [];
            try { $migrations = opsAppliedMigrations(BackupDb::pdo($s['conn'], $s['db'])); } catch (Throwable $e) {}
            $parts = [['name' => 'db.sql.gz', 'size' => filesize("$tmp/db.sql.gz"), 'sha256' => hash_file('sha256', "$tmp/db.sql.gz")]];
            $files = ['included' => false, 'count' => 0, 'bytes' => 0];
            if ($s['files'] !== null && is_dir($s['files'])) {
                $gz = gzopen("$tmp/files.tar.gz", 'wb6');
                $tar = new BackupTar(function ($d) use ($gz) { if (gzwrite($gz, $d) === false) throw new BackupException('Schreibfehler (Dateien).'); });
                $exclude = array_filter([realpath(self::backupDir()) ?: '', realpath(self::stateDir()) ?: '']);
                $warnings = array_merge($warnings, $tar->addTree($s['files'], '', $exclude));
                $tar->finish();
                gzclose($gz);
                $files = ['included' => true, 'count' => $tar->files, 'bytes' => $tar->bytes];
                $parts[] = ['name' => 'files.tar.gz', 'size' => filesize("$tmp/files.tar.gz"), 'sha256' => hash_file('sha256', "$tmp/files.tar.gz")];
            }
            $now = time();
            $name = self::archiveName($s['scope'], $kind, $now);
            $manifest = [
                'format' => 'planquadro-backup', 'version' => 1, 'created_at' => gmdate('c', $now), 'kind' => $kind,
                'scope' => $s['scope'], 'mode' => opsMode(), 'tenant' => $s['tenant'], 'host' => gethostname() ?: null,
                'database' => ['name' => $s['db'], 'platform' => $s['platform'], 'server_version' => $dump['server_version'],
                               'dump_method' => $dump['method'], 'tables' => $dump['tables'], 'schema_migrations' => $migrations],
                'files' => $files, 'parts' => $parts,
                'encryption' => ['scheme' => BackupCrypto::SCHEME, 'key_fingerprint' => BackupCrypto::fingerprint($pk)],
            ];
            /* Verschlüsselt direkt ins lokale Ziel schreiben (.part → rename) */
            $key = $s['scope'] . '/' . $name;
            $dst = $this->local()->path($key);
            if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0750, true)) throw new BackupException('Backup-Verzeichnis nicht beschreibbar: ' . dirname($dst));
            $part = dirname($dst) . '/.' . $name . '.part';
            $fh = fopen($part, 'xb');
            if (!$fh) throw new BackupException('Archivdatei nicht anlegbar: ' . $part);
            try {
                $enc = new BackupEncWriter($fh, $pk);
                $tar = new BackupTar([$enc, 'write']);
                $tar->addString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now);
                foreach ($parts as $p) $tar->addFile($p['name'], "$tmp/{$p['name']}");
                $tar->finish();
                $sha = $enc->finish();
                fflush($fh);
                if (function_exists('fsync')) fsync($fh);
            } catch (Throwable $e) { fclose($fh); @unlink($part); throw $e; }
            fclose($fh);
            rename($part, $dst);
            $size = filesize($dst);
            self::rmTree($tmp); $tmp = null;
            $this->local()->put($dst, $key, $size, $sha);   /* prüft Größe + Hash */
            $done[] = 'local';
            $this->say("[{$s['scope']}] $name (" . self::fmtBytes($size) . ') lokal gespeichert');
            foreach (array_slice($this->targets(), 1) as $t) {
                try { $t->put($dst, $key, $size, $sha); $done[] = $t->name(); $this->say("[{$s['scope']}] → {$t->name()} ok"); }
                catch (Throwable $e) { $warnings[] = "Ziel {$t->name()}: " . $e->getMessage(); $this->say("[{$s['scope']}] → {$t->name()} FEHLER: " . $e->getMessage()); }
            }
            if (count($this->targets()) < 2) $warnings[] = 'Kein externes Backup-Ziel konfiguriert (3-2-1-Regel nicht erfüllt).';
            foreach ($this->targets() as $t) {
                try { $deleted = $this->applyRetention($t, $s['scope']); if ($deleted) $this->say("[{$s['scope']}] {$t->name()}: " . count($deleted) . ' alte Archive gelöscht'); }
                catch (Throwable $e) { $warnings[] = "Aufbewahrung {$t->name()}: " . $e->getMessage(); }
            }
            $status = $warnings ? 'warning' : 'ok';
            $store->finish($id, $status, ['archive' => $key, 'size_bytes' => $size, 'sha256' => $sha, 'targets' => $done,
                'message' => $warnings ? implode(' | ', $warnings) : null, 'rows' => array_sum($dump['tables']), 'files' => $files['count']]);
            $this->alerter()->clear('failed:' . $s['scope']);
            if (array_filter($warnings, fn($w) => strpos($w, 'Ziel ') === 0)) {
                $this->alerter()->alert('target:' . $s['scope'], 'Externes Backup-Ziel fehlgeschlagen', implode("\n", $warnings), ['scope' => $s['scope'], 'archive' => $key]);
            }
            return ['scope' => $s['scope'], 'status' => $status, 'archive' => $key, 'size' => $size, 'sha256' => $sha, 'targets' => $done, 'warnings' => $warnings];
        } catch (Throwable $e) {
            if ($tmp) self::rmTree($tmp);
            $msg = $e instanceof BackupException ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage();
            error_log("Planquadro backup [{$s['scope']}]: $msg");
            $store->finish($id, 'failed', ['message' => mb_substr($msg, 0, 2000), 'targets' => $done]);
            $this->alerter()->alert('failed:' . $s['scope'], 'Backup fehlgeschlagen', $msg, ['scope' => $s['scope'], 'kind' => $kind]);
            $this->say("[{$s['scope']}] FEHLER: $msg");
            return ['scope' => $s['scope'], 'status' => 'failed', 'error' => $msg, 'targets' => $done];
        }
    }

    public static function retentionPolicy(): array
    {
        return ['daily' => max(0, (int)opsEnv('PQ_BACKUP_KEEP_DAILY', '7')),
                'weekly' => max(0, (int)opsEnv('PQ_BACKUP_KEEP_WEEKLY', '4')),
                'monthly' => max(0, (int)opsEnv('PQ_BACKUP_KEEP_MONTHLY', '12')),
                'last' => max(1, (int)opsEnv('PQ_BACKUP_KEEP_LAST', '3')),
                'prerestore_days' => max(0, (int)opsEnv('PQ_BACKUP_KEEP_PRERESTORE_DAYS', '30'))];
    }

    /** @return string[] gelöschte Schlüssel */
    public function applyRetention(BackupTarget $t, string $scope): array
    {
        $items = []; $pol = self::retentionPolicy(); $protected = [];
        foreach ($t->list($scope) as $o) {
            $p = self::parseName($o['key']);
            if (!$p || $p['scope'] !== $scope) continue;
            $items[$o['key']] = $p['time'];
            /* Sicherheits-Backups vor einem Restore bleiben eine Mindestzeit erhalten */
            if ($p['kind'] === 'prerestore' && time() - $p['time'] < $pol['prerestore_days'] * 86400) $protected[$o['key']] = true;
        }
        $keep = array_flip(BackupRetention::keep($items, $pol['daily'], $pol['weekly'], $pol['monthly'], $pol['last'])) + $protected;
        $deleted = [];
        foreach ($items as $k => $_) if (!isset($keep[$k])) { $t->delete($k); $deleted[] = $k; }
        return $deleted;
    }

    /* ------------------------------------------------------------ list */

    /** @return array<string,array> Ziel => Archive (jüngste zuerst) */
    public function listArchives(?string $scope = null): array
    {
        $out = [];
        foreach ($this->targets() as $t) {
            try {
                $rows = [];
                foreach ($t->list($scope ?? '') as $o) {
                    $p = self::parseName($o['key']);
                    if (!$p || ($scope !== null && $p['scope'] !== $scope)) continue;
                    $rows[] = $o + $p;
                }
                usort($rows, fn($a, $b) => $b['time'] <=> $a['time']);
                $out[$t->name()] = $rows;
            } catch (Throwable $e) { $out[$t->name()] = ['error' => $e->getMessage()]; }
        }
        return $out;
    }

    /** Lokaler Pfad eines Archivs; lädt es bei Bedarf von einem externen Ziel. @return array{0:string,1:bool} [Pfad, temporär] */
    public function fetchArchive(string $ref): array
    {
        if (is_file($ref)) return [$ref, false];
        $name = basename($ref);
        $p = self::parseName($name);
        if (!$p) throw new BackupException("Archiv nicht gefunden: $ref");
        $key = $p['scope'] . '/' . $name;
        foreach ($this->targets() as $t) {
            if ($t instanceof BackupLocalTarget) { if (is_file($t->path($key))) return [$t->path($key), false]; continue; }
            try {
                $tmp = tempnam(self::tmpBase(), 'pqbak');
                $t->get($key, $tmp);
                $this->say("Archiv von {$t->name()} geladen");
                return [$tmp, true];
            } catch (Throwable $e) { @unlink($tmp); $this->say("{$t->name()}: " . $e->getMessage()); }
        }
        throw new BackupException("Archiv nicht gefunden: $name");
    }

    /* ------------------------------------------------------------ verify */

    /**
     * Entschlüsselt ein Archiv nach $dir, prüft Prüfsummen und gzip-Integrität.
     * @return array Manifest
     */
    public function decryptTo(string $archive, string $keypair, string $dir): array
    {
        $fh = fopen($archive, 'rb');
        if (!$fh) throw new BackupException("Archiv nicht lesbar: $archive");
        try {
            $r = new BackupDecReader($fh, $keypair);
            $entries = BackupTar::extract(fn() => $r->read(), $dir);
        } finally { fclose($fh); }
        if (!isset($entries['manifest.json'])) throw new BackupException('Archiv enthält kein Manifest.');
        $m = json_decode((string)file_get_contents("$dir/manifest.json"), true);
        if (!is_array($m) || ($m['format'] ?? '') !== 'planquadro-backup') throw new BackupException('Manifest ungültig.');
        if ((int)$m['version'] > 1) throw new BackupException('Archivversion ' . (int)$m['version'] . ' wird von dieser Planquadro-Version nicht unterstützt.');
        foreach ($m['parts'] as $p) {
            $f = "$dir/{$p['name']}";
            if (!is_file($f)) throw new BackupException("Teil fehlt: {$p['name']}");
            if (filesize($f) !== (int)$p['size'] || !hash_equals($p['sha256'], hash_file('sha256', $f))) throw new BackupException("Prüfsumme falsch: {$p['name']}");
            $gz = gzopen($f, 'rb');
            while (!gzeof($gz)) { if (gzread($gz, 1 << 20) === false) { gzclose($gz); throw new BackupException("gzip beschädigt: {$p['name']}"); } }
            gzclose($gz);
        }
        return $m;
    }

    public function verify(string $ref, string $keypair): array
    {
        [$path, $temp] = $this->fetchArchive($ref);
        $dir = self::mkTmp('verify');
        try { return $this->decryptTo($path, $keypair, $dir); }
        finally { self::rmTree($dir); if ($temp) @unlink($path); }
    }

    /* ------------------------------------------------------------ restore */

    /**
     * @param array $o target_db, tenant, files (bool), files_dir, replace (bool), yes (bool)
     */
    public function restore(string $ref, string $keypair, array $o): array
    {
        return $this->withLock(function () use ($ref, $keypair, $o) {
            [$path, $temp] = $this->fetchArchive($ref);
            $dir = self::mkTmp('restore');
            try {
                $m = $this->decryptTo($path, $keypair, $dir);
                $scope = $m['scope'];
                if (!empty($o['tenant']) && $o['tenant'] !== $scope) throw new BackupException("Archiv gehört zu '$scope', nicht zu '{$o['tenant']}'.");
                $conn = !empty($m['database']['platform']) ? self::platformConn() : self::mainConn();
                $replace = !empty($o['replace']);
                if ($replace && empty($o['yes'])) throw new BackupException('--replace ersetzt Produktivdaten. Zur Bestätigung zusätzlich --yes angeben.');
                $target = $o['target_db'] ?? '';
                $prodScope = null;
                try { $prodScope = $this->scope($scope); } catch (Throwable $e) {}
                if ($target === '' && $replace && $prodScope) $target = $prodScope['db'];
                if ($target === '') throw new BackupException('--target-db=<name> fehlt.');
                if (!BackupDb::validName($target)) throw new BackupException("Ungültiger Datenbankname: $target");
                $root = BackupDb::pdo($conn);
                $nonEmpty = BackupDb::exists($root, $target) && BackupDb::tableCount($root, $target) > 0;
                if ($nonEmpty && !$replace) throw new BackupException("Zieldatenbank $target ist nicht leer. Restore nur in eine neue/leere Datenbank – oder bewusst mit --replace --yes (vorher wird automatisch ein Sicherheits-Backup erstellt).");
                $result = ['scope' => $scope, 'archive' => basename($path), 'target_db' => $target, 'created_at' => $m['created_at']];

                if (!$nonEmpty) {
                    $root->exec("CREATE DATABASE IF NOT EXISTS `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $this->say("Spiele Dump in $target ein …");
                    $result['loader'] = BackupSqlLoader::load($conn, $target, "$dir/db.sql.gz");
                    $result['row_check'] = $this->compareCounts($m, BackupDb::rowCounts(BackupDb::pdo($conn, $target)));
                } else {
                    /* 1. Sicherheits-Backup des aktuellen Stands */
                    $safety = $this->runScope(['scope' => $scope, 'db' => $target, 'conn' => $conn,
                        'files' => !empty($o['files']) && $prodScope ? $prodScope['files'] : null,
                        'platform' => !empty($m['database']['platform']), 'tenant' => $m['tenant'] ?? null], 'prerestore');
                    if ($safety['status'] === 'failed') throw new BackupException('Sicherheits-Backup vor dem Restore fehlgeschlagen – Abbruch: ' . ($safety['error'] ?? ''));
                    $result['safety_backup'] = $safety['archive'];
                    $this->say('Sicherheits-Backup: ' . $safety['archive']);
                    /* 2. In Staging-Datenbank einspielen und prüfen */
                    $prefix = opsEnv('PQ_BACKUP_TEMP_DB_PREFIX', 'planquadro_rt_');
                    $suffix = gmdate('YmdHis') . substr(bin2hex(random_bytes(2)), 0, 3);
                    $stage = substr($prefix . 'stage_' . $suffix, 0, 64);
                    $old = substr($prefix . 'old_' . $suffix, 0, 64);
                    $root->exec("CREATE DATABASE `$stage` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    try {
                        $this->say("Spiele Dump in Staging-Datenbank $stage ein …");
                        $result['loader'] = BackupSqlLoader::load($conn, $stage, "$dir/db.sql.gz");
                        $result['row_check'] = $this->compareCounts($m, BackupDb::rowCounts(BackupDb::pdo($conn, $stage)));
                        if (!$result['row_check']['ok']) throw new BackupException('Zeilenzahlen nach dem Einspielen stimmen nicht mit dem Manifest überein – Produktivdatenbank bleibt unverändert.');
                        /* 3. Atomarer Tausch per RENAME TABLE */
                        $this->swap($conn, $root, $target, $stage, $old);
                    } catch (Throwable $e) {
                        try { $root->exec("DROP DATABASE IF EXISTS `$stage`"); } catch (Throwable $e2) {}
                        throw $e;
                    }
                    $root->exec("DROP DATABASE IF EXISTS `$stage`");
                    $result['previous_db'] = $old;
                    $this->say("Vorheriger Stand liegt in Datenbank $old (nach Prüfung löschen: DROP DATABASE `$old`).");
                }

                if (!empty($o['files']) && !empty($m['files']['included'])) {
                    $dest = $o['files_dir'] ?? '';
                    if ($dest === '') $dest = $replace && $prodScope ? $prodScope['files'] : self::storageBase() . '/_restore/' . $scope . '-' . gmdate('Ymd-His');
                    $dest = rtrim($dest, '/');
                    if (is_dir($dest) && (new FilesystemIterator($dest))->valid()) {
                        if (!$replace) throw new BackupException("Zielordner $dest ist nicht leer.");
                        $aside = $dest . '.pre-restore-' . gmdate('Ymd-His');
                        if (!rename($dest, $aside)) throw new BackupException("Kann $dest nicht beiseitelegen.");
                        $result['previous_files'] = $aside;
                    }
                    $gz = gzopen("$dir/files.tar.gz", 'rb');
                    try { $ex = BackupTar::extract(function () use ($gz) { if (gzeof($gz)) return null; $d = gzread($gz, 262144); if ($d === false) throw new BackupException('gzip-Fehler'); return $d; }, $dest); }
                    finally { gzclose($gz); }
                    $result['files_dir'] = $dest;
                    $result['files'] = count($ex);
                    if (count($ex) !== (int)$m['files']['count']) throw new BackupException('Anzahl wiederhergestellter Dateien stimmt nicht mit dem Manifest überein.');
                }
                return $result;
            } finally {
                self::rmTree($dir);
                if ($temp) @unlink($path);
            }
        }, true);
    }

    private function swap(array $conn, PDO $root, string $target, string $stage, string $old): void
    {
        foreach ([$target, $stage] as $db) {
            $s = $root->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW'");
            $s->execute([$db]);
            $v = (int)$s->fetchColumn();
            $s = $root->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?');
            $s->execute([$db]);
            if ($v + (int)$s->fetchColumn() > 0) throw new BackupException("Datenbank $db enthält Views/Trigger – automatischer Tausch nicht möglich. Bitte in eine neue Datenbank wiederherstellen und die Anwendung darauf umstellen.");
        }
        $tables = function (string $db) use ($root) {
            $s = $root->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
            $s->execute([$db]);
            return $s->fetchAll(PDO::FETCH_COLUMN);
        };
        $q = fn($n) => '`' . str_replace('`', '``', $n) . '`';
        $root->exec("CREATE DATABASE `$old` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pairs = [];
        foreach ($tables($target) as $t) $pairs[] = "{$q($target)}.{$q($t)} TO {$q($old)}.{$q($t)}";
        foreach ($tables($stage) as $t) $pairs[] = "{$q($stage)}.{$q($t)} TO {$q($target)}.{$q($t)}";
        if ($pairs) $root->exec('RENAME TABLE ' . implode(', ', $pairs));   /* atomar */
        /* Routinen (nur Hilfsprozeduren der Alt-Migrationen) nachziehen */
        $r = $root->prepare('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?');
        $r->execute([$target]);
        foreach ($r->fetchAll() as $row) $root->exec("DROP {$row['ROUTINE_TYPE']} IF EXISTS {$q($target)}.{$q($row['ROUTINE_NAME'])}");
        $r->execute([$stage]);
        $routines = $r->fetchAll();
        if ($routines) {
            $sp = BackupDb::pdo($conn, $stage);
            $tp = BackupDb::pdo($conn, $target);
            foreach ($routines as $row) {
                $c = $sp->query("SHOW CREATE {$row['ROUTINE_TYPE']} {$q($row['ROUTINE_NAME'])}")->fetch(PDO::FETCH_NUM)[2] ?? null;
                if ($c) $tp->exec(BackupSqlLoader::filterLine($c));
            }
        }
    }

    public function compareCounts(array $manifest, array $actual): array
    {
        $diff = [];
        foreach ($manifest['database']['tables'] as $t => $n) {
            $a = $actual[$t] ?? null;
            if ($a !== (int)$n) $diff[$t] = ['expected' => (int)$n, 'actual' => $a];
        }
        foreach ($actual as $t => $n) if (!array_key_exists($t, $manifest['database']['tables'])) $diff[$t] = ['expected' => null, 'actual' => $n];
        return ['ok' => !$diff, 'tables' => count($manifest['database']['tables']), 'rows' => array_sum($manifest['database']['tables']), 'diff' => $diff];
    }

    /* ------------------------------------------------------------ restore-test */

    /** Spielt das jüngste Archiv jedes Bereichs in eine Wegwerf-Datenbank ein und vergleicht. */
    public function restoreTest(string $keypair, ?string $only = null): array
    {
        return $this->withLock(function () use ($keypair, $only) {
            $res = [];
            $scopes = array_map(fn($s) => $s['scope'], $this->scopes());
            foreach ($scopes as $scope) {
                if ($only !== null && $scope !== $only) continue;
                $res[] = $this->restoreTestScope($scope, $keypair);
            }
            return $res;
        }, true);
    }

    private function restoreTestScope(string $scope, string $keypair): array
    {
        $store = $this->statusStore();
        $id = $store->start($scope, 'restore_test');
        $tmpDb = null; $conn = null; $dir = null; $path = null; $temp = false;
        try {
            $latest = null; $from = null;
            /* Nur reguläre Backups – Sicherheits-Backups vor einem Restore können eine andere Datenbank enthalten */
            foreach ($this->listArchives($scope) as $tn => $rows) {
                if (isset($rows['error'])) continue;
                foreach ($rows as $r) {
                    if ($r['kind'] === 'prerestore') continue;
                    if (!$latest || $r['time'] > $latest['time']) { $latest = $r; $from = $tn; }
                    break;
                }
            }
            if (!$latest) throw new BackupException('Kein Archiv für diesen Bereich gefunden.');
            [$path, $temp] = $this->fetchArchive($latest['key']);
            $dir = self::mkTmp('rtest');
            $m = $this->decryptTo($path, $keypair, $dir);
            $conn = !empty($m['database']['platform']) ? self::platformConn() : self::mainConn();
            $tmpDb = substr(opsEnv('PQ_BACKUP_TEMP_DB_PREFIX', 'planquadro_rt_') . gmdate('YmdHis') . bin2hex(random_bytes(2)), 0, 64);
            $root = BackupDb::pdo($conn);
            $root->exec("CREATE DATABASE `$tmpDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $t0 = microtime(true);
            BackupSqlLoader::load($conn, $tmpDb, "$dir/db.sql.gz");
            $cmp = $this->compareCounts($m, BackupDb::rowCounts(BackupDb::pdo($conn, $tmpDb)));
            $migOk = opsAppliedMigrations(BackupDb::pdo($conn, $tmpDb)) == ($m['database']['schema_migrations'] ?? []);
            $filesOk = true; $fileCount = 0;
            if (!empty($m['files']['included'])) {
                $gz = gzopen("$dir/files.tar.gz", 'rb');
                try { $fileCount = count(BackupTar::extract(function () use ($gz) { if (gzeof($gz)) return null; $d = gzread($gz, 262144); return $d === false ? throw new BackupException('gzip-Fehler') : $d; }, "$dir/files")); }
                finally { gzclose($gz); }
                $filesOk = $fileCount === (int)$m['files']['count'];
            }
            $secs = round(microtime(true) - $t0, 1);
            $ok = $cmp['ok'] && $migOk && $filesOk;
            $age = round((time() - $latest['time']) / 3600, 1);
            $msg = sprintf('%s von %s: %d Tabellen, %d Zeilen%s, Migrationen %s, %d Dateien %s, Dauer %ss, Alter %sh',
                basename($latest['key']), $from, $cmp['tables'], $cmp['rows'], $cmp['ok'] ? ' identisch' : ' ABWEICHUNG ' . json_encode($cmp['diff']),
                $migOk ? 'identisch' : 'ABWEICHEND', $fileCount, $filesOk ? 'ok' : 'ABWEICHEND', $secs, $age);
            $store->finish($id, $ok ? 'ok' : 'failed', ['archive' => $latest['key'], 'size_bytes' => $latest['size'], 'targets' => [$from], 'message' => $msg]);
            if (!$ok) $this->alerter()->alert('restoretest:' . $scope, 'Restore-Test fehlgeschlagen', $msg, ['scope' => $scope]);
            else $this->alerter()->clear('restoretest:' . $scope);
            $this->say("[$scope] Restore-Test " . ($ok ? 'OK' : 'FEHLGESCHLAGEN') . ": $msg");
            return ['scope' => $scope, 'status' => $ok ? 'ok' : 'failed', 'archive' => $latest['key'], 'message' => $msg, 'diff' => $cmp['diff']];
        } catch (Throwable $e) {
            $store->finish($id, 'failed', ['message' => mb_substr($e->getMessage(), 0, 2000)]);
            $this->alerter()->alert('restoretest:' . $scope, 'Restore-Test fehlgeschlagen', $e->getMessage(), ['scope' => $scope]);
            $this->say("[$scope] Restore-Test FEHLER: " . $e->getMessage());
            return ['scope' => $scope, 'status' => 'failed', 'error' => $e->getMessage()];
        } finally {
            if ($tmpDb && $conn) { try { BackupDb::pdo($conn)->exec("DROP DATABASE IF EXISTS `$tmpDb`"); } catch (Throwable $e) {} }
            if ($dir) self::rmTree($dir);
            if ($temp && $path) @unlink($path);
        }
    }

    /* ------------------------------------------------------------ status */

    /** Zusammenfassung je Bereich + globale Warnungen. */
    public function status(?array $onlyScopes = null): array
    {
        $store = $this->statusStore();
        $max = self::maxAgeHours();
        $warn = [];
        $hasKey = false;
        try { $hasKey = self::publicKey() !== null; } catch (Throwable $e) { $warn[] = $e->getMessage(); }
        if (!$hasKey) $warn[] = 'Kein öffentlicher Backup-Schlüssel konfiguriert – es werden keine Backups erstellt.';
        $targets = array_map(fn($t) => $t->name(), $this->targets());
        if (count($targets) < 2) $warn[] = 'Kein externes Backup-Ziel konfiguriert (3-2-1-Regel nicht erfüllt): Backups liegen nur auf diesem Server.';
        $scopes = [];
        foreach ($onlyScopes ?? array_map(fn($s) => $s['scope'], $this->scopes()) as $scope) {
            $runs = $store->runs($scope, 100);
            $backups = array_values(array_filter($runs, fn($r) => in_array($r['kind'], self::KINDS, true) && $r['status'] !== 'running'));
            $success = array_values(array_filter($backups, fn($r) => in_array($r['status'], ['ok', 'warning'], true)));
            $tests = array_values(array_filter($runs, fn($r) => $r['kind'] === 'restore_test' && $r['status'] !== 'running'));
            $last = $backups[0] ?? null;
            $lastOk = $success[0] ?? null;
            $age = $lastOk ? round((time() - strtotime($lastOk['finished_at'] ?? $lastOk['started_at'])) / 3600, 1) : null;
            $sw = [];
            if (!$lastOk) $sw[] = 'Noch kein erfolgreiches Backup.';
            elseif ($age > $max) $sw[] = "Letztes erfolgreiches Backup ist {$age} h alt (Grenze {$max} h).";
            if ($last && $last['status'] === 'failed') $sw[] = 'Letzter Backup-Lauf ist fehlgeschlagen: ' . mb_substr((string)$last['message'], 0, 300);
            if ($last && $last['status'] === 'warning') {
                /* Die 3-2-1-Warnung steht bereits global – hier nur weitere Hinweise */
                $rest = array_filter(explode(' | ', (string)$last['message']), fn($w) => strpos($w, '3-2-1') === false && trim($w) !== '');
                if ($rest) $sw[] = 'Letzter Backup-Lauf mit Warnung: ' . mb_substr(implode(' | ', $rest), 0, 300);
            }
            $lt = $tests[0] ?? null;
            if (!$lt) $sw[] = 'Noch kein Restore-Test durchgeführt.';
            elseif ($lt['status'] !== 'ok') $sw[] = 'Letzter Restore-Test fehlgeschlagen.';
            elseif (time() - strtotime($lt['finished_at']) > 8 * 86400) $sw[] = 'Letzter erfolgreicher Restore-Test ist älter als 8 Tage.';
            $scopes[$scope] = [
                'healthy' => $lastOk !== null && $age <= $max && (!$last || $last['status'] !== 'failed'),
                'last_run' => $last, 'last_success' => $lastOk, 'age_hours' => $age, 'last_restore_test' => $lt, 'warnings' => $sw,
            ];
        }
        return ['generated_at' => gmdate('c'), 'mode' => opsMode(), 'max_age_hours' => $max, 'targets' => $targets,
                'retention' => self::retentionPolicy(), 'encryption' => $hasKey, 'warnings' => $warn, 'scopes' => $scopes,
                'external_target' => count($targets) > 1,
                'healthy' => $hasKey && !array_filter($scopes, fn($s) => !$s['healthy'])];
    }

    /** Alarmiert bei veralteten Backups (vom Scheduler stündlich aufgerufen). */
    public function checkStale(): array
    {
        $st = $this->status();
        $sent = [];
        foreach ($st['scopes'] as $scope => $s) {
            if ($s['age_hours'] === null || $s['age_hours'] > $st['max_age_hours']) {
                $msg = $s['age_hours'] === null ? 'Es gibt noch kein erfolgreiches Backup.' : "Letztes erfolgreiches Backup ist {$s['age_hours']} h alt.";
                if ($this->alerter()->alert('stale:' . $scope, 'Backup veraltet', $msg, ['scope' => $scope])) $sent[] = $scope;
            } else $this->alerter()->clear('stale:' . $scope);
        }
        return $sent;
    }

    public static function fmtBytes(int $b): string
    {
        if ($b < 1024) return $b . ' B';
        if ($b < 1048576) return round($b / 1024, 1) . ' KB';
        if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
        return round($b / 1073741824, 2) . ' GB';
    }

    /**
     * Kurzer Hinweis für das Dashboard (nur Nutzer mit manage_settings) oder null.
     * Abschaltbar mit PQ_BACKUP_BANNER=0.
     */
    public static function dashboardWarning(): ?string
    {
        if (opsEnv('PQ_BACKUP_BANNER', '1') === '0') return null;
        try {
            $b = new self();
            $scope = $b->currentScope()['scope'];
            $st = $b->status([$scope]);
            $s = $st['scopes'][$scope];
            if (!$st['encryption']) return 'Backups sind nicht eingerichtet (kein öffentlicher Backup-Schlüssel).';
            if ($s['last_run'] && $s['last_run']['status'] === 'failed') return 'Der letzte Backup-Lauf ist fehlgeschlagen.';
            if ($s['age_hours'] === null) return 'Es gibt noch kein erfolgreiches Backup.';
            if ($s['age_hours'] > $st['max_age_hours']) return 'Das letzte erfolgreiche Backup ist ' . (int)round($s['age_hours']) . ' Stunden alt.';
        } catch (Throwable $e) {
            error_log('Planquadro backup status: ' . $e->getMessage());
        }
        return null;
    }

    /* ------------------------------------------------------------ Alt-Format */

    /** Entschlüsselt ein .epbak der Vorversion (AES-256-CBC, ohne Integritätsschutz). */
    public static function legacyDecrypt(string $file, string $keyFile): string
    {
        $key = trim((string)@file_get_contents($keyFile));
        if (!preg_match('/^[0-9a-f]{64}$/i', $key)) throw new BackupException('Schlüsseldatei ungültig (erwartet 64 Hex-Zeichen, z. B. app/backups/.backup_key).');
        $raw = base64_decode((string)@file_get_contents($file), true);
        if ($raw === false || strlen($raw) < 17) throw new BackupException('Datei ist kein gültiges .epbak-Archiv.');
        $sql = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', hex2bin($key), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        if ($sql === false || strpos($sql, 'CREATE TABLE') === false) throw new BackupException('Entschlüsselung fehlgeschlagen (falscher Schlüssel oder beschädigte Datei).');
        return $sql;
    }
}
