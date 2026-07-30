-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- Production batch/component trace tables for KreaProducts touch production flow.

CREATE TABLE IF NOT EXISTS llx_kreaproducts_mo_batch (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer NOT NULL,
	fk_mo integer NOT NULL,
	production_qty double(24,8) NOT NULL DEFAULT 0,
	inventorycode varchar(128) NOT NULL,
	fk_user_creat integer NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_kreaproducts_mo_batch (entity, fk_mo, inventorycode),
	KEY idx_kreaproducts_mo_batch_mo (entity, fk_mo)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreaproducts_mo_component_batch (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer NOT NULL,
	fk_trace integer NOT NULL,
	fk_bomline integer NOT NULL DEFAULT 0,
	fk_mo_line integer NOT NULL DEFAULT 0,
	position integer NOT NULL DEFAULT 0,
	fk_component_product integer NOT NULL DEFAULT 0,
	component_qty double(24,8) NOT NULL DEFAULT 0,
	component_batch varchar(128) NOT NULL DEFAULT '',
	fk_user_creat integer NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_kreaproducts_mo_component (entity, fk_trace, fk_mo_line),
	KEY idx_kreaproducts_mo_component_bomline (entity, fk_bomline),
	KEY idx_kreaproducts_mo_component_product (entity, fk_component_product)
) ENGINE=innodb;
