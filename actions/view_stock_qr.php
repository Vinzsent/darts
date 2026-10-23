<?php
/**
 * Stream an inventory / property STOCK ROW's QR code as a PNG image.
 *
 * Usage: view_stock_qr.php?type=property|supply&id=<inventory_id>[&regen=1]
 *   - type=property -> row in property_inventory
 *   - type=supply   -> row in inventory
 *   - The QR is ALWAYS generated fresh from the live row (item, qty, status,
 *     location, ...) so a scan decodes the item's current details — never a
 *     stale snapshot. (&regen=1 is accepted for compatibility but is now
 *     redundant: every request regenerates.)
 *   - The qr_code column keeps its short code (e.g. PROP-01698 / INV-01302)
 *     as a legacy scan identifier only.
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

// Always generate from the live row so the QR payload (item, qty, status,
// location, ...) reflects the current details at scan time. The stored
// qr_code keeps its short code (e.g. PROP-01698 / INV-01302) as a legacy
// scan identifier only.
$png = stock_qr_png($conn, $type, $id);
if ($png !== null) {
    // Ensures the row carries its short code for legacy/manual scans
    // (no-op when qr_code already holds a value).
    stock_qr_persist($conn, $type, $id, $png);
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
