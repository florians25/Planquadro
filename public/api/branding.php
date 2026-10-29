<?php
/**
 * Mandanten-Logo ausliefern (öffentlich – wird auch auf der Login-Seite und
 * in den Apps angezeigt). Nur zuvor geprüfte Rastergrafiken.
 */
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/lib/Security.php';
require_once __DIR__ . '/../../app/lib/TenantConfig.php';

$file = (string)tcfg('branding.logo', '');
if (!preg_match('/^logo\.(png|jpg|webp)$/', $file, $m) || !is_file($path = opsStorageDir('branding') . '/' . $file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$m[1]]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
