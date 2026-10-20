<?php

/**
 * Stream one AIRCON unit's QR code as a PNG image.
 *
 * Usage: view_aircon_qr.php?id=<aircon_id>[&regen=1]
 *   - Reads the stored qr_code BLOB from `aircons` (written on add/edit).
 *   - If none exists yet (or &regen=1), generates it on the fly with the
 *     bundled encoder (includes/qrcode.php), encoding a compact JSON payload
 *     of the unit's identity + key specs, and persists it for next time.
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/qrcode.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$regen = isset($_GET['regen']) && $_GET['regen'] === '1';

if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid aircon ID.';
    exit();
}

/** Build the QR payload + PNG for one unit (shared by both paths). */
function buildAirconQrPng($conn, $id)
{
    $stmt = $conn->prepare(
        "SELECT aircon_id, item_number, brand, model, type, capacity, serial_number,
                location, area, status, campus, lifespan
         FROM aircons WHERE aircon_id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $payload = [
        'type'   => 'AIRCON_UNIT',
        'id'     => (int)$row['aircon_id'],
        'item'   => (string)($row['item_number'] ?? ''),
        'brand'  => (string)($row['brand'] ?? ''),
        'model'  => (string)($row['model'] ?? ''),
        'atype'  => (string)($row['type'] ?? ''),
        'serial' => (string)($row['serial_number'] ?? ''),
        'loc'    => (string)($row['location'] ?? ''),
        'area'   => (string)($row['area'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'campus' => (string)($row['campus'] ?? ''),
        'life'   => (string)($row['lifespan'] ?? ''),
    ];
    $qr = new QrCode(json_encode($payload, JSON_UNESCAPED_UNICODE), QrCode::ECC_MEDIUM);
    return $qr->toPng(6, 4);
}

// Try the stored value first (unless a regen was requested).
$png = null;
if (!$regen) {
    $stmt = $conn->prepare("SELECT qr_code FROM aircons WHERE aircon_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($val);
        if ($stmt->fetch() && $val !== null && $val !== '') {
            if (strpos($val, "\x89PNG") === 0) {
                $png = $val;
            } else {
                try {
                    $qr = new QrCode($val, QrCode::ECC_MEDIUM);
                    $png = $qr->toPng(6, 4);
                } catch (Throwable $e) {
                    $png = null;
                }
            }
        }
        $stmt->close();
    }
}

// Fall back to generating on the fly.
if ($png === null) {
    try {
        $png = buildAirconQrPng($conn, $id);
    } catch (Throwable $e) {
        $png = null;
    }
}

if ($png === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'QR code not available for this unit.';
    exit();
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: no-cache, must-revalidate');
echo $png;
exit();
