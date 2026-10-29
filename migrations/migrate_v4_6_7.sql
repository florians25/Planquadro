-- Migration v4.6.7

-- Changelog table
CREATE TABLE IF NOT EXISTS `changelog` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `version` VARCHAR(20) NOT NULL,
  `release_date` DATE DEFAULT NULL,
  `content` TEXT NOT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insert initial changelog entry
INSERT INTO `changelog` (`version`, `release_date`, `content`) VALUES
('4.6.7', CURDATE(), 'Neue Navbar, Changelog-System, Bulk-Urlaub für alle, Login-Redesign, Sticky Gruppenüberschriften, RU Anf. Spalte fix, WOT-Reload nach Speichern');

-- Update version
UPDATE `app_settings` SET `setting_value` = '4.6.7' WHERE `setting_key` = 'app_version';
