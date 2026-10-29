-- Migration v3.1 -> v3.2
-- Run this against an existing database to upgrade

-- 1) Add training_profession column to users
ALTER TABLE `users` ADD COLUMN `training_profession` VARCHAR(20) DEFAULT '' COMMENT 'Ausbildungsberuf: KM, ITSE, FISI, DUS-WI, DUS-KM' AFTER `weekend_allowed`;

-- 2) Update absence_type ENUM to support half/full day variants
ALTER TABLE `absences` MODIFY COLUMN `absence_type` ENUM('krank_halb','krank_ganz','urlaub_halb','urlaub_ganz','gleitzeit') NOT NULL;

-- 3) Migrate existing data: krank -> krank_ganz, urlaub -> urlaub_ganz
-- (Run only if you had old data with 'krank' and 'urlaub')
-- Note: MySQL won't auto-migrate old enum values. If upgrading from v3.1,
-- you may need to first allow both old+new values, migrate, then restrict:
--
-- Step A: Temporarily allow all values
-- ALTER TABLE `absences` MODIFY COLUMN `absence_type` ENUM('krank','krank_halb','krank_ganz','urlaub','urlaub_halb','urlaub_ganz','gleitzeit') NOT NULL;
-- Step B: Migrate
-- UPDATE `absences` SET absence_type='krank_ganz' WHERE absence_type='krank';
-- UPDATE `absences` SET absence_type='urlaub_ganz' WHERE absence_type='urlaub';
-- Step C: Restrict to new values only
-- ALTER TABLE `absences` MODIFY COLUMN `absence_type` ENUM('krank_halb','krank_ganz','urlaub_halb','urlaub_ganz','gleitzeit') NOT NULL;
