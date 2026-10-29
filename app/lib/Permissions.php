<?php
/**
 * Planquadro – Rechte-Katalog
 * --------------------------------------------------------------------------
 * Einzige Quelle für alle Rechte: Schlüssel, Bezeichnung, Beschreibung,
 * Gruppe und die Standardrollen einer Neuinstallation. allPermissions(), die
 * Seite "Rollen & Rechte", die Rechte-Migrationen und der Rechte-Snapshot
 * richten sich danach. Gespeichert werden Rechte weiterhin je Mandanten-
 * Datenbank in role_permissions (Rolle) und permission_user_overrides
 * (Benutzer); maßgeblich ist immer die Datenbank (docs/PERMISSIONS.md).
 *
 * role_bound = true: Recht ersetzt eine früher fest an Rollen gebundene
 * Prüfung (Migration 0006). Damit sich dabei nichts verschiebt, gilt für diese
 * Rechte:
 *   - Administratoren haben sie immer (wie zuvor hasRole('admin')); auf der
 *     Rollen-Seite sind sie für die Administrator-Rolle gesperrt.
 *   - Fehlt die Zeile in role_permissions (Code vor der Migration ausgerollt,
 *     Rolle nachträglich angelegt), gelten die Standardrollen unten – für
 *     eigene Rollen also "nicht erteilt", wie bei der früheren Rollenprüfung.
 * Für alle übrigen Rechte bleibt es bei "fehlt der Eintrag: verweigert".
 */

/** Feste Rollen (System-Rollen). Eigene Rollen je Mandant: Tabelle custom_roles. */
const SYSTEM_ROLES = ['admin', 'manager', 'teamleiter', 'verwaltung', 'lager', 'mitarbeiter', 'azubi_1', 'azubi_2', 'azubi_3'];

/** Gruppen in Anzeige-Reihenfolge */
const PERMISSION_GROUPS = ['Planung', 'Team & Abwesenheiten', 'Verwaltung', 'Betrieb'];

