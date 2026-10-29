-- Migration v6.2.3
-- Changelog-Eintrag für v6.2.3

DELETE FROM changelog WHERE version IN ('6.2.1','6.2.3');

INSERT INTO changelog (version, release_date, content, entry_type) VALUES
('6.2.3', '2026-04-06',
'Abmelde-Hinweis\n- Grüne Erfolgsmeldung nach Logout auf der Login-Seite\n- Automatisches Ausblenden nach 4 Sekunden\n\nKalender-Logo\n- EP-Text durch SVG-Kalender-Icon ersetzt (Login + Navbar)\n\nJahr veröffentlichen\n- Button in der Monatsplanung für Admins/Manager wiederhergestellt\n- Nutzt bestehende plan_publish_year-Berechtigung\n\nModals überarbeitet\n- Bestätigungs-Dialog mit rundem Warn-Icon, breitere Buttons\n- Eingabe-Dialog mit Icon im Header\n- Verlassen-Modal mit konsistentem Icon-Kreis\n- CSS-Klassen für Icon-Varianten (warn, danger, info, success)\n- Sanftere Animation mit Scale-Effekt\n\nToast-Benachrichtigungen\n- Farbiger Akzentstreifen links, breitere Karten, dezenter Schatten\n\nMein Plan – Kalenderansicht\n- Tage außerhalb des Monats mit Streifenmuster\n- Feiertage: roter Randstreifen, rote Tageszahl\n- Ferien: blauer Randstreifen, blaue Tageszahl\n- Ferien-Name unten rechts bei vorhandenem Einsatz\n- Heute-Button unter dem Monatsnamen\n\nMein Plan – Listenansicht\n- Überarbeitetes Design mit KW-Trennlinien und Hover\n\nDashboard – Meine Woche\n- Farbiger Akzentstreifen, Heute-Badge\n- Feiertage/Ferien mit vollen Namen\n- Bei Ferien: Einsatz + Ferien-Untertitel\n\nDashboard – Kommende Feiertage\n- Zeigt nur noch echte Feiertage, keine Schulferien\n\nGesamtplan\n- Klick ohne Werkzeug zeigt keine Meldung mehr\n- Toast aus app.js für Gesamtplan-Kontext entfernt\n\nVersionsdaten auf v6.2.3 aktualisiert',
'feature');
