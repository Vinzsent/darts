<?php
/**
 * Stream a purchase order's QR code as a PNG image.
 *
 * Usage: view_po_qr.php?id=<po_id>[&regen=1]
 *   - Reads the stored qr_code BLOB from purchase_orders.
 *   - If none exists yet (or &regen=1), generates it on the fly so the <img>
 *     tag always shows something. The BLOB is still persisted on first generate.
 */
session_start();
include '../includes/db.php';
require_once __DIR__ . '/../includes/qrcode.php';

$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$regen = isset($_GET['regen']) && $_GET['regen'] === '1';

if ($po_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid purchase order ID.';
    exit();
}

/** Build the JSON payload + PNG for a PO (shared by store + on-the-fly paths). */
function buildPoQrPng($conn, $po_id)
{
    $po_stmt = $conn->prepare(
        "SELECT po_number, po_date, supplier_name, supplier_address, total_amount, status
         FROM purchase_orders WHERE po_id = ? LIMIT 1"
    );
    if (!$po_stmt) return null;
    $po_stmt->bind_param('i', $po_id);
    $po_stmt->execute();
    $po = $po_stmt->get_result()->fetch_assoc();
    $po_stmt->close();
    if (!$po) return null;

    $items = [];
    $it_stmt = $conn->prepare(
        "SELECT item_number, item_description, quantity, unit_cost, line_total, is_received
         FROM purchase_order_items WHERE po_id = ? ORDER BY item_number ASC"
    );
    if ($it_stmt) {
        $it_stmt->bind_param('i', $po_id);
        $it_stmt->execute();
        $it_res = $it_stmt->get_result();
        while ($row = $it_res->fetch_assoc()) {
            $items[] = [
                'no'   => (int)$row['item_number'],
                'desc' => (string)$row['item_description'],
                'qty'  => (float)$row['quantity'],
                'cost' => (float)$row['unit_cost'],
                'total'=> (float)$row['line_total'],
                'received' => ((int)$row['is_received'] === 1),
            ];
        }
        $it_stmt->close();
    }

    $payload = [
        'type'     => 'PURCHASE_ORDER',
        'po_number'=> (string)$po['po_number'],
        'po_date'  => (string)$po['po_date'],
        'supplier' => (string)$po['supplier_name'],
        'supplier_address' => (string)$po['supplier_address'],
        'total'    => (float)$po['total_amount'],
        'status'   => (string)$po['status'],
        'items'    => $items,
    ];
    $qr = new QrCode(json_encode($payload, JSON_UNESCAPED_UNICODE), QrCode::ECC_MEDIUM);
    return $qr->toPng(4, 4);
}

// Try the stored BLOB first (unless a regen was requested).
$png = null;
if (!$regen) {
    $stmt = $conn->prepare("SELECT qr_code FROM purchase_orders WHERE po_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $po_id);
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
        $png = buildPoQrPng($conn, $po_id);
        if ($png !== null) {
            $up = $conn->prepare("UPDATE purchase_orders SET qr_code = ? WHERE po_id = ?");
            if ($up) {
                $null = NULL;
                $up->bind_param('bi', $null, $po_id);
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
    echo 'QR code not available.';
    exit();
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: no-cache, must-revalidate');
echo $png;
exit();
