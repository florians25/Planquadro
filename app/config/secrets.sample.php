<?php
/**
 * Optionale Datei für DB-Zugangsdaten (Alternative zu Umgebungsvariablen).
 * --------------------------------------------------------------------------
 *   1. Diese Datei nach "secrets.php" kopieren (im selben Ordner app/config/).
 *   2. Werte eintragen. secrets.php ist per .gitignore vom Repository
 *      ausgeschlossen und darf niemals committet werden.
 * Umgebungsvariablen (OPSPILOT_DB_HOST/PORT/NAME/USER/PASS) haben Vorrang.
 */
return [
    'host' => '',
    'port' => '3306',
    'name' => '',
    'user' => '',
    'pass' => '',
];
