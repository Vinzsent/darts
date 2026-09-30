-- Link purchase-order receipts into the inventory tables.
--
-- Receiving a purchase order currently only flags purchase_order_items.is_received.
-- Nothing is written to `inventory` / `property_inventory`, so a fully received item
-- is invisible when someone later searches for it to release it.
--
-- These columns record which PO line an inventory row came from, so re-posting is
-- idempotent (guarded by a UNIQUE key) and stock stays traceable to its PO.
--
-- Safe to run multiple times.

-- ---- inventory (supply office) ----
SET @dbname = DATABASE();

SET @tablename = "inventory";
SET @columnname = "source_po_id";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  "SELECT 'Column source_po_id already exists on inventory.' AS result;",
  "ALTER TABLE `inventory` ADD COLUMN `source_po_id` INT DEFAULT NULL AFTER `inventory_id`;"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = "source_poi_id";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  "SELECT 'Column source_poi_id already exists on inventory.' AS result;",
  "ALTER TABLE `inventory` ADD COLUMN `source_poi_id` INT DEFAULT NULL AFTER `source_po_id`;"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- One inventory row per PO line: makes the "already posted" check a single lookup
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = 'uniq_inventory_source_poi') > 0,
  "SELECT 'Index uniq_inventory_source_poi already exists on inventory.' AS result;",
  "CREATE UNIQUE INDEX `uniq_inventory_source_poi` ON `inventory` (`source_poi_id`);"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- property_inventory (property office) ----
SET @tablename = "property_inventory";

SET @columnname = "source_po_id";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  "SELECT 'Column source_po_id already exists on property_inventory.' AS result;",
  "ALTER TABLE `property_inventory` ADD COLUMN `source_po_id` INT DEFAULT NULL;"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = "source_poi_id";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  "SELECT 'Column source_poi_id already exists on property_inventory.' AS result;",
  "ALTER TABLE `property_inventory` ADD COLUMN `source_poi_id` INT DEFAULT NULL AFTER `source_po_id`;"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = 'uniq_property_source_poi') > 0,
  "SELECT 'Index uniq_property_source_poi already exists on property_inventory.' AS result;",
  "CREATE UNIQUE INDEX `uniq_property_source_poi` ON `property_inventory` (`source_poi_id`);"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
