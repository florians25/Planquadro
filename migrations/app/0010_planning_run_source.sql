-- Planquadro 0010 – Planungsassistent: Übernahme ↔ gespeicherte Planung
-- Eine Übernahme (planning_runs mit is_saved = 0, status 'uebernommen') merkt
-- sich, aus welcher gespeicherten Planung sie stammt. Damit zeigt die
-- Übersicht „übernommen“ an der Planung und „Rückgängig“ setzt den Hinweis
-- wieder zurück.
--
-- Nur additiv: eine NULL-fähige Spalte und ein Index. Bestehende Läufe und
-- gespeicherte Planungen bleiben unverändert (source_run_id = NULL).
-- Mehrfach ausführbar (MariaDB 10.11, MySQL 8.0).
--
-- Rückweg (der ältere Code liest die Spalte nicht):
--   ALTER TABLE planning_runs DROP INDEX idx_pr_source, DROP COLUMN source_run_id;
--   DELETE FROM schema_migrations WHERE version = 'pq_0010_planning_run_source';

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `planning_runs` ADD COLUMN `source_run_id` INT NULL DEFAULT NULL COMMENT ''Übernahme: gespeicherte Planung, aus der sie stammt''',
  'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'planning_runs' AND column_name = 'source_run_id');
PREPARE op_stmt FROM @s; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `planning_runs` ADD INDEX `idx_pr_source` (`source_run_id`, `status`)',
  'DO 0')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'planning_runs' AND index_name = 'idx_pr_source');
PREPARE op_stmt FROM @s; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
