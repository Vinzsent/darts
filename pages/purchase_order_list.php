<?php
// Show all errors on this page for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$pageTitle = 'Purchase Order List';
include '../includes/auth.php';
include '../includes/db.php';
include '../includes/header.php';

$user_type = $_SESSION['user_type'] ?? '';
$user_role_norm = str_replace([' ', '-'], '', strtolower($user_type));

// Editing a purchase order corrects wrongly-entered header/line details, so it is
// limited to the purchasing roles that own the document, plus Admin.
// Office roles (Supply In-charge, Property Custodian) only receive items, so they
// must not be able to rewrite the order itself.
$purchasing_roles = [
    'admin',
    'administrator',
    'purchasingofficer',
    'purchasingstaff',
    'purchaser',
    'purchasing',
];
$can_edit_po = in_array($user_role_norm, $purchasing_roles, true);

// Where received items land in stock is fixed by the user's office:
//   Supply In-charge  -> inventory
//   Property Custodian-> property_inventory
// Other roles (admin, purchasing officer) get a manual choice in the modal.
$role_target_locked = in_array($user_role_norm, ['supplyincharge', 'propertycustodian'], true);
$role_target = ($user_role_norm === 'propertycustodian') ? 'property' : 'supply';
$role_target_label = ($role_target === 'property')
    ? 'Property Office Inventory'
    : 'Supply Office Inventory';

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

// Search: match PO number, supplier, or any line item description.
$search_term = trim($_GET['search'] ?? '');

// Server-side pagination: 20 purchase orders per page via ?page=N.
// The search form only submits `search`, so a new search always lands on page 1.
$per_page = 5;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

$po_query = "
    SELECT 
        p.po_id,
        p.po_number,
        p.po_date,
        p.supplier_name,
        p.supplier_address,
        p.total_amount,
        p.status,
        p.notes,
        p.created_at,
        p.received_date,
        p.received_by,
        p.received_notes,
        $created_by_expr as created_by_name,
        MAX(COALESCE($receiver_name_expr, ru.username, NULLIF(p.received_by, ''), '')) as received_by_name,
        MAX(COALESCE(ru.user_type, '')) as received_by_role,
        COUNT(poi.poi_id) as item_count,
        SUM(CASE WHEN poi.is_received = 1 THEN 1 ELSE 0 END) as received_item_count
    FROM purchase_orders p
    LEFT JOIN `user` u ON p.created_by = u.id
    LEFT JOIN `user` ru ON CAST(p.received_by AS UNSIGNED) = ru.id
    LEFT JOIN purchase_order_items poi ON p.po_id = poi.po_id
    WHERE (
        ? = ''
        OR p.po_number LIKE ?
        OR p.supplier_name LIKE ?
        OR EXISTS (
            SELECT 1 FROM purchase_order_items si
            WHERE si.po_id = p.po_id AND si.item_description LIKE ?
        )
    )
    GROUP BY p.po_id, p.po_number, p.po_date, p.supplier_name, p.supplier_address,
             p.total_amount, p.status, p.notes, p.created_at, p.received_date, p.received_by, p.received_notes,
             $created_by_expr
    ORDER BY
        -- Items already received (e.g. by the property custodian) come first,
        -- fully-received POs ahead of partially-received ones, then newest.
        (SUM(CASE WHEN poi.is_received = 1 THEN 1 ELSE 0 END) > 0) DESC,
        (SUM(CASE WHEN poi.is_received = 1 THEN 1 ELSE 0 END) = COUNT(poi.poi_id)) DESC,
        p.created_at DESC
";

$like = '%' . $search_term . '%';

// Count query wraps the exact same SELECT/GROUP BY so the total always matches
// what the table would render without LIMIT (one grouped row per purchase
// order). The trailing ORDER BY is dropped because it adds nothing to a count.
$order_pos = strrpos($po_query, 'ORDER BY');
$po_base_query = ($order_pos !== false) ? substr($po_query, 0, $order_pos) : $po_query;
$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM ($po_base_query) AS cnt");
if (!$count_stmt) {
    die("Database error preparing count query: " . $conn->error);
}
$count_stmt->bind_param("ssss", $search_term, $like, $like, $like);
$count_stmt->execute();
$total_records = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$total_pages = max(1, (int)ceil($total_records / $per_page));
if ($page > $total_pages) {
    $page = $total_pages; // clamp (e.g. after the search narrows the results)
}
$offset = ($page - 1) * $per_page;

$po_stmt = $conn->prepare($po_query . " LIMIT ? OFFSET ?");
if (!$po_stmt) {
    die("Database error preparing query: " . $conn->error);
}
// Placeholder order is LIMIT then OFFSET: per_page first, offset second.
$po_stmt->bind_param("ssssii", $search_term, $like, $like, $like, $per_page, $offset);
$po_stmt->execute();
$po_result = $po_stmt->get_result();

// Materialise the current page first, so the expandable sub-rows below only
// load line items for the purchase orders actually visible on this page.
$po_rows = [];
while ($r = $po_result->fetch_assoc()) {
    $po_rows[] = $r;
}

// Items grouped by PO (for the click-to-expand sub-rows)
$items_map = [];
$page_po_ids = array_map('intval', array_column($po_rows, 'po_id'));
if ($page_po_ids) {
    $ids_csv = implode(',', $page_po_ids);
    $items_result = $conn->query("SELECT po_id, item_number, item_description, quantity, unit_cost, line_total, is_received, received_date FROM purchase_order_items WHERE po_id IN ($ids_csv) ORDER BY po_id, item_number ASC");
    if ($items_result) {
        while ($it = $items_result->fetch_assoc()) {
            $items_map[$it['po_id']][] = $it;
        }
    }
}

// Letterhead for the Receiving Report (same source as purchase_order.php)
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

/**
 * Compose a full name from an employee row (First M. Last).
 * Middle names are shown as an initial so the signature line stays short.
 */
function build_full_name($row)
{
    if (empty($row)) {
        return '';
    }
    $first = trim((string)($row['first_name'] ?? ''));
    $middle = trim((string)($row['middle_name'] ?? ''));
    $last = trim((string)($row['last_name'] ?? ''));

    $parts = array_filter([$first]);
    if ($middle !== '') {
        $parts[] = strtoupper(substr($middle, 0, 1)) . '.';
    }
    $parts[] = $last;

    $name = trim(implode(' ', array_filter($parts)));
    return $name !== '' ? $name : trim((string)($row['username'] ?? ''));
}

