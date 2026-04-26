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
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--
-- Commercial support and integration services are available from
-- Kreativität Works <mail@kreativitat.com>.

SET @schema = DATABASE();

-- Speeds stock history sums after inventory value dates.
SET @table := CONCAT('llx_stock_mouvement');
SET @has_idx := (
	SELECT COUNT(*) FROM information_schema.STATISTICS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table AND INDEX_NAME = 'idx_kreaproducts_sm_stockdate'
);
SET @has_cols := (
	SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table
	AND COLUMN_NAME IN ('fk_product', 'fk_entrepot', 'batch', 'datem', 'origintype')
);
SET @sql := IF(@has_idx = 0 AND @has_cols = 5,
	'ALTER TABLE llx_stock_mouvement ADD INDEX idx_kreaproducts_sm_stockdate (fk_product, fk_entrepot, batch, datem, origintype)',
	'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Speeds lookup of next inventory anchors and inventory stock movement joins.
SET @has_idx := (
	SELECT COUNT(*) FROM information_schema.STATISTICS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table AND INDEX_NAME = 'idx_kreaproducts_sm_invdate'
);
SET @has_cols := (
	SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table
	AND COLUMN_NAME IN ('origintype', 'fk_product', 'fk_entrepot', 'batch', 'datem', 'fk_origin')
);
SET @sql := IF(@has_idx = 0 AND @has_cols = 6,
	'ALTER TABLE llx_stock_mouvement ADD INDEX idx_kreaproducts_sm_invdate (origintype, fk_product, fk_entrepot, batch, datem, fk_origin)',
	'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Speeds inventory line anchor resolution.
SET @table := CONCAT('llx_inventorydet');
SET @has_idx := (
	SELECT COUNT(*) FROM information_schema.STATISTICS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table AND INDEX_NAME = 'idx_kreaproducts_inventorydet_anchor'
);
SET @has_cols := (
	SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table
	AND COLUMN_NAME IN ('fk_inventory', 'fk_product', 'fk_warehouse', 'batch')
);
SET @sql := IF(@has_idx = 0 AND @has_cols = 4,
	'ALTER TABLE llx_inventorydet ADD INDEX idx_kreaproducts_inventorydet_anchor (fk_inventory, fk_product, fk_warehouse, batch)',
	'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Speeds latest/next recorded inventory anchor scans.
SET @table := CONCAT('llx_inventory');
SET @has_idx := (
	SELECT COUNT(*) FROM information_schema.STATISTICS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table AND INDEX_NAME = 'idx_kreaproducts_inventory_entity_status_date'
);
SET @has_cols := (
	SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table
	AND COLUMN_NAME IN ('entity', 'status', 'date_inventory', 'rowid')
);
SET @sql := IF(@has_idx = 0 AND @has_cols = 4,
	'ALTER TABLE llx_inventory ADD INDEX idx_kreaproducts_inventory_entity_status_date (entity, status, date_inventory, rowid)',
	'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
