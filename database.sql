-- Planquadro (Einsatzplanung) – Basisschema v3.1; danach migrations/ in Versionsreihenfolge einspielen
CREATE DATABASE IF NOT EXISTS `einsatzplanung` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `einsatzplanung`;

DROP TABLE IF EXISTS `publish_log`,`absences`,`assignments`,`user_month_data`,`user_wots`,`holidays`,`plan_areas`,`users`;

CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `role` ENUM('mitarbeiter','teamleiter','manager','verwaltung','azubi_1','azubi_2','azubi_3') NOT NULL DEFAULT 'mitarbeiter',
  `vacation_days` DECIMAL(5,1) NOT NULL DEFAULT 30.0,
  `snow_id` VARCHAR(50) DEFAULT '',
  `os_level` VARCHAR(20) DEFAULT '',
  `weekend_allowed` TINYINT(1) NOT NULL DEFAULT 0,
  `training_profession` VARCHAR(20) DEFAULT '' COMMENT 'Ausbildungsberuf: KM, ITSE, FISI, DUS-WI, DUS-KM',
  `primary_area_id` INT DEFAULT NULL COMMENT 'Haupt-Einsatzbereich',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `initial_password` VARCHAR(100) DEFAULT NULL,
  `exit_date` DATE DEFAULT NULL,
  `entry_date` DATE DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- FK for primary_area_id added after plan_areas creation via ALTER below

CREATE TABLE `plan_areas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `short_code` VARCHAR(10) NOT NULL,
  `color` VARCHAR(20) NOT NULL DEFAULT '#3b82f6',
  `fg_color` VARCHAR(20) NOT NULL DEFAULT '#ffffff',
  `area_type` ENUM('kunde','allgemein') NOT NULL DEFAULT 'kunde',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `user_wots` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `wot_number` VARCHAR(50) NOT NULL,
  `link_url` VARCHAR(500) DEFAULT '',
  `color` VARCHAR(20) DEFAULT '#3b82f6',
  `remarks` TEXT,
  `year` INT NOT NULL,
  `month` INT NOT NULL,
  `visible_to_user` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX (`user_id`,`year`,`month`)
) ENGINE=InnoDB;

CREATE TABLE `user_month_data` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `year` INT NOT NULL,
  `month` INT NOT NULL,
  `visible_to_user` TINYINT(1) NOT NULL DEFAULT 1,
  `hours` DECIMAL(6,2) DEFAULT NULL,
  `snow_checked` TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY (`user_id`,`year`,`month`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `holidays` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `holiday_date` DATE NOT NULL,
  `holiday_type` ENUM('feiertag','ferien') NOT NULL DEFAULT 'feiertag',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (`holiday_date`)
) ENGINE=InnoDB;

CREATE TABLE `assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `plan_date` DATE NOT NULL,
  `plan_area_id` INT NOT NULL,
  `year` INT NOT NULL,
  `month` INT NOT NULL,
  `visible_to_user` TINYINT(1) NOT NULL DEFAULT 1,
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `remarks` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY (`user_id`,`plan_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`plan_area_id`) REFERENCES `plan_areas`(`id`) ON DELETE CASCADE,
  INDEX (`year`,`month`)
) ENGINE=InnoDB;

CREATE TABLE `absences` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `absence_date` DATE NOT NULL,
  `absence_type` ENUM('krank_halb','krank_ganz','krank_attest','urlaub_halb','urlaub_ganz','urlaub_genehmigt','gleitzeit','gesperrt') NOT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `remarks` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY (`user_id`,`absence_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX (`absence_date`)
) ENGINE=InnoDB;

CREATE TABLE `publish_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `year` INT NOT NULL,
  `month` INT NOT NULL,
  `visible_to_user` TINYINT(1) NOT NULL DEFAULT 1,
  `published_by` INT DEFAULT NULL,
  `published_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`published_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX (`year`,`month`)
) ENGINE=InnoDB;

INSERT INTO `plan_areas` (`short_code`,`name`,`color`,`fg_color`,`area_type`,`sort_order`) VALUES
('K1','Kunde Nord (Beispiel)','#dc2626','#ffffff','kunde',1),
('K2','Kunde Süd (Beispiel)','#ea580c','#ffffff','kunde',2),
('K3','Kunde West (Beispiel)','#65a30d','#ffffff','kunde',3),
('K4','Kunde Ost (Beispiel)','#7c3aed','#ffffff','kunde',4),
('AM','Aussenmontage','#f59e0b','#000000','kunde',7),
('HO','Home Office','#6366f1','#ffffff','kunde',12),
('T','Technik','#374151','#ffffff','allgemein',100),
('BS','Berufsschule','#6b7280','#ffffff','allgemein',101),
('S','Schulungstag','#0284c7','#ffffff','allgemein',102),
('SA','Service Administration','#9333ea','#ffffff','allgemein',103);

INSERT INTO `holidays` (`name`,`holiday_date`,`holiday_type`) VALUES
('Neujahr','2026-01-01','feiertag'),('Karfreitag','2026-04-03','feiertag'),
('Ostermontag','2026-04-06','feiertag'),('Tag der Arbeit','2026-05-01','feiertag'),
('Christi Himmelfahrt','2026-05-14','feiertag'),('Pfingstmontag','2026-05-25','feiertag'),
('Fronleichnam','2026-06-04','feiertag'),('Tag der Dt. Einheit','2026-10-03','feiertag'),
('Allerheiligen','2026-11-01','feiertag'),('1. Weihnachtstag','2026-12-25','feiertag'),
('2. Weihnachtstag','2026-12-26','feiertag');

ALTER TABLE `users` ADD FOREIGN KEY (`primary_area_id`) REFERENCES `plan_areas`(`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `user_supervisors` (
  `user_id` INT NOT NULL,
  `supervisor_id` INT NOT NULL,
  PRIMARY KEY (`user_id`, `supervisor_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`supervisor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO `app_settings` (`setting_key`,`setting_value`) VALUES
('maintenance_mode','0'),
('maintenance_message','Das System wird gewartet. Bitte versuchen Sie es später erneut.'),
('company_name','Planquadro'),
('app_version','4.3'),
('approval_system_enabled','0');

CREATE TABLE IF NOT EXISTS `change_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL COMMENT 'Betroffener Mitarbeiter',
  `plan_date` DATE NOT NULL,
  `request_type` ENUM('assignment','absence') NOT NULL,
  `plan_area_id` INT DEFAULT NULL COMMENT 'Nur bei assignment',
  `absence_type` VARCHAR(50) DEFAULT NULL COMMENT 'Nur bei absence',
  `old_type` VARCHAR(20) DEFAULT NULL COMMENT 'Was vorher da war',
  `old_value` VARCHAR(50) DEFAULT NULL COMMENT 'Alter Wert',
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` TEXT DEFAULT NULL,
  `requested_by` INT NOT NULL,
  `approved_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`plan_area_id`) REFERENCES `plan_areas`(`id`) ON DELETE SET NULL,
  INDEX `idx_status` (`status`),
  INDEX `idx_user_date` (`user_id`, `plan_date`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT,
  `link` VARCHAR(500) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_read` (`user_id`, `is_read`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB;
