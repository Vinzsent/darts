<?php
// Edit user action with visible error reporting (no more blank HTTP 500).
// When called via AJAX (the edit modal uses fetch), responds with JSON.
// On a normal form POST, renders a readable error page instead of dying blank.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/db.php';

error_reporting(E_ALL);

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

function _edit_user_fail($message, $detail = '', $isAjax = false)
{
    $full = $message . ($detail !== '' ? ' | ' . $detail : '');
    if ($isAjax) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => $full]);
        exit;
    }
    // Normal POST: show a readable error page (with back link) instead of a blank 500.
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Edit user failed</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#f8f9fa;padding:32px;color:#212529}'
        . '.box{max-width:760px;margin:0 auto;background:#fff;border:2px solid #dc3545;border-radius:10px;padding:24px}'
        . 'h1{color:#dc3545;font-size:20px;margin:0 0 12px}pre{background:#fff5f5;border:1px solid #f1aeb5;border-radius:6px;padding:12px;white-space:pre-wrap;word-break:break-word}'
        . 'a.btn{display:inline-block;margin-top:12px;background:#1a5f3c;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none}</style></head><body>'
        . '<div class="box"><h1>Failed to update user</h1>'
        . '<pre>' . htmlspecialchars($full, ENT_QUOTES, 'UTF-8') . '</pre>'
        . '<a class="btn" href="../pages/users.php">Back to User Management</a></div></body></html>';
    exit;
}

function _edit_user_ok($message, $isAjax = false)
{
    if ($isAjax) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => true, 'message' => $message]);
        exit;
    }
    echo "<script>alert('" . addslashes($message) . "'); window.location.href='../pages/users.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id              = intval($_POST['id'] ?? 0);
    $title           = trim($_POST['title'] ?? '');
    $first_name      = trim($_POST['first_name'] ?? '');
    $middle_name     = trim($_POST['middle_name'] ?? '');
    $last_name       = trim($_POST['last_name'] ?? '');
    $suffix          = trim($_POST['suffix'] ?? '');
    $academic_title  = trim($_POST['academic_title'] ?? '');
    $user_type       = trim($_POST['user_type'] ?? '');
    $department      = trim($_POST['department'] ?? '');
    $username        = trim($_POST['username'] ?? '');
    $password        = $_POST['password'] ?? '';

    if ($id <= 0) {
        _edit_user_fail('Invalid user ID.', 'Received id=' . var_export($_POST['id'] ?? null, true), $isAjax);
    }

    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql = "UPDATE `user` SET title=?, first_name=?, middle_name=?, last_name=?, suffix=?, academic_title=?, user_type=?, department=?, username=?, password=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            _edit_user_fail('Failed to update user: prepare failed.', 'MySQL #' . $conn->errno . ': ' . $conn->error . ' | SQL: ' . $sql, $isAjax);
        }
        $stmt->bind_param("ssssssssssi", $title, $first_name, $middle_name, $last_name, $suffix, $academic_title, $user_type, $department, $username, $hashed_password, $id);
    } else {
        $sql = "UPDATE `user` SET title=?, first_name=?, middle_name=?, last_name=?, suffix=?, academic_title=?, user_type=?, department=?, username=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            _edit_user_fail('Failed to update user: prepare failed.', 'MySQL #' . $conn->errno . ': ' . $conn->error . ' | SQL: ' . $sql, $isAjax);
        }
        $stmt->bind_param("sssssssssi", $title, $first_name, $middle_name, $last_name, $suffix, $academic_title, $user_type, $department, $username, $id);
    }

    if ($stmt->execute()) {
        if ($stmt->affected_rows === 0) {
            // No rows changed: either the id does not exist or every value was identical.
            // Treat as success but say so, instead of a confusing silent redirect.
            $check = $conn->query("SELECT id FROM `user` WHERE id=" . $id);
            if (!$check || $check->num_rows === 0) {
                _edit_user_fail('Failed to update user: user not found.', 'id=' . $id, $isAjax);
            }
        }
        _edit_user_ok("User updated successfully!", $isAjax);
    } else {
        _edit_user_fail('Failed to update user.', 'MySQL #' . $stmt->errno . ': ' . $stmt->error . ' | id=' . $id, $isAjax);
    }
}
