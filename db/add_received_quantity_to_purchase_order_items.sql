-- Track how many units of each purchase-order line were physically received.
--   `quantity`          = how many were ordered on the line
--   `received_quantity` = how many actually arrived (can be fewer on short
--                         deliveries, or 0 when a back-ordered line is formally
--                         closed out)
--   NULL                = not yet received
--
-- This is what powers the "received counter" on the purchase order list page so
-- users can see, per PO, how many items actually arrived versus how many were
-- ordered.
--
-- Safe to run more than once (no error if the column already exists).

SET @db_name = DATABASE();

-- 1) The column itself (nullable INT, after quantity for readability)
SET @sql_col = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `purchase_order_items` ADD COLUMN `received_quantity` int(11) NULL DEFAULT NULL AFTER `quantity`',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'purchase_order_items'
      AND COLUMN_NAME = 'received_quantity'
);
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
