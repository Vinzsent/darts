<?php
// ── Phase 2 of receiving: "Complete Process & Move Items to Inventory" ─────────
// Per-line "Submit" (mark_item_received.php) only RECORDS how many units arrived.
// This endpoint is the single action that actually moves the recorded quantities
// into inventory and finalises the purchase order.
//
// Idempotent by design: po_post_item_to_inventory() is guarded per PO line
// (source_poi_id unique index + link-table lookup), so re-clicking the button can
// never double-post stock. Lines whose received_quantity is still NULL/0 are
// posted at their ordered quantity (the historical "receive all" behaviour), so a
// PO finalised straight from the Received Items page keeps working.
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
require_once __DIR__ . '/../includes/po_inventory_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$po_id = isset($data['po_id']) ? intval($data['po_id']) : 0;
$received_notes = isset($data['notes']) ? trim((string)$data['notes']) : '';

if (!$po_id) {
    echo json_encode(['success' => false, 'message' => 'PO ID is required.']);
    exit;
}

// Same normalized roles the page uses to show the button.
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
$target = po_resolve_target_for_role($raw_user_type, $data['target'] ?? null);

$received_by = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0;
$received_date = date('Y-m-d H:i:s');

// Confirm the PO exists first.
$exists_stmt = $conn->prepare("SELECT po_id, po_number, supplier_name FROM purchase_orders WHERE po_id = ?");
$exists_stmt->bind_param("i", $po_id);
$exists_stmt->execute();
$exists_result = $exists_stmt->get_result();

if (!$exists_result || $exists_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Purchase order not found.']);
    exit;
}
$exists_row = $exists_result->fetch_assoc();
$po_number = (string)($exists_row['po_number'] ?? '');
$po_supplier_name = (string)($exists_row['supplier_name'] ?? '');
$exists_stmt->close();

// Load every line on the PO so the recorded received counts can be posted.
$location_col_sql = po_location_column_exists($conn) ? ', location' : '';
$lines_stmt = $conn->prepare(
    "SELECT poi_id, item_number, item_description, quantity, received_quantity, unit_cost{$location_col_sql}
     FROM purchase_order_items
     WHERE po_id = ?
     ORDER BY item_number ASC"
);
$lines_stmt->bind_param("i", $po_id);
$lines_stmt->execute();
$lines_res = $lines_stmt->get_result();
$lines = [];
while ($row = $lines_res->fetch_assoc()) {
    $lines[] = $row;
}
$lines_stmt->close();

$po_row = ['po_id' => $po_id, 'po_number' => $po_number, 'supplier_name' => $po_supplier_name];
$posted_ok_ids = [];
$post_errors = [];
$stock_results = [];

// Backfill a location mark if one was posted, so each line lands in the right table.
$posted_location = $data['location'] ?? '';
if ($posted_location !== '') {
    foreach ($lines as $idx => $ln) {
        if (empty($ln['location']) && po_apply_location_to_item($conn, $ln['poi_id'], $posted_location)) {
            $lines[$idx]['location'] = (string)po_normalize_location($posted_location);
        }
    }
}

// Post each line at the quantity that was actually RECEIVED (falling back to the
// ordered quantity when nothing was recorded). po_post_item_to_inventory() reads
// $item['quantity'], so the effective count is written there before the post runs.
// It is idempotent per PO line, so an already-posted line simply returns 'skipped'.
foreach ($lines as $ln) {
    $recorded = (isset($ln['received_quantity']) && $ln['received_quantity'] !== null && $ln['received_quantity'] !== '')
        ? (int)round((float)$ln['received_quantity'])
        : (int)round((float)$ln['quantity']);
    $ln['quantity'] = $recorded;

    $r = po_post_item_to_inventory($conn, $po_row, $ln, $target, $received_by, $received_date);
    $r['item_description'] = $ln['item_description'];
    $stock_results[] = $r;

    if (in_array(($r['status'] ?? ''), ['incremented', 'created', 'skipped'], true)) {
        $posted_ok_ids[] = (int)$ln['poi_id'];
    } else {
        $post_errors[] = $ln['item_description'] . ': ' . ($r['message'] ?? 'unknown');
    }
}

// Flag every posted line as fully received and stamp the receiver/date.
// received_quantity keeps its recorded value (COALESCE guards a never-submitted line).
if ($posted_ok_ids) {
    $ids_csv = implode(',', array_map('intval', $posted_ok_ids));
    $conn->query(
        "UPDATE purchase_order_items
            SET is_received = 1,
                received_quantity = COALESCE(received_quantity, quantity),
                received_date = '" . $conn->real_escape_string($received_date) . "',
                received_by = COALESCE(NULLIF(received_by, ''), '" . $conn->real_escape_string((string)$received_by) . "')
          WHERE poi_id IN ($ids_csv)"
    );
}

if ($post_errors && count($posted_ok_ids) === 0) {
    echo json_encode(['success' => false, 'message' => 'No stock posted: ' . implode('; ', $post_errors)]);
    exit;
}

// Finalise the PO. No status guard — re-running after a partial failure must still
// be able to complete the process and save the receiving notes.
$stmt = $conn->prepare(
    "UPDATE purchase_orders SET status = 'Received', received_by = ?, received_date = ?, received_notes = ? WHERE po_id = ?"
);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}
$stmt->bind_param("issi", $received_by, $received_date, $received_notes, $po_id);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    exit;
}

// Summarise what landed in inventory for the UI.
$posted = 0;
$created = 0;
$skipped = 0;
$by_target = ['supply' => 0, 'property' => 0];
foreach ($stock_results as $sr) {
    $bucket = (($sr['target'] ?? $target) === 'property') ? 'property' : 'supply';
    if ($sr['status'] === 'incremented') {
        $posted++;
        $by_target[$bucket]++;
    } elseif ($sr['status'] === 'created') {
        $created++;
        $by_target[$bucket]++;
    } elseif ($sr['status'] === 'skipped') {
        $skipped++;
    }
}

$message = 'Purchase order completed. Received items moved to inventory.';
if ($posted + $created > 0) {
    $parts = [];
    if ($by_target['supply'] > 0) {
        $parts[] = $by_target['supply'] . ' to Supply inventory';
    }
    if ($by_target['property'] > 0) {
        $parts[] = $by_target['property'] . ' to Property inventory';
    }
    $message = sprintf('Process complete. %d item(s) added', $posted + $created);
    if ($parts) {
        $message .= ': ' . implode(', ', $parts);
    }
    $message .= '.';
    if ($created > 0) {
        $message .= sprintf(' (%d new item record(s) created)', $created);
    }
    if ($skipped > 0) {
        $message .= sprintf(' %d line(s) were already in inventory (skipped).', $skipped);
    }
}

echo json_encode([
    'success' => true,
    'message' => $message,
    'stock' => $stock_results,
]);
