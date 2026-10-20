<?php
/**
 * Stream one FIRE EXTINGUISHER's QR code as a PNG image.
 *
 * Usage: view_fire_extinguisher_qr.php?id=<fire_extinguisher_id>[&regen=1]
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/qrcode.php';

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$regen = isset($_GET['regen']) && $_GET['regen'] === '1';

if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid fire extinguisher ID.';
    exit();
}

function buildFeQrPng($conn, $id)
{
    $stmt = $conn->prepare(
        "SELECT fire_extinguisher_id, item_number, serial_number, manufacturer, model,
                type_agent, size_capacity, building, floor, area, location, status,
                pressure_status, condition_status, expiration_date
         FROM fire_extinguishers WHERE fire_extinguisher_id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $payload = [
        'type'       => 'FIRE_EXTINGUISHER',
        'id'         => (int)$row['fire_extinguisher_id'],
        'item'       => (string)($row['item_number'] ?? ''),
        'serial'     => (string)($row['serial_number'] ?? ''),
        'brand'      => (string)($row['manufacturer'] ?? ''),
        'model'      => (string)($row['model'] ?? ''),
        'agent'      => (string)($row['type_agent'] ?? ''),
        'size'       => (string)($row['size_capacity'] ?? ''),
        'building'   => (string)($row['building'] ?? ''),
        'floor'      => (string)($row['floor'] ?? ''),
        'area'       => (string)($row['area'] ?? ''),
        'loc'        => (string)($row['location'] ?? ''),
        'status'     => (string)($row['status'] ?? ''),
        'pressure'   => (string)($row['pressure_status'] ?? ''),
        'condition'  => (string)($row['condition_status'] ?? ''),
        'expires'    => (string)($row['expiration_date'] ?? ''),
    ];
    $qr = new QrCode(json_encode($payload, JSON_UNESCAPED_UNICODE), QrCode::ECC_MEDIUM);
    return $qr->toPng(6, 4);
}

// Try stored value first
$png = null;
if (!$regen) {
    $stmt = $conn->prepare("SELECT qr_code FROM fire_extinguishers WHERE fire_extinguisher_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($val);
        if ($stmt->fetch() && $val !== null && $val !== '') {
            if (strpos($val, "\x89PNG") === 0) {
                $png = $val;
            } else {
                try {
                    $qr  = new QrCode($val, QrCode::ECC_MEDIUM);
                    $png = $qr->toPng(6, 4);
                } catch (Throwable $e) {
                    $png = null;
                }
            }
        }
        $stmt->close();
    }
}

if ($png === null) {
    try {
        $png = buildFeQrPng($conn, $id);
    } catch (Throwable $e) {
        $png = null;
    }
}

if ($png === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'QR code not available for this fire extinguisher.';
    exit();
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: no-cache, must-revalidate');
echo $png;
exit();
