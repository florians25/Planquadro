-- Planquadro Plattform 0002 – Sitzungen der Plattform-Administration (optional, PQ_SESSION_STORE=db)
-- Nur additiv. Wird nur genutzt, wenn PQ_SESSION_STORE=db gesetzt ist; mit dem
-- Standard (files) bleibt die Tabelle leer. Gespeichert wird ausschließlich der
-- SHA-256 der Session-ID, nie die ID selbst.

CREATE TABLE IF NOT EXISTS `sessions` (
  `id_hash`       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 der Session-ID – Klartext wird nie gespeichert',
  `data`          MEDIUMBLOB NOT NULL,
  `last_activity` INT UNSIGNED NOT NULL COMMENT 'Unix-Zeit des letzten Zugriffs',
  `created_at`    INT UNSIGNED NOT NULL COMMENT 'Unix-Zeit',
  PRIMARY KEY (`id_hash`),
  KEY `idx_sessions_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
