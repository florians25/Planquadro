<?php
/**
 * Planquadro – Stammdaten: Kunden, Standorte, Qualifikationen und die Zuordnung
 * von Einsatzcodes zu Kunde/Standort. Zugriff wie "Bereiche"
 * (Manager, Verwaltung, Administrator).
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireConfigManager();
requireModule('masterdata');
$db = getDB();

function sdStr($k, $max = 300) { $v = trim((string)($_POST[$k] ?? '')); return $v === '' ? null : mb_substr($v, 0, $max); }
function sdAudit($a, $d) { try { getDB()->prepare('INSERT INTO audit_log(user_id,action,details,ip_address) VALUES(?,?,?,?)')->execute([$_SESSION['user_id'], $a, mb_substr($d, 0, 500), opsClientIp()]); } catch (Throwable $e) {} }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($act === 'save_customer') {
            $name = sdStr('name', 200);
            if (!$name) throw new InvalidArgumentException('Name ist erforderlich.');
            $email = sdStr('email', 190);
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-Mail-Adresse ist ungültig.');
            $vals = [$name, sdStr('customer_no', 50), sdStr('address'), sdStr('contact_name', 150), sdStr('phone', 60), $email, sdStr('notes', 2000), !empty($_POST['active']) ? 1 : 0];
            if ($id) $db->prepare('UPDATE customers SET name=?,customer_no=?,address=?,contact_name=?,phone=?,email=?,notes=?,active=? WHERE id=?')->execute(array_merge($vals, [$id]));
            else $db->prepare('INSERT INTO customers(name,customer_no,address,contact_name,phone,email,notes,active) VALUES(?,?,?,?,?,?,?,?)')->execute($vals);
            sdAudit('masterdata', 'customer ' . ($id ?: 'new') . ': ' . $name);
            echo json_encode(['success' => true, 'msg' => 'Kunde gespeichert.']); exit;
        }
        if ($act === 'save_location') {
            $name = sdStr('name', 200);
            if (!$name) throw new InvalidArgumentException('Name ist erforderlich.');
            $lat = ($_POST['lat'] ?? '') === '' ? null : (float)str_replace(',', '.', $_POST['lat']);
            $lng = ($_POST['lng'] ?? '') === '' ? null : (float)str_replace(',', '.', $_POST['lng']);
            if (($lat !== null && abs($lat) > 90) || ($lng !== null && abs($lng) > 180)) throw new InvalidArgumentException('Koordinaten ungültig.');
            $cid = (int)($_POST['customer_id'] ?? 0) ?: null;
            $vals = [$name, $cid, sdStr('address'), $lat, $lng, sdStr('notes', 2000), !empty($_POST['active']) ? 1 : 0];
            if ($id) $db->prepare('UPDATE locations SET name=?,customer_id=?,address=?,lat=?,lng=?,notes=?,active=? WHERE id=?')->execute(array_merge($vals, [$id]));
            else $db->prepare('INSERT INTO locations(name,customer_id,address,lat,lng,notes,active) VALUES(?,?,?,?,?,?,?)')->execute($vals);
            sdAudit('masterdata', 'location ' . ($id ?: 'new') . ': ' . $name);
            echo json_encode(['success' => true, 'msg' => 'Standort gespeichert.']); exit;
        }
        if ($act === 'save_qualification') {
            $name = sdStr('name', 150);
            if (!$name) throw new InvalidArgumentException('Name ist erforderlich.');
            $color = opsValidColor($_POST['color'] ?? '') ?? '#64748b';
            if ($id) $db->prepare('UPDATE qualifications SET name=?,description=?,color=? WHERE id=?')->execute([$name, sdStr('description'), $color, $id]);
            else $db->prepare('INSERT INTO qualifications(name,description,color) VALUES(?,?,?)')->execute([$name, sdStr('description'), $color]);
            echo json_encode(['success' => true, 'msg' => 'Qualifikation gespeichert.']); exit;
        }
        if ($act === 'save_holders') {
            $db->beginTransaction();
            $db->prepare('DELETE FROM user_qualifications WHERE qualification_id=?')->execute([$id]);
            $ins = $db->prepare('INSERT INTO user_qualifications(user_id,qualification_id) VALUES(?,?)');
            foreach (array_unique(array_map('intval', (array)($_POST['users'] ?? []))) as $uid) if ($uid > 0) $ins->execute([$uid, $id]);
            $db->commit();
            sdAudit('masterdata', 'qualification holders ' . $id);
            echo json_encode(['success' => true, 'msg' => 'Zuordnung gespeichert.']); exit;
        }
        if ($act === 'save_area_links') {
            $up = $db->prepare('UPDATE plan_areas SET customer_id=?, location_id=? WHERE id=?');
            foreach ((array)($_POST['area'] ?? []) as $aid => $v) {
                $up->execute([(int)($v['customer'] ?? 0) ?: null, (int)($v['location'] ?? 0) ?: null, (int)$aid]);
            }
            sdAudit('masterdata', 'area links');
            echo json_encode(['success' => true, 'msg' => 'Zuordnung gespeichert.']); exit;
        }
        if ($act === 'delete') {
            $table = ['customer' => 'customers', 'location' => 'locations', 'qualification' => 'qualifications'][$_POST['type'] ?? ''] ?? null;
            if (!$table) throw new InvalidArgumentException('Ungültiger Typ.');
            if ($table === 'customers') $db->prepare('UPDATE plan_areas SET customer_id=NULL WHERE customer_id=?')->execute([$id]);
            if ($table === 'locations') $db->prepare('UPDATE plan_areas SET location_id=NULL WHERE location_id=?')->execute([$id]);
            $db->prepare("DELETE FROM `$table` WHERE id=?")->execute([$id]);
            sdAudit('masterdata', "delete $table $id");
            echo json_encode(['success' => true, 'msg' => 'Gelöscht.']); exit;
        }
        echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion']);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('stammdaten: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Speichern fehlgeschlagen.']);
    }
    exit;
}

$pageTitle = 'Stammdaten';
require_once __DIR__ . '/../includes/header.php';
$customers = $db->query('SELECT * FROM customers ORDER BY active DESC, name')->fetchAll();
$locations = $db->query('SELECT l.*, c.name AS customer_name FROM locations l LEFT JOIN customers c ON c.id=l.customer_id ORDER BY l.active DESC, l.name')->fetchAll();
$quals = $db->query('SELECT q.*, (SELECT COUNT(*) FROM user_qualifications uq WHERE uq.qualification_id=q.id) holders FROM qualifications q ORDER BY q.name')->fetchAll();
$holders = [];
foreach ($db->query('SELECT user_id, qualification_id FROM user_qualifications')->fetchAll() as $r) $holders[(int)$r['qualification_id']][(int)$r['user_id']] = true;
$people = planningUsers(true, false);
$areas = planAreas();
$tab = in_array($_GET['tab'] ?? '', ['kunden', 'standorte', 'qualifikationen', 'zuordnung'], true) ? $_GET['tab'] : 'kunden';
?>
<div class="ph"><div class="phl"><h1>Stammdaten</h1><span class="muted" style="font-size:.82rem"><?php echo count($customers); ?> Kunden · <?php echo count($locations); ?> Standorte · <?php echo count($quals); ?> Qualifikationen</span></div></div>

<nav class="sd-tabs" role="tablist" aria-label="Stammdaten">
  <?php foreach (['kunden' => 'Kunden', 'standorte' => 'Standorte', 'qualifikationen' => 'Qualifikationen', 'zuordnung' => 'Einsatzcodes zuordnen'] as $k => $l): ?>
  <a role="tab" aria-selected="<?php echo $tab === $k ? 'true' : 'false'; ?>" class="sd-tab<?php echo $tab === $k ? ' active' : ''; ?>" href="?tab=<?php echo $k; ?>"><?php echo $l; ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'kunden'): ?>
<div class="card"><div class="cb p0">
<table class="tbl sd-tbl"><thead><tr><th>Name</th><th>Nr.</th><th>Adresse</th><th>Ansprechpartner</th><th>Telefon / E-Mail</th><th>Aktiv</th><th></th></tr></thead><tbody>
<?php foreach (array_merge($customers, [['id' => 0, 'name' => '', 'customer_no' => '', 'address' => '', 'contact_name' => '', 'phone' => '', 'email' => '', 'notes' => '', 'active' => 1]]) as $c): ?>
<tr><td colspan="7"><form class="sd-form" data-action="save_customer">
  <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
  <input name="name" aria-label="Name" placeholder="<?php echo $c['id'] ? '' : 'Neuer Kunde …'; ?>" value="<?php echo e($c['name']); ?>" required>
  <input name="customer_no" aria-label="Kundennummer" placeholder="Nr." value="<?php echo e($c['customer_no']); ?>" class="sd-s">
  <input name="address" aria-label="Adresse" placeholder="Adresse" value="<?php echo e($c['address']); ?>">
  <input name="contact_name" aria-label="Ansprechpartner" placeholder="Ansprechpartner" value="<?php echo e($c['contact_name']); ?>">
  <input name="phone" aria-label="Telefon" placeholder="Telefon" value="<?php echo e($c['phone']); ?>" class="sd-s">
  <input name="email" aria-label="E-Mail" placeholder="E-Mail" type="email" value="<?php echo e($c['email']); ?>">
  <label class="sd-chk"><input type="checkbox" name="active" value="1" <?php if ($c['active']) echo 'checked'; ?>> aktiv</label>
  <textarea name="notes" aria-label="Hinweise für Techniker" placeholder="Hinweise für Techniker (Zugang, Parken, Sicherheit …)" rows="1"><?php echo e($c['notes']); ?></textarea>
  <button class="btn btn-sm btn-primary"><?php echo $c['id'] ? 'Speichern' : 'Anlegen'; ?></button>
  <?php if ($c['id']): ?><button type="button" class="btn btn-sm btn-outline" data-del="customer" data-id="<?php echo (int)$c['id']; ?>">Löschen</button><?php endif; ?>
</form></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>

<?php elseif ($tab === 'standorte'): ?>
<div class="card"><div class="cb p0">
<table class="tbl sd-tbl"><tbody>
<?php foreach (array_merge($locations, [['id' => 0, 'name' => '', 'customer_id' => null, 'address' => '', 'lat' => '', 'lng' => '', 'notes' => '', 'active' => 1]]) as $l): ?>
<tr><td><form class="sd-form" data-action="save_location">
  <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
  <input name="name" aria-label="Name" placeholder="<?php echo $l['id'] ? '' : 'Neuer Standort …'; ?>" value="<?php echo e($l['name']); ?>" required>
  <select name="customer_id" aria-label="Kunde"><option value="">– kein Kunde –</option><?php foreach ($customers as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php if ((int)$l['customer_id'] === (int)$c['id']) echo 'selected'; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
  <input name="address" aria-label="Adresse" placeholder="Adresse" value="<?php echo e($l['address']); ?>">
  <input name="lat" aria-label="Breitengrad" placeholder="Breite" value="<?php echo e($l['lat']); ?>" class="sd-s" inputmode="decimal">
  <input name="lng" aria-label="Längengrad" placeholder="Länge" value="<?php echo e($l['lng']); ?>" class="sd-s" inputmode="decimal">
  <label class="sd-chk"><input type="checkbox" name="active" value="1" <?php if ($l['active']) echo 'checked'; ?>> aktiv</label>
  <textarea name="notes" aria-label="Hinweise" placeholder="Hinweise" rows="1"><?php echo e($l['notes']); ?></textarea>
  <button class="btn btn-sm btn-primary"><?php echo $l['id'] ? 'Speichern' : 'Anlegen'; ?></button>
  <?php if ($l['id']): ?><button type="button" class="btn btn-sm btn-outline" data-del="location" data-id="<?php echo (int)$l['id']; ?>">Löschen</button><?php endif; ?>
</form></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>

<?php elseif ($tab === 'qualifikationen'): ?>
<div class="card"><div class="cb">
<?php foreach (array_merge($quals, [['id' => 0, 'name' => '', 'description' => '', 'color' => '#64748b', 'holders' => 0]]) as $q): ?>
<div class="sd-qual">
  <form class="sd-form" data-action="save_qualification">
    <input type="hidden" name="id" value="<?php echo (int)$q['id']; ?>">
    <input type="color" name="color" aria-label="Farbe" value="<?php echo e($q['color']); ?>">
    <input name="name" aria-label="Name" placeholder="<?php echo $q['id'] ? '' : 'Neue Qualifikation …'; ?>" value="<?php echo e($q['name']); ?>" required>
    <input name="description" aria-label="Beschreibung" placeholder="Beschreibung" value="<?php echo e($q['description']); ?>">
    <button class="btn btn-sm btn-primary"><?php echo $q['id'] ? 'Speichern' : 'Anlegen'; ?></button>
    <?php if ($q['id']): ?><button type="button" class="btn btn-sm btn-outline" data-del="qualification" data-id="<?php echo (int)$q['id']; ?>">Löschen</button><?php endif; ?>
  </form>
  <?php if ($q['id']): ?>
  <details><summary><?php echo (int)$q['holders']; ?> Mitarbeiter mit dieser Qualifikation</summary>
    <form class="sd-form sd-holders" data-action="save_holders">
      <input type="hidden" name="id" value="<?php echo (int)$q['id']; ?>">
      <input type="search" class="sd-filter" placeholder="Filtern …" aria-label="Mitarbeiter filtern">
      <div class="sd-people">
      <?php foreach ($people as $p): ?>
        <label class="sd-chk" data-name="<?php echo e(mb_strtolower($p['last_name'] . ' ' . $p['first_name'])); ?>"><input type="checkbox" name="users[]" value="<?php echo (int)$p['id']; ?>" <?php if (isset($holders[(int)$q['id']][(int)$p['id']])) echo 'checked'; ?>> <?php echo e($p['last_name'] . ', ' . $p['first_name']); ?></label>
      <?php endforeach; ?>
      </div>
      <button class="btn btn-sm btn-primary">Zuordnung speichern</button>
    </form>
  </details>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div></div>

<?php else: ?>
<div class="card"><div class="cb">
<p class="muted" style="font-size:.8rem">Ordnet jedem Einsatzcode optional einen Kunden und Standort zu. Mitarbeiter sehen die Kundeninformationen dann in der App bei ihrem Einsatz.</p>
<form class="sd-form sd-links" data-action="save_area_links">
<table class="tbl"><thead><tr><th>Code</th><th>Bereich</th><th>Kunde</th><th>Standort</th></tr></thead><tbody>
<?php foreach ($areas as $a): ?>
<tr><td><span class="ts" style="background:<?php echo e($a['color']); ?>;color:<?php echo e($a['fg_color']); ?>"><?php echo e($a['short_code']); ?></span></td><td><?php echo e($a['name']); ?></td>
<td><select name="area[<?php echo (int)$a['id']; ?>][customer]" aria-label="Kunde für <?php echo e($a['short_code']); ?>"><option value="">–</option><?php foreach ($customers as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php if ((int)($a['customer_id'] ?? 0) === (int)$c['id']) echo 'selected'; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></td>
<td><select name="area[<?php echo (int)$a['id']; ?>][location]" aria-label="Standort für <?php echo e($a['short_code']); ?>"><option value="">–</option><?php foreach ($locations as $l): ?><option value="<?php echo (int)$l['id']; ?>" <?php if ((int)($a['location_id'] ?? 0) === (int)$l['id']) echo 'selected'; ?>><?php echo e($l['name']); ?></option><?php endforeach; ?></select></td></tr>
<?php endforeach; ?>
</tbody></table>
<button class="btn btn-primary">Zuordnung speichern</button>
</form></div></div>
<?php endif; ?>

<style>
.sd-tabs{display:flex;gap:.25rem;margin-bottom:.75rem;flex-wrap:wrap}
.sd-tab{padding:.45rem .8rem;border-radius:var(--r);border:1px solid var(--bd);background:var(--card);color:var(--tx2);font-weight:600;font-size:.82rem;text-decoration:none}
.sd-tab.active{background:var(--p);border-color:var(--p);color:var(--p-on,#fff)}
.sd-form{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}
.sd-form input:not([type=checkbox]):not([type=color]),.sd-form select,.sd-form textarea{padding:.35rem .5rem;border:1px solid var(--bd2);border-radius:var(--r);font-size:.82rem;background:var(--card);color:var(--tx);min-width:120px;flex:1}
.sd-form .sd-s{flex:0 0 90px;min-width:70px}.sd-form textarea{flex-basis:100%}
.sd-chk{display:inline-flex;gap:.3rem;align-items:center;font-size:.8rem;min-height:28px}
.sd-qual{border-bottom:1px solid var(--bd);padding:.6rem 0}.sd-qual details{margin-top:.4rem}
.sd-people{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.15rem .75rem;width:100%;max-height:260px;overflow:auto}
.sd-tbl td{padding:.4rem .6rem}
</style>
<script>
document.querySelectorAll('.sd-form').forEach(function(f){
  f.addEventListener('submit',function(e){e.preventDefault();var fd=new FormData(f);fd.append('action',f.dataset.action);ajaxPost('stammdaten',fd,function(){setTimeout(function(){location.reload();},500);});});
});
document.querySelectorAll('[data-del]').forEach(function(b){
  b.addEventListener('click',function(){epConfirm('Wirklich löschen? Zuordnungen zu Einsatzcodes werden entfernt.',function(){var fd=new FormData();fd.append('action','delete');fd.append('type',b.dataset.del);fd.append('id',b.dataset.id);ajaxPost('stammdaten',fd,function(){setTimeout(function(){location.reload();},400);});});});
});
document.querySelectorAll('.sd-filter').forEach(function(i){
  i.addEventListener('input',function(){var q=i.value.toLowerCase();i.closest('form').querySelectorAll('.sd-people label').forEach(function(l){l.style.display=l.dataset.name.indexOf(q)>=0?'':'none';});});
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
