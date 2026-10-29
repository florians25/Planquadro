# Planquadro Einsatzplanung – Changelog

*Copyright © 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten.*

## Planquadro 7.0.0-dev – Phasen 3–7 „Plattform, Oberfläche, Apps, QS“ (unveröffentlicht)

Alle bisherigen Funktionen, Daten, Rechte und Abläufe bleiben erhalten. Neue Funktionen
sind optional oder standardmäßig so eingestellt, dass sich für bestehende Installationen
nichts ändert.

### Marke
- Neuer Produktname **Planquadro** (Kurzname PQ) mit eigenem Corporate Design
  (`docs/BRAND.md`): Bildmarke „Planquadrat“, Wortmarke, Farben Tinte, Quadro-Blau und
  Signal-Orange, Favicons, PWA- und App-Icons, Splash-Screens.
- Die bisherige Standardfarbe (Rot) ist durch Quadro-Blau ersetzt. Mandanten mit eigenem
  Branding behalten ihre Farben; wer das frühere Rot weiter möchte, trägt es unter
  *Organisation → Branding* ein.

### Plattform (Phase 3)
- **Mandantenfähigkeit** (`PQ_MODE=saas`): eine Datenbank je Mandant, Auflösung per
  Domain, mandantengebundene Sitzungen. Self-Hosting (Standard) bleibt eine Einzel-Installation.
  `bin/tenant.php` legt Mandanten an oder übernimmt eine bestehende Installation
  unverändert (`adopt`). Plattform-Administration unter `/platform/`.
- **Organisation** (neue Seite): Branding (Name, Farben mit automatischer Kontrastprüfung,
  Logo), Module ein-/ausschalten, Reihenfolge und Sichtbarkeit der Navigation,
  Dashboard-Widgets, Planungsregeln. Bisherige Einstellungen werden übernommen.
- **Stammdaten**: Kunden, Standorte, Qualifikationen; Einsatzcodes optional verknüpfbar.
- **Anmeldung per Firmenkonto (OpenID Connect)**, optional. Keine automatische
  Kontoanlage; Administratoren behalten den Passwort-Zugang als Notfallweg.
- Neues Recht „Abwesenheitsdetails aller sehen“ (Personalabteilung); Vorgesetzte sehen
  Details nur noch für ihr Team (Entscheidung E-2).

### API und Push
- REST-API v1 für die Apps (`docs/API.md`): Token-Anmeldung mit rotierenden
  Refresh-Tokens, persönlicher Plan, Team, Abwesenheiten, Anträge, Einsatzdetails,
  Synchronisation.
- Push bei veröffentlichten Planänderungen (FCM, APNs, ntfy für Self-Hosting) über eine
  Warteschlange und `bin/push-worker.php`.

### Betrieb, Backup, Recovery (Phase 4)
- **Docker:** Image (Apache, läuft als www-data), `docker-compose.yml` für Self-Hosting,
  `docker-compose.saas.yml`, optional Caddy für HTTPS; Healthchecks `/api/healthz` und
  `/api/readyz`. Eine Bestands-DB ohne Versionstabelle wird nie automatisch verändert.
- **Neues Backup-System** (`bin/backup.php`, `bin/scheduler.php`): konsistente Dumps,
  authentisierte Verschlüsselung mit öffentlichem Schlüssel (der private Schlüssel muss
  nicht auf dem Server liegen), Prüfsummen-Manifest, Ziele lokal, S3 und rclone,
  Aufbewahrung täglich/wöchentlich/monatlich, je Mandant, automatischer Restore-Test,
  Warnungen per Webhook/Mail und im Dashboard.
- Wiederherstellung nur in eine neue/leere Datenbank oder mit Sicherheits-Backup,
  Staging und atomarem Tausch; bricht beim ersten Fehler ab.
- Der alte Backup-Bereich zeigt Status, erstellt und lädt herunter; Cron-URL mit
  Schlüssel, Schlüsselanzeige und Restore im Browser entfallen (Sicherheitsbefunde B-01 … B-05).
  Alte Backups lassen sich mit `bin/backup.php legacy-decrypt` weiter öffnen.
- Doku: `INSTALLATION`, `CONFIGURATION`, `UPDATE`, `BACKUP`, `DISASTER_RECOVERY`.

### Oberfläche (Phase 5)
- Planquadro-Designsystem mit Dark Mode (folgt dem System, umschaltbar), besseren
  Kontrasten, sichtbarem Fokus, Skip-Link und zugänglichen Dialogen.
- Planungsmatrix per Tastatur bedienbar; Filter „Nur Änderungen“ für Entwürfe.
- Große Pläne laden deutlich schneller (Monatsplan im Test: 856 → 68 Abfragen).

### Mobile-Apps (Phase 6)
- iOS-, Android- und Web-App (`mobile/`, Capacitor): persönlicher Plan, Team,
  Abwesenheiten und Anträge, Einsatzdetails mit Kundeninfos, Push, Offline-Lesemodus,
  Biometrie, Dark Mode, Mandanten-Branding. Noch nicht in den Stores veröffentlicht.

### Sicherheit und Qualität (Phase 7)
- Sicherheitsreview der neuen Teile, alle Befunde behoben (Roadmap R-01 … R-10), u. a.:
  SSO-Einrichtung nur durch Administratoren, keine Abwesenheitsarten in der
  Konfliktvorschau ohne Detailrecht, Passwortwechsel beendet App-Sitzungen,
  SSO funktioniert mit strikten Sitzungs-Cookies.
- CI: Tests auf MariaDB und MySQL, Mobile-Tests, Secret-Scan, Abhängigkeits-Audit,
  OWASP-ZAP-Baseline.

### Hinweis zum Update
`php bin/migrate.php up` spielt die Migrationen 0004 (Stammdaten) und 0005 (API/Push) ein.
Neue Umgebungsvariablen sind optional (`docs/ARCHITECTURE.md`, `docs/API.md`).

## Planquadro 7.0.0-dev – Phase 2 „Stabilisierung“ (unveröffentlicht)

Basis ist Einsatzplanung v6.9.0. Alle Funktionen und Abläufe bleiben erhalten; die
Änderungen beheben Fehler und sichern den Mehrbenutzerbetrieb ab. Details und Befund-IDs:
`docs/ROADMAP.md`.

### Gleichzeitiges Arbeiten
- **Keine stillen Überschreibungen mehr.** Hat jemand anderes eine Zelle geändert, seit
  sie auf dem eigenen Bildschirm geladen wurde, wird nicht gespeichert. Ein Dialog nennt
  Zelle, aktuellen Stand, wer und wann – und lässt bewusst überschreiben oder den
  aktuellen Stand übernehmen.
- Veröffentlichen sperrt die betroffenen Entwürfe; parallel gespeicherte Änderungen gehen
  nicht mehr verloren.
- **Änderungsprotokoll je Zelle** (`plan_change_log`): Entwurf, Überschreiben,
  Veröffentlichen, Antragsgenehmigung, Abwesenheitsmodul – mit Benutzer und Zeitpunkt.

### Sicherheit
- Zentraler CSRF-Schutz für alle Speichervorgänge, neue Session-ID beim Login,
  Session-Cookie überall mit HttpOnly/SameSite.
- Benutzerverwaltung: Die Rolle Administrator und Administrator-Konten können nur noch
  von Administratoren geändert werden; die eigene Rolle ist nicht änderbar.
- Die Teamseite enthält keine Passwort-Hashes, Einmalpasswörter oder Kalender-Tokens
  mehr; Einmalpasswörter werden nicht mehr im Klartext gespeichert.
- `install.php` ist gesperrt (nur per CLI oder mit `PQ_ALLOW_INSTALL=1`) und
  erzeugt ein Zufallspasswort.
- Schutz vor eingeschleustem Skript über Antragsbemerkungen, WOT-Links/-Farben und
  Bereichsfarben.
- Team-Bereich wird jetzt auch bei WOTs, Stunden/Erl. und Wochenkontext geprüft;
  Anträge genehmigen erfordert das Recht „Anträge genehmigen“.
- Vertrauliche Abwesenheitsarten werden im Abwesenheitsmodul nicht mehr übertragen.
- DB-Zugangsdaten nur noch über Umgebung oder `app/config/secrets.php`.

### Datenintegrität
- Abwesenheitsmodul respektiert die Sperre für genehmigten Urlaub und Krankheit,
  prüft Datumsangaben und ersetzt keinen anderen Einsatz der Vertretung.
- Einsatzbereiche mit Einsätzen können nicht mehr gelöscht werden (vorher wurde die
  gesamte Einsatzhistorie mitgelöscht).
- „Rückgängig“ im Planungsassistenten löscht keine später manuell geänderten Einträge.

### Betrieb und Entwicklung
- `bin/migrate.php` mit Versionstabelle; bestehende Installationen einmalig per
  `baseline` übernehmen (siehe `docs/MIGRATION_PLAN.md`). Läuft auf MariaDB und MySQL 8.
- Keine Schemaänderungen mehr zur Laufzeit.
- Automatisierte Tests (Unit, Integration über HTTP, Nebenläufigkeit) und CI.
- Rechte „Abwesenheiten erfassen“ und „Planungsassistent nutzen“ sind auf der Seite
  „Rollen & Rechte“ pflegbar.

### Hinweis zum Update
Vor dem Einspielen: Backup, DB-Passwort rotieren, Zugangsdaten auslagern,
`php bin/migrate.php baseline v6_9_0 && php bin/migrate.php up`.

## v6.9.0 (24.07.2026)

### Planungsassistent: Planungen speichern und wieder aufnehmen
- **Neue Startseite.** Der Assistent beginnt jetzt mit einer Übersicht der gespeicherten Planungen statt direkt im Formular. Jede Kachel zeigt Kunde, Zeitraum, Abdeckung als Balken, offene Punkte und wann zuletzt geändert wurde. Daneben die Schaltfläche „Neue Planung anlegen“.
- **Speichern und Weiterbearbeiten**: Eine Entwurfsplanung lässt sich unter einem Namen sichern und später an derselben Stelle fortsetzen – auch über Tage hinweg und bevor sie in die Monatsplanung übernommen wird.
- Beim Öffnen wird der gespeicherte Stand **frisch gegen die Datenbank geprüft**: Ist zwischenzeitlich Urlaub dazugekommen oder ein Einsatz entstanden, erscheinen die betroffenen Stellen sofort in der Prüfliste. Die gespeicherte Besetzung bleibt dabei erhalten.
- Planungen können für andere Planende **freigegeben** werden; löschen darf sie nur, wer sie angelegt hat. Nach einer Übernahme wird der gespeicherte Stand automatisch aktualisiert.

