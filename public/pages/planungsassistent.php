<?php
/**
 * Planquadro Einsatzplanung – Planungsassistent (Beta)
 *
 * Copyright (c) 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten.
 * -----------------------------------------------------------------------------
 * Geführter Ablauf in vier Schritten:
 *   ① Zeitraum & Team   ② Bedarf & Regeln   ③ Vorschlag prüfen   ④ Übernehmen
 *
 * Die Übernahme schreibt ausschließlich in `plan_drafts` – veröffentlicht wird
 * weiterhin bewusst in der Monatsplanung. Jede Übernahme ist rücknehmbar.
 *
 * Aufteilung
 *   app/lib/PlanningAssistant.php      Algorithmus, Übernahme, AJAX-Aktionen
 *   public/assets/js/assistant-core.js reine Logik (Prüfung, Zeiträume, Texte)
 *   public/assets/js/assistant.js      Oberfläche
 *   public/assets/css/assistant.css    Gestaltung (hell/dunkel, Druck)
 * Diese Seite enthält keine Inline-Skripte, -Stile oder -Handler (CSP);
 * Startdaten stehen als JSON in <script type="application/json">.
 *
 * Voraussetzung: migrate_v6_8_0, migrate_v6_9_0, migrations/app/0010.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../app/lib/PlanCells.php';
require_once __DIR__ . '/../../app/lib/Events.php';
require_once __DIR__ . '/../../app/lib/PlanningAssistant.php';
requireLogin();
requireModule('assistant');

$db  = getDB();
$ME  = (int)($_SESSION['user_id'] ?? 0);

/* Wer planen darf, darf den Assistenten nutzen (eigene Berechtigung optional). */
$EPA_IS_AJAX = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
$EPA_MAY_USE = hasPerm('use_planning_assistant') || hasPerm('plan_edit');
if (!$EPA_MAY_USE) {
    /* Hintergrundaufrufe brauchen JSON – sonst landet HTML im Browser-Handler. */
    if ($EPA_IS_AJAX) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Keine Berechtigung für den Planungsassistenten.']);
        exit;
    }
    showAccessDenied('Sie benötigen die Berechtigung "Planen" bzw. "Planungsassistent nutzen".');
}

/* Vorgaben aus den Einstellungen (Seite „Planung“) */
$EPA_DEFAULTS = epa_defaults();
$EPA_ENV = [
    'me'           => $ME,
    'may_absence'  => hasPerm('unlock_vacation'),     // darf Urlaub/Krank überschreiben
    'may_internal' => hasPerm('plan_view_internal'),
    'may_manage'   => hasPerm('manage_settings'),     // fremde Läufe/Vorlagen verwalten
    'defaults'     => $EPA_DEFAULTS,
];
$EPA_BP = bp();

/* =============================================================================
   AJAX
   ========================================================================== */
if ($EPA_IS_AJAX) {
    header('Content-Type: application/json; charset=utf-8');
    /* Auch schwere Fehler sollen als JSON ankommen. */
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false,
                'error' => 'Serverfehler beim Verarbeiten der Anfrage. Bitte melden Sie den Vorgang.',
                'detail' => basename($e['file']) . ':' . $e['line']]);
        }
    });
    try {
        echo json_encode(epa_handle($db, (string)($_POST['action'] ?? ''), $_POST, $EPA_ENV));
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        opsApiFail($e, 'planungsassistent', ['success' => false, 'error' => opsPublicMessage($e)]);
    }
    exit;
}

/* =============================================================================
   SEITENDATEN (Erstaufbau)
   ========================================================================== */
$scope = epa_scope([], $EPA_DEFAULTS);   // Seitenaufbau: zunächst nur das eigene Team

$areas = $db->query('SELECT id, name, short_code, color, fg_color, area_type FROM plan_areas ORDER BY area_type DESC, sort_order, name')->fetchAll(PDO::FETCH_ASSOC);

