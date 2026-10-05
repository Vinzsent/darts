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
    $poi_recv_col = in_array('received_date', $poi_cols, true) ? 'received_date' : (in_array('date_received', $poi_cols, true) ? 'date_received AS received_date' : 'NULL AS received_date');

    // Get purchase order items
    $items_query = "
        SELECT 
            poi_id,
            item_number,
            item_description,
            quantity,
            unit_cost,
            line_total,
            is_received,
            $poi_recv_col
        FROM purchase_order_items
        WHERE po_id = ?
        ORDER BY item_number ASC
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
