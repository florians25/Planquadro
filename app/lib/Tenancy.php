<?php
/**
 * OpsPilot – Mandantenauflösung
 * --------------------------------------------------------------------------
 * Modell: eine Datenbank je Mandant (gleiches Schema), dazu eine zentrale
 * Plattform-Datenbank (Mandanten, Domains, Plattform-Admins, Backup-Status).
 *
 *   OPSPILOT_MODE=selfhosted (Standard)  genau ein Mandant, Datenbank =
 *                                         OPSPILOT_DB_NAME, keine Plattform-DB nötig
 *   OPSPILOT_MODE=saas                    Mandant aus dem Hostnamen
 *                                         (tenant_domains) oder – nur für die API
 *                                         auf dem Plattform-Host – aus dem Header
 *                                         X-OpsPilot-Tenant
 *
 * Isolation: Jede Abfrage der Anwendung läuft über getDB(), und getDB()
 * verbindet ausschließlich mit der Datenbank des aufgelösten Mandanten. Es
 * gibt keinen Codepfad, der Daten eines anderen Mandanten lesen könnte.
 * Sessions werden an den Mandanten gebunden (opsBindSessionToTenant()).
 *
 * CLI: Mandant per Umgebungsvariable OPSPILOT_TENANT=<slug> wählen.
 */

function opsMode(): string
{
    return getenv('OPSPILOT_MODE') === 'saas' ? 'saas' : 'selfhosted';
}

function opsEnv(string $name, string $default = ''): string
{
    $v = getenv($name);
    if ($v !== false && $v !== '') return $v;
    /* Docker-Secrets: OPSPILOT_DB_PASS_FILE=/run/secrets/db_pass */
    $f = getenv($name . '_FILE');
    if ($f !== false && $f !== '' && is_readable($f)) return trim((string)file_get_contents($f));
    return $default;
}

/** Verbindung zur Plattform-Datenbank (nur SaaS bzw. Plattform-CLI). */
function opsPlatformDB(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = opsEnv('OPSPILOT_PLATFORM_DB_HOST', DB_HOST);
    $port = opsEnv('OPSPILOT_PLATFORM_DB_PORT', DB_PORT);
    $name = opsEnv('OPSPILOT_PLATFORM_DB_NAME', 'opspilot_platform');
    $user = opsEnv('OPSPILOT_PLATFORM_DB_USER', DB_USER);
    $pass = opsEnv('OPSPILOT_PLATFORM_DB_PASS', DB_PASS);
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int)$port, $name), $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function opsPlatformDbName(): string { return opsEnv('OPSPILOT_PLATFORM_DB_NAME', 'opspilot_platform'); }

/** Gültiger Mandanten-Slug: a-z, 0-9, Bindestrich, 2–40 Zeichen */
function opsValidSlug(string $s): bool { return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $s) && strlen($s) >= 2; }

/** Datenbankname eines Mandanten (Konvention, kann in tenants.db_name abweichen) */
function opsTenantDbNameFor(string $slug): string
{
    return opsEnv('OPSPILOT_TENANT_DB_PREFIX', 'opspilot_t_') . str_replace('-', '_', $slug);
}

/**
 * Aktueller Mandant als Array: id, slug, name, db_name, status.
 * Selfhosted: fester Mandant "default" auf OPSPILOT_DB_NAME.
 * Wirft keine Ausnahme; nicht auflösbare Anfragen werden mit 404 beendet.
 */
