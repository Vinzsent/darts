<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user']) && !isset($_SESSION['user_id'])) {
    $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isJson = (stripos($accept, 'application/json') !== false)
        || (stripos($script, '/actions/') !== false)
        || (stripos($script, '/api/') !== false)
        || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

    if ($isJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
        }
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please log in again.']);
        exit;
    }

    // Dynamically find the path to index.php at the root
    $rootPath = (strpos($script, '/pages/') !== false) ? '../index.php' : 'index.php';
    header("Location: $rootPath");
    exit;
}
