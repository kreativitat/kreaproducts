-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License,
-- or (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.

CREATE TABLE IF NOT EXISTS llx_kreaproducts_inventory_adjustment (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer NOT NULL,
	fk_inventory integer NOT NULL,
	fk_inventorydet integer NOT NULL,
	fk_product integer NOT NULL,
	fk_warehouse integer NOT NULL,
	batch varchar(128) NOT NULL DEFAULT '',
	value_datetime datetime NOT NULL,
	live_qty_before double(24,8) NOT NULL DEFAULT 0,
	post_value_qty double(24,8) NOT NULL DEFAULT 0,
	expected_qty double(24,8) NOT NULL DEFAULT 0,
	counted_qty double(24,8) NOT NULL DEFAULT 0,
	adjustment_qty double(24,8) NOT NULL DEFAULT 0,
	fk_movement integer DEFAULT NULL,
	status smallint NOT NULL DEFAULT 1,
	fk_reverse_movement integer DEFAULT NULL,
	fk_user_creat integer NOT NULL,
	fk_user_reverse integer DEFAULT NULL,
	date_creation datetime NOT NULL,
	date_reversal datetime DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY idx_kreaproducts_inventory_adjustment_line (entity, fk_inventorydet, status),
	KEY idx_kreaproducts_inventory_adjustment_anchor (entity, fk_product, fk_warehouse, batch, value_datetime, status),
	KEY idx_kreaproducts_inventory_adjustment_inventory (entity, fk_inventory, status),
	KEY idx_kreaproducts_inventory_adjustment_movement (fk_movement),
	KEY idx_kreaproducts_inventory_adjustment_reverse (fk_reverse_movement)
) ENGINE=innodb;
