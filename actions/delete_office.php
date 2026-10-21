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

    if ($id <= 0) {
        $_SESSION['error'] = 'Invalid office ID.';
        header("Location: ../pages/offices.php");
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM offices WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $_SESSION['success'] = 'Office deleted successfully.';
    } else {
        $_SESSION['error'] = 'Error deleting office: ' . $conn->error;
    }

    $stmt->close();
    header("Location: ../pages/offices.php");
    exit;
}
