# Berechtigungsmodell

> **Stand Planquadro 7.0.0:** Rechte stehen als Katalog im Code
> (`app/lib/Permissions.php`), sechs bisher rollengebundene Prüfungen sind Rechte
> (Abschnitt 7, Migration 0006). Abschnitte 1–6 beschreiben den Ausgangszustand v6.9.0,
> gegen den der Rechte-Snapshot (`tests/fixtures/permissions_snapshot.json`) geprüft wird;
> die Wirkung für alle bestehenden Rollen ist unverändert.

# Bestehendes Berechtigungsmodell (Einsatzplanung v6.9.0)

Stand: 29.10.2026. Dieses Dokument hält den **Ist-Zustand** fest, bevor irgendetwas am
Rechtesystem geändert wird. Es ist die Referenz für die Rechte-Migration
([`MIGRATION_PLAN.md`](MIGRATION_PLAN.md), Abschnitt 4): Nach jeder Migration muss die
effektive Rechtematrix jedes Benutzers mit dem hier beschriebenen Verhalten übereinstimmen –
abgesehen von den ausdrücklich als Fehlerbehebung markierten Punkten in Abschnitt 6.

## 1. Bausteine

Eine Prüfung setzt sich aus bis zu vier unabhängigen Teilen zusammen:

1. **Rolle** – `users.role`. Feste Rollen: `admin`, `manager`, `teamleiter`, `verwaltung`,
   `lager`, `mitarbeiter` (Anzeige „Techniker“), `azubi_1`, `azubi_2`, `azubi_3`. Dazu
   kommen eigene Rollen aus `custom_roles` (Schlüssel, Bezeichnung, Farbe, Basisrolle).
2. **Recht** – `hasPerm($perm)` in `public/includes/auth.php`:
   1. Benutzer-Override in `permission_user_overrides` (granted 0/1) hat Vorrang,
   2. sonst `role_permissions` für die Rolle,
   3. fehlt die Tabelle, greift eine fest codierte Legacy-Tabelle,
   4. fehlt der Eintrag: **verweigert**.
   Es gibt **keine** automatische Admin-Ausnahme – der Admin hat alle Rechte nur, weil sie
   in `role_permissions` gesetzt sind.
3. **Team-Scope** – `visibleUserIds()` / `canEditUser($uid)` in `includes/functions.php`:
   - `admin` sieht immer alle.
   - Sonst entscheidet das Recht `view_all_employees` (Override > Rolle > Standard).
     Standard ohne Eintrag: alle Rollen sehen alle, **außer** `manager` und `teamleiter`.
   - Eingeschränkte Benutzer sehen sich selbst plus alle direkt und **transitiv**
     unterstellten Mitarbeiter (`user_supervisors`, bis zu 4 Vorgesetzte je Mitarbeiter).
   - **Vertretung** (`deputations`, Zeitraum `start_date..end_date`): Die Vertretung erhält
     zusätzlich den Scope der vertretenen Person. Im Modul Abwesenheiten erbt sie außerdem
     deren Recht `manage_absences`.
4. **Vertraulichkeit** – `canSeeAbsenceDetails($uid)`: Art und Bemerkung von Krankheit
   (`krank_*`) und Urlaub (`urlaub_*`) sehen nur der Mitarbeiter selbst, `admin`, sowie
   `manager`/`teamleiter` für Mitarbeiter in ihrem Scope. Alle anderen sehen nur „A“
   (abwesend). Gleitzeit, Gesperrt und „offen“ sind nicht vertraulich.

## 2. Rechte und Standardzuordnung

Werte nach Einspielen aller Migrationen bis v6.9.0 (Neuinstallation). Bestehende
Installationen können abweichen, wenn Rechte über „Rollen & Rechte“ geändert wurden –
maßgeblich ist dann die Datenbank.

