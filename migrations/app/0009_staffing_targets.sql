-- Planquadro 0009 – Soll-Besetzung je Einsatzbereich und Wochentag
-- Optional: Wie viele Personen sollen an einem Wochentag mindestens in einem
-- Bereich eingeplant sein? Die Monatsplanung zeigt in der Abdeckungszeile
-- eine dezente Warnung bei Unterbesetzung. Ohne Einträge ändert sich nichts.
-- Nur additiv, idempotent (MariaDB 10.11, MySQL 8.0).

CREATE TABLE IF NOT EXISTS `plan_area_targets` (
  `plan_area_id` INT NOT NULL,
  `weekday`      TINYINT NOT NULL COMMENT '1 = Montag … 7 = Sonntag (ISO-8601)',
  `min_staff`    SMALLINT NOT NULL DEFAULT 0,
  `updated_by`   INT NULL,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`plan_area_id`, `weekday`),
  CONSTRAINT `fk_pat_area` FOREIGN KEY (`plan_area_id`) REFERENCES `plan_areas`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
