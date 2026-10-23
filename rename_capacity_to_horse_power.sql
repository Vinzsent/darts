-- ============================================================
-- Migration: rename aircons.capacity -> aircons.horse_power
-- Reason: the field represents Horse Power, not BTU capacity.
--         All forms, exports, reports, QR payloads and the
--         scanner now use horse_power.
-- Safe to run once. Existing values (e.g. "2 HP", "1.5") are
-- preserved exactly.
-- Run on the live database (e.g. via phpMyAdmin) TOGETHER with
-- deploying the updated PHP files.
-- ============================================================

ALTER TABLE aircons CHANGE capacity horse_power VARCHAR(50) NULL DEFAULT NULL;

-- Verification: should return horse_power
SHOW COLUMNS FROM aircons LIKE 'horse_power';

-- Sanity check: sample of current values
SELECT horse_power, COUNT(*) AS cnt FROM aircons GROUP BY horse_power ORDER BY cnt DESC LIMIT 10;