| Recht | admin | manager | teamleiter | verwaltung | lager | mitarbeiter | azubi_1 | azubi_2 | azubi_3 |
|---|---|---|---|---|---|---|---|---|---|
| `approve_requests` | ✔ | ✔ | ✔ | – | – | – | – | – | – |
| `delete_users` | ✔ | – | – | – | – | – | – | – | – |
| `manage_absences` | ✔ | ✔ | ✔ | ✔ | – | – | – | – | – |
| `manage_roles` | ✔ | – | – | – | – | – | – | – | – |
| `manage_settings` | ✔ | ✔ | – | – | – | – | – | – | – |
| `manage_users` | ✔ | ✔ | – | ✔ | – | – | – | – | – |
| `plan_edit` | ✔ | ✔ | ✔ | ✔ | – | – | – | – | – |
| `plan_publish_all` | ✔ | ✔ | – | – | – | – | – | – | – |
| `plan_publish_own` | ✔ | ✔ | ✔ | ✔ | – | – | – | – | – |
| `plan_publish_year` | ✔ | ✔ | – | – | – | – | – | – | – |
| `plan_view` | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `plan_view_internal` | ✔ | ✔ | ✔ | – | – | – | – | – | – |
| `unlock_vacation` | ✔ | ✔ | – | – | – | – | – | – | – |
| `use_planning_assistant` | ✔ | ✔ | ✔ | ✔ | – | – | – | – | – |
| `view_all_employees` | ✔ | – | – | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `view_erl_column` | ✔ | ✔ | ✔ | ✔ | – | – | – | – | – |
| `view_gesamtplan_details` | ✔ | ✔ | ✔ | ✔ | – | – | – | – | – |

Hinweise:
- `manage_absences` und `use_planning_assistant` werden geprüft, fehlten aber bis v6.9.0
  in `allPermissions()` und waren deshalb auf der Seite „Rollen & Rechte“ nicht pflegbar
  (seit Phase 2 behoben, Werte unverändert übernommen).
- `manage_users` wird gespeichert, aber **nicht ausgewertet** – die Benutzerverwaltung
  hängt fest an der Rolle (siehe 3.).

### Ergänzungen seit Phase 2/3

| Recht | Standard | Wirkung |
|---|---|---|
| `view_absence_details_all` | nur Admin (Rolle hat ohnehin Einsicht) | Art und Bemerkung von Krankheit/Urlaub bei **allen** Mitarbeitern sehen. Ohne dieses Recht sehen Manager/Teamleiter Gesundheitsdaten nur noch für tatsächlich unterstellte Mitarbeiter (direkt, transitiv, Vertretung) – die Planungsfreigabe `view_all_employees` öffnet sie nicht mehr (Entscheidung E-2). |

Die API v1 (`docs/API.md`) nutzt exakt dieselben Funktionen (`hasPerm`, `canEditUser`,
`visibleUserIds`, `canSeeAbsenceDetails`). Mitarbeiter sehen dort nur veröffentlichte Stände.

## 3. Fest an Rollen gebundene Prüfungen

| Prüfung | Rollen | Wirkt auf |
|---|---|---|
| `canManageUsers()` | admin, manager, verwaltung | Seite und Aktionen „Team“ (`pages/mitarbeiter.php`) |
| `canManageConfig()` | admin, manager, verwaltung | Bereiche/Einsatzcodes, Feiertage, Stammdaten (Kunden, Standorte, Qualifikationen) |
| Wartungsmodus-Ausnahme | admin, manager | arbeiten während Wartung weiter |
| `api/cleanup`, `api/maintenance` | admin, manager | Bereinigung, geplante Wartung |
| Changelog löschen | admin, manager | `api/changelog` |
| Urlaubsübersicht | admin, manager, teamleiter | `pages/urlaubsplanung.php`, Navigation |
| Vertretung anlegen | admin, manager, teamleiter | `pages/vertretung.php` |
| „Meine Mitarbeiter“-Filter | manager, teamleiter | Gesamtplan |
| Planungsassistent teamübergreifend | admin, manager immer; teamleiter nur mit Einstellung `epa_cross_team` | `pages/planungsassistent.php` |

## 4. Seiten und Endpunkte

### 4.1 Seiten (Zugang)

