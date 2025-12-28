-- Copyright (C) 2025 Kreativität Works
-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
--
-- KreaProducts Allergens Dictionary Table
--
-- This table contains the list of allergens used by the module

CREATE TABLE IF NOT EXISTS `llx_c_kreaproducts` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(5) NOT NULL,
  `label` varchar(255) NOT NULL,
  `icon` varchar(120) NOT NULL,
  `active` int(3) DEFAULT 1,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Insert allergen data (skip if already exists)
--

INSERT IGNORE INTO `llx_c_kreaproducts` (`label`, `icon`, `active`, `code`) VALUES
('No allergens', 'noallergens.png', 0, 'NOAL'),
('Celery', 'celery.png', 1, 'CELE'),
('Peanut', 'peanut.png', 1, 'PEAN'),
('Crustacens', 'crustacens.png', 1, 'CRUS'),
('Tree nut', 'treenut.png', 1, 'TNUT'),
('Wheat', 'wheat.png', 1, 'GLUT'),
('Milk', 'milk.png', 1, 'LEIT'),
('Molluscs', 'molluscs.png', 1, 'MOLU'),
('Mustard', 'mustard.png', 1, 'MUST'),
('Eggs', 'eggs.png', 1, 'EGGS'),
('Fish', 'fish.png', 1, 'FISH'),
('Sesame seeds', 'sesame.png', 1, 'SESA'),
('Soya', 'soya.png', 1, 'SOYA'),
('Sulphurdioxide', 'sulphurdioxide.png', 1, 'SULP'),
('Lupin', 'lupin.png', 1, 'LUPI');
