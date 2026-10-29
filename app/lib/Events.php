<?php
/**
 * Planquadro – Live-Ereignisse (Outbox) und Server-Sent Events
 * --------------------------------------------------------------------------
 * Schreiben: pqEventEmit() legt in der laufenden Transaktion des Aufrufers
 *   ein Ereignis in live_events ab (Migration 0007). Ein Ereignis nennt nur,
 *   WELCHE Zellen (Mitarbeiter, Tag) sich geändert haben und ob der
 *   veröffentlichte Plan betroffen ist – keine Inhalte, keine
 *   Abwesenheitsarten, keine Bemerkungen. Clients laden Details über die
 *   bestehenden, rechtegeprüften Endpunkte nach.
 * Lesen: pqSseStream() liefert die Ereignisse als text/event-stream ab einer
 *   ID (Last-Event-ID), gefiltert mit pqEventForViewer() auf genau die Zellen,
 *   die der Empfänger sehen darf. Die Verbindung endet nach einer begrenzten
 *   Laufzeit; der Client verbindet sich neu und setzt mit Last-Event-ID fort.
 *   Weil die Outbox in der Datenbank liegt, funktioniert das mit beliebig
 *   vielen App-Instanzen und hinter jedem Reverse Proxy, ohne weiteren Dienst.
 *
 * Konfiguration (docs/CONFIGURATION.md): PQ_SSE_ENABLED, PQ_SSE_MAX_SECONDS,
 * PQ_SSE_POLL_MS, PQ_SSE_HEARTBEAT_SECONDS, PQ_EVENTS_RETENTION_HOURS.
 */

const PQ_EVENT_TYPES = ['cells_changed', 'published', 'request_changed', 'presence'];
/** Mehr Zellen werden nicht einzeln gespeichert (dann: ganzer Monat) */
const PQ_EVENT_MAX_CELLS = 2000;
/** So viele Zellen gehen höchstens einzeln an einen Client */
const PQ_EVENT_MAX_SEND_CELLS = 500;
/** Wartezeit auf Lücken in der ID-Folge (noch nicht bestätigte Transaktionen) */
const PQ_EVENT_GAP_WAIT_MS = 3000;
/** Lebensdauer eines Einmal-Tickets für /api/v1/events */
const PQ_EVENT_TICKET_TTL = 30;

function pqSseEnabled(): bool
{
    return (function_exists('opsEnv') ? opsEnv('PQ_SSE_ENABLED', '1') : (getenv('PQ_SSE_ENABLED') ?: '1')) !== '0';
}

function pqSseInt(string $name, int $default, int $min, int $max): int
{
    $v = function_exists('opsEnv') ? opsEnv($name, '') : (string)getenv($name);
    $n = ($v === '' || !is_numeric($v)) ? $default : (int)$v;
    return max($min, min($max, $n));
}

/**
 * Ereignis in die Outbox schreiben (in der Transaktion des Aufrufers).
 *
 * @param array<int, array{0:int,1:string}>|null $cells  betroffene Zellen; null = ganze Monate ($opts['months'])
 * @param array{internal?:bool, months?:string[], data?:array} $opts
 *
 * Fehler beim Schreiben (z. B. fehlende Tabelle vor der Migration) brechen den
 * eigentlichen Vorgang nicht ab – die Live-Aktualisierung ist ein Zusatz, die
 * Clients haben ihren Polling-/Sync-Fallback. Deadlocks und Lock-Timeouts werden
 * weitergereicht, weil sie die Transaktion beenden (opsTransaction wiederholt).
 */
function pqEventEmit(PDO $db, string $type, ?array $cells, bool $published, ?int $actor, array $opts = []): void
{
    if (!in_array($type, PQ_EVENT_TYPES, true)) throw new InvalidArgumentException('Unbekannter Ereignistyp ' . $type);
    $norm = null; $months = [];
    foreach ((array)($opts['months'] ?? []) as $m) if (is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m)) $months[$m] = true;
    if ($cells !== null) {
        $norm = []; $seen = [];
        foreach ($cells as $c) {
            $u = (int)($c[0] ?? 0); $d = (string)($c[1] ?? '');
            if ($u <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
            $k = $u . '|' . $d;
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $norm[] = [$u, $d];
            $months[substr($d, 0, 7)] = true;
        }
        if (!$norm && !$months) return;               /* nichts geändert */
        if (count($norm) > PQ_EVENT_MAX_CELLS) $norm = null;
    }
    ksort($months);
    try {
        $db->prepare('INSERT INTO live_events(event_type, is_published, is_internal, months, cells, data, actor_id) VALUES(?,?,?,?,?,?,?)')
           ->execute([$type, $published ? 1 : 0, !empty($opts['internal']) ? 1 : 0, json_encode(array_keys($months)),
                      $norm === null ? null : json_encode($norm), isset($opts['data']) ? json_encode($opts['data'], JSON_UNESCAPED_UNICODE) : null, $actor]);
    } catch (PDOException $e) {
        $code = (int)($e->errorInfo[1] ?? 0);
        if ($code === 1213 || $code === 1205) throw $e;
        error_log('live_events: ' . $e->getMessage());
    }
}

