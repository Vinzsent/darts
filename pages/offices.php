<?php
$pageTitle = 'Office Management';
include '../includes/auth.php';

// Only admins can access
$user_type = $_SESSION['user_type'] ?? '';
if (strtolower($user_type) !== 'admin') {
    $_SESSION['error'] = 'Access denied. Only administrators can access this page.';
    header("Location: ../dashboard.php");
    exit;
}

include '../includes/db.php';

// Handle AJAX requests for pagination/search
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == 1;

if (!$isAjax) {
    include '../includes/header.php';
}

$records_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

// Handle search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_param = "%$search%";

// Count total records
$total_offices_count = $conn->query("SELECT COUNT(*) as count FROM offices")->fetch_assoc()['count'];

// Count for pagination (Filtered)
if (!empty($search)) {
    $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM offices WHERE office_name LIKE ?");
    $count_stmt->bind_param("s", $search_param);
    $count_stmt->execute();
    $total_filtered = $count_stmt->get_result()->fetch_assoc()['count'];
    $count_stmt->close();
} else {
    $total_filtered = $total_offices_count;
}

$total_pages = max(1, (int) ceil($total_filtered / $records_per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $records_per_page;

if (!empty($search)) {
    $stmt = $conn->prepare("SELECT * FROM offices WHERE office_name LIKE ? ORDER BY office_name ASC LIMIT ?, ?");
    $stmt->bind_param("sii", $search_param, $offset, $records_per_page);
} else {
    $stmt = $conn->prepare("SELECT * FROM offices ORDER BY office_name ASC LIMIT ?, ?");
    $stmt->bind_param("ii", $offset, $records_per_page);
}
$stmt->execute();
$result = $stmt->get_result();

// Prepare table rows for AJAX or initial load
ob_start();
while ($row = $result->fetch_assoc()):
    $statusClass = (strtolower($row['status'] ?? 'active') === 'active') ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle';
?>
    <tr>
        <td class="ps-4">
            <div class="fw-bold text-dark d-flex align-items-center">
                <i class="fas fa-building text-success me-2 opacity-75"></i>
                <?= htmlspecialchars($row['office_name']) ?>
            </div>
            <small class="text-muted ms-4">ID: #<?= (int)$row['id'] ?></small>
        </td>
        <td class="text-center">
            <span class="badge rounded-pill px-3 py-1 <?= $statusClass ?>">
                <?= htmlspecialchars($row['status'] ?? 'Active') ?>
            </span>
        </td>
        <td class="text-center text-muted small">
            <?= !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '—' ?>
        </td>
        <td class="text-center pe-4">
            <div class="d-flex justify-content-center align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary px-3 rounded-3" onclick="openEditModal(
                    <?= (int)$row['id'] ?>,
                    '<?= addslashes(htmlspecialchars($row['office_name'])) ?>',
                    '<?= addslashes(htmlspecialchars($row['status'] ?? 'Active')) ?>'
                ); return false;">
                    <i class="fas fa-edit me-1"></i>Edit
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger px-3 rounded-3" onclick="openDeleteModal(
                    <?= (int)$row['id'] ?>
                ); return false;">
                    <i class="fas fa-trash me-1"></i>Delete
                </button>
            </div>
        </td>
    </tr>
<?php
endwhile;

if ($result->num_rows === 0):
?>
    <tr>
        <td colspan="4" class="text-center py-5 text-muted">
            <i class="fas fa-building fa-2x mb-2 d-block opacity-50"></i>
            No offices found matching your criteria.
        </td>
    </tr>
<?php
endif;
$stmt->close();
$table_rows = ob_get_clean();

// Prepare minimal pagination (Previous / Current / Next) per project rules
ob_start();
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="text-muted small">
        Showing <?= min($total_filtered, $offset + 1) ?> to <?= min($total_filtered, $offset + $records_per_page) ?> of <?= $total_filtered ?> offices
    </div>
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
            <a class="page-link" href="#" onclick="loadOffices(<?= $page - 1 ?>); return false;">
                <i class="fas fa-chevron-left me-1"></i>Previous
            </a>
        </li>
        <li class="page-item active">
            <span class="page-link" style="background-color: var(--primary-green); border-color: var(--primary-green);">
                Page <?= $page ?> of <?= $total_pages ?>
            </span>
        </li>
        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
            <a class="page-link" href="#" onclick="loadOffices(<?= $page + 1 ?>); return false;">
                Next<i class="fas fa-chevron-right ms-1"></i>
            </a>
        </li>
    </ul>
</div>
<?php
$pagination_html = ob_get_clean();

if ($isAjax):
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'table_rows' => $table_rows,
        'pagination' => $pagination_html,
        'total' => $total_filtered
    ]);
    exit;
