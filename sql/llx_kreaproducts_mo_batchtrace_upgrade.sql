-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License,
-- or (at your option) any later version.

SET @schema = DATABASE();

-- Keep the production inventory code wide enough for current identifiers.
SET @needs_inventorycode_resize := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema
	AND TABLE_NAME = 'llx_kreaproducts_mo_batch'
	AND COLUMN_NAME = 'inventorycode'
	AND (CHARACTER_MAXIMUM_LENGTH IS NULL OR CHARACTER_MAXIMUM_LENGTH < 128)
);
SET @sql := IF(@needs_inventorycode_resize > 0,
	'ALTER TABLE llx_kreaproducts_mo_batch MODIFY COLUMN inventorycode varchar(128) NOT NULL',
	'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove early header-trace fields now resolved through core MO relations.
SET @has_index := (
	SELECT COUNT(*) FROM information_schema.STATISTICS
	WHERE TABLE_SCHEMA = @schema
	AND TABLE_NAME = 'llx_kreaproducts_mo_batch'
	AND INDEX_NAME = 'idx_kreaproducts_mo_batch_bom'
);
SET @sql := IF(@has_index > 0,
	'ALTER TABLE llx_kreaproducts_mo_batch DROP INDEX idx_kreaproducts_mo_batch_bom',
	'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'llx_kreaproducts_mo_batch' AND COLUMN_NAME = 'fk_bom'
);
SET @sql := IF(@has_column > 0, 'ALTER TABLE llx_kreaproducts_mo_batch DROP COLUMN fk_bom', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'llx_kreaproducts_mo_batch' AND COLUMN_NAME = 'fk_product'
);
SET @sql := IF(@has_column > 0, 'ALTER TABLE llx_kreaproducts_mo_batch DROP COLUMN fk_product', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'llx_kreaproducts_mo_batch' AND COLUMN_NAME = 'produced_batch'
);
SET @sql := IF(@has_column > 0, 'ALTER TABLE llx_kreaproducts_mo_batch DROP COLUMN produced_batch', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'llx_kreaproducts_mo_batch' AND COLUMN_NAME = 'inventorylabel'
);
SET @sql := IF(@has_column > 0, 'ALTER TABLE llx_kreaproducts_mo_batch DROP COLUMN inventorylabel', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove early component-trace fields now resolved through the trace and core lines.
SET @has_index := (
	SELECT COUNT(*) FROM information_schema.STATISTICS
	WHERE TABLE_SCHEMA = @schema
	AND TABLE_NAME = 'llx_kreaproducts_mo_component_batch'
	AND INDEX_NAME = 'idx_kreaproducts_mo_component_mo'
);
SET @sql := IF(@has_index > 0,
	'ALTER TABLE llx_kreaproducts_mo_component_batch DROP INDEX idx_kreaproducts_mo_component_mo',
	'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'llx_kreaproducts_mo_component_batch' AND COLUMN_NAME = 'fk_mo'
);
SET @sql := IF(@has_column > 0, 'ALTER TABLE llx_kreaproducts_mo_component_batch DROP COLUMN fk_mo', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'llx_kreaproducts_mo_component_batch' AND COLUMN_NAME = 'fk_bom'
);
SET @sql := IF(@has_column > 0, 'ALTER TABLE llx_kreaproducts_mo_component_batch DROP COLUMN fk_bom', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'llx_kreaproducts_mo_component_batch' AND COLUMN_NAME = 'component_ref'
);
SET @sql := IF(@has_column > 0, 'ALTER TABLE llx_kreaproducts_mo_component_batch DROP COLUMN component_ref', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_column := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'llx_kreaproducts_mo_component_batch' AND COLUMN_NAME = 'component_label'
);
SET @sql := IF(@has_column > 0, 'ALTER TABLE llx_kreaproducts_mo_component_batch DROP COLUMN component_label', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
