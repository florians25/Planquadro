<?php
require_once __DIR__ . '/../app/config/database.php';
$db = getDB(); $msgs = [];
try {
    $c = $db->query("SELECT COUNT(*) FROM users WHERE username='admin'")->fetchColumn();
    if ($c == 0) {
        $h = password_hash('Admin2026!', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users(username,password_hash,first_name,last_name,role,vacation_days) VALUES(?,?,?,?,?,?)")
           ->execute(['admin',$h,'System','Administrator','manager',30]);
        $msgs[] = 'Admin angelegt: admin / Admin2026!';
    } else { $msgs[] = 'Admin existiert bereits.'; }
} catch(Exception $e) { $msgs[] = 'Fehler: '.$e->getMessage(); }
?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Install</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="login-bg"><div class="login-wrap"><div class="login-box">
<h1>Installation</h1>
<?php foreach($msgs as $m): ?><div class="alert alert-ok"><?php echo htmlspecialchars($m); ?></div><?php endforeach; ?>
<a href="index.php" class="btn btn-primary btn-full" style="margin-top:1rem">Zum Login</a>
</div></div></body></html>