/** Alle Tage eines Zeitraums für einen Mitarbeiter als Zellen (für Bereichsänderungen). */
function pqEventRangeCells(int $uid, string $from, string $to, int $limit = 400): array
{
    $out = [];
    for ($t = strtotime($from . ' 12:00'), $end = strtotime($to . ' 12:00'); $t <= $end && count($out) < $limit; $t = strtotime('+1 day', $t)) {
        $out[] = [$uid, date('Y-m-d', $t)];
    }
    return $out;
}

/**
 * Rechte des aktuellen Benutzers für die Ereignisfilterung (einmal je Verbindung).
 * Kanal 'web': Session-Benutzer (Monatsplanung); 'app': API v1, nur veröffentlichte Stände.
 */
function pqEventViewer(string $channel): array
{
    $u = currentUser();
    $edit = $channel === 'web' && hasPerm('plan_edit');
    $vis = visibleUserIds();
    return [
        'id'       => (int)($u['id'] ?? 0),
        'channel'  => $channel,
        'drafts'   => $edit,                                       /* Entwürfe und Präsenz */
        'team'     => hasPerm('plan_view') || hasPerm('plan_edit'), /* Zellen anderer im Scope */
        'internal' => $edit && hasPerm('plan_view_internal'),
        'approve'  => $channel === 'web' && hasPerm('approve_requests'),
        'visible'  => $vis === null ? null : array_flip(array_map('intval', $vis)),
    ];
}

/**
 * Reine Funktion: Was darf dieser Empfänger von einem Ereignis sehen?
 * @param array $row  Zeile aus live_events
 * @param array $v    pqEventViewer()
 * @return array|null  Nutzlast für den Client oder null (nicht senden)
 */
function pqEventForViewer(array $row, array $v): ?array
{
    $type = (string)$row['event_type'];
    $published = (bool)(int)$row['is_published'];
    $months = json_decode((string)($row['months'] ?? '[]'), true) ?: [];
    $data = isset($row['data']) && $row['data'] !== null ? (json_decode((string)$row['data'], true) ?: []) : [];
    $actor = isset($row['actor_id']) && $row['actor_id'] !== null ? (int)$row['actor_id'] : null;
    $base = ['type' => $type, 'published' => $published];

    if ($type === 'presence') {
        if ($v['channel'] !== 'web' || !$v['drafts']) return null;
        return $base + ['months' => $months, 'page' => (string)($data['page'] ?? ''), 'action' => (string)($data['action'] ?? ''),
            'own' => $actor === $v['id']];
    }
    if (!empty($row['is_internal']) && empty($v['internal'])) return null;

    /* Wer darf Zellen ANDERER Mitarbeiter aus diesem Ereignis sehen? */
    if ($published) $others = $v['team'] || $v['drafts'];
    elseif ($type === 'request_changed') $others = $v['drafts'] || $v['approve'];
    else $others = $v['drafts'];
    /* Eigene Zellen: veröffentlichte Änderungen und eigene Anträge immer,
       Entwürfe nur für Planer (Mitarbeiter sehen keine Entwürfe). */
    $ownOk = $published || $type === 'request_changed' || $v['drafts'];

    $visible = $v['visible'];
    $cells = $row['cells'] === null ? null : (json_decode((string)$row['cells'], true) ?: []);
    if ($cells === null) {
        /* Ganzer Monat ohne Einzelzellen: nur, wer den Monat ohnehin neu laden darf */
        if (!$others && !($published && $ownOk)) return null;
        return $base + ['months' => $months, 'cells' => null, 'self' => null, 'own' => $actor === $v['id']];
    }
    $keep = []; $self = false; $m = [];
    foreach ($cells as $c) {
        $u = (int)$c[0];
        if ($u === $v['id']) { if (!$ownOk) continue; $self = true; }
        elseif (!$others || ($visible !== null && !isset($visible[$u]))) continue;
        $keep[] = [$u, (string)$c[1]];
        $m[substr((string)$c[1], 0, 7)] = true;
    }
    if (!$keep) {
        /* Veröffentlichung ohne Zelländerung (z. B. leerer Monat): Monatsstand für Planer */
        if (!$cells && ($others || $published)) return $base + ['months' => $months, 'cells' => [], 'self' => false, 'own' => $actor === $v['id']];
        return null;
    }
    ksort($m);
    if (count($keep) > PQ_EVENT_MAX_SEND_CELLS) $keep = null;
    return $base + ['months' => array_keys($m), 'cells' => $keep, 'self' => $self, 'own' => $actor === $v['id']];
}

