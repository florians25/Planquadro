-- Planquadro 0002 – Änderungsprotokoll je Planzelle (Mitarbeiter, Tag)
-- Jede Änderung an Entwürfen und jede Veröffentlichung wird mit altem und
-- neuem Stand, Benutzer, Zeitpunkt und Quelle festgehalten. Nur additiv.

CREATE TABLE IF NOT EXISTS `plan_change_log` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT NOT NULL COMMENT 'betroffener Mitarbeiter',
  `plan_date`   DATE NOT NULL,
  `action`      VARCHAR(30) NOT NULL COMMENT 'draft_set, draft_clear, remark, publish, overwrite, approve, absence_module',
  `old_state`   TEXT NULL COMMENT 'JSON',
  `new_state`   TEXT NULL COMMENT 'JSON',
  `changed_by`  INT NULL,
  `changed_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source`      VARCHAR(30) NOT NULL DEFAULT 'web',
  `request_id`  CHAR(16) NULL,
  KEY `idx_pcl_cell` (`user_id`, `plan_date`),
  KEY `idx_pcl_time` (`changed_at`),
  KEY `idx_pcl_by` (`changed_by`, `changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
