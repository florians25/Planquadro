-- Planquadro 0006 – früher fest an Rollen gebundene Prüfungen als Rechte
-- (Rechte-Katalog app/lib/Permissions.php, Einträge mit role_bound).
--
-- Bildet exakt die bisherigen Rollenbindungen ab – ohne Privilegienausweitung:
--   manage_team         admin, manager, verwaltung   (vorher canManageUsers)
--   manage_config       admin, manager, verwaltung   (vorher canManageConfig)
--   bypass_maintenance  admin, manager               (Wartungsmodus-Ausnahme)
--   manage_maintenance  admin, manager               (api/maintenance, api/cleanup)
--   delete_changelog    admin, manager               (api/changelog delete)
--   manage_deputations  admin, manager, teamleiter   (Seite Vertretung)
-- Alle übrigen Rollen – auch eigene Rollen, unabhängig von ihrer Basisrolle –
-- erhalten granted = 0, wie bei der bisherigen Rollenprüfung.
--
-- Nur INSERT IGNORE: Bestehende Zeilen in role_permissions und alle Einträge in
-- permission_user_overrides bleiben unverändert; mehrfach ausführbar.
-- MariaDB 10.3+ und MySQL 5.7/8.0.
--
-- Rückweg (Code-Rollback genügt, der ältere Code wertet die Zeilen nicht aus):
--   DELETE FROM role_permissions WHERE permission IN ('manage_team','manage_config',
--     'bypass_maintenance','manage_maintenance','delete_changelog','manage_deputations');
--   DELETE FROM permission_user_overrides WHERE permission IN (…dieselben…);
--   DELETE FROM schema_migrations WHERE version = 'pq_0006_role_bound_permissions';

INSERT IGNORE INTO `role_permissions` (`role`, `permission`, `granted`) VALUES
  ('admin', 'manage_team', 1), ('manager', 'manage_team', 1), ('verwaltung', 'manage_team', 1),
  ('teamleiter', 'manage_team', 0), ('lager', 'manage_team', 0), ('mitarbeiter', 'manage_team', 0),
  ('azubi_1', 'manage_team', 0), ('azubi_2', 'manage_team', 0), ('azubi_3', 'manage_team', 0),

  ('admin', 'manage_config', 1), ('manager', 'manage_config', 1), ('verwaltung', 'manage_config', 1),
  ('teamleiter', 'manage_config', 0), ('lager', 'manage_config', 0), ('mitarbeiter', 'manage_config', 0),
  ('azubi_1', 'manage_config', 0), ('azubi_2', 'manage_config', 0), ('azubi_3', 'manage_config', 0),

  ('admin', 'bypass_maintenance', 1), ('manager', 'bypass_maintenance', 1), ('verwaltung', 'bypass_maintenance', 0),
  ('teamleiter', 'bypass_maintenance', 0), ('lager', 'bypass_maintenance', 0), ('mitarbeiter', 'bypass_maintenance', 0),
  ('azubi_1', 'bypass_maintenance', 0), ('azubi_2', 'bypass_maintenance', 0), ('azubi_3', 'bypass_maintenance', 0),

  ('admin', 'manage_maintenance', 1), ('manager', 'manage_maintenance', 1), ('verwaltung', 'manage_maintenance', 0),
  ('teamleiter', 'manage_maintenance', 0), ('lager', 'manage_maintenance', 0), ('mitarbeiter', 'manage_maintenance', 0),
  ('azubi_1', 'manage_maintenance', 0), ('azubi_2', 'manage_maintenance', 0), ('azubi_3', 'manage_maintenance', 0),

  ('admin', 'delete_changelog', 1), ('manager', 'delete_changelog', 1), ('verwaltung', 'delete_changelog', 0),
  ('teamleiter', 'delete_changelog', 0), ('lager', 'delete_changelog', 0), ('mitarbeiter', 'delete_changelog', 0),
  ('azubi_1', 'delete_changelog', 0), ('azubi_2', 'delete_changelog', 0), ('azubi_3', 'delete_changelog', 0),

  ('admin', 'manage_deputations', 1), ('manager', 'manage_deputations', 1), ('teamleiter', 'manage_deputations', 1),
  ('verwaltung', 'manage_deputations', 0), ('lager', 'manage_deputations', 0), ('mitarbeiter', 'manage_deputations', 0),
  ('azubi_1', 'manage_deputations', 0), ('azubi_2', 'manage_deputations', 0), ('azubi_3', 'manage_deputations', 0);

-- Eigene Rollen (custom_roles) und Rollen, die nur in role_permissions vorkommen:
-- ausdrücklich nicht erteilt.
INSERT IGNORE INTO `role_permissions` (`role`, `permission`, `granted`)
SELECT r.`role`, p.`permission`, 0
FROM (SELECT `role_key` AS `role` FROM `custom_roles`
      UNION SELECT DISTINCT `role` FROM `role_permissions`) r
CROSS JOIN (SELECT 'manage_team' AS `permission` UNION ALL SELECT 'manage_config' UNION ALL SELECT 'bypass_maintenance'
            UNION ALL SELECT 'manage_maintenance' UNION ALL SELECT 'delete_changelog' UNION ALL SELECT 'manage_deputations') p
WHERE r.`role` NOT IN ('admin', 'manager', 'teamleiter', 'verwaltung', 'lager', 'mitarbeiter', 'azubi_1', 'azubi_2', 'azubi_3');
