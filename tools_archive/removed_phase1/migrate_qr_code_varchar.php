<?php
/**
 * Migration Script: Update qr_code columns to VARCHAR(255)
 * Tables updated:
 * - inventory
 * - property_inventory
 * - aircons
 * - fire_extinguishers
 *
 * Can be run via CLI: php actions/migrate_qr_code_varchar.php
 * Or via web browser by an authenticated administrator.
 */

session_start();
include __DIR__ . '/../includes/db.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    include __DIR__ . '/../includes/auth.php';
    if (!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) !== 'admin') {
        http_response_code(403);
        die('Forbidden: Admin access required.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

echo "=== QR Code Column Migration to VARCHAR(255) ===\n\n";

$targets = [
    'inventory' => ['qr_code', 'qrcode'],
    'property_inventory' => ['qr_code', 'qrcode'],
    'aircons' => ['qr_code'],
    'fire_extinguishers' => ['qr_code']
];

foreach ($targets as $table => $columns) {
    echo "--- Table: `$table` ---\n";
    $tCheck = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$tCheck || $tCheck->num_rows === 0) {
        echo "  Table `$table` not found. Skipping.\n";
        continue;
    }

    foreach ($columns as $col) {
        $cCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
        if ($cCheck && $cCheck->num_rows > 0) {
            $colInfo = $cCheck->fetch_assoc();
            $currType = strtolower($colInfo['Type']);
            if (strpos($currType, 'varchar(255)') !== false) {
                echo "  Column `$col` is already VARCHAR(255).\n";
            } else {
                echo "  Column `$col` is currently $currType. Modifying to VARCHAR(255)...\n";
                $sql = "ALTER TABLE `$table` MODIFY COLUMN `$col` VARCHAR(255) NULL DEFAULT NULL";
                if ($conn->query($sql)) {
                    echo "    [OK] Successfully modified `$table`.`$col` to VARCHAR(255).\n";
                } else {
                    echo "    [ERROR] " . $conn->error . "\n";
                }
            }
        } else {
            echo "  Column `$col` does not exist. Adding column as VARCHAR(255)...\n";
            $sql = "ALTER TABLE `$table` ADD COLUMN `$col` VARCHAR(255) NULL DEFAULT NULL";
            if ($conn->query($sql)) {
                echo "    [OK] Successfully added `$col` to `$table` as VARCHAR(255).\n";
            } else {
                echo "    [ERROR] " . $conn->error . "\n";
            }
        }
    }
}

echo "\n--- Populating Empty QR Codes with Alphanumeric Identifiers ---\n";

// inventory
$res = $conn->query("SELECT inventory_id FROM inventory WHERE qr_code IS NULL OR qr_code = ''");
$c = 0;
while ($r = $res->fetch_assoc()) {
    $id = (int)$r['inventory_id'];
    $code = 'INV-' . str_pad($id, 5, '0', STR_PAD_LEFT);
    $conn->query("UPDATE inventory SET qr_code = '$code', qrcode = '$code' WHERE inventory_id = $id");
    $c++;
}
echo "Populated $c empty records in `inventory`.\n";

// property_inventory
$res = $conn->query("SELECT inventory_id FROM property_inventory WHERE qr_code IS NULL OR qr_code = ''");
$c = 0;
while ($r = $res->fetch_assoc()) {
    $id = (int)$r['inventory_id'];
    $code = 'PROP-' . str_pad($id, 5, '0', STR_PAD_LEFT);
    $conn->query("UPDATE property_inventory SET qr_code = '$code', qrcode = '$code' WHERE inventory_id = $id");
    $c++;
}
echo "Populated $c empty records in `property_inventory`.\n";

// aircons
$res = $conn->query("SELECT aircon_id, item_number FROM aircons WHERE qr_code IS NULL OR qr_code = ''");
$c = 0;
while ($r = $res->fetch_assoc()) {
    $id = (int)$r['aircon_id'];
    $itemNum = trim((string)($r['item_number'] ?? ''));
    if ($itemNum !== '' && preg_match('/^[A-Za-z0-9\-_]+$/', $itemNum)) {
        $code = (stripos($itemNum, 'AC') === 0) ? $itemNum : ('AC-' . $itemNum);
    } else {
        $code = 'AC-' . str_pad($id, 4, '0', STR_PAD_LEFT);
    }
    $esc = $conn->real_escape_string($code);
    $conn->query("UPDATE aircons SET qr_code = '$esc' WHERE aircon_id = $id");
    $c++;
}
echo "Populated $c empty records in `aircons`.\n";

// fire_extinguishers
$res = $conn->query("SELECT fire_extinguisher_id, item_number FROM fire_extinguishers WHERE qr_code IS NULL OR qr_code = ''");
$c = 0;
while ($r = $res->fetch_assoc()) {
    $id = (int)$r['fire_extinguisher_id'];
    $itemNum = trim((string)($r['item_number'] ?? ''));
    if ($itemNum !== '' && preg_match('/^[A-Za-z0-9\-_]+$/', $itemNum)) {
        $code = (stripos($itemNum, 'FE') === 0) ? $itemNum : ('FE-' . $itemNum);
    } else {
        $code = 'FE-' . str_pad($id, 4, '0', STR_PAD_LEFT);
    }
    $esc = $conn->real_escape_string($code);
    $conn->query("UPDATE fire_extinguishers SET qr_code = '$esc', barcode_qr = COALESCE(NULLIF(barcode_qr, ''), '$esc') WHERE fire_extinguisher_id = $id");
    $c++;
}
echo "Populated $c empty records in `fire_extinguishers`.\n";

echo "\nMigration finished successfully.\n";
