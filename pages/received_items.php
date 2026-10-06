<?php

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Clear OPcache and stat cache to ensure we see the latest code snippet updates
clearstatcache();
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$pageTitle = 'Received Items';
include '../includes/auth.php';
include '../includes/db.php';
include '../includes/header.php';

$raw_user_type = $_SESSION['user_type'] ?? '';
$user_type = str_replace([' ', '-'], '', strtolower($raw_user_type));
$user_id = $_SESSION['user']['id'] ?? 0;

// Fetch purchase order records that are Approved or Received
// Specifically those created by Purchasing Officer for transparency
//
// NOTE: name columns differ between deployments (firstname vs first_name), so probe
// Query user table for names
$user_cols = [];
$name_res = $conn->query("SHOW COLUMNS FROM `user`");
while ($name_res && $nc = $name_res->fetch_assoc()) {
    $user_cols[] = $nc['Field'];
}
if (in_array('first_name', $user_cols, true) && in_array('last_name', $user_cols, true)) {
    $name_expr = "CONCAT_WS(' ', %s.first_name, %s.last_name)";
} elseif (in_array('firstname', $user_cols, true) && in_array('lastname', $user_cols, true)) {
    $name_expr = "CONCAT_WS(' ', %s.firstname, %s.lastname)";
} elseif (in_array('name', $user_cols, true)) {
    $name_expr = "%s.name";
} else {
    $name_expr = "%s.username";
}
$created_by_name = sprintf($name_expr, 'u_creator', 'u_creator');
$received_by_name = sprintf($name_expr, 'u_receiver', 'u_receiver');

// Probe columns in purchase_orders and purchase_order_items to avoid fatal schema mismatches
$po_cols = [];
$po_cols_res = $conn->query("SHOW COLUMNS FROM purchase_orders");
while ($po_cols_res && $c = $po_cols_res->fetch_assoc()) {
    $po_cols[] = $c['Field'];
}
$po_recv_col = in_array('received_date', $po_cols, true) ? 'p.received_date' : (in_array('date_received', $po_cols, true) ? 'p.date_received' : 'NULL');

$poi_cols = [];
$poi_cols_res = $conn->query("SHOW COLUMNS FROM purchase_order_items");
while ($poi_cols_res && $c = $poi_cols_res->fetch_assoc()) {
    $poi_cols[] = $c['Field'];
}
$poi_recv_col = in_array('received_date', $poi_cols, true) ? 'received_date' : (in_array('date_received', $poi_cols, true) ? 'date_received AS received_date' : 'NULL AS received_date');

$query = "
    SELECT 
        p.po_id,
        p.po_number,
        p.po_date,
        p.supplier_name,
        p.supplier_address,
        p.total_amount,
        p.status,
        $po_recv_col AS received_date,
        p.received_notes,
        MAX($created_by_name) as created_by_name,
        MAX($received_by_name) as received_by_name,
        COUNT(poi.poi_id) as item_count,
        SUM(poi.is_received) as received_item_count
    FROM purchase_orders p
    LEFT JOIN `user` u_creator ON p.created_by = u_creator.id
    LEFT JOIN `user` u_receiver ON CAST(p.received_by AS UNSIGNED) = u_receiver.id
    LEFT JOIN purchase_order_items poi ON p.po_id = poi.po_id
    GROUP BY p.po_id, p.po_number, p.po_date, p.supplier_name, p.supplier_address,
             p.total_amount, p.status, $po_recv_col, p.received_notes
    ORDER BY CASE 
        WHEN p.status = 'Approved' THEN 0 
        WHEN p.status = 'Pending' THEN 1 
        WHEN p.status = 'Draft' THEN 2 
        ELSE 3 
    END, p.created_at DESC
";

// ── Pagination: 20 purchase orders per page via ?page=N ──
$per_page = 5;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

