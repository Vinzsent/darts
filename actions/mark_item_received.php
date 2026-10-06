<?php
include '../includes/auth.php';
include '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$poi_id = isset($data['poi_id']) ? intval($data['poi_id']) : 0;

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

// Load the line first so it can be posted into inventory, and so we can tell
// "already received" apart from "update matched 0 rows".
// The location mark is optional (older databases may not have the column).
$location_col_sql = po_location_column_exists($conn) ? ', location' : '';

$item_stmt = $conn->prepare(
    "SELECT poi_id, po_id, item_description, quantity, unit_cost{$location_col_sql}
     FROM purchase_order_items
     WHERE poi_id = ? AND is_received = 0
     LIMIT 1"
);
$item_stmt->bind_param("i", $poi_id);
$item_stmt->execute();
$item_res = $item_stmt->get_result();

if (!$item_res || $item_res->num_rows === 0) {
    $item_stmt->close();
    echo json_encode(['success' => false, 'message' => 'Item not found or already received.']);
    exit;
}
$item = $item_res->fetch_assoc();
$item_stmt->close();

// Record where the stock actually went, so the line keeps an audit trail even
// though the PO form no longer has a Location column. An existing mark is never
// overwritten, and with no mark posted this is simply a no-op.
if (empty($item['location'])) {
    $posted_location = $data['location'] ?? '';
    if (po_apply_location_to_item($conn, $poi_id, $posted_location)) {
        $item['location'] = (string)po_normalize_location($posted_location);
    }
}

$po_stmt = $conn->prepare("SELECT po_id, po_number, supplier_name FROM purchase_orders WHERE po_id = ?");
$po_stmt->bind_param("i", $item['po_id']);
$po_stmt->execute();
$po_res = $po_stmt->get_result();
$po = ($po_res && $po_res->num_rows > 0) ? $po_res->fetch_assoc() : ['po_id' => $item['po_id'], 'po_number' => ''];
$po_stmt->close();

$stmt = $conn->prepare("UPDATE purchase_order_items SET is_received = 1, received_date = ? WHERE poi_id = ? AND is_received = 0");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param("si", $received_date, $poi_id);

if ($stmt->execute()) {
    // Post the received quantity into inventory so it becomes searchable.
    if (!function_exists('po_post_item_to_inventory')) {
        require_once __DIR__ . '/../includes/po_inventory_helper.php';
    }
    $stock = po_post_item_to_inventory($conn, $po, $item, $target, $user_id, $received_date);

    // If all items on this PO are now received, update the PO status to Received
    $chk_po = $conn->prepare("SELECT COUNT(*) AS total_items, SUM(CASE WHEN is_received = 1 THEN 1 ELSE 0 END) AS received_items FROM purchase_order_items WHERE po_id = ?");
    if ($chk_po) {
        $chk_po->bind_param("i", $item['po_id']);
        $chk_po->execute();
        $po_counts = $chk_po->get_result()->fetch_assoc();
        $chk_po->close();

        if ($po_counts && (int)$po_counts['total_items'] > 0 && (int)$po_counts['total_items'] === (int)$po_counts['received_items']) {
            $upd_po = $conn->prepare("UPDATE purchase_orders SET status = 'Received', received_by = COALESCE(NULLIF(received_by, ''), ?), received_date = COALESCE(NULLIF(received_date, ''), ?) WHERE po_id = ?");
            if ($upd_po) {
                $uid_str = (string)($user_id ?? 0);
                $upd_po->bind_param("ssi", $uid_str, $received_date, $item['po_id']);
                $upd_po->execute();
                $upd_po->close();
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Item marked as received successfully. ' . $stock['message'],
        'stock' => $stock,
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
}