-- v5.2.4 Migration: Login Security
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `idx_username` (`username`),
    INDEX `idx_ip` (`ip_address`),
    INDEX `idx_attempted` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT,
    `ip_address` VARCHAR(45),
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- New security settings
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('captcha_enabled', '1'),
('max_login_attempts', '5'),
('lockout_duration_minutes', '15'),
('min_password_length', '6'),
('session_timeout_minutes', '480'),
('login_log_enabled', '1'),
('password_require_uppercase', '0'),
('password_require_number', '0'),
('password_require_special', '0'),
('dashboard_show_absent', '1');

-- Changelog v5.2.7
INSERT IGNORE INTO `changelog` (`version`, `release_date`, `content`) VALUES
('5.2.7', CURDATE(), 'Draft-System für Planung\n- Änderungen in der Monatsplanung werden als Entwürfe gespeichert\n- Gesamtplan zeigt NUR veröffentlichte Daten\n- Löschen, Ändern und Bemerkungen erst nach Veröffentlichung sichtbar\n- Veröffentlichung wendet alle Entwürfe auf die Datenbank an\n\nSicherheit & Login\n- CAPTCHA (Mathe-Aufgabe) nach 2 Fehlversuchen beim Login\n- Konfigurierbare Kontosperre nach zu vielen Fehlversuchen\n- Passwort-Richtlinien: Mindestlänge, Großbuchstabe, Zahl, Sonderzeichen\n- Audit-Log für alle sicherheitsrelevanten Aktionen\n\nEinstellungen\n- Sidebar-Layout mit 10 Bereichen\n- Erfassung (Changelog, Wartung) über Modals\n- Login-Dashboard mit Live-Statistiken\n- Heute Abwesend auf Dashboard steuerbar\n\nAnträge\n- Komplett neues Design mit Avatar und Timeline-Blöcken\n- Detail-Modal mit einzelner Tages-Genehmigung\n- Aufeinanderfolgende gleiche Änderungen als Block\n- Mitarbeiter-Ansicht mit aufklappbaren Karten\n\nDashboard\n- KPI-Leiste, WOTs als Tabelle unter Wochenansicht\n- Planstatus mit Planungsquote\n- Professionelle Wochenansicht als Raster\n\nPlanung\n- Alle Dropdowns mit Kunden/Allgemein-Gruppierung\n- Sticky-Spalten fixiert bei allen Displaygrößen\n- Breitere WOTs/Std/Erl-Spalten\n\nKalender (Mein Plan)\n- Tabellen-Raster statt Einzelkarten');