### Automatik gründlicher
- Die Besetzung läuft jetzt in **drei Durchgängen**: reguläre Kandidaten, dann die an Belastungsgrenzen zurückgestellten (mit Hinweis), zuletzt ein gezielter Durchgang für Restlücken, der Fachkräfte vor Azubis zieht. Damit bleibt weniger Handarbeit übrig.

### Neues Modul: Abwesenheiten
Für Manager und Teamleitung, erreichbar über die Hauptnavigation (Berechtigung `manage_absences`).
- **Zeitraum statt Einzeltage**: Urlaub, Krankheit, Gleitzeit oder Sperrtage über einen Zeitraum erfassen, wahlweise ohne Wochenenden und ohne Feiertage. Eine Vorschau nennt vorab die Zahl der betroffenen Tage.
- **Vorschau vor dem Erfassen**: Ein Dialog zeigt zuerst, welche Einsätze im Zeitraum konkret wegfallen – mit Datum, Kürzel und Bereichsfarbe. Sind keine geplant, wird das ausdrücklich gesagt.
- **Automatische Vertretungsvorschläge**: Im selben Dialog stehen bis zu acht Kandidaten, sortiert nach Eignung. Bewertet werden Verfügbarkeit an den betroffenen Tagen, Zugehörigkeit zum Bereich und frühere Einsätze dort. Jede Zeile begründet sich selbst („betreut den Bereich · 4× dort im Einsatz · an allen Tagen frei“ bzw. „2 von 3 Tagen frei · 1× anderweitig verplant“). Wer an keinem Tag kann, ist deaktiviert. „Ersatzlos“ steht an erster Stelle und bleibt vorausgewählt.
- **Vertretung oder ersatzlos.** Ohne Angabe entfallen die Einsätze des Zeitraums ersatzlos – das ist der Standard und der Regelfall. Wird eine Vertretung gewählt, übernimmt sie die betroffenen Einsätze automatisch; ist sie an einem Tag selbst abwesend, wird dieser Tag übersprungen und gemeldet.
- **Übersicht nach Zeiträumen** statt Einzeltagen, mit Überbrückung von Wochenenden – ein Zweiwochenurlaub erscheint als ein Eintrag, nicht als zwei. Abwesenheitsgründe folgen denselben Sichtbarkeitsregeln wie die Monatsplanung.
- Alle Änderungen laufen über die vorhandenen Tabellen und erscheinen unmittelbar in Monatsplanung und Planungsassistent. Jede Erfassung wird protokolliert.

### Behoben
- **Schulferien wurden als Feiertage behandelt.** Die Tabelle `holidays` führt gesetzliche Feiertage *und* Schulferien; das Modul filterte nicht nach `holiday_type`. In den Sommerferien wurde dadurch jeder Tag übersprungen – der Dialog meldete „0 Tage" und fand folgerichtig auch keine Einsätze, obwohl in der Monatsplanung welche standen. Es zählen jetzt nur noch Einträge mit `holiday_type = 'feiertag'`; Ferien sind normale Arbeitstage. Die Beschriftung im Dialog heißt entsprechend „Gesetzliche Feiertage auslassen".
- **Einsätze werden jetzt aus derselben Quelle gelesen wie die Monatsplanung.** Das Modul stellte die betroffenen Einsätze über eigene Abfragen zusammen und wich dadurch von dem ab, was der Planer auf dem Bildschirm sieht – etwa bei einem Entwurf, der einen Einsatz löscht. Es nutzt jetzt die App-eigene Funktion `assignments($jahr, $monat, false)`, also veröffentlichte Einsätze mit darübergelegten Entwürfen. Damit ist die Liste im Dialog garantiert identisch mit der Monatsplanung.
- **„pubHint is not defined“** im Erfassungsdialog: Beim Umbau auf zwei Schritte wurde die Funktion durch ein zu weit greifendes Suchmuster gelöscht, der Aufruf blieb stehen. Wiederhergestellt.
- **Zerschossenes Layout der Modulseite**: Beim Kürzen des Formulars ging ein schließendes `</div>` verloren, wodurch die Übersicht innerhalb der Erfassungskarte landete und beide Karten in der schmalen linken Spalte klebten. Korrigiert.
- **Abwesenheiten kamen nicht sauber in der Monatsplanung an.** Die Anwendung führt je Tag entweder einen Einsatz oder eine Abwesenheit, und Einträge aus `plan_drafts` legen sich über beides. Das Modul schrieb die Abwesenheit zwar korrekt, räumte den Tag aber nicht: Ein vorhandener Entwurf überlagerte sie weiterhin, sodass in der Monatsplanung unverändert der alte Stand stand. Beim Veröffentlichen wird der Tag jetzt genauso geräumt wie in `api/publish.php` – Einsatz, alte Abwesenheit und Entwurf – bevor der neue Eintrag gesetzt wird. Dasselbe gilt für die Vertretung.
- **Neu: „Sofort veröffentlichen“** (standardmäßig an). Die Abwesenheit steht damit unmittelbar in der Monatsplanung und wirkt sofort im Planungsassistenten. Als **Ausnahme** lässt sich der Haken entfernen: Dann entsteht ein Entwurf in `plan_drafts`, der in der Monatsplanung als solcher erscheint und dort über den gewohnten Weg veröffentlicht wird – samt Vertretungseinsatz.
- **Vertretung tagesweise.** Der Dialog zeigt oben Kacheln mit allen infrage kommenden Personen – Kürzel, Begründung, ein Balken für die Verfügbarkeit und „x von y Tagen frei“; die beste trägt die Marke „Empfehlung“. Ein Klick setzt sie für alle Tage ein, an denen sie kann. Darunter steht jeder betroffene Tag in einer Tabelle mit eigener Auswahl, sodass sich einzelne Tage abweichend besetzen oder ersatzlos lassen. Übernommene Zeilen sind grün hinterlegt, die Fußzeile zählt mit („2 von 3 Einsätzen übernommen“). Personen, die an einem Tag nicht können, sind dort nicht wählbar.
- **Automatischer Vermerk**: Am Einsatz der Vertretung steht „Vertretung für <Name>“ – in der Monatsplanung direkt am Eintrag sichtbar.
- Der Dialog ist deutlich breiter (bis 940 px) und scrollt bei vielen Tagen innerhalb des Inhaltsbereichs.
- **Erfassung in zwei Schritten.** Das Formular fragt nur noch nach Mitarbeiter, Art und Zeitraum. Alles Weitere steht im Dialog: welche Einsätze im Zeitraum wegfallen (mit Kennzeichnung, ob veröffentlicht, unveröffentlicht oder Entwurf), die Vertretungsvorschläge, Wochenend- und Feiertagsbehandlung, Bemerkung und die Veröffentlichung. Wochenende und Feiertage lassen sich im Dialog umschalten – die Liste der betroffenen Einsätze aktualisiert sich sofort.
- Die Übersicht im Modul zeigt jetzt auch nicht veröffentlichte Abwesenheiten, gekennzeichnet mit „Entwurf“. Beim Entfernen werden Entwürfe mit gelöscht.
- Neue Schaltfläche **„Abwesenheiten“** in der Werkzeugleiste der Monatsplanung.
- **„Unerwartete Antwort des Servers“ beim Neuberechnen.** In der Besetzungsschleife hieß meine neue Liste der zurückgestellten Kandidaten `$reserve` – so wie die bereits vorhandene Variable für die reservierten Ausbildungsplätze, die eine Zahl enthält. Sobald ein Tag unterbesetzt blieb, traf der dritte Durchgang auf eine Zahl statt auf eine Liste und brach mit einem schweren Fehler ab; der Browser bekam eine leere Antwort. Bei kurzen Zeiträumen fiel das nicht auf, weil dort alle Plätze besetzt wurden. Die Variable heißt jetzt `$limited_pool`.
- **Fehler werden nicht mehr verschluckt**: Auch schwere Fehler liefern jetzt eine lesbare JSON-Antwort mit Fundstelle statt einer leeren Seite. Die Fehlerbehandlung fängt zusätzlich `Throwable` statt nur `Exception`.
- **Abwesenheiten erkannten Einsätze unvollständig.** Gesucht wurde nur nach veröffentlichten Einträgen in `assignments`. Noch nicht veröffentlichte Einsätze und Entwürfe aus `plan_drafts` blieben unberücksichtigt – sie fielen weder weg noch gingen sie an die Vertretung. Berücksichtigt werden jetzt alle drei Quellen, wobei Entwürfe als aktuellerer Stand über den Einträgen liegen.

### Navigation und Werkzeugleiste
- **Neue Reihenfolge in der Navigation**: Dashboard, Mein Plan, Gesamtplan, Planung, Assistent, Jahresplan, Urlaub, Abwesenheiten, Vertretung, Anträge, Team, Bereiche, Feiertage, Einstellungen, Rollen.
- **Kleine Bildschirme**: Ab 1400 px werden die Beschriftungen kleiner, ab 1150 px zeigt die Navigation nur noch Symbole – so bleibt sie vollständig sichtbar, statt seitlich hinauszuragen. Die Werkzeugleiste der Monatsplanung wird ab 1100 px kompakter und scrollt ab 780 px waagerecht in sich, statt die Seite zu verbreitern. Erst unter 480 px reduziert sich der Assistenten-Knopf auf sein Symbol.
- Der Knopf **„Abwesenheiten“** in der Werkzeugleiste ist entfallen – die Seite steht in der Navigation.
- Der Knopf **„Assistent“** trägt seine Beschriftung wieder (sie war durch die Beta-Marke verdrängt worden).

### Zusammenspiel mit dem Vertretungsmodul
- Vertretungen erweiterten in der Anwendung bislang nur den **Sichtbereich**, nicht die Rechte. Wer eine Teamleitung mit Abwesenheitsverwaltung vertritt, konnte deren Team zwar sehen, das Modul aber nicht öffnen. Jetzt gilt: Wer jemanden vertritt, der Abwesenheiten verwalten darf, darf es während der Vertretung ebenfalls. Ein Hinweis oben auf der Seite nennt die vertretene Person; jede Erfassung wird mit dem eigenen Namen protokolliert.

### Abwesenheitsmodul
- **Mitarbeitersuche** statt Auswahlliste: Suche über Name, Bereich und Rolle, jede Zeile mit Namenskürzel, Rolle und dem Stammbereich als farbiges Abzeichen.
- **Ausgeschiedene Mitarbeiter erscheinen nicht mehr** – wer ein Austrittsdatum in der Vergangenheit hat, wird ausgeblendet.