| Seite | Zugang |
|---|---|
| dashboard, mein_plan, gesamtplan, jahresuebersicht, profil, datenschutz | angemeldet |
| monatsplanung | `plan_edit` |
| planungsassistent | `use_planning_assistant` oder `plan_edit` |
| abwesenheiten | `manage_absences` oder `plan_edit` oder aktive Vertretung eines Berechtigten |
| genehmigungen | `plan_edit` (Navigation zusätzlich: Einstellung aktiv + `approve_requests`) |
| urlaubsplanung | Rolle admin/manager/teamleiter |
| vertretung | Rolle admin/manager/teamleiter |
| mitarbeiter | Rolle admin/manager/verwaltung |
| planbereiche, feiertage | Rolle admin/manager/verwaltung |
| einstellungen, organisation | `manage_settings` |
| stammdaten | Rolle admin/manager/verwaltung + Modul `masterdata` |
| rollen | `manage_roles` |

### 4.2 API-Endpunkte

| Endpunkt | Aktion | Prüfung (Ist) |
|---|---|---|
| `api/save_bulk` | Zellen als Entwurf setzen/leeren, Konfliktvorschau | `plan_edit`; je Zelle `canEditUser`; Urlaub/Krank überschreiben nur mit `unlock_vacation` |
| `api/save_remark` | Bemerkungen (öffentlich/intern) | `plan_edit`; je Zelle `canEditUser` |
| `api/publish` | Monat (eigene/alle), Jahr | `plan_edit` + `plan_publish_own` / `plan_publish_all` / `plan_publish_year` |
| `api/live_status` | ping, cells, leave, lock_row | angemeldet (keine Scope-Filterung der Einsätze, Abwesenheiten maskiert) |
| `api/change_requests` | submit_batch | angemeldet; ohne `plan_edit` nur für sich selbst, keine Krank-/Sperrtage |
| | approve_batch, reject_batch | `plan_edit` + Scope (`approve_requests` wird **nicht** geprüft) |
| | week_context | `plan_edit` (kein Scope) |
| | list, pending_count, pending_map, dashboard_stats, my_requests | angemeldet, Scope-gefiltert bzw. eigene |
| `api/save_wots` | list/add/update/delete | `plan_edit` (kein Scope) |
| `api/save_month_data` | Stunden/Erl. | `plan_edit` (kein Scope) |
| `api/planning_plus` (7.0.0) | cell_info (Entwurf, Änderungsverlauf einer Zelle) | `plan_edit` + `canEditUser`; Urlaub/Krank nur mit `canSeeAbsenceDetails`, interne Einträge nur mit `plan_view_internal` |
| | targets / targets_save (Soll-Besetzung) | `plan_edit` / `manage_config` |
| `api/backup` | create/list/download/restore/delete/setup_cron/get_key | `manage_settings`; `cron_backup` per Schlüssel ohne Login |
| `api/announcements` | active | **ohne Login** |
| | list/create/update/delete/toggle | `manage_settings` |
| `api/notifications` | count/list/mark_read | angemeldet, nur eigene |
| `api/changelog` | list / add / delete | angemeldet / `plan_edit` / Rolle admin, manager |
| `api/feiertage` | import | `plan_edit` |
| `api/cleanup`, `api/maintenance` | diverse | Rolle admin, manager |
| `api/change_password` | eigenes Passwort | angemeldet + altes Passwort |
| `api/ical` | Feed | Token je Benutzer, ohne Session |

### 4.3 AJAX-Aktionen in Seiten

| Seite | Aktionen → zusätzliche Prüfung (Ist) |
|---|---|
| mitarbeiter | save, toggle, save_supervisors, promote, save_subordinates, reset_pw → keine; delete_user → `delete_users` |
| rollen | save_perms, add_override, remove_override, get_overrides, nobody_except, create_role, delete_role → `manage_roles` + CSRF |
| planungsassistent | generate, candidates, suggest, apply_preview, apply → Scope; run_list → eigene Läufe (alle mit `manage_settings`); plan_* → Eigentümer/freigegeben; undo, profile_* → Eigentümer oder `manage_settings`; Urlaub überschreiben → `unlock_vacation`; intern → `plan_view_internal` |
| abwesenheiten | list, impact, save, delete → Scope |
| einstellungen | save_settings, import_holidays, get_audit_log, clear_audit_log, get_login_stats, clear_lockouts → `manage_settings` |
| vertretung | create (eigene Vertretung), delete (eigene; admin alle) |
| planbereiche | save, del, reorder → `canManageConfig` |
| feiertage | save, range, del, del_range, import_api, import_ferien → `canManageConfig` |
| profil | generate_cal_token, revoke_cal_token → **ohne** `requireLogin()` |

