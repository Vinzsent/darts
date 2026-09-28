<?php
require_once __DIR__ . '/../includes/db.php';

$stmt = $conn->prepare("SELECT * FROM user WHERE username = ?");
$username = 'admin';
$stmt->bind_param("s", $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && password_verify('admin123', $user['password'])) {
    echo "LOGIN TEST SUCCESS for {$user['username']} ({$user['user_type']})\n";
} else {
    echo "LOGIN TEST FAILED\n";
}
