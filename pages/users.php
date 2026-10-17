<?php
// --- TEMPORARY: surface PHP errors on-screen so a 500 shows its cause -----
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ob_start();

function _users_page_render_error($title, $message, $file = '', $line = 0)
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $f = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');
    echo '<div style="margin:20px;padding:16px;border:2px solid #dc3545;background:#fff5f5;color:#212529;border-radius:8px;font-family:monospace;white-space:pre-wrap;">';
    echo '<strong style="color:#dc3545;">' . $t . '</strong>' . "\n\n" . $m;
    if ($f !== '') {
        echo "\n\nat " . $f . ':' . (int) $line;
    }
    echo '</div>';
}

// Catch uncaught exceptions before PHP turns them into a blank 500.
set_exception_handler(function ($e) {
    _users_page_render_error(
        'Unhandled ' . get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    exit;
});

// Catch fatal errors (including parse/compile) that reach end of request.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (ob_get_level()) {
            ob_end_flush();
        }
        _users_page_render_error('PHP Fatal Error', $err['message'], $err['file'], $err['line']);
    }
});
// --------------------------------------------------------------------------

$pageTitle = 'User Management';
include '../includes/auth.php';

// Check if admin is verified (password was entered correctly)
if (!isset($_SESSION['admin_verified']) || $_SESSION['admin_verified'] !== true) {
    $_SESSION['error'] = 'Admin access required. Please enter the admin password.';
    header("Location: ../dashboard.php");
    exit;
}

// Check if admin session is still valid (30 minutes timeout)
if (isset($_SESSION['admin_verified_time']) && (time() - $_SESSION['admin_verified_time']) > 1800) {
    unset($_SESSION['admin_verified']);
    unset($_SESSION['admin_verified_time']);
    $_SESSION['error'] = 'Admin session expired. Please re-enter the password.';
    header("Location: ../dashboard.php");
    exit;
}

include '../includes/db.php';
include '../includes/header.php';

// Count different user types for dashboard cards
$total_users = $conn->query("SELECT COUNT(*) as count FROM user")->fetch_assoc()['count'];
$admin_users = $conn->query("SELECT COUNT(*) as count FROM user WHERE user_type = 'Admin'")->fetch_assoc()['count'];
$regular_users = $conn->query("SELECT COUNT(*) as count FROM user WHERE user_type = 'User'")->fetch_assoc()['count'];
$active_users = $conn->query("SELECT COUNT(*) as count FROM user WHERE user_type IN ('Admin', 'User')")->fetch_assoc()['count'];

