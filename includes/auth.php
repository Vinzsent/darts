<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── CSRF token (issued once per session, checked on every POST) ──────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 1) Authentication: is anyone logged in?
$isLoggedIn = isset($_SESSION['user']) || isset($_SESSION['user_id']);

// 2) Context detection: actions/api endpoints expect JSON responses
$script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$isJson = (stripos($accept, 'application/json') !== false)
    || (stripos($script, '/actions/') !== false)
    || (stripos($script, '/api/') !== false)
    || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

if (!$isLoggedIn) {
    if ($isJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
        }
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please log in again.']);
        exit;
    }
    $rootPath = (strpos($script, '/pages/') !== false) ? '../index.php' : 'index.php';
    header("Location: $rootPath");
    exit;
}

// 3) CSRF protection: every state-changing POST must carry the session token —
//    either as a `csrf_token` form field or an `X-CSRF-Token` header.
//    assets/js/csrf.js attaches it automatically to every form and AJAX call.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf_token'], $sent)) {
        if ($isJson) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
            }
            echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token. Refresh the page and try again.']);
            exit;
        }
        $_SESSION['error'] = 'Your security token expired. Please reload the page and try again.';
        $back = $_SERVER['HTTP_REFERER'] ?? ((strpos($script, '/pages/') !== false) ? '../index.php' : 'index.php');
        header("Location: " . $back);
        exit;
    }
}
