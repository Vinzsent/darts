<?php
require_once __DIR__ . '/../includes/db.php';

$username = 'admin';
$stmt = $conn->prepare("SELECT * FROM employees WHERE username = ? OR email = ?");
$stmt->bind_param("ss", $username, $username);
$stmt->execute();
$res = $stmt->get_result();
echo "Found rows: " . $res->num_rows . "\n";
if ($res->num_rows === 1) {
    $row = $res->fetch_assoc();
    echo "Employee ID: " . $row['id'] . ", Name: " . $row['first_name'] . ' ' . $row['last_name'] . ", Role: " . $row['user_type'] . "\n";
}
