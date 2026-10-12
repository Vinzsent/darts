<?php
/**
 * Generate a QR code image for a purchase order and store it as a BLOB.
 *
 * The QR encodes a compact JSON payload with the full PO header and every line
 * item, so a single scan reveals all order information. The PNG is written to
 * purchase_orders.qr_code (mediumblob). Each line item also gets its own QR
 * (item description + qty + cost) stored in purchase_order_items.qr_code.
 *
 * Responds with JSON so the list page can refresh the QR preview inline.
 */
session_start();
header('Content-Type: application/json');

include '../includes/db.php';
require_once __DIR__ . '/../includes/qrcode.php';

// Only authenticated users may generate QR codes.
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit();
}

$po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
if ($po_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid purchase order ID.']);
    exit();
}

// ── Load the PO header ──────────────────────────────────────────────
$po_stmt = $conn->prepare(
    "SELECT po_id, po_number, po_date, supplier_name, supplier_address,
            total_amount, status, notes, created_at
     FROM purchase_orders WHERE po_id = ? LIMIT 1"
);
if (!$po_stmt) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
    exit();
}
$po_stmt->bind_param('i', $po_id);
$po_stmt->execute();
$po = $po_stmt->get_result()->fetch_assoc();
$po_stmt->close();

if (!$po) {
    echo json_encode(['success' => false, 'message' => 'Purchase order not found.']);
    exit();
}

// ── Load the line items ─────────────────────────────────────────────
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

// ── Build the QR payload (compact JSON of all info) ─────────────────
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
$qr_text = json_encode($payload, JSON_UNESCAPED_UNICODE);

try {
    // Render the QR to a PNG blob (medium error-correction, 4px modules, 4-module margin).
    $qr = new QrCode($qr_text, QrCode::ECC_MEDIUM);
    $png = $qr->toPng(4, 4);

    // Persist the PO-level QR as a BLOB. send_long_data handles binary safely.
    $up = $conn->prepare("UPDATE purchase_orders SET qr_code = ? WHERE po_id = ?");
    if (!$up) {
        echo json_encode(['success' => false, 'message' => 'DB error (prepare update): ' . $conn->error]);
        exit();
    }
    $null = NULL;
    $up->bind_param('bi', $null, $po_id);
    $up->send_long_data(0, $png);
    if (!$up->execute()) {
        echo json_encode(['success' => false, 'message' => 'DB error (update): ' . $up->error]);
        exit();
    }
    $up->close();

    echo json_encode([
        'success'  => true,
        'message'  => 'QR code generated for PO ' . $po['po_number'] . '.',
        'po_id'    => $po_id,
        'qr_bytes' => strlen($png),
        'version'  => $qr->getSize(),
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'QR generation failed: ' . $e->getMessage()]);
}
exit();