function opsCurrentTenant(): array
{
    static $t = null;
    if ($t !== null) return $t;
    if (opsMode() === 'selfhosted') {
        return $t = ['id' => 1, 'slug' => 'default', 'name' => '', 'db_name' => DB_NAME, 'status' => 'active'];
    }
    $slug = null;
    if (PHP_SAPI === 'cli') {
        $slug = getenv('OPSPILOT_TENANT') ?: null;
        if (!$slug) { fwrite(STDERR, "OPSPILOT_TENANT=<slug> fehlt (SaaS-Modus)\n"); exit(2); }
    }
    try {
        $db = opsPlatformDB();
        if ($slug === null) {
            $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
            $s = $db->prepare('SELECT t.* FROM tenant_domains d JOIN tenants t ON t.id = d.tenant_id WHERE d.host = ?');
            $s->execute([$host]);
            $row = $s->fetch();
            /* Zentraler API-Host: Mandant per Header (nur /api/v1, nie für Cookie-Sessions) */
            $platHost = strtolower(opsEnv('OPSPILOT_PLATFORM_HOST'));
            $reqPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if (!$row && $platHost !== '' && $host === $platHost && strncmp($reqPath, '/api/v1/', 8) === 0) {
                $slug = strtolower(trim((string)($_SERVER['HTTP_X_OPSPILOT_TENANT'] ?? '')));
            }
        }
        if ($slug !== null) {
            $s = $db->prepare('SELECT * FROM tenants WHERE slug = ?');
            $s->execute([$slug]);
            $row = $s->fetch();
        }
    } catch (PDOException $e) {
        error_log('OpsPilot platform DB: ' . $e->getMessage());
        opsTenantAbort(503, 'Dienst vorübergehend nicht verfügbar.');
    }
    if (empty($row) || $row['status'] === 'deleted') opsTenantAbort(404, 'Unbekannter Mandant.');
    if ($row['status'] === 'suspended') opsTenantAbort(503, 'Dieser Zugang ist derzeit gesperrt. Bitte wenden Sie sich an Ihren Anbieter.');
    $row['id'] = (int)$row['id'];
    return $t = $row;
}

function opsTenantAbort(int $code, string $msg): void
{
    http_response_code($code);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg . "\n"); exit(1); }
    $isApi = strncmp((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/api/', 5) === 0;
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => $code === 404 ? 'unknown_tenant' : 'tenant_unavailable', 'message' => $msg]]);
    } else {
        echo '<!DOCTYPE html><html lang="de"><meta charset="utf-8"><title>OpsPilot</title><body style="font-family:sans-serif;padding:3rem;text-align:center"><h2>'
           . htmlspecialchars($msg) . '</h2></body></html>';
    }
    exit;
}

/** Datenbank des aktuellen Mandanten (für getDB()). */
function opsTenantDbName(): string { return opsCurrentTenant()['db_name']; }

/** Session an Mandanten binden: eine Session eines anderen Mandanten wird verworfen. */
function opsBindSessionToTenant(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $slug = opsCurrentTenant()['slug'];
    if (isset($_SESSION['tenant']) && $_SESSION['tenant'] !== $slug) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['tenant'] = $slug;
}

/**
 * Legt einen Mandanten an: Datenbank, Plattform-Eintrag, Domain.
 * Das Schema wird anschließend per bin/migrate.php eingespielt.
 * @return array Mandant
 */
function opsCreateTenant(string $slug, string $name, ?string $domain = null): array
{
    if (!opsValidSlug($slug)) throw new InvalidArgumentException('Ungültiger Slug (a-z, 0-9, -; 2–40 Zeichen).');
    $db = opsPlatformDB();
    $dbName = opsTenantDbNameFor($slug);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) throw new InvalidArgumentException('Ungültiger Datenbankname.');
    $s = $db->prepare('SELECT COUNT(*) FROM tenants WHERE slug = ? OR db_name = ?');
    $s->execute([$slug, $dbName]);
    if ((int)$s->fetchColumn() > 0) throw new RuntimeException('Mandant existiert bereits.');
    $db->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->prepare("INSERT INTO tenants(slug, name, db_name, status) VALUES(?,?,?, 'active')")->execute([$slug, $name, $dbName]);
    $id = (int)$db->lastInsertId();
    if ($domain) $db->prepare('INSERT INTO tenant_domains(host, tenant_id) VALUES(?,?)')->execute([strtolower($domain), $id]);
    return ['id' => $id, 'slug' => $slug, 'name' => $name, 'db_name' => $dbName, 'status' => 'active'];
}
