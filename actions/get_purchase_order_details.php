<?php
header('Content-Type: application/json');
include '../includes/auth.php';
include '../includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid purchase order ID']);
    exit;
}

$po_id = intval($_GET['id']);

try {
    // Detect user table name columns
    $user_cols = [];
    $u_res = $conn->query("SHOW COLUMNS FROM `user`");
    while ($u_res && $c = $u_res->fetch_assoc()) {
        $user_cols[] = $c['Field'];
    }

    if (in_array('first_name', $user_cols, true) && in_array('last_name', $user_cols, true)) {
        $user_name_expr = "NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), '')";
        $receiver_name_expr = "NULLIF(TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)), '')";
    } elseif (in_array('firstname', $user_cols, true) && in_array('lastname', $user_cols, true)) {
        $user_name_expr = "NULLIF(TRIM(CONCAT_WS(' ', u.firstname, u.lastname)), '')";
        $receiver_name_expr = "NULLIF(TRIM(CONCAT_WS(' ', ru.firstname, ru.lastname)), '')";
    } elseif (in_array('name', $user_cols, true)) {
        $user_name_expr = "NULLIF(TRIM(u.name), '')";
        $receiver_name_expr = "NULLIF(TRIM(ru.name), '')";
    } else {
        $user_name_expr = "NULLIF(TRIM(u.username), '')";
        $receiver_name_expr = "NULLIF(TRIM(ru.username), '')";
    }
    $created_by_expr = "COALESCE($user_name_expr, u.username, 'Unknown')";

    // Get purchase order details
    $po_query = "
        SELECT 
            p.po_id,
            p.po_number,
            p.po_date,
            p.supplier_name,
            p.supplier_address,
            p.payment_method,
            p.payment_details,
            p.cash_amount,
            p.subtotal,
            p.total_amount,
            p.status,
            p.notes,
            p.received_by,
            p.received_date,
            p.received_notes,
            p.created_at,
            p.updated_at,
            $created_by_expr as created_by_name,
            COALESCE($receiver_name_expr, ru.username, NULLIF(p.received_by, ''), 'Unknown') as receiver_name,
            ru.user_type as receiver_role
        FROM purchase_orders p
        LEFT JOIN `user` u ON p.created_by = u.id
        LEFT JOIN `user` ru ON CAST(p.received_by AS UNSIGNED) = ru.id
        WHERE p.po_id = ?
    ";
    
    $stmt = $conn->prepare($po_query);
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $po_result = $stmt->get_result();
    
    if ($po_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Purchase order not found']);
        exit;
    }
    
    $purchase_order = $po_result->fetch_assoc();
    
    // Check available columns in purchase_order_items
    $poi_cols = [];
    $poi_res = $conn->query("SHOW COLUMNS FROM purchase_order_items");
    while ($poi_res && $col = $poi_res->fetch_assoc()) {
        $poi_cols[] = $col['Field'];
    }
    $poi_recv_col = in_array('received_date', $poi_cols, true) ? 'poi.received_date' : (in_array('date_received', $poi_cols, true) ? 'poi.date_received AS received_date' : 'NULL AS received_date');
    // Per-item receiver (who clicked Receive on that line). Older databases may
    // not have the column yet, so fall back to the PO-level receiver.
    $has_item_recv_by = in_array('received_by', $poi_cols, true);
    $item_recv_by_col = $has_item_recv_by ? 'poi.received_by AS item_received_by' : 'p.received_by AS item_received_by';
    if (in_array('first_name', $user_cols, true) && in_array('last_name', $user_cols, true)) {
        $item_receiver_expr = $has_item_recv_by
            ? "NULLIF(TRIM(CONCAT_WS(' ', iu.first_name, iu.last_name)), '')"
            : $receiver_name_expr;
    } elseif (in_array('firstname', $user_cols, true) && in_array('lastname', $user_cols, true)) {
        $item_receiver_expr = $has_item_recv_by
            ? "NULLIF(TRIM(CONCAT_WS(' ', iu.firstname, iu.lastname)), '')"
            : $receiver_name_expr;
    } elseif (in_array('name', $user_cols, true)) {
        $item_receiver_expr = $has_item_recv_by ? "NULLIF(TRIM(iu.name), '')" : $receiver_name_expr;
    } else {
        $item_receiver_expr = $has_item_recv_by ? "NULLIF(TRIM(iu.username), '')" : $receiver_name_expr;
    }
    $item_join = $has_item_recv_by ? "LEFT JOIN `user` iu ON CAST(poi.received_by AS UNSIGNED) = iu.id" : "";

    // Get purchase order items
    $items_query = "
        SELECT
            poi.poi_id,
            poi.item_number,
            poi.item_description,
            poi.quantity,
            poi.unit_cost,
            poi.line_total,
            poi.is_received,
            $poi_recv_col,
            $item_recv_by_col,
            COALESCE($item_receiver_expr, " . ($has_item_recv_by ? "iu.username" : "ru.username") . ", NULLIF(" . ($has_item_recv_by ? "poi.received_by" : "p.received_by") . ", ''), 'Unknown') AS item_received_by_name,
            " . ($has_item_recv_by ? "iu.user_type" : "ru.user_type") . " AS item_received_by_role
        FROM purchase_order_items poi
        JOIN purchase_orders p ON p.po_id = poi.po_id
        LEFT JOIN `user` ru ON CAST(p.received_by AS UNSIGNED) = ru.id
        $item_join
        WHERE poi.po_id = ?
        ORDER BY poi.item_number ASC
    ";
    
    $stmt = $conn->prepare($items_query);
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $items_result = $stmt->get_result();
    
    $items = [];
    while ($item = $items_result->fetch_assoc()) {
        $items[] = $item;
    }
    
    echo json_encode([
        'success' => true,
        'purchase_order' => $purchase_order,
        'items' => $items
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>