// Display session messages
if (isset($_SESSION['message'])) {
    echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['message']) . '</div>';
    unset($_SESSION['message']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>

<?php include('../includes/navbar.php'); ?>

<style>
    /* Main color scheme matching the image */
    :root {
        --primary-green: #1a5f3c;
        --secondary-green: #2d7a4d;
        --light-green: #4a9c6b;
        --accent-orange: #fd7e14;
        --accent-red: #dc3545;
        --accent-blue: #0d6efd;
        --text-dark: #212529;
        --text-light: #6c757d;
        --bg-light: #ffffff;
        --border-light: #dee2e6;
    }

    /* Header Banner */
    .header-banner {
        background: linear-gradient(135deg, var(--primary-green), var(--secondary-green));
        color: white;
        padding: 2rem 0;
        margin-bottom: 2rem;
        border-radius: 0 0 15px 15px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .header-banner h1 {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .header-banner p {
        font-size: 1.1rem;
        opacity: 0.9;
        margin-bottom: 0;
    }

    /* Information Cards */
    .info-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .info-card {
        background: var(--bg-light);
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        border: 1px solid var(--border-light);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .info-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
    }

    .info-card .icon {
        font-size: 2.5rem;
        margin-bottom: 1rem;
    }

    .info-card .number {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .info-card .label {
        font-size: 1rem;
        color: var(--text-light);
        font-weight: 500;
    }

    /* Card colors */
    .card-total {
        color: var(--primary-green);
    }

    .card-admin {
        color: var(--accent-orange);
    }

    .card-user {
        color: var(--accent-red);
    }

    .card-active {
        color: var(--accent-blue);
    }

    /* Section Header */
    .section-header {
        background: var(--primary-green);
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .section-header h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 600;
    }

    /* Table Styling */
    .table-container {
        background: var(--bg-light);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        border: 1px solid var(--border-light);
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: var(--primary-green);
        color: white;
        border: none;
        padding: 1rem;
        font-weight: 600;
        font-size: 0.95rem;
    }

    .table tbody td {
        padding: 1rem;
        vertical-align: middle;
        border-bottom: 1px solid var(--border-light);
    }

    .table tbody tr:hover {
        background-color: rgba(26, 95, 60, 0.05);
    }

    /* Buttons */
    .btn-primary {
        background: var(--accent-orange);
        border-color: var(--accent-orange);
        color: white;
        font-weight: 500;
        padding: 0.5rem 1.5rem;
        border-radius: 8px;
        transition: all 0.2s ease;
    }

    .btn-primary:hover {
        background: #e8690b;
        border-color: #e8690b;
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: var(--text-light);
        border-color: var(--text-light);
        color: white;
        font-weight: 500;
        padding: 0.5rem 1.5rem;
        border-radius: 8px;
        transition: all 0.2s ease;
    }

    .btn-secondary:hover {
        background: #5a6268;
        border-color: #5a6268;
        color: white;
    }

    .btn-warning {
        background: var(--accent-orange);
        border-color: var(--accent-orange);
        color: white;
        font-size: 0.8rem;
        padding: 0.4rem 0.8rem;
        border-radius: 6px;
        margin-right: 0.5rem;
    }

    .btn-warning:hover {
        background: #e8690b;
        border-color: #e8690b;
        color: white;
    }

    .btn-danger {
        background: var(--accent-red);
        border-color: var(--accent-red);
        color: white;
        font-size: 0.8rem;
        padding: 0.4rem 0.8rem;
        border-radius: 6px;
    }

    .btn-danger:hover {
        background: #c82333;
        border-color: #c82333;
        color: white;
    }

    /* Modal Styling */
    .modal-custom {
        display: none;
        position: fixed;
        z-index: 1050;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.5);
    }

    .modal-content-custom {
        background-color: var(--bg-light);
        margin: 5% auto;
        padding: 2rem;
        border-radius: 12px;
        max-width: 500px;
        color: var(--text-dark);
        border: 1px solid var(--border-light);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .info-cards {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .header-banner h1 {
            font-size: 2rem;
        }

        .section-header {
            flex-direction: column;
            gap: 1rem;
            text-align: center;
        }
    }

    @media (max-width: 576px) {
        .info-cards {
            grid-template-columns: 1fr;
        }

        .header-banner {
            padding: 1.5rem 0;
        }

        .header-banner h1 {
            font-size: 1.8rem;
        }
    }

    /* Offset the main content so the fixed sidebar (280px) doesn't overlap it */
    .content-wrap {
        margin-left: 280px;
        padding-top: 10px;
    }
</style>

<!-- Admin Sidebar (loads the role-based sidebar with all admin modules + Printer Header Settings) -->
<?php include '../includes/sidebar.php'; ?>

<div class="content-wrap">
    <div class="container-fluid" style="margin-top: 100px;">
        <!-- Header Banner -->
        <div class="header-banner">
            <div class="container">
                <h1>User Management</h1>
                <p>Manage system users, roles, and permissions</p>
                <a href="../dashboard.php"><button class="btn btn-primary mt-3"><i class="fas fa-arrow-left me-2"></i> Previous Page</button></a>
            </div>
        </div>

        <div class="container">
            <!-- Information Cards -->
            <div class="info-cards">
                <div class="info-card">
                    <div class="icon card-total">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="number card-total"><?= $total_users ?></div>
                    <div class="label">Total Users</div>
                </div>

                <div class="info-card">
                    <div class="icon card-admin">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="number card-admin"><?= $admin_users ?></div>
                    <div class="label">Administrators</div>
                </div>

                <div class="info-card">
                    <div class="icon card-user">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="number card-user"><?= $regular_users ?></div>
                    <div class="label">Regular Users</div>
                </div>

                <div class="info-card">
                    <div class="icon card-active">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="number card-active"><?= $active_users ?></div>
                    <div class="label">Active Users</div>
                </div>
            </div>

            <!-- User Records Section -->
            <div class="section-header">
                <h2><i class="fas fa-list me-2"></i>User Records</h2>
                <button class="btn btn-primary" onclick="document.getElementById('addUser').style.display='block'">
                    <i class="fas fa-plus me-2"></i>New User
                </button>
            </div>

            <!-- Search users (live, AJAX) -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <div class="input-group" style="max-width: 380px; flex: 1 1 260px;">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" id="userSearch" class="form-control" placeholder="Search by name or position..." autocomplete="off">
                </div>
                <span id="userCount" class="text-muted small ms-auto"></span>
            </div>

            <!-- User Table -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Position</th>
                                <th>Username</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    <i class="fas fa-spinner fa-spin me-2"></i>Loading users...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div id="usersPagination" class="mt-3"></div>
            </div>
        </div>
    </div>
</div><!-- /.content-wrap -->

<!-- Add User Modal -->
<div id="addUser" class="modal-custom">
    <div class="modal-content-custom shadow">
        <div class="d-flex justify-content-between mb-3">
            <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
            <button class="btn-close" onclick="document.getElementById('addUser').style.display='none'"></button>
        </div>
        <?php include '../modals/add_user.php'; ?>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUser" class="modal-custom">
    <div class="modal-content-custom shadow">
        <div class="d-flex justify-content-between mb-3">
            <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit User</h5>
            <button class="btn-close" onclick="document.getElementById('editUser').style.display='none'"></button>
        </div>
        <?php include '../modals/edit_user.php'; ?>
    </div>
</div>

<!-- Delete User Modal -->
<div id="deleteUser" class="modal-custom">
    <div class="modal-content-custom shadow">
        <div class="d-flex justify-content-between mb-3">
            <h5 class="mb-0"><i class="fas fa-user-times me-2"></i>Delete User</h5>
            <button class="btn-close" onclick="document.getElementById('deleteUser').style.display='none'"></button>
        </div>
        <?php include '../modals/delete_user.php'; ?>
    </div>
</div>

<style>
    /* AJAX loading state: dim the current content instead of wiping it, so
   changing pages doesn't blank the table and look like a full page reload. */
    #usersTableBody.is-loading,
    #usersPagination.is-loading {
        opacity: 0.4;
        transition: opacity 0.15s ease;
        pointer-events: none;
    }
</style>
<script>
    // Close modals when clicking outside
    window.onclick = function(event) {
        if (event.target.className === 'modal-custom') {
            event.target.style.display = 'none';
        }
    }

    function openEditModal(id, title, firstName, middleName, lastName, suffix, academicTitle, userType, username) {
        document.getElementById('edit-id').value = id;
        document.getElementById('edit-title').value = title;
        document.getElementById('edit-firstname').value = firstName;
        document.getElementById('edit-middlename').value = middleName || '';
        document.getElementById('edit-lastname').value = lastName;
        document.getElementById('edit-suffix').value = suffix || '';
        document.getElementById('edit-academictitle').value = academicTitle || '';
        document.getElementById('edit-usertype').value = userType;
        document.getElementById('edit-username').value = username;

        // Show the edit modal
        document.getElementById('editUser').style.display = 'block';
    }

    function openDeleteModal(id) {
        document.getElementById('delete-id').value = id;
        document.getElementById('deleteUser').style.display = 'block';
    }

    // Close modals with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal-custom').forEach(modal => {
                modal.style.display = 'none';
            });
        }
    });

    // --- Users table: AJAX pagination + live search ---
    function loadUsers(page, search, opts) {
        page = page || 1;
        search = search || '';
        opts = opts || {};
        var tbody = document.getElementById('usersTableBody');
        var pag = document.getElementById('usersPagination');
        if (!tbody) return;

        // On page navigation keep the current rows visible (just dimmed) so the
        // table doesn't blank out and look like a full page reload. On first load
        // and on search we swap in the spinner, since the content changes entirely.
        if (opts.pageNav) {
            tbody.classList.add('is-loading');
            if (pag) pag.classList.add('is-loading');
        } else {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Loading users...</td></tr>';
        }

        var url = '../api/users_search.php?page=' + encodeURIComponent(page) + '&search=' + encodeURIComponent(search);

        function done() {
            tbody.classList.remove('is-loading');
            if (pag) pag.classList.remove('is-loading');
        }

        fetch(url, {
                credentials: 'same-origin'
            })
            .then(function(res) {
                return res.json();
            })
            .then(function(data) {
                done();
                if (data.error) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + data.error + '</td></tr>';
                    if (pag) pag.innerHTML = '';
                    return;
                }
                tbody.innerHTML = data.rows || '';
                if (pag) pag.innerHTML = data.pagination || '';
                var count = document.getElementById('userCount');
                if (count) count.textContent = (data.total || 0) + ' user(s) found';
            })
            .catch(function() {
                done();
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Failed to load users. Please try again.</td></tr>';
                if (pag) pag.innerHTML = '';
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var searchInput = document.getElementById('userSearch');
        var pagination = document.getElementById('usersPagination');

        // Initial load (page 1)
        loadUsers(1, '');

        // Live search (debounced)
        if (searchInput) {
            var timer;
            searchInput.addEventListener('input', function() {
                clearTimeout(timer);
                var value = this.value.trim();
                timer = setTimeout(function() {
                    loadUsers(1, value);
                }, 300);
            });
        }

        // Pagination clicks (event delegation — links are injected dynamically)
        if (pagination) {
            pagination.addEventListener('click', function(e) {
                if (!e.target || !e.target.closest) return;
                var link = e.target.closest('.ajax-pagination');
                if (!link) return;
                e.preventDefault();
                var page = parseInt(link.getAttribute('data-page'), 10);
                if (!isNaN(page)) loadUsers(page, searchInput ? searchInput.value.trim() : '', {
                    pageNav: true
                });
            });
        }
    });
</script>

<?php include('../includes/footer.php'); ?>

</html>