<?php
/**
 * Planquadro – Sitzungen in der Datenbank (optional)
 * --------------------------------------------------------------------------
 *   PQ_SESSION_STORE=files  (Standard) PHP-Dateisessions wie bisher
 *   PQ_SESSION_STORE=db     Tabelle `sessions` – für mehrere App-Container
 *                           hinter einem Load Balancer
 *
 * Speicherort: selfhosted in PQ_DB_NAME, SaaS in der Datenbank des
 * aufgelösten Mandanten (Trennung bleibt strukturell, zusätzlich zu
 * opsBindSessionToTenant() und dem Cookie-Namen je Mandant); die
 * Plattform-Administration (/platform/) nutzt die Tabelle `sessions` der
 * Plattform-Datenbank.
 *
 * Sicherheit:
 *  - gespeichert wird nur SHA-256 der Session-ID, nie die ID selbst
 *  - session.use_strict_mode greift (validateId()): unbekannte oder
 *    abgelaufene IDs werden verworfen, PHP vergibt eine neue
 *  - je Session eine Sperre (GET_LOCK) wie flock() bei Dateisessions:
 *    parallele Requests derselben Sitzung überschreiben sich nicht.
 *    Wartezeit PQ_SESSION_LOCK_TIMEOUT (Standard 10 s), für Polling-Requests
 *    (live_status, notifications, Ereignisstrom api/events) PQ_SESSION_POLL_LOCK_TIMEOUT (Standard 1 s).
 *    Bekommt ein Request die Sperre nicht, liest er die Sitzung und schreibt
 *    sie am Ende nicht zurück (nur lesend) – Polling blockiert nicht.
 *  - eine während des Requests gelöschte Sitzung (Logout/Regenerate in
 *    einem parallelen Request) wird nicht wieder angelegt
 *  - Ablauf: Zeilen älter als PQ_SESSION_LIFETIME Sekunden (Standard 86400,
 *    mindestens das maximale Leerlauf-Timeout von 1440 Minuten) gelten als
 *    nicht vorhanden; Aufräumen über PHPs GC und stündlich im Scheduler.
 *    Das Leerlauf-Timeout der Anwendung (opsEnforceSessionTimeout) ist
 *    davon unabhängig und unverändert.
 */

function opsSessionStore(): string
{
    return strtolower(opsEnv('PQ_SESSION_STORE', 'files')) === 'db' ? 'db' : 'files';
}

function opsSessionLifetime(): int
{
    return max(300, (int)opsEnv('PQ_SESSION_LIFETIME', '86400'));
}

/**
 * Registriert den DB-Handler vor session_start(), falls PQ_SESSION_STORE=db.
 * $target: 'tenant' (Anwendung) oder 'platform' (Plattform-Administration).
 */
function opsUseSessionStore(string $target = 'tenant'): bool
{
    if (opsSessionStore() !== 'db' || session_status() !== PHP_SESSION_NONE) return false;
    $lifetime = opsSessionLifetime();
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $poll = strpos($uri, 'live_status') !== false || strpos($uri, 'notifications') !== false || strpos($uri, '/api/events') !== false;
    $wait = $poll ? (int)opsEnv('PQ_SESSION_POLL_LOCK_TIMEOUT', '1') : (int)opsEnv('PQ_SESSION_LOCK_TIMEOUT', '10');
    $connect = $target === 'platform'
        ? fn() => opsSessionPdo(opsEnv('PQ_PLATFORM_DB_HOST', DB_HOST), opsEnv('PQ_PLATFORM_DB_PORT', DB_PORT), opsPlatformDbName(),
                                opsEnv('PQ_PLATFORM_DB_USER', DB_USER), opsEnv('PQ_PLATFORM_DB_PASS', DB_PASS))
        : fn() => opsSessionPdo(DB_HOST, DB_PORT, opsTenantDbName(), DB_USER, DB_PASS);
    session_set_save_handler(new PqDbSessionHandler($connect, $lifetime, max(0, $wait)), true);
    return true;
}

/** Eigene Verbindung für Sitzungen: unabhängig von Transaktionen der Anwendung auf getDB(). */
function opsSessionPdo(string $host, string $port, string $db, string $user, string $pass): PDO
{
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int)$port, $db), $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
    ]);
}

/** Löscht abgelaufene Sitzungen einer Datenbank; liefert die Anzahl. */
function opsSessionGc(PDO $db, ?int $lifetime = null): int
{
    $s = $db->prepare('DELETE FROM sessions WHERE last_activity < ?');
    $s->execute([time() - ($lifetime ?? opsSessionLifetime())]);
    return $s->rowCount();
}

