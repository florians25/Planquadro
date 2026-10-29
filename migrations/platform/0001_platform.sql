-- OpsPilot Plattform-Datenbank (nur SaaS): Mandanten, Domains, Plattform-Admins,
-- zentrales Protokoll und Backup-Status. Fachdaten liegen ausschließlich in den
-- Mandanten-Datenbanken.

CREATE TABLE IF NOT EXISTS `tenants` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `slug`       VARCHAR(40)  NOT NULL UNIQUE,
  `name`       VARCHAR(200) NOT NULL,
  `db_name`    VARCHAR(64)  NOT NULL UNIQUE,
  `status`     ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  `plan`       VARCHAR(40)  NOT NULL DEFAULT 'standard',
  `notes`      TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tenant_domains` (
  `host`       VARCHAR(190) NOT NULL PRIMARY KEY,
  `tenant_id`  INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_td_tenant` (`tenant_id`),
  CONSTRAINT `fk_td_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_admins` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  `last_login`    TIMESTAMP NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_audit` (
  `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
  `admin`      VARCHAR(100) NULL,
  `action`     VARCHAR(60)  NOT NULL,
  `details`    VARCHAR(500) NULL,
  `ip_hash`    CHAR(16) NULL COMMENT 'gekürzter Hash, keine Klartext-IP',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pa_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backup_runs` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `scope`       VARCHAR(64) NOT NULL COMMENT 'Mandanten-Slug oder _platform/_files',
  `kind`        VARCHAR(20) NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled, manual, restore_test',
  `status`      ENUM('running','ok','failed','warning') NOT NULL DEFAULT 'running',
  `archive`     VARCHAR(255) NULL,
  `size_bytes`  BIGINT NULL,
  `sha256`      CHAR(64) NULL,
  `targets`     VARCHAR(500) NULL,
  `message`     TEXT NULL,
  `started_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` TIMESTAMP NULL,
  KEY `idx_br_scope` (`scope`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