### Kleinigkeiten
- **Doppelte Überschrift entfernt**: Die Kopfzeile des Assistenten (Titel, Zeitraum, Schaltflächen) erschien auch auf der neuen Startseite, sodass „Planungsassistent“ zweimal untereinander stand. Sie gehört jetzt in den Assistenten. Der Einleitungstext auf der Startseite und der Beta-Hinweis wurden gekürzt.

### Hinweis zur Einspielung
`migrations/migrate_v6_9_0.sql` setzt `migrate_v6_8_0.sql` voraus und ist mehrfach ausführbar.

## v6.8.5 (24.07.2026)

### Planungsassistent – Verbesserungen

- **Entwurfsmatrix zeigt standardmäßig nur Verplante.** Über „Alle anzeigen“ kommt der komplette Kandidatenkreis zurück.
- **Schmalere Mitarbeiterspalte** (148 px statt 190 px, ab sehr breiten Bildschirmen 176 px). Der Vorname wird abgekürzt („Mustermann, M.“), der vollständige Name steht im Tooltip.
- **Rechtsklick auf eine Person in der Entwurfsansicht** öffnet ein Menü: diesen Tag entfernen oder einplanen, passende Personen für den Tag anzeigen, die Person komplett aus dem Vorschlag nehmen, ganz ausschließen oder an allen möglichen Tagen fest einplanen.
- **Legende zeigt nur noch, was tatsächlich zu sehen ist.** Sie wird aus der gezeichneten Matrix abgeleitet – mit den echten Bereichsfarben und Kürzeln statt allgemeiner Platzhalter. Kategorien ohne Vorkommen entfallen.

- **Zusammenfassung als eigener Schritt** (Schritt 5, vor dem Entwurf): alle Vorgaben in einer Tabelle – Einsatz, Zeitraum, Bedarf, Qualifikation, Stammbereich, Kandidatenkreis, Azubi-Regeln, Grenzen, Umgang mit belegten Tagen und Umplanung. Widersprüchliche oder unvollständige Angaben sind gelb markiert und werden darunter im Klartext benannt.
- **„Ändern“ springt direkt zum Feld**: Jede Zeile hat eine Schaltfläche, die zum passenden Schritt wechselt, das betroffene Feld in den Blick rückt, kurz hervorhebt und den Fokus setzt.
- **Drucken behoben.** Der Druckbehälter lag im Inhaltsbereich und wurde von der eigenen Druckregel mit ausgeblendet – die Ausgabe blieb leer. Er wird jetzt vor dem Druck an das Seitenende verschoben; zusätzlich werden Navigation, Fußzeile, Meldungen und Dialoge sicher ausgeblendet.
- **Entwurfsansicht weiter geschärft**: Zähler an den Filtern der Prüfliste (leere Kategorien sind deaktiviert), Tagesansicht nach Kalenderwochen gruppiert mit Filter „Nur Tage mit offenen Plätzen“ und Angabe, wie viele Tage unvollständig sind. In der Namensspalte kennzeichnen ein „S“ den Stammbereich und ein Abzeichen das Lehrjahr.
- **Copyright** in der Fußzeile der Anwendung ergänzt.

### Teamübergreifend planen
- Neue Freigabe **„Teamleiter dürfen teamübergreifend planen“** (Einstellungen → Planung, standardmäßig aus). Ist sie gesetzt, erscheint im Assistenten ein Schalter, mit dem Teamleiter zusätzlich Mitarbeitende anderer Teams einbeziehen können – mit deutlichem Hinweis, sich vorher abzustimmen. Administratoren und Manager konnten das ohnehin.
- **Wichtig dabei:** Die Sichtbarkeit von Abwesenheitsgründen bleibt unberührt. Ein Teamleiter, der teamübergreifend plant, sieht bei fremden Mitarbeitenden nur „Abwesend“, im eigenen Team weiterhin den Grund.
- Teamübergreifende Übernahmen werden im Protokoll als solche gekennzeichnet, und die Zusammenfassung weist den Umfang gelb hervor.

### Ausdruck der Entwurfsplanung
- **Die Matrix ist wieder das Herzstück**: eigene Kopfzeile mit Kalenderwochen über den Tagen, kräftige Linien an den Wochengrenzen, Tag und Wochentag zweizeilig, Wochenenden und Tage ohne Bedarf grau hinterlegt. Geplante Einsätze erscheinen in der echten Bereichsfarbe (mit `print-color-adjust`, damit der Browser sie nicht wegoptimiert), fremde Einsätze mit ihrem Kürzel in Grau, Abwesenheiten als schlichtes „A“, Berufsschule als „BS“, Sperrtage als „—“. Rechts eine Summenspalte je Person, unten die Zeile Besetzung/Bedarf mit hervorgehobenen Lücken, darunter eine kompakte Legende.
- **Passt sich der Länge an**: Ab 30 bzw. 45 Tagen wird die Matrix automatisch kleiner gesetzt, statt umzubrechen. Die Einsatzliste beginnt immer auf einer neuen Seite.
- **Behoben – Matrix im Ausdruck war winzig, zweigeteilt und farblos**: Die Drucktabelle trug die Klasse `mx`, die im Stylesheet der Anwendung bereits vergeben ist – für den Schließen-Knopf der Dialoge (`display:flex; width:32px; background:none`). Die Tabelle wurde dadurch zu einem 32 Pixel breiten Flex-Kasten, Kopf und Körper standen nebeneinander, und die Bereichsfarben verschwanden. Sämtliche Klassen der Druckausgabe tragen jetzt das Präfix `epa-` und können nicht mehr mit dem Stylesheet kollidieren.
- **Behoben – Ausdruck nutzte die Seite nicht aus**: Die Kopfdaten standen als schmaler Block am linken Rand statt über die Breite, und die Matrix war unnötig klein gesetzt. Kopftabelle und Matrix spannen jetzt über die volle Seitenbreite, die Seitenränder sind auf 8 mm reduziert, Zellen und Beschriftungen deutlich größer (9 pt statt 7,5 pt, 20 px statt 15 px Zeilenhöhe), die Namensspalte auf 42 mm verbreitert.
- **Kopfzeile als Tabelle**: Kunde, Zeitraum mit Zahl der Einsatztage, Abdeckung, Beteiligte und Stand nebeneinander, dazu die Bemerkung und ein Hinweis, falls teamübergreifend geplant wurde.
- **Neue Einsatzliste**: Tag für Tag mit Soll/Ist und ausgeschriebenen Namen, nach Kalenderwochen gegliedert, unbesetzte Tage grau hinterlegt. Das ist die Fassung, die man tatsächlich weitergibt – die Matrix eignet sich zum Überblick, nicht zum Ablesen.
- **Einsatztage je Person** mit Zeitraum von–bis für Rückfragen und Abstimmung.
- **Sauberer Seitenumbruch**: Der Tabellenkopf wiederholt sich auf jeder Seite, Zeilen werden nicht mehr zerrissen, die Einsatzliste beginnt auf einer neuen Seite.

### Datenschutz
- **Abwesenheitsgründe nach denselben Regeln wie die Monatsplanung.** Der Assistent nutzt jetzt die vorhandenen Funktionen `canSeeAbsenceDetails()` und `isConfidentialAbsence()`: Administratoren sehen Urlaub und Krankmeldung immer, Manager und Teamleiter für ihr eigenes Team, alle übrigen nur „Abwesend“. Das gilt in der Matrix, der Prüfliste, den Vorschlägen und allen Dialogen. Krank- und Urlaubsdaten gelten dabei als vertraulich (Art. 9 DSGVO).
- **Ausdrucke enthalten nie Abwesenheitsgründe.** Der Ausdruck verlässt das System und wird weitergereicht – er führt deshalb nur tagesbezogene Befunde auf (Unterdeckung, Betreuungsschlüssel). Personenbezogene Hinweise werden lediglich gezählt, mit Verweis auf die Prüfliste im Assistenten. Die Fußzeile weist auf den vertraulichen Charakter hin.
- **Warnhinweis beim Überschreiben**: Wer „Einsätze und Abwesenheiten überschreiben“ wählt, wird darauf hingewiesen, dass damit in Urlaub oder Krankmeldung eingegriffen wird und jede Übernahme protokolliert ist.
- **Datenschutzerklärung ergänzt**: Abschnitt „Zweck der Verarbeitung“ beschreibt jetzt, was der Assistent auswertet, dass keine automatisierte Entscheidung nach Art. 22 DSGVO stattfindet (der Vorschlag ist ein von Menschen geprüfter Entwurf) und wie Abwesenheitsgründe minimiert werden können.

### Behoben: Bedienung der Entwurfsmatrix
- **Zeilen springen beim Nachplanen nicht mehr.** Die Matrix sortierte sich nach jeder Änderung neu nach Auslastung – die gerade bearbeitete Person rutschte einem dabei unter dem Mauszeiger weg. Die Reihenfolge wird jetzt beim Berechnen eines Vorschlags einmal festgelegt und danach gehalten. Ein neuer Knopf **„Sortieren"** ordnet auf Wunsch bewusst neu.
- **Prüfliste rutschte bei langen Zeiträumen aus dem Bild.** Die Spalte der Matrix wuchs mit der Tabellenbreite mit, weil Grid-Spalten von Haus aus nicht unter ihren Inhalt schrumpfen (`min-width: auto`). Mit `minmax(0, 1fr)` bleibt die Prüfliste an ihrem Platz, und gescrollt wird ausschließlich innerhalb der Tabelle. Zusätzlich bleibt die Liste beim vertikalen Scrollen sichtbar.

### Einstellbar statt fest verdrahtet
- Neue Karte **„Planungsassistent"** unter Einstellungen → Planung. Damit lässt sich die Anwendung ohne Codeänderung an den eigenen Betrieb anpassen – Voraussetzung für den Einsatz in anderen Häusern:
  - **Einsatzbereiche mit freier Kapazität**: Welche Bereiche (Technik, Innendienst, …) als verfügbar gelten und beim Übernehmen umgetragen werden. Frei wählbar aus allen Planbereichen, statt wie bisher am Kürzel erraten.
  - Freie Kapazität aktiv auflösen, Kundenbindung beachten, Stammbereichs-Voreinstellung.
  - Regelbedarf, Mindestbesetzung, Tage am Stück, Tage im Zeitraum.
  - Azubis: zugelassene Lehrjahre, Höchstzahl pro Tag, Betreuungsschlüssel.
  - **Auswertungszeiträume**: aus wie vielen Monaten Kundenerfahrung und Kundenbindung berechnet werden (bisher fest 12 bzw. 6).
