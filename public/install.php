<?php
/**
 * Ersteinrichtung: legt den ersten Administrator an.
 * --------------------------------------------------------------------------
 * Gesperrt, solange nicht ausdrücklich freigegeben:
 *   - per CLI:  php public/install.php
 *   - per Web:  nur mit Umgebungsvariable PQ_ALLOW_INSTALL=1
 * Läuft nur, wenn noch KEIN Benutzer existiert. Das Passwort wird zufällig
 * erzeugt, einmal angezeigt und muss beim ersten Login geändert werden.
 */
$cli = PHP_SAPI === 'cli';
if (!$cli && getenv('PQ_ALLOW_INSTALL') !== '1') {
    http_response_code(404);
    exit('Not Found');
}
require_once __DIR__ . '/../app/config/database.php';
$db = getDB(); $msgs = []; $ok = false;
try {
    $c = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($c === 0) {
        $pw = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        $db->prepare("INSERT INTO users(username,password_hash,first_name,last_name,role,vacation_days,must_change_password) VALUES(?,?,?,?,?,?,1)")
           ->execute(['admin', password_hash($pw, PASSWORD_DEFAULT), 'System', 'Administrator', 'admin', 30]);
        $msgs[] = "Administrator angelegt. Benutzer: admin · Einmalpasswort: $pw";
        $msgs[] = 'Das Passwort wird nur jetzt angezeigt und muss beim ersten Login geändert werden.';
        $ok = true;
    } else {
        $msgs[] = 'Es existieren bereits Benutzer – keine Änderung vorgenommen.';
    }
} catch (Exception $e) { $msgs[] = 'Fehler bei der Einrichtung. Details im Server-Log.'; error_log('Planquadro install: ' . $e->getMessage()); }
if ($cli) { echo implode(PHP_EOL, $msgs), PHP_EOL; exit($ok ? 0 : 1); }
header('Cache-Control: no-store');
?><!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Installation</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="login-bg"><div class="login-wrap"><div class="login-box">
<h1>Installation</h1>
<?php foreach($msgs as $m): ?><div class="alert alert-ok"><?php echo htmlspecialchars($m); ?></div><?php endforeach; ?>
<p class="muted">Danach <code>PQ_ALLOW_INSTALL</code> wieder entfernen.</p>
<a href="./" class="btn btn-primary btn-full" style="margin-top:1rem">Zum Login</a>
</div></div></body></html>
