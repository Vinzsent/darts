<?php
ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode(['success' => false, 'message' => 'PHP fatal error: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']]);
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

include '../includes/auth.php';
include '../includes/db.php';
require_once __DIR__ . '/../includes/po_inventory_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not authenticated. Please log in again.']);
    exit;
}

// Get raw input
$raw_input = file_get_contents('php://input');

// Check if input is empty
if (empty($raw_input)) {
    echo json_encode(['success' => false, 'message' => 'No data received']);
    exit;
}

// Try to decode JSON
$input = json_decode($raw_input, true);

// Check for JSON decode errors
if (json_last_error() !== JSON_ERROR_NONE) {
    $error_message = 'JSON decode error: ' . json_last_error_msg();
    echo json_encode(['success' => false, 'message' => $error_message, 'raw_input' => substr($raw_input, 0, 200)]);
    exit;
}

// Check if decoded data is valid
if (!$input || !is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data structure']);
    exit;
}

function savePurchaseOrder($data, $conn, $user_id) {
    try {
        $conn->begin_transaction();
        
        // Validate required fields
        if (empty($data['po_number']) || empty($data['po_date']) || empty($data['supplier_name'])) {
            throw new Exception('Required fields are missing: PO Number, Date, and Supplier Name');
        }
        
        // Prepare main PO data with proper escaping
        $po_number = $conn->real_escape_string(trim($data['po_number']));
        $po_date = $conn->real_escape_string($data['po_date']);
        $supplier_name = $conn->real_escape_string(trim($data['supplier_name']));
        $supplier_address = $conn->real_escape_string($data['supplier_address'] ?? '');
        $payment_method = $conn->real_escape_string($data['payment_method'] ?? 'Check');
        $payment_details = $conn->real_escape_string($data['payment_details'] ?? '');
        $cash_amount = floatval($data['cash_amount'] ?? 0);
        $notes = $conn->real_escape_string($data['notes'] ?? '');
        
        // Check if PO number already exists (for new POs)
        $po_id = isset($data['po_id']) ? intval($data['po_id']) : null;
        
        if (!$po_id) {
            $check_sql = "SELECT po_id FROM purchase_orders WHERE po_number = '$po_number'";
            $check_result = $conn->query($check_sql);
            if ($check_result && $check_result->num_rows > 0) {
                throw new Exception('PO Number already exists: ' . $po_number);
            }
        }
        
        if ($po_id) {
            // Update existing PO
            $sql = "UPDATE purchase_orders SET 
                    po_date = '$po_date',
                    supplier_name = '$supplier_name',
                    supplier_address = '$supplier_address',
                    payment_method = '$payment_method',
                    payment_details = '$payment_details',
                    cash_amount = $cash_amount,
                    notes = '$notes',
                    updated_at = NOW()
                    WHERE po_id = $po_id";
        } else {
            // Insert new PO
            $sql = "INSERT INTO purchase_orders (
                    po_number, po_date, supplier_name, supplier_address,
                    payment_method, payment_details, cash_amount, notes, created_by
                    ) VALUES (
                    '$po_number', '$po_date', '$supplier_name', '$supplier_address',
                    '$payment_method', '$payment_details', $cash_amount, '$notes', $user_id
                    )";
        }
        
        if (!$conn->query($sql)) {
            throw new Exception('Failed to save purchase order: ' . $conn->error);
        }
        
        if (!$po_id) {
            $po_id = $conn->insert_id;
        }
        
        // Delete existing items for update
        $conn->query("DELETE FROM purchase_order_items WHERE po_id = $po_id");
        
        // Insert items
        $total_amount = 0;
        // Check if quantity column is string (varchar/text) or numeric (decimal)
        $is_qty_str = true;
        $q_res = $conn->query("SHOW COLUMNS FROM purchase_order_items LIKE 'quantity'");
        if ($q_res && $q_col = $q_res->fetch_assoc()) {
            $col_type = strtolower($q_col['Type'] ?? '');
            $is_qty_str = (strpos($col_type, 'char') !== false || strpos($col_type, 'text') !== false);
            $q_res->free();
        }

        // The location column is optional so this still works on older databases.
        $has_location = po_location_column_exists($conn);
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $index => $item) {
                if (!empty($item['description'])) {
                    $item_number = $index + 1;
                    $description = $conn->real_escape_string(trim($item['description']));
                    $raw_quantity = trim((string)($item['quantity'] ?? ''));
                    $quantity_escaped = $conn->real_escape_string($raw_quantity);
                    $num_quantity = floatval($raw_quantity);
                    $unit_cost = floatval($item['unit_cost'] ?? 0);
                    $line_total = $num_quantity * $unit_cost;
                    $total_amount += $line_total;
                    
                    // Route marker: 'supply' -> inventory, 'property' -> property_inventory.
                    // An empty value stays NULL, which falls back to the receiver's role.
                    $location = po_normalize_location($item['location'] ?? '');
                    $location_sql = $location === null ? 'NULL' : "'$location'";
                    $qty_sql = $is_qty_str ? "'$quantity_escaped'" : $num_quantity;

                    $item_sql = $has_location
                        ? "INSERT INTO purchase_order_items
                            (po_id, item_number, item_description, quantity, unit_cost, line_total, location)
                            VALUES ($po_id, $item_number, '$description', $qty_sql, $unit_cost, $line_total, $location_sql)"
                        : "INSERT INTO purchase_order_items
                            (po_id, item_number, item_description, quantity, unit_cost, line_total)
                            VALUES ($po_id, $item_number, '$description', $qty_sql, $unit_cost, $line_total)";
                    
                    if (!$conn->query($item_sql)) {
                        throw new Exception('Failed to save item: ' . $conn->error);
                    }
                }
            }
        }
        
        // Update total amount
        $update_total_sql = "UPDATE purchase_orders SET total_amount = $total_amount WHERE po_id = $po_id";
        if (!$conn->query($update_total_sql)) {
            throw new Exception('Failed to update total amount: ' . $conn->error);
        }
        
        $conn->commit();
        return [
            'success' => true, 
            'message' => 'Purchase order saved successfully', 
            'po_id' => $po_id,
            'po_number' => $po_number,
            'total_amount' => $total_amount
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Process the save request
try {
    $result = savePurchaseOrder($input, $conn, $user_id);
    
    // Ensure clean JSON output
    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode($result);
    exit;
} catch (Exception $e) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
?>