/** Alte Ereignisse und abgelaufene Tickets löschen; der neueste Eintrag bleibt (Cursor). */
function pqEventsCleanup(PDO $db): void
{
    $hours = pqSseInt('PQ_EVENTS_RETENTION_HOURS', 48, 1, 24 * 90);
    try {
        $max = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM live_events')->fetchColumn();
        $db->prepare('DELETE FROM live_events WHERE id < ? AND created_at < ? LIMIT 5000')->execute([$max, date('Y-m-d H:i:s', time() - $hours * 3600)]);
        $db->prepare('DELETE FROM live_event_tickets WHERE expires_at < ? LIMIT 5000')->execute([date('Y-m-d H:i:s', time() - 3600)]);
    } catch (PDOException $e) { error_log('live_events cleanup: ' . $e->getMessage()); }
}

/* ------------------------------------------------------------------ Tickets (API v1) */

/** Einmal-Ticket für /api/v1/events ausstellen (an Benutzer, Token-Familie und Mandant gebunden). */
function pqEventTicketIssue(PDO $db, int $uid, string $family, string $tenant): string
{
    $ticket = 'pqe.' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $db->prepare('INSERT INTO live_event_tickets(token_hash, user_id, family_id, tenant, expires_at) VALUES(?,?,?,?,?)')
       ->execute([hash('sha256', $ticket), $uid, $family, $tenant, date('Y-m-d H:i:s', time() + PQ_EVENT_TICKET_TTL)]);
    return $ticket;
}

/**
 * Ticket einlösen: genau einmal, nur vor Ablauf, nur im ausstellenden Mandanten,
 * nur solange die Token-Familie gültig und der Benutzer aktiv ist.
 * @return int|null  Benutzer-ID
 */
function pqEventTicketRedeem(PDO $db, string $ticket, string $tenant): ?int
{
    if (!preg_match('/^pqe\.[A-Za-z0-9_-]{43}$/', $ticket)) return null;
    $s = $db->prepare('SELECT t.id, t.user_id, t.family_id, t.tenant, t.expires_at, t.used_at, u.active,
                              (SELECT COUNT(*) FROM api_refresh_tokens r WHERE r.family_id=t.family_id AND r.revoked_at IS NULL) fam
                         FROM live_event_tickets t JOIN users u ON u.id=t.user_id WHERE t.token_hash=?');
    $s->execute([hash('sha256', $ticket)]);
    $t = $s->fetch(PDO::FETCH_ASSOC);
    if (!$t || $t['used_at'] !== null || !hash_equals((string)$t['tenant'], $tenant)) return null;
    if (strtotime((string)$t['expires_at']) < time() || !(int)$t['active'] || (int)$t['fam'] === 0) return null;
    $u = $db->prepare('UPDATE live_event_tickets SET used_at=NOW() WHERE id=? AND used_at IS NULL');
    $u->execute([(int)$t['id']]);
    if ($u->rowCount() !== 1) return null;                    /* parallel eingelöst */
    return (int)$t['user_id'];
}

/* ------------------------------------------------------------------ Stream */

/** Last-Event-ID aus Header oder (für neu aufgebaute Verbindungen) Query-Parameter. */
function pqSseLastEventId(): ?int
{
    $v = $_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['last_event_id'] ?? null);
    if ($v === null || $v === '' || !preg_match('/^\d{1,19}$/', (string)$v)) return null;
    return (int)$v;
}

