<?php
/**
 * Stream an inventory / property STOCK ROW's QR code as a PNG image.
 *
 * Usage: view_stock_qr.php?type=property|supply&id=<inventory_id>[&regen=1]
 *   - type=property -> row in property_inventory
 *   - type=supply   -> row in inventory
 *   - Reads the stored qr_code BLOB first; when missing (or &regen=1) the
 *     PNG is generated on the fly from the row data and persisted for next
 *     time, mirroring view_item_qr.php / view_po_qr.php.
 */
session_start();
include '../includes/db.php';
require_once __DIR__ . '/../includes/stock_qr.php';

$type = isset($_GET['type']) ? (string)$_GET['type'] : '';
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$regen = isset($_GET['regen']) && $_GET['regen'] === '1';

if (stock_qr_table($type) === null || $id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid QR request (type must be property or supply, and id must be a positive integer).';
    exit();
}

// Try the stored BLOB first (unless a regen was requested).
$png = null;
if (!$regen) {
    $png = stock_qr_blob($conn, $type, $id);
}

// Fall back to generating on the fly (and persist it for next time).
if ($png === null) {
    $png = stock_qr_png($conn, $type, $id);
    if ($png !== null) {
        stock_qr_persist($conn, $type, $id, $png);
    }
}

if ($png === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'QR code not available for this item.';
    exit();
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: no-cache, must-revalidate');
echo $png;
exit();
