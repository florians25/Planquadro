<?php
/**
 * Planquadro – Demodaten für eine leere Installation
 * --------------------------------------------------------------------------
 *   php bin/demo.php            legt eine fiktive Firma mit Team, Kunden,
 *                               Einsatzbereichen, Plan, Abwesenheiten und
 *                               Anträgen an (aktueller und nächster Monat)
 *
 * Nur für Test-, Vorführ- und Schulungsinstanzen. Läuft ausschließlich auf
 * einer frisch installierten Datenbank (höchstens ein Benutzer, keine
 * Einsätze) – eine bestehende Installation wird nie verändert.
 * Alle Namen und Firmen sind frei erfunden.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/config/database.php';

$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$users = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$asg = (int)$db->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
if ($users > 1 || $asg > 0) {
    fwrite(STDERR, "Abbruch: Die Datenbank enthält bereits Daten. Demodaten nur auf einer frischen Installation.\n");
    exit(2);
}
mt_srand(20261029);   /* reproduzierbar */

$db->beginTransaction();
$set = $db->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
$set->execute(['company_name', 'Muster Service GmbH']);
$set->execute(['approval_system_enabled', '1']);

/* --- Kunden, Standorte, Einsatzbereiche --- */
$customers = [
    ['Stadtwerke Nordhafen', 'K-1001', 'Hafenstraße 12, 20457 Nordhafen', 'Frau Lindqvist'],
    ['Klinikum am Park', 'K-1002', 'Parkallee 3, 10115 Parkstadt', 'Herr Okafor'],
    ['Logistik Mitte AG', 'K-1003', 'Industrieweg 40, 60311 Mittelfeld', 'Frau Brandt'],
    ['Rathaus Westend', 'K-1004', 'Marktplatz 1, 50667 Westend', 'Herr Yilmaz'],
];
$cIns = $db->prepare('INSERT INTO customers(name,customer_no,address,contact_name,phone,email,active) VALUES(?,?,?,?,?,?,1)');
$lIns = $db->prepare('INSERT INTO locations(name,customer_id,address,active) VALUES(?,?,?,1)');
$cust = [];
foreach ($customers as $i => [$n, $no, $addr, $ct]) {
    $cIns->execute([$n, $no, $addr, $ct, '+49 30 5550' . (100 + $i), 'service@' . strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $n)[0])) . '.example']);
    $cust[] = (int)$db->lastInsertId();
    $lIns->execute([$n . ' – Hauptstandort', end($cust), $addr]);
}
$areas = [   /* Name, Kürzel, Farbe, Schrift, Typ, Kunde */
    ['Stadtwerke Nordhafen – Netzleitstelle', 'SWN', '#2557d6', '#ffffff', 'kunde', 0],
    ['Klinikum am Park – Medizintechnik', 'KAP', '#0f766e', '#ffffff', 'kunde', 1],
    ['Logistik Mitte – Lagertechnik', 'LMA', '#7c3aed', '#ffffff', 'kunde', 2],
    ['Rathaus Westend – IT-Arbeitsplätze', 'RWE', '#ea580c', '#ffffff', 'kunde', 3],
    ['Büro / Disposition', 'BÜ', '#e2e8f0', '#14213d', 'allgemein', null],
    ['Schulung', 'SCH', '#fde68a', '#14213d', 'allgemein', null],
    ['Rufbereitschaft', 'RB', '#14213d', '#ffffff', 'allgemein', null],
];
$aIns = $db->prepare('INSERT INTO plan_areas(name,short_code,color,fg_color,area_type,sort_order,customer_id) VALUES(?,?,?,?,?,?,?)');
$areaIds = [];
foreach ($areas as $i => [$n, $code, $bg, $fg, $type, $ci]) {
    $aIns->execute([$n, $code, $bg, $fg, $type, $i + 1, $ci === null ? null : $cust[$ci]]);
    $areaIds[$code] = (int)$db->lastInsertId();
}

