<?php
include '../includes/auth.php';
include '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$po_id = isset($data['po_id']) ? intval($data['po_id']) : 0;
$received_notes = isset($data['notes']) ? trim((string)$data['notes']) : '';
// True  -> also flag every outstanding line item as received
// False -> only persist the PO status/notes (all items already received)
$mark_all_items = !empty($data['mark_all_items']);

if (!$po_id) {
    echo json_encode(['success' => false, 'message' => 'PO ID is required.']);
    exit;
}

// Check if user has permission (same normalized roles the page uses to show the button)
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

$received_by = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0;
$received_date = date('Y-m-d H:i:s');

// Confirm the PO exists first — the UPDATE below may legitimately affect 0 rows
// when the PO is already Received and the values are unchanged (notes resubmitted).
$exists_stmt = $conn->prepare("SELECT po_id, po_number FROM purchase_orders WHERE po_id = ?");
$exists_stmt->bind_param("i", $po_id);
$exists_stmt->execute();
$exists_result = $exists_stmt->get_result();

if (!$exists_result || $exists_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Purchase order not found.']);
    exit;
}
$po_number = (string)($exists_result->fetch_assoc()['po_number'] ?? '');
$exists_stmt->close();

// Duplicate guard: the UI disables the button once every line item is received,
// but enforce it here too so a repeat POST (double click, refresh, stale tab)
// cannot re-run the receive and re-post stock.
$totals_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total_items,
            SUM(CASE WHEN is_received = 0 THEN 1 ELSE 0 END) AS pending_items
     FROM purchase_order_items
     WHERE po_id = ?"
);
if ($totals_stmt) {
    $totals_stmt->bind_param("i", $po_id);
    $totals_stmt->execute();
    $totals = $totals_stmt->get_result()->fetch_assoc();
    $totals_stmt->close();

    $totalItems = (int)($totals['total_items'] ?? 0);
    // COALESCE guards a row where is_received is NULL rather than 0.
    $pendingItems = (int)($totals['pending_items'] ?? 0);
    if (!isset($totals['pending_items'])) {
        $pendingItems = $totalItems;
    }

    if ($totalItems > 0 && $pendingItems === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'All items on this purchase order are already received. '
                . 'It has already been completed and cannot be submitted again.',
            'already_received' => true,
        ]);
        exit;
    }
}

// Optionally close out every outstanding line item in the same call so the
// item-level Action column and the PO status can never drift apart again,
// and post the quantities into inventory so they become searchable for release.
$stock_results = [];
if ($mark_all_items) {
    // Capture the outstanding lines BEFORE the bulk UPDATE so we know exactly
    // which ones newly arrived and need posting (keeps re-runs idempotent).
    // The location mark is optional (older databases may not have the column).
    $location_col_sql = po_location_column_exists($conn) ? ', location' : '';

    $pending_stmt = $conn->prepare(
        "SELECT poi_id, item_description, quantity, unit_cost{$location_col_sql}
         FROM purchase_order_items
         WHERE po_id = ? AND is_received = 0"
    );
    $pending_items = [];
    if ($pending_stmt) {
        $pending_stmt->bind_param("i", $po_id);
        $pending_stmt->execute();
        $pres = $pending_stmt->get_result();
        while ($pr = $pres->fetch_assoc()) {
            $pending_items[] = $pr;
        }
        $pending_stmt->close();
    }

    // Record where the stock actually went (audit trail only — the PO form has no
    // Location column any more). Existing marks are never overwritten.
    $posted_location = $data['location'] ?? '';
    if ($posted_location !== '') {
        foreach ($pending_items as $idx => $pi) {
            if (empty($pi['location']) && po_apply_location_to_item($conn, $pi['poi_id'], $posted_location)) {
                $pending_items[$idx]['location'] = (string)po_normalize_location($posted_location);
            }
        }
    }

    $item_stmt = $conn->prepare("UPDATE purchase_order_items SET is_received = 1, received_date = ? WHERE po_id = ? AND is_received = 0");
    if ($item_stmt) {
        $item_stmt->bind_param("si", $received_date, $po_id);
        $item_stmt->execute();
        $item_stmt->close();
    }

    // Post each newly received line into inventory.
    if (!function_exists('po_post_item_to_inventory')) {
        require_once __DIR__ . '/../includes/po_inventory_helper.php';
    }
    $po_row = ['po_id' => $po_id, 'po_number' => $po_number];
    foreach ($pending_items as $pi) {
        $r = po_post_item_to_inventory($conn, $po_row, $pi, $target, $received_by, $received_date);
        $r['item_description'] = $pi['item_description'];
        $stock_results[] = $r;
    }
}

// Note: no `AND status != 'Received'` guard — resubmitting notes on an already
// received PO must still succeed, otherwise the footer button could never be used
// to finalise a purchase order.
$stmt = $conn->prepare("UPDATE purchase_orders SET status = 'Received', received_by = ?, received_date = ?, received_notes = ? WHERE po_id = ?");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param("issi", $received_by, $received_date, $received_notes, $po_id);

if ($stmt->execute()) {
    // Summarise what landed in inventory so the UI can report it.
    // A single PO can mix supply and property lines, so count each bucket separately.
    $posted = 0;
    $created = 0;
    $by_target = ['supply' => 0, 'property' => 0];
    foreach ($stock_results as $sr) {
        $bucket = (($sr['target'] ?? $target) === 'property') ? 'property' : 'supply';
        if ($sr['status'] === 'incremented') {
            $posted++;
            $by_target[$bucket]++;
        } elseif ($sr['status'] === 'created') {
            $created++;
            $by_target[$bucket]++;
        }
    }

    $message = 'Purchase order marked as received successfully.';
    if ($mark_all_items && ($posted || $created)) {
        $parts = [];
        if ($by_target['supply'] > 0) {
            $parts[] = $by_target['supply'] . ' to Supply inventory';
        }
        if ($by_target['property'] > 0) {
            $parts[] = $by_target['property'] . ' to Property inventory';
        }
        $message .= sprintf(' %d item(s) added', $posted + $created);
        if ($parts) {
            $message .= ': ' . implode(', ', $parts);
        }
        $message .= '.';
        if ($created > 0) {
            $message .= sprintf(' (%d new item record(s) created)', $created);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'stock' => $stock_results,
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
