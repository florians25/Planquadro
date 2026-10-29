-- Planquadro 0007 – Live-Ereignisse (Outbox) für Server-Sent Events
-- Schreibende Vorgänge (Entwürfe, Veröffentlichung, Anträge, Abwesenheitsmodul,
-- Assistent, Präsenz) legen in derselben Transaktion ein Ereignis ab. Die
-- SSE-Endpunkte /api/events (Web) und /api/v1/events (App) lesen ab einer
-- Ereignis-ID weiter (Last-Event-ID). Ereignisse enthalten nur WELCHE Zellen
-- (Mitarbeiter, Tag) betroffen sind – nie Inhalte oder Abwesenheitsarten.
-- Aufräumen: alte Einträge löscht die Anwendung selbst (PQ_EVENTS_RETENTION_HOURS),
-- der jeweils neueste Eintrag bleibt stehen (Cursor-Kontinuität).
-- Nur additiv, mehrfach ausführbar (MariaDB 10.11, MySQL 8.0).

CREATE TABLE IF NOT EXISTS `live_events` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_type`   VARCHAR(30) NOT NULL COMMENT 'cells_changed, published, request_changed, presence',
  `is_published` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = betrifft den veröffentlichten Plan',
  `is_internal`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = nur für plan_view_internal',
  `months`       TEXT NULL COMMENT 'JSON ["2026-11", …]',
  `cells`        MEDIUMTEXT NULL COMMENT 'JSON [[user_id, "Y-m-d"], …]; NULL = ganzer Monat',
  `data`         TEXT NULL COMMENT 'JSON, z. B. Präsenz (Seite, Aktion); ohne Inhalte',
  `actor_id`     INT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_le_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Einmal-Tickets für EventSource in der App (EventSource kann keinen
-- Authorization-Header senden; das Access-Token gehört nie in eine URL).
CREATE TABLE IF NOT EXISTS `live_event_tickets` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `token_hash`  CHAR(64) NOT NULL COMMENT 'SHA-256 – Klartext wird nie gespeichert',
  `user_id`     INT NOT NULL,
  `family_id`   CHAR(32) NOT NULL COMMENT 'Token-Familie (Widerruf wirkt sofort)',
  `tenant`      VARCHAR(40) NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  DATETIME NOT NULL,
  `used_at`     DATETIME NULL,
  UNIQUE KEY `uq_let_hash` (`token_hash`),
  KEY `idx_let_expires` (`expires_at`),
  CONSTRAINT `fk_let_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
