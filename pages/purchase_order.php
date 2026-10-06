<?php
// Show all errors on this page for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$pageTitle = 'Purchase Order';
include '../includes/auth.php';
include '../includes/db.php';
require_once __DIR__ . '/../includes/po_inventory_helper.php';
include '../includes/header.php';

$user_type = $_SESSION['user_type'] ?? '';
$user_id = $_SESSION['user']['id'] ?? 0;

// Fetch letterhead settings
$header_query = $conn->query("SELECT * FROM printer_header_settings WHERE id = 1");
$print_header = $header_query ? $header_query->fetch_assoc() : null;

$school_logo = '../assets/images/logo.png';
if ($print_header && !empty($print_header['logo_path']) && file_exists('../' . $print_header['logo_path'])) {
    $school_logo = '../' . $print_header['logo_path'];
} elseif (file_exists('../DCC2.png')) {
    $school_logo = '../DCC2.png';
}

$school_name = !empty($print_header['school_name']) ? $print_header['school_name'] : 'DAVAO CENTRAL COLLEGE';
$school_address = !empty($print_header['address']) ? $print_header['address'] : 'Juan dela Cruz St., Toril, Davao City, Philippines';
$school_email = !empty($print_header['email_address']) ? $print_header['email_address'] : 'davaocentralcollege2011@gmail.com';
$school_website = !empty($print_header['website']) ? $print_header['website'] : 'www.dcc.edu.ph';

// Check if we're in edit mode
$edit_mode = isset($_GET['edit']) && is_numeric($_GET['edit']);
$po_data = null;
$po_items = [];

if ($edit_mode) {
    $po_id = intval($_GET['edit']);

    // Fetch purchase order data
    $po_query = "SELECT * FROM purchase_orders WHERE po_id = ?";
    $stmt = $conn->prepare($po_query);
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $po_result = $stmt->get_result();

    if ($po_result->num_rows > 0) {
        $po_data = $po_result->fetch_assoc();

        // Fetch purchase order items
        $items_query = "SELECT * FROM purchase_order_items WHERE po_id = ? ORDER BY item_number ASC";
        $stmt = $conn->prepare($items_query);
        $stmt->bind_param("i", $po_id);
        $stmt->execute();
        $items_result = $stmt->get_result();

        while ($item = $items_result->fetch_assoc()) {
            $po_items[] = $item;
        }
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    switch ($_POST['action']) {
        case 'save_po':
            $result = savePurchaseOrder($_POST, $conn, $user_id);
            echo json_encode($result);
            exit;

        case 'load_po':
            $result = loadPurchaseOrder($_POST['po_id'], $conn);
            echo json_encode($result);
            exit;

        case 'generate_po_number':
            $result = generatePONumber($conn);
            echo json_encode($result);
            exit;
    }
}

// Function to generate next PO number
function generatePONumber($conn)
{
    // Get the highest existing PO number from database
    $query = "SELECT MAX(CAST(po_number AS UNSIGNED)) as max_po FROM purchase_orders WHERE po_number REGEXP '^[0-9]+$'";
    $result = $conn->query($query);

    $nextNumber = 4296; // Starting number

    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['max_po'] && $row['max_po'] >= 4296) {
            $nextNumber = $row['max_po'] + 1;
        }
    }

    return ['success' => true, 'po_number' => (string)$nextNumber];
}

