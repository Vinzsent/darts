<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Only Admin / Superadmin accounts may verify for user management
$raw_role = $_SESSION['user_type'] ?? ($_SESSION['user']['user_type'] ?? '');
$role = str_replace([' ', '-'], '', strtolower($raw_role));
if (!in_array($role, ['admin', 'superadmin'], true)) {
    $_SESSION['error'] = 'Access denied. Only administrators can verify this password.';
    header("Location: ../dashboard.php");
    exit;
}

// No password submitted - redirect back
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['admin_password'])) {
    header("Location: ../dashboard.php");
    exit;
}

// CSRF check (token is rendered inside the dashboard password prompt)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// CSRF check (token is rendered inside the dashboard password prompt; AJAX
// callers may send it via the X-CSRF-Token header instead).
$sent_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($sent_token) || $sent_token === '' ||
    !hash_equals($_SESSION['csrf_token'], $sent_token)) {
    $_SESSION['error'] = 'Security validation failed. Please try again.';
    header("Location: ../dashboard.php");
    exit;
}

// Verify the submitted password against the LOGGED-IN ADMIN'S real password
// hash in the `user` table. (This previously compared against a hardcoded
// string, which anyone with code access could read from version control.)
$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
$entered_password = (string)($_POST['admin_password'] ?? '');

$ok = false;
if ($user_id > 0 && $entered_password !== '') {
    $stmt = $conn->prepare("SELECT password FROM `user` WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && !empty($row['password'])) {
            $ok = password_verify($entered_password, $row['password']);
        }
    }
}

// Small random delay on failure to slow brute-force attempts
if (!$ok) {
    usleep(random_int(200000, 500000));
    $_SESSION['error'] = 'Incorrect admin password. Access denied.';
    header("Location: ../dashboard.php");
    exit;
}

// Set admin session flag and continue to the settings page
$_SESSION['admin_verified'] = true;
$_SESSION['admin_verified_time'] = time();
header("Location: ../pages/users.php");
exit;
