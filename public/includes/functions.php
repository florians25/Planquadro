<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../app/lib/TenantConfig.php';

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function flash($t, $m) { $_SESSION['flash'] = ['type'=>$t,'msg'=>$m]; }
function getFlash() {
    if (isset($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; }
    return null;
}

function monthName($m) {
    return ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'][$m] ?? '';
}

function wdShort($d) { return ['So','Mo','Di','Mi','Do','Fr','Sa'][(int)date('w',strtotime($d))]; }
function isWE($d) { $w=(int)date('w',strtotime($d)); return $w===0||$w===6; }
function kw($d) { return (int)date('W',strtotime($d)); }

function daysInMonth($y, $m) {
    $c = cal_days_in_month(CAL_GREGORIAN, $m, $y);
    $d = [];
    for ($i=1; $i<=$c; $i++) $d[] = sprintf('%04d-%02d-%02d', $y, $m, $i);
    return $d;
}

/* -- Absence mappings -- */
function absenceLabel($type) {
    return ['krank_halb'=>'k','krank_ganz'=>'K','krank_attest'=>'K','urlaub_halb'=>'u','urlaub_ganz'=>'U','urlaub_genehmigt'=>'U','gleitzeit'=>'GL','gesperrt'=>'—','offen'=>'?'][$type] ?? 'A';
}
function absenceColor($type) {
    return ['krank_halb'=>'#ef4444','krank_ganz'=>'#dc2626','krank_attest'=>'#16a34a','urlaub_halb'=>'#fbbf24','urlaub_ganz'=>'#dc2626','urlaub_genehmigt'=>'#16a34a','gleitzeit'=>'#8b5cf6','gesperrt'=>'#94a3b8','offen'=>'#475569'][$type] ?? '#ef4444';
}
function absenceFg($type) { return ($type==='urlaub_halb') ? '#000' : '#fff'; }
function absenceName($type) {
    return ['krank_halb'=>'Krank ½ Tag','krank_ganz'=>'Krank ohne Attest','krank_attest'=>'Krank mit Attest','urlaub_halb'=>'Urlaub ½ Tag','urlaub_ganz'=>'Urlaub (beantragt)','urlaub_genehmigt'=>'Urlaub (genehmigt)','gleitzeit'=>'Gleitzeit','gesperrt'=>'Gesperrt','offen'=>'Offen / noch zu klären'][$type] ?? $type;
}
function absenceDayValue($type) {
    if ($type==='gesperrt' || $type==='offen') return 0;
    return in_array($type, ['krank_halb','urlaub_halb']) ? 0.5 : 1.0;
}

/* -- Training professions -- */
function trainingProfessions() {
    return [''=>'– Kein –','KM'=>'Kaufmann/-frau für IT-System-Management','ITSE'=>'IT-Systemelektroniker/in','FISI'=>'Fachinformatiker/in Systemintegration','DUS-WI'=>'Duales Studium Wirtschaftsinformatik','DUS-KM'=>'Duales Studium IT-System-Management'];
}

/* -- Users with exit_date filter -- */
function allUsers($active=true) {
    $sql = 'SELECT * FROM users';
    if ($active) $sql .= ' WHERE active=1';
    $sql .= " ORDER BY FIELD(role,'admin','manager','teamleiter','verwaltung','mitarbeiter','lager','azubi_1','azubi_2','azubi_3'), last_name, first_name";
    return getDB()->query($sql)->fetchAll();
}

/* === Sichtbarkeits-Scope: Vorgesetzte sehen nur ihre Mitarbeiter === */

/* Darf der aktuelle Benutzer ALLE Mitarbeiter in der Planung sehen?
   Admins immer; sonst über die Berechtigung 'view_all_employees'.
   Diese ist per Standard für alle Rollen gesetzt – AUSSER manager/teamleiter,
   die dadurch nur noch ihr eigenes Team sehen (Datenminimierung). */
function canViewAllEmployees(): bool {
    $u = currentUser();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    /* Expliziter Benutzer-Override hat Vorrang */
    try {
        $s = getDB()->prepare('SELECT granted FROM permission_user_overrides WHERE user_id=? AND permission=?');
        $s->execute([(int)$u['id'], 'view_all_employees']);
        if ($r = $s->fetch()) return (bool)$r['granted'];
    } catch (Exception $e) {}
    /* Explizites Rollenrecht (über Rollen-Seite gesetzt) */
    try {
        $s = getDB()->prepare('SELECT granted FROM role_permissions WHERE role=? AND permission=?');
        $s->execute([$u['role'], 'view_all_employees']);
        if ($r = $s->fetch()) return (bool)$r['granted'];
    } catch (Exception $e) {}
    /* Standard (auch ohne Migration korrekt): alle dürfen alle sehen,
       NUR Manager und Teamleiter werden auf ihr eigenes Team beschränkt. */
    return !in_array($u['role'], ['manager','teamleiter'], true);
}

/* === Vertreterregelung (Deputation) ===
   Ein abwesender Vorgesetzter (Teamleiter/Manager) kann seine Rechte für einen
   bestimmten Zeitraum an eine Vertretung abgeben. Während dieses Zeitraums
   erhält die Vertretung den Verantwortungsbereich (das Team) des Abgebenden
   zusätzlich zum eigenen – sie kann dann für dessen Team eintragen und
   Anträge genehmigen. */
function activeDeputationDelegators(int $deputyId): array {
    static $cache = [];
    if (isset($cache[$deputyId])) return $cache[$deputyId];
    $ids = [];
    try {
        $today = date('Y-m-d');
        $s = getDB()->prepare('SELECT DISTINCT delegator_id FROM deputations WHERE deputy_id=? AND start_date<=? AND end_date>=?');
        $s->execute([$deputyId, $today, $today]);
        foreach ($s->fetchAll() as $r) $ids[] = (int)$r['delegator_id'];
    } catch (Exception $e) { /* Tabelle evtl. noch nicht migriert */ }
    $cache[$deputyId] = $ids;
    return $ids;
}
/* Vertritt der aktuelle Benutzer gerade jemanden? */
function hasActiveDeputation(): bool {
    $u = currentUser();
    if (!$u) return false;
    return count(activeDeputationDelegators((int)$u['id'])) > 0;
}
/* Namen der aktuell vertretenen Personen (für Hinweise in der Oberfläche). */
function activeDeputationNames(): array {
    $u = currentUser();
    if (!$u) return [];
    $delegators = activeDeputationDelegators((int)$u['id']);
    if (!$delegators) return [];
    $names = [];
    try {
        $in = implode(',', array_fill(0, count($delegators), '?'));
        $s = getDB()->prepare("SELECT first_name,last_name FROM users WHERE id IN ($in)");
        $s->execute($delegators);
        foreach ($s->fetchAll() as $r) $names[] = $r['first_name'].' '.$r['last_name'];
    } catch (Exception $e) {}
    return $names;
}

/* Menge der User-IDs, die der aktuelle Benutzer in der Planung sehen darf.
   Rückgabe NULL  = uneingeschränkt (sieht alle).
   Rückgabe Array = nur diese IDs (er selbst + alle direkt und indirekt
   unterstellten Mitarbeiter laut Tabelle user_supervisors). */
function visibleUserIds() {
    static $cached = false; static $cache = null;
    if ($cached) return $cache;
    $cached = true;

    if (canViewAllEmployees()) { $cache = null; return null; }

    $me = currentUser();
    if (!$me) { $cache = []; return []; }
    $meId = (int)$me['id'];
    $ids  = [$meId => true];

    try {
        $rows = getDB()->query('SELECT user_id, supervisor_id FROM user_supervisors')->fetchAll();
        $reportsOf = [];
        foreach ($rows as $r) { $reportsOf[(int)$r['supervisor_id']][] = (int)$r['user_id']; }
        /* transitive Hülle: auch Mitarbeiter unterstellter Teamleiter werden sichtbar */
        $queue = [$meId];
        /* Vertreterregelung: aktuell vertretene Vorgesetzte + deren Teams ergänzen */
        foreach (activeDeputationDelegators($meId) as $delId) {
            if (!isset($ids[$delId])) { $ids[$delId] = true; }
            $queue[] = $delId;
        }
        while ($queue) {
            $cur = array_shift($queue);
            foreach (($reportsOf[$cur] ?? []) as $rep) {
                if (!isset($ids[$rep])) { $ids[$rep] = true; $queue[] = $rep; }
            }
        }
    } catch (Exception $e) {
        /* Tabelle fehlt o.ä. -> nicht aussperren, aber auch nicht alles zeigen:
           der Vorgesetzte sieht zumindest sich selbst. */
        $cache = [$meId];
        return $cache;
    }

    $cache = array_keys($ids);
    return $cache;
}

/* Eine Benutzerliste auf den sichtbaren Scope reduzieren. */
function scopeUsers(array $users): array {
    $set = visibleUserIds();
    if ($set === null) return array_values($users);
    $set = array_flip(array_map('intval', $set));
    return array_values(array_filter($users, function($u) use ($set) {
        return isset($set[(int)$u['id']]);
    }));
}

/* Darf der aktuelle Benutzer den Plan dieses Mitarbeiters bearbeiten/sehen?
   (serverseitige Absicherung – verhindert manipulierte Requests) */
function canEditUser($uid): bool {
    $set = visibleUserIds();
    if ($set === null) return true;
    return in_array((int)$uid, array_map('intval', $set), true);
}

/* === Vertraulichkeit von Krankheit & Urlaub ===
   Krank- und Urlaubsdaten dürfen NUR gesehen werden von:
   - dem Mitarbeiter selbst
   - seinem Teamleiter / dem FOS-Manager (Vorgesetzte:r im eigenen Verantwortungsbereich)
   - ggf. dem Administrator
   Alle anderen Rollen (z.B. Verwaltung, Lager, Kollegen) sehen nur "abwesend",
   nicht aber Art oder Grund der Abwesenheit. */
function canSeeAbsenceDetails($employeeId): bool {
    $u = currentUser();
    if (!$u) return false;
    if ((int)$u['id'] === (int)$employeeId) return true;   // Mitarbeiter selbst
    if ($u['role'] === 'admin') return true;               // Administrator (ggf.)
    /* Ausdrücklich erteiltes Recht (z. B. Personalabteilung) */
    if (hasPerm('view_absence_details_all')) return true;
    /* Vorgesetzte: nur für tatsächlich unterstellte Mitarbeiter (direkt,
       transitiv oder per Vertretung). Die Planungsfreigabe "darf alle
       planen" (view_all_employees) öffnet KEINE Gesundheitsdaten mehr
       (Entscheidung zu Befund S-18). */
    if (in_array($u['role'], ['manager','teamleiter'], true)) {
        return in_array((int)$employeeId, array_map('intval', supervisedIdSet()), true);
    }
    return false;
}

/* Gilt dieser Abwesenheitstyp als vertraulich (Krankheit/Urlaub)? */
function isConfidentialAbsence($type): bool {
    return strpos((string)$type, 'krank_') === 0 || strpos((string)$type, 'urlaub_') === 0;
}

/* Users for planning (excludes admin role, respektiert Vorgesetzten-Scope) */
function planningUsers($active=true, $scoped=true) {
    $u = array_values(array_filter(allUsers($active), function($u) { return $u['role'] !== 'admin'; }));
    return $scoped ? scopeUsers($u) : $u;
}

/* Users visible in a given month (excludes exited + admin) */
function usersForMonth($y, $m, $scoped=true) {
    $firstOfMonth = sprintf('%04d-%02d-01', $y, $m);
    $users = planningUsers(true, $scoped);
    return array_filter($users, function($u) use ($firstOfMonth) {
        if (empty($u['exit_date'] ?? null)) return true;
        $exitFirst = date('Y-m-01', strtotime(($u['exit_date'] ?? '2099-12-31') . ' +1 month'));
        return $firstOfMonth < $exitFirst;
    });
}

/* Menge der dem aktuellen Benutzer unterstellten User-IDs (er selbst + alle
   direkt und transitiv unterstellten Mitarbeiter) – unabhängig von Rechten.
   Wird z.B. für den "Meine Mitarbeiter"-Filter im Gesamtplan verwendet. */
function supervisedIdSet(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $me = currentUser();
    if (!$me) { $cache = []; return []; }
    $meId = (int)$me['id'];
    $ids = [$meId => true];
    try {
        $rows = getDB()->query('SELECT user_id, supervisor_id FROM user_supervisors')->fetchAll();
        $reportsOf = [];
        foreach ($rows as $r) { $reportsOf[(int)$r['supervisor_id']][] = (int)$r['user_id']; }
        $queue = [$meId];
        /* Vertreterregelung: aktuell vertretene Vorgesetzte + deren Teams gehören
           während des Vertretungszeitraums zum "eigenen" Bereich. */
        foreach (activeDeputationDelegators($meId) as $delId) {
            if (!isset($ids[$delId])) { $ids[$delId] = true; }
            $queue[] = $delId;
        }
        while ($queue) {
            $cur = array_shift($queue);
            foreach (($reportsOf[$cur] ?? []) as $rep) {
                if (!isset($ids[$rep])) { $ids[$rep] = true; $queue[] = $rep; }
            }
        }
    } catch (Exception $e) {}
    $cache = array_keys($ids);
    return $cache;
}

function usersByGroup($y=0, $m=0, $scoped=true) {
    $users = ($y && $m) ? usersForMonth($y, $m, $scoped) : planningUsers(true, $scoped);
    $g = ['Techniker'=>[],'Verwaltung'=>[],'Lager'=>[],'Azubi 1. Lehrjahr'=>[],'Azubi 2. Lehrjahr'=>[],'Azubi 3. Lehrjahr'=>[]];
    foreach ($users as $u) {
        if ($u['role']==='azubi_1') $g['Azubi 1. Lehrjahr'][]=$u;
        elseif ($u['role']==='azubi_2') $g['Azubi 2. Lehrjahr'][]=$u;
        elseif ($u['role']==='azubi_3') $g['Azubi 3. Lehrjahr'][]=$u;
        elseif (($u['department']??'') === 'verwaltung' || $u['role']==='verwaltung') $g['Verwaltung'][]=$u;
        elseif (($u['department']??'') === 'lager' || $u['role']==='lager') $g['Lager'][]=$u;
        else $g['Techniker'][]=$u;
    }
    return array_filter($g);
}

function userWots($uid, $y=0, $m=0) {
    if ($y && $m) {
        /* je Monat einmal für alle Mitarbeiter laden (Monatsplanung: eine Abfrage statt einer pro Zeile) */
        static $byMonth = [];
        $k = $y . '-' . $m;
        if (!isset($byMonth[$k])) {
            $byMonth[$k] = [];
            $s = getDB()->prepare('SELECT * FROM user_wots WHERE year=? AND month=? ORDER BY id');
            $s->execute([$y,$m]);
            foreach ($s->fetchAll() as $r) $byMonth[$k][(int)$r['user_id']][] = $r;
        }
        return $byMonth[$k][(int)$uid] ?? [];
    }
    $s = getDB()->prepare('SELECT * FROM user_wots WHERE user_id=? ORDER BY year DESC,month DESC,id');
    $s->execute([$uid]);
    return $s->fetchAll();
}

function wotCount($uid, $y, $m) {
    $s = getDB()->prepare('SELECT COUNT(*) c FROM user_wots WHERE user_id=? AND year=? AND month=?');
    $s->execute([$uid,$y,$m]); return (int)$s->fetch()['c'];
}

function userMonthData($uid, $y, $m) {
    $s = getDB()->prepare('SELECT * FROM user_month_data WHERE user_id=? AND year=? AND month=?');
    $s->execute([$uid,$y,$m]);
    return $s->fetch() ?: ['hours'=>null,'snow_checked'=>0];
}

function allMonthData($y, $m) {
    $s = getDB()->prepare('SELECT * FROM user_month_data WHERE year=? AND month=?');
    $s->execute([$y,$m]); $map = [];
    foreach ($s->fetchAll() as $r) $map[$r['user_id']] = $r;
    return $map;
}

function planAreas($type='') {
    if ($type) { $s = getDB()->prepare('SELECT * FROM plan_areas WHERE area_type=? ORDER BY sort_order,name'); $s->execute([$type]); return $s->fetchAll(); }
    return getDB()->query('SELECT * FROM plan_areas ORDER BY area_type DESC, sort_order, name')->fetchAll();
}
function planAreaMap() { $m=[]; foreach (planAreas() as $a) $m[$a['id']]=$a; return $m; }

function holidays($y, $m=0) {
    if ($m) { $start=sprintf('%04d-%02d-01',$y,$m); $end=date('Y-m-t',strtotime($start));
        $s=getDB()->prepare('SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ?'); $s->execute([$start,$end]);
    } else { $s=getDB()->prepare('SELECT * FROM holidays WHERE YEAR(holiday_date)=?'); $s->execute([$y]); }
    $map=[]; foreach ($s->fetchAll() as $r) $map[$r['holiday_date']]=$r; return $map;
}

function assignments($y, $m, $pub=false) {
    $s=getDB()->prepare('SELECT * FROM assignments WHERE year=? AND month=? AND is_published=1');
    $s->execute([$y,$m]); $map=[];
    foreach($s->fetchAll() as $r) $map[$r['user_id']][$r['plan_date']]=$r;
    if($pub) return $map;
    $drafts = loadDrafts($y, $m);
    foreach($drafts as $d) {
        $uid=$d['user_id']; $date=$d['plan_date'];
        if($d['action']==='clear') {
            unset($map[$uid][$date]);
        } elseif($d['change_type']==='assignment') {
            $map[$uid][$date] = ['user_id'=>$uid,'plan_date'=>$date,'plan_area_id'=>(int)$d['change_value'],'year'=>$d['year'],'month'=>$d['month'],'is_published'=>0,'remarks'=>$d['remarks'],'internal_remark'=>$d['internal_remark']??null,'is_internal'=>$d['is_internal']??0,'remark_by'=>($d['remarks']||($d['internal_remark']??null))?$d['created_by']:null,'remark_at'=>($d['remarks']||($d['internal_remark']??null))?$d['created_at']:null,'is_draft'=>1];
        } elseif($d['change_type']==='absence' || $d['change_type']==='locked') {
            unset($map[$uid][$date]);
        }
    }
    return $map;
}

function absences($y, $m, $pub=false) {
    $start=sprintf('%04d-%02d-01',$y,$m); $end=date('Y-m-t',strtotime($start));
    $s=getDB()->prepare('SELECT * FROM absences WHERE absence_date BETWEEN ? AND ? AND is_published=1');
    $s->execute([$start,$end]); $map=[];
    foreach($s->fetchAll() as $r) $map[$r['user_id']][$r['absence_date']]=$r;
    if($pub) return $map;
    $drafts = loadDrafts($y, $m);
    foreach($drafts as $d) {
        $uid=$d['user_id']; $date=$d['plan_date'];
        if($d['action']==='clear') {
            unset($map[$uid][$date]);
        } elseif($d['change_type']==='absence' || $d['change_type']==='locked') {
            $map[$uid][$date] = ['user_id'=>$uid,'absence_date'=>$date,'absence_type'=>$d['change_value'],'is_published'=>0,'remarks'=>$d['remarks'],'internal_remark'=>$d['internal_remark']??null,'is_internal'=>$d['is_internal']??0,'remark_by'=>($d['remarks']||($d['internal_remark']??null))?$d['created_by']:null,'remark_at'=>($d['remarks']||($d['internal_remark']??null))?$d['created_at']:null,'is_draft'=>1];
        } elseif($d['change_type']==='assignment') {
            unset($map[$uid][$date]);
        }
    }
    return $map;
}

function loadDrafts($y, $m) {
    try {
        $s=getDB()->prepare('SELECT * FROM plan_drafts WHERE year=? AND month=?');
        $s->execute([$y,$m]); return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) { return []; }
}

function hasDrafts($y, $m) {
    try {
        $s=getDB()->prepare('SELECT COUNT(*) c FROM plan_drafts WHERE year=? AND month=?');
        $s->execute([$y,$m]); return (int)$s->fetch()['c'] > 0;
    } catch(Exception $e) { return false; }
}

function todayAbsent() {
    $set = visibleUserIds();
    $sql = 'SELECT a.user_id,u.first_name,u.last_name,a.absence_type FROM absences a JOIN users u ON u.id=a.user_id WHERE a.absence_date=? AND a.is_published=1';
    if ($set !== null) {
        if (empty($set)) return [];
        $sql .= ' AND a.user_id IN ('.implode(',', array_map('intval', $set)).')';
    }
    $sql .= ' ORDER BY u.last_name';
    $s=getDB()->prepare($sql);
    $s->execute([date('Y-m-d')]);
    /* Vertraulichkeit: nur Abwesenheiten zeigen, die der Betrachter sehen darf */
    return array_values(array_filter($s->fetchAll(), function($r){
        return canSeeAbsenceDetails($r['user_id']);
    }));
}

function lastPublished($y,$m) {
    $s=getDB()->prepare('SELECT published_at FROM publish_log WHERE year=? AND month=? ORDER BY published_at DESC LIMIT 1');
    $s->execute([$y,$m]); $r=$s->fetch(); return $r?$r['published_at']:null;
}

function lastChange($y,$m) {
    try {
        $s=getDB()->prepare('SELECT MAX(created_at) v FROM plan_drafts WHERE year=? AND month=?');
        $s->execute([$y,$m]); $d=$s->fetch()['v'];
        return $d ?: null;
    } catch(Exception $e) { return null; }
}

function vacUsed($uid,$y) {
    $s=getDB()->prepare("SELECT absence_type,COUNT(*) c FROM absences WHERE user_id=? AND YEAR(absence_date)=? AND absence_type IN('urlaub_halb','urlaub_ganz','urlaub_genehmigt') AND is_published=1 GROUP BY absence_type");
    $s->execute([$uid,$y]); $t=0.0;
    foreach($s->fetchAll() as $r){if($r['absence_type']==='urlaub_halb')$t+=(int)$r['c']*0.5;else $t+=(int)$r['c'];}return $t;
}
function vacLeft($uid,$y){$s=getDB()->prepare('SELECT vacation_days FROM users WHERE id=?');$s->execute([$uid]);$u=$s->fetch();return($u?(float)$u['vacation_days']:0)-vacUsed($uid,$y);}
/* Urlaubskonto: Werte werden je Stichtag EINMAL für alle Mitarbeiter geladen
   (vorher eine Abfrage je Zeile und Aufruf – bei 60 Mitarbeitern ~240 Abfragen
   pro Monatsplanung). Ergebnis und Semantik sind unverändert. */
function _vacUsedMap($y, $date, $inclusive) {
    static $cache = [];
    $key = $y . '|' . $date . '|' . ($inclusive ? 1 : 0);
    if (!isset($cache[$key])) {
        $op = $inclusive ? '<=' : '<';
        $s = getDB()->prepare("SELECT user_id, absence_type, COUNT(*) c FROM absences WHERE YEAR(absence_date)=? AND absence_date $op ? AND absence_type IN('urlaub_halb','urlaub_ganz','urlaub_genehmigt') AND is_published=1 GROUP BY user_id, absence_type");
        $s->execute([$y, $date]);
        $m = [];
        foreach ($s->fetchAll() as $r) $m[(int)$r['user_id']] = ($m[(int)$r['user_id']] ?? 0.0) + ($r['absence_type'] === 'urlaub_halb' ? (int)$r['c'] * 0.5 : (int)$r['c']);
        $cache[$key] = $m;
    }
    return $cache[$key];
}
function _vacationDays($uid) {
    static $all = null;
    if ($all === null) { $all = []; foreach (getDB()->query('SELECT id, vacation_days FROM users')->fetchAll() as $r) $all[(int)$r['id']] = (float)$r['vacation_days']; }
    return $all[(int)$uid] ?? 0.0;
}
function vacUsedUntil($uid,$date){return _vacUsedMap((int)date('Y',strtotime($date)),$date,false)[(int)$uid] ?? 0.0;}
function vacUsedThrough($uid,$date){return _vacUsedMap((int)date('Y',strtotime($date)),$date,true)[(int)$uid] ?? 0.0;}
function vacLeftAtStart($uid,$y,$m){$d=sprintf('%04d-%02d-01',$y,$m);return _vacationDays($uid)-vacUsedUntil($uid,$d);}
function vacLeftAtEnd($uid,$y,$m){$d=date('Y-m-t',strtotime(sprintf('%04d-%02d-01',$y,$m)));return _vacationDays($uid)-vacUsedThrough($uid,$d);}
function sickDays($uid,$y){$s=getDB()->prepare("SELECT absence_type,COUNT(*) c FROM absences WHERE user_id=? AND YEAR(absence_date)=? AND absence_type IN('krank_halb','krank_ganz','krank_attest') AND is_published=1 GROUP BY absence_type");$s->execute([$uid,$y]);$t=0.0;foreach($s->fetchAll() as $r){if($r['absence_type']==='krank_halb')$t+=(int)$r['c']*0.5;else $t+=(int)$r['c'];}return $t;}

function teamStats($y,$m) {
    $users=usersForMonth($y,$m);$totalUsers=count($users);$asgn=assignments($y,$m,true);$abs=absences($y,$m,true);$days=daysInMonth($y,$m);$hols=holidays($y,$m);
    $totalWorkdays=0;foreach($days as $d){if(!isWE($d)&&!isset($hols[$d]))$totalWorkdays++;}
    $assignedDays=0;$absentDays=0;$unplannedDays=0;$areaStats=[];$pa=planAreaMap();
    foreach($users as $u){foreach($days as $d){if(isWE($d)||isset($hols[$d]))continue;
        if(isset($abs[$u['id']][$d]))$absentDays++;
        elseif(isset($asgn[$u['id']][$d])){$assignedDays++;$aid=$asgn[$u['id']][$d]['plan_area_id'];if(isset($pa[$aid])){$k=$pa[$aid]['short_code'];if(!isset($areaStats[$k]))$areaStats[$k]=['count'=>0,'color'=>$pa[$aid]['color'],'fg'=>$pa[$aid]['fg_color'],'name'=>$pa[$aid]['name']];$areaStats[$k]['count']++;}}
        else $unplannedDays++;
    }}arsort($areaStats);return compact('totalUsers','totalWorkdays','assignedDays','absentDays','unplannedDays','areaStats');
}
function upcomingHolidays($y,$limit=5){$s=getDB()->prepare('SELECT * FROM holidays WHERE holiday_date>=? AND holiday_type=? ORDER BY holiday_date LIMIT ?');$s->execute([date('Y-m-d'),'feiertag',$limit]);return $s->fetchAll();}
function roleName($r){
    static $map=null;
    if($map===null){
        $map=['admin'=>'Administrator','mitarbeiter'=>'Techniker','teamleiter'=>'Teamleiter','manager'=>'Manager','verwaltung'=>'Verwaltung','lager'=>'Lager','azubi_1'=>'Azubi 1. LJ','azubi_2'=>'Azubi 2. LJ','azubi_3'=>'Azubi 3. LJ'];
        try{$s=getDB()->query('SELECT role_key,role_label FROM custom_roles');foreach($s->fetchAll() as $cr)$map[$cr['role_key']]=$cr['role_label'];}catch(Exception $e){}
    }
    return $map[$r]??$r;
}
function allRoles(){
    $roles=['admin'=>'Administrator','manager'=>'Manager','teamleiter'=>'Teamleiter','verwaltung'=>'Verwaltung','lager'=>'Lager','mitarbeiter'=>'Techniker','azubi_1'=>'Azubi 1. LJ','azubi_2'=>'Azubi 2. LJ','azubi_3'=>'Azubi 3. LJ'];
    try{$s=getDB()->query('SELECT role_key,role_label FROM custom_roles ORDER BY sort_order');foreach($s->fetchAll() as $cr)$roles[$cr['role_key']]=$cr['role_label'];}catch(Exception $e){}
    return $roles;
}
function roleColor($r){
    static $colors=null;
    if($colors===null){
        $colors=['admin'=>'#e2001a','manager'=>'#2563eb','teamleiter'=>'#7c3aed','verwaltung'=>'#0891b2','lager'=>'#d97706','mitarbeiter'=>'#059669','azubi_1'=>'#ec4899','azubi_2'=>'#f472b6','azubi_3'=>'#f9a8d4'];
        try{$s=getDB()->query('SELECT role_key,color FROM custom_roles');foreach($s->fetchAll() as $cr)$colors[$cr['role_key']]=$cr['color'];}catch(Exception $e){}
    }
    return $colors[$r]??'#6b7280';
}

/* -- Planned Maintenance -- */
function upcomingMaintenance($limit=3) {
    try {
        $s=getDB()->prepare('SELECT pm.*, u.first_name, u.last_name FROM planned_maintenance pm LEFT JOIN users u ON u.id=pm.created_by WHERE pm.is_active=1 AND (pm.end_date IS NULL OR pm.end_date>=NOW()) ORDER BY pm.start_date LIMIT ?');
        $s->execute([$limit]); return $s->fetchAll();
    } catch(Exception $e) { return []; }
}

/* -- Live Editing Status -- */
function pingEditing($uid, $page, $y, $m) {
    try {
        getDB()->prepare('INSERT INTO editing_status(user_id,page,year,month) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_ping=NOW()')
               ->execute([$uid,$page,$y,$m]);
    } catch(Exception $e) {}
}
function activeEditors($page, $y, $m, $excludeUid=0) {
    try {
        $s=getDB()->prepare('SELECT es.user_id, u.first_name, u.last_name FROM editing_status es JOIN users u ON u.id=es.user_id WHERE es.page=? AND es.year=? AND es.month=? AND es.last_ping>=DATE_SUB(NOW(),INTERVAL 30 SECOND) AND es.user_id!=?');
        $s->execute([$page,$y,$m,$excludeUid]); return $s->fetchAll();
    } catch(Exception $e) { return []; }
}

/* -- App Settings -- */
function getSetting($key, $default='') {
    try {
        $s=getDB()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
        $s->execute([$key]); $r=$s->fetch();
        return $r ? $r['setting_value'] : $default;
    } catch(Exception $e) { return $default; }
}
function setSetting($key, $val) {
    getDB()->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?')
           ->execute([$key,$val,$val]);
}
function isMaintenanceMode() { return getSetting('maintenance_mode','0') === '1'; }

/* -- Notifications -- */
function addNotification($userId, $title, $message='', $link='') {
    try {
        getDB()->prepare('INSERT INTO notifications (user_id, title, message, link) VALUES (?,?,?,?)')
               ->execute([$userId, $title, $message ?: null, $link ?: null]);
    } catch(Exception $e) {}
}
