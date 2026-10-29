-- Planquadro 0004 – Stammdaten: Kunden, Standorte, Qualifikationen
-- Nur additiv. Einsatzcodes (plan_areas) können optional einem Kunden und
-- einem Standort zugeordnet werden; Mitarbeiter erhalten Qualifikationen.

CREATE TABLE IF NOT EXISTS `customers` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(200) NOT NULL,
  `customer_no`  VARCHAR(50)  NULL,
  `address`      VARCHAR(300) NULL,
  `contact_name` VARCHAR(150) NULL,
  `phone`        VARCHAR(60)  NULL,
  `email`        VARCHAR(190) NULL,
  `notes`        TEXT NULL COMMENT 'Hinweise für Techniker (Zugang, Parken …)',
  `active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_cust_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `locations` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(200) NOT NULL,
  `customer_id` INT NULL,
  `address`     VARCHAR(300) NULL,
  `lat`         DECIMAL(9,6) NULL,
  `lng`         DECIMAL(9,6) NULL,
  `notes`       TEXT NULL,
  `active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_loc_customer` (`customer_id`),
  CONSTRAINT `fk_loc_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qualifications` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(150) NOT NULL,
  `description` VARCHAR(300) NULL,
  `color`       VARCHAR(20) NOT NULL DEFAULT '#64748b',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_qual_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_qualifications` (
  `user_id`          INT NOT NULL,
  `qualification_id` INT NOT NULL,
  `valid_until`      DATE NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `qualification_id`),
  KEY `idx_uq_qual` (`qualification_id`),
  CONSTRAINT `fk_uq_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uq_qual` FOREIGN KEY (`qualification_id`) REFERENCES `qualifications`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `plan_areas` ADD COLUMN `customer_id` INT NULL', 'DO 0')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plan_areas' AND column_name = 'customer_id');
PREPARE op_stmt FROM @s; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `plan_areas` ADD COLUMN `location_id` INT NULL', 'DO 0')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plan_areas' AND column_name = 'location_id');
PREPARE op_stmt FROM @s; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