## 5. Weitere Schutzmechanismen

- Login-Sperre nach `max_login_attempts` Fehlversuchen (Benutzername **oder** IP) für
  `lockout_duration_minutes`; Rechen-Captcha ab 2 Fehlversuchen.
- Passwortablauf (`password_expiry_days`, Standard 30), Mindestlänge und Zeichenklassen
  konfigurierbar; Einmalpasswort erzwingt Wechsel beim ersten Login.
- Session-Rate-Limit für einige APIs (`apiRateLimit`).
- Audit-Log: Login (Erfolg/Fehlschlag), Passwortwechsel, verdächtige Aktivitäten.

## 6. Abweichungen zwischen beabsichtigtem und tatsächlichem Verhalten

Diese Punkte sind **Fehler**, keine Rechte, die erhalten werden müssen. P-1 bis P-5 und
P-7 sind in Phase 2 behoben (Tests in `tests/Integration/SecurityTest.php` und
`DataIntegrityTest.php`); P-6 wartet auf eine fachliche Entscheidung.

| # | Beabsichtigt (Doku/UI) | Tatsächlich | Wirkung der Behebung |
|---|---|---|---|
| P-1 | Benutzerverwaltung pflegt Mitarbeiter | Manager/Verwaltung können jedem Konto – auch dem eigenen – die Rolle `admin` geben und Admin-Passwörter setzen | Admin-Rolle und Admin-Konten nur noch durch Admins änderbar; eigene Rolle nicht änderbar; Rollen mit `manage_roles` vergibt nur, wer es selbst hat. **Unverändert:** Manager/Verwaltung pflegen weiterhin alle übrigen Konten und Rollen (keine Team-Einschränkung) – eine strengere Regel ist Teil der Rechte-Migration in Phase 3. |
| P-2 | Anträge genehmigt, wer `approve_requests` hat (Navigation) | API prüft nur `plan_edit` → Verwaltung kann per Direktaufruf genehmigen | Genehmigen/Ablehnen erfordert `approve_requests` |
| P-3 | Team-Scope gilt für alle Planungsdaten | WOTs, Stunden/Erl. und Wochenkontext ohne Scope-Prüfung | Scope-Prüfung ergänzt |
| P-4 | Vertraulichkeit Krankheit/Urlaub | Liste im Abwesenheitsmodul überträgt die Art im Feld `key` | Feld wird maskiert |
| P-5 | Genehmigter Urlaub/Krankheit nur mit `unlock_vacation` überschreibbar | Abwesenheitsmodul löscht sie ohne Prüfung | Prüfung ergänzt |
| P-6 | Teamübergreifende Planung im Assistenten nur mit Freigabe | Manager immer, auch ohne `view_all_employees` | **offen – Entscheidung des Product Owners nötig** (siehe Roadmap) |
| P-7 | Profil-Aktionen nur für angemeldete Benutzer | auch während erzwungenem Passwortwechsel | `requireLogin()` ergänzt |

## 7. Rechte-Katalog und Migration 0006 (Planquadro 7.0.0)

### 7.1 Katalog

`app/lib/Permissions.php` enthält für jedes Recht Schlüssel, Bezeichnung, Beschreibung,
Gruppe (Planung · Team & Abwesenheiten · Verwaltung · Betrieb) und die Standardrollen einer
Neuinstallation. `allPermissions()`, die Seite „Rollen & Rechte“, `create_role` und der
Rechte-Snapshot verwenden ihn. Ein Test stellt sicher, dass die Standardrollen im Katalog
exakt den Werten einer Neuinstallation entsprechen und kein Recht in der Datenbank fehlt.
Gespeichert wird weiterhin pro Mandanten-Datenbank (`role_permissions`,
`permission_user_overrides`); maßgeblich ist die Datenbank.

`manage_users` bleibt im Katalog, wird aber weiterhin nicht ausgewertet (Bezeichnung
„ohne Wirkung“). Es wurde nicht für die Team-Seite verwendet, weil Bestandsinstallationen
es abweichend gesetzt haben können – das hätte Rechte verschoben.

### 7.2 Früher rollengebundene Prüfungen