/* --- Team --- */
$pw = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
$hash = password_hash($pw, PASSWORD_DEFAULT);
$people = [  /* Benutzer, Vorname, Nachname, Rolle, Vorgesetzter, Stamm-Bereich */
    ['s.hoffmann', 'Sabine', 'Hoffmann', 'manager', null, 'BÜ'],
    ['m.keller', 'Markus', 'Keller', 'teamleiter', 's.hoffmann', 'SWN'],
    ['a.demir', 'Aylin', 'Demir', 'teamleiter', 's.hoffmann', 'KAP'],
    ['j.berger', 'Jonas', 'Berger', 'mitarbeiter', 'm.keller', 'SWN'],
    ['l.novak', 'Lena', 'Novak', 'mitarbeiter', 'm.keller', 'SWN'],
    ['t.schulz', 'Tobias', 'Schulz', 'mitarbeiter', 'm.keller', 'LMA'],
    ['n.fischer', 'Nina', 'Fischer', 'mitarbeiter', 'm.keller', 'LMA'],
    ['p.wagner', 'Paul', 'Wagner', 'mitarbeiter', 'm.keller', 'RWE'],
    ['e.kowalski', 'Emilia', 'Kowalski', 'mitarbeiter', 'm.keller', 'RWE'],
    ['d.nguyen', 'David', 'Nguyen', 'mitarbeiter', 'a.demir', 'KAP'],
    ['s.weber', 'Sophie', 'Weber', 'mitarbeiter', 'a.demir', 'KAP'],
    ['f.richter', 'Felix', 'Richter', 'mitarbeiter', 'a.demir', 'KAP'],
    ['h.meyer', 'Hannah', 'Meyer', 'mitarbeiter', 'a.demir', 'SWN'],
    ['k.ahmadi', 'Karim', 'Ahmadi', 'mitarbeiter', 'a.demir', 'LMA'],
    ['m.lange', 'Mia', 'Lange', 'mitarbeiter', 'a.demir', 'RWE'],
    ['b.koch', 'Ben', 'Koch', 'azubi_1', 'm.keller', 'SWN'],
    ['c.vogel', 'Clara', 'Vogel', 'verwaltung', null, 'BÜ'],
];
$uIns = $db->prepare('INSERT INTO users(username,password_hash,first_name,last_name,role,vacation_days,primary_area_id,password_changed_at,active,entry_date) VALUES(?,?,?,?,?,30,?,NOW(),1,?)');
$uid = [];
foreach ($people as [$u, $fn, $ln, $role, , $area]) {
    $uIns->execute([$u, $hash, $fn, $ln, $role, $areaIds[$area], date('Y-01-01', strtotime('-2 years'))]);
    $uid[$u] = (int)$db->lastInsertId();
}
$sIns = $db->prepare('INSERT INTO user_supervisors(user_id,supervisor_id) VALUES(?,?)');
foreach ($people as [$u, , , , $boss]) if ($boss) $sIns->execute([$uid[$u], $uid[$boss]]);
$admin = (int)$db->query("SELECT id FROM users WHERE username <> '' ORDER BY id LIMIT 1")->fetchColumn();

/* --- Feiertage (bundesweit) für dieses und nächstes Jahr --- */
$hIns = $db->prepare("INSERT IGNORE INTO holidays(name,holiday_date,holiday_type,source) VALUES(?,?,'feiertag','demo')");
foreach ([(int)date('Y'), (int)date('Y') + 1] as $y) {
    $e = (new DateTimeImmutable("$y-03-21"))->modify('+' . easter_days($y) . ' days');
    $fixed = ["$y-01-01" => 'Neujahr', "$y-05-01" => 'Tag der Arbeit', "$y-10-03" => 'Tag der Deutschen Einheit',
              "$y-12-25" => '1. Weihnachtstag', "$y-12-26" => '2. Weihnachtstag'];
    foreach (['-2 days' => 'Karfreitag', '+1 day' => 'Ostermontag', '+39 days' => 'Christi Himmelfahrt', '+50 days' => 'Pfingstmontag'] as $off => $n) {
        $fixed[$e->modify($off)->format('Y-m-d')] = $n;
    }
    foreach ($fixed as $d => $n) $hIns->execute([$n, $d]);
}
$hol = array_flip($db->query("SELECT holiday_date FROM holidays WHERE holiday_type='feiertag'")->fetchAll(PDO::FETCH_COLUMN));

