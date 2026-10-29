-- v3.5 Migration: Einmalpasswort / Passwort-Pflichtänderung
ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `primary_area_id`;
ALTER TABLE `users` ADD COLUMN `initial_password` VARCHAR(100) DEFAULT NULL AFTER `must_change_password`;