// Count wraps the same SELECT/GROUP BY so the total always equals the rows the
// table would show without LIMIT (one grouped row per purchase order). The
// trailing ORDER BY is dropped because it adds nothing to a count.
$order_pos = strrpos($query, 'ORDER BY');
$base_query = ($order_pos !== false) ? substr($query, 0, $order_pos) : $query;
$count_result = $conn->query("SELECT COUNT(*) AS total FROM ($base_query) AS cnt");
$total_records = $count_result ? (int)($count_result->fetch_assoc()['total'] ?? 0) : 0;
$total_pages = max(1, (int)ceil($total_records / $per_page));
if ($page > $total_pages) {
    $page = $total_pages; // clamp (e.g. after rows are received/removed)
}
$offset = ($page - 1) * $per_page;

$query .= " LIMIT $per_page OFFSET $offset"; // integers only, safe to interpolate

$result = $conn->query($query);
if (!$result) {
    $query_error = $conn->error;
}

// Materialise the current page so the expandable sub-rows below only load
// line items for the purchase orders actually visible on this page.
$po_rows = [];
if ($result) {
    while ($r = $result->fetch_assoc()) {
        $po_rows[] = $r;
    }
}

// Line items per PO, for the expandable detail rows
$items_map = [];
$page_po_ids = array_map('intval', array_column($po_rows, 'po_id'));
if ($page_po_ids) {
    $ids_csv = implode(',', $page_po_ids);
    $items_result = $conn->query("
        SELECT poi_id, po_id, item_number, item_description, quantity, unit_cost, line_total, is_received, $poi_recv_col
        FROM purchase_order_items
        WHERE po_id IN ($ids_csv)
        ORDER BY po_id, item_number ASC
    ");
    if ($items_result) {
        while ($it = $items_result->fetch_assoc()) {
            $items_map[$it['po_id']][] = $it;
        }
    }
}
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
        /* Force dark text — dark-mode.js may set data-bs-theme="dark" (white text) while this page stays light */
        color: #212529;
        margin: 0;
        padding: 0;
    }

    .main-content {
        margin-left: 280px;
        padding: 20px;
        min-height: 100vh;
    }

    .content-header {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
        color: var(--text-white);
        padding: 30px;
        border-radius: 10px;
        margin-bottom: 30px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .list-container {
        background: var(--text-white);
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        overflow: hidden;
    }

    .list-header {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
        color: var(--text-white);
        padding: 20px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .po-table {
        width: 100%;
        border-collapse: collapse;
    }

    .po-table th {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
        color: var(--text-white);
        padding: 15px 12px;
        text-align: left;
    }

    .po-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #e9ecef;
        color: #212529;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-draft {
        background: linear-gradient(135deg, #6c757d, #5a6268);
        color: white;
    }

    .status-pending {
        background: linear-gradient(135deg, var(--accent-orange), #e8690b);
        color: white;
    }

    .status-approved {
        background: linear-gradient(135deg, var(--accent-green-approved), #1e7e34);
        color: white;
    }

    .status-received {
        background: linear-gradient(135deg, var(--accent-blue), #357abd);
        color: white;
    }

    .btn-received {
        background-color: var(--accent-orange);
        color: var(--text-dark);
        border: none;
        padding: 8px 15px;
        border-radius: 5px;
        font-weight: 600;
        cursor: pointer;
        transition: 0.3s;
    }

    .btn-received:hover {
        background-color: #d4b422;
        transform: translateY(-2px);
    }

    /* Sidebar Styles (matching purchase_order_list.php) */
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

    .sidebar-nav {
        padding: 20px 0;
    }

    .nav-link {
        display: flex;
        align-items: center;
        padding: 8px 15px;
        color: var(--text-white);
        text-decoration: none;
        transition: 0.3s;
        border-left: 4px solid transparent;
        font-size: 0.85rem;
    }

    .nav-link:hover {
        background: rgba(255, 255, 255, 0.1);
        border-left-color: var(--accent-orange);
    }

    .nav-link.active {
        background: rgba(255, 255, 255, 0.15);
        border-left-color: var(--accent-orange);
        font-weight: 600;
    }

    .nav-link i {
        margin-right: 12px;
        width: 20px;
        text-align: center;
    }

    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
        }

        .main-content {
            margin-left: 0;
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

<div class="main-content">
    <div class="content-header">
        <h1>Received Items Tracking</h1>
        <p>Manage and track the receipt of approved purchase orders</p>
    </div>

    <div class="list-container">
        <div class="list-header">
            <h2 style="margin: 0; font-size: 1.5rem;">Purchase Records</h2>
        </div>

        <table class="po-table">
            <thead>
                <tr>
                    <th>PO Number</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>Amount</th>
                    <th>Items Received</th>
                    <th>Created By</th>
                    <th>Status</th>
                    <th>Marked Received By</th>
                    <th>Received Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$result): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #c82333;">
                            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                            <p>Could not load purchase records.</p>
                            <small><?= htmlspecialchars($query_error ?? 'Unknown database error') ?></small>
                        </td>
                    </tr>
                <?php elseif (!empty($po_rows)): ?>
                    <?php foreach ($po_rows as $row): ?>
                        <?php
                        $row_items = $items_map[$row['po_id']] ?? [];
                        $total_items = (int)$row['item_count'];
                        $received_items = (int)($row['received_item_count'] ?? 0);
                        $all_received = $total_items > 0 && $received_items >= $total_items;
                        $has_outstanding = $received_items < $total_items;
                        ?>
                        <tr class="po-row" style="cursor: pointer;" onclick="togglePoItems(this, event)">
                            <td>
                                <strong><?= htmlspecialchars($row['po_number']) ?></strong>
                                <i class="fas fa-caret-down" style="opacity: 0.6; margin-left: 5px;" title="Click to view items"></i>
                            </td>
                            <td><?= date('M d, Y', strtotime($row['po_date'])) ?></td>
                            <td>
                                <div>
                                    <?= htmlspecialchars($row['supplier_name']) ?>
                                    <?php if (!empty($row['supplier_address'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($row['supplier_address']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>₱<?= number_format($row['total_amount'], 2) ?></td>
                            <td>
                                <span class="badge" style="font-size: 12px; padding: 4px 8px; border-radius: 4px; font-weight: bold;
                                    background-color: <?= $all_received ? 'var(--accent-green-approved)' : 'var(--accent-orange)' ?>; color: white;">
                                    <?= $received_items ?> / <?= $total_items ?>
                                </span>
                                <?php if ($has_outstanding && $total_items > 0): ?>
                                    <br><small class="text-muted"><?= $total_items - $received_items ?> outstanding</small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['created_by_name'] ?? '---') ?></td>
                            <td>
                                <?php if ($all_received || $row['status'] === 'Received'): ?>
                                    <span class="badge bg-success" style="background-color: var(--accent-green-approved); color: white; padding: 6px 12px; border-radius: 4px; font-weight: bold; font-size: 0.85rem;">
                                        <i class="fas fa-check-circle"></i> Fully Received
                                    </span>
                                <?php elseif ($received_items > 0): ?>
                                    <span class="badge bg-warning text-dark" style="padding: 6px 12px; border-radius: 4px; font-weight: bold; font-size: 0.85rem;">
                                        <i class="fas fa-clock"></i> Partially Received
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary" style="background-color: #6c757d; color: white; padding: 6px 12px; border-radius: 4px; font-weight: bold; font-size: 0.85rem;">
                                        <i class="fas fa-hourglass-half"></i> Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['received_by_name'] ?? '---') ?></td>
                            <td><?= $row['received_date'] ? date('M d, Y g:i A', strtotime($row['received_date'])) : '---' ?></td>
                        </tr>
                        <!-- Expandable per-item detail row -->
                        <tr class="po-items-row" style="display: none; background-color: #f8f9fa;">
                            <td colspan="9" style="padding: 14px 20px;">
                                <h5 style="font-size: 1rem; margin-bottom: 8px; color: var(--primary-green);">
                                    <i class="fas fa-box-open"></i> Received Item Details
                                </h5>

                                <?php if (!empty($row['received_notes'])): ?>
                                    <div style="background:#fff; border:1px solid #e9ecef; border-left:4px solid var(--primary-green);
                                                border-radius:4px; padding:8px 12px; margin-bottom:12px;">
                                        <strong style="font-size: 0.85rem;">Receiving Notes:</strong>
                                        <div style="font-size: 0.9rem; white-space: pre-wrap;"><?= htmlspecialchars($row['received_notes']) ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($row_items): ?>
                                    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem; background:#fff;">
                                        <thead>
                                            <tr>
                                                <th style="text-align: left; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 50px;">#</th>
                                                <th style="text-align: left; padding: 6px 10px; border-bottom: 2px solid var(--primary-green);">Item Description</th>
                                                <th style="text-align: right; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 90px;">Quantity</th>
                                                <th style="text-align: right; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 110px;">Unit Cost</th>
                                                <th style="text-align: right; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 120px;">Line Total</th>
                                                <th style="text-align: center; padding: 6px 10px; border-bottom: 2px solid var(--primary-green); width: 150px;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($row_items as $ritem): ?>
                                                <tr>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef;"><?= (int)$ritem['item_number'] ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef;"><?= htmlspecialchars($ritem['item_description']) ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef; text-align: right;"><?= htmlspecialchars($ritem['quantity']) ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef; text-align: right;">₱<?= number_format($ritem['unit_cost'], 2) ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef; text-align: right;">₱<?= number_format($ritem['line_total'], 2) ?></td>
                                                    <td style="padding: 6px 10px; border-bottom: 1px solid #e9ecef; text-align: center;">
                                                        <?php if ((int)$ritem['is_received'] === 1): ?>
                                                            <span class="badge bg-success"><i class="fas fa-check"></i> Received</span>
                                                            <?php if (!empty($ritem['received_date'])): ?>
                                                                <br><small class="text-muted"><?= date('M d, Y g:i A', strtotime($ritem['received_date'])) ?></small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary"><i class="fas fa-clock"></i> Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <em style="color: #6c757d;">No items recorded for this purchase order.</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #6c757d;">
                            <i class="fas fa-info-circle fa-2x mb-3"></i>
                            <p>No approved or received purchase orders found.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="po-pager">
                <div class="po-pager-info">
                    Showing <strong><?= $total_records > 0 ? $offset + 1 : 0 ?>&ndash;<?= min($offset + $per_page, $total_records) ?></strong>
                    of <strong><?= $total_records ?></strong> purchase orders
                </div>
                <nav aria-label="Received items pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="received_items.php?page=<?= max(1, $page - 1) ?>">&laquo; Prev</a>
                        </li>
                        <?php
                        // Window of 5 pages around the current one, with ellipses.
                        $window_start = max(1, $page - 2);
                        $window_end = min($total_pages, $page + 2);
                        if ($window_start > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="received_items.php?page=1">1</a>
                            </li>
                            <?php if ($window_start > 2): ?>
                                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $window_start; $i <= $window_end; $i++): ?>
                            <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                                <a class="page-link" href="received_items.php?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($window_end < $total_pages): ?>
                            <?php if ($window_end < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="received_items.php?page=<?= $total_pages ?>"><?= $total_pages ?></a>
                            </li>
                        <?php endif; ?>
                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="received_items.php?page=<?= min($total_pages, $page + 1) ?>">Next &raquo;</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Expand/collapse the per-item detail row when clicking a PO row
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

    function markAsReceived(poId, btn) {
        if (!confirm('Mark this purchase order and all of its items as received?')) return;

        // Remember the button's own label so a failed save restores the right text
        // (it is "Mark Received" or "Receive Remaining" depending on the state).
        const restoreLabel = btn ? btn.innerHTML : '';

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
                    po_id: poId,
                    // Keep the item flags in step with the PO status, otherwise this
                    // page would still report the PO as partially received.
                    mark_all_items: true
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = restoreLabel;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = restoreLabel;
                }
            });
    }
</script>

<?php include '../includes/footer.php'; ?>