/** Laufzeit der Verbindung: Konfiguration, auf Wunsch des Clients kürzer (?max=). */
function pqSseMaxSeconds(): int
{
    $cfg = pqSseInt('PQ_SSE_MAX_SECONDS', 40, 5, 300);
    if (isset($_GET['max']) && is_numeric($_GET['max'])) $cfg = max(1, min($cfg, (int)$_GET['max']));
    return $cfg;
}

function pqSseSend(string $chunk): bool
{
    echo $chunk;
    @flush();
    return !connection_aborted();
}

/** Ereignisstrom. Muss aufgerufen werden, bevor irgendetwas ausgegeben wurde. */
function pqSseStream(PDO $db, array $viewer, ?int $lastId, int $maxSeconds): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    while (ob_get_level() > 0) @ob_end_clean();
    ignore_user_abort(false);
    @set_time_limit($maxSeconds + 30);
    http_response_code(200);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, no-transform');
    header('X-Accel-Buffering: no');
    header('X-Content-Type-Options: nosniff');

    $pollUs = pqSseInt('PQ_SSE_POLL_MS', 1000, 200, 10000) * 1000;
    $hb = pqSseInt('PQ_SSE_HEARTBEAT_SECONDS', 15, 2, 120);
    if (random_int(1, 50) === 1) pqEventsCleanup($db);

    $step = 1;
    try { $step = max(1, (int)$db->query('SELECT @@auto_increment_increment')->fetchColumn()); } catch (PDOException $e) {}
    $r = $db->query('SELECT COALESCE(MIN(id),0) mi, COALESCE(MAX(id),0) ma FROM live_events')->fetch(PDO::FETCH_ASSOC);
    $min = (int)$r['mi']; $max = (int)$r['ma'];

    if (!pqSseSend("retry: 3000\n\n")) return;
    if ($lastId === null) {
        $cursor = $max;
        if (!pqSseSend("id: $cursor\nevent: ready\ndata: " . json_encode(['cursor' => (string)$cursor]) . "\n\n")) return;
    } elseif ($lastId > $max || ($min > 0 && $lastId < $min - 1)) {
        /* Cursor unbekannt oder Ereignisse bereits aufgeräumt → Client lädt neu */
        $cursor = $max;
        if (!pqSseSend("id: $cursor\nevent: reset\ndata: " . json_encode(['cursor' => (string)$cursor]) . "\n\n")) return;
    } else {
        $cursor = $lastId;
        if (!pqSseSend("event: ready\ndata: " . json_encode(['cursor' => (string)$cursor]) . "\n\n")) return;
    }

    $sentId = $cursor; $lastOut = microtime(true); $gapSince = null;
    $deadline = microtime(true) + $maxSeconds;
    $q = $db->prepare('SELECT id, event_type, is_published, is_internal, months, cells, data, actor_id FROM live_events WHERE id > ? ORDER BY id LIMIT 200');
    while (true) {
        $q->execute([$cursor]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $q->closeCursor();
        $buf = '';
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            /* Lücke: eine frühere Transaktion ist evtl. noch nicht bestätigt → kurz warten */
            if ($step === 1 && $id > $cursor + 1) {
                $now = microtime(true);
                if ($gapSince === null) { $gapSince = $now; break; }
                if (($now - $gapSince) * 1000 < PQ_EVENT_GAP_WAIT_MS) break;
            }
            $gapSince = null;
            $cursor = $id;
            $out = pqEventForViewer($row, $viewer);
            if ($out === null) continue;
            $buf .= "id: $id\nevent: {$row['event_type']}\ndata: " . json_encode($out, JSON_UNESCAPED_SLASHES) . "\n\n";
            $sentId = $id;
        }
        if ($buf !== '') { if (!pqSseSend($buf)) return; $lastOut = microtime(true); }
        $now = microtime(true);
        if ($now >= $deadline) break;
        if ($now - $lastOut >= $hb) {
            /* Nur "id:" ohne data aktualisiert die Last-Event-ID des Clients, ohne ein Ereignis auszulösen */
            $chunk = $cursor !== $sentId ? "id: $cursor\n\n" : ": hb\n\n";
            $sentId = $cursor;
            if (!pqSseSend($chunk)) return;
            $lastOut = $now;
        }
        if (count($rows) < 200 || $gapSince !== null) usleep((int)min($pollUs, max(0, ($deadline - $now) * 1e6)));
    }
    pqSseSend(($cursor !== $sentId ? "id: $cursor\n" : '') . "event: bye\ndata: {}\n\n");
}