const PERMISSION_CATALOG = [
    /* ---- Planung ---- */
    'plan_view' => ['label' => 'Plan ansehen', 'group' => 'Planung',
        'description' => 'Monats- und Gesamtplan einsehen',
        'roles' => ['admin', 'manager', 'teamleiter', 'verwaltung', 'lager', 'mitarbeiter', 'azubi_1', 'azubi_2', 'azubi_3']],
    'plan_edit' => ['label' => 'Plan bearbeiten', 'group' => 'Planung',
        'description' => 'Einträge im Monatsplan bearbeiten',
        'roles' => ['admin', 'manager', 'teamleiter', 'verwaltung']],
    'plan_publish_own' => ['label' => 'Eigene Änderungen veröffentlichen', 'group' => 'Planung',
        'description' => 'Eigene Planänderungen veröffentlichen',
        'roles' => ['admin', 'manager', 'teamleiter', 'verwaltung']],
    'plan_publish_all' => ['label' => 'Alle Änderungen veröffentlichen', 'group' => 'Planung',
        'description' => 'Änderungen aller Benutzer veröffentlichen',
        'roles' => ['admin', 'manager']],
    'plan_publish_year' => ['label' => 'Jahr veröffentlichen', 'group' => 'Planung',
        'description' => 'Ganzes Jahr auf einmal veröffentlichen',
        'roles' => ['admin', 'manager']],
    'plan_view_internal' => ['label' => 'Interne Einträge sehen', 'group' => 'Planung',
        'description' => 'Als intern markierte Einträge sehen',
        'roles' => ['admin', 'manager', 'teamleiter']],
    'view_erl_column' => ['label' => 'Erl.-Spalte sehen', 'group' => 'Planung',
        'description' => 'Erledigt-Spalte im Plan anzeigen',
        'roles' => ['admin', 'manager', 'teamleiter', 'verwaltung']],
    'unlock_vacation' => ['label' => 'Genehmigten Urlaub entsperren', 'group' => 'Planung',
        'description' => 'Genehmigten Urlaub und Krankheit überschreiben',
        'roles' => ['admin', 'manager']],
    'view_gesamtplan_details' => ['label' => 'Gesamtplan Details sehen', 'group' => 'Planung',
        'description' => 'Details im Gesamtplan einsehen',
        'roles' => ['admin', 'manager', 'teamleiter', 'verwaltung']],
    'use_planning_assistant' => ['label' => 'Planungsassistent nutzen', 'group' => 'Planung',
        'description' => 'Planungsassistent öffnen (auch ohne „Plan bearbeiten“)',
        'roles' => ['admin', 'manager', 'teamleiter', 'verwaltung']],

    /* ---- Team & Abwesenheiten ---- */
    'view_all_employees' => ['label' => 'Alle Mitarbeiter sehen (sonst nur eigenes Team)', 'group' => 'Team & Abwesenheiten',
        'description' => 'Alle Mitarbeiter in der Planung sehen. Ohne dieses Recht sieht der Benutzer nur Mitarbeiter, bei denen er als Vorgesetzter eingetragen ist.',
        'roles' => ['admin', 'verwaltung', 'lager', 'mitarbeiter', 'azubi_1', 'azubi_2', 'azubi_3']],
    'approve_requests' => ['label' => 'Anträge genehmigen', 'group' => 'Team & Abwesenheiten',
        'description' => 'Änderungsanträge genehmigen/ablehnen',
        'roles' => ['admin', 'manager', 'teamleiter']],
    'manage_absences' => ['label' => 'Abwesenheiten erfassen (Modul Abwesenheiten)', 'group' => 'Team & Abwesenheiten',
        'description' => 'Modul Abwesenheiten: Urlaub, Krankheit und Sperrtage über Zeiträume erfassen',
        'roles' => ['admin', 'manager', 'teamleiter', 'verwaltung']],
    'view_absence_details_all' => ['label' => 'Krankheits-/Urlaubsdetails aller Mitarbeiter sehen', 'group' => 'Team & Abwesenheiten',
        'description' => 'Art und Bemerkung von Krankheit und Urlaub bei ALLEN Mitarbeitern sehen (sonst nur bei unterstellten). Gesundheitsdaten – nur sehr gezielt vergeben.',
        'roles' => []],
    'manage_deputations' => ['label' => 'Vertretungen einrichten', 'group' => 'Team & Abwesenheiten',
        'description' => 'Seite Vertretung: eigene Vertretung an Teamleiter, Manager oder Administratoren abgeben',
        'roles' => ['admin', 'manager', 'teamleiter'], 'role_bound' => true],

    /* ---- Verwaltung ---- */
    'manage_team' => ['label' => 'Team & Benutzer verwalten', 'group' => 'Verwaltung',
        'description' => 'Seite Team: Mitarbeiter anlegen und bearbeiten, Rollen und Vorgesetzte zuordnen, Passwörter zurücksetzen (Administrator-Konten und die Administrator-Rolle bleiben Administratoren vorbehalten)',
        'roles' => ['admin', 'manager', 'verwaltung'], 'role_bound' => true],
    'manage_users' => ['label' => 'Mitarbeiter verwalten (ohne Wirkung)', 'group' => 'Verwaltung',
        'description' => 'Historisches Recht, wird nicht ausgewertet – maßgeblich ist „Team & Benutzer verwalten“',
        'roles' => ['admin', 'manager', 'verwaltung']],
    'delete_users' => ['label' => 'Mitarbeiter löschen', 'group' => 'Verwaltung',
        'description' => 'Mitarbeiter endgültig löschen',
        'roles' => ['admin']],
    'manage_config' => ['label' => 'Bereiche, Feiertage & Stammdaten pflegen', 'group' => 'Verwaltung',
        'description' => 'Einsatzbereiche/-codes, Feiertage und Stammdaten (Kunden, Standorte, Qualifikationen) bearbeiten',
        'roles' => ['admin', 'manager', 'verwaltung'], 'role_bound' => true],
    'manage_settings' => ['label' => 'Einstellungen verwalten', 'group' => 'Verwaltung',
        'description' => 'App-Einstellungen, Organisation und Backups verwalten',
        'roles' => ['admin', 'manager']],
    'manage_roles' => ['label' => 'Rollen & Rechte verwalten', 'group' => 'Verwaltung',
        'description' => 'Diese Seite – Rollen & Rechte verwalten',
        'roles' => ['admin']],

    /* ---- Betrieb ---- */
    'bypass_maintenance' => ['label' => 'Im Wartungsmodus weiterarbeiten', 'group' => 'Betrieb',
        'description' => 'Anwendung und App auch während des Wartungsmodus nutzen',
        'roles' => ['admin', 'manager'], 'role_bound' => true],
    'manage_maintenance' => ['label' => 'Wartung planen & Daten bereinigen', 'group' => 'Betrieb',
        'description' => 'Geplante Wartungen anlegen/löschen und Bereinigungen (alte Benachrichtigungen, Anträge, Protokolle) ausführen',
        'roles' => ['admin', 'manager'], 'role_bound' => true],
    'delete_changelog' => ['label' => 'Changelog-Einträge löschen', 'group' => 'Betrieb',
        'description' => 'Einträge im Änderungsprotokoll der Anwendung löschen',
        'roles' => ['admin', 'manager'], 'role_bound' => true],
];

/** Alle Rechte des Katalogs: Schlüssel => Eintrag */
function permissionCatalog(): array { return PERMISSION_CATALOG; }

/** Ersetzt das Recht eine frühere Rollenprüfung (siehe Kopf)? */
function permissionIsRoleBound(string $perm): bool { return !empty(PERMISSION_CATALOG[$perm]['role_bound']); }

/** Standardwert einer Rolle laut Katalog (Neuinstallation) */
function permissionDefault(string $perm, string $role): bool
{
    return in_array($role, PERMISSION_CATALOG[$perm]['roles'] ?? [], true);
}

/** Rechte nach Gruppen: Gruppe => [Schlüssel, …] */
function permissionsByGroup(): array
{
    $out = array_fill_keys(PERMISSION_GROUPS, []);
    foreach (PERMISSION_CATALOG as $k => $p) $out[$p['group']][] = $k;
    return array_filter($out);
}
