-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License,
-- or (at your option) any later version.

SET @schema = DATABASE();
SET @table = 'llx_kreaproducts_inventory_adjustment';

-- Adjustment generations are append-only. One inventory line can therefore
-- have multiple historical rows after Edit, compensation, and re-execution.
SET @has_legacy_unique := (
	SELECT COUNT(*) FROM information_schema.STATISTICS
	WHERE TABLE_SCHEMA = @schema
	AND TABLE_NAME = @table
	AND INDEX_NAME = 'uk_kreaproducts_inventory_adjustment_line'
);
SET @sql := IF(
	@has_legacy_unique > 0,
	'ALTER TABLE llx_kreaproducts_inventory_adjustment DROP INDEX uk_kreaproducts_inventory_adjustment_line',
	'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_history_index := (
	SELECT COUNT(*) FROM information_schema.STATISTICS
	WHERE TABLE_SCHEMA = @schema
	AND TABLE_NAME = @table
	AND INDEX_NAME = 'idx_kreaproducts_inventory_adjustment_line'
);
SET @sql := IF(
	@has_history_index = 0,
	'CREATE INDEX idx_kreaproducts_inventory_adjustment_line ON llx_kreaproducts_inventory_adjustment (entity, fk_inventorydet, status)',
	'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