/* --- Plan: aktueller Monat veröffentlicht, nächster Monat teilweise im Entwurf --- */
$months = [new DateTimeImmutable('first day of this month'), new DateTimeImmutable('first day of next month')];
$asgIns = $db->prepare('INSERT INTO assignments(user_id,plan_date,plan_area_id,year,month,visible_to_user,is_published,created_by) VALUES(?,?,?,?,?,1,1,?)');
$absIns = $db->prepare('INSERT INTO absences(user_id,absence_date,absence_type,is_published,created_by) VALUES(?,?,?,1,?)');
$drIns = $db->prepare("INSERT INTO plan_drafts(user_id,plan_date,year,month,action,change_type,change_value,created_by) VALUES(?,?,?,?,'set',?,?,?)");
$codes = ['SWN', 'KAP', 'LMA', 'RWE'];
/* Urlaubsblöcke und einzelne Abwesenheiten */
$vac = ['l.novak' => [8, 12], 'f.richter' => [15, 19], 'p.wagner' => [22, 26], 'h.meyer' => [3, 4]];
$sick = ['t.schulz' => [10, 11], 'k.ahmadi' => [17, 17]];
foreach ($months as $mi => $m) {
    $y = (int)$m->format('Y'); $mo = (int)$m->format('n'); $days = (int)$m->format('t');
    foreach ($people as $pi => [$u, , , $role, , $home]) {
        for ($d = 1; $d <= $days; $d++) {
            $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            $wd = (int)date('N', strtotime($date));
            if ($wd > 5 || isset($hol[$date])) continue;
            $absType = null;
            if ($mi === 0 && isset($vac[$u]) && $d >= $vac[$u][0] && $d <= $vac[$u][1]) $absType = 'urlaub_genehmigt';
            if ($mi === 0 && isset($sick[$u]) && $d >= $sick[$u][0] && $d <= $sick[$u][1]) $absType = 'krank_attest';
            if ($mi === 0 && $u === 'n.fischer' && $wd === 5 && $d % 2) $absType = 'gleitzeit';
            if ($absType) { if ($mi === 0) $absIns->execute([$uid[$u], $date, $absType, $admin]); continue; }
            /* Einsatz: meist Stammbereich, gelegentlich Wechsel, Schulung, Rufbereitschaft */
            $r = mt_rand(1, 100);
            $code = $home;
            if (in_array($role, ['manager', 'verwaltung'], true)) $code = 'BÜ';
            elseif ($r > 92) $code = 'SCH';
            elseif ($r > 85) $code = 'RB';
            elseif ($r > 70) $code = $codes[($pi + $d) % 4];
            if ($mi === 0) {
                $asgIns->execute([$uid[$u], $date, $areaIds[$code], $y, $mo, $admin]);
            } elseif ($d <= 14) {
                $drIns->execute([$uid[$u], $date, $y, $mo, 'assignment', (string)$areaIds[$code], $uid['m.keller']]);
            }
        }
    }
}
/* Offene Anträge und eine Mitteilung */
$next = $months[1];
$crIns = $db->prepare("INSERT INTO change_requests(user_id,plan_date,request_type,absence_type,remarks,status,requested_by) VALUES(?,?,'absence','urlaub_ganz',?,'pending',?)");
foreach (['j.berger' => [20, 'Familienfeier'], 'd.nguyen' => [23, 'Brückentag'], 's.weber' => [27, '']] as $u => [$d, $txt]) {
    $crIns->execute([$uid[$u], $next->format('Y-m-') . sprintf('%02d', min($d, (int)$next->format('t'))), $txt, $uid[$u]]);
}
$db->prepare("INSERT INTO announcements(title,content,priority,visible_from,visible_until,created_by,active) VALUES(?,?,'info',CURDATE(),DATE_ADD(CURDATE(), INTERVAL 30 DAY),?,1)")
   ->execute(['Neue Planungsrunde', 'Der Plan für den nächsten Monat ist im Entwurf. Wünsche bitte bis zum 20. als Antrag einreichen.', $admin]);
$db->commit();

fwrite(STDOUT, "Demodaten angelegt: " . count($people) . " Personen, " . count($customers) . " Kunden, " . count($areas) . " Einsatzbereiche.\n");
fwrite(STDOUT, "Anmeldung für alle Demo-Konten (z. B. s.hoffmann = Manager, m.keller = Teamleiter, j.berger = Mitarbeiter): Passwort $pw\n");