- Der Assistent übernimmt diese Werte als Voreinstellung jedes neuen Laufs; im Assistenten selbst bleibt alles weiterhin für den Einzelfall änderbar. Sind keine Bereiche gepflegt, greift weiterhin die automatische Erkennung als Vorschlag.

### Behoben: Innendienst wurde nicht automatisch aufgelöst
- Die Vorauswahl der „frei verplanbaren“ Bereiche suchte nach dem **Kürzel „T“**. Heißt der Bereich anders (etwa „TEC“), blieb die Liste leer – Innendienst-Tage galten dann als fremder Einsatz und wurden übersprungen statt umgeplant. Die Vorauswahl kommt jetzt vom Server und erkennt Innendienst-Bereiche unabhängig vom Kürzel; ausgenommen bleiben Schulungs-, Urlaubs- und Abwesenheitsbereiche sowie Berufsschule.
- Eine in derselben Sitzung gespeicherte leere Auswahl überschrieb die Vorauswahl dauerhaft. Leere Listen werden nun ignoriert, sodass die Vorauswahl wieder greift.
- **Neue Regel „Innendienst-Tage aktiv auflösen“** (standardmäßig an): Wer an dem Tag im Innendienst steht, wird bevorzugt zum Kunden geplant – vor Personen ohne Eintrag, die als Reserve für andere Kunden frei bleiben.

### Entwurf: Herkunft der Kapazität sichtbar
- **Innendienst-Tage werden direkt mit dem Einsatz belegt** und in der Matrix als solche gezeigt: Die Zelle trägt oben links ein kleines Dreieck in der Farbe des bisherigen Eintrags und beschriftet den Wechsel kompakt mit „T›K1“. Ersetzte Kundeneinsätze bekommen zusätzlich eine gestrichelte Unterkante, aufgelöster Innendienst eine durchgezogene.
- Die Legende benennt beide Fälle mit den echten Farben und Kürzeln („T → K1“), die Zusammenfassung zählt sie: „3 aus dem Innendienst übernommen · 1 ersetzt einen bestehenden Einsatz“.

