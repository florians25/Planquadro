-- OpsPilot 0005 – API v1 (Mobile-Apps): Refresh-Tokens, Push-Geräte, Push-Warteschlange
-- Nur additiv.

CREATE TABLE IF NOT EXISTS `api_refresh_tokens` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT NOT NULL,
  `family_id`   CHAR(32) NOT NULL COMMENT 'alle Rotationen eines Logins',
  `token_hash`  CHAR(64) NOT NULL COMMENT 'SHA-256 des Tokens – Klartext wird nie gespeichert',
  `device_name` VARCHAR(100) NULL,
  `auth_method` VARCHAR(20) NOT NULL DEFAULT 'password',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  DATETIME NOT NULL,
  `used_at`     DATETIME NULL,
  `revoked_at`  DATETIME NULL,
  UNIQUE KEY `uq_art_hash` (`token_hash`),
  KEY `idx_art_family` (`family_id`),
  KEY `idx_art_user` (`user_id`),
  CONSTRAINT `fk_art_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_devices` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT NOT NULL,
  `platform`    VARCHAR(10) NOT NULL COMMENT 'ios, android, web',
  `provider`    VARCHAR(10) NOT NULL COMMENT 'fcm, apns, ntfy',
  `token`       VARCHAR(512) NOT NULL,
  `token_hash`  CHAR(64) NOT NULL,
  `app_version` VARCHAR(30) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pd_token` (`provider`, `token_hash`),
  KEY `idx_pd_user` (`user_id`),
  CONSTRAINT `fk_pd_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_queue` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT NOT NULL,
  `title`       VARCHAR(120) NOT NULL,
  `body`        VARCHAR(300) NOT NULL,
  `data`        TEXT NULL COMMENT 'JSON, ohne Abwesenheitsgründe',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `next_try_at` DATETIME NULL,
  `attempts`    INT NOT NULL DEFAULT 0,
  `sent_at`     DATETIME NULL,
  `last_error`  VARCHAR(300) NULL,
  KEY `idx_pq_pending` (`sent_at`, `next_try_at`),
  CONSTRAINT `fk_pq_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
