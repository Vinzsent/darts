<?php
// Always return JSON, even on PHP warnings/notices that would corrupt fetch().json()
ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'PHP fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']]);
    }
});
include '../includes/auth.php';
include '../includes/db.php';
require_once __DIR__ . '/../includes/qrcode.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$poi_id = isset($data['poi_id']) ? intval($data['poi_id']) : 0;
// The counter must accept 0 (it is possible to formally receive a line with
// nothing physically delivered, e.g. a back-ordered item). Only a negative
// value or a non-numeric string is rejected.
$received_qty_raw = isset($data['received_quantity']) ? trim((string)$data['received_quantity']) : '';
if ($received_qty_raw === '' || !is_numeric($received_qty_raw) || (float)$received_qty_raw < 0) {
    echo json_encode(['success' => false, 'message' => 'A valid received quantity is required.']);
    exit;
}
$received_qty = (int)round((float)$received_qty_raw);

if (!$poi_id) {
    echo json_encode(['success' => false, 'message' => 'Item ID is required.']);
    exit;
}

// Same normalized roles the page uses to show the button
$raw_user_type = $_SESSION['user_type'] ?? $_SESSION['user']['user_type'] ?? '';
$user_type = str_replace([' ', '-'], '', strtolower($raw_user_type));
$allowed_roles = ['propertycustodian', 'supplyincharge', 'purchasingofficer', 'purchasingstaff', 'admin'];

if (!in_array($user_type, $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Where the received goods belong is decided by the location marked on each PO line
// (supply -> inventory, property -> property_inventory). The receiver's role is only
// the fallback for lines that were never marked.
require_once __DIR__ . '/../includes/po_inventory_helper.php';
$target = po_resolve_target_for_role($raw_user_type, $data['target'] ?? null);

$received_date = date('Y-m-d H:i:s');
$user_id = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;

// Load the line so its received count can be recorded/updated. There is
// intentionally NO is_received guard here: a recorded (partial) line must stay
// editable, so the receiver can re-submit tomorrow with the units that arrive then.
// The location mark is optional (older databases may not have the column).
$location_col_sql = po_location_column_exists($conn) ? ', location' : '';

$item_stmt = $conn->prepare(
    "SELECT poi_id, po_id, item_number, item_description, quantity, received_quantity, unit_cost{$location_col_sql}
     FROM purchase_order_items
     WHERE poi_id = ?
     LIMIT 1"
);
$item_stmt->bind_param("i", $poi_id);
$item_stmt->execute();
$item_res = $item_stmt->get_result();

if (!$item_res || $item_res->num_rows === 0) {
    $item_stmt->close();
    echo json_encode(['success' => false, 'message' => 'Item not found.']);
    exit;
}
$item = $item_res->fetch_assoc();
$item_stmt->close();

// Guard: you cannot receive more than what was ordered on the line.
$ordered_qty = (int)round((float)$item['quantity']);
if ($received_qty > $ordered_qty) {
    echo json_encode(['success' => false,
        'message' => "Received quantity ({$received_qty}) cannot exceed the ordered quantity ({$ordered_qty})."]);
    exit;
}

// Record where the stock will go, so the line keeps an audit trail even though
// the PO form no longer has a Location column. An existing mark is never
// overwritten, and with no mark posted this is simply a no-op.
if (empty($item['location'])) {
    $posted_location = $data['location'] ?? '';
    if (po_apply_location_to_item($conn, $poi_id, $posted_location)) {
        $item['location'] = (string)po_normalize_location($posted_location);
    }
}

// ── Phase 1 of receiving: RECORD how many units arrived on this line ──────────
// Nothing is moved into inventory here. That only happens when the receiver
// clicks the modal's "Complete Process & Move Items to Inventory" button (phase 2,
// complete_po_receiving.php). There is deliberately NO is_received guard on the
// UPDATE: a recorded (partial) line must stay editable so a short delivery can be
// topped up tomorrow with the units that arrive then.
$stmt = $conn->prepare(
    "UPDATE purchase_order_items
        SET is_received = 1, received_quantity = ?, received_date = ?, received_by = ?
      WHERE poi_id = ?"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$uid_str = (string)($user_id ?? 0);
$stmt->bind_param("issi", $received_qty, $received_date, $uid_str, $poi_id);

if ($stmt->execute()) {
    // ── Stamp a per-item QR code so the recorded line can be scanned later ──────
    // The PNG is stored in purchase_order_items.qr_code and shown via view_item_qr.php.
    // A failure here never undoes the successful record.
    $item_qr_url = '';
    try {
        $po_stmt = $conn->prepare("SELECT po_number FROM purchase_orders WHERE po_id = ?");
        $po_number = '';
        if ($po_stmt) {
            $po_stmt->bind_param("i", $item['po_id']);
            $po_stmt->execute();
            $por = $po_stmt->get_result();
            if ($por && $por->num_rows > 0) {
                $po_number = (string)($por->fetch_assoc()['po_number'] ?? '');
            }
            $po_stmt->close();
        }
        $item_payload = json_encode([
            'type'        => 'PO_ITEM',
            'po_number'   => $po_number,
            'item'        => (string)($item['item_number'] ?? ''),
            'desc'        => (string)($item['item_description'] ?? ''),
            'qty'         => (string)($item['quantity'] ?? ''),
            'unit_cost'   => (string)($item['unit_cost'] ?? ''),
            'received'    => (string)$received_qty,
            'received_on' => $received_date,
        ], JSON_UNESCAPED_UNICODE);
        $item_qr = new QrCode($item_payload, QrCode::ECC_MEDIUM);
        $item_png = $item_qr->toPng(4, 4);
        $qrs = $conn->prepare("UPDATE purchase_order_items SET qr_code = ? WHERE poi_id = ?");
        if ($qrs) {
            $null = null;
            $qrs->bind_param('bi', $null, $poi_id);
            $qrs->send_long_data(0, $item_png);
            if ($qrs->execute()) {
                $item_qr_url = '../actions/view_item_qr.php?id=' . $poi_id;
            }
            $qrs->close();
        }
    } catch (Throwable $e) {
        $item_qr_url = '';
    }

    $is_short = ($received_qty < (int)round((float)$item['quantity']));
    $status_label = $is_short ? 'Pending (partial delivery)' : 'Fully Received';
    echo json_encode([
        'success' => true,
        'message' => "Recorded {$received_qty} unit(s) received for this item ({$status_label}). "
            . "Items are moved to inventory only after you click "
            . "\"Complete Process & Move Items to Inventory\".",
        'received_quantity' => $received_qty,
        'item_qr_url' => $item_qr_url,
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
}