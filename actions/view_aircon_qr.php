<?php

/**
 * Stream one AIRCON unit's QR code as a PNG image.
 *
 * Usage: view_aircon_qr.php?id=<aircon_id>[&regen=1]
 *   - The QR is ALWAYS generated fresh from the live `aircons` row with the
 *     bundled encoder (includes/qrcode.php), encoding a compact JSON payload
 *     of the unit's identity + key specs — so details shown by a scan are
 *     never stale. (&regen=1 is accepted for compatibility but is now
 *     redundant: every request regenerates.)
 *   - The qr_code column keeps its short code (e.g. AC-006) as a legacy
 *     scan identifier only; it is no longer used to render the image.
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
        "SELECT aircon_id, item_number, brand, model, type, horse_power, serial_number,
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

// Always generate from the live row so the QR payload reflects the
// current details (brand, model, location, status, ...) at scan time.
// The stored qr_code text (e.g. AC-006) remains in the DB purely as a
// legacy scan identifier and is no longer used to render the image.
$png = null;
try {
    $png = buildAirconQrPng($conn, $id);
} catch (Throwable $e) {
    $png = null;
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