### Bedarf vollständiger decken
- **Zweiter Durchgang**: Bleiben Plätze offen, obwohl noch Personen könnten, zieht der Assistent jene heran, die zuvor nur an den Belastungsgrenzen gescheitert sind (Tage am Stück, Tage im Zeitraum). Das geschieht nicht stillschweigend, sondern wird je Fall als Hinweis ausgewiesen.
- **Leicht umplanbare Personen zuerst**: Wer an dem Tag im Innendienst steht, bekommt einen deutlichen Bonus – das ist freie Kapazität. Wer überwiegend bei einem anderen Kunden sitzt, bleibt dort.
- **Unterdeckung wird begründet**: Statt nur „2 von 4 Plätzen besetzt" steht jetzt dabei, ob noch jemand verfügbar wäre oder warum nicht – etwa „Niemand mehr verfügbar – 3× Urlaub, 2× nicht für Wochenenddienst freigegeben, 1× bereits geplant".
- **Vorschlagsliste sortiert nach Aufwand**: Zuerst Innendienst („lässt sich leicht umplanen"), dann freie Personen, dann Stammbereich, zuletzt die Gebundenen mit dem Hinweis „ist überwiegend dort – lieber jemand anderen". Kopfzeile nennt die Zahl der Verfügbaren.

### Wartungsmodus
- **Administratoren kommen wieder durch.** Die Prüfung ließ ausschließlich die Rolle „Manager“ passieren – Administratoren sahen die Wartungsseite und konnten während der Wartung nicht arbeiten. Jetzt gilt sie für Administratoren und Manager.
- **Neu gestaltete Wartungsseite** statt der bisherigen Notausgabe: Symbol, Wartungsnachricht in ganzer Länge, das voraussichtliche Ende aus der geplanten Wartung, Schaltflächen für „Erneut versuchen“ und „Abmelden“ sowie die Angabe, wer angemeldet ist. Die Seite aktualisiert sich alle 60 Sekunden selbst – nach Ende der Wartung geht es ohne Zutun weiter.
- **Hinweisleiste für Berechtigte**: Wer während der Wartung weiterarbeitet, sieht oben einen Streifen „Wartungsmodus aktiv“ mit direktem Weg in die Einstellungen zum Beenden. So bleibt der Zustand nicht unbemerkt.
- Die Beschreibung in den Einstellungen sagt jetzt korrekt „Nur Administratoren und Manager können weiterarbeiten“.

### Hinweis zur Einspielung
`migrations/migrate_v6_8_5.sql` setzt `migrate_v6_8_0.sql` voraus, enthält selbst keine Schemaänderungen und ist mehrfach ausführbar.

## v6.8.0 (24.07.2026)

### Neu: Planungsassistent (Beta)

Ein geführter Assistent für komplexe Einsätze und Kundenprojekte. Aufruf über `planungsassistent`; die Berechtigung heißt `use_planning_assistant` (wer planen darf, bekommt sie bei der Migration automatisch).

- **Schritt 1 – Einsatz & Zeitraum**: Bezeichnung, Kunde/Einsatzbereich, Bemerkung am Eintrag, interne Kennzeichnung, Zeitraum (max. 120 Tage), Einsatztage je Wochentag, Feiertage überspringen.
- **Schritt 2 – Bedarf**: Regelbedarf und Mindestbesetzung, abweichender Bedarf je Wochentag, Sondertage mit eigener Besetzung (Wert 0 nimmt den Tag heraus), fachliche Vorgaben zu OS-Level, Ausbildungsberuf und Stammbereich.
- **Schritt 3 – Team & Azubis**: Kandidatenkreis nach Rolle, einzelne Personen fest einplanen oder ausschließen. Für Auszubildende: zugelassene Lehrjahre, Höchstzahl und Höchstanteil pro Tag, Betreuungsschlüssel, „nie allein beim Kunden", Berufsschultage und Wochenenden aussparen.
- **Schritt 4 – Regeln & Gewichtung**: Tage am Stück und Tage gesamt je Person, Wochenend-Freigabe beachten, Umgang mit belegten Tagen (nichts / andere Einsätze / auch Abwesenheiten – Letzteres nur mit Berechtigung). Gewichtung von Kontinuität, Gleichverteilung, Stammbereich und Qualifikation.
- **Schritt 5 – Entwurf**: Vorschlag als Matrix Mitarbeiter × Tag mit Abdeckungsquote und Kennzahlen. Jede Zelle lässt sich per Klick umbesetzen; für offene Bedarfe zeigt ein Klick auf die Tagesspalte passende Personen samt Begründung, warum jemand nicht kann.
- **Konfliktprüfung in drei Stufen**, live bei jeder Änderung: Blocker (Urlaub, Krankheit, Sperrtag, Berufsschule, Unterschreitung der Mindestbesetzung), Warnungen (Unterdeckung, Betreuungsschlüssel, zu viele Tage am Stück) und Hinweise. Jeder Eintrag springt zur betroffenen Zelle.
- **Übernahme als Entwurf**: Der Vorschlag wird nach `plan_drafts` geschrieben – veröffentlicht wird weiterhin bewusst in der Monatsplanung. Übersprungene Einträge werden aufgelistet, die gesamte Übernahme lässt sich in einem Zug zurücknehmen.
- **Vorlagen**: Anforderungen als Vorlage speichern (privat oder für alle Planenden) und beim nächsten Kundeneinsatz laden.

### Kandidatenauswahl
- **Stammbereich zählt deutlich stärker.** Neue Einstellung „Stammbereich der Mitarbeiter": *bevorzugen* (Standard), *ausschließlich* oder *egal*. Im strikten Modus kommen nur Leute infrage, die den Kunden als Stammbereich haben oder dort schon im Einsatz waren.
- **Kundenerfahrung fließt ein**: Wer in den letzten 12 Monaten bei diesem Kunden war, wird bevorzugt – gedämpft gewichtet, damit nicht immer dieselbe Person fährt. Abschaltbar.
- **Vorgabe „mindestens N aus dem Stammbereich"** wird jetzt aktiv besetzt statt nur hinterher bemängelt: Diese Plätze werden vor dem Auffüllen reserviert.

### Personensuche
- Filterleiste mit Volltextsuche über Name, OS-Level, Ausbildungsberuf und Kürzel, dazu Filter nach Stammbereich und Rolle sowie Schnellfilter „Nur Stammbereich", „Nur mit Kundenerfahrung", „Ohne Azubis" und „Nur mit Wochenend-Freigabe".
- Sortierung nach Eignung, Name, Kundenerfahrung oder OS-Level; Zähler „x von y Personen".
- Sammelaktionen: alle sichtbaren Personen fest einplanen, ausschließen oder die Auswahl zurücksetzen.
- Jede Zeile zeigt Stammbereich, frühere Einsätze beim Kunden (mit Datum des letzten), OS-Level, Ausbildungsberuf und Rolle.

### Lesbarkeit und Bedienung
- **Behoben – Text auf hellen Bereichsfarben**: Die Zellen der Entwurfsmatrix nutzten immer weiße Schrift. Jetzt wird die in den Planbereichen hinterlegte Schriftfarbe (`fg_color`) verwendet, sodass helle Farben wie Außenmontage wieder lesbar sind.
- **Übersicht „Ihre Vorgaben auf einen Blick"** am Ende von Schritt 4: Einsatz, Zeitraum, Bedarf, Stammbereich, Azubi-Regeln, Grenzen und Umgang mit belegten Tagen in einer Tabelle – erspart das Zurückblättern.
- Neuer Filter „Nur gut umplanbare" in der Personensuche.

### Innendienst als freie Kapazität
- **Technik-Tage gelten als verplanbar** (standardmäßig aktiv). Wer an einem Tag für Technik eingetragen ist, wird als verfügbar behandelt und der Eintrag beim Übernehmen ersetzt. Über „Als verplanbar behandeln" lassen sich weitere Allgemein-Bereiche wie Schulungstag freigeben; Berufsschule steht bewusst nicht zur Wahl.

### Umplanung mit Augenmaß
- Der Assistent wertet die letzten 6 Monate aus und erkennt, wie stark jemand an einen einzelnen Kunden gebunden ist. **Wer fast ausschließlich bei einem Kunden ist, wird nicht abgezogen**; wer ohnehin zwischen Kunden wechselt, wird bevorzugt umgeplant. Abschaltbar über „Kundenbindung beachten".
- In der Personenliste sichtbar als „fest bei ROS (91 %)" beziehungsweise „wechselt oft".

### Vorschlag drucken
- Schaltfläche **„Drucken"** in der Entwurfsansicht erzeugt eine druckfertige Fassung: Kopf mit Kunde, Zeitraum, Abdeckung und Stand, die Matrix im Querformat, die Summenzeile, eine Liste der offenen Punkte und ein Hinweis, dass es sich um einen unveröffentlichten Entwurf handelt. Im Druckdialog des Browsers lässt sich die Ausgabe wahlweise drucken oder über „Als PDF speichern" als Datei sichern – ohne Zusatzsoftware auf dem Server.

### Als Beta gekennzeichnet
- Der Assistent trägt ein **Beta-Abzeichen** in der Navigation, in der Werkzeugleiste der Monatsplanung und in der Seitenüberschrift. Ein Hinweis oben auf der Seite erklärt, dass die Funktion neu ist, ausschließlich Entwürfe schreibt und jede Übernahme zurückgenommen werden kann.

### Entwurfsansicht
- Drei Ansichten: **Matrix** (Mitarbeiter × Tag), **Tage** (pro Tag Besetzung, offene Plätze, direkt zu passenden Personen springen) und **Auslastung** (wer trägt wie viel, mit Balken und Stammbereichs-Kennzeichnung).
- Legende erklärt die Zellfarben; offene Tage sind rot markiert und bieten „N offen – Personen zeigen" an.
- Matrix überarbeitet: Kalenderwochen werden markiert und optisch getrennt, der heutige Tag ist hervorgehoben, unterbesetzte Tage färben den Spaltenkopf rot, die Fußzeile zeigt Besetzungsbalken statt nackter Zahlen, Zeilen sind abwechselnd hinterlegt.
- Zusammenfassungszeile über der Matrix: Einsatztage, Ø Personen pro Tag, Besetzungen aus dem Stammbereich, Anteil der Azubis und Zahl der ersetzten Einträge.

### Bereits geplante Einsätze ersetzen
- **Ersetzen per Klick**: Zellen mit einem fremden Einsatz (und, mit Recht `unlock_vacation`, auch Abwesenheiten) sind mit ⇄ gekennzeichnet. Ein Klick fragt im Klartext nach, was überschrieben wird, und setzt die Person nach Bestätigung ein – ohne dass die globale Regel geändert werden muss. Wird die Zelle wieder geleert, verfällt die Freigabe.
- Die Freigabe gilt ausschließlich für die angeklickte Zelle und wird serverseitig erneut geprüft. Sperrtage und Berufsschule bleiben in jedem Fall unantastbar.
- **Behoben**: Bei der Übernahme wurden fremde Einsätze bisher auch dann überschrieben, wenn „Nichts überschreiben" eingestellt war. Jetzt werden sie abgewiesen und im Ergebnis aufgeführt.

### Einstieg
- **Neuer Menüpunkt „Assistent"** in der Hauptnavigation direkt hinter „Planung" sowie ein **Button „Assistent"** in der Werkzeugleiste der Monatsplanung. Beide erscheinen nur für Rollen mit `use_planning_assistant` bzw. `plan_edit`.

### Darstellung
- Der Assistent nutzt durchgehend die Bausteine der Anwendung: Kopf- und Fußzeile, Karten (`card`/`ch`/`cb`), Formularfelder (`fg`), die Schrittleiste aus der Mitarbeiterverwaltung (`wz-steps`), die Planungstabelle (`pt`) für die Entwurfsmatrix, die gewohnten Dialoge (`modal-bg`) und Meldungen (`toast`). Kein eigenes Layout, keine abweichenden Farben – ergänzt wurden nur rund 85 Zeilen CSS für Dinge, die es in der App noch nicht gab (Bedarfsraster, Gewichtungsregler, Zellen der Matrix).

### Technisch
- Neue Tabellen `planning_profiles` und `planning_runs`, neue Spalte `plan_drafts.planning_run_id` (Migration `migrations/migrate_v6_8_0.sql`).
- Serverseitige Absicherung: Sperrtage und Berufsschule werden nie überschrieben, Abwesenheiten nur mit Berechtigung `unlock_vacation`. Planbar sind ausschließlich Mitarbeiter im eigenen Zuständigkeitsbereich – der Assistent nutzt dafür dieselbe Funktion `visibleUserIds()` wie die Monatsplanung (inkl. unterstellter Teamleiter und aktiver Vertretungen). Administratoren bleiben wie überall außen vor.
- Als Ist-Stand werden veröffentlichte Einträge gelesen und vorhandene Entwürfe darübergelegt – identisch zur Planungsseite. Übernahmen landen ausschließlich in `plan_drafts`.
- Aufrufe ohne Berechtigung werden abgewiesen: die Seite mit der gewohnten „Kein Zugriff"-Ansicht, Hintergrundaufrufe mit HTTP 403 und JSON.

## v6.7.0 (03.07.2026)

### Behoben
- **Wochenend-Einsätze wieder in voller Farbe**: Einsätze an Samstagen/Sonntagen wurden durch die blasse Wochenend-Hinterlegung überdeckt und nur sehr schwach angezeigt. Die Einsatzfarbe hat jetzt immer Vorrang – in der Monats-, Gesamt- und Jahresansicht sowie beim interaktiven Eintragen und der Live-Aktualisierung.

### Neu / verbessert
- **Werkzeug „Int. Bemerkung" (Masseneintragung)**: Interne Bemerkungen lassen sich jetzt – wie öffentliche Bemerkungen – per Zellauswahl für mehrere Tage gleichzeitig setzen (nur für Berechtigte mit interner Ansicht sichtbar).
- **Stift-Symbol** am Bemerkungswerkzeug gedreht (zeigt nun nach links).
- **Planbereiche-Verwaltung – Sortierung**: Die Reihenfolge wird jetzt automatisch und lückenlos vergeben; doppelte Sortierwerte sind ausgeschlossen. Umsortiert wird bequem über Pfeil-Buttons (↑/↓); das manuelle Zahlenfeld entfällt.
- **WOT-Fenster bleibt geöffnet**: Die WOT-Dialoge schließen nicht mehr versehentlich beim Klick daneben oder mit Esc – nur noch über „X" oder „Schließen".
- **Offline-Sperre**: Bricht die Serververbindung ab, erscheint sofort ein ganzseitiger Hinweis; Eintragungen sind währenddessen gesperrt und werden nach Wiederverbindung automatisch wieder freigegeben.

### Darstellung
- **Sortierung als kompakter Button** statt Dropdown – spart Platz in der oberen Leiste und verhindert die verschobene Darstellung auf kleineren Bildschirmen. Ein Klick schaltet zwischen „Standard" und „A–Z" um.

## v6.6.5 (30.06.2026)

### Behoben
- **Mitarbeiterauswahl** endgültig korrigiert: Die Listeneinträge liegen in einem `.fg`-Block, dessen globale `label`-Regel (block, zentriert, Großbuchstaben) die Zeilen überschrieben hatte – dadurch wirkte die Checkbox „mittig" und Namen wurden verdeckt. Layout ist jetzt fest verankert (Checkbox ganz links, Name sichtbar, Badges rechts).

## v6.6.4 (30.06.2026)

### Geändert
- **Migrationen liegen jetzt im Ordner `migrations/`** (statt im Projekt-Hauptverzeichnis), inkl. kurzer Anleitung (`migrations/README.md`). Deploy-Hinweis angepasst.

### Behoben
- **Mitarbeiterauswahl**: Anhakfeld (Checkbox) sitzt jetzt fest ganz links, der Name füllt die Zeile – keine zentrierte/„schwebende" Checkbox mehr.

## v6.6.3 (30.06.2026)

### Einsatz erfassen – Mitarbeiterauswahl überarbeitet
- **Einzeilig statt klobig**: pro Person eine schlanke Zeile, Name links, Zusatzinfos rechts.
- **Mehr Kontext je Person**: Team / Stammbereich (farbig), OS-Level, Ausbildungsberuf und Rolle bzw. Ausbildungsjahr (z. B. „Azubi 1. LJ") direkt sichtbar.

## v6.6.2 (30.06.2026)

### Behoben
- **„?"-Eintrag wird jetzt gespeichert**: Die Server-Prüfung beim Speichern hatte den neuen Typ „offen" abgelehnt – er blieb daher nicht stehen. (Zum Veröffentlichen weiterhin Migration `migrate_v6_6_0.sql` nötig.)
- **Eingefrorene Spalten beim Querscrollen fixiert**: Die Gruppen-Überschrift hatte die erste Spalte breiter erzwungen (min-width 200px) als die fixierten Versätze annehmen – dadurch „wanderten" Info und RU Anf. beim seitlichen Scrollen. Überschrift ist jetzt auf die Namensspaltenbreite begrenzt.

### Darstellung
- **Kopfzeilen „Mitarbeiter" und „Info"** vereinheitlicht (gleicher Text, gleiche Ausrichtung und Höhe).
- **Druck**: Samstag/Sonntag jetzt auch in der Kopfzeile etwas dunkler hinterlegt.

## v6.6.1 (30.06.2026)

### Behoben
- **Tabellen-Layout-Fehler korrigiert**: Die Info-Spalte hatte das Spaltenraster der Planungstabelle zerschossen (große Lücke vor den Tagesspalten). Die Mehrfach-Inhalte brechen jetzt korrekt um, ohne das Layout zu stören.

### Neu / verbessert
- **„?"-Schnellbutton** in der Werkzeugleiste der Planung (Offen / noch zu klären) – nicht nur im Dialog.
- **Info-Spalte im Druck** sauber dargestellt (Badges brechen um, ausreichend Platz).
- **„Mein Plan"-Kalender** übersichtlicher: größere Tageskacheln, deutlichere Tageszahlen, größere Einsatz-Chips, vollständige Bemerkungen.
- **Profilansicht am Handy** optimiert: einspaltig, lesbare Tabellen und Kennzahlen.

## v6.6.0 (30.06.2026)

### Planung
- **Neue Eintragung „?" (Offen / noch zu klären)** – für Tage, an denen noch etwas offen ist. Eigene Farbe, im Erfassen-Dialog (einmalig & wiederkehrend) und in der Legende.
- **Sortierung wählbar**: „Standard" (Manager, dann Teamleiter, dann alphabetisch) oder „A–Z" – jeweils **innerhalb der einzelnen Gruppen**.
- **Filter- und Sortierauswahl werden dauerhaft gespeichert** und beim nächsten Besuch automatisch angewendet.

### Darstellung
- **Gruppen-Überschriften** (Techniker, Azubis …) klarer abgesetzt: durchgehende Trennlinien oben/unten und ein roter Akzent.
- **Info-Spalte** mit mehreren Inhalten (Bereich, OS-Level, Ausbildung) bricht jetzt sauber um statt zu quetschen.
- **Mitarbeiterauswahl** im „Einsatz erfassen"-Dialog übersichtlicher (zweispaltig, klar markierte Auswahl).

## v6.5.2 (30.06.2026)

### Druck
- Ausdrucke nutzen jetzt **zuverlässig die volle Seitenbreite** (Umstellung auf automatische Tabellenbreite – die Tagesspalten dehnen sich über das ganze Blatt).

### Vertretungsseite
- Mehr Innenabstand in den Karten – Texte kleben nicht mehr am Rand.

### Sicherheit
- DB-Zugangsdaten können nun vollständig über **Umgebungsvariablen** (CCKOE_DB_*) oder eine ausgelagerte `secrets.php` gesetzt werden – **kein Klartext im Projekt nötig**. Die Zugangsdaten liegen weiterhin außerhalb von `public/`.

### Performance
- **Dauerhafte Datenbankverbindungen** (persistent), zusätzliche **Indizes** für häufige Abfragen und **verzögertes Laden** des Skripts für schnelleren Seitenaufbau.

## v6.5.1 (30.06.2026)

### Vertreterregelung
- Ist ein **Teamleiter krank oder im Urlaub**, kann er seine Rechte für einen **bestimmten Zeitraum an eine Vertretung** abgeben. Die Vertretung sieht und bearbeitet während dieser Zeit automatisch das Team des Abwesenden, kann dort eintragen und Anträge genehmigen – danach enden die Rechte automatisch.
- Neuer Bereich **„Vertretung"** (für Teamleiter, Manager, Admin): Vertretung wählen, Zeitraum festlegen, optional mit Notiz; jederzeit beendbar. Administratoren sehen eine Gesamtübersicht.
- Ein dezenter Hinweis zeigt der Vertretung, wen sie gerade vertritt.

## v6.5.0 (30.06.2026)

### Sicherheit
- **Neue Ordnerstruktur mit `public/`-Verzeichnis**: Alle öffentlich erreichbaren Dateien liegen jetzt in `public/`. Die **Datenbank-Zugangsdaten** (und die Backups) liegen in `app/` **außerhalb** des Web-Wurzelverzeichnisses und sind öffentlich nicht erreichbar, intern aber voll nutzbar. Zusätzliche `.htaccess`-Sperren als Defense-in-Depth. Anleitung für STRATO in `README_DEPLOY.md`.

### Darstellung & Bedienung
- **Ausdrucke nutzen wieder die volle Seitenbreite** – die Tagesspalten dehnen sich jetzt über das ganze Blatt.
- Fällt **„heute" auf ein Wochenende oder einen Feier-/Ferientag**, wird der Spaltenkopf trotzdem korrekt blau markiert.
- **„Mein Plan" (mobil)**: neuer **„Heute"-Knopf**, der direkt zum heutigen Tag springt.

### Performance
- **GZIP-Komprimierung** und **Browser-Caching** für CSS/JS/Bilder (schnelleres Laden, besonders bei wiederholten Aufrufen). Automatische Cache-Erneuerung bei Updates über Datei-Versionierung.

## v6.4.4 (27.06.2026)

### Planung – Verlassen-Hinweis
- Beim **Durchschalten der Monate** in der Planung erscheint **keine** „Seite verlassen"-Meldung mehr.
- Die **Browser-Meldung** wurde abgeschaltet. Ein Hinweis auf unveröffentlichte Änderungen kommt jetzt – wie schon zuvor – ausschließlich über den **app-eigenen Dialog** und nur, wenn in der aktuellen Sitzung tatsächlich unveröffentlichte Änderungen vorliegen.

## v6.4.3 (27.06.2026)

### Design
- Durchgängig **Markenrot** für die Avatare im Bearbeiten-Fenster und im Team-Builder – keine blau/violetten Farbverläufe mehr.
- Im Team-Builder starten die Boxen **„Verfügbar" und „Im Team" jetzt auf gleicher Höhe** (gleicher oberer Abschluss, gleiche Höhe).

## v6.4.2 (27.06.2026)

### Korrektur
- **Team-Builder & Bearbeiten-Fenster**: Kopfbereich überarbeitet – korrekte, bündige Abstände (gleiche Ränder wie der restliche Dialog) und ein dezenter, professioneller Team-Zähler statt der auffälligen roten Zahl.

## v6.4.1 (27.06.2026)

### Überarbeitete Dialoge in der Mitarbeiterverwaltung
- **Benutzer bearbeiten**: deutlich aufgeräumteres, breiteres Fenster mit Kopfbereich (Avatar mit Initialen, Name, Rolle, Benutzername) und einer ausgewogenen Anordnung – persönliche Daten links, Zugang und Einsatz-Details rechts, Vorgesetzte über die volle Breite. Jede Sektion ist als eigene Karte mit Überschrift dargestellt.
- **Team zusammenstellen** (Unterstellte zuordnen): komplett neuer, grafischer Team-Builder mit zwei Spalten – „Verfügbare Mitarbeiter" und „Im Team". Mitarbeiter werden per Klick hin- und hergeschoben, mit farbigen Rollen-Avataren, Suche, Live-Zähler und übersichtlicher Darstellung.

## v6.4.0 (26.06.2026)

### Mitarbeiter- & Vorgesetzten-Verwaltung
- **Bis zu 4 Vorgesetzte pro Mitarbeiter**: Diese Personen sehen den Plan, Urlaub & Krankheit und dürfen für den Mitarbeiter eintragen. Die Grenze wird in der Oberfläche und serverseitig durchgesetzt.
- **Grafisch überarbeitete Zuweisung**: Zugewiesene Vorgesetzte erscheinen als übersichtliche Chips (mit Initiale, Name und Rolle) und lassen sich einzeln per Klick entfernen; darunter eine durchsuchbare Liste zum Hinzufügen samt Zähler „x / 4".
- **Bearbeiten ohne Assistent**: Beim Bearbeiten eines Benutzers öffnet sich jetzt ein **breiteres Fenster mit allen Details auf einen Blick** (zweispaltig, kein Schritt-für-Schritt). Das Anlegen neuer Benutzer nutzt weiterhin den geführten Assistenten.
- **Azubi-Beförderung**: Neue Aktion „★ Zum Techniker befördern" – wenn ein Auszubildender die Ausbildung abgeschlossen hat, wird er mit einem Klick zum Techniker (Rolle „Techniker").

## v6.3.7 (26.06.2026)

### Korrektur
- **Ferien-/Feiertagsbeschriftung im Spaltenkopf** liegt jetzt bei allen Tagen **auf einer Linie**. Die Kalenderwochen-Zeile (z. B. „KW30") wird in jeder Spalte gleich hoch reserviert, sodass Tagesnummer, Wochentag und Ferientext (z. B. „Somm") spaltenübergreifend bündig stehen.

## v6.3.6 (26.06.2026)

### Korrekturen & Verbesserungen
- **Ferien werden nicht mehr eingefärbt/„verblasst"** – da Mitarbeiter auch während der Ferien geplant werden, erfolgt die Markierung jetzt ausschließlich im **Spaltenkopf** (Beschriftung), die Plan-Zellen bleiben normal nutzbar. Auch in „Mein Plan" überdeckt ein Ferien-Tag keine Einsatzfarbe mehr.
- **„Mein Plan" auf dem Handy** komplett neu als übersichtliche Agenda: Monats-Navigation, Kurzübersicht (Einsätze / Abwesend / Frei), Gruppierung nach Kalenderwoche, große Datums-Badges, farbige Einsatz-Pillen mit Bezeichnung, Bemerkungen, klar markierter „Heute"-Tag und Ferien als dezentes Label.

## v6.3.5 (26.06.2026)

### Gesamtplan & Sichtbarkeit
- Im **Gesamtplan** sehen jetzt alle Rollen (auch Teamleiter) **alle Mitarbeiter**.
- Neuer Schnellfilter **„Meine Mitarbeiter"** für Teamleiter und Manager (blendet auf das eigene Team um).
- Heikle Details (**Urlaub/Krankheit**) bleiben den jeweiligen Vorgesetzten vorbehalten – sonst erscheint nur ein rotes **„A"**.
- **Verwaltung** kann alle Mitarbeiter sehen und planen; Abwesenheiten weiterhin nur als „A".

### Planung
- **Ausnahmeregel**: Einzelne Teamleiter können das Recht erhalten, für **alle** Mitarbeiter zu planen (Schalter im Mitarbeiter-Assistenten, Schritt „Team & Abschluss").

### Darstellung
- **„Mein Plan" in der Handyansicht** komplett überarbeitet – der Kalender ist jetzt eine gut lesbare **Agenda-Liste** statt eines engen Rasters.
- Bessere Darstellung von **Ferien (blau)** und **Feiertagen (rot)** im Plan, inkl. farbiger Tagesnummern.
- Das Abwesenheits-**„A" ist jetzt durchgängig rot**.

### Performance
- Konfliktprüfung und Speichern bei Mehrfachplanung deutlich beschleunigt: Sammelabfragen statt vieler Einzelabfragen (von ~5 Abfragen pro Zeile auf 3 Gesamtabfragen). Spürbar bei großen Datenbanken.
- Zusätzlicher Datenbank-Index für die Vorgesetzten-Zuordnung (`user_supervisors.supervisor_id`).

## v6.3.4 (26.06.2026)

### Konfliktbearbeitung & Mehrfachplanung
- **Mehrere Mitarbeiter gleichzeitig planen** – ideal für einen Onsite-Einsatz beim Kunden über mehrere Tage: Das Erfassen-Fenster bietet jetzt eine durchsuchbare Mehrfachauswahl der Mitarbeiter (mit „Alle/Keine").
- **Interaktive Konfliktauflösung**: Vor dem Speichern werden alle Konflikte gesammelt geprüft und – nach Mitarbeiter gruppiert – übersichtlich angezeigt. Pro Mitarbeiter oder pro Tag kann gewählt werden, ob ein bestehender Eintrag überschrieben oder unverändert gelassen wird. Ganze Mitarbeiter lassen sich mit einem Klick aus der Überschreibung herausnehmen.
- Geschützte **Urlaubs-/Kranktage** werden nur mit der Berechtigung „Urlaub entsperren" zur Überschreibung angeboten; ansonsten bleiben sie erhalten.
- Live-Zähler zeigt, wie viele Einträge angelegt und wie viele Einträge überschrieben werden.

### Planungstabelle
- Einheitliche Zellgrößen und durchgängig **dünnere, ruhigere Trennlinien**.

### Berechtigungen & Sichtbarkeit
- Der **Gesamtplan** ist für alle einsehbar; heikle Details wie **Urlaub und Krankheit** sehen weiterhin nur die jeweiligen Vorgesetzten.
- **Einsatzbereiche** und **Feiertage** sind nur noch für Manager, Verwaltung (Service Administration) und Administrator bearbeitbar.

## v6.3.3 (26.06.2026)

### Planungstabelle
- Klar sichtbare Trennlinien für **alle** Zellen – auch auf farbig hinterlegten Einsatzzellen. Einheitliches Raster über eine zentrale Rasterfarbe, deutlich abgesetzte fixierte Spalten und saubere Außenkanten.

### Druck
- Größerer Seitenrand (8&nbsp;mm) für einen aufgeräumten Ausdruck.
- **Namen werden vollständig angezeigt** (breitere, umbrechende Namensspalte); die Tagesspalten wurden entsprechend schmaler gesetzt, damit der Plan weiterhin auf eine A4-Seite passt.

### Nutzerverwaltung
- Tabelle mit fixierter Namensspalte beim seitlichen Scrollen, sticky Kopfzeile, Zebra-Streifen und deutlicherem Hover.
- Aktionen-Menü (⋯) öffnet jetzt frei positioniert und wird nicht mehr abgeschnitten; schließt automatisch beim Scrollen/Klick daneben.
- Leerzustand „Keine Mitarbeiter gefunden" bei leerem Filter, Suchfeld wird automatisch fokussiert.
- **Benutzer- & Teamverwaltung ausschließlich für Manager, Verwaltung (Service Administration) und Administrator** – fest an die Rolle gebunden, unabhängig von DB-Rechten oder Migrationsstand.

## v6.3.2 (26.06.2026)

### Planungstabelle – Feinschliff (seitliches Scrollen / kleine Monitore)
- Die fixierten Spalten (Name, Info, Resturlaub) skalieren jetzt responsiv: auf kleineren Monitoren, Tablets und Smartphones werden sie schmaler, sodass mehr Tagesspalten sichtbar bleiben.
- Beim horizontalen Scrollen hebt sich der fixierte Bereich durch einen dezenten Schatten ab – man erkennt sofort, wo die feststehenden Spalten enden und der scrollbare Teil beginnt.
- Spaltenbreiten und -versätze laufen jetzt über zentrale CSS-Variablen (calc), wodurch sich die fixierten Spalten exakt aneinanderreihen und nicht mehr verrutschen.
- Sanfteres, „eingefangenes" Touch-Scrolling (kein versehentliches Zurück-Wischen), dezenter Scrollbalken und saubere Namenskürzung (…) bei knappem Platz.

## v6.3.1 (25.06.2026)

### Verbesserungen
- **Anlage-Assistent**: Neue Benutzer werden jetzt über einen übersichtlichen 4-Schritte-Assistenten angelegt (Zugang → Person → Einsatz → Team &amp; Abschluss) mit Fortschrittsanzeige, Validierung pro Schritt und Zusammenfassung. Die Schritte sind direkt anklickbar.
- **SNOW-ID entfernt**: Das Feld SNOW-ID wurde aus den Mitarbeiterdaten (Anlage, Bearbeitung, Profil) entfernt.
- **Druck**: Planausdruck mit schmaleren Seitenrändern (2&nbsp;mm) und leicht kompakteren Zeilen – passt vollständig auf eine DIN-A4-Seite (Querformat).

### Berechtigungen
- **Benutzer- &amp; Teamverwaltung** ist nur noch für **Manager, Verwaltung (Service Administration) und Administrator** zugänglich. **Teamleiter** können keine Benutzer mehr anlegen/bearbeiten und keine Teams mehr zuordnen (Menüpunkt „Team" wird ausgeblendet).

## v6.3.0 (25.06.2026)

### Vertraulichkeit (Krankheit & Urlaub)
- **Strenger Sichtschutz**: Krank- und Urlaubsdaten sind jetzt nur noch sichtbar für den Mitarbeiter selbst, seinen Teamleiter, den zuständigen (FOS-)Manager und ggf. den Administrator. Alle anderen Rollen (z.B. Verwaltung, Lager, Kollegen) sehen an betroffenen Tagen nur noch ein neutrales „A" (abwesend) – ohne Art, Grund oder Bemerkung.
- Maskierung greift überall: Monatsplanung (inkl. Resturlaubs-Spalten „RU Anf./Ende"), Gesamtplan, Jahresübersicht, Live-Aktualisierung und „Heute abwesend" auf dem Dashboard.
- **Urlaubsplanung gesperrt**: Die Urlaubs-/Krankheitsübersicht ist nur noch für Teamleiter, Manager und Administratoren erreichbar; der Menüpunkt wird für andere ausgeblendet.

### Features
- **Mitarbeiter einem Manager/Teamleiter zuordnen**: In der Nutzerverwaltung kann die Team-Zusammensetzung jetzt direkt von der Vorgesetzten-Seite gepflegt werden – über die neue „Team"-Spalte lassen sich einem Manager oder Teamleiter mit einem Klick die unterstellten Mitarbeiter zuordnen (mit Suchfeld). Funktioniert für Manager, Teamleiter und Administratoren.
- **Vorgesetzten-Sicht in der Planung**: Manager und Teamleiter sehen in Monatsplanung, Gesamtplan, Jahresübersicht, Urlaubsplanung und Dashboard nur noch die ihnen zugeordneten Mitarbeiter (inkl. transitiv unterstellter Teams), gesteuert über das Recht `view_all_employees`.
- **Schnelle Vorgesetzten-Änderung**: Vorgesetzte lassen sich direkt in der Mitarbeiterliste über ein kompaktes, durchsuchbares Modal ändern.
- **Datenschutz-Seite (DSGVO / BetrVG)**: Neue Seite `datenschutz` mit Verarbeitungshinweisen; pflegbare Angaben unter Einstellungen → Allgemein; Link im Benutzermenü und Footer.

### Nutzerverwaltung – Umbau
- Neue Kopfzeile mit anklickbaren Kennzahlen-Kacheln (Aktiv, Inaktiv, Ausgetreten, Gesamt) als Schnellfilter.
- Zusätzlicher **Rollenfilter** und Live-Anzahl der Treffer neben Such- und Statusfilter.
- Aufgeräumte Tabelle: Benutzername unter dem Namen, Ein-/Austritt zusammengefasst, klarere Status-Badges, neue „Team"-Spalte mit Anzahl Unterstellter.
- Aktionen (Aktivieren, Passwort, Entfernen) in ein kompaktes ⋯-Menü zusammengefasst.

### Sicherheit / Rechtssicherheit
- Serverseitige Scope-Absicherung in `save_bulk.php` und `save_remark.php` – Bearbeitung nur im eigenen Verantwortungsbereich.
- Genehmigungen und Pending-Markierungen konsequent auf das eigene Team beschränkt.

### Bugfixes
- **Interne & öffentliche Bemerkungen gleichzeitig**: Beide Bemerkungstypen bestehen jetzt unabhängig nebeneinander, ohne sich gegenseitig zu überschreiben.

## v6.2.1 (02.04.2026)

### Features
- **Abmelde-Hinweis**: Nach erfolgreicher Abmeldung wird auf der Login-Seite eine grüne Erfolgsmeldung angezeigt, die nach 4 Sekunden automatisch ausblendet.
- **Kalender-Logo**: EP-Text durch SVG-Kalender-Icon auf Login-Seite und in der Navbar ersetzt.
- **Jahr veröffentlichen**: Button im Jahresplan für Admins/Manager – jetzt als Outline-Button mit roter Schrift und rotem Rahmen (statt gefüllt). Zeigt das aktuelle Jahr im Button-Text.

### Verbesserungen
- **Modals komplett überarbeitet**: Bestätigungs-Dialog (`epConfirm`) mit rundem Warn-Icon zentriert, breitere Buttons. Eingabe-Dialog (`epPrompt`) mit Icon im Header. Verlassen-Modal mit konsistentem Icon-Kreis. Neue CSS-Klassen für Icon-Varianten (warn, danger, info, success). Sanftere Einblende-Animation mit Scale-Effekt.
- **Toast-Benachrichtigungen**: Farbiger Akzentstreifen links, etwas breitere Karten mit max-width, dezenter Schatten.
- **Mein Plan – Kalenderansicht**: Tage außerhalb des Monats mit Streifenmuster dargestellt. Feiertage mit rotem Randstreifen und roter Tageszahl. Ferien mit blauem Randstreifen und blauer Tageszahl. Ferien-Name wird unten rechts im Kästchen angezeigt wenn ein Einsatz vorhanden ist.
- **Mein Plan – Heute-Button**: Unter dem Monatsnamen platziert (wie in Monatsplanung/Gesamtplan).
- **Mein Plan – Listenansicht**: Überarbeitetes Tabellendesign mit KW-Trennlinien, Ferien-Hervorhebung und Hover-Effekt.
- **Dashboard – Meine Woche**: Farbiger Akzentstreifen oben, „Heute"-Badge, bessere Feiertag/Ferien-Darstellung mit vollen Namen. Bei Ferien wird der Einsatzbereich weiterhin angezeigt mit Ferien-Name als Untertitel.
- **Dashboard – Kommende Feiertage**: Zeigt jetzt nur noch echte Feiertage, keine Schulferien/Ferien mehr.
- **Gesamtplan**: Klick ohne Werkzeug zeigt keine Meldung mehr – weder Toast noch Statustext.

### Bugfixes
- **Gesamtplan Toast-Meldung**: „Bitte zuerst ein Werkzeug wählen" aus app.js entfernt, die fälschlicherweise auch im Gesamtplan feuerte (pe-c Klasse wurde geteilt).

### Sonstiges
- Alle Versionsdaten auf v6.2.3 aktualisiert.

## v6.1.6 (22.03.2026)

### Features
- **Krankheitstage bei Dauereinsatz überspringen**: Veröffentlichte Krankheitstage (krank halb/ganz/Attest) werden bei der Dauereinsatzplanung automatisch übersprungen – analog zu genehmigtem Urlaub. Im Konflikt-Modal wird eine eigene Sektion mit SVG-Icon angezeigt.
- **Meldungen / Announcements**: Neue DB-Tabelle `announcements` mit Titel, Inhalt, Priorität (Info/Warnung/Kritisch) und optionalem Sichtbarkeitszeitraum. Dashboard zeigt aktive Meldungen als farbige Banner. Verwaltung unter Einstellungen → Wartung.
- **Verlassen-Warnung beim Tab/Fenster schließen**: `beforeunload`-Event zeigt den nativen Browser-Dialog wenn unveröffentlichte Änderungen (Entwürfe) vorhanden sind. Gilt für Tab schließen, Fenster schließen, URL-Eingabe und F5.

### Verbesserungen
- **Changelog erweitert**: Modal hat scrollbaren Body (`max-height: 60vh`), Typ-Auswahl beim Erstellen (Feature, Bugfix, Verbesserung, Sicherheit, Breaking Change), farbige Typ-Badges in der Anzeige.
- **Keine Emojis mehr**: Alle Unicode-Emojis (⛔, ⚠, ℹ, 🩺) durch saubere inline SVG-Icons ersetzt – im Konflikt-Modal, Warn-Dialogen und allen UI-Elementen.
- **Druckformular komplett überarbeitet**: Alle `@media print`-Blöcke in einen einzigen konsolidierten Block zusammengeführt. Globales `print-color-adjust: exact` auf allen Elementen. Proportionen optimiert: Name 44px/4.5pt, Info 16px, RU 22px, Tageszellen automatische Breite. WOT-Spalte im Druck ausgeblendet. Seitenränder auf 3mm reduziert.

### Bugfixes
- **Druckseite weiß**: Ursache behoben – Mobile-CSS (`@media max-width: 768px`) ohne `screen`-Qualifier hat `.mob-hide-content { display: none }` auch beim Drucken angewendet und den gesamten Inhalt ausgeblendet. Alle responsiven Breakpoints jetzt `@media screen and (max-width: ...)`.
- **Einstellungsseite Darstellungsfehler**: Meldungen-Card war außerhalb des `sP4`-Panels – jetzt korrekt innerhalb der Wartungs-Sektion platziert.


## v6.1.2 (21.03.2026)

### Features
- **Mobile Version**: Bottom-Navigation mit 4 Tabs (Home, Mein Plan, Profil, Abmelden) fixiert am unteren Bildschirmrand. Desktop-only Seiten zeigen Hinweis mit Link zum Dashboard. Mein Plan Kalender touch-optimiert (56px Zellenhöhe, kompakte Tags). Desktop-Buttons (Planung, Gesamtplan, Jahresansicht, Drucken) auf Mobilgeräten ausgeblendet.
- **iCal-Abo pro User**: Neues API `api/ical.php` liefert Standard-RFC-5545 iCal-Feed. 64-Zeichen-Token pro User (`bin2hex(random_bytes(32))`), Token-Verwaltung auf der Profil-Seite (generieren, kopieren, widerrufen). Feed enthält Einsätze, Abwesenheiten, Feiertage. Sync-Intervall 15 Minuten.
- **Backup & Restore System**: AES-256-CBC verschlüsselte Backups als `.epbak`-Dateien. Maximal 10 Backups, ältestes wird automatisch überschrieben. Manuelles Backup per Klick, Restore mit automatischem Sicherheits-Backup. Cron-Endpoint mit Secret Key für tägliche Automatisierung. Schlüssel-Anzeige in der UI. Neuer Tab "Backup" in den Einstellungen.
- **Entwürfe anderer Benutzer sichtbar**: Eigene Entwürfe = blauer Balken am Zellenrand. Fremde Entwürfe = gelber Balken + Dreieck + Tooltip mit Autor-Name. Legende um alle Status-Indikatoren erweitert.
- **Rollen: "Niemand außer"**: Override-Modal mit 3 Modi – "Zusätzlich gewähren" (grün), "Niemand außer" (gelb/orange), "Verweigern für" (rot). "Niemand außer" verweigert allen Rollen-Mitgliedern das Recht und gewährt es nur den ausgewählten Personen.

### Verbesserungen
- **Nicht veröffentlichen = Ein/Aus-Toggle**: Statt Checkbox jetzt ein Slider-Toggle in fester Box (26px Höhe). Grüner Track "Veröffentlichen" ↔ orangener Track "Nicht veröffentlichen" mit dynamischem Label.
- **Audit Log anonymisiert**: Keine Benutzernamen, keine IPs, keine Details in der UI-Anzeige. Login-Statistik zeigt nur Tagesaggregate. Daten bleiben vollständig in der DB.
- **Gesperrte Benutzer erkennbar**: Rotes Schloss-SVG am Mitarbeiternamen wenn das Konto temporär gesperrt ist.
- **Veröffentlichung: Andere Änderungen bleiben**: Beim Veröffentlichen eigener Änderungen werden Entwürfe anderer als Entwürfe beibehalten. Toast-Meldung zeigt Anzahl verbliebener fremder Entwürfe.

### Bugfixes
- **Namensspalte Ausdruck**: Print-CSS von 130px schrittweise auf 44px optimiert mit `text-overflow: ellipsis`. Badges (TL, MA, WE, Schloss) im Druck ausgeblendet.


## v6.0.5 (20.03.2026)

### Features
- **Andere unveröffentlichte Änderungen – Info-Banner**: Blaues Banner in der Monatsplanung zeigt Anzahl fremder Entwürfe. "Alle veröffentlichen"-Button für Berechtigte. Badges getrennt: "Meine: X" (gelb) und "Andere: Y" (blau).
- **Dauereinsatz Konflikt-Modal**: Pre-Check via `check_conflicts` API. Modal zeigt: gesperrte Urlaubstage (rot), bestehende Einträge die überschrieben werden (gelb) inkl. aktuellem Wert, Entwürfe mit "(Entwurf)"-Label, übersprungene Tage.
- **Neue Rollen anlegen**: DB-Tabelle `custom_roles` mit Schlüssel, Name, Farbe, Basis-Rolle. System-Rollen geschützt. `users.role` von ENUM auf VARCHAR(30) geändert. Rollen-Seite: "Neue Rolle erstellen"-Button mit Modal, "Rolle löschen" für Custom Roles.
- **Benutzer-Überschreibungen (Overrides)**: Tabelle `permission_user_overrides` mit grant/deny pro Person und Berechtigung. Override-Tags inline an Berechtigungen (grün = gewährt, rot = verweigert). `hasPerm()` prüft User-Overrides vor Rollen-Permissions.
- **Sicherheits-Hardening**: `includes/security.php` mit Rate-Limiting, Input-Sanitierung, CSRF-Validierung. Alle 12 API-Endpoints abgesichert. Session-Cookie: httponly, samesite=Strict, secure. CSP/HSTS-Header. `.htaccess` blockiert .sql/.env/.log/.epbak.

### Verbesserungen
- **Rollen-Seite komplett neu**: Grid-Layout mit Sidebar (Rollen-Cards) + Hauptpanel. Custom Toggle-Switches, gruppierte Berechtigungen mit Icons, "Alle an/aus"-Buttons. Toggle und Personen-Button unabhängig voneinander.
- **Bemerkungen: Dreiecke links + rechts**: Interne Bemerkung = rotes Dreieck oben links. Öffentliche Bemerkung = oranges Dreieck oben rechts. Beide gleichzeitig sichtbar.
- **Nicht veröffentlichen: Neon-Orange**: Rahmen `#ff6b00`, 2.5px dashed. Toolbar-Label mit orangem Hintergrund.
- **Wochenende mit Einsatz**: PHP rendert `background:#xxx !important` für `we-filled` Cells.
- **Mein Plan Kalender**: Linker Rand (4px, volle Farbe) + leichter Farb-Tint für Lesbarkeit.

### Datenbank
- **Idempotente Migrationen**: Stored Procedures `ep_safe_col()` und `ep_safe_idx()` prüfen über `information_schema` ob Spalte/Index existiert. Kein `#1060 Duplicate column` oder `#1064 Syntax error` mehr möglich.
- **Performance-Indexes**: 8 neue Indexes auf absences, assignments, plan_drafts, notifications, audit_log, login_attempts.
- **editing_status optimiert**: Composite PK statt auto-increment, InnoDB statt MEMORY, `target_uid`-Spalte mit Fallback.

### Bugfixes
- **#1054 target_uid**: `live_status.php` prüft per `SHOW COLUMNS` ob `target_uid` existiert.
- **Kommentar-Metadaten**: `remark_by`/`remark_at` wird nur gesetzt wenn tatsächlich Bemerkungstext vorhanden ist.
- **save_bulk.php**: `functions.php` korrekt eingebunden für `planAreaMap()`/`absenceName()`. `$userNames` Array initialisiert.


## v6.0.0 (20.03.2026)

### Features
- **Rollen & Berechtigungssystem**: Neue Tabelle `role_permissions` mit 14 Berechtigungen pro Rolle. `hasPerm()`-Funktion prüft Berechtigungen. Rollen: Admin, Manager, Teamleiter, Verwaltung, Lager, Techniker, Azubi 1-3.
- **Plan-Entwürfe / Veröffentlichung**: Entwurfs-System mit `plan_drafts`-Tabelle. "Meine Änderungen veröffentlichen" / "Alle veröffentlichen". Draft-Badge mit Anzahl. Verlassen-Modal bei unveröffentlichten eigenen Änderungen.
- **Interne Einträge**: `is_internal`-Flag auf Assignments/Absences/Drafts. Interne Einträge werden nie veröffentlicht und nie im Badge gezählt. Neon-gelb gestrichelte Markierung.
- **Interne Bemerkungen**: Separates Feld `internal_remark` neben öffentlichen Bemerkungen. Nur mit Berechtigung `plan_view_internal` sichtbar. SVG-Schloss-Icon im Tooltip.
- **Changelog-System**: Neue Tabelle `changelog` mit Version, Datum, Inhalt, Typ. Modal in der Navigation "Was gibt es Neues?". Typ-Badges (Feature, Bugfix, etc.).

### Berechtigungen (14)
`plan_view`, `plan_edit`, `plan_publish_own`, `plan_publish_all`, `plan_publish_year`, `plan_view_internal`, `view_erl_column`, `manage_users`, `delete_users`, `manage_settings`, `manage_roles`, `view_gesamtplan_details`, `approve_requests`, `unlock_vacation`

### Datenbank
- Neue Tabellen: `role_permissions`, `plan_drafts`, `changelog`
- Neue Spalten: `is_internal`, `internal_remark`, `remark_by`, `remark_at` auf assignments, absences, plan_drafts
- `entry_type` auf changelog