endif;
?>

    <style>
        :root {
            --primary-green: #073b1d;
            --dark-green: #0b5e2e;
            --accent-orange: #facc15;
            --bg-light: #f8fafc;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-light);
        }

        .main-content {
            margin-left: 280px;
            padding: 24px;
            min-height: 100vh;
        }

        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0;
                padding: 16px;
            }
        }

        .content-header {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: white;
            padding: 28px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 4px 15px rgba(7, 59, 29, 0.15);
        }

        .section-header {
            background: var(--primary-green);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .search-container {
            flex-grow: 1;
            max-width: 420px;
            margin: 0 1rem;
        }

        .search-container input {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: white;
            border-radius: 8px;
            padding: 8px 16px;
            width: 100%;
            transition: all 0.2s;
        }

        .search-container input::placeholder {
            color: rgba(255, 255, 255, 0.7);
        }

        .search-container input:focus {
            background: rgba(255, 255, 255, 0.25);
            outline: none;
            color: white;
            border-color: rgba(255, 255, 255, 0.5);
        }

        .table-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
            border: 1px solid #e2e8f0;
        }

        .table thead th {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 1rem;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .btn-theme-add {
            background: var(--accent-orange);
            color: #073b1d;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            padding: 8px 18px;
            transition: all 0.2s;
        }

        .btn-theme-add:hover {
            background: #eab308;
            color: #073b1d;
            transform: translateY(-1px);
        }

        /* Modal Styles */
        .modal-custom {
            display: none;
            position: fixed;
            z-index: 1050;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
            overflow: auto;
        }

        .modal-content-custom {
            background: white;
            margin: 6% auto;
            padding: 2rem;
            border-radius: 14px;
            max-width: 500px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
            animation: fadeInModal 0.2s ease-out;
        }

        @keyframes fadeInModal {
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>

    <!-- Sidebar -->
    <?php include '../sidebar/admin.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-header">
            <h1 class="h3 fw-bold mb-1"><i class="fas fa-building me-2"></i>Office Management</h1>
            <p class="mb-0 text-white-50">Manage school offices, departments, and destination units for purchase orders and inventory</p>
        </div>

        <div class="section-header">
            <h2 class="h5 mb-0 fw-semibold d-flex align-items-center">
                <i class="fas fa-list-ul me-2"></i>Offices List (<span id="offices-count"><?= (int)$total_offices_count ?></span>)
            </h2>
            <div class="search-container">
                <input type="text" id="search" placeholder="Search office name..." value="<?= htmlspecialchars($search) ?>" onkeyup="handleSearch()">
            </div>
            <button type="button" class="btn btn-theme-add shadow-sm" onclick="document.getElementById('addOfficeModal').style.display='block'">
                <i class="fas fa-plus me-1"></i>Add New Office
            </button>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Office Name</th>
                            <th class="text-center" style="width: 140px;">Status</th>
                            <th class="text-center" style="width: 160px;">Date Created</th>
                            <th class="text-center pe-4" style="width: 200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="offices-tbody">
                        <?= $table_rows ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 pt-3 border-top" id="offices-pagination">
                <?= $pagination_html ?>
            </div>
        </div>
    </div>

    <!-- Add Office Modal -->
    <div id="addOfficeModal" class="modal-custom">
        <div class="modal-content-custom">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-bold text-dark d-flex align-items-center">
                    <i class="fas fa-plus-circle me-2 text-success"></i>Add New Office
                </h5>
                <button type="button" class="btn-close" onclick="document.getElementById('addOfficeModal').style.display='none'"></button>
            </div>
            <?php include '../modals/add_office.php'; ?>
        </div>
    </div>

    <!-- Edit Office Modal -->
    <div id="editOfficeModal" class="modal-custom">
        <div class="modal-content-custom">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-bold text-dark d-flex align-items-center">
                    <i class="fas fa-edit me-2 text-primary"></i>Edit Office
                </h5>
                <button type="button" class="btn-close" onclick="document.getElementById('editOfficeModal').style.display='none'"></button>
            </div>
            <?php include '../modals/edit_office.php'; ?>
        </div>
    </div>

    <!-- Delete Office Modal -->
    <div id="deleteOfficeModal" class="modal-custom">
        <div class="modal-content-custom">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-bold text-danger d-flex align-items-center">
                    <i class="fas fa-trash-alt me-2"></i>Delete Office
                </h5>
                <button type="button" class="btn-close" onclick="document.getElementById('deleteOfficeModal').style.display='none'"></button>
            </div>
            <?php include '../modals/delete_office.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Session notifications
        <?php if(isset($_SESSION['success'])): ?>
            Swal.fire({ icon: 'success', title: 'Success!', text: <?= json_encode($_SESSION['success']) ?>, timer: 2500, showConfirmButton: false });
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
            Swal.fire({ icon: 'error', title: 'Error!', text: <?= json_encode($_SESSION['error']) ?> });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        // Close modal on click outside or escape key
        window.onclick = function(e) {
            if (e.target.classList.contains('modal-custom')) {
                e.target.style.display = 'none';
            }
        };

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-custom').forEach(m => m.style.display = 'none');
            }
        });

        function openEditModal(id, name, status) {
            document.getElementById('edit_office_id').value = id;
            document.getElementById('edit_office_name').value = name;
            document.getElementById('edit_office_status').value = status || 'Active';
            document.getElementById('editOfficeModal').style.display = 'block';
        }

        function openDeleteModal(id) {
            document.getElementById('delete_office_id').value = id;
            document.getElementById('deleteOfficeModal').style.display = 'block';
        }

        // Debounced Search and Pagination
        let searchTimeout;
        function handleSearch() {
            clearTimeout(searchTimeout);
            const val = document.getElementById('search').value;
            searchTimeout = setTimeout(() => loadOffices(1, val), 300);
        }

        function loadOffices(page = 1, searchTerm = null) {
            if (searchTerm === null) searchTerm = document.getElementById('search').value;
            const tbody = document.getElementById('offices-tbody');
            const pagination = document.getElementById('offices-pagination');

            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x mb-2 d-block"></i>Loading offices...</td></tr>';

            const url = new URL(window.location.href);
            url.searchParams.set('ajax', '1');
            url.searchParams.set('page', page);
            if (searchTerm) url.searchParams.set('search', searchTerm);
            else url.searchParams.delete('search');

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        tbody.innerHTML = data.table_rows;
                        pagination.innerHTML = data.pagination;
                        window.history.pushState({}, '', url);
                    }
                })
                .catch(err => {
                    console.error('Failed to load offices:', err);
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-danger">Error loading offices.</td></tr>';
                });
        }
    </script>

    <?php include('../includes/footer.php'); ?>
