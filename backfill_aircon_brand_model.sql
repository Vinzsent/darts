-- ============================================================
-- Backfill: aircons.brand / aircons.model
-- Purpose: units with empty Brand or Model cause the Edit form
--          to fail validation ("Brand and Model are required").
--          This fills them with 'N/A' and normalizes casing.
-- Safe to run multiple times (idempotent - only touches empty
-- or case-variant values, never overwrites real data).
-- Run on the live database (e.g. via phpMyAdmin) BEFORE or
-- together with deploying pages/aircon_list.php and
-- actions/edit_aircon.php.
-- ============================================================

-- 1) Fill empty/NULL brands with 'N/A'
UPDATE aircons SET brand = 'N/A' WHERE brand IS NULL OR TRIM(brand) = '';

-- 2) Fill empty/NULL models with 'N/A'
UPDATE aircons SET model = 'N/A' WHERE model IS NULL OR TRIM(model) = '';

-- 3) Normalize lowercase/mixed-case 'n/a' variants to 'N/A'
UPDATE aircons SET brand = 'N/A' WHERE TRIM(brand) IN ('n/a', 'N/a', 'n/A');
UPDATE aircons SET model = 'N/A' WHERE TRIM(model) IN ('n/a', 'N/a', 'n/A');

-- 4) Verification (should both return 0)
SELECT COUNT(*) AS empty_brand_remaining FROM aircons WHERE brand IS NULL OR TRIM(brand) = '';
SELECT COUNT(*) AS empty_model_remaining FROM aircons WHERE model IS NULL OR TRIM(model) = '';
