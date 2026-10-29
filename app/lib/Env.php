<?php
/**
 * Vireo – Kompatibilität der Umgebungsvariablen
 * --------------------------------------------------------------------------
 * Vireo hieß in der Entwicklungsphase "OpsPilot". Variablen mit dem alten
 * Präfix OPSPILOT_* werden weiter akzeptiert: Ist VIREO_X nicht gesetzt,
 * gilt OPSPILOT_X. Gesetzte VIREO_*-Variablen haben immer Vorrang.
 * Berücksichtigt Prozess-Umgebung und Webserver-Variablen (SetEnv).
 */
(function (): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $legacy = [];
    foreach ((array)getenv() as $k => $v) if (strncmp((string)$k, 'OPSPILOT_', 9) === 0) $legacy[$k] = (string)$v;
    foreach ($_SERVER as $k => $v) if (is_string($k) && strncmp($k, 'OPSPILOT_', 9) === 0 && is_scalar($v) && !isset($legacy[$k])) $legacy[$k] = (string)$v;
    foreach ($legacy as $k => $v) {
        $new = 'VIREO_' . substr($k, 9);
        if (getenv($new) === false && !isset($_SERVER[$new])) putenv($new . '=' . $v);
    }
})();
