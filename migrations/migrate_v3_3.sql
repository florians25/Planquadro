-- Migration v3.2 -> v3.3
-- Run this against an existing database to upgrade

-- 1) Add primary_area_id column to users (Haupt-Einsatzbereich)
ALTER TABLE `users` ADD COLUMN `primary_area_id` INT DEFAULT NULL AFTER `training_profession`;
ALTER TABLE `users` ADD FOREIGN KEY (`primary_area_id`) REFERENCES `plan_areas`(`id`) ON DELETE SET NULL;
