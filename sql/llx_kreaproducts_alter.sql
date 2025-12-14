-- Rename legacy kreap_syncprice extrafield to kreap_updatebuyprice (safe when column is missing)
SET @schema = DATABASE();

-- Rename data column if legacy exists and new one missing
SET @table := 'llxnm_product_extrafields';
SET @has_old_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table AND COLUMN_NAME = 'kreap_syncprice'
);
SET @has_new_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table AND COLUMN_NAME = 'kreap_updatebuyprice'
);
SET @sql := IF(@has_old_col > 0 AND @has_new_col = 0,
    'ALTER TABLE llxnm_product_extrafields CHANGE kreap_syncprice kreap_updatebuyprice int(11) DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Clean extrafield definition if both exist (keep new, drop old)
DELETE FROM llxnm_extrafields
WHERE elementtype = 'product' AND name = 'kreap_syncprice'
  AND EXISTS (SELECT 1 FROM llxnm_extrafields e2 WHERE e2.elementtype = 'product' AND e2.name = 'kreap_updatebuyprice');

-- Rename extrafield definition when only legacy exists
UPDATE llxnm_extrafields e
LEFT JOIN llxnm_extrafields e2
  ON e2.elementtype = 'product' AND e2.name = 'kreap_updatebuyprice'
SET e.name = 'kreap_updatebuyprice', e.label = 'kreap_updatebuyprice'
WHERE e.elementtype = 'product' AND e.name = 'kreap_syncprice' AND e2.rowid IS NULL;

-- Remove unused kreap_spread_buyprice extrafield/column
DELETE FROM llxnm_extrafields WHERE elementtype = 'product' AND name = 'kreap_spread_buyprice';

SET @has_spread_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = @table AND COLUMN_NAME = 'kreap_spread_buyprice'
);
SET @sql := IF(@has_spread_col > 0,
    'ALTER TABLE llxnm_product_extrafields DROP COLUMN kreap_spread_buyprice',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `llxnm_product_extrafields` MODIFY `kreap_hideproduct` TINYINT(1) NULL DEFAULT 0;
ALTER TABLE `llxnm_product_extrafields` MODIFY `kreap_updatebuyprice` TINYINT(1) NULL DEFAULT 1;