// Function to save purchase order
function savePurchaseOrder($data, $conn, $user_id)
{
    try {
        $conn->begin_transaction();

        // Prepare main PO data
        $po_number = $conn->real_escape_string($data['po_number']);
        $po_date = $conn->real_escape_string($data['po_date']);
        $supplier_name = $conn->real_escape_string($data['supplier_name']);
        $supplier_address = $conn->real_escape_string($data['supplier_address']);
        $payment_method = $conn->real_escape_string($data['payment_method'] ?? 'Check');
        $payment_details = $conn->real_escape_string($data['payment_details'] ?? '');
        $cash_amount = floatval($data['cash_amount'] ?? 0);
        $total_amount = floatval($data['total_amount'] ?? 0);
        $notes = $conn->real_escape_string($data['notes'] ?? '');

        // Check if PO exists
        $po_id = null;
        if (isset($data['po_id']) && !empty($data['po_id'])) {
            $po_id = intval($data['po_id']);

            // Block edits on received POs
            $guard = $conn->query("SELECT status FROM purchase_orders WHERE po_id = $po_id");
            $guard_row = $guard ? $guard->fetch_assoc() : null;
            if ($guard_row && $guard_row['status'] === 'Received') {
                return ['success' => false, 'message' => 'Cannot edit a received purchase order'];
            }

            // Update existing PO
            $sql = "UPDATE purchase_orders SET 
                    po_date = '$po_date',
                    supplier_name = '$supplier_name',
                    supplier_address = '$supplier_address',
                    payment_method = '$payment_method',
                    payment_details = '$payment_details',
                    cash_amount = $cash_amount,
                    total_amount = $total_amount,
                    notes = '$notes',
                    updated_at = NOW()
                    WHERE po_id = $po_id";
        } else {
            // Insert new PO
            $sql = "INSERT INTO purchase_orders (
                    po_number, po_date, supplier_name, supplier_address,
                    payment_method, payment_details, cash_amount, total_amount,
                    notes, created_by
                    ) VALUES (
                    '$po_number', '$po_date', '$supplier_name', '$supplier_address',
                    '$payment_method', '$payment_details', $cash_amount, $total_amount,
                    '$notes', $user_id
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
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $index => $item) {
                if (!empty($item['description'])) {
                    $item_number = $index + 1;
                    $description = $conn->real_escape_string($item['description']);
                    $quantity = floatval($item['quantity'] ?? 0);
                    $unit_cost = floatval($item['unit_cost'] ?? 0);
                    $line_total = $quantity * $unit_cost;

                    // Route marker: 'supply' -> inventory, 'property' -> property_inventory.
                    $location = po_normalize_location($item['location'] ?? '');
                    $location_sql = $location === null ? 'NULL' : "'$location'";

                    $item_sql = po_location_column_exists($conn)
                        ? "INSERT INTO purchase_order_items
                            (po_id, item_number, item_description, quantity, unit_cost, line_total, location)
                            VALUES ($po_id, $item_number, '$description', $quantity, $unit_cost, $line_total, $location_sql)"
                        : "INSERT INTO purchase_order_items
                            (po_id, item_number, item_description, quantity, unit_cost, line_total)
                            VALUES ($po_id, $item_number, '$description', $quantity, $unit_cost, $line_total)";

                    if (!$conn->query($item_sql)) {
                        throw new Exception('Failed to save item: ' . $conn->error);
                    }
                }
            }
        }

        // Update total amount based on items
        $conn->query("UPDATE purchase_orders SET total_amount = (
            SELECT COALESCE(SUM(line_total), 0) FROM purchase_order_items WHERE po_id = $po_id
        ) WHERE po_id = $po_id");

        $conn->commit();
        return ['success' => true, 'message' => 'Purchase order saved successfully', 'po_id' => $po_id];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Function to load purchase order
function loadPurchaseOrder($po_id, $conn)
{
    try {
        $po_id = intval($po_id);

        // Get PO data
        $po_sql = "SELECT * FROM purchase_orders WHERE po_id = $po_id";
        $po_result = $conn->query($po_sql);

        if (!$po_result || $po_result->num_rows === 0) {
            return ['success' => false, 'message' => 'Purchase order not found'];
        }

        $po_data = $po_result->fetch_assoc();

        // Get items
        $items_sql = "SELECT * FROM purchase_order_items WHERE po_id = $po_id ORDER BY item_number";
        $items_result = $conn->query($items_sql);

        $items = [];
        if ($items_result) {
            while ($item = $items_result->fetch_assoc()) {
                $items[] = $item;
            }
        }

        return [
            'success' => true,
            'po_data' => $po_data,
            'items' => $items
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Get existing POs for dropdown
$existing_pos_sql = "SELECT po_id, po_number, supplier_name, po_date, status FROM purchase_orders ORDER BY created_at DESC LIMIT 20";
$existing_pos_result = $conn->query($existing_pos_sql);

// Fetch suppliers from database for the searchable dropdown
$next_po_number = 4291; // Starting PO number
if (!$edit_mode) {
    $max_query = "SELECT MAX(CAST(po_number AS UNSIGNED)) as max_po FROM purchase_orders WHERE po_number REGEXP '^[0-9]+$'";
    $max_result = $conn->query($max_query);
    if ($max_result) {
        $max_row = $max_result->fetch_assoc();
        if ($max_row['max_po'] && $max_row['max_po'] >= 4291) {
            $next_po_number = $max_row['max_po'] + 1;
        }
    }
}
$suppliers_query = "SELECT supplier_id, supplier_name, address, city, province, zip_code FROM supplier ORDER BY supplier_name ASC";
$suppliers_result = $conn->query($suppliers_query);
$suppliers_array = [];
if ($suppliers_result && $suppliers_result->num_rows > 0) {
    while ($supplier = $suppliers_result->fetch_assoc()) {
        // Construct full address for auto-fill
        $fullAddress = $supplier['address'] ?? '';
        if ($supplier['city']) $fullAddress .= ', ' . $supplier['city'];
        if ($supplier['province']) $fullAddress .= ', ' . $supplier['province'];
        if ($supplier['zip_code']) $fullAddress .= ' ' . $supplier['zip_code'];
        $supplier['full_address'] = trim((string)$fullAddress, ', ');
        $suppliers_array[] = $supplier;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order</title>
</head>

<body>



    <style>
        :root {
            --primary-green: #073b1d;
            --dark-green: #073b1d;
            --light-green: #2d8aad;
            --accent-orange: #EACA26;
            --accent-blue: #4a90e2;
            --accent-green-approved: #28a745;
            --accent-red: #e74c3c;
            --text-white: #ffffff;
            --text-dark: #073b1d;
            --bg-light: #f8f9fa;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-light);
            margin: 0;
            padding: 0;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: 240px;
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: var(--text-white);
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h4 {
            margin: 0;
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--text-white);
        }

        .welcome-text {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 5px;
        }

        .sidebar-nav {
            padding: 20px 0;
        }

        .sidebar-nav ul {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }

        .nav-item {
            padding: 0;
            margin: 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 8px 15px;
            color: var(--text-white);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            font-size: 0.85rem;
        }

        .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-white);
            border-left-color: var(--accent-orange);
        }

        .nav-link.active {
            background-color: rgba(255, 255, 255, 0.15);
            border-left-color: var(--accent-orange);
            font-weight: 600;
        }

        .nav-link i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        .nav-link.logout {
            color: var(--accent-red);
            margin-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            background-color: var(--bg-light);
        }

        .content-header {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: var(--text-white);
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .content-header h1 {
            margin: 0;
            font-weight: 700;
            font-size: 2.2rem;
        }

        /* Purchase Order Form */
        .po-container {
            background: var(--text-white);
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
            padding: 40px;
        }

        .po-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            border-bottom: 2px solid var(--primary-green);
            padding-bottom: 20px;
        }

        .po-title {
            color: var(--primary-green);
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }

        .po-info {
            text-align: right;
        }

        .po-info-item {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }

        .po-info-label {
            font-weight: 600;
            margin-right: 10px;
            color: var(--text-dark);
            min-width: 80px;
        }

        .po-info-input {
            border: none;
            border-bottom: 1px solid #ccc;
            padding: 5px 10px;
            font-size: 1rem;
            min-width: 150px;
            background: transparent;
        }

        .po-info-input:focus {
            outline: none;
            border-bottom-color: var(--primary-green);
        }

        .po-details {
            margin-bottom: 30px;
        }

        .po-details-row {
            display: flex;
            margin-bottom: 15px;
            align-items: center;
        }

        .po-details-label {
            font-weight: 600;
            color: var(--text-dark);
            min-width: 100px;
            margin-right: 15px;
        }

        .po-details-input {
            border: none;
            border-bottom: 1px solid #ccc;
            padding: 8px 10px;
            font-size: 1rem;
            flex: 1;
            background: transparent;
        }

        .po-details-input:focus {
            outline: none;
            border-bottom-color: var(--primary-green);
        }

        .po-info-input,
        .po-details-input,
        .supplier-input,
        .payment-input,
        .po-table input,
        .po-table select,
        .po-table textarea {
            color: var(--text-dark) !important;
            -webkit-text-fill-color: var(--text-dark);
            background-color: transparent !important;
        }

        .po-info-input::placeholder,
        .po-details-input::placeholder,
        .supplier-input::placeholder,
        .payment-input::placeholder,
        .po-table input::placeholder,
        .po-table textarea::placeholder {
            color: #6c757d;
        }

        /* Searchable Dropdown Styles */
        .supplier-dropdown-container {
            position: relative;
            width: 100%;
        }

        .supplier-input {
            width: 100%;
            padding: 8px 10px;
            border: none !important;
            border-bottom: 1px solid #ccc !important;
            background: transparent !important;
            font-size: 1rem;
        }

        .supplier-input:focus {
            outline: none !important;
            border-bottom-color: var(--primary-green) !important;
        }

        .supplier-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 250px;
            overflow-y: auto;
            background: white;
            border: 1px solid #ddd;
            z-index: 1001;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: left;
            display: none;
            border-radius: 0 0 8px 8px;
        }

        .supplier-item {
            padding: 12px 15px;
            cursor: pointer;
            transition: background 0.2s;
            color: var(--text-dark);
            border-bottom: 1px solid #f0f0f0;
        }

        .supplier-item:hover {
            background: rgba(7, 59, 29, 0.1);
        }

        .add-supplier-item {
            display: block;
            padding: 12px;
            background: var(--primary-green);
            color: white !important;
            text-decoration: none;
            font-weight: 600;
            text-align: center;
            position: sticky;
            bottom: 0;
        }

        .add-supplier-item:hover {
            background: var(--dark-green);
            color: var(--accent-orange) !important;
        }

        /* Table Styles */
        .po-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .po-table th {
            background: var(--primary-green);
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
            padding: 20px 10px;
            text-align: center;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border: 1px solid var(--primary-green);
        }

        .po-table td {
            padding: 18px 10px;
            border: 1px solid #e9ecef;
            text-align: center;
            vertical-align: middle;
            color: var(--text-dark) !important;
            -webkit-text-fill-color: var(--text-dark);
        }

        .po-table tr.total-row td,
        .po-table #totalAmountRow td {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        .po-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .po-table tbody tr:hover {
            background-color: rgba(7, 59, 29, 0.05);
        }

        .po-table tbody tr.total-row:hover,
        .po-table tbody #totalAmountRow:hover {
            background-color: var(--primary-green);
        }

        .po-table input {
            border: none;
            background: transparent;
            width: 100%;
            text-align: center;
            padding: 6px 4px;
            font-size: 0.95rem;
            color: var(--text-dark);
        }

        .po-table input:focus {
            outline: 1px solid var(--primary-green);
            background: white;
        }

        /* Payment Section */
        .payment-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .payment-item {
            display: flex;
            align-items: center;
        }

        .payment-label {
            font-weight: 600;
            margin-right: 10px;
            color: var(--text-dark);
        }

        .payment-input {
            border: none;
            border-bottom: 1px solid #ccc;
            padding: 5px 10px;
            font-size: 1rem;
            background: transparent;
            min-width: 150px;
        }

        .payment-input:focus {
            outline: none;
            border-bottom-color: var(--primary-green);
        }

        /* Signature Section */
        .signature-section {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 40px;
            margin-top: 50px;
        }

        .signature-box {
            text-align: center;
        }

        .signature-line {
            border-bottom: 1px solid #333;
            height: 60px;
            margin-bottom: 10px;
            position: relative;
            color: var(--text-dark) !important;
            -webkit-text-fill-color: var(--text-dark);
        }

        .signature-title {
            font-weight: 600;
            color: var(--text-dark) !important;
            -webkit-text-fill-color: var(--text-dark) !important;
            margin-bottom: 5px;
        }

        .signature-subtitle {
            font-size: 0.9rem;
            color: #666;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: flex-end;
        }

        .btn-po {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background-color: var(--primary-green);
            color: var(--text-white);
        }

        .btn-primary:hover {
            background-color: var(--dark-green);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: #6c757d;
            color: var(--text-white);
        }

        .btn-secondary:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }

        .btn-success {
            background-color: var(--accent-green-approved);
            color: var(--text-white);
        }

        .btn-success:hover {
            background-color: #1e7e34;
            transform: translateY(-2px);
        }

        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .po-container {
                padding: 20px;
            }

            .po-header {
                flex-direction: column;
                text-align: center;
            }

            .po-info {
                text-align: left;
                margin-top: 20px;
            }

            .signature-section {
                grid-template-columns: 1fr;
                gap: 30px;
            }


        }
    </style>
    </head>

    <body>
        <!-- Sidebar -->
        <?php include '../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="content-header page-title">
                <h1>Purchase Order</h1>

                <!-- Purchase Order Form -->
                <div class="po-container">
                    <div class="po-header">
                        <div style="text-align: center; margin-bottom: 20px;">
                        </div>
                        <div class="po-info">
                            <div class="po-info-item">
                                <span class="po-info-label">PO No.:</span>
                                <input type="text" class="po-info-input" id="poNumber" placeholder="Enter PO Number" value="<?= $edit_mode && $po_data ? htmlspecialchars($po_data['po_number']) : $next_po_number ?>">
                            </div>
                            <div class="po-info-item">
                                <span class="po-info-label">Date:</span>
                                <input type="date" class="po-info-input" id="poDate" value="<?= $edit_mode && $po_data ? $po_data['po_date'] : date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>
                    <a href="purchase_order_list.php" class="btn view-button mb-3 text-dark" style="background-color: var(--accent-orange); color: white; text-decoration: none; padding: 8px 16px; border-radius: 5px; display: inline-block; font-size: 14px;"><i class="fas fa-eye"></i> View Purchase Order List</a>

                    <div class="po-details">
                        <div class="po-details-row">
                            <span class="po-details-label">TO:</span>
                            <div class="supplier-dropdown-container">
                                <input type="text" class="supplier-input" id="supplierNameInput" placeholder="Select or Search Supplier"
                                    onfocus="showSupplierDropdown(this)"
                                    oninput="filterSuppliers(this)"
                                    onblur="hideSupplierDropdown(this)"
                                    value="<?= $edit_mode && $po_data ? htmlspecialchars($po_data['supplier_name']) : '' ?>"
                                    autocomplete="off">
                                <input type="hidden" id="supplierName" value="<?= $edit_mode && $po_data ? htmlspecialchars($po_data['supplier_name']) : '' ?>">
                                <div id="supplierDropdown" class="supplier-list">
                                    <?php foreach ($suppliers_array as $supplier): ?>
                                        <div class="supplier-item" onmousedown="selectSupplier(this, '<?= htmlspecialchars(addslashes($supplier['supplier_name'])) ?>', '<?= htmlspecialchars(addslashes($supplier['full_address'])) ?>')">
                                            <?= htmlspecialchars($supplier['supplier_name']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <a href="suppliers.php?add=1&return=purchase_order.php" class="add-supplier-item">
                                        <i class="fas fa-plus"></i> Add New Supplier
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="po-details-row">
                            <span class="po-details-label">ADDRESS:</span>
                            <input type="text" class="po-details-input" id="supplierAddress" placeholder="Enter Supplier Address" value="<?= $edit_mode && $po_data ? htmlspecialchars($po_data['supplier_address']) : '' ?>">
                        </div>
                    </div>

                    <!-- Items Table -->
                    <table class="po-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="width: 8%;">NO</th>
                                <th style="width: 40%;">ITEM DESCRIPTION</th>
                                <th style="width: 15%;">QUANTITY</th>
                                <th style="width: 17%;">UNIT COST</th>
                                <th style="width: 20%;">AMOUNT</th>
                            </tr>
                        </thead>
                        <tbody id="itemsTableBody">
                            <!-- Dynamic rows will be added here -->
                            <tr id="totalAmountRow" class="total-row" style="background-color: var(--primary-green); color: #ffffff !important; font-weight: bold;">
                                <td colspan="4" style="text-align: right; padding-right: 20px; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">TOTAL AMOUNT:</td>
                                <td id="totalAmount" style="color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">₱0.00</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Row Management Buttons -->
                    <div class="row-management" style="margin: 20px 0; text-align: center;">
                        <button type="button" class="btn-po btn-success" onclick="addPORow()">
                            <i class="fas fa-plus"></i> Add Row
                        </button>
                        <button type="button" class="btn-po btn-danger" style="background-color: var(--accent-red); color: white;" onclick="removeLastPORow()">
                            <i class="fas fa-minus"></i> Remove Row
                        </button>
                    </div>

                    <!-- Payment Section -->
                    <div class="payment-section">
                        <div class="payment-item">
                            <span class="payment-label">Payment Thru: Check</span>
                            <input type="text" class="payment-input" placeholder="Enter Check Details Here">
                        </div>
                        <div class="payment-item">
                            <span class="payment-label">Cash:</span>
                            <input type="text" id="cashAmountInput" class="payment-input" placeholder="PHP 0.00" value="PHP 0.00">
                        </div>
                    </div>

                    <!-- Signature Section -->
                    <div class="signature-section">
                        <div class="signature-box">
                            <div class="signature-line">Marilou L. Suarez</div>
                            <div class="signature-title">Prepared By:</div>
                            <div class="signature-subtitle">Purchasing Officer</div>
                        </div>
                        <div class="signature-box">
                            <div class="signature-line">Lyca E. Monterola</div>
                            <div class="signature-title">Checked By:</div>
                            <div class="signature-subtitle">Budget Officer</div>
                        </div>
                        <div class="signature-box">
                            <div class="signature-line">Dr. Delia C. Advincula</div>
                            <div class="signature-title">Approved By:</div>
                            <div class="signature-subtitle">VP for Finance and Administration</div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button type="button" class="btn-po btn-secondary" onclick="clearForm()">
                            <i class="fas fa-undo"></i> Clear
                        </button>
                        <button type="button" class="btn-po btn-success" onclick="printPO()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button type="button" class="btn-po btn-primary" onclick="savePO()">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </div>


            </div>

            <script>
                // Calculate row total when quantity or unit cost changes
                function calculateRowTotal(input) {
                    const row = input.closest('tr');
                    const quantity = parseFloat(row.cells[2].querySelector('input').value) || 0;
                    const unitCost = parseFloat(row.cells[3].querySelector('input').value) || 0;
                    const amount = quantity * unitCost;

                    row.cells[4].textContent = '₱' + amount.toFixed(2);

                    calculateGrandTotal();
                }

                // Calculate grand total
                function calculateGrandTotal() {
                    const rows = document.querySelectorAll('#itemsTable tbody tr:not(:last-child)');
                    let total = 0;

                    rows.forEach(row => {
                        const amountText = row.cells[4].textContent.replace('₱', '').replace(',', '');
                        const amount = parseFloat(amountText) || 0;
                        total += amount;
                    });

                    const formattedTotal = total.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });

                    document.getElementById('totalAmount').textContent = '₱' + formattedTotal;

                    const cashInput = document.getElementById('cashAmountInput') || document.querySelector('.payment-input[placeholder*="PHP"]');
                    if (cashInput) {
                        cashInput.value = 'PHP ' + formattedTotal;
                    }
                }

                // Clear form
                function clearForm() {
                    if (confirm('Are you sure you want to clear all data?')) {
                        document.getElementById('poNumber').value = '';
                        document.getElementById('poDate').value = '<?= date('Y-m-d') ?>';
                        document.getElementById('supplierName').value = '';
                        document.getElementById('supplierNameInput').value = '';
                        document.getElementById('supplierAddress').value = '';

                        const inputs = document.querySelectorAll('#itemsTable input');
                        inputs.forEach(input => input.value = '');

                        const amountCells = document.querySelectorAll('.amount-cell');
                        amountCells.forEach(cell => cell.textContent = '₱0.00');

                        document.getElementById('totalAmount').textContent = '₱0.00';

                        const cashInput = document.getElementById('cashAmountInput') || document.querySelector('.payment-input[placeholder*="PHP"]');
                        if (cashInput) {
                            cashInput.value = 'PHP 0.00';
                        }
                    }
                }

                // Print PO - Opens Print Preview Modal styled after Davao Central College PO Form
                function printPO() {
                    // 1. Get PO metadata
                    const poNumber = document.getElementById('poNumber')?.value.trim() || '—';
                    const poDateVal = document.getElementById('poDate')?.value;
                    let formattedDate = poDateVal || '';
                    if (poDateVal) {
                        try {
                            const dt = new Date(poDateVal + 'T00:00:00');
                            const months = ["Jan.", "Feb.", "Mar.", "Apr.", "May", "Jun.", "Jul.", "Aug.", "Sept.", "Oct.", "Nov.", "Dec."];
                            formattedDate = months[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear();
                        } catch (e) {}
                    }

                    const supplierName = document.getElementById('supplierNameInput')?.value.trim() || document.getElementById('supplierName')?.value.trim() || '';
                    const supplierAddress = document.getElementById('supplierAddress')?.value.trim() || '';
                    const checkDetails = document.querySelector('.payment-input[placeholder*="Check"]')?.value.trim() || '';
                    const cashAmount = document.querySelector('.payment-input[placeholder*="PHP"]')?.value.trim() || '';

                    // 2. Set Preview Meta Details
                    document.getElementById('pvPoNumber').textContent = poNumber;
                    document.getElementById('pvPoDate').textContent = formattedDate || '—';
                    document.getElementById('pvSupplierName').textContent = supplierName;
                    document.getElementById('pvSupplierAddress').textContent = supplierAddress;
                    document.getElementById('pvCheckDetails').textContent = checkDetails ? ' ' + checkDetails : '';
                    document.getElementById('pvCashAmount').textContent = cashAmount ? ' ' + cashAmount : '';

                    // 3. Populate Items Grid
                    const rows = document.querySelectorAll('#itemsTable tbody tr:not(:last-child)');
                    const items = [];
                    rows.forEach(row => {
                        const descInput = row.cells[1].querySelector('input') || row.cells[1].querySelector('select');
                        const desc = descInput ? descInput.value.trim() : '';
                        const qty = row.cells[2].querySelector('input')?.value.trim() || '';
                        const cost = row.cells[3].querySelector('input')?.value.trim() || '';
                        const amt = row.cells[4].textContent.replace('₱', '').trim();

                        if (desc || qty || cost || (amt && amt !== '0.00')) {
                            items.push({
                                desc: desc,
                                qty: qty,
                                cost: cost ? parseFloat(cost).toLocaleString('en-US', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }) : '',
                                amount: amt && amt !== '0.00' ? amt : ''
                            });
                        }
                    });

                    const tbody = document.getElementById('pvItemsBody');
                    tbody.innerHTML = '';

                    // Rows: minimum 10 (physical form look); more items extend onto continuation pages
                    const totalDisplayRows = Math.max(10, items.length);

                    for (let i = 0; i < totalDisplayRows; i++) {
                        const tr = document.createElement('tr');
                        if (i < items.length) {
                            const itm = items[i];
                            tr.innerHTML = `
                        <td style="text-align: center;">${i + 1}</td>
                        <td style="text-align: left; padding-left: 8px;">${escapeHtml(itm.desc)}</td>
                        <td style="text-align: center;">${escapeHtml(itm.qty)}</td>
                        <td style="text-align: right; padding-right: 8px;">${itm.cost ? itm.cost : ''}</td>
                        <td style="text-align: right; padding-right: 8px;">${itm.amount ? itm.amount : ''}</td>
                    `;
                        } else {
                            tr.innerHTML = `
                        <td style="text-align: center;">&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    `;
                        }
                        tbody.appendChild(tr);
                    }

                    // 4. Duplicate twice for printing — 3 POs per long bond paper
                    const wrap = document.getElementById('printCopiesWrap');
                    wrap.querySelectorAll('.po-print-sheet:not(#printablePurchaseOrder)').forEach(n => n.remove());
                    for (let c = 0; c < 2; c++) {
                        const clone = document.getElementById('printablePurchaseOrder').cloneNode(true);
                        clone.removeAttribute('id');
                        clone.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
                        clone.classList.add('po-copy');
                        wrap.appendChild(clone);
                    }

                    // Dynamic row compression: fit ALL items inside one half-sheet
                    // (~290px budget for rows after signatory margins; shrink rows/fonts as items grow)
                    const rowH = Math.max(8, Math.min(15, Math.floor(290 / Math.max(10, items.length))));
                    wrap.style.setProperty('--po-row-h', rowH + 'px');
                    wrap.style.setProperty('--po-td-fs', rowH < 11 ? '7.5pt' : '9pt');

                    // Show the hidden print container only during the print call
                    wrap.style.display = 'block';

                    window.print();

                    // Restore after printing: remove duplicate copies, hide container
                    wrap.querySelectorAll('.po-copy').forEach(n => n.remove());
                    wrap.style.display = 'none';
                }

                function escapeHtml(text) {
                    if (!text) return '';
                    const map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    };
                    return text.toString().replace(/[&<>"']/g, m => map[m]);
                }

                // Save PO
                function savePO() {
                    const poNumber = document.getElementById('poNumber').value;
                    const poDate = document.getElementById('poDate').value;
                    const supplierName = document.getElementById('supplierName').value;
                    const supplierAddress = document.getElementById('supplierAddress').value;
                    const paymentDetails = document.querySelector('.payment-input[placeholder="Enter Check Details Here"]').value;
                    const cashAmount = (document.getElementById('cashAmountInput') || document.querySelector('.payment-input[placeholder*="PHP"]'))?.value || '';

                    if (!poNumber || !poDate || !supplierName) {
                        alert('Please fill in all required fields (PO Number, Date, and Supplier Name)');
                        return;
                    }

                    const items = [];
                    const rows = document.querySelectorAll('#itemsTable tbody tr:not(:last-child)');
                    rows.forEach((row, index) => {
                        const descriptionElement = row.cells[1].querySelector('select') || row.cells[1].querySelector('input');
                        const description = descriptionElement ? descriptionElement.value : '';
                        const quantity = parseFloat(row.cells[2].querySelector('input').value) || 0;
                        const unit_cost = parseFloat(row.cells[3].querySelector('input').value) || 0;

                        if (description && (quantity > 0 || unit_cost > 0)) {
                            items.push({
                                description: description,
                                quantity: quantity,
                                unit_cost: unit_cost
                            });
                        }
                    });

                    const data = {
                        po_number: poNumber,
                        po_date: poDate,
                        supplier_name: supplierName,
                        supplier_address: supplierAddress,
                        payment_method: 'Check',
                        payment_details: paymentDetails,
                        cash_amount: parseFloat(cashAmount.replace(/[^\d.-]/g, '')) || 0,
                        items: items
                    };

                    // Add po_id if in edit mode
                    const urlParams = new URLSearchParams(window.location.search);
                    const editId = urlParams.get('edit');
                    if (editId) {
                        data.po_id = editId;
                    }

                    // Debug: Log the data being sent
                    console.log('Data being sent:', data);
                    console.log('JSON string:', JSON.stringify(data));

                    // Show loading state
                    const saveBtn = document.querySelector('.btn-primary');
                    const originalText = saveBtn.innerHTML;
                    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                    saveBtn.disabled = true;

                    fetch('../actions/save_purchase_order.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(data)
                        })
                        .then(response => response.json())
                        .then(result => {
                            if (result.success) {
                                alert('Purchase order saved successfully!\nPO Number: ' + (result.po_number || data.po_number));
                                // Optionally refresh the existing PO dropdown
                                location.reload();
                            } else {
                                alert('Failed to save purchase order: ' + result.message);
                            }
                        })
                        .catch(error => {
                            alert('Error saving purchase order: ' + error.message);
                        })
                        .finally(() => {
                            // Restore button state
                            saveBtn.innerHTML = originalText;
                            saveBtn.disabled = false;
                        });
                }

                // Load existing PO
                function loadExistingPO() {
                    const poId = document.getElementById('existingPOSelect').value;

                    if (!poId) {
                        alert('Please select a purchase order to load');
                        return;
                    }

                    fetch('../actions/load_purchase_order.php?po_id=' + poId)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Fill form fields
                                document.getElementById('poNumber').value = data.po_number;
                                document.getElementById('poDate').value = data.po_date;
                                document.getElementById('supplierName').value = data.supplier_name;
                                document.getElementById('supplierNameInput').value = data.supplier_name;
                                document.getElementById('supplierAddress').value = data.supplier_address || '';

                                // Fill payment details
                                if (data.payment_details) {
                                    document.querySelector('.payment-input[placeholder="Enter Check Details Here"]').value = data.payment_details;
                                }
                                if (data.cash_amount > 0) {
                                    document.querySelector('.payment-input[placeholder="PHP 0.00"]').value = 'PHP ' + data.cash_amount.toFixed(2);
                                }

                                // Clear existing items
                                const rows = document.querySelectorAll('#itemsTable tbody tr:not(:last-child)');
                                rows.forEach(row => {
                                    row.cells[1].querySelector('input').value = '';
                                    row.cells[2].querySelector('input').value = '';
                                    row.cells[3].querySelector('input').value = '';
                                    row.cells[4].textContent = '₱0.00';
                                });

                                // Fill items
                                data.items.forEach((item, index) => {
                                    if (index < rows.length) {
                                        const row = rows[index];
                                        row.cells[1].querySelector('input').value = item.item_description;
                                        row.cells[2].querySelector('input').value = item.quantity;
                                        row.cells[3].querySelector('input').value = item.unit_cost;
                                        row.cells[4].textContent = '₱' + item.line_total.toFixed(2);
                                    }
                                });

                                calculateGrandTotal();
                                alert('Purchase order loaded successfully!');
                            } else {
                                alert('Failed to load purchase order: ' + data.message);
                            }
                        })
                        .catch(error => {
                            alert('Error loading purchase order: ' + error.message);
                        });
                }

                // Load canvass items for dropdown
                let canvassItems = [];
                let suppliers = [];

                function loadSuppliers() {
                    fetch('../api/get_suppliers.php')
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok: ' + response.statusText);
                            }
                            const contentType = response.headers.get('content-type');
                            if (!contentType || !contentType.includes('application/json')) {
                                return response.text().then(text => {
                                    throw new Error('Expected JSON but received: ' + text.substring(0, 100));
                                });
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) {
                                suppliers = data.suppliers;
                            } else {
                                console.error('Failed to load suppliers:', data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error loading suppliers:', error.message);
                        });
                }

                function loadCanvassItems() {
                    fetch('../api/get_canvass_items.php')
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok: ' + response.statusText);
                            }
                            const contentType = response.headers.get('content-type');
                            if (!contentType || !contentType.includes('application/json')) {
                                return response.text().then(text => {
                                    throw new Error('Expected JSON but received: ' + text.substring(0, 100));
                                });
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) {
                                canvassItems = data.items;
                            } else {
                                console.error('Failed to load canvass items:', data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error loading canvass items:', error.message);
                        });
                }

                // Build a unique datalist id for each row
                let datalistCounter = 0;

                // Add new row to the purchase order table (unlimited — print splits into continuation pages)
                function addPORow() {
                    const tbody = document.getElementById('itemsTableBody');
                    const totalRow = tbody.lastElementChild;
                    const rowCount = tbody.querySelectorAll('tr:not(:last-child)').length + 1;
                    const listId = 'canvass-list-' + (++datalistCounter);

                    // Build datalist options from canvass items
                    const uniqueDescriptions = [...new Set(canvassItems.map(item => item.description))];
                    let datalistHtml = `<datalist id="${listId}">`;
                    uniqueDescriptions.forEach(d => {
                        datalistHtml += `<option value="${d}"></option>`;
                    });
                    datalistHtml += '</datalist>';

                    const newRow = document.createElement('tr');
                    newRow.innerHTML = `
            <td>${rowCount}</td>
            <td>
                ${datalistHtml}
                <input type="text" class="form-control form-control-sm" list="${listId}"
                    placeholder="Type or choose from canvass list"
                    oninput="onItemSelect(this)" onchange="onItemSelect(this)">
            </td>
            <td><input type="text" placeholder="0" oninput="calculateRowTotal(this)"></td>
            <td><input type="number" placeholder="0.00" oninput="calculateRowTotal(this)" min="0" step="0.01"></td>
            <td class="amount-cell">₱0.00</td>
        `;

                    // Insert before the total row
                    tbody.insertBefore(newRow, totalRow);
                }

                // Searchable Dropdown Functions
                function showSupplierDropdown(input) {
                    const list = input.nextElementSibling.nextElementSibling; // Skip hidden input
                    list.style.display = 'block';
                }

                function hideSupplierDropdown(input) {
                    const list = input.nextElementSibling.nextElementSibling;
                    // Use timeout to allow mousedown on items to fire first
                    setTimeout(() => {
                        list.style.display = 'none';
                    }, 200);
                }

                function filterSuppliers(input) {
                    const filter = input.value.toLowerCase();
                    const list = input.nextElementSibling.nextElementSibling;
                    const items = list.getElementsByClassName('supplier-item');

                    for (let i = 0; i < items.length; i++) {
                        const txtValue = items[i].textContent || items[i].innerText;
                        if (txtValue.toLowerCase().indexOf(filter) > -1) {
                            items[i].style.display = "";
                        } else {
                            items[i].style.display = "none";
                        }
                    }
                }

                function selectSupplier(item, name, address) {
                    const container = item.closest('.supplier-dropdown-container');
                    const input = container.querySelector('.supplier-input');
                    const hiddenInput = document.getElementById('supplierName');
                    const addressInput = document.getElementById('supplierAddress');

                    input.value = name;
                    hiddenInput.value = name;
                    addressInput.value = address;

                    container.querySelector('.supplier-list').style.display = 'none';
                }

                // Initialize
                // Auto-fill item details based on description and selected supplier
                function autoFillItemDetails(selectElement) {
                    const row = selectElement.closest('tr');
                    const description = selectElement.value;
                    const supplierName = document.getElementById('supplierName').value;

                    if (!description || !supplierName) return;

                    // Find match for item + supplier
                    const matchedItem = canvassItems.find(item =>
                        item.description === description && item.supplier === supplierName
                    );

                    const quantityInput = row.cells[2].querySelector('input');
                    const unitCostInput = row.cells[3].querySelector('input');

                    if (matchedItem) {
                        quantityInput.value = matchedItem.quantity;
                        unitCostInput.value = matchedItem.unit_cost;
                    } else {
                        // Optional: clear if no match found for this supplier, or keep empty
                        quantityInput.value = '';
                        unitCostInput.value = '';
                    }

                    // Recalculate total immediately
                    calculateRowTotal(selectElement);
                }

                // Add row with existing data for edit mode
                function addPORowWithData(itemNumber, description, quantity, unitCost) {
                    const tbody = document.getElementById('itemsTableBody');
                    const totalRow = tbody.lastElementChild;
                    const listId = 'canvass-list-' + (++datalistCounter);

                    // Build datalist options from canvass items
                    const uniqueDescriptions = [...new Set(canvassItems.map(item => item.description))];
                    let datalistHtml = `<datalist id="${listId}">`;
                    uniqueDescriptions.forEach(d => {
                        datalistHtml += `<option value="${d}"></option>`;
                    });
                    datalistHtml += '</datalist>';

                    const newRow = document.createElement('tr');
                    newRow.innerHTML = `
            <td>${itemNumber}</td>
            <td>
                ${datalistHtml}
                <input type="text" class="form-control form-control-sm" list="${listId}"
                    placeholder="Type or choose from canvass list"
                    oninput="onItemSelect(this)" onchange="onItemSelect(this)">
            </td>
            <td><input type="text" placeholder="0" oninput="calculateRowTotal(this)"></td>
            <td><input type="number" placeholder="0.00" oninput="calculateRowTotal(this)" min="0" step="0.01"></td>
            <td class="amount-cell">₱0.00</td>
        `;

                    // Insert before the total row
                    tbody.insertBefore(newRow, totalRow);

                    // Populate the description input and qty/cost
                    newRow.cells[1].querySelector('input[type="text"]').value = description;
                    const quantityInput = newRow.cells[2].querySelector('input');
                    const unitCostInput = newRow.cells[3].querySelector('input');

                    quantityInput.value = quantity;
                    unitCostInput.value = unitCost;

                    // Calculate and display total
                    const lineTotal = quantity * unitCost;
                    newRow.cells[4].textContent = '₱' + lineTotal.toFixed(2);

                    // Recalculate grand total
                    calculateGrandTotal();
                }

                // Remove last row from the purchase order table
                function removeLastPORow() {
                    const tbody = document.getElementById('itemsTableBody');
                    const rows = tbody.querySelectorAll('tr:not(:last-child)'); // Exclude total row

                    if (rows.length > 1) {
                        rows[rows.length - 1].remove();
                        // Update row numbers
                        updateRowNumbers();
                        calculateGrandTotal();
                    } else {
                        alert('At least one row must remain.');
                    }
                }

                // Update row numbers after adding/removing rows
                function updateRowNumbers() {
                    const tbody = document.getElementById('itemsTableBody');
                    const rows = tbody.querySelectorAll('tr:not(:last-child)');

                    rows.forEach((row, index) => {
                        row.cells[0].textContent = index + 1;
                    });
                }

                // Initialize with one empty row or load existing data
                document.addEventListener('DOMContentLoaded', function() {
                    // Load suppliers and canvass items first
                    loadSuppliers();
                    loadCanvassItems();

                    <?php if ($edit_mode && !empty($po_items)): ?>
                        // Wait for canvass items to load before adding existing PO items
                        setTimeout(() => {
                            <?php foreach ($po_items as $item): ?>
                                addPORowWithData(
                                    <?= floatval($item['item_number']) ?>,
                                    <?= json_encode($item['item_description']) ?>,
                                    <?= floatval($item['quantity']) ?>,
                                    <?= floatval($item['unit_cost']) ?>
                                );
                            <?php endforeach; ?>
                        }, 500);
                    <?php else: ?>
                        // PO number is pre-filled server-side; pre-fill 10 blank rows (physical form layout)
                        setTimeout(() => {
                            for (let i = 0; i < 10; i++) {
                                addPORow();
                            }
                        }, 500);
                    <?php endif; ?>
                });

                // Generate new PO number
                function generateNewPONumber() {
                    fetch('../actions/generate_po_number.php')
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('poNumber').value = data.po_number;
                                // Clear form for new PO
                                clearForm();
                                document.getElementById('poNumber').value = data.po_number;
                                document.getElementById('existingPOSelect').value = '';
                            } else {
                                alert('Failed to generate PO number: ' + data.message);
                            }
                        })
                        .catch(error => {
                            alert('Error generating PO number: ' + error.message);
                        });
                }
                // Autofill item details, supplier info, and calculate total
                function onItemSelect(select) {
                    const row = select.closest('tr');
                    const description = select.value;
                    const item = canvassItems.find(i => i.description === description);

                    if (item) {
                        // Fill quantity and unit cost
                        row.cells[2].querySelector('input').value = item.quantity;
                        row.cells[3].querySelector('input').value = item.unit_cost;

                        // Always update supplier (TO:) and Address based on selected item
                        const supplierNameInput = document.getElementById('supplierNameInput');
                        const supplierNameHidden = document.getElementById('supplierName');
                        const supplierAddressInput = document.getElementById('supplierAddress');

                        if (item.supplier && supplierNameInput) {
                            supplierNameInput.value = item.supplier;
                            supplierNameHidden.value = item.supplier;

                            // Find matching address from the rendered supplier dropdown items
                            const allSupplierItems = document.querySelectorAll('#supplierDropdown .supplier-item');
                            for (const el of allSupplierItems) {
                                const onmd = el.getAttribute('onmousedown') || '';
                                if (onmd.includes(item.supplier)) {
                                    const match = onmd.match(/selectSupplier\(this,\s*'[^']*',\s*'([^']*)'\)/);
                                    if (match && supplierAddressInput) {
                                        supplierAddressInput.value = match[1];
                                    }
                                    break;
                                }
                            }
                        }
                    } else if (description === '') {
                        // Clear row data if empty selection
                        row.cells[2].querySelector('input').value = '';
                        row.cells[3].querySelector('input').value = '';
                    }

                    calculateRowTotal(select);
                }
            </script>

            <!-- Hidden print container (shown only during the print call) -->

            <div id="printCopiesWrap" style="display: none;">
                <div id="printablePurchaseOrder" class="po-print-sheet">
                    <!-- Top Header Section -->
                    <div class="dcc-header">
                        <div class="dcc-logo-wrap">
                            <img src="<?= htmlspecialchars($school_logo) ?>" alt="DCC Logo" class="dcc-logo">
                            <div class="accreditation-tag">ACSCU-ACI ACCREDITED</div>
                        </div>
                        <div class="dcc-title-wrap">
                            <h1 class="dcc-school-name"><?= htmlspecialchars($school_name) ?></h1>
                            <h2 class="dcc-office-name">Purchasing Office</h2>
                            <div class="dcc-address"><?= htmlspecialchars($school_address) ?></div>
                        </div>
                        <div class="dcc-po-box">
                            <div class="po-box-line">PO - 001</div>
                            <div class="po-box-line">Revision: 3</div>
                            <div class="po-box-line">Date Revised:</div>
                            <div class="po-box-line">August 2025</div>
                        </div>
                    </div>

                    <!-- Dark Contact Ribbon -->
                    <div class="dcc-dark-banner">
                        <span>• Email Address: <?= htmlspecialchars($school_email) ?></span>
                        <span>• Website: <?= htmlspecialchars($school_website) ?></span>
                    </div>

                    <!-- Purchase Order Title -->
                    <div class="dcc-po-banner">
                        PURCHASE ORDER
                    </div>

                    <!-- Supplier & Order Meta Table -->
                    <table class="dcc-meta-table">
                        <tr>
                            <td class="meta-left">
                                <span class="meta-label">TO:</span>
                                <span class="meta-value" id="pvSupplierName"></span>
                            </td>
                            <td class="meta-right">
                                <span class="meta-label">PO No.</span>
                                <span class="meta-value" id="pvPoNumber"></span>
                            </td>
                        </tr>
                        <tr>
                            <td class="meta-left">
                                <span class="meta-label">ADDRESS:</span>
                                <span class="meta-value" id="pvSupplierAddress"></span>
                            </td>
                            <td class="meta-right">
                                <span class="meta-label">Date:</span>
                                <span class="meta-value" id="pvPoDate"></span>
                            </td>
                        </tr>
                    </table>

                    <!-- Delivery Condition Notice -->
                    <div class="dcc-condition-notice">
                        Please deliver the item/s enumerated below under the terms and condition of sale agreed upon to wit:
                    </div>

                    <!-- Items Grid Table (flex-grow to fill remaining space) -->
                    <div class="dcc-items-wrap">
                        <table class="dcc-items-grid">
                            <thead>
                                <tr>
                                    <th style="width: 7%;">NO</th>
                                    <th style="width: 53%;">ITEM DESCRIPTION</th>
                                    <th style="width: 12%;">QUANTITY</th>
                                    <th style="width: 13%;">UNIT COST</th>
                                    <th style="width: 15%;">AMOUNT</th>
                                </tr>
                            </thead>
                            <tbody id="pvItemsBody">
                                <!-- Populated dynamically with rows & filler grid lines -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Bottom: Payment + Signatures pinned to bottom -->
                    <div class="dcc-bottom-section">
                        <!-- Payment Section -->
                        <div class="dcc-payment-row">
                            <div class="payment-check">
                                Payment Thru: Check <span class="payment-val-line" id="pvCheckDetails"></span>
                            </div>
                            <div class="payment-cash">
                                Cash: Php <span class="payment-val-line" id="pvCashAmount"></span>
                            </div>
                        </div>

                        <!-- Signatures Section: line ABOVE the name (signature in upper part, name below) -->
                        <div class="dcc-signatures-grid">
                            <div class="dcc-sig-col">
                                <div class="sig-header">Prepared By:</div>
                                <div class="sig-underline"></div>
                                <div class="sig-name">Marilou L. Suarez</div>
                                <div class="sig-title">Purchasing Officer</div>
                            </div>
                            <div class="dcc-sig-col">
                                <div class="sig-header">Checked By:</div>
                                <div class="sig-underline"></div>
                                <div class="sig-name">Lyca E. Monterola</div>
                                <div class="sig-title">Budget Officer</div>
                            </div>
                            <div class="dcc-sig-col">
                                <div class="sig-header">Approved By:</div>
                                <div class="sig-underline"></div>
                                <div class="sig-name">Dr. Delia C. Advincula</div>
                                <div class="sig-title">VP for Finance and Administration</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Print & Print Preview Styles -->
            <style>
                /* Printable Sheet Formatting */
                .po-print-sheet {
                    background: #ffffff;
                    width: 100%;
                    max-width: 720px;
                    padding: 14px 20px;
                    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.18);
                    box-sizing: border-box;
                    font-family: Arial, Helvetica, sans-serif;
                    color: #000000;
                    line-height: 1.2;
                    font-size: 13pt;
                    /* Flex column so items grow and sigs pin to bottom */
                    display: flex;
                    flex-direction: column;
                    min-height: 620px;
                }

                /* Items table wrapper grows to fill space */
                .dcc-items-wrap {
                    flex: 1 1 auto;
                    display: flex;
                    flex-direction: column;
                }

                .dcc-items-wrap .dcc-items-grid {
                    flex: 1 1 auto;
                }

                /* Bottom section (payment + sigs) stays at bottom */
                .dcc-bottom-section {
                    margin-top: auto;
                }

                /* DCC Header */
                .dcc-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 4px;
                }

                .dcc-logo-wrap {
                    text-align: center;
                    width: 115px;
                    flex-shrink: 0;
                }

                .dcc-logo {
                    width: 78px;
                    height: 78px;
                    object-fit: contain;
                    display: block;
                    margin: 0 auto;
                }

                .accreditation-tag {
                    font-size: 11.5pt;
                    font-weight: bold;
                    color: #000;
                    letter-spacing: 0.3px;
                    margin-top: 3px;
                    text-transform: uppercase;
                }

                .dcc-title-wrap {
                    text-align: center;
                    flex: 1;
                    padding: 0 6px;
                }

                .dcc-school-name {
                    font-size: 24pt;
                    font-weight: 800;
                    text-transform: uppercase;
                    margin: 0;
                    color: #000;
                    letter-spacing: 0.5px;
                    line-height: 1.1;
                }

                .dcc-office-name {
                    font-size: 17pt;
                    font-weight: 700;
                    margin: 3px 0 2px 0;
                    color: #000;
                }

                .dcc-address {
                    font-size: 13pt;
                    color: #111;
                }

                .dcc-po-box {
                    border: 2px solid #000;
                    padding: 5px 9px;
                    border-radius: 4px;
                    font-size: 13pt;
                    font-weight: bold;
                    line-height: 1.5;
                    text-align: left;
                    min-width: 110px;
                    flex-shrink: 0;
                }

                /* Dark Contact Ribbon */
                .dcc-dark-banner {
                    background-color: #2b332d;
                    color: #ffffff;
                    font-size: 11pt;
                    font-weight: 600;
                    display: flex;
                    justify-content: space-between;
                    padding: 2px 10px;
                    margin-top: 4px;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }

                /* PO Center Title */
                .dcc-po-banner {
                    border: 1px solid #000;
                    border-top: none;
                    text-align: center;
                    font-size: 13pt;
                    font-weight: bold;
                    letter-spacing: 1px;
                    padding: 2px 0;
                    background: #fff;
                }

                /* Meta Table */
                .dcc-meta-table {
                    width: 100%;
                    border-collapse: collapse;
                    border: 1px solid #000;
                    border-top: none;
                }

                .dcc-meta-table td {
                    border: 1px solid #000;
                    padding: 4px 6px;
                    font-size: 12.5pt;
                    vertical-align: middle;
                }

                .dcc-meta-table .meta-left {
                    width: 65%;
                }

                .dcc-meta-table .meta-right {
                    width: 35%;
                }

                .dcc-meta-table .meta-label {
                    font-weight: bold;
                    margin-right: 5px;
                    color: #000;
                }

                .dcc-meta-table .meta-value {
                    font-weight: 600;
                    color: #000;
                }

                /* Condition Notice */
                .dcc-condition-notice {
                    font-size: 10.5pt;
                    font-style: italic;
                    border: 1px solid #000;
                    border-top: none;
                    padding: 3px 6px;
                    background: #fff;
                    color: #000;
                }

                /* Items Grid Table */
                .dcc-items-grid {
                    width: 100%;
                    border-collapse: collapse;
                    border: 1px solid #000;
                    border-top: none;
                }

                .dcc-items-grid th {
                    border: 1px solid #000;
                    padding: 5px 5px;
                    font-size: 11.5pt;
                    font-weight: bold;
                    text-align: center;
                    background-color: #fff;
                    color: #000;
                }

                .dcc-items-grid td {
                    border: 1px solid #000;
                    padding: 3px 5px;
                    font-size: 12.5pt;
                    height: 30px;
                    color: #000;
                    box-sizing: border-box;
                }

                /* Payment Row */
                .dcc-payment-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 5px 4px 3px 4px;
                    font-size: 12.5pt;
                    font-weight: bold;
                    color: #000;
                }

                .payment-val-line {
                    display: inline-block;
                    min-width: 140px;
                    border-bottom: 1px solid #000;
                    padding: 0 4px;
                    font-weight: normal;
                }

                /* Signatures Grid */
                .dcc-signatures-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr 1fr;
                    gap: 15px;
                    margin-top: 14px;
                }

                .dcc-sig-col {
                    text-align: center;
                }

                .dcc-sig-col .sig-name {
                    font-size: 12pt;
                    font-weight: 600;
                    color: #000000;
                    margin-bottom: 3px;
                    min-height: 12px;
                }

                .dcc-sig-col .sig-underline {
                    border-bottom: 1px solid #000000;
                    margin-bottom: 3px;
                    width: 100%;
                    height: 8px;
                }

                .dcc-sig-col .sig-header {
                    font-size: 12pt;
                    font-weight: 700;
                    color: #073b1d;
                    margin-bottom: 1px;
                    text-align: center;
                }

                .dcc-sig-col .sig-title {
                    font-size: 11pt;
                    color: #444444;
                    text-align: center;
                }

                /* ── Print Media Query ── */
                @media print {
                    @page {
                        /* Long bond paper: 8.5in × 13in portrait — 2 POs per page, 3rd PO on page 2 */
                        size: 8.5in 13in;
                        margin: 3mm 5mm;
                    }

                    html,
                    body {
                        background: #ffffff !important;
                        margin: 0 !important;
                        padding: 0 !important;
                    }

                    body * {
                        visibility: hidden !important;
                    }

                    #printCopiesWrap,
                    #printablePurchaseOrder,
                    #printablePurchaseOrder *,
                    .po-copy,
                    .po-copy * {
                        visibility: visible !important;
                    }

                    #printCopiesWrap {
                        display: block !important;
                        position: static !important;
                        width: 100% !important;
                        height: auto !important;
                        background: transparent !important;
                        padding: 0 !important;
                        margin: 0 !important;
                    }

                    /* Fill the portrait letter sheet (8.5in × 11in) */
                    .po-print-sheet {
                        box-shadow: none !important;
                        padding: 4px 8px !important;
                        margin: 0 !important;
                        width: 100% !important;
                        max-width: 100% !important;
                        /* Half of long bond: (13in - 6mm margins - 0.25in cut gap) / 2 per PO */
                        min-height: calc((13in - 6mm - 0.25in) / 2) !important;
                        page-break-inside: avoid;
                        break-inside: avoid;
                        transform: none;
                    }

                    /* Copies 2 & 3 sit below with dashed cut lines */
                    .po-copy {
                        margin-top: 0.25in !important;
                        border-top: 1px dashed #999999;
                    }

                    /* 3rd PO forced onto page 2 (its own long bond sheet) */
                    .po-copy:nth-of-type(3) {
                        page-break-before: always;
                        break-before: page;
                        margin-top: 0 !important;
                    }

                    /* ── Fit whole PO inside each 6.5in half-sheet ── */

                    /* Table stretches to fill the half-sheet (rows grow);
                       margin below it pushes payment inputs + signatures to the sheet bottom */
                    .dcc-items-wrap {
                        flex: 1 1 auto;
                        margin-bottom: 14px;
                    }

                    .dcc-payment-row {
                        padding: 2px 4px 1px 4px;
                        font-size: 8.5pt;
                    }

                    .dcc-logo {
                        width: 54px;
                        height: 54px;
                    }

                    .accreditation-tag {
                        font-size: 8pt;
                    }

                    .dcc-school-name {
                        font-size: 16pt;
                    }

                    .dcc-office-name {
                        font-size: 12pt;
                        margin: 1px 0 1px 0;
                    }

                    .dcc-address {
                        font-size: 9pt;
                    }

                    .dcc-po-box {
                        font-size: 9.5pt;
                        padding: 2px 5px;
                        min-width: 90px;
                        line-height: 1.25;
                    }

                    .dcc-header {
                        margin-bottom: 2px;
                    }

                    .dcc-dark-banner {
                        font-size: 8pt;
                        padding: 1px 8px;
                    }

                    .dcc-po-banner {
                        font-size: 10pt;
                        padding: 1px 0;
                    }

                    .dcc-meta-table td {
                        font-size: 9pt;
                        padding: 1px 5px;
                    }

                    .dcc-condition-notice {
                        font-size: 7.5pt;
                        padding: 1px 5px;
                    }

                    .dcc-items-grid th {
                        padding: 1px 3px;
                        font-size: 8pt;
                    }

                    .dcc-items-grid td {
                        height: var(--po-row-h, 15px);
                        padding: 0 3px;
                        font-size: var(--po-td-fs, 9pt);
                    }

                    .dcc-signatures-grid {
                        /* Airy spacing around/inside the signatories (elderly-readable) */
                        gap: 16px;
                        margin-top: 18px;
                        padding: 0 8px 6px 8px;
                    }

                    .dcc-sig-col .sig-header {
                        font-size: 10pt;
                        /* Signing space between label and the line (sign above the name) */
                        margin-bottom: 40px;
                    }

                    .dcc-sig-col .sig-underline {
                        height: 8px;
                        margin-bottom: 4px;
                    }

                    .dcc-sig-col .sig-name {
                        font-size: 10.5pt;
                        margin-bottom: 3px;
                        min-height: 0;
                    }

                    .dcc-sig-col .sig-title {
                        font-size: 9pt;
                    }

                    .sidebar,
                    .content-header,
                    .po-container,
                    .view-button,
                    .page-title,
                    .action-buttons {
                        display: none !important;
                    }

                    /* Reset screen layout offsets so the print container spans the full page */
                    .main-content {
                        margin-left: 0 !important;
                        padding: 0 !important;
                        width: 100% !important;
                    }
                }
            </style>

            <?php include '../includes/footer.php'; ?>
    </body>

</html>