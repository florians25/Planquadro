-- v6.5.1 – Vertreterregelung (Deputation)
-- Ein abwesender Vorgesetzter (Teamleiter/Manager) kann seine Planungs- und
-- Genehmigungsrechte für einen bestimmten Zeitraum an eine Vertretung abgeben.

CREATE TABLE IF NOT EXISTS `deputations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `delegator_id` INT NOT NULL,          -- gibt seine Rechte ab
  `deputy_id`    INT NOT NULL,          -- erhält die Rechte (Vertretung)
  `start_date`   DATE NOT NULL,
  `end_date`     DATE NOT NULL,
  `note`         VARCHAR(255) DEFAULT NULL,
  `created_by`   INT DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_deputy_active` (`deputy_id`, `start_date`, `end_date`),
  INDEX `idx_delegator` (`delegator_id`),
  CONSTRAINT `fk_dep_delegator` FOREIGN KEY (`delegator_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dep_deputy`    FOREIGN KEY (`deputy_id`)    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- In-App-Changelog
DELETE FROM `changelog` WHERE `version` = '6.5.1';
INSERT INTO `changelog` (`version`,`release_date`,`content`,`entry_type`) VALUES
('6.5.1','2026-06-30',
'Vertreterregelung\n• Ist ein Teamleiter krank oder im Urlaub, kann er seine Rechte für einen bestimmten Zeitraum an eine Vertretung abgeben.\n• Die Vertretung sieht und bearbeitet während dieses Zeitraums automatisch das Team des Abwesenden und kann dort eintragen sowie Anträge genehmigen.\n• Verwaltung unter „Vertretung": Vertretung wählen, Zeitraum festlegen, optional mit Notiz – jederzeit beendbar.',
'feature');