// "Inspected By" is whoever is logged in and printing the report.
$inspector_name = build_full_name($_SESSION['user'] ?? []);
if ($inspector_name === '') {
    $inspector_name = trim((string)($_SESSION['name'] ?? ''));
}

// "Verified By" is the Purchasing Officer who signs off on receipts.
// The middle initial is not stored in the employees record, so the full printed
// name is fixed here rather than derived from the (blank) middle_name field.
$verifier_name = 'Marilou L. Suarez';
?>

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

    /* List Container */
    .list-container {
        background: var(--text-white);
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .list-header {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
        color: var(--text-white);
        padding: 20px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .list-title {
        font-size: 1.5rem;
        font-weight: 600;
        margin: 0;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-primary {
        background-color: var(--accent-orange);
        color: var(--text-white);
    }

    .btn-primary:hover {
        background-color: #e8690b;
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

    /* Table Styles */
    .po-table {
        width: 100%;
        border-collapse: collapse;
        margin: 0;
        color: #111827 !important;
    }

    .po-table th {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
        color: var(--text-white) !important;
        -webkit-text-fill-color: var(--text-white) !important;
        padding: 15px 12px;
        text-align: left;
        font-weight: 600;
        border-bottom: 2px solid var(--primary-green);
    }

    .po-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #e9ecef;
        vertical-align: middle;
        color: #111827 !important;
        -webkit-text-fill-color: #111827 !important;
    }

    .po-table td strong {
        color: #000000 !important;
        -webkit-text-fill-color: #000000 !important;
        font-weight: 600;
    }

    .po-table td small,
    .po-table td .text-muted {
        color: #4b5563 !important;
        -webkit-text-fill-color: #4b5563 !important;
    }

    .po-table tbody tr:hover {
        background-color: rgba(7, 59, 29, 0.05);
    }

    /* Status Badges & Items Badge */
    .status-badge,
    .badge:not(.bg-light):not(.bg-white),
    .badge-info {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    .badge.bg-light {
        color: #212529 !important;
        -webkit-text-fill-color: #212529 !important;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    .status-draft {
        background: linear-gradient(135deg, #6c757d, #5a6268);
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    .status-pending {
        background: linear-gradient(135deg, var(--accent-orange), #e8690b);
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    .status-approved {
        background: linear-gradient(135deg, var(--accent-green-approved), #1e7e34);
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    .status-rejected {
        background: linear-gradient(135deg, var(--accent-red), #c82333);
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    .status-completed {
        background: linear-gradient(135deg, var(--accent-blue), #357abd);
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    .status-cancelled {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    .status-received {
        background: linear-gradient(135deg, var(--accent-blue), #357abd);
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    /* Action Buttons in Table */
    .table-actions {
        display: flex;
        gap: 8px;
    }

    .btn-sm {
        padding: 6px 12px;
        font-size: 0.8rem;
        border-radius: 4px;
    }

    .btn-info {
        background-color: var(--accent-blue);
        color: white;
    }

    .btn-info:hover {
        background-color: #357abd;
    }

    .btn-warning {
        background-color: var(--accent-orange);
        color: white;
    }

    .btn-warning:hover {
        background-color: #e8690b;
    }

    .btn-danger {
        background-color: var(--accent-red);
        color: white;
    }

    .btn-danger:hover {
        background-color: #c82333;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }

    .empty-state i {
        font-size: 4rem;
        margin-bottom: 20px;
        opacity: 0.5;
    }

    .empty-state h3 {
        margin-bottom: 10px;
        color: var(--text-dark);
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

        .list-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }

        .po-table {
            font-size: 0.9rem;
        }

        .po-table th,
        .po-table td {
            padding: 10px 8px;
        }

        .table-actions {
            flex-direction: column;
            gap: 5px;
        }
    }

    /* Pager */
    .po-pager {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        padding: 15px 20px;
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
    }

    .po-pager-info {
        font-size: 0.9rem;
        color: #4b5563;
    }

    .po-pager .pagination {
        margin: 0;
    }

    .po-pager .page-link {
        color: var(--primary-green);
        border-radius: 5px;
        margin: 0 2px;
        font-size: 0.875rem;
    }

    .po-pager .page-item.active .page-link {
        background-color: var(--primary-green);
        border-color: var(--primary-green);
        color: #fff;
    }

    .po-pager .page-item.disabled .page-link {
        color: #adb5bd;
    }
</style>

<!-- Sidebar -->
<?php include '../includes/sidebar.php'; ?>

<!-- Main Content -->
<div class="main-content">
    <div class="content-header">
        <h1>Purchase Order List</h1>
        <p>View and manage all purchase order records</p>
    </div>

    <!-- Purchase Order List -->
    <div class="list-container">
        <div class="list-header">
            <h2 class="list-title">All Purchase Order Records</h2>
            <div class="action-buttons">
                <form method="GET" action="purchase_order_list.php" class="d-flex align-items-center" style="gap:.5rem;">
                    <input type="text" name="search" id="poSearchInput" class="form-control form-control-sm"
                           placeholder="Search item, PO no., or supplier..."
                           value="<?= htmlspecialchars($search_term) ?>"
                           style="width: 320px;" aria-label="Search purchase orders">
                    <button type="submit" class="btn btn-sm" style="background:#fff;color:var(--primary-green);">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if ($search_term !== ''): ?>
                        <a href="purchase_order_list.php" class="btn btn-sm" style="background:#fff;color:var(--primary-green);"
                           title="Clear search">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </form>
                <a href="../actions/export_purchase_orders_excel.php" class="btn btn-success">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </a>
                <?php if ($user_role_norm !== 'propertycustodian'): ?>
                    <a href="purchase_order.php" class="btn btn-primary text-dark">
                        <i class="fas fa-plus"></i> New Purchase Order
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($search_term !== ''): ?>
            <div style="padding: 10px 20px; background:#e8f4ec; border-bottom:1px solid #d1dbd5; font-size:.9rem;">
                <i class="fas fa-filter"></i>
                Showing results for <strong><?= htmlspecialchars($search_term) ?></strong>
                — purchase orders with <strong>already-received items are listed first</strong>.
            </div>
        <?php endif; ?>

        <?php if (!empty($po_rows)): ?>
            <table class="po-table">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Total Amount</th>
                        <th>Items</th>
                        <th>Received</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($po_rows as $po): ?>
                        <tr class="po-row" style="cursor: pointer;" onclick="togglePoItems(this, event)">
                            <td>
                                <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                            </td>
                            <td>
                                <?= date('M d, Y', strtotime($po['po_date'])) ?>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($po['supplier_name']) ?></strong>
                                    <?php if ($po['supplier_address']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($po['supplier_address']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong>₱<?= number_format($po['total_amount'], 2) ?></strong>
                            </td>
                            <td>
                                <span class="badge badge-info" style="background-color: var(--primary-green); color: white; font-weight: bold; font-size: 12px; padding: 4px 8px; border-radius: 4px;"><?= $po['item_count'] ?> items</span>
                                <i class="fas fa-caret-down" style="opacity: 0.6; margin-left: 5px;" title="Click to view items"></i>
                            </td>
                            <td>
                                <?php
                                $row_total = (int)$po['item_count'];
                                $row_recv  = (int)($po['received_item_count'] ?? 0);
                                if ($row_total > 0 && $row_recv >= $row_total) {
                                    $recv_label  = 'Fully Received';
                                    $recv_bg     = 'var(--accent-green-approved)';
                                } elseif ($row_recv > 0) {
                                    $recv_label  = 'Partially Received';
                                    $recv_bg     = 'var(--accent-orange)';
                                } else {
                                    $recv_label  = 'None';
                                    $recv_bg     = '#6c757d';
                                }
                                ?>
                                <span class="badge" style="background-color: <?= $recv_bg ?>; color: white;
                                                     font-size: 11px; padding: 4px 8px; border-radius: 4px; font-weight: bold;">
                                    <?= $row_recv ?> / <?= $row_total ?>
                                </span>
                                <br>
                                <small class="text-muted"><?= $recv_label ?></small>
                                <?php if (!empty($po['received_by_name'])): ?>
                                    <div class="mt-1" style="font-size: 0.75rem; color: #155724; line-height: 1.2;">
                                        <i class="fas fa-user-check"></i> <strong><?= htmlspecialchars($po['received_by_name']) ?></strong>
                                        <?php if (!empty($po['received_by_role'])): ?>
                                            <br><span style="color: #374151;">(<?= htmlspecialchars($po['received_by_role']) ?>)</span>
                                        <?php endif; ?>
                                        <?php if (!empty($po['received_date'])): ?>
                                            <br><span style="color: #374151;"><?= date('M d, Y', strtotime($po['received_date'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?= strtolower($po['status']) ?>">
                                    <?= htmlspecialchars($po['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?= htmlspecialchars($po['created_by_name'] ?? 'Unknown') ?>
                            </td>
                            <td>
                                <?= date('M d, Y g:i A', strtotime($po['created_at'])) ?>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <button class="btn btn-info btn-sm" onclick="viewPurchaseOrder(<?= (int)$po['po_id'] ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php if ($can_edit_po): ?>
                                        <button class="btn btn-warning btn-sm" onclick="editPurchaseOrder(<?= (int)$po['po_id'] ?>)" title="Edit this purchase order">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <!-- Expandable items sub-row -->
                        <tr class="po-items-row" style="display: none; background-color: #f8f9fa;">
                            <td colspan="10" style="padding: 12px 20px;">
                                <?php $row_items = $items_map[$po['po_id']] ?? []; ?>
                                <?php if ($row_items): ?>
                                    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                                        <thead>
                                            <tr>
                                                <th style="text-align: left; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 50px;">#</th>
                                                <th style="text-align: left; padding: 6px 10px; border-bottom: 2px solid var(--primary-green);">Item Description</th>
                                                <th style="text-align: right; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 90px;">Quantity</th>
                                                <th style="text-align: right; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 120px;">Unit Cost</th>
                                                <th style="text-align: right; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 130px;">Line Total</th>
                                                <th style="text-align: center; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 140px;">Received Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $sub_total = 0; ?>
                                            <?php foreach ($row_items as $ritem): ?>
                                                <?php $sub_total += $ritem['line_total']; ?>
                                                <tr>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef;"><?= (int)$ritem['item_number'] ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef;"><?= htmlspecialchars($ritem['item_description']) ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef; text-align: right;"><?= htmlspecialchars($ritem['quantity']) ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef; text-align: right;">₱<?= number_format($ritem['unit_cost'], 2) ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef; text-align: right;">₱<?= number_format($ritem['line_total'], 2) ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef; text-align: center;">
                                                        <?php if ((int)($ritem['is_received'] ?? 0) === 1): ?>
                                                            <span class="badge bg-success" style="font-size:0.75rem;"><i class="fas fa-check"></i> Received</span>
                                                            <?php if (!empty($ritem['received_date'])): ?>
                                                                <br><small style="color: #374151; font-size: 0.75rem;"><?= date('M d, Y', strtotime($ritem['received_date'])) ?></small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary" style="font-size:0.75rem;">Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr>
                                                <td colspan="4" style="padding: 8px 10px; text-align: right; font-weight: bold; color: var(--primary-green);">SUBTOTAL:</td>
                                                <td style="padding: 8px 10px; text-align: right; font-weight: bold; color: var(--primary-green);">₱<?= number_format($sub_total, 2) ?></td>
                                                <td></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <em style="color: #6c757d;">No items recorded for this purchase order.</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            // ── Pager ───────────────────────────────────────────────────────
            // Every link keeps the active search so paging never clears the filter.
            $pager_link = static function ($p) use ($search_term) {
                $params = ['page' => $p];
                if ($search_term !== '') {
                    $params['search'] = $search_term;
                }
                return 'purchase_order_list.php?' . http_build_query($params);
            };
            $showing_from = $total_records > 0 ? $offset + 1 : 0;
            $showing_to = min($offset + $per_page, $total_records);
            ?>
            <?php if ($total_pages > 1): ?>
                <div class="po-pager">
                    <div class="po-pager-info">
                        Showing <strong><?= $showing_from ?>&ndash;<?= $showing_to ?></strong>
                        of <strong><?= $total_records ?></strong> purchase orders
                    </div>
                    <nav aria-label="Purchase order pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars($pager_link(max(1, $page - 1))) ?>">&laquo; Prev</a>
                            </li>
                            <?php
                            // Window of 5 pages around the current one, with ellipses.
                            $window_start = max(1, $page - 2);
                            $window_end = min($total_pages, $page + 2);
                            if ($window_start > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= htmlspecialchars($pager_link(1)) ?>">1</a>
                                </li>
                                <?php if ($window_start > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php for ($i = $window_start; $i <= $window_end; $i++): ?>
                                <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($pager_link($i)) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($window_end < $total_pages): ?>
                                <?php if ($window_end < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= htmlspecialchars($pager_link($total_pages)) ?>"><?= $total_pages ?></a>
                                </li>
                            <?php endif; ?>
                            <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars($pager_link(min($total_pages, $page + 1))) ?>">Next &raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-file-invoice"></i>
                <h3>No Purchase Order Records Found</h3>
                <p>Start by creating your first purchase order.</p>
                <?php if ($user_role_norm !== 'propertycustodian'): ?>
                    <a href="purchase_order.php" class="btn btn-primary text-dark">
                        <i class="fas fa-plus"></i> Create New Purchase Order
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- View Purchase Order Modal -->
<div class="modal fade" id="viewPurchaseOrderModal" tabindex="-1" aria-labelledby="viewPurchaseOrderLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%); color: white;">
                <h5 class="modal-title" id="viewPurchaseOrderLabel">Purchase Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: invert(1);"></button>
            </div>
            <div class="modal-body" id="purchaseOrderDetailsContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="markPoReceivedBtn" onclick="markPOAsReceivedFromModal()">
                    <i class="fas fa-check-circle"></i> Received
                </button>
                <button type="button" class="btn btn-primary" onclick="printPurchaseOrderDetails()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    let currentViewedPoId = null;
    let currentPo = null;
    let currentPoItems = [];

    // Expand/collapse the items sub-row when clicking a PO row
    function togglePoItems(row, event) {
        // Don't toggle when a button/link inside the row was clicked
        if (event && event.target.closest('button, a')) return;

        const detailRow = row.nextElementSibling;
        if (detailRow && detailRow.classList.contains('po-items-row')) {
            const isOpen = detailRow.style.display !== 'none';
            detailRow.style.display = isOpen ? 'none' : 'table-row';

            const caret = row.querySelector('.fa-caret-down, .fa-caret-right');
            if (caret) {
                caret.classList.toggle('fa-caret-down', isOpen);
                caret.classList.toggle('fa-caret-right', !isOpen);
            }
        }
    }

    // Remember which office inventory the user picked from the dropdown.
    // The details modal is rebuilt after every single-item receipt, and the whole
    // page reloads after a full receipt, so without this the select silently
    // snapped back to "Supply" and the next PO risked being filed to the wrong
    // inventory. localStorage also survives that reload.
    const STOCK_TARGET_KEY = 'po_stock_target';

    function getSavedStockTarget() {
        try {
            const v = localStorage.getItem(STOCK_TARGET_KEY);
            return (v === 'property' || v === 'supply') ? v : 'supply';
        } catch (e) {
            return 'supply'; // storage disabled (private mode) - fall back safely
        }
    }

    function saveStockTarget(value) {
        if (value !== 'property' && value !== 'supply') return;
        try {
            localStorage.setItem(STOCK_TARGET_KEY, value);
        } catch (e) { }
    }

    // Re-apply the saved choice every time the modal content is rebuilt.
    function restoreStockTargetSelect() {
        const sel = document.getElementById('stockTargetSelect');
        // Office roles get a hidden input fixed by their role - never override it.
        if (sel && sel.tagName === 'SELECT') {
            sel.value = getSavedStockTarget();
        }
    }

    // View purchase order details
    function viewPurchaseOrder(poId) {
        currentViewedPoId = poId;
        fetch(`../actions/get_purchase_order_details.php?id=${poId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayPurchaseOrderDetails(data.purchase_order, data.items);
                    $('#viewPurchaseOrderModal').modal('show');
                } else {
                    alert('Error loading purchase order details: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
            });
    }

    // Display purchase order details in modal
    function displayPurchaseOrderDetails(po, items) {
        // Keep data for the Receiving Report printout
        currentPo = po;
        currentPoItems = items || [];

        let itemsHtml = '';
        let grandTotal = 0;

        items.forEach(item => {
            const isRecv = parseInt(item.is_received) === 1;
            const recvDateStr = item.received_date ? new Date(item.received_date).toLocaleDateString() : '';
            itemsHtml += `
                <tr>
                    <td>${item.item_number}</td>
                    <td>${item.item_description}</td>
                    <td>${item.quantity}</td>
                    <td>₱${parseFloat(item.unit_cost).toFixed(2)}</td>
                    <td>₱${parseFloat(item.line_total).toFixed(2)}</td>
                    <td>${recvDateStr ? `<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#e9ecef;color:#212529;font-size:0.82rem;border:1px solid #ced4da;">${recvDateStr}</span>` : '<span style="color:#6c757d;font-size:0.85rem;">Not received</span>'}</td>
                    <td>
                        ${isRecv ? `
                            <span class="badge bg-success"><i class="fas fa-check"></i> Already Received</span>
                        ` : `
                            <button class="btn btn-success btn-sm" onclick="markItemReceived(${item.poi_id}, this)">
                                <i class="fas fa-check-circle"></i> Receive Items
                            </button>
                        `}
                    </td>
                </tr>
            `;
            grandTotal += parseFloat(item.line_total);
        });

        const content = `
            <div class="purchase-order-details">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h4>Purchase Order Information</h4>
                        <p><strong>PO Number:</strong> ${po.po_number}</p>
                        <p><strong>Date:</strong> ${new Date(po.po_date).toLocaleDateString()}</p>
                        <p><strong>Status:</strong> <span class="status-badge status-${po.status.toLowerCase()}">${po.status}</span></p>
                        <p><strong>Payment Method:</strong> ${po.payment_method || 'N/A'}</p>
                        ${po.status === 'Received' || po.received_date ? `
                            <p><strong>Received Date:</strong> ${po.received_date ? new Date(po.received_date).toLocaleString() : 'N/A'}</p>
                            <p><strong>Received By:</strong> ${po.receiver_name ? `${po.receiver_name} ${po.receiver_role ? `(${po.receiver_role})` : ''}` : (po.received_by || 'N/A')}</p>
                        ` : ''}
                    </div>
                    <div class="col-md-6">
                        <h4>Supplier Information</h4>
                        <p><strong>Supplier:</strong> ${po.supplier_name}</p>
                        <p><strong>Address:</strong> ${po.supplier_address || 'N/A'}</p>
                        <p><strong>Total Amount:</strong> ₱${parseFloat(po.total_amount).toFixed(2)}</p>
                        <p><strong>Created By:</strong> ${po.created_by_name || 'Unknown'}</p>
                    </div>
                </div>

                <h4>Items</h4>
                <table class="table table-bordered">
                    <thead style="background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%); color: white;">
                        <tr>
                            <th>#</th>
                            <th>Item Description</th>
                            <th>Quantity</th>
                            <th>Unit Cost</th>
                            <th>Total</th>
                            <th>Received Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                        <tr style="background-color: var(--primary-green); color: white; font-weight: bold;">
                            <td colspan="4" style="text-align: right;">GRAND TOTAL:</td>
                            <td>₱${grandTotal.toFixed(2)}</td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>

                <div class="mt-3 mb-3">
                    <h5>Receiving Notes</h5>
                    <textarea class="form-control" id="receivedNotesInput" rows="3" placeholder="Enter receiving notes (optional)...">${(po.received_notes || '').replace(/</g, '&lt;')}</textarea>
                </div>

                <div class="mt-3 mb-3" id="stockTargetWrap">
                    <h5>Items will be added to</h5>
                    <?php if ($role_target_locked): ?>
                        <input type="hidden" id="stockTargetSelect" value="<?= htmlspecialchars($role_target) ?>">
                        <div class="alert alert-success py-2 mb-1" style="font-size:0.9rem;">
                            <i class="fas fa-box"></i>
                            <strong><?= htmlspecialchars($role_target_label) ?></strong>
                            — set by your role (<?= htmlspecialchars($user_type) ?>).
                        </div>
                    <?php else: ?>
                        <select class="form-select" id="stockTargetSelect" onchange="saveStockTarget(this.value)">
                            <option value="supply">Supply Office Inventory</option>
                            <option value="property">Property Office Inventory</option>
                        </select>
                        <small class="text-muted">
                            These items will be added to stock so they can be searched and released later.
                        </small>
                    <?php endif; ?>
                </div>

                ${po.notes ? `<div class="mt-3"><h5>Notes:</h5><p>${po.notes}</p></div>` : ''}
            </div>
        `;

        document.getElementById('purchaseOrderDetailsContent').innerHTML = content;

        // The freshly built markup always starts on the first option, so put the
        // user's saved choice back before they read or submit anything.
        restoreStockTargetSelect();

        // Footer button state is driven by the item-level Action column.
        // Once every line item is received there is nothing left to action, so the
        // button locks out — this prevents the same PO being submitted twice.
        const receivedBtn = document.getElementById('markPoReceivedBtn');
        if (receivedBtn) {
            const allItems = (items || []).length;
            const pendingCount = (items || [])
                .filter(item => parseInt(item.is_received, 10) !== 1).length;

            // The target selector only matters when there is stock to post.
            const targetWrap = document.getElementById('stockTargetWrap');
            if (targetWrap) {
                targetWrap.style.display = pendingCount > 0 ? 'block' : 'none';
            }

            if (allItems > 0 && pendingCount === 0) {
                // Everything received — lock the button against duplicate submissions.
                receivedBtn.dataset.mode = 'done';
                receivedBtn.disabled = true;
                receivedBtn.classList.add('disabled');
                receivedBtn.innerHTML = '<i class="fas fa-check"></i> Received';
                receivedBtn.title = 'All line items are already received. ' +
                    'This purchase order is complete and cannot be submitted again.';
            } else if (pendingCount > 0) {
                receivedBtn.dataset.mode = 'receive-all';
                receivedBtn.disabled = false;
                receivedBtn.classList.remove('disabled');
                receivedBtn.innerHTML = '<i class="fas fa-check-circle"></i> Received';
                receivedBtn.title = pendingCount + ' line item(s) still outstanding — ' +
                    'clicking marks them and the whole purchase order as received.';
            } else {
                // No items on this PO at all — allow the status/notes save.
                receivedBtn.dataset.mode = 'submit';
                receivedBtn.disabled = false;
                receivedBtn.classList.remove('disabled');
                receivedBtn.innerHTML = '<i class="fas fa-check"></i> Submit';
                receivedBtn.title = 'No line items on this purchase order — ' +
                    'clicking saves the receiving notes.';
            }
        }
    }

    // Edit purchase order
    function editPurchaseOrder(poId) {
        window.location.href = `purchase_order.php?edit=${poId}`;
    }

    // Finalise from the modal footer. The button is disabled by
    // displayPurchaseOrderDetails() when every line item is already received, so
    // that state can never be submitted twice; this guard is the safety net.
    //   receive-all -> outstanding line items + the PO are marked received
    //   submit      -> notes are persisted (PO has no line items)
    function markPOAsReceivedFromModal() {
        if (!currentViewedPoId) return;

        const btn = document.getElementById('markPoReceivedBtn');
        const mode = (btn && btn.dataset.mode) || 'submit';

        if (mode === 'done' || (btn && btn.disabled)) {
            alert('All items on this purchase order are already received.\n' +
                'This purchase order has already been completed.');
            return;
        }

        const notesEl = document.getElementById('receivedNotesInput');
        const notes = notesEl ? notesEl.value.trim() : '';
        const targetEl = document.getElementById('stockTargetSelect');
        const target = targetEl ? targetEl.value : 'supply';

        const message = mode === 'receive-all' ?
            'Mark all outstanding items and this purchase order as received?' :
            'Submit the receiving report and save your notes?';

        if (!confirm(message)) return;

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }

        fetch('../actions/mark_as_received.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    po_id: currentViewedPoId,
                    notes: notes,
                    mark_all_items: mode === 'receive-all',
                    target: target
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    $('#viewPurchaseOrderModal').modal('hide');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    // A duplicate rejection means the PO is complete, so keep the
                    // button locked; anything else leaves the button usable.
                    if (data.already_received) {
                        if (btn) {
                            btn.dataset.mode = 'done';
                            btn.disabled = true;
                            btn.classList.add('disabled');
                            btn.innerHTML = '<i class="fas fa-check"></i> Received';
                        }
                    } else {
                        restoreReceivedBtn(btn);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
                restoreReceivedBtn(btn);
            });
    }

    // Put the footer button back into its last known state after a failed save,
    // otherwise the "Saving..." spinner + disabled flag would stick around.
    // A button that was locked because everything is already received stays locked.
    function restoreReceivedBtn(btn) {
        if (!btn) return;
        if (btn.dataset.mode === 'done') {
            btn.disabled = true;
            btn.classList.add('disabled');
            btn.innerHTML = '<i class="fas fa-check"></i> Received';
            return;
        }
        btn.disabled = false;
        btn.classList.remove('disabled');
        btn.innerHTML = btn.dataset.mode === 'receive-all' ?
            '<i class="fas fa-check-circle"></i> Received' :
            '<i class="fas fa-check"></i> Submit';
    }

    // Mark a single purchase order item as Received (from the details modal)
    function markItemReceived(poiId, btn) {
        if (!confirm('Mark this item as received and add it to inventory?')) return;

        const targetEl = document.getElementById('stockTargetSelect');
        const target = targetEl ? targetEl.value : 'supply';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }

        fetch('../actions/mark_item_received.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    poi_id: poiId,
                    target: target
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    // Refresh the modal so only that item shows as received
                    viewPurchaseOrder(currentViewedPoId);
                } else {
                    alert('Error: ' + data.message);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check-circle"></i> Receive Items';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
    }

    // Delete purchase order
    function deletePurchaseOrder(poId) {
        if (confirm('Are you sure you want to delete this purchase order? This action cannot be undone.')) {
            fetch('../actions/delete_purchase_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        po_id: poId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Purchase order deleted successfully');
                        location.reload();
                    } else {
                        alert('Error deleting purchase order: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                });
        }
    }

    // Print Receiving Report — fills the hidden container, then opens the browser print preview
    // (same pattern as printPO() in purchase_order.php)
    function printPurchaseOrderDetails() {
        if (!currentPo) {
            alert('Open a purchase order first.');
            return;
        }

        const po = currentPo;
        const items = currentPoItems || [];

        const esc = (t) => String(t ?? '').replace(/[&<>"']/g, m => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        } [m]));
        const peso = (n) => '₱' + parseFloat(n || 0).toFixed(2);

        // Fill Received from / Date fields
        document.getElementById('rrSupplier').textContent = po.supplier_name || '';
        document.getElementById('rrDate').textContent = po.received_date ?
            new Date(po.received_date).toLocaleDateString() :
            (po.po_date ? new Date(po.po_date).toLocaleDateString() : '');

        // Fill table: real items first, then blank filler rows.
        // The exact row count and font size are worked out below, then the body
        // is rebuilt once the sizing has been decided.
        const tbody = document.getElementById('rrItemsBody');
        tbody.innerHTML = '';

        const wrap = document.getElementById('receivingReportWrap');
        wrap.classList.remove('rr-compact', 'rr-full-page');

        // Dynamic scale detection based on item count
        const realRows = items.length;
        const isDense = realRows > 12; // Compact styling for 13–24 items (e.g. 23 items)
        const isFullPage = realRows > 24; // 25+ items print 1 copy per full sheet

        if (isFullPage) {
            wrap.classList.add('rr-full-page');
        } else if (isDense) {
            wrap.classList.add('rr-compact');
        }

        // Space budget for items:
        // Half-sheet gives ~606px total height. Non-table elements in compact mode take ~195px.
        // That leaves ~410px for the table rows.
        const TABLE_BUDGET = isFullPage ? 750 : (isDense ? 405 : 300);
        const MAX_FONT = isDense ? 10 : 14;
        const MIN_FONT = isDense ? 8.5 : 11;
        const ROW_PAD = isDense ? 2 : 6;
        const MAX_FILLER_ROWS = isDense ? 0 : 12;

        let fillerRows = Math.max(0, MAX_FILLER_ROWS - realRows);
        let totalRows = realRows + fillerRows;

        let fontPx = MAX_FONT;
        let rowH = 0;

        for (let guard = 0; guard < 200; guard++) {
            const needed = Math.ceil(fontPx * 1.25) + ROW_PAD;
            const affordable = Math.floor(TABLE_BUDGET / totalRows);

            if (needed <= affordable) {
                rowH = Math.min(affordable, needed + (isDense ? 2 : 6));
                break;
            }

            if (fontPx > MIN_FONT) {
                fontPx -= 0.5;
                continue;
            }

            if (fillerRows > 0) {
                const drop = Math.min(
                    fillerRows,
                    Math.max(1, Math.ceil(((needed * totalRows) - TABLE_BUDGET) / needed))
                );
                fillerRows -= drop;
                totalRows = realRows + fillerRows;
                continue;
            }

            rowH = Math.max(MIN_FONT + (isDense ? 2 : 4), affordable);
            break;
        }

        if (!rowH) {
            rowH = Math.ceil(fontPx * 1.25) + ROW_PAD;
        }

        // Rebuild the body with the row count we settled on.
        tbody.innerHTML = '';
        for (let i = 0; i < totalRows; i++) {
            const tr = document.createElement('tr');
            if (i < realRows) {
                const it = items[i];
                tr.innerHTML = `<td>${esc(it.item_description)}</td><td class="rr-center">${esc(it.quantity)}</td><td class="rr-center">${peso(it.unit_cost)}</td><td class="rr-center">${peso(it.line_total)}</td>`;
            } else {
                tr.innerHTML = '<td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>';
            }
            tbody.appendChild(tr);
        }

        wrap.style.setProperty('--rr-row-h', rowH + 'px');
        wrap.style.setProperty('--rr-td-fs', fontPx + 'px');

        // Build the 2nd (and any additional) copies from the filled sheet.
        const COPIES = 2;
        const sheet = wrap.querySelector('.rr-sheet');
        wrap.querySelectorAll('.rr-copy-extra').forEach(node => node.remove());
        for (let c = 1; c < COPIES; c++) {
            if (!isFullPage) {
                const sep = document.createElement('div');
                sep.className = 'rr-cut-line rr-copy-extra';
                sep.innerHTML = '<span class="rr-cut-icon">&#9986;</span><span class="rr-cut-label">CUT HERE</span>';
                wrap.appendChild(sep);
            }

            const copy = sheet.cloneNode(true);
            copy.classList.add('rr-copy-extra');
            copy.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
            const tag = document.createElement('div');
            tag.className = 'rr-copy-tag';
            tag.textContent = 'COPY ' + (c + 1);
            copy.insertBefore(tag, copy.firstChild);
            wrap.appendChild(copy);
        }

        // Show the hidden print container only during the print call
        wrap.style.display = 'block';
        window.print();
        wrap.style.display = 'none';
        wrap.querySelectorAll('.rr-copy-extra').forEach(node => node.remove());
        wrap.classList.remove('rr-compact', 'rr-full-page');
    }
</script>

<!-- Hidden Receiving Report container (shown only during the print call) -->
<div id="receivingReportWrap" style="display: none;">
    <div class="rr-sheet">
        <!-- Header -->
        <div class="rr-head">
            <div class="rr-head-left">
                <div class="rr-logo-block">
                    <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo" class="rr-logo">
                    <div class="rr-accred">ACSCU-ACI ACCREDITED</div>
                </div>
                <div class="rr-title-wrap">
                    <div class="rr-school-name"><?= htmlspecialchars($school_name) ?></div>
                    <div class="rr-office-name">Supply Office</div>
                    <div class="rr-address"><?= htmlspecialchars($school_address) ?></div>
                </div>
            </div>
            <div class="rr-form-box">
                <div class="rr-form-box-line">SO - 002</div>
                <div class="rr-form-box-line">Revision: 2</div>
                <div class="rr-form-box-line">Date Revised:</div>
                <div class="rr-form-box-line">August 2025</div>
            </div>
        </div>

        <!-- Dark ribbon -->
        <div class="rr-banner">
            <span>ACSCU-ACI ACCREDITED</span>
            <span>• Email Address: <?= htmlspecialchars($school_email) ?></span>
            <span>• Website: <?= htmlspecialchars($school_website) ?></span>
        </div>

        <!-- Title -->
        <div class="rr-title">RECEIVING REPORT</div>

        <!-- Received from / Date -->
        <div class="rr-fields">
            <div class="rr-field-group">
                <span class="rr-field-label">Received from:</span>
                <span class="rr-underline rr-underline-wide" id="rrSupplier">&nbsp;</span>
            </div>
            <div class="rr-field-group">
                <span class="rr-field-label">Date:</span>
                <span class="rr-underline rr-underline-date" id="rrDate">&nbsp;</span>
            </div>
        </div>

        <!-- Items Table -->
        <table class="rr-table">
            <thead>
                <tr>
                    <th class="rr-col-desc">Description</th>
                    <th class="rr-col-num">Quantity</th>
                    <th class="rr-col-num">Unit Cost</th>
                    <th class="rr-col-num">Total</th>
                </tr>
            </thead>
            <tbody id="rrItemsBody">
                <!-- Populated dynamically with items + blank filler rows -->
            </tbody>
        </table>

        <!-- Certification -->
        <p class="rr-certification">
            I hereby certify that the materials or services has been received, inspected and found satisfactory for the purpose for which they were purchased.
        </p>

        <!-- Signatures — names are printed so the form is ready for signing -->
        <div class="rr-signatures">
            <div class="rr-sign-col">
                <div class="rr-sign-label">Inspected By:</div>
                <div class="rr-sign-line"></div>
                <div class="rr-sign-name"><?= htmlspecialchars($inspector_name) ?></div>
                <div class="rr-sign-title">Inspector/ Receiver</div>
            </div>
            <div class="rr-sign-col">
                <div class="rr-sign-label">Verified By:</div>
                <div class="rr-sign-line"></div>
                <div class="rr-sign-name"><?= htmlspecialchars($verifier_name) ?></div>
                <div class="rr-sign-title">Purchasing Officer</div>
            </div>
        </div>
    </div>
</div>

<!-- Receiving Report Print Styles (same pattern as purchase_order.php) -->
<style>
    /* Screen preview of the SO-002 form — hidden until print() */
    .rr-sheet {
        background: #ffffff;
        width: 100%;
        max-width: 800px;
        margin: 0 auto;
        padding: 24px;
        box-sizing: border-box;
        /* Same font as the purchase_order.php print preview */
        font-family: Arial, Helvetica, sans-serif;
        color: #000000;
        font-size: 13px;
        line-height: 1.25;
        border: 1px solid #b0b0b0;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.18);
    }

    .rr-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 4px;
    }

    .rr-head-left {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 1;
    }

    .rr-logo-block {
        text-align: center;
        width: 76px;
        flex-shrink: 0;
    }

    .rr-logo {
        width: 64px;
        height: 64px;
        object-fit: contain;
        display: block;
        margin: 0 auto;
    }

    .rr-accred {
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-top: 3px;
    }

    .rr-title-wrap {
        flex: 1;
        text-align: center;
        padding: 0 6px;
    }

    .rr-school-name {
        font-size: 22px;
        font-weight: bold;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        line-height: 1.1;
    }

    .rr-office-name {
        font-size: 14px;
        font-weight: bold;
        margin-top: 2px;
    }

    .rr-address {
        font-size: 11px;
        margin-top: 1px;
    }

    .rr-form-box {
        border: 1px solid #000000;
        padding: 6px 8px;
        font-size: 11px;
        line-height: 1.35;
        text-align: right;
        flex-shrink: 0;
    }

    .rr-form-box-line:first-child {
        font-weight: bold;
    }

    .rr-banner {
        background-color: #000000;
        color: #ffffff;
        font-size: 10px;
        display: flex;
        justify-content: space-between;
        padding: 3px 8px;
        margin: 6px 0 10px 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .rr-title {
        text-align: center;
        font-size: 16px;
        font-weight: bold;
        letter-spacing: 4px;
        margin-bottom: 12px;
    }

    .rr-fields {
        display: flex;
        justify-content: space-between;
        margin-bottom: 12px;
        font-size: 12px;
    }

    .rr-field-group {
        display: flex;
        align-items: baseline;
    }

    .rr-field-label {
        margin-right: 6px;
    }

    .rr-underline {
        border-bottom: 1px solid #000000;
        display: inline-block;
        min-height: 14px;
        text-align: center;
    }

    .rr-underline-wide {
        width: 256px;
    }

    .rr-underline-date {
        width: 128px;
    }

    .rr-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #000000;
        font-size: 12px;
        margin-bottom: 16px;
    }

    .rr-table th {
        border: 1px solid #000000;
        padding: 4px 8px;
        font-weight: bold;
        text-align: center;
    }

    .rr-table td {
        border: 1px solid #000000;
        height: 24px;
        padding: 2px 8px;
    }

    .rr-table .rr-col-desc {
        text-align: left;
        width: 55%;
    }

    .rr-table .rr-col-num {
        width: 15%;
    }

    .rr-center {
        text-align: center;
    }

    .rr-certification {
        font-size: 11px;
        text-align: justify;
        margin: 0 0 32px 0;
    }

    .rr-signatures {
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        margin-top: 36px;
    }

    .rr-sign-col {
        width: 42%;
        text-align: center;
    }

    .rr-sign-label {
        margin-bottom: 4px;
    }

    /* Printed name under the signature line */
    .rr-sign-name {
        font-weight: bold;
        margin-bottom: 1px;
    }

    .rr-sign-line {
        border-bottom: 1px solid #000000;
        height: 32px;
        margin-bottom: 4px;
    }

    /* ── Print Media Query ── */
    @media print {
        @page {
            /* Long bond paper: 8.5in × 13in portrait */
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

        #receivingReportWrap,
        #receivingReportWrap * {
            visibility: visible !important;
        }

        #receivingReportWrap {
            display: block !important;
            position: static !important;
            width: 100% !important;
            height: auto !important;
            background: transparent !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .rr-sheet {
            border: none !important;
            box-shadow: none !important;
            margin: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            /* Exactly half of long bond paper minus margins & cut line */
            height: calc((13in - 6mm - 12px) / 2) !important;
            box-sizing: border-box !important;
            display: flex !important;
            flex-direction: column !important;
            padding: 4px 12px 4px 12px !important;
            page-break-inside: avoid;
            break-inside: avoid;
            overflow: hidden !important;
        }

        /* Two copies share one long bond page — proportional sizes to fill the half-sheet */
        .rr-sheet .rr-head {
            margin-bottom: 2px !important;
            flex-shrink: 0;
        }

        .rr-sheet .rr-logo {
            width: 52px !important;
            height: 52px !important;
        }

        .rr-sheet .rr-logo-block {
            width: 62px !important;
        }

        .rr-sheet .rr-accred {
            font-size: 8px !important;
        }

        .rr-sheet .rr-school-name {
            font-size: 18px !important;
        }

        .rr-sheet .rr-office-name {
            font-size: 13px !important;
        }

        .rr-sheet .rr-address {
            font-size: 9.5px !important;
        }

        .rr-sheet .rr-form-box {
            font-size: 9.5px !important;
            padding: 3px 6px !important;
        }

        .rr-sheet .rr-banner {
            margin: 3px 0 5px 0 !important;
            font-size: 10px !important;
            padding: 2px 6px !important;
            flex-shrink: 0;
        }

        .rr-sheet .rr-title {
            font-size: 17px !important;
            letter-spacing: 3px !important;
            margin-bottom: 6px !important;
            flex-shrink: 0;
        }

        .rr-sheet .rr-fields {
            margin-bottom: 6px !important;
            font-size: 12.5px !important;
            flex-shrink: 0;
        }

        .rr-sheet .rr-underline-wide {
            width: 250px !important;
        }

        .rr-sheet .rr-underline-date {
            width: 120px !important;
        }

        .rr-sheet .rr-table {
            font-size: var(--rr-td-fs, 13px) !important;
            margin-bottom: 3px !important;
            flex-shrink: 0;
        }

        /* Bigger, bolder column headers so the table reads at a glance */
        .rr-sheet .rr-table th {
            padding: 4px 6px !important;
            font-size: calc(var(--rr-td-fs, 13px) + 1px) !important;
            font-weight: 700 !important;
        }

        .rr-sheet .rr-table td {
            height: var(--rr-row-h, 24px) !important;
            padding: 2px 6px !important;
            font-size: var(--rr-td-fs, 13px) !important;
            line-height: 1.2 !important;
        }

        .rr-sheet .rr-certification {
            font-size: 10.5px !important;
            margin-bottom: 3px !important;
            line-height: 1.2 !important;
            flex-shrink: 0;
        }

        /* Pin signatures to the bottom of each half-page */
        .rr-sheet .rr-signatures {
            margin-top: auto !important;
            font-size: 12.5px !important;
            padding-bottom: 4px !important;
            flex-shrink: 0;
        }

        .rr-sheet .rr-sign-line {
            height: 22px !important;
        }

        /* Printed names — bold and slightly larger than the role title beneath */
        .rr-sheet .rr-sign-name {
            font-size: 13.5px !important;
            font-weight: 700 !important;
        }

        .rr-sheet .rr-sign-title {
            font-size: 10.5px !important;
            font-style: italic !important;
        }

        /* ── Compact layout for dense orders (13–24 items, e.g. 23 items) to fit perfectly on half-sheet ── */
        #receivingReportWrap.rr-compact .rr-sheet {
            padding: 2px 10px 2px 10px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-head {
            margin-bottom: 1px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-logo {
            width: 38px !important;
            height: 38px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-logo-block {
            width: 48px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-accred {
            font-size: 7px !important;
            margin-top: 1px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-school-name {
            font-size: 15px !important;
            line-height: 1.1 !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-office-name {
            font-size: 11px !important;
            margin-top: 1px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-address {
            font-size: 8px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-form-box {
            font-size: 8px !important;
            padding: 2px 4px !important;
            line-height: 1.2 !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-banner {
            margin: 1px 0 2px 0 !important;
            font-size: 8px !important;
            padding: 1px 4px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-title {
            font-size: 13px !important;
            letter-spacing: 2px !important;
            margin-bottom: 2px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-fields {
            margin-bottom: 2px !important;
            font-size: 10px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-underline-wide {
            width: 220px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-underline-date {
            width: 100px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-table {
            font-size: var(--rr-td-fs, 9.5px) !important;
            margin-bottom: 2px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-table th {
            padding: 2px 4px !important;
            font-size: calc(var(--rr-td-fs, 9.5px) + 0.5px) !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-table td {
            height: var(--rr-row-h, 14px) !important;
            padding: 1px 4px !important;
            font-size: var(--rr-td-fs, 9.5px) !important;
            line-height: 1.15 !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-certification {
            font-size: 8px !important;
            margin-bottom: 2px !important;
            line-height: 1.1 !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-signatures {
            margin-top: auto !important;
            font-size: 9.5px !important;
            padding-bottom: 2px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-sign-line {
            height: 14px !important;
            margin-bottom: 2px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-sign-name {
            font-size: 10.5px !important;
        }

        #receivingReportWrap.rr-compact .rr-sheet .rr-sign-title {
            font-size: 8.5px !important;
        }

        /* ── Full page mode for very large orders (>24 items) ── */
        #receivingReportWrap.rr-full-page .rr-sheet {
            height: calc(13in - 6mm) !important;
            max-height: none !important;
            page-break-after: always !important;
            break-after: page !important;
            padding: 16px 20px !important;
            overflow: visible !important;
        }

        #receivingReportWrap.rr-full-page .rr-cut-line {
            display: none !important;
        }

        /* Scissors/cut guide exactly at the paper center (6.5in) */
        .rr-cut-line {
            position: relative !important;
            height: 12px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .rr-cut-line::before {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            border-top: 1px dashed #888888;
        }

        .rr-cut-icon {
            position: relative;
            background: #ffffff;
            padding: 0 4px;
            font-size: 11px;
            line-height: 1;
            color: #555555;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .rr-cut-label {
            position: relative;
            background: #ffffff;
            padding: 0 4px;
            font-size: 7.5px;
            font-weight: 600;
            letter-spacing: 1px;
            color: #777777;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .rr-copy-tag {
            text-align: right;
            font-size: 8px;
            color: #777777;
            letter-spacing: 1px;
            margin-bottom: 2px;
            flex-shrink: 0;
        }

        .rr-banner {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .sidebar,
        .content-header,
        .list-container,
        .modal,
        .no-print {
            display: none !important;
        }

        /* main-content has min-height:100vh — hide it entirely so the
           report starts on page 1 instead of page 2 */
        .main-content {
            display: none !important;
        }
    }
</style>

<?php include '../includes/footer.php'; ?>