final class PqDbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** Zeitstempel höchstens so oft aktualisieren, wenn sich die Daten nicht ändern (Polling). */
    private const TOUCH_INTERVAL = 60;

    private ?PDO $db = null;
    private ?string $lock = null;
    private bool $readOnly = false;
    private bool $existed = false;
    private int $readAt = 0;
    private ?string $dbName = null;

    /** @param callable():PDO $connect */
    public function __construct(private $connect, private int $lifetime, private int $lockWait) {}

    public static function hashId(string $id): string { return hash('sha256', $id); }

    private function db(): PDO
    {
        return $this->db ??= ($this->connect)();
    }

    public function open(string $path, string $name): bool
    {
        try {
            $this->db();
            return true;
        } catch (PDOException $e) {
            error_log('Planquadro session store: Verbindung fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    public function close(): bool
    {
        $this->unlock();
        $this->readOnly = false;
        $this->existed = false;
        return true;
    }

    private function unlock(): void
    {
        if ($this->lock === null || !$this->db) return;
        try { $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->lock]); } catch (PDOException $e) {}
        $this->lock = null;
    }

    /** Sperrname je Datenbank und Sitzung (max. 64 Zeichen). */
    private function lockName(string $hash): string
    {
        $this->dbName ??= (string)$this->db()->query('SELECT DATABASE()')->fetchColumn();
        return 'pq_sess_' . substr(hash('sha256', $this->dbName . ':' . $hash), 0, 48);
    }

    public function read(string $id): string|false
    {
        $hash = self::hashId($id);
        try {
            $this->unlock();
            $name = $this->lockName($hash);
            $s = $this->db()->prepare('SELECT GET_LOCK(?, ?)');
            $s->execute([$name, $this->lockWait]);
            if ((int)$s->fetchColumn() === 1) {
                $this->lock = $name;
                $this->readOnly = false;
            } else {
                /* Ein anderer Request derselben Sitzung läuft noch: lesen, aber nicht zurückschreiben */
                $this->readOnly = true;
            }
            $s = $this->db()->prepare('SELECT data, last_activity FROM sessions WHERE id_hash = ? AND last_activity >= ?');
            $s->execute([$hash, time() - $this->lifetime]);
            $row = $s->fetch();
            $this->existed = (bool)$row;
            $this->readAt = $row ? (int)$row['last_activity'] : 0;
            return $row ? (string)$row['data'] : '';
        } catch (PDOException $e) {
            error_log('Planquadro session store: Lesen fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    public function write(string $id, string $data): bool
    {
        if ($this->readOnly) return true;
        $hash = self::hashId($id);
        $now = time();
        try {
            if ($this->existed) {
                $s = $this->db()->prepare('UPDATE sessions SET data = ?, last_activity = ? WHERE id_hash = ?');
                $s->execute([$data, $now, $hash]);
                /* 0 Zeilen: parallel gelöscht (Logout, Regenerate) – nicht wieder anlegen */
                return true;
            }
            $this->db()->prepare('INSERT INTO sessions (id_hash, data, last_activity, created_at) VALUES (?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE data = ?, last_activity = ?')
                ->execute([$hash, $data, $now, $now, $data, $now]);
            $this->existed = true;
            $this->readAt = $now;
            return true;
        } catch (PDOException $e) {
            error_log('Planquadro session store: Schreiben fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->db()->prepare('DELETE FROM sessions WHERE id_hash = ?')->execute([self::hashId($id)]);
            $this->existed = false;
            return true;
        } catch (PDOException $e) {
            error_log('Planquadro session store: Löschen fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return opsSessionGc($this->db(), max($max_lifetime, $this->lifetime));
        } catch (PDOException $e) {
            error_log('Planquadro session store: GC fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    /** Strict Mode: nur vorhandene, nicht abgelaufene Sitzungen sind gültig. */
    public function validateId(string $id): bool
    {
        try {
            $s = $this->db()->prepare('SELECT 1 FROM sessions WHERE id_hash = ? AND last_activity >= ?');
            $s->execute([self::hashId($id), time() - $this->lifetime]);
            return (bool)$s->fetchColumn();
        } catch (PDOException $e) {
            error_log('Planquadro session store: Prüfen fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    /** Daten unverändert (lazy_write): nur den Zeitstempel auffrischen, gedrosselt. */
    public function updateTimestamp(string $id, string $data): bool
    {
        if ($this->readOnly) return true;
        if (!$this->existed) return $this->write($id, $data);
        if (time() - $this->readAt < self::TOUCH_INTERVAL) return true;
        try {
            $this->db()->prepare('UPDATE sessions SET last_activity = ? WHERE id_hash = ?')->execute([time(), self::hashId($id)]);
            return true;
        } catch (PDOException $e) {
            error_log('Planquadro session store: Zeitstempel fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }
}
