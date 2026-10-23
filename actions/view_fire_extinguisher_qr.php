<?php
/**
 * Stream one FIRE EXTINGUISHER's QR code as a PNG image.
 *
 * Usage: view_fire_extinguisher_qr.php?id=<fire_extinguisher_id>[&regen=1]
 *   - The QR is ALWAYS generated fresh from the live `fire_extinguishers`
 *     row (agent, pressure, condition, location, expiry, ...) so a scan
 *     decodes the unit's current details — never a stale snapshot.
 *     (&regen=1 is accepted for compatibility but is now redundant.)
 *   - The qr_code column keeps its short code (e.g. FE-276) as a legacy
 *     scan identifier only.
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

// Always generate from the live row so the QR payload reflects the
// current details (agent, pressure, condition, location, ...) at scan
// time. The stored qr_code text (e.g. FE-276) stays as a legacy scan
// identifier only.
$png = null;
try {
    $png = buildFeQrPng($conn, $id);
} catch (Throwable $e) {
    $png = null;
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
