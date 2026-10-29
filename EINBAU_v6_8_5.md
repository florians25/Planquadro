# Planungsassistent v6.8.5 – Einbau

*Copyright © 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten.*

Dieses Archiv ist der vollständige Projektstand mit fertig eingebautem Assistenten.
Sie müssen **keine Datei mehr von Hand bearbeiten** – nur die Migration einspielen und hochladen.

## Reihenfolge

**1. Backup ziehen.** Einstellungen → Backup. Dauert eine Minute und erspart im Zweifel den Abend.

**2. Migrationen einspielen.** Zuerst `migrations/migrate_v6_8_0.sql` (Tabellen und Berechtigung),
danach `migrations/migrate_v6_8_5.sql` (nur Changelog-Eintrag, keine Schemaänderung).
Beide sind idempotent, ein zweiter Durchlauf schadet nicht.

**3. Dateien hochladen** (siehe Tabelle unten).

**4. Aufrufen.** Der Button „Assistent" steht danach in der Navigation neben „Planung"
und in der Werkzeugleiste der Monatsplanung. Direkte Adresse: `…/planungsassistent`.

Eine Anpassung der `.htaccess` ist **nicht** nötig – die bestehende Regel
`^([a-z_]+)$ → pages/$1.php` greift für `planungsassistent` automatisch.

## Geänderte und neue Dateien

| Datei | Art | Inhalt |
|---|---|---|
| `public/pages/planungsassistent.php` | neu | der Assistent |
| `migrations/migrate_v6_8_0.sql` | neu | Tabellen, Berechtigung, Changelog |
| `migrations/migrate_v6_8_5.sql` | neu | Changelog-Eintrag 6.8.5 |
| `public/includes/footer.php` | geändert | Copyright-Hinweis |
| `public/includes/header.php` | geändert | Navigationseintrag „Assistent" nach „Planung" |
| `public/pages/monatsplanung.php` | geändert | Button „Assistent" in der Werkzeugleiste |
| `public/assets/css/style.css` | geändert | 8 Zeilen für die beiden Einstiege, ans Dateiende angehängt |
| `CHANGELOG.md` | geändert | Eintrag v6.8.0 |

Alle übrigen Dateien sind unverändert. Wer nur die geänderten Dateien einzeln hochladen
möchte: Es sind genau diese sechs.

## Wer den Assistenten sieht

Die Migration legt das Recht `use_planning_assistant` an und vergibt es an alle Rollen,
die bereits `plan_edit` besitzen – standardmäßig Admin, Manager, Teamleiter und Verwaltung.
Änderbar unter Einstellungen → Rollen & Rechte. Ohne dieses Recht (oder `plan_edit`)
erscheint weder der Button noch die Seite.

Geplant werden können ausschließlich Mitarbeiter im eigenen Zuständigkeitsbereich.
Der Assistent nutzt dafür dieselbe Funktion wie die Monatsplanung (`visibleUserIds()`),
inklusive unterstellter Teamleiter und aktiver Vertretungen. Administratoren erscheinen
wie überall sonst nicht im Planungspool.

## Was der Assistent mit den Daten macht

Geschrieben wird ausschließlich nach `plan_drafts`, `planning_runs` und `planning_profiles` –
**nie** direkt nach `assignments` oder `absences`. Veröffentlicht wird weiterhin bewusst in
der Monatsplanung. Als Ist-Stand liest der Assistent veröffentlichte Einträge
(`is_published = 1`) und legt vorhandene Entwürfe darüber – dieselbe Logik wie die Planungsseite.

Der Server prüft jede Übernahme unabhängig vom Browser noch einmal:

- Sperrtage und Berufsschule werden nie überschrieben.
- Urlaub und Krankmeldungen nur bei Einstellung „Einsätze und Abwesenheiten überschreiben"
  **und** Recht `unlock_vacation`.
- Mitarbeiter außerhalb des eigenen Bereichs werden abgewiesen.
- Ein Lauf umfasst höchstens 120 Tage.

Was dabei abgewiesen wurde, steht anschließend im Übernahme-Dialog. Jede Übernahme
lässt sich über denselben Dialog vollständig zurücknehmen.

## Aussehen

Die Seite verwendet die Bausteine Ihrer Anwendung: dieselbe Navigation und Fußzeile, dieselben
Karten, Formularfelder, Dialoge, Meldungen und Schaltflächen, dieselbe Schrittleiste wie beim
Anlegen eines Mitarbeiters und dieselbe Tabellenoptik wie die Monatsplanung. Eigenes CSS gibt
es nur dort, wo die App bisher keinen passenden Baustein hatte – rund 85 Zeilen für das
Bedarfsraster, die Gewichtungsregler und die Zellen der Entwurfsmatrix.

Damit ist der Assistent keine Vollbildseite mehr, sondern eine normale Seite der Anwendung.
Die Navigation bleibt sichtbar, man kann jederzeit wegklicken.

## Getestet

Gegen eine frisch aufgesetzte Datenbank (Schema + alle Migrationen) wurde geprüft:

- Migration läuft durch; Tabellen, Spalte und Rechte werden korrekt angelegt.
- Anmeldung als Teamleiter, Assistent lädt (73 Elemente, gültiges JavaScript, Umlaute korrekt).
- Sichtbarer Personenkreis entspricht dem Team des Teamleiters – nicht mehr, nicht weniger.
- Vorschlag über zwei Wochen: 100 % Abdeckung, Azubi-Regeln und Grenzen eingehalten.
- Übernahme: 30 Entwürfe, Protokolleintrag in `audit_log`, Zurücknehmen entfernt alle 30.
- Vorlage speichern und laden.
- Manipulierter Übernahme-Aufruf mit Urlaubstag, Berufsschultag und teamfremdem Mitarbeiter:
  alle drei serverseitig abgewiesen, nur die legitimen Einträge geschrieben.
- Mitarbeiter ohne Planungsrecht: Seite gesperrt, Hintergrundaufruf beantwortet mit
  HTTP 403 und JSON, Navigationseintrag nicht sichtbar.

## Erster Durchlauf in der Praxis

1. Als Planer anmelden, „Assistent" drücken.
2. Kunde wählen, Zeitraum zwei Wochen, Bedarf 2 Personen pro Tag.
3. Schritt 3: Azubis einbeziehen, nur 2. und 3. Lehrjahr, höchstens einer pro Tag.
4. Bis zum Entwurf durchklicken – der Vorschlag wird berechnet.
5. Eine Zelle anklicken (umbesetzen), eine Spaltenüberschrift anklicken (Alternativen),
   die Konfliktliste durchgehen.
6. Übernehmen, in der Monatsplanung nachsehen, dann „Übernahme zurücknehmen" drücken.
   Der Zustand muss exakt wie vorher sein.

## Wenn etwas klemmt

| Symptom | Ursache |
|---|---|
| Weiße Seite beim Aufruf | Migration nicht gelaufen (`planning_profiles` fehlt) |
| „Sie benötigen die Berechtigung …" | Rolle hat weder `use_planning_assistant` noch `plan_edit` |
| Button fehlt | dieselbe Ursache – oder `header.php` wurde nicht mit hochgeladen |
| 404 beim Klick | `public/pages/planungsassistent.php` liegt nicht im richtigen Ordner |
| „Für Sie sind keine planbaren Mitarbeiter hinterlegt" | keine Zuordnung in `user_supervisors` und kein Recht `view_all_employees` |
| Button ohne Farbe | `style.css` nicht mit hochgeladen (der Assistent funktioniert trotzdem) |
