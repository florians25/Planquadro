<?php
/**
 * iCal Subscription Feed
 * URL: /api/ical?token=<user_cal_token>
 * No session required - authenticated via unique token per user
 */
require_once __DIR__.'/../../app/config/database.php';

/* Ensure cal_token column exists */
try {
    $db = getDB();
    $cols = $db->query("SHOW COLUMNS FROM users LIKE 'cal_token'")->fetchAll();
    if (count($cols) === 0) {
        $db->exec("ALTER TABLE users ADD COLUMN `cal_token` VARCHAR(64) DEFAULT NULL UNIQUE");
    }
} catch (Exception $e) { http_response_code(500); exit('Server error'); }

$token = trim($_GET['token'] ?? '');
if (!$token || strlen($token) < 32) {
    http_response_code(403);
    exit('Forbidden');
}

/* Rate limit by IP */
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$cacheKey = 'ical_' . md5($ip);
if (function_exists('apcu_fetch')) {
    $hits = apcu_fetch($cacheKey) ?: 0;
    if ($hits > 60) { http_response_code(429); exit('Too many requests'); }
    apcu_store($cacheKey, $hits + 1, 60);
}

/* Lookup user by token */
try {
    $s = $db->prepare('SELECT id, first_name, last_name, username FROM users WHERE cal_token = ? AND active = 1');
    $s->execute([$token]);
    $user = $s->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { http_response_code(500); exit('Server error'); }

if (!$user) {
    http_response_code(403);
    exit('Invalid token');
}

$uid = (int)$user['id'];
$userName = $user['first_name'] . ' ' . $user['last_name'];

/* Determine date range: current month -1 to +3 */
$startDate = date('Y-m-01', strtotime('-1 month'));
$endDate = date('Y-m-t', strtotime('+3 months'));

/* Fetch published assignments */
try {
    $s = $db->prepare('SELECT a.plan_date, a.plan_area_id, a.remarks, p.name AS area_name, p.short_code
        FROM assignments a
        JOIN plan_areas p ON p.id = a.plan_area_id
        WHERE a.user_id = ? AND a.plan_date BETWEEN ? AND ? AND a.is_published = 1
        ORDER BY a.plan_date');
    $s->execute([$uid, $startDate, $endDate]);
    $assignments = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $assignments = []; }

/* Fetch published absences */
try {
    $s = $db->prepare('SELECT absence_date, absence_type, remarks
        FROM absences
        WHERE user_id = ? AND absence_date BETWEEN ? AND ? AND is_published = 1
        ORDER BY absence_date');
    $s->execute([$uid, $startDate, $endDate]);
    $absences = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $absences = []; }

/* Fetch holidays */
try {
    $s = $db->prepare('SELECT holiday_date, name, holiday_type FROM holidays WHERE holiday_date BETWEEN ? AND ?');
    $s->execute([$startDate, $endDate]);
    $holidays = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $holidays = []; }

/* Helper: absence type to readable name */
function icalAbsName($t) {
    return ['krank_halb'=>'Krank (halber Tag)','krank_ganz'=>'Krank','krank_attest'=>'Krank (Attest)','urlaub_halb'=>'Urlaub (halber Tag)','urlaub_ganz'=>'Urlaub (beantragt)','urlaub_genehmigt'=>'Urlaub','gleitzeit'=>'Gleitzeit','gesperrt'=>'Gesperrt'][$t] ?? $t;
}

/* Build iCal */
$serverName = $_SERVER['SERVER_NAME'] ?? 'einsatzplanung.local';
$cal = "BEGIN:VCALENDAR\r\n";
$cal .= "VERSION:2.0\r\n";
$cal .= "PRODID:-//Einsatzplanung//NONSGML v6.0//DE\r\n";
$cal .= "CALSCALE:GREGORIAN\r\n";
$cal .= "METHOD:PUBLISH\r\n";
$cal .= "X-WR-CALNAME:Einsatzplan " . icalEscape($userName) . "\r\n";
$cal .= "X-WR-TIMEZONE:Europe/Berlin\r\n";
$cal .= "REFRESH-INTERVAL;VALUE=DURATION:PT15M\r\n";
$cal .= "X-PUBLISHED-TTL:PT15M\r\n";

/* Assignments */
foreach ($assignments as $a) {
    $d = str_replace('-', '', $a['plan_date']);
    $uid_hash = substr(md5($uid . '_asgn_' . $a['plan_date']), 0, 16);
    $cal .= "BEGIN:VEVENT\r\n";
    $cal .= "UID:" . $uid_hash . "@" . $serverName . "\r\n";
    $cal .= "DTSTART;VALUE=DATE:" . $d . "\r\n";
    $cal .= "DTEND;VALUE=DATE:" . date('Ymd', strtotime($a['plan_date'] . ' +1 day')) . "\r\n";
    $cal .= "SUMMARY:" . icalEscape($a['short_code'] . ' - ' . $a['area_name']) . "\r\n";
    $cal .= "DESCRIPTION:" . icalEscape('Einsatz: ' . $a['area_name'] . ($a['remarks'] ? '\n' . $a['remarks'] : '')) . "\r\n";
    $cal .= "CATEGORIES:Einsatz\r\n";
    $cal .= "TRANSP:OPAQUE\r\n";
    $cal .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    $cal .= "END:VEVENT\r\n";
}

/* Absences */
foreach ($absences as $a) {
    $d = str_replace('-', '', $a['absence_date']);
    $uid_hash = substr(md5($uid . '_abs_' . $a['absence_date']), 0, 16);
    $cal .= "BEGIN:VEVENT\r\n";
    $cal .= "UID:" . $uid_hash . "@" . $serverName . "\r\n";
    $cal .= "DTSTART;VALUE=DATE:" . $d . "\r\n";
    $cal .= "DTEND;VALUE=DATE:" . date('Ymd', strtotime($a['absence_date'] . ' +1 day')) . "\r\n";
    $cal .= "SUMMARY:" . icalEscape(icalAbsName($a['absence_type'])) . "\r\n";
    if ($a['remarks']) $cal .= "DESCRIPTION:" . icalEscape($a['remarks']) . "\r\n";
    $cal .= "CATEGORIES:Abwesenheit\r\n";
    $cal .= "TRANSP:TRANSPARENT\r\n";
    $cal .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    $cal .= "END:VEVENT\r\n";
}

/* Holidays */
foreach ($holidays as $h) {
    $d = str_replace('-', '', $h['holiday_date']);
    $uid_hash = substr(md5('holiday_' . $h['holiday_date']), 0, 16);
    $cal .= "BEGIN:VEVENT\r\n";
    $cal .= "UID:" . $uid_hash . "@" . $serverName . "\r\n";
    $cal .= "DTSTART;VALUE=DATE:" . $d . "\r\n";
    $cal .= "DTEND;VALUE=DATE:" . date('Ymd', strtotime($h['holiday_date'] . ' +1 day')) . "\r\n";
    $cal .= "SUMMARY:" . icalEscape(($h['holiday_type'] === 'feiertag' ? '[Feiertag] ' : '[Ferien] ') . $h['name']) . "\r\n";
    $cal .= "CATEGORIES:" . ($h['holiday_type'] === 'feiertag' ? 'Feiertag' : 'Ferien') . "\r\n";
    $cal .= "TRANSP:TRANSPARENT\r\n";
    $cal .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    $cal .= "END:VEVENT\r\n";
}

$cal .= "END:VCALENDAR\r\n";

function icalEscape($s) {
    $s = str_replace(['\\', "\n", "\r", ';', ','], ['\\\\', '\\n', '', '\\;', '\\,'], $s);
    return $s;
}

/* Output */
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="einsatzplan.ics"');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $cal;
