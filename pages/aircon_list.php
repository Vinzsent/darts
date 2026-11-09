<?php
require_once __DIR__ . '/../includes/helpers.php';
$pageTitle = 'Aircon Condition Management';
include '../includes/auth.php';
include '../includes/db.php';

// Handle AJAX requests for pagination
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    // This is an AJAX request, return only the table content
    $isAjax = true;
} else {
    include '../includes/header.php';
    $isAjax = false;
}

// Handle print_all_qr AJAX request - return all aircons as JSON (no pagination)
if ($isAjax && !empty($_GET['print_all_qr'])) {
    $sql = "SELECT pi.aircon_id, pi.item_number, pi.serial_number, pi.brand, pi.model, pi.location, pi.campus
            FROM aircons pi
            ORDER BY pi.aircon_id ASC";
    $result = $conn->query($sql);
    $aircons = [];
    while ($row = $result->fetch_assoc()) {
        $aircons[] = [
            'id' => (int)$row['aircon_id'],
            'item_number' => htmlspecialchars($row['item_number'] ?? '', ENT_QUOTES),
            'serial_number' => htmlspecialchars($row['serial_number'] ?? '', ENT_QUOTES),
            'brand' => htmlspecialchars($row['brand'] ?? '', ENT_QUOTES),
            'model' => htmlspecialchars($row['model'] ?? '', ENT_QUOTES),
            'location' => htmlspecialchars($row['location'] ?? '', ENT_QUOTES),
            'campus' => htmlspecialchars($row['campus'] ?? '', ENT_QUOTES),
        ];
    }
    header('Content-Type: application/json');
    echo json_encode($aircons);
    exit;
}

$user_type = $_SESSION['user_type'] ?? $_SESSION['user']['user_type'] ?? '';
$user_role_norm = str_replace([' ', '-'], '', strtolower($user_type));
$can_manage_aircon = !in_array($user_role_norm, ['purchasingstaff', 'purchasingofficer']);
$dashboard_link = ($user_type == 'Admin') ? '../dashboard.php' : '../dashboard.php';

// Helpers: parse School Year input into start/end dates (July 1 to June 30)

// Per-table School Year search values
$sy_inv_raw  = $_GET['sy_inv']  ?? '';
$sy_recv_raw = $_GET['sy_recv'] ?? '';
$sy_logs_raw = $_GET['sy_logs'] ?? '';

// Campus filter (BED/TED)
$campus_raw = strtoupper(trim($_GET['campus'] ?? ''));

// Status filter
$status_raw = trim($_GET['status'] ?? '');

// Search parameter
$search_term = trim($_GET['search'] ?? '');

list($sy_inv_start, $sy_inv_end)   = parse_school_year_range($sy_inv_raw);
list($sy_recv_start, $sy_recv_end) = parse_school_year_range($sy_recv_raw);
list($sy_logs_start, $sy_logs_end) = parse_school_year_range($sy_logs_raw);

// Build optional WHERE conditions per query based on per-table School Year
$inv_where_conditions = [];
$recv_where_conditions = [];
$logs_where_conditions = [];

// Removed receiver filter - not applicable for aircon management
// $inv_where_conditions[] = "i.receiver = 'Property Custodian'";
// $recv_where_conditions[] = "st.receiver = 'Property Custodian'";
// $logs_where_conditions[] = "sl.receiver = 'Property Custodian'";

// Add search filter if search term is provided
if (!empty($search_term)) {
    $search_escaped = $conn->real_escape_string($search_term);
    $inv_where_conditions[] = "(i.brand LIKE '%$search_escaped%' OR i.model LIKE '%$search_escaped%' OR i.type LIKE '%$search_escaped%' OR i.serial_number LIKE '%$search_escaped%' OR i.location LIKE '%$search_escaped%')";
}

// Add school year filters if provided
if ($sy_inv_start && $sy_inv_end) {
    $start_esc = $conn->real_escape_string($sy_inv_start);
    $end_esc   = $conn->real_escape_string($sy_inv_end);
    $inv_where_conditions[] = "i.date_created >= '$start_esc' AND i.date_created <= '$end_esc'";
}
// Add campus filter if provided (expects values BED/TED stored in campus column)
if ($campus_raw === 'BED' || $campus_raw === 'TED') {
    $campus_esc = $conn->real_escape_string($campus_raw);
    $inv_where_conditions[] = "TRIM(UPPER(i.campus)) = '$campus_esc'";
}
// Add status filter if provided (case-insensitive match)
$allowed_statuses = ['Working', 'Replace', 'Needs Repair', 'Needs Attention', 'Under Maintenance', 'Decommissioned', 'Not Applicable'];
if ($status_raw !== '' && in_array($status_raw, $allowed_statuses, true)) {
    $status_esc = $conn->real_escape_string($status_raw);
    $inv_where_conditions[] = "LOWER(TRIM(i.status)) = LOWER('$status_esc')";
}
if ($sy_recv_start && $sy_recv_end) {
    $start_esc = $conn->real_escape_string($sy_recv_start);
    $end_esc   = $conn->real_escape_string($sy_recv_end);
    $recv_where_conditions[] = "st.date_received >= '$start_esc' AND st.date_received <= '$end_esc'";
}
if ($sy_logs_start && $sy_logs_end) {
    $start_esc = $conn->real_escape_string($sy_logs_start);
    $end_esc   = $conn->real_escape_string($sy_logs_end);
    $logs_where_conditions[] = "sl.date_created >= '$start_esc' AND sl.date_created <= '$end_esc'";
}

// Build final WHERE clauses
$inv_where = !empty($inv_where_conditions) ? ' WHERE ' . implode(' AND ', $inv_where_conditions) : '';
$recv_where = !empty($recv_where_conditions) ? ' WHERE ' . implode(' AND ', $recv_where_conditions) : '';
$logs_where = !empty($logs_where_conditions) ? ' WHERE ' . implode(' AND ', $logs_where_conditions) : '';

// Get purchased data
$sql = "SELECT pi.*, s.supplier_name 
        FROM aircons pi 
        LEFT JOIN supplier s ON pi.supplier_id = s.supplier_id
        ORDER BY pi.date_created DESC";
$result = $conn->query($sql);

// Get inventory data
$sql = "SELECT i.*, s.supplier_name 
        FROM aircons i 
        LEFT JOIN supplier s ON i.supplier_id = s.supplier_id 
        $inv_where
        ORDER BY i.date_created DESC";
$result = $conn->query($sql);

$sql1 = "SELECT st.*, s.supplier_name
        FROM supplier_transaction st
        JOIN supplier s ON s.supplier_id = st.supplier_id
        $recv_where
        AND st.status IN ('Pending')
        ORDER BY COALESCE(st.date_received, st.date_created) DESC";
$result1 = $conn->query($sql1);

// Get suppliers for dropdown
$suppliers_sql = "SELECT supplier_id, supplier_name FROM supplier WHERE status = 'Active' ORDER BY supplier_name";
$suppliers_result = $conn->query($suppliers_sql);

// Build School Year options for dropdowns (last 10 years)
$currYear = (int)date('Y');
$minYear = $currYear - 10;
$sy_years = [];
for ($y = $currYear; $y >= $minYear; $y--) {
    $sy_years[] = $y . '-' . ($y + 1);
}

// Calculate statistics - execute the query first to get proper counts
$stats_result = $conn->query($sql);
$total_items = $stats_result ? $stats_result->num_rows : 0;

// Calculate aircon statistics by status
$operational_count = 0;
$needs_repair_count = 0;
$under_maintenance_count = 0;
$decommissioned_count = 0;
$working_count = 0;
$na_count = 0;

$campus_clause = '';
if ($campus_raw === 'BED' || $campus_raw === 'TED') {
    $campus_esc_for_counts = $conn->real_escape_string($campus_raw);
    $campus_clause = " WHERE campus = '$campus_esc_for_counts'";
}

$status_sql = "SELECT status, COUNT(*) as count FROM aircons" . $campus_clause . " GROUP BY status";
$status_result = $conn->query($status_sql);
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $status = (string)($row['status'] ?? '');
        $count = (int)$row['count'];

        switch (strtolower(trim($status))) {
            case 'operational':
                $operational_count = $count;
                break;
            case 'working':
                $working_count = $count;
                break;
            case 'needs repair':
            case 'needs attention':
                $needs_repair_count = $count;
                break;
            case 'under maintenance':
                $under_maintenance_count = $count;
                break;
            case 'decommissioned':
                $decommissioned_count = $count;
                break;
            case 'n/a':
            case 'not applicable':
                $na_count = $count;
                break;
        }
    }
}

// Calculate combined counts for display
$good_condition_count = $operational_count + $working_count; // Operational + Working
$attention_needed_count = $needs_repair_count + $under_maintenance_count; // Needs Repair + Under Maintenance

// Fetch aircons by condition for modals
$good_condition_aircons = [];
$needs_attention_aircons = [];
$decommissioned_aircons = [];
$not_applicable_aircons = [];

// Get good condition aircons (Operational + Working)
$good_sql = "SELECT * FROM aircons WHERE status IN ('Operational', 'Working')"
    . ($campus_clause ? str_replace(' WHERE ', ' AND ', $campus_clause) : '')
    . " ORDER BY location, brand";
$good_result = $conn->query($good_sql);
if ($good_result) {
    while ($row = $good_result->fetch_assoc()) {
        $good_condition_aircons[] = $row;
    }
}

// Get aircons needing attention (Needs Repair + Under Maintenance)
$attention_sql = "SELECT * FROM aircons WHERE status IN ('Needs Repair', 'Needs Attention', 'Under Maintenance')"
    . ($campus_clause ? str_replace(' WHERE ', ' AND ', $campus_clause) : '')
    . " ORDER BY location, brand";
$attention_result = $conn->query($attention_sql);
if ($attention_result) {
    while ($row = $attention_result->fetch_assoc()) {
        $needs_attention_aircons[] = $row;
    }
}

// Get decommissioned aircons
$decom_sql = "SELECT * FROM aircons WHERE status = 'Decommissioned'"
    . ($campus_clause ? str_replace(' WHERE ', ' AND ', $campus_clause) : '')
    . " ORDER BY location, brand";
$decom_result = $conn->query($decom_sql);
if ($decom_result) {
    while ($row = $decom_result->fetch_assoc()) {
        $decommissioned_aircons[] = $row;
    }
}

// Get Not Applicable aircons ('Not Applicable' or legacy 'N/A')
$na_aircons_sql = "SELECT * FROM aircons WHERE LOWER(TRIM(status)) IN ('not applicable', 'n/a')"
    . ($campus_clause ? str_replace(' WHERE ', ' AND ', $campus_clause) : '')
    . " ORDER BY location, brand";
$na_aircons_result = $conn->query($na_aircons_sql);
if ($na_aircons_result) {
    while ($row = $na_aircons_result->fetch_assoc()) {
        $not_applicable_aircons[] = $row;
    }
}

