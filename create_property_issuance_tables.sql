-- ─────────────────────────────────────────────────────────────────────────
-- Migration: tables for the Property Issuance/Transfer Slip
-- (pages/property_issuance.php -> actions/save_property_issuance.php)
-- Run on the live server once, together with deploying the new action.
-- Safe to re-run (CREATE TABLE IF NOT EXISTS).
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS property_issuance (
    issuance_id      INT AUTO_INCREMENT PRIMARY KEY,
    transferring_dept VARCHAR(255) NOT NULL,
    receiving_dept    VARCHAR(255) NOT NULL,
    reason_transfer   VARCHAR(500) NOT NULL,
    transfer_date     DATE NOT NULL,
    transfer_time     TIME NOT NULL,
    created_by        INT NULL,
    date_created      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS property_issuance_items (
    item_entry_id    INT AUTO_INCREMENT PRIMARY KEY,
    issuance_id      INT NOT NULL,
    source           ENUM('property','supply') NOT NULL,
    inventory_id     INT NOT NULL,
    item_name        VARCHAR(255) NOT NULL,
    quantity         INT NOT NULL,
    description      VARCHAR(500) NULL,
    serial_number    VARCHAR(255) NULL,
    transferring_code VARCHAR(255) NULL,
    receiving_code   VARCHAR(255) NULL,
    INDEX idx_pi_issuance (issuance_id),
    INDEX idx_pi_source (source, inventory_id),
    CONSTRAINT fk_pi_header FOREIGN KEY (issuance_id)
        REFERENCES property_issuance (issuance_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verification
SHOW TABLES FROM darts LIKE 'property_issuance%';
