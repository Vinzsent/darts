<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user_type = $_SESSION['user_type'] ?? '';
if (strtolower($user_type) !== 'admin') {
    $_SESSION['error'] = 'Access denied. Only administrators can perform this action.';
    header("Location: ../pages/offices.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id = intval($_POST['id'] ?? 0);
    $office_name = trim($_POST['office_name'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');
    $userName = $_SESSION['user']['username'] ?? $_SESSION['user']['first_name'] ?? 'Admin';

    if ($id <= 0 || empty($office_name)) {
        $_SESSION['error'] = 'Invalid office data.';
        header("Location: ../pages/offices.php");
        exit;
    }

    // Check duplicate excluding this id
    $check_stmt = $conn->prepare("SELECT id FROM offices WHERE LOWER(office_name) = LOWER(?) AND id != ? LIMIT 1");
    $check_stmt->bind_param("si", $office_name, $id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        $_SESSION['error'] = 'Another office with this name already exists.';
        $check_stmt->close();
        header("Location: ../pages/offices.php");
        exit;
    }
    $check_stmt->close();

    $stmt = $conn->prepare("UPDATE offices SET office_name = ?, status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("sssi", $office_name, $status, $userName, $id);

    if ($stmt->execute()) {
        $_SESSION['success'] = 'Office updated successfully.';
    } else {
        $_SESSION['error'] = 'Error updating office: ' . $conn->error;
    }

    $stmt->close();
    header("Location: ../pages/offices.php");
    exit;
}
