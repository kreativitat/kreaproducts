-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License,
-- or (at your option) any later version.

CREATE TABLE IF NOT EXISTS llx_kreaproducts_inventory_correction (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer NOT NULL,
	fk_inventory integer NOT NULL,
	fk_inventorydet integer NOT NULL,
	fk_product integer NOT NULL,
	fk_warehouse integer NOT NULL,
	batch varchar(128) NOT NULL DEFAULT '',
	value_datetime datetime NOT NULL,
	previous_counted_qty double(24,8) NOT NULL,
	corrected_counted_qty double(24,8) NOT NULL,
	adjustment_qty double(24,8) NOT NULL,
	fk_movement integer DEFAULT NULL,
	status smallint NOT NULL DEFAULT 1,
	fk_reverse_movement integer DEFAULT NULL,
	fk_user_creat integer NOT NULL,
	fk_user_reverse integer DEFAULT NULL,
	date_creation datetime NOT NULL,
	date_reversal datetime DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY idx_kreaproducts_inventory_correction_inventory (entity, fk_inventory, status),
	KEY idx_kreaproducts_inventory_correction_line (entity, fk_inventorydet, status),
	KEY idx_kreaproducts_inventory_correction_movement (fk_movement),
	KEY idx_kreaproducts_inventory_correction_reverse (fk_reverse_movement)
) ENGINE=innodb;
