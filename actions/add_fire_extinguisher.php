<?php
session_start();
include '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: ../pages/property_inventory.php');
    exit();
}

// Collect and sanitize inputs
$item_number         = trim($_POST['item_number'] ?? '');
$serial_number       = trim($_POST['serial_number'] ?? '');
$manufacturer        = trim($_POST['manufacturer'] ?? '');
$model               = trim($_POST['model'] ?? '');
$type_agent          = trim($_POST['type_agent'] ?? '');
$size_capacity       = trim($_POST['size_capacity'] ?? '');
$rating              = trim($_POST['rating'] ?? '');
$building            = trim($_POST['building'] ?? '');
$floor               = trim($_POST['floor'] ?? '');
$area                = trim($_POST['area'] ?? '');
$location            = trim($_POST['location'] ?? '');
$status              = trim($_POST['status'] ?? 'In Service');
$pressure_status     = trim($_POST['pressure_status'] ?? '');
$condition_status    = trim($_POST['condition_status'] ?? '');
$last_inspection_result = trim($_POST['last_inspection_result'] ?? '');
$next_monthly_due    = trim($_POST['next_monthly_due'] ?? '');
$next_annual_due     = trim($_POST['next_annual_due'] ?? '');
$expiration_date     = trim($_POST['expiration_date'] ?? '');
$purchase_date       = trim($_POST['purchase_date'] ?? '');
$purchase_price      = ($_POST['purchase_price'] ?? '') !== '' ? (float)$_POST['purchase_price'] : null;
$supplier_id         = (int)($_POST['supplier_id'] ?? 0);
$responsible_party   = trim($_POST['responsible_party'] ?? '');
$notes               = trim($_POST['notes'] ?? '');
$receiver            = trim($_POST['receiver'] ?? 'Property Custodian');

// Current user's name for the audit trail
$created_by = trim((string)($_SESSION['name'] ?? $_SESSION['user']['name'] ?? $_SESSION['username'] ?? 'System'));

// Basic validation
if ($item_number === '' || $status === '') {
    $_SESSION['error'] = 'Item number and status are required.';
    header('Location: ../pages/property_inventory.php');
    exit();
}

// Empty dates should be stored as NULL, not '0000-00-00'
$nullify_date = static function ($v) {
    return ($v === '' ) ? null : $v;
};
$next_monthly_due = $nullify_date($next_monthly_due);
$next_annual_due  = $nullify_date($next_annual_due);
$expiration_date  = $nullify_date($expiration_date);
$purchase_date    = $nullify_date($purchase_date);

$sql = "INSERT INTO fire_extinguishers
    (item_number, serial_number, manufacturer, model, type_agent, size_capacity, rating,
     building, floor, area, location, status, pressure_status, condition_status,
     last_inspection_result, next_monthly_due, next_annual_due, expiration_date,
     purchase_date, purchase_price, supplier_id, responsible_party, notes, receiver, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $_SESSION['error'] = 'Database error (prepare): ' . $conn->error;
    header('Location: ../pages/property_inventory.php');
    exit();
}

// Types: s(item_number) s(serial) s(manufacturer) s(model) s(type_agent) s(size) s(rating)
//         s(building) s(floor) s(area) s(location) s(status) s(pressure) s(condition)
//         s(last_insp) s(next_monthly) s(next_annual) s(expiration) s(purchase_date)
//         d(purchase_price) i(supplier_id) s(responsible) s(notes) s(receiver) s(created_by)
$stmt->bind_param(
    'sssssssssssssssssssdissss',
    $item_number,
    $serial_number,
    $manufacturer,
    $model,
    $type_agent,
    $size_capacity,
    $rating,
    $building,
    $floor,
    $area,
    $location,
    $status,
    $pressure_status,
    $condition_status,
    $last_inspection_result,
    $next_monthly_due,
    $next_annual_due,
    $expiration_date,
    $purchase_date,
    $purchase_price,
    $supplier_id,
    $responsible_party,
    $notes,
    $receiver,
    $created_by
);

if ($stmt->execute()) {
    $_SESSION['message'] = "Fire extinguisher '{$item_number}' has been added successfully.";
} else {
    $_SESSION['error'] = 'Error adding fire extinguisher: ' . $stmt->error;
}
$stmt->close();

header('Location: ../pages/property_inventory.php');
exit();
