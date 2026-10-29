<?php
require_once __DIR__ . '/../app/lib/Security.php';
opsStartSession();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
}
session_destroy();
header('Location: ./?logged_out=1');
exit;