// Store session messages for modal display
$session_message = '';
$session_error = '';
if (isset($_SESSION['message'])) {
    $session_message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['error'])) {
    $session_error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Fetch all category data for dropdown
$categories_query = "
    SELECT 
        at.id as category_id,
        at.name as main_category,
        sc.name as subcategory,
        ssc.name as sub_subcategory,
        sssc.name as sub_sub_subcategory
    FROM account_types at
    LEFT JOIN account_subcategories sc ON at.id = sc.parent_id
    LEFT JOIN account_sub_subcategories ssc ON sc.id = ssc.subcategory_id
    LEFT JOIN account_sub_sub_subcategories sssc ON ssc.id = sssc.sub_subcategory_id
    WHERE at.id BETWEEN 14 AND 21
    ORDER BY at.id, sc.name, ssc.name, sssc.name
";
$categories_result = $conn->query($categories_query);

// Organize categories hierarchically
$organized_categories = [];
if ($categories_result && $categories_result->num_rows > 0) {
    while ($row = $categories_result->fetch_assoc()) {
        $main = $row['main_category'];
        if (!isset($organized_categories[$main])) {
            $organized_categories[$main] = [];
        }

        if (!empty($row['subcategory'])) {
            $sub = $row['subcategory'];
            if (!in_array($sub, $organized_categories[$main])) {
                $organized_categories[$main][] = $sub;
            }
        }

        if (!empty($row['sub_subcategory'])) {
            $subsub = $row['sub_subcategory'];
            if (!in_array($subsub, $organized_categories[$main])) {
                $organized_categories[$main][] = $subsub;
            }
        }

        if (!empty($row['sub_sub_subcategory'])) {
            $subsubsub = $row['sub_sub_subcategory'];
            if (!in_array($subsubsub, $organized_categories[$main])) {
                $organized_categories[$main][] = $subsubsub;
            }
        }
    }
}

// Fetch all category data for dropdown
$categories_query = "
    SELECT 
        at.id as category_id,
        at.name as main_category,
        sc.name as subcategory,
        ssc.name as sub_subcategory,
        sssc.name as sub_sub_subcategory
    FROM account_types at
    LEFT JOIN account_subcategories sc ON at.id = sc.parent_id
    LEFT JOIN account_sub_subcategories ssc ON sc.id = ssc.subcategory_id
    LEFT JOIN account_sub_sub_subcategories sssc ON ssc.id = sssc.sub_subcategory_id
    WHERE at.id BETWEEN 14 AND 21
    ORDER BY at.id, sc.name, ssc.name, sssc.name
";
$categories_result = $conn->query($categories_query);

// Organize categories hierarchically
$organized_categories = [];
if ($categories_result && $categories_result->num_rows > 0) {
    while ($row = $categories_result->fetch_assoc()) {
        $main = $row['main_category'];
        if (!isset($organized_categories[$main])) {
            $organized_categories[$main] = [];
        }

        if (!empty($row['subcategory'])) {
            $sub = $row['subcategory'];
            if (!in_array($sub, $organized_categories[$main])) {
                $organized_categories[$main][] = $sub;
            }
        }

        if (!empty($row['sub_subcategory'])) {
            $subsub = $row['sub_subcategory'];
            if (!in_array($subsub, $organized_categories[$main])) {
                $organized_categories[$main][] = $subsub;
            }
        }

        if (!empty($row['sub_sub_subcategory'])) {
            $subsubsub = $row['sub_sub_subcategory'];
            if (!in_array($subsubsub, $organized_categories[$main])) {
                $organized_categories[$main][] = $subsubsub;
            }
        }
    }
}

?>

<?php if (!$isAjax): ?>
    <link rel="stylesheet" href="../assets/css/aircon_list.css">

    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-header">
            <h1>Aircon Condition Management</h1>
            <p>Track aircon location, monitor aircon condition, and manage aircon details</p>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon items">
                    <i class="fas fa-snowflake"></i>
                </div>
                <div class="stat-number"><?= $total_items ?></div>
                <div class="stat-label">Total Aircon Units</div>
            </div>

            <div class="stat-card clickable" style="border-left: 4px solid #28a745; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#goodConditionModal" title="Click to view good condition aircons">
                <div class="stat-icon" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number" style="color: #28a745;"><?= $good_condition_count ?></div>
                <div class="stat-label">Good Condition</div>
                <small class="text-muted mt-1"><i class="fas fa-eye"></i> Click to view details</small>
            </div>

            <div class="stat-card clickable" style="border-left: 4px solid #ffc107; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#needsAttentionModal" title="Click to view aircons needing attention">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-number" style="color: #ffc107;"><?= $attention_needed_count ?></div>
                <div class="stat-label">Needs Attention</div>
                <small class="text-muted mt-1"><i class="fas fa-eye"></i> Click to view details</small>
            </div>

            <div class="stat-card clickable" style="border-left: 4px solid #dc3545; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#decommissionedModal" title="Click to view decommissioned aircons">
                <div class="stat-icon" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-number" style="color: #dc3545;"><?= $decommissioned_count ?></div>
                <div class="stat-label">Decommissioned</div>
                <small class="text-muted mt-1"><i class="fas fa-eye"></i> Click to view details</small>
            </div>

            <div class="stat-card clickable" style="border-left: 4px solid #6c757d; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#notApplicableModal" title="Click to view Not Applicable aircons">
                <div class="stat-icon" style="background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);">
                    <i class="fas fa-ban"></i>
                </div>
                <div class="stat-number" style="color: #6c757d;"><?= $na_count ?></div>
                <div class="stat-label">Not Applicable</div>
                <small class="text-muted mt-1"><i class="fas fa-eye"></i> Click to view details</small>
            </div>
        </div>


        <!-- Low Stock Items Modal -->
        <div class="modal fade" id="lowStockModal" tabindex="-1" aria-labelledby="lowStockModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); color: white;">
                        <h5 class="modal-title" id="lowStockModalLabel">
                            <i class="fas fa-exclamation-triangle me-2"></i>Low Stock Items
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($low_stock_items)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong><?= count($low_stock_items) ?></strong> item(s) are running low on stock. Consider reordering soon.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-warning">
                                        <tr>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <th>Current Stock</th>
                                            <th>Reorder Level</th>
                                            <th>Unit</th>
                                            <th>Supplier</th>
                                            <th>Last Updated</th>
                                            <?php if ($can_manage_aircon): ?>
                                                <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($low_stock_items as $item): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                                <td><?= htmlspecialchars($item['category']) ?></td>
                                                <td>
                                                    <span class="badge bg-warning text-dark">
                                                        <?= $item['current_stock'] ?>
                                                    </span>
                                                </td>
                                                <td><?= $item['reorder_level'] ?></td>
                                                <td><?= htmlspecialchars($item['unit']) ?></td>
                                                <td><?= htmlspecialchars($item['supplier_name'] ?? 'N/A') ?></td>
                                                <td><?= date('M d, Y', strtotime($item['date_updated'])) ?></td>
                                                <?php if ($can_manage_aircon): ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-success" onclick="stockIn(<?= $item['inventory_id'] ?>); $('#lowStockModal').modal('hide');" title="Add Stock">
                                                            <i class="fas fa-plus"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-info" onclick="editInventoryItem(<?= (int)$item['inventory_id'] ?>, 'lowStockModal');" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                <h4>All Good!</h4>
                                <p class="text-muted">No items are running low on stock.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Out of Stock Items Modal -->
        <div class="modal fade" id="outOfStockModal" tabindex="-1" aria-labelledby="outOfStockModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white;">
                        <h5 class="modal-title" id="outOfStockModalLabel">
                            <i class="fas fa-times-circle me-2"></i>Out of Stock Items
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($out_of_stock_items)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong><?= count($out_of_stock_items) ?></strong> item(s) are completely out of stock. Immediate action required!
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-danger">
                                        <tr>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <th>Current Stock</th>
                                            <th>Reorder Level</th>
                                            <th>Unit</th>
                                            <th>Supplier</th>
                                            <th>Last Updated</th>
                                            <?php if ($can_manage_aircon): ?>
                                                <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($out_of_stock_items as $item): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                                <td><?= htmlspecialchars($item['category']) ?></td>
                                                <td>
                                                    <span class="badge bg-danger">
                                                        <?= $item['current_stock'] ?>
                                                    </span>
                                                </td>
                                                <td><?= $item['reorder_level'] ?></td>
                                                <td><?= htmlspecialchars($item['unit']) ?></td>
                                                <td><?= htmlspecialchars($item['supplier_name'] ?? 'N/A') ?></td>
                                                <td><?= date('M d, Y', strtotime($item['date_updated'])) ?></td>
                                                <?php if ($can_manage_aircon): ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-success" onclick="stockIn(<?= $item['inventory_id'] ?>); $('#outOfStockModal').modal('hide');" title="Add Stock">
                                                            <i class="fas fa-plus"></i> Restock
                                                        </button>
                                                        <button class="btn btn-sm btn-info" onclick="editInventoryItem(<?= (int)$item['inventory_id'] ?>, 'outOfStockModal');" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                <h4>Excellent!</h4>
                                <p class="text-muted">No items are out of stock.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Good Condition Aircons Modal -->
        <div class="modal fade" id="goodConditionModal" tabindex="-1" aria-labelledby="goodConditionModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white;">
                        <h5 class="modal-title" id="goodConditionModalLabel">
                            <i class="fas fa-check-circle me-2"></i>Good Condition Aircons
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($good_condition_aircons)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong><?= count($good_condition_aircons) ?></strong> aircon unit(s) are in good working condition.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-success">
                                        <tr>
                                            <th>Brand</th>
                                            <th>Model</th>
                                            <th>Type</th>
                                            <th>Location</th>
                                            <th>Status</th>
                                            <th>Serial No.</th>
                                            <th>Last Service</th>
                                            <?php if ($can_manage_aircon): ?><th>Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($good_condition_aircons as $aircon): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($aircon['brand'] ?? 'N/A') ?></strong></td>
                                                <td><?= htmlspecialchars($aircon['model'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($aircon['type'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($aircon['location'] ?? 'N/A') ?></td>
                                                <td><span class="badge bg-success"><?= htmlspecialchars($aircon['status']) ?></span></td>
                                                <td><?= htmlspecialchars($aircon['serial_number'] ?? 'N/A') ?></td>
                                                <td><?= !empty($aircon['last_service_date']) ? date('M d, Y', strtotime($aircon['last_service_date'])) : 'N/A' ?></td>
                                                <?php if ($can_manage_aircon): ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary view-aircon-details-btn"
                                                            title="View Details"
                                                            data-aircon-id="<?= (int)$aircon['aircon_id'] ?>"
                                                            data-item-number="<?= htmlspecialchars($aircon['item_number'] ?? '', ENT_QUOTES) ?>"
                                                            data-brand="<?= htmlspecialchars($aircon['brand'] ?? '', ENT_QUOTES) ?>"
                                                            data-model="<?= htmlspecialchars($aircon['model'] ?? '', ENT_QUOTES) ?>"
                                                            data-type="<?= htmlspecialchars($aircon['type'] ?? '', ENT_QUOTES) ?>"
                                                            data-horse-power="<?= htmlspecialchars($aircon['horse_power'] ?? '', ENT_QUOTES) ?>"
                                                            data-serial-number="<?= htmlspecialchars($aircon['serial_number'] ?? '', ENT_QUOTES) ?>"
                                                            data-location="<?= htmlspecialchars($aircon['location'] ?? '', ENT_QUOTES) ?>"
                                                            data-status="<?= htmlspecialchars($aircon['status'] ?? '', ENT_QUOTES) ?>"
                                                            data-purchase-date="<?= htmlspecialchars($aircon['purchase_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-warranty-expiry="<?= htmlspecialchars($aircon['warranty_expiry'] ?? '', ENT_QUOTES) ?>"
                                                            data-last-service-date="<?= htmlspecialchars($aircon['last_service_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-maintenance-schedule="<?= htmlspecialchars($aircon['maintenance_schedule'] ?? '', ENT_QUOTES) ?>"
                                                            data-supplier-info="<?= htmlspecialchars($aircon['supplier_name'] ?? '', ENT_QUOTES) ?>"
                                                            data-installation-date="<?= htmlspecialchars($aircon['installation_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-energy-efficiency="<?= htmlspecialchars($aircon['energy_efficiency_rating'] ?? '', ENT_QUOTES) ?>"
                                                            data-power-consumption="<?= htmlspecialchars($aircon['power_consumption'] ?? '', ENT_QUOTES) ?>"
                                                            data-notes="<?= htmlspecialchars($aircon['notes'] ?? '', ENT_QUOTES) ?>"
                                                            data-purchase-price="<?= htmlspecialchars($aircon['purchase_price'] ?? '0', ENT_QUOTES) ?>"
                                                            data-depreciated-value="<?= htmlspecialchars($aircon['depreciated_value'] ?? '0', ENT_QUOTES) ?>"
                                                            data-lifespan="<?= htmlspecialchars($aircon['lifespan'] ?? '', ENT_QUOTES) ?>"
                                                            data-area="<?= htmlspecialchars($aircon['area'] ?? '', ENT_QUOTES) ?>"
                                                            data-receiver="<?= htmlspecialchars($aircon['receiver'] ?? '', ENT_QUOTES) ?>"
                                                            data-created-by="<?= htmlspecialchars($aircon['created_by'] ?? '', ENT_QUOTES) ?>"
                                                            data-date-created="<?= htmlspecialchars($aircon['date_created'] ?? '', ENT_QUOTES) ?>">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-snowflake fa-4x text-muted mb-3"></i>
                                <h4>No Aircons</h4>
                                <p class="text-muted">No aircons in good condition found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Needs Attention Aircons Modal -->
        <div class="modal fade" id="needsAttentionModal" tabindex="-1" aria-labelledby="needsAttentionModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: white;">
                        <h5 class="modal-title" id="needsAttentionModalLabel">
                            <i class="fas fa-exclamation-triangle me-2"></i>Aircons Needing Attention
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($needs_attention_aircons)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong><?= count($needs_attention_aircons) ?></strong> aircon unit(s) need repair or maintenance attention.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-warning">
                                        <tr>
                                            <th>Brand</th>
                                            <th>Model</th>
                                            <th>Type</th>
                                            <th>Location</th>
                                            <th>Status</th>
                                            <th>Serial No.</th>
                                            <th>Last Service</th>
                                            <?php if ($can_manage_aircon): ?><th>Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($needs_attention_aircons as $aircon): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($aircon['brand'] ?? 'N/A') ?></strong></td>
                                                <td><?= htmlspecialchars($aircon['model'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($aircon['type'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($aircon['location'] ?? 'N/A') ?></td>
                                                <td>
                                                    <span class="badge bg-<?= match ($aircon['status']) {
                                                                                'Working' => 'success',
                                                                                'Needs Repair' => 'warning',
                                                                                'Replace', 'Decommissioned' => 'danger',
                                                                                'Under Maintenance' => 'info',
                                                                                default => 'secondary'
                                                                            } ?>">
                                                        <?= htmlspecialchars($aircon['status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($aircon['serial_number'] ?? 'N/A') ?></td>
                                                <td><?= !empty($aircon['last_service_date']) ? date('M d, Y', strtotime($aircon['last_service_date'])) : 'N/A' ?></td>
                                                <?php if ($can_manage_aircon): ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary view-aircon-details-btn"
                                                            title="View Details"
                                                            data-aircon-id="<?= (int)$aircon['aircon_id'] ?>"
                                                            data-item-number="<?= htmlspecialchars($aircon['item_number'] ?? '', ENT_QUOTES) ?>"
                                                            data-brand="<?= htmlspecialchars($aircon['brand'] ?? '', ENT_QUOTES) ?>"
                                                            data-model="<?= htmlspecialchars($aircon['model'] ?? '', ENT_QUOTES) ?>"
                                                            data-type="<?= htmlspecialchars($aircon['type'] ?? '', ENT_QUOTES) ?>"
                                                            data-horse-power="<?= htmlspecialchars($aircon['horse_power'] ?? '', ENT_QUOTES) ?>"
                                                            data-serial-number="<?= htmlspecialchars($aircon['serial_number'] ?? '', ENT_QUOTES) ?>"
                                                            data-location="<?= htmlspecialchars($aircon['location'] ?? '', ENT_QUOTES) ?>"
                                                            data-status="<?= htmlspecialchars($aircon['status'] ?? '', ENT_QUOTES) ?>"
                                                            data-purchase-date="<?= htmlspecialchars($aircon['purchase_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-warranty-expiry="<?= htmlspecialchars($aircon['warranty_expiry'] ?? '', ENT_QUOTES) ?>"
                                                            data-last-service-date="<?= htmlspecialchars($aircon['last_service_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-maintenance-schedule="<?= htmlspecialchars($aircon['maintenance_schedule'] ?? '', ENT_QUOTES) ?>"
                                                            data-supplier-info="<?= htmlspecialchars($aircon['supplier_name'] ?? '', ENT_QUOTES) ?>"
                                                            data-installation-date="<?= htmlspecialchars($aircon['installation_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-energy-efficiency="<?= htmlspecialchars($aircon['energy_efficiency_rating'] ?? '', ENT_QUOTES) ?>"
                                                            data-power-consumption="<?= htmlspecialchars($aircon['power_consumption'] ?? '', ENT_QUOTES) ?>"
                                                            data-notes="<?= htmlspecialchars($aircon['notes'] ?? '', ENT_QUOTES) ?>"
                                                            data-purchase-price="<?= htmlspecialchars($aircon['purchase_price'] ?? '0', ENT_QUOTES) ?>"
                                                            data-depreciated-value="<?= htmlspecialchars($aircon['depreciated_value'] ?? '0', ENT_QUOTES) ?>"
                                                            data-lifespan="<?= htmlspecialchars($aircon['lifespan'] ?? '', ENT_QUOTES) ?>"
                                                            data-area="<?= htmlspecialchars($aircon['area'] ?? '', ENT_QUOTES) ?>"
                                                            data-receiver="<?= htmlspecialchars($aircon['receiver'] ?? '', ENT_QUOTES) ?>"
                                                            data-created-by="<?= htmlspecialchars($aircon['created_by'] ?? '', ENT_QUOTES) ?>"
                                                            data-date-created="<?= htmlspecialchars($aircon['date_created'] ?? '', ENT_QUOTES) ?>"
                                                            data-picture="<?= htmlspecialchars($aircon['picture'] ?? '', ENT_QUOTES) ?>"
                                                            data-modal-id="needsAttentionModal">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                <h4>All Good!</h4>
                                <p class="text-muted">No aircons need repair or maintenance attention.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Decommissioned Aircons Modal -->
        <div class="modal fade" id="decommissionedModal" tabindex="-1" aria-labelledby="decommissionedModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white;">
                        <h5 class="modal-title" id="decommissionedModalLabel">
                            <i class="fas fa-times-circle me-2"></i>Decommissioned Aircons
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($decommissioned_aircons)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-times-circle me-2"></i>
                                <strong><?= count($decommissioned_aircons) ?></strong> aircon unit(s) have been decommissioned and are out of service.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-danger">
                                        <tr>
                                            <th>Brand</th>
                                            <th>Model</th>
                                            <th>Type</th>
                                            <th>Location</th>
                                            <th>Status</th>
                                            <th>Serial No.</th>
                                            <th>Last Service</th>
                                            <?php if ($can_manage_aircon): ?><th>Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($decommissioned_aircons as $aircon): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($aircon['brand'] ?? 'N/A') ?></strong></td>
                                                <td><?= htmlspecialchars($aircon['model'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($aircon['type'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($aircon['location'] ?? 'N/A') ?></td>
                                                <td><span class="badge bg-danger"><?= htmlspecialchars($aircon['status']) ?></span></td>
                                                <td><?= htmlspecialchars($aircon['serial_number'] ?? 'N/A') ?></td>
                                                <td><?= !empty($aircon['last_service_date']) ? date('M d, Y', strtotime($aircon['last_service_date'])) : 'N/A' ?></td>
                                                <?php if ($can_manage_aircon): ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary view-aircon-details-btn"
                                                            title="View Details"
                                                            data-aircon-id="<?= (int)$aircon['aircon_id'] ?>"
                                                            data-item-number="<?= htmlspecialchars($aircon['item_number'] ?? '', ENT_QUOTES) ?>"
                                                            data-brand="<?= htmlspecialchars($aircon['brand'] ?? '', ENT_QUOTES) ?>"
                                                            data-model="<?= htmlspecialchars($aircon['model'] ?? '', ENT_QUOTES) ?>"
                                                            data-type="<?= htmlspecialchars($aircon['type'] ?? '', ENT_QUOTES) ?>"
                                                            data-horse-power="<?= htmlspecialchars($aircon['horse_power'] ?? '', ENT_QUOTES) ?>"
                                                            data-serial-number="<?= htmlspecialchars($aircon['serial_number'] ?? '', ENT_QUOTES) ?>"
                                                            data-location="<?= htmlspecialchars($aircon['location'] ?? '', ENT_QUOTES) ?>"
                                                            data-status="<?= htmlspecialchars($aircon['status'] ?? '', ENT_QUOTES) ?>"
                                                            data-purchase-date="<?= htmlspecialchars($aircon['purchase_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-warranty-expiry="<?= htmlspecialchars($aircon['warranty_expiry'] ?? '', ENT_QUOTES) ?>"
                                                            data-last-service-date="<?= htmlspecialchars($aircon['last_service_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-maintenance-schedule="<?= htmlspecialchars($aircon['maintenance_schedule'] ?? '', ENT_QUOTES) ?>"
                                                            data-supplier-info="<?= htmlspecialchars($aircon['supplier_name'] ?? '', ENT_QUOTES) ?>"
                                                            data-installation-date="<?= htmlspecialchars($aircon['installation_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-energy-efficiency="<?= htmlspecialchars($aircon['energy_efficiency_rating'] ?? '', ENT_QUOTES) ?>"
                                                            data-power-consumption="<?= htmlspecialchars($aircon['power_consumption'] ?? '', ENT_QUOTES) ?>"
                                                            data-notes="<?= htmlspecialchars($aircon['notes'] ?? '', ENT_QUOTES) ?>"
                                                            data-purchase-price="<?= htmlspecialchars($aircon['purchase_price'] ?? '0', ENT_QUOTES) ?>"
                                                            data-depreciated-value="<?= htmlspecialchars($aircon['depreciated_value'] ?? '0', ENT_QUOTES) ?>"
                                                            data-lifespan="<?= htmlspecialchars($aircon['lifespan'] ?? '', ENT_QUOTES) ?>"
                                                            data-area="<?= htmlspecialchars($aircon['area'] ?? '', ENT_QUOTES) ?>"
                                                            data-receiver="<?= htmlspecialchars($aircon['receiver'] ?? '', ENT_QUOTES) ?>"
                                                            data-created-by="<?= htmlspecialchars($aircon['created_by'] ?? '', ENT_QUOTES) ?>"
                                                            data-date-created="<?= htmlspecialchars($aircon['date_created'] ?? '', ENT_QUOTES) ?>"
                                                            data-picture="<?= htmlspecialchars($aircon['picture'] ?? '', ENT_QUOTES) ?>"
                                                            data-modal-id="decommissionedModal">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                <h4>Excellent!</h4>
                                <p class="text-muted">No aircons have been decommissioned.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Not Applicable Aircons Modal -->
        <div class="modal fade" id="notApplicableModal" tabindex="-1" aria-labelledby="notApplicableModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%); color: white;">
                        <h5 class="modal-title" id="notApplicableModalLabel">
                            <i class="fas fa-ban me-2"></i>Not Applicable Aircons
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($not_applicable_aircons)): ?>
                            <div class="alert alert-secondary">
                                <i class="fas fa-ban me-2"></i>
                                <strong><?= count($not_applicable_aircons) ?></strong> aircon unit(s) are marked as Not Applicable.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-secondary">
                                        <tr>
                                            <th>Brand</th>
                                            <th>Model</th>
                                            <th>Type</th>
                                            <th>Location</th>
                                            <th>Status</th>
                                            <th>Serial No.</th>
                                            <th>Last Service</th>
                                            <?php if ($can_manage_aircon): ?><th>Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($not_applicable_aircons as $aircon): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($aircon['brand'] ?? 'N/A') ?></strong></td>
                                                <td><?= htmlspecialchars($aircon['model'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($aircon['type'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($aircon['location'] ?? 'N/A') ?></td>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars($aircon['status']) ?></span></td>
                                                <td><?= htmlspecialchars($aircon['serial_number'] ?? 'N/A') ?></td>
                                                <td><?= !empty($aircon['last_service_date']) ? date('M d, Y', strtotime($aircon['last_service_date'])) : 'N/A' ?></td>
                                                <?php if ($can_manage_aircon): ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary view-aircon-details-btn"
                                                            title="View Details"
                                                            data-aircon-id="<?= (int)$aircon['aircon_id'] ?>"
                                                            data-item-number="<?= htmlspecialchars($aircon['item_number'] ?? '', ENT_QUOTES) ?>"
                                                            data-brand="<?= htmlspecialchars($aircon['brand'] ?? '', ENT_QUOTES) ?>"
                                                            data-model="<?= htmlspecialchars($aircon['model'] ?? '', ENT_QUOTES) ?>"
                                                            data-type="<?= htmlspecialchars($aircon['type'] ?? '', ENT_QUOTES) ?>"
                                                            data-horse-power="<?= htmlspecialchars($aircon['horse_power'] ?? '', ENT_QUOTES) ?>"
                                                            data-serial-number="<?= htmlspecialchars($aircon['serial_number'] ?? '', ENT_QUOTES) ?>"
                                                            data-location="<?= htmlspecialchars($aircon['location'] ?? '', ENT_QUOTES) ?>"
                                                            data-status="<?= htmlspecialchars($aircon['status'] ?? '', ENT_QUOTES) ?>"
                                                            data-purchase-date="<?= htmlspecialchars($aircon['purchase_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-warranty-expiry="<?= htmlspecialchars($aircon['warranty_expiry'] ?? '', ENT_QUOTES) ?>"
                                                            data-last-service-date="<?= htmlspecialchars($aircon['last_service_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-maintenance-schedule="<?= htmlspecialchars($aircon['maintenance_schedule'] ?? '', ENT_QUOTES) ?>"
                                                            data-supplier-info="<?= htmlspecialchars($aircon['supplier_name'] ?? '', ENT_QUOTES) ?>"
                                                            data-installation-date="<?= htmlspecialchars($aircon['installation_date'] ?? '', ENT_QUOTES) ?>"
                                                            data-energy-efficiency="<?= htmlspecialchars($aircon['energy_efficiency_rating'] ?? '', ENT_QUOTES) ?>"
                                                            data-power-consumption="<?= htmlspecialchars($aircon['power_consumption'] ?? '', ENT_QUOTES) ?>"
                                                            data-notes="<?= htmlspecialchars($aircon['notes'] ?? '', ENT_QUOTES) ?>"
                                                            data-purchase-price="<?= htmlspecialchars($aircon['purchase_price'] ?? '0', ENT_QUOTES) ?>"
                                                            data-depreciated-value="<?= htmlspecialchars($aircon['depreciated_value'] ?? '0', ENT_QUOTES) ?>"
                                                            data-lifespan="<?= htmlspecialchars($aircon['lifespan'] ?? '', ENT_QUOTES) ?>"
                                                            data-area="<?= htmlspecialchars($aircon['area'] ?? '', ENT_QUOTES) ?>"
                                                            data-receiver="<?= htmlspecialchars($aircon['receiver'] ?? '', ENT_QUOTES) ?>"
                                                            data-created-by="<?= htmlspecialchars($aircon['created_by'] ?? '', ENT_QUOTES) ?>"
                                                            data-date-created="<?= htmlspecialchars($aircon['date_created'] ?? '', ENT_QUOTES) ?>"
                                                            data-picture="<?= htmlspecialchars($aircon['picture'] ?? '', ENT_QUOTES) ?>"
                                                            data-modal-id="notApplicableModal">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-ban fa-4x text-muted mb-3"></i>
                                <h4>No Aircons</h4>
                                <p class="text-muted">No aircons are marked as Not Applicable.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        

        <!-- Aircon Table -->
        <div class="table-container">
            <div class="table-header aircon-list-header">
                <div class="aircon-header-top">
                    <h3 class="mb-0"><i class="fas fa-snowflake me-2"></i>Aircons List</h3>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a id="airconExportBtn" class="btn btn-success btn-sm" href="../actions/export_aircons.php?search=<?= urlencode($search_term) ?>&sy_inv=<?= urlencode($sy_inv_raw) ?>&campus=<?= urlencode($campus_raw) ?>&status=<?= urlencode($status_raw) ?>">
                            <i class="fas fa-file-export"></i> Export
                        </a>
                        <?php if ($can_manage_aircon): ?>
                            <button class="btn btn-add text-dark btn-sm" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
                                <i class="fas fa-plus"></i> Add Aircon
                            </button>
                            <button type="button" class="btn btn-success btn-sm btn-print-all-qr" onclick="printAllAirconQr()">
                                <i class="fas fa-qrcode"></i> Print All QR
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="GET" class="aircon-filter-bar" id="airconFilterForm">
                    <div class="aircon-filter-field aircon-filter-search search-input">
                        <label for="search" class="form-label mb-0 text-white">Search Aircon</label>
                        <input type="text" id="search" name="search" class="form-control form-control-sm" placeholder="Brand, model, type, serial no., location..." value="<?= htmlspecialchars($search_term) ?>">
                    </div>
                    <div class="aircon-filter-field">
                        <label for="sy_inv" class="form-label mb-0 text-white">School Year</label>
                        <select id="sy_inv" name="sy_inv" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($sy_years as $sy): ?>
                                <option value="<?= htmlspecialchars($sy) ?>" <?= ($sy_inv_raw === $sy) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sy) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="aircon-filter-field">
                        <label for="campus" class="form-label mb-0 text-white">Campus</label>
                        <select id="campus" name="campus" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="BED" <?= ($campus_raw === 'BED') ? 'selected' : '' ?>>BED</option>
                            <option value="TED" <?= ($campus_raw === 'TED') ? 'selected' : '' ?>>TED</option>
                        </select>
                    </div>
                    <div class="aircon-filter-field">
                        <label for="status" class="form-label mb-0 text-white">Status</label>
                        <select id="status" name="status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach (['Working', 'Replace', 'Needs Repair', 'Needs Attention', 'Under Maintenance', 'Decommissioned', 'Not Applicable'] as $st): ?>
                                <option value="<?= htmlspecialchars($st) ?>" <?= ($status_raw === $st) ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="aircon-filter-actions">
                        <button type="submit" class="btn btn-search btn-sm">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <?php if (!empty($search_term) || !empty($sy_inv_raw) || !empty($campus_raw) || !empty($status_raw)): ?>
                            <a href="aircon_list.php" class="btn btn-outline-light btn-sm">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>


            <?php
            // Pagination settings
            $records_per_page = 10;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $offset = ($page - 1) * $records_per_page;

            // Get total number of records
            $count_sql = "SELECT COUNT(*) as total FROM aircons i $inv_where";
            $count_result = $conn->query($count_sql);
            $total_records = $count_result->fetch_assoc()['total'];
            $total_pages = ceil($total_records / $records_per_page);

            // Get inventory data with pagination (respect filters and join supplier)
            // Sort by most recently touched first (date_updated on edit,
            // falling back to date_created) so an edited unit always appears
            // as the top row of page 1 after a successful save.
            $sql = "SELECT i.*, s.supplier_name, 
                           (SELECT image_path FROM aircon_images WHERE aircon_id = i.aircon_id ORDER BY id ASC LIMIT 1) as first_image 
                    FROM aircons i 
                    LEFT JOIN supplier s ON i.supplier_id = s.supplier_id 
                    $inv_where
                    ORDER BY COALESCE(i.date_updated, i.date_created) DESC, i.installation_date DESC, i.date_created DESC
                    LIMIT $records_per_page OFFSET $offset";
            $result = $conn->query($sql);
            ?>


            <!-- Aircon Table List -->
            <div class="table-responsive">
                <div id="inventoryTable">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Campus</th>
                                <th>Brand</th>
                                <th>Model</th>
                                <th>Type</th>
                                <th>Horse Power</th>
                                <th>Serial No.</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Maintenance Schedule</th>
                                <th>Installation Date/ Release Date</th>
                                <th>Notes</th>
                                <?php if ($can_manage_aircon): ?>
                                    <th>QR Code</th>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php
                                $item_counter = 1;
                                while ($row = $result->fetch_assoc()):
                                ?>
                                    <tr>
                                        <td data-label="Campus"><?= htmlspecialchars($row['campus'] ?? 'N/A') ?></td>
                                        <td data-label="Brand"><?= htmlspecialchars($row['brand'] ?? 'N/A') ?></td>
                                        <td data-label="Model"><?= htmlspecialchars($row['model'] ?? 'N/A') ?></td>
                                        <td data-label="Type"><?= htmlspecialchars($row['type'] ?? 'N/A') ?></td>
                                        <td data-label="Horse Power"><?= htmlspecialchars($row['horse_power'] ?? 'N/A') ?></td>
                                        <td data-label="Serial No."><?= htmlspecialchars($row['serial_number'] ?? 'N/A') ?></td>
                                        <td data-label="Location"><?= htmlspecialchars($row['location'] ?? 'N/A') ?></td>
                                        <td data-label="Status">
                                            <span class="badge bg-<?= match (strtolower(trim($row['status'] ?? ''))) {
                                                                        'working' => 'success',
                                                                        'needs repair', 'needs attention', 'not applicable', 'n/a' => 'warning text-dark',
                                                                        'replace', 'decommissioned' => 'danger',
                                                                        'under maintenance' => 'info',
                                                                        default => 'danger'
                                                                    } ?>">
                                                <?= htmlspecialchars($row['status'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td data-label="Maintenance Schedule">
                                            <button class="btn btn-sm btn-outline-info view-maintenance-btn"
                                                data-aircon-id="<?= (int)$row['aircon_id'] ?>"
                                                data-brand="<?= htmlspecialchars($row['brand'] ?? '', ENT_QUOTES) ?>"
                                                data-model="<?= htmlspecialchars($row['model'] ?? '', ENT_QUOTES) ?>"
                                                data-serial="<?= htmlspecialchars($row['serial_number'] ?? '', ENT_QUOTES) ?>"
                                                title="View Maintenance Records">
                                                <i class="fas fa-calendar-alt"></i> View Records
                                            </button>
                                        </td>
                                        <td data-label="Installation Date"><?= !empty($row['installation_date']) ? date('M d, Y', strtotime($row['installation_date'])) : 'N/A' ?></td>
                                        <td data-label="Notes"><?= htmlspecialchars($row['notes'] ?? 'N/A') ?></td>
                                        <?php if ($can_manage_aircon): ?>
                                            <td data-label="QR Code" class="text-center">
                                                <button type="button" class="btn btn-outline-info btn-sm btn-print-single-qr"
                                                    onclick="printSingleAirconQr(<?= (int)$row['aircon_id'] ?>, this)"
                                                    title="Print QR Code"
                                                    data-aircon-id="<?= (int)$row['aircon_id'] ?>"
                                                    data-item-number="<?= htmlspecialchars($row['item_number'] ?? '', ENT_QUOTES) ?>"
                                                    data-serial-number="<?= htmlspecialchars($row['serial_number'] ?? '', ENT_QUOTES) ?>"
                                                    data-brand="<?= htmlspecialchars($row['brand'] ?? '', ENT_QUOTES) ?>"
                                                    data-model="<?= htmlspecialchars($row['model'] ?? '', ENT_QUOTES) ?>"
                                                    data-location="<?= htmlspecialchars($row['location'] ?? '', ENT_QUOTES) ?>"
                                                    data-campus="<?= htmlspecialchars($row['campus'] ?? '', ENT_QUOTES) ?>">
                                                    <i class="fas fa-qrcode me-1"></i> QR
                                                </button>
                                                <div id="qr-display-<?= (int)$row['aircon_id'] ?>" class="qr-print-container mt-2" style="display:none;"></div>
                                            </td>
                                            <td data-label="Actions" class="actions">
                                                <button class="btn btn-sm btn-primary view-aircon-details-btn"
                                                    title="View Details"
                                                    data-aircon-id="<?= (int)$row['aircon_id'] ?>"
                                                    data-item-number="<?= htmlspecialchars($row['item_number'] ?? '', ENT_QUOTES) ?>"
                                                    data-brand="<?= htmlspecialchars($row['brand'] ?? '', ENT_QUOTES) ?>"
                                                    data-model="<?= htmlspecialchars($row['model'] ?? '', ENT_QUOTES) ?>"
                                                    data-type="<?= htmlspecialchars($row['type'] ?? '', ENT_QUOTES) ?>"
                                                    data-horse-power="<?= htmlspecialchars($row['horse_power'] ?? '', ENT_QUOTES) ?>"
                                                    data-serial-number="<?= htmlspecialchars($row['serial_number'] ?? '', ENT_QUOTES) ?>"
                                                    data-location="<?= htmlspecialchars($row['location'] ?? '', ENT_QUOTES) ?>"
                                                    data-status="<?= htmlspecialchars($row['status'] ?? '', ENT_QUOTES) ?>"
                                                    data-purchase-date="<?= htmlspecialchars($row['purchase_date'] ?? '', ENT_QUOTES) ?>"
                                                    data-warranty-expiry="<?= htmlspecialchars($row['warranty_expiry'] ?? '', ENT_QUOTES) ?>"
                                                    data-last-service-date="<?= htmlspecialchars($row['last_service_date'] ?? '', ENT_QUOTES) ?>"
                                                    data-maintenance-schedule="<?= htmlspecialchars($row['maintenance_schedule'] ?? '', ENT_QUOTES) ?>"
                                                    data-supplier-info="<?= htmlspecialchars($row['supplier_name'] ?? '', ENT_QUOTES) ?>"
                                                    data-installation-date="<?= htmlspecialchars($row['installation_date'] ?? '', ENT_QUOTES) ?>"
                                                    data-energy-efficiency="<?= htmlspecialchars($row['energy_efficiency_rating'] ?? '', ENT_QUOTES) ?>"
                                                    data-power-consumption="<?= htmlspecialchars($row['power_consumption'] ?? '', ENT_QUOTES) ?>"
                                                    data-notes="<?= htmlspecialchars($row['notes'] ?? '', ENT_QUOTES) ?>"
                                                    data-purchase-price="<?= htmlspecialchars($row['purchase_price'] ?? '0', ENT_QUOTES) ?>"
                                                    data-depreciated-value="<?= htmlspecialchars($row['depreciated_value'] ?? '0', ENT_QUOTES) ?>"
                                                    data-lifespan="<?= htmlspecialchars($row['lifespan'] ?? '', ENT_QUOTES) ?>"
                                                    data-area="<?= htmlspecialchars($row['area'] ?? '', ENT_QUOTES) ?>"
                                                    data-receiver="<?= htmlspecialchars($row['receiver'] ?? '', ENT_QUOTES) ?>"
                                                    data-created-by="<?= htmlspecialchars($row['created_by'] ?? '', ENT_QUOTES) ?>"
                                                    data-date-created="<?= htmlspecialchars($row['date_created'] ?? '', ENT_QUOTES) ?>"
                                                    data-picture="<?= htmlspecialchars($row['first_image'] ?? '', ENT_QUOTES) ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-info" title="Edit"
                                                    onclick="openEditAirconModal(
                                                    <?= (int)$row['aircon_id'] ?>,
                                                    <?= htmlspecialchars(json_encode($row['item_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['brand'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['horse_power'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['serial_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['location'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['area'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['campus'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['purchase_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['warranty_expiry'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['last_service_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['maintenance_schedule'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= (int)($row['supplier_id'] ?? 0) ?>,
                                                    <?= htmlspecialchars(json_encode($row['installation_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['energy_efficiency_rating'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['power_consumption'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['purchase_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['depreciated_value'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['lifespan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= htmlspecialchars(json_encode($row['first_image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                    )">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" title="Delete"
                                                    onclick='deleteAircon(<?= (int)$row['aircon_id'] ?>, <?= json_encode($row['brand'] ?? '') ?>)'>
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="13" class="text-center py-4">
                                        <i class="fas fa-snowflake fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No aircon units found</p>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
                                            Add First Aircon
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center mt-3" id="paginationContainer">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="#" onclick="loadInventory(<?= max(1, $page - 1) ?>); return false;">&laquo;</a>
                        </li>
                        <?php
                        $neighborRange = 2;
                        $start = max(1, (int)$page - $neighborRange);
                        $end = min((int)$total_pages, (int)$page + $neighborRange);
                        ?>
                        <li class="page-item <?= (1 === (int)$page) ? 'active' : '' ?>">
                            <a class="page-link" href="#" onclick="loadInventory(1); return false;">1</a>
                        </li>

                        <?php if ($start > 2): ?>
                            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                        <?php endif; ?>

                        <?php for ($i = $start; $i <= $end; $i++): ?>
                            <?php if ($i === 1 || $i === (int)$total_pages) continue; ?>
                            <li class="page-item <?= ($i === (int)$page) ? 'active' : '' ?>">
                                <a class="page-link" href="#" onclick="loadInventory(<?= $i ?>); return false;"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($end < ((int)$total_pages - 1)): ?>
                            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                        <?php endif; ?>

                        <?php if ($total_pages > 1): ?>
                            <li class="page-item <?= (((int)$total_pages === (int)$page) ? 'active' : '') ?>">
                                <a class="page-link" href="#" onclick="loadInventory(<?= (int)$total_pages ?>); return false;"><?= (int)$total_pages ?></a>
                            </li>
                        <?php endif; ?>

                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="#" onclick="loadInventory(<?= min((int)$total_pages, $page + 1) ?>); return false;">&raquo;</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

            <!-- Approve/Received Modal -->
            <div class="modal fade" id="receivedModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Mark as Received Items</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="approveForm" action="../actions/mark_property_transaction.php" method="POST">
                            <input type="hidden" id="approve-id" name="procurement_id" style="display: none;">
                            <input type="hidden" id="approve-item-name" name="item_name" style="display: none;">
                            <input type="hidden" id="approve-quantity" name="quantity" style="display: none;">
                            <input type="hidden" id="approve-unit" name="unit" style="display: none;">
                            <input type="hidden" id="approve-supplier" name="supplier" style="display: none;">
                            <input type="hidden" id="approve-price" name="price" style="display: none;">
                            <input type="hidden" id="approve-notes" name="notes" style="display: none;">
                            <div class="modal-body">
                                <div class="text-center mb-3">
                                    <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                                </div>
                                <p class="text-center">Are you sure you want to mark this item as received and add the item to the inventory?</p>
                                <div class="text-center mb-3">
                                    <strong id="display-item-name"></strong>
                                </div>

                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Received Date</label>
                                        <input type="date" name="received_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Received Notes (Optional)</label>
                                        <textarea name="received_notes" class="form-control" rows="3" placeholder="Any additional notes about the received items..."></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">Mark as Received</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Add New Aircon Modal -->
            <div class="modal fade" id="addInventoryModal" tabindex="-1">
                <div class="modal-dialog modal-xl modal-dialog-scrollable aircon-edit-dialog">
                    <div class="modal-content aircon-edit-modal">
                        <div class="modal-header aircon-edit-header">
                            <div class="d-flex align-items-center gap-3">
                                <span class="aircon-edit-icon"><i class="fas fa-plus"></i></span>
                                <div>
                                    <h5 class="modal-title text-white mb-0">Add New Aircon</h5>
                                    <small class="aircon-edit-subtitle">Register a new aircon unit to the inventory</small>
                                </div>
                            </div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="../actions/add_aircon.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="receiver" value="Property Custodian">
                            <div class="modal-body aircon-edit-body">
                                <!-- Unit Information -->
                                <div class="aircon-edit-section mb-3">
                                    <h6 class="aircon-edit-section-title"><i class="fas fa-snowflake me-2"></i>Unit Information</h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Item Number <span class="text-danger">*</span></label>
                                            <input type="text" name="item_name" class="form-control" required placeholder="e.g., AC-001">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Category <span class="text-danger">*</span></label>
                                            <select id="accountSelect" name="category" class="form-select">
                                                <option value="">Select Category</option>
                                                <?php
                                                // Use the same organized categories from the main page
                                                if (isset($organized_categories) && !empty($organized_categories)) {
                                                    foreach ($organized_categories as $main_category => $subcategories) {
                                                        echo '<optgroup label="' . htmlspecialchars($main_category) . '">';
                                                        foreach ($subcategories as $subcategory) {
                                                            echo '<option value="' . htmlspecialchars($subcategory) . '">' . htmlspecialchars($subcategory) . '</option>';
                                                        }
                                                        echo '</optgroup>';
                                                    }
                                                } else {
                                                    // Fallback options if no data available - display as bold headers only
                                                    echo '<optgroup label="Property and Equipment"></optgroup>';
                                                    echo '<optgroup label="Intangible Assets"></optgroup>';
                                                    echo '<optgroup label="Office Supplies"></optgroup>';
                                                    echo '<optgroup label="Medical Supplies"></optgroup>';
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Brand <span class="text-danger">*</span></label>
                                            <input type="text" name="brand" class="form-control" required placeholder="e.g., Carrier, Daikin">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Model <span class="text-danger">*</span></label>
                                            <input type="text" name="model" class="form-control" required placeholder="e.g., 42QHC018">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Type</label>
                                            <select name="type" class="form-select">
                                                <option value="">Select Type</option>
                                                <option value="Split">Split</option>
                                                <option value="Window">Window</option>
                                                <option value="Portable">Portable</option>
                                                <option value="Cassette">Cassette</option>
                                                <option value="Central">Central</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Horse Power</label>
                                            <input type="text" name="horse_power" class="form-control" placeholder="e.g., 1.5 HP">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Serial Number</label>
                                            <input type="text" name="serial_number" class="form-control" placeholder="Manufacturer serial number">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Location</label>
                                            <input type="text" name="location" class="form-control" placeholder="e.g., Room 101, Office">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Area</label>
                                            <input type="text" name="area" class="form-control" placeholder="e.g., Ground Floor, Annex">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Status</label>
                                            <select name="status" class="form-select">
                                                <option value="Working">Working</option>
                                                <option value="Replace">Replace</option>
                                                <option value="Needs Repair">Needs Repair</option>
                                                <option value="Needs Attention">Needs Attention</option>
                                                <option value="Under Maintenance">Under Maintenance</option>
                                                <option value="Decommissioned">Decommissioned</option>
                                                <option value="Not Applicable">Not Applicable</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Campus</label>
                                            <select name="campus" class="form-select">
                                                <option value="TED">TED</option>
                                                <option value="BED">BED</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Dates & Supplier -->
                                <div class="aircon-edit-section mb-3">
                                    <h6 class="aircon-edit-section-title"><i class="fas fa-calendar-alt me-2"></i>Dates & Supplier</h6>
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label">Purchase Date</label>
                                            <input type="date" name="purchase_date" class="form-control">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Warranty Expiry</label>
                                            <input type="date" name="warranty_expiry" class="form-control">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Last Service Date</label>
                                            <input type="date" name="last_service" class="form-control">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Maintenance Schedule</label>
                                            <input type="date" name="maintenance_schedule" class="form-control">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Supplier</label>
                                            <select name="supplier_id" class="form-select">
                                                <option value="">Select Supplier</option>
                                                <?php
                                                // Reset the result pointer to reuse the suppliers data
                                                if ($suppliers_result && $suppliers_result->num_rows > 0) {
                                                    $suppliers_result->data_seek(0);
                                                    while ($supplier = $suppliers_result->fetch_assoc()) {
                                                        echo '<option value="' . $supplier['supplier_id'] . '">' . htmlspecialchars($supplier['supplier_name']) . '</option>';
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Installation Date/ Release Date</label>
                                            <input type="date" name="installation_date" class="form-control">
                                        </div>
                                    </div>
                                </div>

                                <!-- Value, Specs & Pictures -->
                                <div class="aircon-edit-section">
                                    <h6 class="aircon-edit-section-title"><i class="fas fa-coins me-2"></i>Value, Specs & Pictures</h6>
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label">Energy Efficiency Rating</label>
                                            <input type="text" name="energy_efficient" class="form-control" placeholder="e.g., 5-star, A++">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Power Consumption (kW)</label>
                                            <input type="number" step="0.1" name="power_consumption" class="form-control" placeholder="e.g., 1.5">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Purchase Price (₱)</label>
                                            <input type="number" step="0.01" name="purchase_price" class="form-control" placeholder="0.00">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Depreciated Value (₱)</label>
                                            <input type="number" step="0.01" name="depreciated_value" class="form-control" placeholder="0.00">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Lifespan</label>
                                            <input type="text" name="lifespan" class="form-control" placeholder="e.g., 10 years">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Upload Pictures (Multiple)</label>
                                            <input type="file" name="pictures[]" id="aircon_picture_add" class="form-control" accept="image/*" multiple>
                                            <div id="picture_preview_container_add" class="mt-2" style="display:none;">
                                                <div id="picture_previews_add" class="d-flex flex-wrap gap-2"></div>
                                                <button type="button" class="btn btn-sm btn-danger mt-1 d-block" onclick="clearImagePreview('add')">
                                                    <i class="fas fa-times"></i> Clear All
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer aircon-edit-footer">
                                <button type="button" class="btn aircon-btn-ghost" data-bs-dismiss="modal"><i class="fas fa-xmark me-1"></i>Cancel</button>
                                <button type="submit" class="btn aircon-btn-save"><i class="fas fa-plus me-1"></i>Add Item</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- View Aircon Details Modal -->
            <div class="modal fade" id="viewAirconModal" tabindex="-1">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header text-white" style="background-color: var(--primary-green);">
                            <h5 class="modal-title">
                                <i class="fas fa-snowflake me-2"></i>Aircon Unit Details
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body aircon-view-body">
                            <style>
                                /* Compact Aircon Unit Details — scoped classes.
                                   Kept inside .modal-body so printAirconDetails()
                                   (which copies this markup) inherits them too. */
                                .aircon-view-hero {
                                    background: linear-gradient(135deg, var(--primary-green) 0%, #0e5a2e 100%);
                                    color: #fff;
                                    border-radius: 12px;
                                    padding: 14px 16px;
                                    margin-bottom: 10px;
                                    -webkit-print-color-adjust: exact;
                                    print-color-adjust: exact;
                                }
                                .aircon-view-brand {
                                    font-size: 1.4rem;
                                    font-weight: 700;
                                    line-height: 1.2;
                                    word-break: break-word;
                                }
                                .aircon-view-sub {
                                    font-size: 0.92rem;
                                    opacity: 0.92;
                                    margin-top: 1px;
                                }
                                .aircon-view-itemnum {
                                    font-size: 0.76rem;
                                    opacity: 0.75;
                                    margin-top: 3px;
                                }
                                .aircon-view-keyfacts {
                                    display: grid;
                                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                                    gap: 6px;
                                    margin-top: 10px;
                                }
                                .aircon-view-keyfact {
                                    background: rgba(255, 255, 255, 0.12);
                                    border: 1px solid rgba(255, 255, 255, 0.2);
                                    border-radius: 8px;
                                    padding: 6px 10px;
                                    min-width: 0;
                                }
                                .aircon-view-keyfact label {
                                    display: block;
                                    font-size: 0.66rem;
                                    font-weight: 600;
                                    text-transform: uppercase;
                                    letter-spacing: 0.05em;
                                    opacity: 0.8;
                                    margin-bottom: 1px;
                                }
                                .aircon-view-keyfact b {
                                    font-size: 0.85rem;
                                    word-break: break-word;
                                }
                                .aircon-view-status {
                                    font-size: 0.9rem;
                                    padding: 0.45em 0.9em;
                                    border-radius: 8px;
                                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
                                    white-space: nowrap;
                                }
                                .aircon-view-card {
                                    background: #fff;
                                    border: 1px solid #e5e9e4;
                                    border-left: 3px solid var(--primary-green);
                                    border-radius: 10px;
                                    padding: 10px 12px;
                                    height: 100%;
                                    min-width: 0;
                                }
                                .aircon-view-card > h6 {
                                    color: var(--primary-green);
                                    font-size: 0.72rem;
                                    font-weight: 700;
                                    text-transform: uppercase;
                                    letter-spacing: 0.06em;
                                    border-bottom: 2px solid #eef1ec;
                                    padding-bottom: 4px;
                                    margin-bottom: 8px;
                                }
                                .av-row {
                                    display: flex;
                                    justify-content: space-between;
                                    align-items: baseline;
                                    gap: 10px;
                                    padding: 3.5px 0;
                                    border-bottom: 1px dashed #eef1ec;
                                }
                                .av-row:last-child {
                                    border-bottom: none;
                                }
                                .av-row label {
                                    color: #6c757d;
                                    font-size: 0.78rem;
                                    margin: 0;
                                    flex-shrink: 0;
                                }
                                .av-row b {
                                    font-size: 0.82rem;
                                    text-align: right;
                                    word-break: break-word;
                                }
                                .aircon-view-meta {
                                    font-size: 0.75rem;
                                    color: #6c757d;
                                    margin-top: 8px;
                                    padding-top: 6px;
                                    border-top: 1px dashed #eef1ec;
                                }
                                .aircon-view-qrimg {
                                    width: 120px;
                                    height: 120px;
                                    object-fit: contain;
                                    border: 1px solid #dee2e6;
                                    border-radius: 8px;
                                    background: #fff;
                                    padding: 4px;
                                }
                                /* Default image placeholder used when an aircon has no uploaded picture. */
                                .aircon-default-pic {
                                    display: flex;
                                    flex-direction: column;
                                    align-items: center;
                                    justify-content: center;
                                    height: 100%;
                                    min-height: 140px;
                                    border: 2px dashed #dee2e6;
                                    border-radius: 10px;
                                    background: #f8f9fa;
                                    cursor: default;
                                    padding: 12px;
                                    text-align: center;
                                }
                                .aircon-default-pic img {
                                    width: 100px;
                                    height: 100px;
                                    object-fit: contain;
                                    color: var(--primary-green);
                                }
                                .aircon-default-caption {
                                    margin-top: 6px;
                                    font-size: 0.75rem;
                                    color: #6c757d;
                                    max-width: 220px;
                                }
                                @media (max-width: 576px) {
                                    .aircon-view-hero { padding: 12px; }
                                    .aircon-view-brand { font-size: 1.2rem; }
                                    .aircon-view-status { font-size: 0.8rem; }
                                }
                            </style>

                            <!-- Highlight strip: the most important facts at a glance -->
                            <div class="aircon-view-hero">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div style="min-width:0;">
                                        <div class="aircon-view-brand" id="view_brand">-</div>
                                        <div class="aircon-view-sub">Model: <span id="view_model">-</span> &middot; Type: <span id="view_type">-</span></div>
                                        <div class="aircon-view-itemnum"><i class="fas fa-tag me-1"></i>Item No. <span id="view_item_number">-</span> &middot; ID <span id="view_aircon_id">-</span></div>
                                    </div>
                                    <span id="view_status" class="badge aircon-view-status bg-secondary">-</span>
                                </div>
                                <div class="aircon-view-keyfacts">
                                    <div class="aircon-view-keyfact"><label>Serial No.</label><b id="view_serial_number">-</b></div>
                                    <div class="aircon-view-keyfact"><label>Location</label><b id="view_location">-</b></div>
                                    <div class="aircon-view-keyfact"><label>Area</label><b id="view_area">-</b></div>
                                    <div class="aircon-view-keyfact"><label>Horse Power</label><b id="view_horse_power">-</b></div>
                                </div>
                            </div>

                            <div class="row g-2">
                                <!-- Dates & Maintenance -->
                                <div class="col-12 col-md-6">
                                    <div class="aircon-view-card">
                                        <h6><i class="fas fa-calendar-alt me-1"></i>Dates & Maintenance</h6>
                                        <div class="av-row"><label>Purchase Date</label><b id="view_purchase_date">-</b></div>
                                        <div class="av-row"><label>Warranty Expiry</label><b id="view_warranty_expiry">-</b></div>
                                        <div class="av-row"><label>Installation / Release</label><b id="view_installation_date">-</b></div>
                                        <div class="av-row"><label>Last Service</label><b id="view_last_service_date">-</b></div>
                                        <div class="av-row"><label>Next Maintenance</label><b id="view_maintenance_schedule">-</b></div>
                                        <div class="av-row"><label>Supplier</label><b id="view_supplier_info">-</b></div>
                                    </div>
                                </div>

                                <!-- Value & Specs -->
                                <div class="col-12 col-md-6">
                                    <div class="aircon-view-card">
                                        <h6><i class="fas fa-coins me-1"></i>Value & Specs</h6>
                                        <div class="av-row"><label>Purchase Price</label><b id="view_purchase_price">-</b></div>
                                        <div class="av-row"><label>Depreciated Value</label><b id="view_depreciated_value">-</b></div>
                                        <div class="av-row"><label>Energy Efficiency</label><b id="view_energy_efficiency_rating">-</b></div>
                                        <div class="av-row"><label>Power Consumption (kW)</label><b id="view_power_consumption">-</b></div>
                                        <div class="av-row"><label>Lifespan</label><b id="view_lifespan">-</b></div>
                                    </div>
                                </div>

                                <!-- Pictures -->
                                <div class="col-12 col-md-6">
                                    <div class="aircon-view-card">
                                        <h6><i class="fas fa-images me-1"></i>Pictures</h6>
                                        <div id="airconPicturesGallery" class="d-flex flex-wrap gap-2">
                                            <!-- Images will be loaded dynamically via AJAX -->
                                        </div>
                                    </div>
                                </div>

                                <!-- QR Code (image streams from the qr_code blob column) -->
                                <div class="col-12 col-md-6">
                                    <div class="aircon-view-card">
                                        <h6><i class="fas fa-qrcode me-1"></i>QR Code</h6>
                                        <div id="viewQrWrap" class="text-center">
                                            <div id="viewQrSpinner" class="spinner-border text-success spinner-border-sm" role="status" style="display:none;">
                                                <span class="visually-hidden">Loading QR...</span>
                                            </div>
                                            <img id="viewQrImage" src="" alt="Aircon QR Code" class="aircon-view-qrimg" style="display:none;">
                                            <div id="viewQrEmpty" class="text-muted small">No QR code stored for this unit yet.</div>
                                            <button type="button" class="btn btn-sm btn-outline-success mt-2" id="viewQrRegenBtn">
                                                <i class="fas fa-rotate me-1"></i>Generate / Refresh
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Notes + record meta -->
                                <div class="col-12">
                                    <div class="aircon-view-card">
                                        <h6><i class="fas fa-sticky-note me-1"></i>Notes</h6>
                                        <p class="mb-0" id="view_notes" style="font-size:0.85rem;">-</p>
                                        <div class="aircon-view-meta">
                                            <i class="fas fa-database me-1"></i>Created by <b id="view_created_by">-</b> &middot; <span id="view_date_created">-</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" onclick="printAirconDetails()">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Movement Modal -->
            <div class="modal fade" id="stockMovementModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Stock Movement</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="stockMovementForm" action="../actions/property_stock_movement.php" method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="inventory_id" id="movement_inventory_id">
                                <input type="hidden" name="movement_type" id="movement_type">

                                <div class="mb-3">
                                    <label class="form-label">Item</label>
                                    <input type="text" id="movement_item_name" class="form-control" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Movement Type</label>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-success flex-fill movement-btn" id="stockInBtn" onclick="setMovementType('IN')">
                                            <i class="fas fa-plus"></i> Stock In
                                        </button>
                                        <button type="button" class="btn btn-warning flex-fill movement-btn" id="stockOutBtn" onclick="setMovementType('OUT')">
                                            <i class="fas fa-minus"></i> Stock Out
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Quantity</label>
                                    <input type="text" name="quantity" class="form-control" required min="1">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="3"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Record Movement</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Hidden form for Add to Inventory from Received row -->
            <form id="addInventoryHiddenForm" action="../actions/add_property.php" method="POST" style="display:none;">
                <input type="hidden" name="procurement_id" id="ai_procurement_id">
                <input type="hidden" name="item_name" id="ai_item_name">
                <input type="hidden" name="category" id="ai_category">
                <input type="hidden" name="current_stock" id="ai_current_stock">
                <input type="hidden" name="unit" id="ai_unit">
                <input type="hidden" name="reorder_level" id="ai_reorder_level" value="0">
                <input type="hidden" name="supplier_id" id="ai_supplier_id">
                <input type="hidden" name="unit_cost" id="ai_unit_cost">
                <input type="hidden" name="location" id="ai_location" value="Warehouse">
                <input type="hidden" name="description" id="ai_description">
                <input type="hidden" name="status" id="ai_status">
                <input type="hidden" name="receiver" id="ai_receiver" value="Property Custodian">
            </form>

            <!-- Edit Inventory Modal -->
            <div class="modal fade" id="editInventoryModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Property Item</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="editInventoryForm" action="../actions/edit_property.php" method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="inventory_id" id="ei_inventory_id">

                                <!-- Basic Information -->
                                <div class="mb-3 pb-2 border-bottom">
                                    <h6 class="mb-3 text-uppercase text-muted">Basic Information</h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Item Name</label>
                                            <input type="text" class="form-control" name="item_name" id="ei_item_name" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Category</label>
                                            <select id="ei_category" name="category" class="form-select">
                                                <option value="">Select Category</option>
                                                <?php
                                                // Use the same organized categories from the main page
                                                if (isset($organized_categories) && !empty($organized_categories)) {
                                                    foreach ($organized_categories as $main_category => $subcategories) {
                                                        echo '<optgroup label="' . htmlspecialchars($main_category) . '">';
                                                        foreach ($subcategories as $subcategory) {
                                                            echo '<option value="' . htmlspecialchars($subcategory) . '">' . htmlspecialchars($subcategory) . '</option>';
                                                        }
                                                        echo '</optgroup>';
                                                    }
                                                } else {
                                                    // Fallback options if no data available - display as bold headers only
                                                    echo '<optgroup label="Property and Equipment"></optgroup>';
                                                    echo '<optgroup label="Intangible Assets"></optgroup>';
                                                    echo '<optgroup label="Office Supplies"></optgroup>';
                                                    echo '<optgroup label="Medical Supplies"></optgroup>';
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row g-3 mt-2">
                                        <div class="col-3">
                                            <label class="form-label">Brand</label>
                                            <input class="form-control" name="brand" id="ei_brand">
                                        </div>

                                        <div class="col-3">
                                            <label class="form-label">Color</label>
                                            <input class="form-control" name="color" id="ei_color">
                                            <small class="text-muted" style="font-size: 12px;">leave black if not applicable</small>
                                        </div>

                                        <div class="col-3">
                                            <label class="form-label">Size</label>
                                            <input class="form-control" name="size" id="ei_size">
                                            <small class="text-muted" style="font-size: 12px;">leave black if not applicable</small>
                                        </div>

                                        <div class="col-3">
                                            <label class="form-label">Type</label>
                                            <input class="form-control" name="type" id="ei_type">
                                            <small class="text-muted" style="font-size: 12px;">leave black if not applicable</small>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control" name="description" id="ei_description" rows="2" placeholder="Optional description..."></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Stock & Unit -->
                                <div class="mb-3 pb-2 border-bottom">
                                    <h6 class="mb-3 text-uppercase text-muted">Stock & Unit</h6>
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label">Current Stock</label>
                                            <input type="number" class="form-control" name="current_stock" id="ei_current_stock" min="0" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Reorder Level</label>
                                            <input type="number" class="form-control" name="reorder_level" id="ei_reorder_level" min="0">
                                            <small style="color: gray; font-size: 12px;">Leave blank if not applicable</small>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Unit</label>
                                            <select name="unit" id="ei_unit" class="form-select" required>
                                                <option value="">--Select Unit--</option>
                                                <option value="pc">Piece</option>
                                                <option value="pcs">Pieces</option>
                                                <option value="box">Box</option>
                                                <option value="boxes">Boxes</option>
                                                <option value="kg">Kilogram</option>
                                                <option value="kgs">Kilograms</option>
                                                <option value="liter">Liter</option>
                                                <option value="liters">Liters</option>
                                                <option value="set">Set</option>
                                                <option value="sets">Sets</option>
                                                <option value="pack">Pack</option>
                                                <option value="packs">Packs</option>
                                                <option value="ream">Ream</option>
                                                <option value="reams">Reams</option>
                                                <option value="gal">Gallon</option>
                                                <option value="gals">Gallons</option>
                                                <option value="bag">Bag</option>
                                                <option value="bags">Bags</option>
                                                <option value="none">Others</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Unit Cost</label>
                                            <input type="number" step="0.01" class="form-control" name="unit_cost" id="ei_unit_cost" required>
                                        </div>
                                    </div>
                                </div>

                                <!-- Supplier & Location -->
                                <div class="mb-3 pb-2 border-bottom">
                                    <h6 class="mb-3 text-uppercase text-muted">Supplier & Location</h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Supplier</label>
                                            <select class="form-select" name="supplier_id" id="ei_supplier_id" required>
                                                <option value="">Select Supplier</option>
                                                <?php
                                                if ($suppliers_result) {
                                                    $suppliers_result->data_seek(0);
                                                    while ($supplier = $suppliers_result->fetch_assoc()):
                                                ?>
                                                        <option value="<?= $supplier['supplier_id'] ?>"><?= htmlspecialchars($supplier['supplier_name']) ?></option>
                                                <?php endwhile;
                                                } ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Location</label>
                                            <input type="text" class="form-control" name="location" id="ei_location">
                                        </div>
                                    </div>
                                </div>

                                <!-- Status & Meta -->
                                <div class="mb-2">
                                    <h6 class="mb-3 text-uppercase text-muted">Status & Meta</h6>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Status</label>
                                            <select class="form-select" name="status" id="ei_status">
                                                <option value="Active">Active</option>
                                                <option value="Inactive">Inactive</option>
                                                <option value="Discontinued">Discontinued</option>
                                            </select>
                                        </div>
                                        <input type="hidden" class="form-control" name="quantity" id="ei_quantity" min="0">
                                        <div class="col-md-4">
                                            <label class="form-label">Receiver</label>
                                            <input type="text" class="form-control" name="receiver" id="ei_receiver" placeholder="e.g., Property Custodian">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Received Notes</label>
                                            <textarea type="text" class="form-control" name="received_notes" id="ei_received_notes" placeholder="Optional notes"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Maintenance Records Modal -->
            <div class="modal fade" id="maintenanceRecordsModal" tabindex="-1">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white;">
                            <h5 class="modal-title">
                                <i class="fas fa-tools me-2"></i>Maintenance Records
                                <small class="d-block mt-1" style="font-size: 0.85rem;" id="maintenance-aircon-info"></small>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0">Maintenance History</h6>
                                <div class="d-flex gap-2">
                                    <a id="exportMaintCsv" class="btn btn-outline-primary btn-sm" href="#" target="_blank">
                                        <i class="fas fa-file-csv"></i> Export CSV
                                    </a>
                                    <?php if ($can_manage_aircon): ?>
                                        <button type="button" class="btn btn-success btn-sm" id="addMaintenanceBtn">
                                            <i class="fas fa-plus"></i> Add Maintenance Record
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Maintenance Records Table -->
                            <div class="table-responsive">
                                <table class="table table-hover table-striped" id="maintenanceTable">
                                    <thead class="table-info">
                                        <tr>
                                            <th>Service Date</th>
                                            <th>Service Type</th>
                                            <th>Technician</th>
                                            <th>Next Scheduled</th>
                                            <th>Remarks</th>
                                            <th>Created By</th>
                                            <?php if ($can_manage_aircon): ?><th>Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody id="maintenanceTableBody">
                                        <tr>
                                            <td colspan="<?= $can_manage_aircon ? 7 : 6 ?>" class="text-center">
                                                <div class="spinner-border text-info" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div id="noMaintenanceMessage" class="text-center py-4" style="display: none;">
                                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                <h5>No Maintenance Records</h5>
                                <p class="text-muted">No maintenance records found for this aircon unit.</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add/Edit Maintenance Record Modal -->
            <div class="modal fade" id="maintenanceFormModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header" style="background-color: var(--primary-green);">
                            <h5 class="modal-title text-white" id="maintenanceFormTitle">
                                <i class="fas fa-wrench me-2"></i>Add Maintenance Record
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="maintenanceForm">
                            <input type="hidden" id="maint_maintenance_id" name="maintenance_id">
                            <input type="hidden" id="maint_aircon_id" name="aircon_id">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Service Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="maint_service_date" name="service_date" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Service Type <span class="text-danger">*</span></label>
                                        <select class="form-select" id="maint_service_type" name="service_type" required>
                                            <option value="">-- Select Service Type --</option>
                                            <option value="Cleaning">Cleaning</option>
                                            <option value="Repair">Repair</option>
                                            <option value="Preventive Maintenance">Preventive Maintenance</option>
                                            <option value="Filter Replacement">Filter Replacement</option>
                                            <option value="Gas Refill">Gas Refill</option>
                                            <option value="Inspection">Inspection</option>
                                            <option value="Others">Others</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Technician</label>
                                        <input type="text" class="form-control" id="maint_technician" name="technician" placeholder="Name or company">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Next Scheduled Date</label>
                                        <input type="date" class="form-control" id="maint_next_scheduled_date" name="next_scheduled_date">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Remarks</label>
                                        <textarea class="form-control" id="maint_remarks" name="remarks" rows="3" placeholder="Notes or issues found..."></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Record
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- View Maintenance Detail Modal -->
            <div class="modal fade" id="viewMaintenanceModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header" style="background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%); color: white;">
                            <h5 class="modal-title">
                                <i class="fas fa-eye me-2"></i>Maintenance Record Details
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Service Date</label>
                                    <p class="form-control-plaintext" id="view_service_date"></p>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Service Type</label>
                                    <p class="form-control-plaintext" id="view_service_type"></p>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Technician</label>
                                    <p class="form-control-plaintext" id="view_technician"></p>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Next Scheduled Date</label>
                                    <p class="form-control-plaintext" id="view_next_scheduled_date"></p>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">Remarks</label>
                                    <p class="form-control-plaintext" id="view_remarks"></p>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Created By</label>
                                    <p class="form-control-plaintext" id="view_created_by"></p>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Date Created</label>
                                    <p class="form-control-plaintext" id="view_date_created"></p>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>


            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

            <script>window.PAGE_CFG = { canManageAircon: <?= $can_manage_aircon ? 'true' : 'false' ?>, sessionMessage: '<?= addslashes($session_message) ?>', sessionError: '<?= addslashes($session_error) ?>' };</script>
<script src="../assets/js/aircon_list.js"></script>
        <?php endif; ?>

        <!--- Another aircon lost pagination -->
        <?php
        if (!$isAjax) {
            include '../includes/footer.php';
        } else {
            // For AJAX requests, output only the table content
            // Re-execute pagination logic for AJAX
            $records_per_page = 10;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $offset = ($page - 1) * $records_per_page;

            // Re-process search and filters for AJAX
            $search_term_ajax = trim($_GET['search'] ?? '');
            $sy_inv_raw_ajax = $_GET['sy_inv'] ?? '';
            $campus_raw_ajax = strtoupper(trim($_GET['campus'] ?? ''));
$status_raw_ajax = trim($_GET['status'] ?? '');

            // Rebuild WHERE conditions for AJAX
            $inv_where_conditions_ajax = [];
            // Removed receiver filter - not applicable for aircon management
            // $inv_where_conditions_ajax[] = "pi.receiver = 'Property Custodian'";

            if (!empty($search_term_ajax)) {
                $search_escaped_ajax = $conn->real_escape_string($search_term_ajax);
                $inv_where_conditions_ajax[] = "(pi.brand LIKE '%$search_escaped_ajax%' OR pi.model LIKE '%$search_escaped_ajax%' OR pi.type LIKE '%$search_escaped_ajax%' OR pi.serial_number LIKE '%$search_escaped_ajax%' OR pi.location LIKE '%$search_escaped_ajax%')";
            }

            list($sy_inv_start_ajax, $sy_inv_end_ajax) = parse_school_year_range($sy_inv_raw_ajax);
            if ($sy_inv_start_ajax && $sy_inv_end_ajax) {
                $start_esc_ajax = $conn->real_escape_string($sy_inv_start_ajax);
                $end_esc_ajax = $conn->real_escape_string($sy_inv_end_ajax);
                $inv_where_conditions_ajax[] = "pi.date_created >= '$start_esc_ajax' AND pi.date_created <= '$end_esc_ajax'";
            }

            // Campus filter (BED/TED) for AJAX
            if ($campus_raw_ajax === 'BED' || $campus_raw_ajax === 'TED') {
                $campus_esc_ajax = $conn->real_escape_string($campus_raw_ajax);
                $inv_where_conditions_ajax[] = "TRIM(UPPER(pi.campus)) = '$campus_esc_ajax'";
            }
            // Status filter for AJAX (case-insensitive match)
            $allowed_statuses_ajax = ['Working', 'Replace', 'Needs Repair', 'Needs Attention', 'Under Maintenance', 'Decommissioned', 'Not Applicable'];
            if ($status_raw_ajax !== '' && in_array($status_raw_ajax, $allowed_statuses_ajax, true)) {
                $status_esc_ajax = $conn->real_escape_string($status_raw_ajax);
                $inv_where_conditions_ajax[] = "LOWER(TRIM(pi.status)) = LOWER('$status_esc_ajax')";
            }

            $inv_where_ajax = !empty($inv_where_conditions_ajax) ? ' WHERE ' . implode(' AND ', $inv_where_conditions_ajax) : '';

            // Get total number of records
            $count_sql = "SELECT COUNT(*) as total FROM aircons pi $inv_where_ajax";
            $count_result = $conn->query($count_sql);
            $total_records = $count_result->fetch_assoc()['total'];
            $total_pages = ceil($total_records / $records_per_page);

            // Get inventory data with pagination
            // Same "most recently touched first" order as the static table
            // so AJAX page loads / searches stay consistent.
            $sql = "SELECT pi.*, s.supplier_name 
                    FROM aircons pi 
                    LEFT JOIN supplier s ON pi.supplier_id = s.supplier_id 
                    $inv_where_ajax
                    ORDER BY COALESCE(pi.date_updated, pi.date_created) DESC, pi.installation_date DESC, pi.date_created DESC
                    LIMIT $records_per_page OFFSET $offset";
            $result = $conn->query($sql);

            // Output only the table and pagination
            echo '<div id="inventoryTable">';
            echo '<table class="table table-hover mb-0">';
            echo '<thead class="table-dark">';
            echo '<tr>';
            echo '<th>Campus</th>';
            echo '<th>Brand</th>';
            echo '<th>Model</th>';
            echo '<th>Type</th>';
            echo '<th>Horse Power</th>';
            echo '<th>Serial No.</th>';
            echo '<th>Location</th>';
            echo '<th>Status</th>';
            echo '<th>Maintenance Schedule</th>';
            echo '<th>Installation Date/ Release Date</th>';
            echo '<th>Notes</th>';
            if ($can_manage_aircon) echo '<th>QR Code</th>';
            if ($can_manage_aircon) echo '<th>Actions</th>';
            echo '</tr>';
            echo '</thead><tbody>';

            if ($result && $result->num_rows > 0) {
                $item_counter = ($page - 1) * $records_per_page + 1;
                while ($row = $result->fetch_assoc()) {
                    $status_class = match (strtolower(trim($row['status'] ?? ''))) {
                        'working' => 'success',
                        'needs repair', 'needs attention', 'not applicable', 'n/a' => 'warning text-dark',
                        'under maintenance' => 'info',
                        'decommissioned', 'replace' => 'danger',
                        default => 'danger'
                    };

                    echo '<tr>';
                    echo '<td data-label="Campus">' . htmlspecialchars($row['campus'] ?? 'N/A') . '</td>';
                    echo '<td data-label="Brand">' . htmlspecialchars($row['brand'] ?? 'N/A') . '</td>';
                    echo '<td data-label="Model">' . htmlspecialchars($row['model'] ?? 'N/A') . '</td>';
                    echo '<td data-label="Type">' . htmlspecialchars($row['type'] ?? 'N/A') . '</td>';
                    echo '<td data-label="Horse Power">' . htmlspecialchars($row['horse_power'] ?? 'N/A') . '</td>';
                    echo '<td data-label="Serial No.">' . htmlspecialchars($row['serial_number'] ?? 'N/A') . '</td>';
                    echo '<td data-label="Location">' . htmlspecialchars($row['location'] ?? 'N/A') . '</td>';
                    echo '<td data-label="Status"><span class="badge bg-' . $status_class . '">' . htmlspecialchars($row['status'] ?? 'N/A') . '</span></td>';

                    echo '<td data-label="Maintenance Schedule">
                            <button class="btn btn-sm btn-outline-primary view-maintenance-btn" 
                                data-aircon-id="' . (int)$row['aircon_id'] . '"
                                data-brand="' . htmlspecialchars($row['brand'] ?? '', ENT_QUOTES) . '"
                                data-model="' . htmlspecialchars($row['model'] ?? '', ENT_QUOTES) . '"
                                data-serial="' . htmlspecialchars($row['serial_number'] ?? '', ENT_QUOTES) . '"
                                title="View Maintenance Records">
                                <i class="fas fa-calendar-alt"></i> View Records
                            </button>
                        </td>';

                    echo '<td data-label="Installation Date/ Release Date">' . (!empty($row['installation_date']) ? date('M d, Y', strtotime($row['installation_date'])) : 'N/A') . '</td>';
                    echo '<td data-label="Notes">' . htmlspecialchars($row['notes'] ?? 'N/A') . '</td>';
                    if ($can_manage_aircon) {
                        echo '<td data-label="QR Code" class="text-center">';
                        echo '<button type="button" class="btn btn-outline-info btn-sm btn-print-single-qr" onclick="printSingleAirconQr(' . (int)$row['aircon_id'] . ', this)" title="Print QR Code" data-aircon-id="' . (int)$row['aircon_id'] . '" data-item-number="' . htmlspecialchars($row['item_number'] ?? '', ENT_QUOTES) . '" data-serial-number="' . htmlspecialchars($row['serial_number'] ?? '', ENT_QUOTES) . '" data-brand="' . htmlspecialchars($row['brand'] ?? '', ENT_QUOTES) . '" data-model="' . htmlspecialchars($row['model'] ?? '', ENT_QUOTES) . '" data-location="' . htmlspecialchars($row['location'] ?? '', ENT_QUOTES) . '" data-campus="' . htmlspecialchars($row['campus'] ?? '', ENT_QUOTES) . '"><i class="fas fa-qrcode me-1"></i> QR</button>';
                        echo '<div id="qr-display-' . (int)$row['aircon_id'] . '" class="qr-print-container mt-2" style="display:none;"></div>';
                        echo '</td>';
                        echo '<td data-label="Actions" class="actions">';
                        echo '<button class="btn btn-sm btn-primary" title="View Details" onclick=\'viewAirconDetails('
                            . (int)$row['aircon_id'] . ', '
                            . htmlspecialchars(json_encode($row['item_number'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['brand'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['model'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['type'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['horse_power'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['serial_number'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['location'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['area'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['purchase_date'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['warranty_expiry'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['last_service_date'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['maintenance_schedule'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['installation_date'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['energy_efficiency_rating'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['power_consumption'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['purchase_price'] ?? '0'), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['depreciated_value'] ?? '0'), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['lifespan'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['receiver'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['created_by'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['date_created'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['picture'] ?? ''), ENT_QUOTES, 'UTF-8')
                            . ')\'><i class="fas fa-eye"></i></button> ';
                        echo '<button class="btn btn-sm btn-info" title="Edit" onclick=\'openEditAirconModal('
                            . (int)$row['aircon_id'] . ', '
                            . htmlspecialchars(json_encode($row['item_number'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['category'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['brand'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['model'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['type'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['horse_power'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['serial_number'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['location'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['area'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['campus'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['purchase_date'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['warranty_expiry'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['last_service_date'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['maintenance_schedule'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . (int)($row['supplier_id'] ?? 0) . ', '
                            . htmlspecialchars(json_encode($row['installation_date'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['energy_efficiency_rating'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['power_consumption'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['purchase_price'] ?? '0'), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['depreciated_value'] ?? '0'), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['lifespan'] ?? ''), ENT_QUOTES, 'UTF-8') . ', '
                            . htmlspecialchars(json_encode($row['picture'] ?? ''), ENT_QUOTES, 'UTF-8')
                            . ')\'><i class="fas fa-edit"></i></button> ';

                        echo '<button class="btn btn-sm btn-danger" title="Delete" onclick=\'deleteAircon(' . (int)$row['aircon_id'] . ', ' . htmlspecialchars(json_encode($row['brand'] ?? ''), ENT_QUOTES, 'UTF-8') . ')\'><i class="fas fa-trash"></i></button></td>';
                    } // end if can_manage_aircon
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="13" class="text-center py-4"><i class="fas fa-snowflake fa-3x text-muted mb-3"></i><p class="text-muted">No aircon units found</p></td></tr>';
            }

            echo '</tbody></table></div>';

            // Output pagination
            if ($total_pages > 1) {
                $neighborRange = 2;
                $start = max(1, (int)$page - $neighborRange);
                $end = min((int)$total_pages, (int)$page + $neighborRange);

                echo '<nav><ul class="pagination justify-content-center mt-3" id="paginationContainer">';
                echo '<li class="page-item ' . (($page <= 1) ? 'disabled' : '') . '">';
                echo '<a class="page-link" href="#" onclick="loadInventory(' . max(1, $page - 1) . '); return false;">&laquo;</a></li>';

                // First page
                echo '<li class="page-item ' . ((1 === (int)$page) ? 'active' : '') . '">';
                echo '<a class="page-link" href="#" onclick="loadInventory(1); return false;">1</a></li>';

                // Leading ellipsis
                if ($start > 2) {
                    echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                }

                // Middle pages
                for ($i = $start; $i <= $end; $i++) {
                    if ($i === 1 || $i === (int)$total_pages) continue;
                    echo '<li class="page-item ' . (($i === (int)$page) ? 'active' : '') . '">';
                    echo '<a class="page-link" href="#" onclick="loadInventory(' . $i . '); return false;">' . $i . '</a></li>';
                }

                // Trailing ellipsis
                if ($end < ((int)$total_pages - 1)) {
                    echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                }

                // Last page
                if ($total_pages > 1) {
                    echo '<li class="page-item ' . (((int)$total_pages === (int)$page) ? 'active' : '') . '">';
                    echo '<a class="page-link" href="#" onclick="loadInventory(' . (int)$total_pages . '); return false;">' . (int)$total_pages . '</a></li>';
                }

                // Next
                echo '<li class="page-item ' . (($page >= $total_pages) ? 'disabled' : '') . '">';
                echo '<a class="page-link" href="#" onclick="loadInventory(' . min((int)$total_pages, $page + 1) . '); return false;">&raquo;</a></li>';
                echo '</ul></nav>';
            } else {
                echo '<ul class="pagination justify-content-center mt-3 d-none" id="paginationContainer"></ul>';
            }
            exit;
        }
        ?>
        

        <!-- Edit Aircon Modal -->
        <div class="modal fade" id="editAirconModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable aircon-edit-dialog">
                <div class="modal-content aircon-edit-modal">
                    <div class="modal-header aircon-edit-header">
                        <div class="d-flex align-items-center gap-3">
                            <span class="aircon-edit-icon"><i class="fas fa-snowflake"></i></span>
                            <div>
                                <h5 class="modal-title text-white mb-0">Edit Aircon Details</h5>
                                <small class="aircon-edit-subtitle">Update unit information &amp; inventory record</small>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="../actions/edit_aircon.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="aircon_id" id="edit_aircon_id">
                        <div class="modal-body aircon-edit-body">
                            <!-- Basic Information -->
                            <div class="aircon-edit-section">
                                <h6 class="aircon-edit-section-title"><i class="fas fa-circle-info me-2"></i>Basic Information</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Item Number</label>
                                        <input type="text" name="item_name" id="edit_item_name" class="form-control" placeholder="e.g., AC-001">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Category</label>
                                        <select id="edit_category" name="category" class="form-select">
                                            <option value="">Select Category</option>
                                            <?php
                                            // Use the same organized categories from the main page
                                            if (isset($organized_categories) && !empty($organized_categories)) {
                                                foreach ($organized_categories as $main_category => $subcategories) {
                                                    echo '<optgroup label="' . htmlspecialchars($main_category) . '">';
                                                    foreach ($subcategories as $subcategory) {
                                                        echo '<option value="' . htmlspecialchars($subcategory) . '">' . htmlspecialchars($subcategory) . '</option>';
                                                    }
                                                    echo '</optgroup>';
                                                }
                                            } else {
                                                // Fallback options if no data available - display as bold headers only
                                                echo '<optgroup label="Property and Equipment"></optgroup>';
                                                echo '<optgroup label="Intangible Assets"></optgroup>';
                                                echo '<optgroup label="Office Supplies"></optgroup>';
                                                echo '<optgroup label="Medical Supplies"></optgroup>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Brand <span class="text-danger">*</span></label>
                                        <input type="text" name="brand" id="edit_brand" class="form-control" required placeholder="e.g., Carrier, Daikin">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Model <span class="text-danger">*</span></label>
                                        <input type="text" name="model" id="edit_model" class="form-control" required placeholder="e.g., 42QHC018">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Type</label>
                                        <select name="type" id="edit_type" class="form-select">
                                            <option value="">Select Type</option>
                                            <option value="Split">Split</option>
                                            <option value="Window">Window</option>
                                            <option value="Portable">Portable</option>
                                            <option value="Cassette">Cassette</option>
                                            <option value="Central">Central</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Horse Power</label>
                                        <input type="text" name="horse_power" id="edit_horse_power" class="form-control" placeholder="e.g., 1.5 HP">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Serial Number</label>
                                        <input type="text" name="serial_number" id="edit_serial_number" class="form-control" placeholder="Manufacturer serial number">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Location</label>
                                        <input type="text" name="location" id="edit_location" class="form-control" placeholder="e.g., Room 101, Office">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Area</label>
                                        <input type="text" name="area" id="edit_area" class="form-control" placeholder="e.g., Ground Floor, Annex">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" id="edit_status" class="form-select">
                                            <option value="Working">Working</option>
                                            <option value="Replace">Replace</option>
                                            <option value="Needs Repair">Needs Repair</option>
                                            <option value="Needs Attention">Needs Attention</option>
                                            <option value="Under Maintenance">Under Maintenance</option>
                                            <option value="Decommissioned">Decommissioned</option>
                                            <option value="Not Applicable">Not Applicable</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Campus</label>
                                        <select name="campus" id="edit_campus" class="form-select">
                                            <option value="">Select Campus</option>
                                            <option value="TED">TED</option>
                                            <option value="BED">BED</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Purchase Date</label>
                                        <input type="date" name="purchase_date" id="edit_purchase_date" class="form-control">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Warranty Expiry</label>
                                        <input type="date" name="warranty_expiry" id="edit_warranty_expiry" class="form-control">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Last Service Date</label>
                                        <input type="date" name="last_service" id="edit_last_service" class="form-control">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Maintenance Schedule</label>
                                        <input type="date" name="maintenance_schedule" id="edit_maintenance_schedule" class="form-control">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Supplier</label>
                                        <select name="supplier_id" id="edit_supplier_id" class="form-select">
                                            <option value="">Select Supplier</option>
                                            <?php
                                            // Reset the result pointer to reuse the suppliers data
                                            if ($suppliers_result && $suppliers_result->num_rows > 0) {
                                                $suppliers_result->data_seek(0);
                                                while ($supplier = $suppliers_result->fetch_assoc()) {
                                                    echo '<option value="' . $supplier['supplier_id'] . '">' . htmlspecialchars($supplier['supplier_name']) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Installation Date/ Release Date</label>
                                        <input type="date" name="installation_date" id="edit_installation_date" class="form-control">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Energy Efficiency Rating</label>
                                        <input type="text" name="energy_efficient" id="edit_energy_efficient" class="form-control" placeholder="e.g., 5-star, A++">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Power Consumption (kW)</label>
                                        <input type="number" step="0.1" name="power_consumption" id="edit_power_consumption" class="form-control" placeholder="e.g., 1.5">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" id="edit_notes" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Purchase Price (₱)</label>
                                        <input type="number" step="0.01" name="purchase_price" id="edit_purchase_price" class="form-control" placeholder="0.00">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Depreciated Value (₱)</label>
                                        <input type="number" step="0.01" name="depreciated_value" id="edit_depreciated_value" class="form-control" placeholder="0.00">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Lifespan</label>
                                        <input type="text" name="lifespan" id="edit_lifespan" class="form-control" placeholder="e.g., 10 years">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Update/Add Pictures (Multiple)</label>
                                        <input type="file" name="pictures[]" id="aircon_picture_edit" class="form-control" accept="image/*" multiple>
                                        <div id="existing_pictures_container" class="mt-2" style="display:none;">
                                            <label class="small text-muted">Existing Pictures:</label>
                                            <div id="existing_pictures_gallery" class="d-flex flex-wrap gap-2"></div>
                                        </div>
                                        <div id="picture_preview_container_edit" class="mt-2" style="display:none;">
                                            <label class="small text-success">New Preview:</label>
                                            <div id="picture_previews_edit" class="d-flex flex-wrap gap-2"></div>
                                            <button type="button" class="btn btn-sm btn-danger mt-1 d-block" onclick="clearImagePreview('edit')">
                                                <i class="fas fa-times"></i> Clear New
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer aircon-edit-footer">
                            <button type="button" class="btn aircon-btn-ghost" data-bs-dismiss="modal"><i class="fas fa-xmark me-1"></i>Cancel</button>
                            <button type="submit" class="btn aircon-btn-save"><i class="fas fa-save me-1"></i>Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        