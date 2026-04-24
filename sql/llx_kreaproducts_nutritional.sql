-- Copyright (C) 2025		Kreativität Works <mail@kreativitat.com>
-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.


CREATE TABLE llx_kreaproducts_nutritional(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL, 
	date_creation datetime NOT NULL, 
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
	fk_user_creat integer NOT NULL, 
	fk_user_modif integer, 
	fk_product integer NOT NULL, 
	is_food tinyint DEFAULT 1 NOT NULL,
	energy_kcal double(28,4), 
	energy_kj double(28,4), 
	fat double(28,4), 
	saturates double(28,4), 
	carbohydrates double(28,4), 
	sugars double(28,4), 
	protein double(28,4), 
	salt double(28,4), 
	fiber double(28,4)
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
