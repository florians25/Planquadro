<?php
/**
 * Router für den eingebauten PHP-Webserver (Entwicklung und Tests).
 * Bildet die Rewrite-Regeln aus public/.htaccess nach:
 *   /                → index.php
 *   /logout, /install → logout.php, install.php
 *   /api/name        → api/name.php
 *   /name            → pages/name.php
 *   statische Dateien werden direkt ausgeliefert
 *   config/, includes/, backups/ und sensible Dateitypen → 403
 *
 * Aufruf: php -S 127.0.0.1:8080 -t public tests/router.php
 */
$docRoot = realpath(__DIR__ . '/../public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

if (preg_match('#^/(config|includes|backups)(/|$)#', $path)
    || preg_match('/\.(sql|md|log|env|bak|old|ini|conf|epbak)$/i', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

$target = null;
if ($path === '/' || $path === '/index.php') {
    $target = '/index.php';
} elseif (preg_match('#^/(logout|install|oidc)$#', $path, $m)) {
    $target = '/' . $m[1] . '.php';
} elseif (preg_match('#^/api/([a-z0-9_]+)$#', $path, $m) && is_file($docRoot . '/api/' . $m[1] . '.php')) {
    $target = '/api/' . $m[1] . '.php';
} elseif (preg_match('#^/([a-z_]+)$#', $path, $m) && is_file($docRoot . '/pages/' . $m[1] . '.php')) {
    $target = '/pages/' . $m[1] . '.php';
} elseif (is_file($docRoot . $path)) {
    if (substr($path, -4) !== '.php') return false; // statische Datei
    $target = $path;
}

if ($target === null) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

$file = $docRoot . $target;
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['PHP_SELF'] = $target;
chdir(dirname($file));
require $file;
return true;
