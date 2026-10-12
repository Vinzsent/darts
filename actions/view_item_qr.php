<?php
/**
 * Stream a purchase order LINE ITEM's QR code as a PNG image.
 *
 * Usage: view_item_qr.php?id=<poi_id>[&regen=1]
 *   - Reads the stored qr_code BLOB from purchase_order_items (written when the
 *     line is marked received, or by generate_po_qr.php).
 *   - If none exists yet (or &regen=1), generates it on the fly from the item
 *     + PO header and persists it for next time.
 */
session_start();
include '../includes/db.php';
require_once __DIR__ . '/../includes/qrcode.php';

$poi_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$regen = isset($_GET['regen']) && $_GET['regen'] === '1';

if ($poi_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid item ID.';
    exit();
}

/** Build the JSON payload + PNG for one line item (shared by both paths). */
function buildItemQrPng($conn, $poi_id)
{
    $stmt = $conn->prepare(
        "SELECT poi.item_number, poi.item_description, poi.quantity, poi.unit_cost,
                poi.line_total, poi.received_date, p.po_number, p.supplier_name
         FROM purchase_order_items poi
         LEFT JOIN purchase_orders p ON poi.po_id = p.po_id
         WHERE poi.poi_id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $poi_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $payload = [
        'type'      => 'PO_ITEM',
        'po_number' => (string)($row['po_number'] ?? ''),
        'supplier'  => (string)($row['supplier_name'] ?? ''),
        'item'      => (string)$row['item_number'],
        'desc'      => (string)$row['item_description'],
        'qty'       => (string)$row['quantity'],
        'unit_cost' => (string)$row['unit_cost'],
        'total'     => (string)$row['line_total'],
        'received'  => (string)($row['received_date'] ?? ''),
    ];
    $qr = new QrCode(json_encode($payload, JSON_UNESCAPED_UNICODE), QrCode::ECC_MEDIUM);
    return $qr->toPng(4, 4);
}

// Try the stored BLOB first (unless a regen was requested).
$png = null;
if (!$regen) {
    $stmt = $conn->prepare("SELECT qr_code FROM purchase_order_items WHERE poi_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $poi_id);
        $stmt->execute();
        $stmt->bind_result($blob);
        if ($stmt->fetch() && $blob !== null && $blob !== '') {
            $png = $blob;
        }
        $stmt->close();
    }
}

// Fall back to generating on the fly (and persist it for next time).
if ($png === null) {
    try {
        $png = buildItemQrPng($conn, $poi_id);
        if ($png !== null) {
            $up = $conn->prepare("UPDATE purchase_order_items SET qr_code = ? WHERE poi_id = ?");
            if ($up) {
                $null = NULL;
                $up->bind_param('bi', $null, $poi_id);
                $up->send_long_data(0, $png);
                $up->execute();
                $up->close();
            }
        }
    } catch (Throwable $e) {
        $png = null;
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