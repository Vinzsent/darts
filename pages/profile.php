<?php
$pageTitle = 'My Profile';
include '../includes/auth.php';
include '../includes/db.php';
include '../includes/header.php';

$user_id    = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;
$user_type  = $_SESSION['user_type'] ?? $_SESSION['user']['user_type'] ?? '';

if (!$user_id) {
    header('Location: ../index.php');
    exit;
}

// Fetch fresh user data.
//
// Login (index.php) reads from the `user` table/view, but this page previously
// queried `employees`. When those two are not perfectly in sync — e.g. the deployed
// database keeps `user` as a separate table with its own IDs — the lookup returns
// nothing and the guard below bounced the user to the login page even though they
// were logged in correctly. Read from `user` first (same source as login) and fall
// back to `employees` so a profile always resolves.
$fetch_user = function ($conn, $user_id) {
    foreach (['user', 'employees'] as $table) {
        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
        if (!$stmt) {
            continue; // table missing or query failed - try the next one
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
    }
    return null;
};

$user = $user_id ? $fetch_user($conn, $user_id) : null;

// Fall back to the session copy so a transient lookup miss never logs the user out.
if (!$user && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $user = $_SESSION['user'];
} elseif (!$user) {
    $user = [
        'username' => $_SESSION['username'] ?? '',
        'user_type' => $_SESSION['user_type'] ?? '',
    ];
}

// Personal details (name, title, department, role) are read-only here and are
// maintained by an administrator in pages/users.php. This page only lets a user
// change their own username and password.

// Flash messages
$success = $_SESSION['profile_success'] ?? null;
$error   = $_SESSION['profile_error']   ?? null;
unset($_SESSION['profile_success'], $_SESSION['profile_error']);
?>

<?php include('../includes/navbar.php'); ?>

<style>
    :root {
        --primary-green:  #073b1d;
        --dark-green:     #052915;
        --mid-green:      #0a4f28;
        --accent-gold:    #EACA26;
        --accent-blue:    #4a90e2;
        --accent-red:     #e74c3c;
        --text-white:     #ffffff;
        --text-dark:      #073b1d;
        --bg-light:       #f0f4f2;
        --card-shadow:    0 8px 32px rgba(7,59,29,.12);
        --card-radius:    16px;
        --transition:     all .3s cubic-bezier(.4,0,.2,1);
    }

    body {
        background: linear-gradient(135deg, var(--primary-green) 0%, #0e5c32 60%, #1a7a46 100%);
        min-height: 100vh;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    /* ── Page wrapper ── */
    .profile-wrapper {
        margin-top: 72px;
        padding: 2rem 1.5rem 4rem;
        max-width: 1100px;
        margin-left: auto;
        margin-right: auto;
    }

    /* ── Hero banner ── */
    .profile-hero {
        position: relative;
        background: rgba(255,255,255,.08);
        backdrop-filter: blur(14px);
        border: 1px solid rgba(255,255,255,.18);
        border-radius: var(--card-radius);
        padding: 2.5rem 2rem 2rem;
        color: var(--text-white);
        margin-bottom: 2rem;
        overflow: hidden;
        animation: fadeSlideDown .5s ease both;
    }

    .profile-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(234,202,38,.15) 0%, transparent 60%);
        pointer-events: none;
    }

    .avatar-ring {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--accent-gold), #f5b942);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.8rem;
        color: var(--primary-green);
        box-shadow: 0 6px 24px rgba(234,202,38,.4);
        flex-shrink: 0;
        transition: var(--transition);
    }

    .avatar-ring:hover { transform: scale(1.06) rotate(3deg); }

    .hero-name { font-size: 1.75rem; font-weight: 700; line-height: 1.2; }
    .hero-role {
        display: inline-block;
        background: rgba(234,202,38,.2);
        border: 1px solid rgba(234,202,38,.4);
        color: var(--accent-gold);
        padding: .2rem .85rem;
        border-radius: 100px;
        font-size: .85rem;
        font-weight: 600;
        letter-spacing: .5px;
        margin-top: .4rem;
    }
    .hero-dept { opacity: .75; font-size: .92rem; margin-top: .3rem; }

    /* ── Section cards ── */
    .profile-card {
        background: rgba(255,255,255,.95);
        border-radius: var(--card-radius);
        box-shadow: var(--card-shadow);
        border: 1px solid rgba(7,59,29,.08);
        overflow: hidden;
        margin-bottom: 1.75rem;
        animation: fadeSlideUp .5s ease both;
    }

    .profile-card:nth-child(2) { animation-delay: .08s; }
    .profile-card:nth-child(3) { animation-delay: .16s; }
    .profile-card:nth-child(4) { animation-delay: .24s; }

    .card-head {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--mid-green) 100%);
        color: var(--text-white);
        padding: 1rem 1.5rem;
        display: flex;
        align-items: center;
        gap: .75rem;
        font-size: 1rem;
        font-weight: 600;
    }

    .card-head i { font-size: 1.1rem; opacity: .85; }

    .card-body-p { padding: 1.75rem 1.75rem 1.5rem; }

    /* ── Form controls ── */
    .form-label {
        font-weight: 600;
        font-size: .85rem;
        color: var(--text-dark);
        margin-bottom: .35rem;
    }

    .form-label i { color: var(--mid-green); width: 16px; margin-right: 4px; }

    .form-control, .form-select {
        border: 1.5px solid #d1dbd5;
        border-radius: 10px;
        padding: .65rem 1rem;
        font-size: .93rem;
        transition: var(--transition);
        background: #fafcfb;
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--mid-green);
        box-shadow: 0 0 0 3px rgba(10,79,40,.15);
        background: #fff;
    }

    .form-control::placeholder { color: #9fb4aa; }

    .input-group-text {
        background: #f0f7f3;
        border: 1.5px solid #d1dbd5;
        border-radius: 10px 0 0 10px;
        color: var(--mid-green);
    }

    .input-group .form-control {
        border-left: none;
        border-radius: 0 10px 10px 0;
    }

    .input-group:focus-within .input-group-text {
        border-color: var(--mid-green);
        background: #e4f2eb;
    }

    /* Password strength */
    .pw-strength-bar {
        height: 4px;
        border-radius: 2px;
        background: #e9ecef;
        margin-top: 6px;
        overflow: hidden;
    }

    .pw-strength-fill {
        height: 100%;
        width: 0;
        border-radius: 2px;
        transition: width .4s ease, background-color .4s ease;
    }

    .pw-hint { font-size: .78rem; color: #6c757d; margin-top: 4px; }

    /* ── Buttons ── */
    .btn-save {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--mid-green) 100%);
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: .75rem 2rem;
        font-weight: 600;
        font-size: .95rem;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .btn-save::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(255,255,255,.1) 0%, transparent 100%);
        opacity: 0;
        transition: opacity .3s;
    }

    .btn-save:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(7,59,29,.35); }
    .btn-save:hover::after { opacity: 1; }
    .btn-save:active { transform: translateY(0); }

    .btn-cancel {
        background: transparent;
        color: #6c757d;
        border: 1.5px solid #dee2e6;
        border-radius: 10px;
        padding: .75rem 1.5rem;
        font-weight: 500;
        transition: var(--transition);
    }

    .btn-cancel:hover { background: #f8f9fa; border-color: #adb5bd; color: #495057; }

    /* ── Alert toasts ── */
    .profile-alert {
        border-radius: 12px;
        padding: 1rem 1.25rem;
        display: flex;
        align-items: center;
        gap: .75rem;
        font-size: .93rem;
        font-weight: 500;
        margin-bottom: 1.5rem;
        animation: fadeSlideDown .4s ease;
    }

    .alert-success-custom {
        background: #d4edda;
        border-left: 4px solid #28a745;
        color: #155724;
    }

    .alert-error-custom {
        background: #f8d7da;
        border-left: 4px solid #dc3545;
        color: #721c24;
    }

    /* ── Breadcrumb ── */
    .profile-breadcrumb {
        display: flex;
        align-items: center;
        gap: .5rem;
        color: rgba(255,255,255,.7);
        font-size: .88rem;
        margin-bottom: 1.5rem;
        animation: fadeSlideDown .4s ease;
    }

    .profile-breadcrumb a { color: rgba(255,255,255,.85); text-decoration: none; transition: color .2s; }
    .profile-breadcrumb a:hover { color: var(--accent-gold); }
    .profile-breadcrumb .sep { opacity: .5; }

    /* ── Tab nav ── */
    .profile-tabs {
        display: flex;
        gap: .5rem;
        margin-bottom: 1.75rem;
        background: rgba(255,255,255,.1);
        border-radius: 12px;
        padding: .35rem;
        animation: fadeSlideDown .45s ease;
    }

    .profile-tab {
        flex: 1;
        padding: .6rem 1rem;
        text-align: center;
        border-radius: 9px;
        color: rgba(255,255,255,.7);
        cursor: pointer;
        font-size: .88rem;
        font-weight: 500;
        transition: var(--transition);
        border: none;
        background: transparent;
        user-select: none;
    }

    .profile-tab.active {
        background: rgba(255,255,255,.95);
        color: var(--primary-green);
        font-weight: 700;
        box-shadow: 0 2px 8px rgba(0,0,0,.12);
    }

    .profile-tab:hover:not(.active) { background: rgba(255,255,255,.15); color: #fff; }

    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    /* ── Animations ── */
    @keyframes fadeSlideDown {
        from { opacity: 0; transform: translateY(-16px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    @keyframes fadeSlideUp {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* ── Responsive ── */
    @media (max-width: 768px) {
        .profile-wrapper { padding: 1rem 1rem 3rem; }
        .hero-name { font-size: 1.35rem; }
        .card-body-p { padding: 1.25rem; }
        .profile-tabs { flex-direction: column; }
    }
</style>

<div class="profile-wrapper" style="margin-top: 72px;">

    <!-- Breadcrumb -->
    <div class="profile-breadcrumb">
        <a href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a>
        <span class="sep">›</span>
        <span>My Profile</span>
    </div>

    <!-- Hero Card -->
    <div class="profile-hero">
        <div class="d-flex align-items-center gap-4 flex-wrap">
            <div class="avatar-ring">
                <i class="fas fa-user-circle"></i>
            </div>
            <div>
                <div class="hero-name">
                    <?= htmlspecialchars(trim(($user['title'] ?? '') . ' ' . ($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? '') . ' ' . ($user['suffix'] ?? ''))) ?>
                </div>
                <div class="hero-role">
                    <i class="fas fa-id-badge me-1"></i><?= htmlspecialchars($user['user_type'] ?? 'User') ?>
                </div>
                <div class="hero-dept">
                    <i class="fas fa-building me-1"></i><?= htmlspecialchars($user['department'] ?? '—') ?>
                    &nbsp;|&nbsp;
                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($user['username'] ?? '') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($success): ?>
        <div class="profile-alert alert-success-custom">
            <i class="fas fa-check-circle fa-lg"></i>
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="profile-alert alert-error-custom">
            <i class="fas fa-exclamation-circle fa-lg"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <div class="profile-tabs">
        <!-- Single section: shown as a static heading rather than a clickable tab,
             since there is nothing to switch to. -->
        <div class="profile-tab active">
            <i class="fas fa-lock me-2"></i>Account &amp; Security
        </div>
    </div>

    <!-- ═══════════════════════════ ACCOUNT TAB ══════════════════════════ -->
    <div id="tab-account" class="tab-pane active">
        <!-- Always rendered on load: .tab-pane defaults to display:none, and this
             section previously stayed invisible unless the URL had ?tab=account. -->
        <form action="../actions/update_profile.php" method="POST" id="form-account">
            <input type="hidden" name="tab" value="account">

            <!-- Username -->
            <div class="profile-card">
                <div class="card-head">
                    <i class="fas fa-at"></i> Login Username
                </div>
                <div class="card-body-p">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label"><i class="fas fa-user"></i>Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-at"></i></span>
                                <input class="form-control" type="text" name="username"
                                    value="<?= htmlspecialchars($user['username'] ?? '') ?>"
                                    placeholder="username@example.com" required
                                    autocomplete="username">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Password -->
            <div class="profile-card">
                <div class="card-head">
                    <i class="fas fa-lock"></i> Change Password
                </div>
                <div class="card-body-p">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-lock"></i>Current Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-key"></i></span>
                                <input class="form-control" type="password" name="current_password"
                                    id="current_password" placeholder="Enter current password"
                                    autocomplete="current-password">
                                <button type="button" class="btn btn-outline-secondary border-start-0"
                                    style="border:1.5px solid #d1dbd5; border-left:none; border-radius:0 10px 10px 0;"
                                    onclick="togglePw('current_password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-lock"></i>New Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-unlock"></i></span>
                                <input class="form-control" type="password" name="new_password"
                                    id="new_password" placeholder="New password"
                                    autocomplete="new-password"
                                    oninput="checkStrength(this.value)">
                                <button type="button" class="btn btn-outline-secondary border-start-0"
                                    style="border:1.5px solid #d1dbd5; border-left:none; border-radius:0 10px 10px 0;"
                                    onclick="togglePw('new_password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="pw-strength-bar"><div class="pw-strength-fill" id="pw-fill"></div></div>
                            <div class="pw-hint" id="pw-hint">Leave blank to keep current password.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-lock"></i>Confirm New Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-check-circle"></i></span>
                                <input class="form-control" type="password" name="confirm_password"
                                    id="confirm_password" placeholder="Confirm new password"
                                    autocomplete="new-password"
                                    oninput="checkMatch()">
                                <button type="button" class="btn btn-outline-secondary border-start-0"
                                    style="border:1.5px solid #d1dbd5; border-left:none; border-radius:0 10px 10px 0;"
                                    onclick="togglePw('confirm_password', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="pw-hint" id="pw-match-hint"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="d-flex justify-content-end gap-3">
                <a href="../dashboard.php" class="btn btn-cancel"><i class="fas fa-times me-2"></i>Cancel</a>
                <button type="submit" class="btn btn-save"><i class="fas fa-shield-alt me-2"></i>Update Account</button>
            </div>
        </form>
    </div>

</div><!-- end .profile-wrapper -->

<script>
    /* ── Tab switching ── */
    /* ── Tab switching ──
       There is a single section now, so this is kept only so any older link that
       still passes ?tab=... cannot blank the page. It re-applies `active` to the
       one pane instead of removing it from every pane first. */
    function switchTab(name, btn) {
        const pane = document.getElementById('tab-' + name) || document.getElementById('tab-account');
        if (pane) pane.classList.add('active');
        if (btn) btn.classList.add('active');
    }

    /* ── Password visibility toggle ── */
    function togglePw(id, btn) {
        const input = document.getElementById(id);
        const icon  = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    /* ── Password strength ── */
    function checkStrength(val) {
        const fill = document.getElementById('pw-fill');
        const hint = document.getElementById('pw-hint');
        if (!val) {
            fill.style.width = '0'; fill.style.background = '';
            hint.textContent = 'Leave blank to keep current password.'; hint.style.color = '#6c757d';
            return;
        }
        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        const levels = [
            { pct: '25%', color: '#e74c3c', label: 'Weak' },
            { pct: '50%', color: '#f39c12', label: 'Fair' },
            { pct: '75%', color: '#3498db', label: 'Good' },
            { pct: '100%', color: '#27ae60', label: 'Strong' },
        ];
        const l = levels[score - 1] || levels[0];
        fill.style.width = l.pct;
        fill.style.background = l.color;
        hint.textContent = 'Strength: ' + l.label;
        hint.style.color = l.color;
        checkMatch();
    }

    /* ── Password match check ── */
    function checkMatch() {
        const pw  = document.getElementById('new_password').value;
        const cp  = document.getElementById('confirm_password').value;
        const hint = document.getElementById('pw-match-hint');
        if (!cp) { hint.textContent = ''; return; }
        if (pw === cp) {
            hint.textContent = '✓ Passwords match';
            hint.style.color = '#27ae60';
        } else {
            hint.textContent = '✗ Passwords do not match';
            hint.style.color = '#e74c3c';
        }
    }

    /* ── Client-side form validation ── */
    document.getElementById('form-account').addEventListener('submit', function(e) {
        const np  = document.getElementById('new_password').value;
        const cp  = document.getElementById('confirm_password').value;
        if (np && np !== cp) {
            e.preventDefault();
            alert('New password and confirmation do not match.');
        }
    });

    /* ── Auto-dismiss flash alert ── */
    const alerts = document.querySelectorAll('.profile-alert');
    alerts.forEach(a => {
        setTimeout(() => { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(() => a.remove(), 500); }, 4500);
    });

    /* ── Activate account tab if redirected to it ── */
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('tab') === 'account') {
        // The account pane is already active from the server-rendered markup; this
        // only re-asserts it so a stale ?tab= link can never leave it hidden.
        switchTab('account', document.querySelector('.profile-tab'));
    }
</script>

<?php include('../includes/footer.php'); ?>
