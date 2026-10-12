<?php
session_start();
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();
include '../includes/db.php';
if (isset($conn) && $conn instanceof mysqli) { @$conn->set_charset('utf8mb4'); }
header('Content-Type: application/json; charset=utf-8');
function inv_fail($msg) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function inv_utf8($v) {
    if (is_string($v)) {
        if (function_exists('mb_check_encoding') && !mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }
        return $v;
    }
    if (is_array($v)) {
        foreach ($v as $k => $x) { $v[$k] = inv_utf8($x); }
    }
    return $v;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['inventory_id'])) {
    $inventory_id = (int)$_GET['inventory_id'];
    if (!isset($conn) || !($conn instanceof mysqli) || @$conn->connect_error) {
        inv_fail('Database unavailable.');
    }
    $sql = "SELECT i.*, s.supplier_name FROM inventory i LEFT JOIN supply_supplier s ON i.supplier_id = s.supplier_id WHERE i.inventory_id = ?";
    $stmt = @$conn->prepare($sql);
    if (!$stmt) {
        inv_fail('Lookup failed: ' . (string)@$conn->error);
    }
    $stmt->bind_param('i', $inventory_id);
    if (!$stmt->execute()) {
        $e = (string)$stmt->error;
        $stmt->close();
        inv_fail('Lookup failed: ' . $e);
    }
    $result = $stmt->get_result();
    if (!$result) {
        $e = (string)$stmt->error;
        $stmt->close();
        inv_fail('Lookup failed: ' . $e);
    }
    if ($result->num_rows > 0) {
        $item = inv_utf8($result->fetch_assoc());
        $stmt->close();
        while (ob_get_level() > 0) { ob_end_clean(); }
        $json = json_encode(['success' => true, 'item' => $item], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            inv_fail('Lookup failed: could not encode item data.');
        }
        echo $json;
    } else {
        $stmt->close();
        inv_fail('Item not found');
    }
} else {
    inv_fail('Invalid request');
}