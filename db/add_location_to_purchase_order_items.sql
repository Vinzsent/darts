-- Mark each purchase-order line with where it belongs once it is received:
--   'supply'   -> added to `inventory` (Supply Inventory)
--   'property' -> added to `property_inventory` (Property Inventory)
--   NULL       -> not marked yet; the receiving user's role decides
--
-- Safe to run more than once (no error if the column/index already exist).

SET @db_name = DATABASE();

-- 1) The column itself
SET @sql_col = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `purchase_order_items` ADD COLUMN `location` varchar(255) NULL DEFAULT NULL',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'purchase_order_items'
      AND COLUMN_NAME = 'location'
);
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- 2) Index so filtering/looking up lines by destination stays fast on large POs
SET @sql_idx = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `purchase_order_items` ADD INDEX `idx_poi_location` (`location`)',
        'DO 0'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'purchase_order_items'
      AND INDEX_NAME = 'idx_poi_location'
);
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;