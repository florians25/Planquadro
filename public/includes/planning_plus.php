<?php
/**
 * Planungshilfen: Konfiguration für public/assets/js/planning-plus.js
 * Einbinden in Monats- und Gesamtplan nach der Tabelle:
 *   $pqPlusPage = 'monatsplanung'; require __DIR__ . '/../includes/planning_plus.php';
 * Ausgabe ist ein verstecktes Element mit JSON im data-Attribut (kein Inline-Skript, CSP-tauglich).
 * Enthält nur Stammdaten (Bereiche, Soll-Besetzung) und Schalter – keine Personendaten.
 */
require_once __DIR__ . '/../../app/lib/PlanningPlus.php';
$__pqPage = isset($pqPlusPage) ? (string)$pqPlusPage : 'monatsplanung';
$__pqEdit = $__pqPage === 'monatsplanung' && canEdit();
$__pqCfg = [
    'page'      => $__pqPage,
    'year'      => isset($y) ? (int)$y : (int)date('Y'),
    'month'     => isset($m) ? (int)$m : (int)date('n'),
    'edit'      => $__pqEdit,
    'coverage'  => (bool)tcfg('planning.coverage_row', true),
    'history'   => $__pqEdit && (bool)tcfg('planning.cell_history', true),
    'copy'      => $__pqEdit && (bool)tcfg('planning.period_copy', true),
    'undo'      => $__pqEdit && (bool)tcfg('planning.undo', true),
    'targetsEdit' => $__pqEdit && canManageConfig(),
    'targets'   => (object)pqTargetsAll(getDB()),
    'areas'     => array_values(array_map(function ($id, $a) { return ['id' => $id] + $a; }, array_keys($__pqAreas = pqAreaInfo(getDB())), $__pqAreas)),
    'tenant'    => (string)tcfg('branding.name', 'Planquadro'),
    'today'     => date('Y-m-d'),
];
?>
<div id="pqPlusCfg" hidden data-cfg="<?php echo htmlspecialchars(json_encode($__pqCfg, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"></div>