| Recht | Prüfung vorher | Standard (= vorher) |
|---|---|---|
| `manage_team` | `canManageUsers()` – Seite Team, Navigation | admin, manager, verwaltung |
| `manage_config` | `canManageConfig()` – Bereiche, Feiertage, Stammdaten | admin, manager, verwaltung |
| `bypass_maintenance` | `hasRole('admin','manager')` im Wartungsmodus (Web, API v1) | admin, manager |
| `manage_maintenance` | `api/maintenance`, `api/cleanup` | admin, manager |
| `delete_changelog` | `api/changelog` delete | admin, manager |
| `manage_deputations` | Seite Vertretung, Navigation | admin, manager, teamleiter |

Regeln, damit sich nichts verschiebt (`role_bound` im Katalog):
- **Administratoren haben diese Rechte immer** – wie bei `hasRole('admin', …)`; weder
  Rollen-Schalter noch Benutzer-Override entziehen sie. Auf der Rollen-Seite sind sie für die
  Administrator-Rolle gesperrt (ebenso wie bisher `manage_roles`).
- **Fehlt die Zeile** in `role_permissions` (Code vor der Migration, Rolle ohne Eintrag),
  gilt der Katalog-Standard. Eigene Rollen haben im Standard keine dieser Rechte – wie
  vorher, weil die Rollenprüfung nur den exakten Schlüssel kannte.
- Für alle anderen Rechte bleibt es bei „fehlt der Eintrag: verweigert“.
- Neu ist nur die **Einstellbarkeit**: Wer „Rollen & Rechte verwalten“ hat, kann die Rechte
  anderen Rollen oder einzelnen Personen geben oder entziehen.

Bewusst **weiter an Rollen gebunden** (Wirkung auf Gesundheitsdaten oder auf das
Verhältnis Vorgesetzter/Mitarbeiter; Umwandlung braucht eine fachliche Entscheidung):
Urlaubsübersicht (admin, manager, teamleiter – zeigt Kranktage), Navigationsblock
Abwesenheiten, Vertraulichkeit `canSeeAbsenceDetails`, „alle sehen“ für Administratoren,
„Meine Mitarbeiter“-Filter, Planungsassistent teamübergreifend (P-6), Auswahl möglicher
Vorgesetzter/Vertretungen (Zielrolle), SSO-Konfiguration und Passwort-Notfallzugang nur
für Administratoren, Löschen fremder Vertretungen nur durch Administratoren.

### 7.3 Eigene Rollen je Mandant

Auf „Rollen & Rechte“: anlegen (Rechte einer bestehenden Rolle werden kopiert – die
wirksamen Werte, inkl. der Rechte aus 7.2), **umbenennen** (Name, Farbe; der Schlüssel bleibt,
weil er bei Benutzern und Rechten gespeichert ist) und löschen (nur ohne zugeordnete
Benutzer). System-Rollen können weder umbenannt noch gelöscht werden; die Basisrolle muss
existieren. Hinweis: Eine **neu** auf Basis „Manager“ angelegte Rolle erhält damit auch
„Team & Benutzer verwalten“ – Rollen, die vor der Migration angelegt wurden, nicht.

### 7.4 Rollen aus IdP-Gruppen (OIDC)

`security.oidc` (TenantConfig, Seite Organisation – nur Administratoren):

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `group_roles_enabled` | `false` | Zuordnung aktiv; aus = Rolle bleibt, wie in Planquadro gepflegt |
| `groups_claim` | `groups` | Claim mit den Gruppen (Liste oder durch Komma/Leerzeichen getrennt) |
| `group_roles` | `[]` | `[{"group": "…", "role": "…"}]`, die erste passende Zeile gilt |

Bei jeder SSO-Anmeldung (Web und App) wird die Rolle gesetzt und im Audit-Log
(`oidc_role_mapped`) vermerkt. Ohne Treffer bleibt die Rolle. Sicherheit: Die Rolle
`admin` und Rollen mit `manage_roles` werden nie vergeben (beim Speichern abgelehnt und
beim Login erneut geprüft); Konten mit diesen Rollen ändert der IdP nie. Eine unzulässige
erste passende Zeile lässt die Rolle unverändert (kein Ausweichen auf spätere Zeilen).
Benutzer werden weiterhin nicht automatisch angelegt.