$roleRows = [];
try { $roleRows = $db->query('SELECT role_key, role_label, color, base_role, sort_order FROM custom_roles ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
if (!$roleRows) {
    $roleRows = [
        ['role_key' => 'manager', 'role_label' => 'Manager', 'color' => '#2563eb', 'base_role' => 'manager', 'sort_order' => 2],
        ['role_key' => 'teamleiter', 'role_label' => 'Teamleiter', 'color' => '#7c3aed', 'base_role' => 'teamleiter', 'sort_order' => 3],
        ['role_key' => 'mitarbeiter', 'role_label' => 'Techniker', 'color' => '#059669', 'base_role' => 'mitarbeiter', 'sort_order' => 6],
        ['role_key' => 'azubi_1', 'role_label' => 'Azubi 1. LJ', 'color' => '#ec4899', 'base_role' => 'azubi_1', 'sort_order' => 7],
        ['role_key' => 'azubi_2', 'role_label' => 'Azubi 2. LJ', 'color' => '#f472b6', 'base_role' => 'azubi_2', 'sort_order' => 8],
        ['role_key' => 'azubi_3', 'role_label' => 'Azubi 3. LJ', 'color' => '#f9a8d4', 'base_role' => 'azubi_3', 'sort_order' => 9],
    ];
}

$uSql = 'SELECT id, first_name, last_name, role, os_level, training_profession, primary_area_id
           FROM users WHERE active = 1 AND role <> \'admin\'';
$uPar = [];
if ($scope !== null) {
    if (!$scope) { $scope = [0]; }
    $uSql .= ' AND id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
    $uPar = $scope;
}
$uSql .= ' ORDER BY last_name, first_name';
$us = $db->prepare($uSql); $us->execute($uPar);
$userRows = $us->fetchAll(PDO::FETCH_ASSOC);

$osLevels = array_values(array_filter(array_unique(array_map(function ($u) { return trim((string)$u['os_level']); }, $userRows))));
sort($osLevels);
$professions = array_values(array_filter(array_unique(array_map(function ($u) { return trim((string)$u['training_profession']); }, $userRows))));
sort($professions);

$boot = [
    'areas'   => $areas,
    'roles'   => $roleRows,
    'users'   => $userRows,
    'os'      => $osLevels,
    'profs'   => $professions,
    'perm'    => ['absence' => $EPA_ENV['may_absence'], 'internal' => $EPA_ENV['may_internal'],
                  'manage' => $EPA_ENV['may_manage'], 'all' => ($scope === null)],
    /* Allgemein-Bereiche mit Vorschlag, welche als freie Kapazität gelten.
       Bewusst nicht am Kürzel festgemacht – das heißt nicht überall "T". */
    'general' => array_values(array_map(function ($a) {
                     $txt = mb_strtolower($a['name'] . ' ' . $a['short_code']);
                     $a['default_free'] = !preg_match('/schul|urlaub|krank|feiertag|frei|abwesen/u', $txt);
                     return $a;
                 }, array_filter($areas, function ($a) {
                     return $a['area_type'] === 'allgemein' && strtoupper($a['short_code']) !== 'BS';
                 }))),
    'defaults'     => $EPA_DEFAULTS,
    'may_cross'    => epa_may_cross_team($EPA_DEFAULTS),
    'own_team_only'=> ($scope !== null),
    'today'        => date('Y-m-d'),
    'me'           => $ME,
    'planning_url' => $EPA_BP . 'monatsplanung',
];
$v = function (string $f) { return @filemtime(__DIR__ . '/../assets/' . $f); };

$pageTitle = 'Planungsassistent';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo e($EPA_BP); ?>assets/css/assistant.css?v=<?php echo $v('css/assistant.css'); ?>">
<script type="application/json" id="pqaBoot" nonce="<?php echo opsCspNonce(); ?>"><?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

<div class="pqa" id="pqa">
<p class="sr-only" id="pqaLive" role="status" aria-live="polite"></p>

<!-- ======================== Übersicht ======================== -->
<section id="epaHome" class="pqa-home" aria-labelledby="pqaHomeTitle">
  <header class="pqa-hero">
    <div class="pqa-hero-t">
      <h1 id="pqaHomeTitle">Planungsassistent <span class="pqa-beta" title="Neue Funktion – noch in Erprobung">Beta</span></h1>
      <p>Einsätze und Kundenprojekte in vier Schritten planen. Der Assistent schlägt vor, Sie entscheiden –
         übernommen wird nur als <b>Entwurf</b>, jederzeit rücknehmbar.</p>
      <ol class="pqa-hero-steps" aria-label="Ablauf">
        <li><span>1</span>Zeitraum &amp; Team</li><li><span>2</span>Bedarf &amp; Regeln</li>
        <li><span>3</span>Vorschlag prüfen</li><li><span>4</span>Übernehmen</li>
      </ol>
    </div>
    <div class="pqa-hero-a">
      <button type="button" class="btn btn-primary pqa-btn-lg" data-act="new">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        Neue Planung</button>
      <a class="btn btn-outline" href="<?php echo e($EPA_BP); ?>monatsplanung">Zur Monatsplanung</a>
    </div>
  </header>

  <div class="pqa-home-grid">
    <section class="card pqa-card" aria-labelledby="pqaTplTitle">
      <div class="ch"><h2 id="pqaTplTitle" class="pqa-h2">Vorlagen</h2><span class="muted pqa-count" id="tplCount"></span></div>
      <div class="cb" id="tplList"><p class="muted">Wird geladen …</p></div>
    </section>
    <section class="card pqa-card" aria-labelledby="pqaRunTitle">
      <div class="ch"><h2 id="pqaRunTitle" class="pqa-h2">Letzte Übernahmen</h2><span class="muted pqa-count" id="runCount"></span></div>
      <div class="cb" id="runList"><p class="muted">Wird geladen …</p></div>
    </section>
  </div>

  <section class="card pqa-card" aria-labelledby="pqaPlansTitle">
    <div class="ch"><h2 id="pqaPlansTitle" class="pqa-h2">Gespeicherte Planungen</h2>
      <span class="muted pqa-count" id="homeCount"></span></div>
    <div class="cb" id="homeList"><p class="muted">Wird geladen …</p></div>
  </section>
</section>

<!-- ======================== Assistent ======================== -->
<section id="epaWizard" class="pqa-wizard" hidden aria-labelledby="epaSub">
  <header class="pqa-top">
    <button type="button" class="btn btn-outline btn-sm" data-act="home" title="Zur Übersicht der Planungen">&larr; Übersicht</button>
    <div class="pqa-top-t">
      <h1 class="pqa-title"><span id="epaSub">Neue Planung</span> <span class="pqa-beta">Beta</span></h1>
      <div class="pqa-top-meta">
        <span class="pqa-chip" id="epaRange">Zeitraum offen</span>
        <span class="pqa-chip pqa-chip-muted" id="epaSaved" hidden></span>
      </div>
    </div>
    <div class="pqa-top-a">
      <span class="pqa-undo-pill" id="undoPill" hidden>
        <span id="undoPillTxt"></span>
        <button type="button" class="btn btn-sm btn-outline pqa-btn-undo" data-act="undo">Rückgängig</button>
      </span>
      <button type="button" class="btn btn-outline btn-sm" data-act="save-plan" id="btnSavePlan">Speichern</button>
      <button type="button" class="btn btn-outline btn-sm" data-act="templates">Vorlagen</button>
      <div class="pqa-menu">
        <button type="button" class="btn btn-outline btn-sm" id="moreBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="moreMenu">Mehr <span aria-hidden="true">▾</span></button>
        <div class="pqa-menu-list" id="moreMenu" role="menu" hidden>
          <button type="button" role="menuitem" data-act="save-profile">Als Vorlage sichern</button>
          <button type="button" role="menuitem" data-act="print">Drucken / PDF</button>
          <button type="button" role="menuitem" data-act="reset">Zurücksetzen</button>
          <a role="menuitem" href="<?php echo e($EPA_BP); ?>monatsplanung">Zur Monatsplanung</a>
        </div>
      </div>
    </div>
  </header>

  <nav class="pqa-steps" aria-label="Schritte des Assistenten">
    <ol>
      <li><button type="button" class="pqa-step" data-step="0"><span class="pqa-step-n" aria-hidden="true">1</span>
        <span class="pqa-step-t"><b>Zeitraum &amp; Team</b><small id="stepSum0">Bereich und Zeitraum wählen</small></span></button></li>
      <li><button type="button" class="pqa-step" data-step="1"><span class="pqa-step-n" aria-hidden="true">2</span>
        <span class="pqa-step-t"><b>Bedarf &amp; Regeln</b><small id="stepSum1">Besetzung je Tag</small></span></button></li>
      <li><button type="button" class="pqa-step" data-step="2"><span class="pqa-step-n" aria-hidden="true">3</span>
        <span class="pqa-step-t"><b>Vorschlag prüfen</b><small id="stepSum2">Raster ansehen und anpassen</small></span></button></li>
      <li><button type="button" class="pqa-step" data-step="3"><span class="pqa-step-n" aria-hidden="true">4</span>
        <span class="pqa-step-t"><b>Übernehmen</b><small id="stepSum3">als Entwurf speichern</small></span></button></li>
    </ol>
  </nav>

  <div class="pqa-note" role="note">
    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
    <span>Der Assistent schreibt nur <b>Entwürfe</b> – veröffentlicht wird in der Monatsplanung. Jede Übernahme ist rücknehmbar.</span>
  </div>

  <div class="pqa-layout" id="pqaLayout">
  <div class="pqa-main">

    <!-- ---------------- ① Zeitraum & Team ---------------- -->
    <div class="pqa-panel" data-panel="0" role="region" aria-label="Schritt 1: Zeitraum und Team">
      <section class="card pqa-sec">
        <h2 class="pqa-h2">Einsatz</h2>
        <div class="pqa-grid2">
          <div class="fg"><label for="fArea">Einsatzbereich / Kunde <span class="pqa-req" aria-hidden="true">*</span></label>
            <select id="fArea" required aria-required="true"></select></div>
          <div class="fg"><label for="fTitle">Bezeichnung</label>
            <input type="text" id="fTitle" maxlength="140" placeholder="z. B. Rollout Kunde Nord – Phase 2"></div>
        </div>
        <details class="pqa-more" id="moreEntry">
          <summary>Bemerkung und Sichtbarkeit <small class="pqa-sum" id="sumEntry"></small></summary>
          <div class="pqa-more-b">
            <div class="fg"><label for="fRemarks">Bemerkung am Eintrag (optional)</label>
              <input type="text" id="fRemarks" maxlength="200" placeholder="Leer lassen = Bezeichnung verwenden"></div>
            <div class="fg-check" id="rowInternal"><input type="checkbox" id="fInternal"><label for="fInternal">Als interne Eintragung markieren (nur für Berechtigte sichtbar)</label></div>
          </div>
        </details>
      </section>

      <section class="card pqa-sec">
        <h2 class="pqa-h2">Zeitraum</h2>
        <div class="pqa-presets" id="fPresets" role="group" aria-label="Zeitraum schnell wählen"></div>
        <div class="pqa-grid2">
          <div class="fg"><label for="fStart">Von</label><input type="date" id="fStart"></div>
          <div class="fg"><label for="fEnd">Bis</label><input type="date" id="fEnd"></div>
        </div>
        <p class="pqa-hint" id="rangeInfo">Höchstens 120 Tage je Lauf – längere Projekte in Etappen planen.</p>
        <fieldset class="pqa-fs">
          <legend>Einsatztage</legend>
          <div class="pqa-toggles" id="fWeekdays"></div>
        </fieldset>
        <div class="fg-check"><input type="checkbox" id="fSkipHol" checked><label for="fSkipHol">Gesetzliche Feiertage überspringen</label></div>
      </section>

      <section class="card pqa-sec">
        <h2 class="pqa-h2">Team</h2>
        <div id="rowCross" class="pqa-scope">
          <div class="pqa-seg" role="radiogroup" aria-label="Personenkreis">
            <label><input type="radio" name="fScope" value="own" checked><span>Eigenes Team</span></label>
            <label><input type="radio" name="fScope" value="cross"><span>Teamübergreifend</span></label>
          </div>
          <p class="pqa-hint" id="crossHint">Teamübergreifend greift in die Planung anderer Teams ein – bitte vorher absprechen.
            Abwesenheitsgründe außerhalb Ihres Teams bleiben verborgen; jede Übernahme wird protokolliert.</p>
        </div>
        <fieldset class="pqa-fs">
          <legend>Rollen <small>(ohne Auswahl kommen alle planbaren Personen infrage)</small></legend>
          <div class="pqa-toggles" id="fRoles"></div>
        </fieldset>
        <details class="pqa-more" id="morePeople">
          <summary>Einzelne Personen fest einplanen oder ausschließen <small class="pqa-sum" id="sumPeople"></small></summary>
          <div class="pqa-more-b">
            <p class="pqa-hint">„Fest“ plant an jedem möglichen Tag ein, „Raus“ schließt komplett aus. Die Liste zeigt Stammbereich, Qualifikation und frühere Einsätze beim gewählten Kunden.</p>
            <div class="pqa-filter">
              <input type="search" class="inp-sm" id="fPersonSearch" aria-label="Personen suchen" placeholder="Name, Kürzel, OS-Level oder Beruf …">
              <select class="sel sel-sm" id="fFltArea" aria-label="Stammbereich"><option value="">Alle Stammbereiche</option></select>
              <select class="sel sel-sm" id="fFltRole" aria-label="Rolle"><option value="">Alle Rollen</option></select>
              <select class="sel sel-sm" id="fFltSort" aria-label="Sortierung">
                <option value="best">Sortierung: Beste zuerst</option>
                <option value="name">Sortierung: Name</option>
                <option value="exp">Sortierung: Kundenerfahrung</option>
                <option value="os">Sortierung: OS-Level</option>
              </select>
            </div>
            <div class="pqa-filter">
              <label class="pqa-chk"><input type="checkbox" id="fFltHome"> Nur Stammbereich</label>
              <label class="pqa-chk"><input type="checkbox" id="fFltExp"> Nur mit Kundenerfahrung</label>
              <label class="pqa-chk"><input type="checkbox" id="fFltNoAz"> Ohne Azubis</label>
              <label class="pqa-chk"><input type="checkbox" id="fFltWe"> Nur mit Wochenend-Freigabe</label>
              <label class="pqa-chk"><input type="checkbox" id="fFltFlex"> Nur gut umplanbare</label>
              <span class="muted pqa-count" id="fPeopleCnt" aria-live="polite"></span>
            </div>
            <div class="pqa-filter">
              <button type="button" class="btn btn-xs btn-outline" id="fBulkFix">Sichtbare fest einplanen</button>
              <button type="button" class="btn btn-xs btn-outline" id="fBulkOut">Sichtbare ausschließen</button>
              <button type="button" class="btn btn-xs btn-outline" id="fBulkClr">Auswahl zurücksetzen</button>
            </div>
            <div class="pqa-people" id="fPeople"></div>
          </div>
        </details>
      </section>
    </div>

    <!-- ---------------- ② Bedarf & Regeln ---------------- -->
    <div class="pqa-panel" data-panel="1" role="region" aria-label="Schritt 2: Bedarf und Regeln" hidden>
      <section class="card pqa-sec">
        <h2 class="pqa-h2">Bedarf</h2>
        <div class="pqa-grid2">
          <div class="pqa-num-row">
            <label for="fDemand"><b>Personen pro Tag</b><small>Zielbesetzung, die der Assistent erreichen will</small></label>
            <span class="pqa-stepper"><button type="button" data-step-for="fDemand" data-d="-1" aria-label="Weniger">−</button><input type="number" id="fDemand" value="2" min="1" max="99" inputmode="numeric"><button type="button" data-step-for="fDemand" data-d="1" aria-label="Mehr">+</button></span>
          </div>
          <div class="pqa-num-row">
            <label for="fDemandMin"><b>Mindestbesetzung</b><small>darunter meldet die Prüfung einen Blocker</small></label>
            <span class="pqa-stepper"><button type="button" data-step-for="fDemandMin" data-d="-1" aria-label="Weniger">−</button><input type="number" id="fDemandMin" value="1" min="0" max="99" inputmode="numeric"><button type="button" data-step-for="fDemandMin" data-d="1" aria-label="Mehr">+</button></span>
          </div>
        </div>
        <details class="pqa-more" id="moreWd">
          <summary>Je Wochentag abweichend <small class="pqa-sum" id="sumWd"></small></summary>
          <div class="pqa-more-b">
            <p class="pqa-hint">Leer heißt Regelbedarf. Ausgegraute Tage sind in Schritt 1 nicht aktiviert.</p>
            <div class="pqa-dgrid" id="fDemandWd"></div>
          </div>
        </details>
        <details class="pqa-more" id="moreSpecial">
          <summary>Sondertage <small class="pqa-sum" id="sumSpecial"></small></summary>
          <div class="pqa-more-b">
            <p class="pqa-hint">Einzelne Termine mit abweichender Besetzung. Wert 0 nimmt den Tag aus der Planung.</p>
            <div id="fSpecial"></div>
            <button type="button" class="btn btn-outline btn-xs" id="addSpecial">+ Sondertag</button>
          </div>
        </details>
      </section>

      <section class="card pqa-sec">
        <h2 class="pqa-h2">Wer passt am besten?</h2>
        <fieldset class="pqa-fs">
          <legend>Stammbereich der Mitarbeitenden</legend>
          <div class="pqa-seg" role="radiogroup" aria-describedby="fHomeModeHint">
            <label><input type="radio" name="fHomeModeR" value="prefer"><span>Bevorzugen</span></label>
            <label><input type="radio" name="fHomeModeR" value="only"><span>Ausschließlich</span></label>
            <label><input type="radio" name="fHomeModeR" value="off"><span>Egal</span></label>
          </div>
          <p class="pqa-hint" id="fHomeModeHint"></p>
        </fieldset>
        <div class="pqa-grid2">
          <div class="fg"><label for="fHomeCount">Aus dem Stammbereich (Personen/Tag)</label>
            <input type="number" id="fHomeCount" value="0" min="0" max="99">
            <small class="muted">Diese Plätze werden gezielt mit Leuten besetzt, die den Kunden betreuen.</small></div>
          <div class="fg"><span class="pqa-label">Kundenerfahrung</span>
            <div class="fg-check"><input type="checkbox" id="fUseExp" checked><label for="fUseExp">Frühere Einsätze beim Kunden mitgewichten</label></div>
            <small class="muted" id="fUseExpHint">Wer in den letzten Monaten dort war, kennt die Anlage.</small></div>
        </div>
        <details class="pqa-more" id="moreQual">
          <summary>Qualifikation <small class="pqa-sum" id="sumQual"></small></summary>
          <div class="pqa-more-b">
            <p class="pqa-hint">Der Assistent bevorzugt passende Profile und meldet, wenn eine Vorgabe an einem Tag nicht erfüllt ist.</p>
            <div class="pqa-grid2">
              <div class="fg"><label for="fOsLevel">Mindest-OS-Level</label><select id="fOsLevel"></select></div>
              <div class="fg"><label for="fOsCount">Davon mindestens (Personen/Tag)</label><input type="number" id="fOsCount" value="0" min="0" max="99"></div>
            </div>
            <fieldset class="pqa-fs"><legend>Ausbildungsberuf bevorzugen</legend><div class="pqa-toggles" id="fProfs"></div></fieldset>
          </div>
        </details>
      </section>

      <section class="card pqa-sec">
        <div class="pqa-sec-h">
          <h2 class="pqa-h2">Auszubildende</h2>
          <label class="pqa-switch"><input type="checkbox" id="azOn" checked role="switch"><span aria-hidden="true"></span>einbeziehen</label>
        </div>
        <div id="azBox">
          <p class="pqa-hint">Azubis zählen auf die Tagesbesetzung, brauchen aber Begleitung. Die Regeln werden bei jedem Vorschlag und jeder Änderung geprüft.</p>
          <fieldset class="pqa-fs"><legend>Zugelassene Lehrjahre</legend>
            <div class="pqa-toggles" id="azYears">
              <label class="pqa-tg"><input type="checkbox" data-year="1"><span>1. LJ</span></label>
              <label class="pqa-tg"><input type="checkbox" data-year="2" checked><span>2. LJ</span></label>
              <label class="pqa-tg"><input type="checkbox" data-year="3" checked><span>3. LJ</span></label>
            </div>
          </fieldset>
          <details class="pqa-more" id="moreAz">
            <summary>Azubi-Regeln <small class="pqa-sum" id="sumAz"></small></summary>
            <div class="pqa-more-b">
              <div class="pqa-rows">
                <div class="pqa-num-row"><label for="azMax"><b>Höchstens Azubis pro Tag</b><small>absolute Obergrenze</small></label><input type="number" class="pqa-num" id="azMax" value="1" min="0" max="20"></div>
                <div class="pqa-num-row"><label for="azMinDay"><b>Reservierte Ausbildungsplätze pro Tag</b><small>werden zuerst mit Azubis besetzt</small></label><input type="number" class="pqa-num" id="azMinDay" value="0" min="0" max="20"></div>
                <div class="pqa-num-row"><label for="azShare"><b>Höchstanteil an der Tagesbesetzung</b><small>50 % bei 4 Personen erlaubt höchstens 2 Azubis</small></label><span class="pqa-unit"><input type="number" class="pqa-num" id="azShare" value="50" min="0" max="100"> %</span></div>
                <div class="pqa-num-row"><label for="azRatio"><b>Betreuungsschlüssel</b><small>eine Fachkraft betreut höchstens so viele Azubis</small></label><span class="pqa-unit">1 : <input type="number" class="pqa-num" id="azRatio" value="2" min="1" max="10"></span></div>
              </div>
              <div class="fg-check"><input type="checkbox" id="azAlone" checked><label for="azAlone">Nie allein beim Kunden (Tag nur mit Azubis wird gemeldet)</label></div>
              <div class="fg-check"><input type="checkbox" id="azSchool" checked><label for="azSchool">Berufsschultage aussparen</label></div>
              <div class="fg-check"><input type="checkbox" id="azWe" checked><label for="azWe">Kein Wochenenddienst für Azubis</label></div>
            </div>
          </details>
        </div>
      </section>

      <section class="card pqa-sec">
        <details class="pqa-more pqa-expert" id="moreExpert">
          <summary><span class="pqa-h2">Experten-Optionen</span> <small class="pqa-sum" id="sumExpert"></small></summary>
          <div class="pqa-more-b">
            <h3 class="pqa-h3">Grenzen pro Person</h3>
            <div class="pqa-rows">
              <div class="pqa-num-row"><label for="rMaxStreak"><b>Tage am Stück</b><small>danach wird die Person zunächst übersprungen</small></label><input type="number" class="pqa-num" id="rMaxStreak" value="10" min="1" max="60"></div>
              <div class="pqa-num-row"><label for="rMaxTotal"><b>Tage im gesamten Zeitraum</b><small>verhindert, dass wenige Köpfe das ganze Projekt tragen</small></label><input type="number" class="pqa-num" id="rMaxTotal" value="20" min="1" max="120"></div>
            </div>
            <div class="fg-check"><input type="checkbox" id="rWeekend" checked><label for="rWeekend">Wochenend-Freigabe beachten</label></div>

            <h3 class="pqa-h3">Bestehende Einträge</h3>
            <p class="pqa-hint">Sperrtage und Berufsschule werden grundsätzlich nie überschrieben.</p>
            <div class="pqa-radio-cards" role="radiogroup" aria-label="Umgang mit belegten Tagen">
              <label><input type="radio" name="rOverwriteR" value="none"><span><b>Nichts überschreiben</b><small>belegte Tage überspringen (empfohlen)</small></span></label>
              <label><input type="radio" name="rOverwriteR" value="assignments"><span><b>Andere Einsätze ersetzen</b><small>Urlaub, Krankheit, Sperrtage bleiben</small></span></label>
              <label><input type="radio" name="rOverwriteR" value="all"><span><b>Auch Abwesenheiten</b><small>nur mit Recht „Urlaub entsperren“</small></span></label>
            </div>
            <p class="pqa-hint" id="rOverwriteHint"></p>
            <p class="pqa-hint pqa-hint-warn">Abwesenheiten zu überschreiben heißt, in Urlaub oder Krankmeldung einzugreifen. Krankmeldungen sind
              Gesundheitsdaten – bitte nur nach Rücksprache und mit Anlass. Jede Übernahme wird protokolliert.</p>

            <h3 class="pqa-h3">Freie Kapazität</h3>
            <fieldset class="pqa-fs"><legend>Als verplanbar behandeln</legend><div class="pqa-toggles" id="fFreeAreas"></div></fieldset>
            <p class="pqa-hint">Vorausgewählt sind die Innendienst-Bereiche. Wer an einem solchen Tag steht, gilt als verfügbar und wird beim Übernehmen umgetragen. Berufsschule ist bewusst nicht wählbar.</p>
            <div class="fg-check"><input type="checkbox" id="fPreferFree" checked><label for="fPreferFree">Innendienst-Tage aktiv auflösen – wer dort steht, wird bevorzugt zum Kunden geplant</label></div>
            <div class="fg-check"><input type="checkbox" id="fRespectBond" checked><label for="fRespectBond">Kundenbindung beachten – wer fast immer beim selben Kunden ist, bleibt dort</label></div>
            <p class="pqa-hint" id="bondHint">Bevorzugt Personen, die ohnehin zwischen Kunden wechseln.</p>

            <h3 class="pqa-h3">Gewichtung</h3>
            <p class="pqa-hint">Entscheidet nur bei gleichwertigen Kandidaten, wer den Zuschlag bekommt.</p>
            <div class="pqa-w"><label for="wCont"><b>Kontinuität</b><small>möglichst dieselben Köpfe über den ganzen Einsatz</small></label>
              <input type="range" id="wCont" min="0" max="100" value="70"><output id="wContV" for="wCont">70</output></div>
            <div class="pqa-w"><label for="wFair"><b>Gleichverteilung</b><small>Last fair über das Team streuen</small></label>
              <input type="range" id="wFair" min="0" max="100" value="50"><output id="wFairV" for="wFair">50</output></div>
            <div class="pqa-w"><label for="wHome"><b>Stammbereich</b><small>wer den Kunden ohnehin betreut, kommt zuerst</small></label>
              <input type="range" id="wHome" min="0" max="100" value="40"><output id="wHomeV" for="wHome">40</output></div>
            <div class="pqa-w"><label for="wOs"><b>Qualifikation</b><small>OS-Level und Ausbildungsberuf</small></label>
              <input type="range" id="wOs" min="0" max="100" value="30"><output id="wOsV" for="wOs">30</output></div>
          </div>
        </details>
      </section>
    </div>

    <!-- ---------------- ③ Vorschlag prüfen ---------------- -->
    <div class="pqa-panel" data-panel="2" role="region" aria-label="Schritt 3: Vorschlag prüfen" hidden>
      <div id="resEmpty" class="card pqa-empty">
        <p><b>Noch kein Vorschlag.</b> Wählen Sie in Schritt 1 einen Einsatzbereich – der Vorschlag wird dann automatisch berechnet.</p>
        <button type="button" class="btn btn-primary" data-act="generate">Vorschlag berechnen</button>
      </div>
      <div id="resWrap" hidden>
        <div class="pqa-stale" id="staleBar" role="alert" hidden>
          <span><b>Die Vorgaben haben sich geändert.</b> Ihre Handänderungen bleiben erhalten, bis Sie neu berechnen.</span>
          <button type="button" class="btn btn-sm btn-primary" data-act="generate">Neu berechnen</button>
        </div>
        <div class="pqa-kpis" id="kpis"></div>
        <p class="pqa-sumline" id="epaSum"></p>
        <div class="pqa-review">
          <section class="card pqa-res" aria-label="Vorschlag">
            <div class="pqa-res-h">
              <div class="pqa-tabs" role="tablist" aria-label="Ansicht">
                <button type="button" role="tab" id="tabMatrix" aria-controls="matrix" aria-selected="true" data-view="matrix">Raster</button>
                <button type="button" role="tab" id="tabDays" aria-controls="daysView" aria-selected="false" data-view="days" tabindex="-1">Tage</button>
                <button type="button" role="tab" id="tabLoad" aria-controls="loadView" aria-selected="false" data-view="load" tabindex="-1">Auslastung</button>
              </div>
              <div class="pqa-res-a">
                <label class="pqa-switch pqa-switch-sm"><input type="checkbox" id="btnOnly" role="switch" checked><span aria-hidden="true"></span>Nur Verplante</label>
                <button type="button" class="btn btn-outline btn-xs" data-act="resort" title="Zeilen nach aktueller Auslastung neu ordnen">Sortieren</button>
                <button type="button" class="btn btn-outline btn-xs" data-act="print" title="Druckansicht öffnen – im Druckdialog lässt sich auch „Als PDF speichern“ wählen">Drucken</button>
                <button type="button" class="btn btn-outline btn-xs" data-act="generate">Neu berechnen</button>
              </div>
            </div>
            <div class="pqa-ps" id="matrix" role="tabpanel" aria-labelledby="tabMatrix"></div>
            <div id="daysView" role="tabpanel" aria-labelledby="tabDays" hidden></div>
            <div id="loadView" role="tabpanel" aria-labelledby="tabLoad" hidden></div>
            <div class="pqa-legend" id="legend"></div>
          </section>
          <aside class="card pqa-check" aria-labelledby="cfTitle">
            <div class="ch"><h2 class="pqa-h2" id="cfTitle">Prüfung</h2><span class="muted pqa-count" id="cfCount"></span></div>
            <div class="pqa-cf-filter" role="group" aria-label="Prüfpunkte filtern">
              <button type="button" class="pqa-pill is-on" data-lvl="all" aria-pressed="true">Alle</button>
              <button type="button" class="pqa-pill" data-lvl="block" aria-pressed="false">Blocker</button>
              <button type="button" class="pqa-pill" data-lvl="warn" aria-pressed="false">Warnungen</button>
              <button type="button" class="pqa-pill" data-lvl="info" aria-pressed="false">Hinweise</button>
            </div>
            <div class="cb pqa-cf-list" id="cfList"></div>
          </aside>
        </div>
      </div>
    </div>

    <!-- ---------------- ④ Übernehmen ---------------- -->
    <div class="pqa-panel" data-panel="3" role="region" aria-label="Schritt 4: Übernehmen" hidden>
      <section class="card pqa-sec pqa-apply" id="applyBox" aria-live="polite"></section>
      <section class="card pqa-sec">
        <details class="pqa-more" id="moreRecap" open>
          <summary><span class="pqa-h2">Vorgaben im Überblick</span> <small class="pqa-sum">„Ändern“ springt direkt zum Feld</small></summary>
          <div class="pqa-more-b">
            <div class="pqa-recap" id="epaRecap"></div>
            <div class="pqa-recap-note" id="epaRecapWarn"></div>
          </div>
        </details>
      </section>
    </div>
  </div>

  <!-- Live-Vorschau (Schritte 1 und 2) -->
  <aside class="pqa-live card" id="livePanel" aria-labelledby="liveTitle">
    <div class="ch"><h2 class="pqa-h2" id="liveTitle">Live-Vorschau</h2><span class="pqa-live-st" id="liveState" aria-live="polite"></span></div>
    <div class="cb" id="liveBody"><p class="muted">Sobald Einsatzbereich und Zeitraum stehen, erscheint hier der Vorschlag.</p></div>
  </aside>
  </div>

  <footer class="pqa-foot">
    <span class="pqa-foot-h" id="footHint"></span>
    <button type="button" class="btn btn-outline" id="btnBack">Zurück</button>
    <button type="button" class="btn btn-primary" id="btnNext">Weiter</button>
  </footer>
</section>

<!-- ---------------- Dialoge (Bauweise der App) ---------------- -->
<div class="modal-bg" id="ovAlt"><div class="modal modal-lg">
  <div class="mh"><h3 id="altTitle">Personen</h3><button type="button" class="mx" data-close="ovAlt" aria-label="Schließen">&times;</button></div>
  <div class="mb" id="altBody"></div>
  <div class="mf"><button type="button" class="btn btn-outline" data-close="ovAlt">Schließen</button></div>
</div></div>

<div class="modal-bg" id="ovProf"><div class="modal modal-lg">
  <div class="mh"><h3>Vorlagen</h3><button type="button" class="mx" data-close="ovProf" aria-label="Schließen">&times;</button></div>
  <div class="mb" id="profBody"></div>
  <div class="mf"><button type="button" class="btn btn-outline" data-act="save-profile">Aktuelle Vorgaben als Vorlage sichern</button>
    <span class="pqa-flex"></span><button type="button" class="btn btn-outline" data-close="ovProf">Schließen</button></div>
</div></div>

<div class="modal-bg" id="ovSave"><div class="modal">
  <div class="mh"><h3 id="spTitle">Sichern</h3><button type="button" class="mx" data-close="ovSave" aria-label="Schließen">&times;</button></div>
  <div class="mb">
    <div class="fg"><label for="spName">Name</label><input type="text" id="spName" maxlength="150" placeholder="z. B. Rollout Kunde Nord – Phase 2"></div>
    <div class="fg" id="spDescRow"><label for="spDesc">Kurzbeschreibung (optional)</label><input type="text" id="spDesc" maxlength="255"></div>
    <div class="fg-check" id="spUpdRow" hidden><input type="checkbox" id="spUpdate"><label for="spUpdate" id="spUpdateLbl">Bestehende Vorlage aktualisieren</label></div>
    <div class="fg-check"><input type="checkbox" id="spShared" checked><label for="spShared">Für andere Planende freigeben</label></div>
    <small class="muted" id="spHint"></small>
  </div>
  <div class="mf"><button type="button" class="btn btn-outline" data-close="ovSave">Abbrechen</button><button type="button" class="btn btn-primary" id="spSaveBtn">Sichern</button></div>
</div></div>

<div class="modal-bg" id="ovAsk"><div class="modal">
  <div class="mh"><h3 id="askTitle">Bitte bestätigen</h3><button type="button" class="mx" data-close="ovAsk" aria-label="Schließen">&times;</button></div>
  <div class="mb" id="askBody"></div>
  <div class="mf"><button type="button" class="btn btn-outline" id="askNo">Abbrechen</button><button type="button" class="btn btn-primary" id="askYes">OK</button></div>
</div></div>

<div id="epaCtx" class="ctx-menu pqa-ctx" role="menu" aria-label="Aktionen"></div>
<div class="pqa-busy" id="busy" role="alert" aria-busy="true" hidden><div class="pqa-spin" aria-hidden="true"></div><p id="busyTxt">Vorschlag wird berechnet …</p></div>
</div><!-- /pqa -->

<div id="epaPrint"></div>

<script defer src="<?php echo e($EPA_BP); ?>assets/js/assistant-core.js?v=<?php echo $v('js/assistant-core.js'); ?>"></script>
<script defer src="<?php echo e($EPA_BP); ?>assets/js/assistant.js?v=<?php echo $v('js/assistant.js'); ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
