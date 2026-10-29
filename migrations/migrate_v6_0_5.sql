-- v6.0.5 Migration: DB-Optimierung, editing_status, Permission-Overrides, Custom Roles
-- Alle Statements sind idempotent (können mehrfach ohne Fehler ausgeführt werden)

-- ============================================================
-- Helper Procedures (werden am Ende wieder entfernt)
-- ============================================================
DELIMITER //

DROP PROCEDURE IF EXISTS `ep_safe_col`//
CREATE PROCEDURE `ep_safe_col`(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_def VARCHAR(500))
BEGIN
    DECLARE cnt INT DEFAULT 0;
    SELECT COUNT(*) INTO cnt FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_tbl AND column_name = p_col;
    IF cnt = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DROP PROCEDURE IF EXISTS `ep_safe_idx`//
CREATE PROCEDURE `ep_safe_idx`(IN p_tbl VARCHAR(64), IN p_idx VARCHAR(64), IN p_cols VARCHAR(200))
BEGIN
    DECLARE cnt INT DEFAULT 0;
    DECLARE tbl_exists INT DEFAULT 0;
    -- Prüfe ob Tabelle existiert
    SELECT COUNT(*) INTO tbl_exists FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = p_tbl;
    IF tbl_exists > 0 THEN
        SELECT COUNT(*) INTO cnt FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = p_tbl AND index_name = p_idx;
        IF cnt = 0 THEN
            SET @sql = CONCAT('CREATE INDEX `', p_idx, '` ON `', p_tbl, '` (', p_cols, ')');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END IF;
END//

DELIMITER ;

-- ============================================================
-- 1. editing_status: Rebuild without auto-increment ID
-- ============================================================
DROP TABLE IF EXISTS `editing_status`;
CREATE TABLE `editing_status` (
    `user_id` INT NOT NULL,
    `page` VARCHAR(50) NOT NULL,
    `year` INT NOT NULL DEFAULT 0,
    `month` INT NOT NULL DEFAULT 0,
    `target_uid` INT DEFAULT NULL,
    `last_ping` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `page`, `year`, `month`),
    INDEX `idx_ping` (`last_ping`),
    INDEX `idx_page_ym` (`page`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. Performance indexes
-- ============================================================
CALL ep_safe_idx('absences', 'idx_abs_user_date', 'user_id, absence_date');
CALL ep_safe_idx('absences', 'idx_abs_published', 'is_published, absence_date');
CALL ep_safe_idx('assignments', 'idx_asgn_published', 'is_published, year, month');
CALL ep_safe_idx('plan_drafts', 'idx_drafts_created', 'created_by, year, month');
CALL ep_safe_idx('plan_drafts', 'idx_drafts_internal', 'is_internal, year, month');
CALL ep_safe_idx('notifications', 'idx_notif_user', 'user_id, is_read, created_at');
CALL ep_safe_idx('audit_log', 'idx_audit_created', 'created_at');
CALL ep_safe_idx('login_attempts', 'idx_login_time', 'attempted_at');

-- ============================================================
-- 3. Permission user overrides
-- ============================================================
CREATE TABLE IF NOT EXISTS `permission_user_overrides` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `permission` VARCHAR(50) NOT NULL,
    `user_id` INT NOT NULL,
    `granted` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_perm_user` (`permission`, `user_id`),
    INDEX `idx_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. Custom roles table
-- ============================================================
CREATE TABLE IF NOT EXISTS `custom_roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_key` VARCHAR(30) NOT NULL UNIQUE,
    `role_label` VARCHAR(100) NOT NULL,
    `color` VARCHAR(20) NOT NULL DEFAULT '#6b7280',
    `base_role` VARCHAR(30) DEFAULT 'mitarbeiter',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 100,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO custom_roles (role_key, role_label, color, is_system, sort_order) VALUES
('admin', 'Administrator', '#e2001a', 1, 1),
('manager', 'Manager', '#2563eb', 1, 2),
('teamleiter', 'Teamleiter', '#7c3aed', 1, 3),
('verwaltung', 'Verwaltung', '#0891b2', 1, 4),
('lager', 'Lager', '#d97706', 1, 5),
('mitarbeiter', 'Techniker', '#059669', 1, 6),
('azubi_1', 'Azubi 1. LJ', '#ec4899', 1, 7),
('azubi_2', 'Azubi 2. LJ', '#f472b6', 1, 8),
('azubi_3', 'Azubi 3. LJ', '#f9a8d4', 1, 9);

-- ============================================================
-- 5. Users: ENUM -> VARCHAR für Custom Roles
-- ============================================================
ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(30) NOT NULL DEFAULT 'mitarbeiter';

-- ============================================================
-- 6. Sichere Spalten-Ergänzungen (falls v6.0.0 nicht gelaufen)
-- ============================================================
CALL ep_safe_col('plan_drafts', 'is_internal', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ep_safe_col('plan_drafts', 'internal_remark', 'TEXT DEFAULT NULL');
CALL ep_safe_col('assignments', 'is_internal', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ep_safe_col('assignments', 'internal_remark', 'TEXT DEFAULT NULL');
CALL ep_safe_col('assignments', 'remark_by', 'INT DEFAULT NULL');
CALL ep_safe_col('assignments', 'remark_at', 'DATETIME DEFAULT NULL');
CALL ep_safe_col('absences', 'is_internal', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ep_safe_col('absences', 'internal_remark', 'TEXT DEFAULT NULL');
CALL ep_safe_col('absences', 'remark_by', 'INT DEFAULT NULL');
CALL ep_safe_col('absences', 'remark_at', 'DATETIME DEFAULT NULL');
CALL ep_safe_col('changelog', 'entry_type', "VARCHAR(20) DEFAULT 'feature'");
CALL ep_safe_col('users', 'cal_token', 'VARCHAR(64) DEFAULT NULL');
CALL ep_safe_idx('users', 'idx_cal_token', 'cal_token');

-- ============================================================
-- 7. Berechtigungen
-- ============================================================
INSERT IGNORE INTO role_permissions (role, permission, granted) VALUES
('admin','plan_publish_year',1),('manager','plan_publish_year',1),
('teamleiter','plan_publish_year',0),('verwaltung','plan_publish_year',0),
('lager','plan_publish_year',0),('mitarbeiter','plan_publish_year',0),
('azubi_1','plan_publish_year',0),('azubi_2','plan_publish_year',0),('azubi_3','plan_publish_year',0);

-- ============================================================
-- 8. Announcements / Meldungen
-- ============================================================
CREATE TABLE IF NOT EXISTS `announcements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `content` TEXT NOT NULL,
    `priority` ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    `visible_from` DATE DEFAULT NULL,
    `visible_until` DATE DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    INDEX `idx_active_dates` (`active`, `visible_from`, `visible_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. Cleanup
-- ============================================================
DELETE FROM plan_drafts WHERE is_internal = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH);

-- ============================================================
-- 9. Optimize
-- ============================================================
OPTIMIZE TABLE plan_drafts;
OPTIMIZE TABLE assignments;
OPTIMIZE TABLE absences;

-- ============================================================
-- Cleanup helpers
-- ============================================================
DROP PROCEDURE IF EXISTS `ep_safe_col`;
DROP PROCEDURE IF EXISTS `ep_safe_idx`;
