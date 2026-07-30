-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License,
-- or (at your option) any later version.

SET @schema = DATABASE();
SET @table = 'llx_kreaproducts_productallergens';
SET @index = 'idx_kreaproducts_productallergens_fk_product';

SET @has_fk_product_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = @table
      AND INDEX_NAME = @index
);

SET @sql := IF(
    @has_fk_product_index = 0,
    'CREATE INDEX idx_kreaproducts_productallergens_fk_product ON llx_kreaproducts_productallergens (fk_product)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
