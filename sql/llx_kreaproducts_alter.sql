-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
-- Rename legacy kreap_syncprice extrafield to kreap_updatebuyprice (safe when column is missing)
SET @schema = DATABASE();

-- Rename data column if legacy exists and new one missing
SET @table := CONCAT('llx_product_extrafields');
SET @has_old_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table AND COLUMN_NAME = 'kreap_syncprice'
);
SET @has_new_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table AND COLUMN_NAME = 'kreap_updatebuyprice'
);
SET @sql := IF(@has_old_col > 0 AND @has_new_col = 0,
    'ALTER TABLE llx_product_extrafields CHANGE kreap_syncprice kreap_updatebuyprice int(11) DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Clean extrafield definition if both exist (keep new, drop old)
DELETE FROM llx_extrafields
WHERE elementtype = 'product' AND name = 'kreap_syncprice'
  AND EXISTS (SELECT 1 FROM llx_extrafields e2 WHERE e2.elementtype = 'product' AND e2.name = 'kreap_updatebuyprice');

-- Rename extrafield definition when only legacy exists
UPDATE llx_extrafields e
LEFT JOIN llx_extrafields e2
  ON e2.elementtype = 'product' AND e2.name = 'kreap_updatebuyprice'
SET e.name = 'kreap_updatebuyprice', e.label = 'kreap_updatebuyprice'
WHERE e.elementtype = 'product' AND e.name = 'kreap_syncprice' AND e2.rowid IS NULL;

ALTER TABLE `llx_product_extrafields` MODIFY `kreap_hideproduct` TINYINT(1) NULL DEFAULT 0;
ALTER TABLE `llx_product_extrafields` MODIFY `kreap_updatebuyprice` TINYINT(1) NULL DEFAULT 1;
