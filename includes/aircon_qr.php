<?php
/**
 * Helper functions for aircon QR generation and persistence.
 */
require_once __DIR__ . '/qrcode.php';

function aircon_qr_png(int $aircon_id): ?string
{
    require_once __DIR__ . '/db.php';
    global $conn;

    $stmt = $conn->prepare(
        "SELECT aircon_id, item_number, brand, model, type, horse_power, serial_number,
                location, area, status, campus, lifespan, qr_code
         FROM aircons WHERE aircon_id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $aircon_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $codeVal = !empty($row['qr_code']) ? $row['qr_code'] : ('AC-' . str_pad($aircon_id, 4, '0', STR_PAD_LEFT));

    try {
        $qr = new QrCode($codeVal, QrCode::ECC_MEDIUM);
        return $qr->toPng(4, 4);
    } catch (Throwable $e) {
        return null;
    }
}

function aircon_qr_persist($conn, int $aircon_id, string $png = ''): bool
{
    $stmt = $conn->prepare("SELECT item_number, qr_code FROM aircons WHERE aircon_id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $aircon_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $itemNum = trim((string)($row['item_number'] ?? ''));
    if ($itemNum !== '' && preg_match('/^[A-Za-z0-9\-_]+$/', $itemNum)) {
        $code = (stripos($itemNum, 'AC') === 0) ? $itemNum : ('AC-' . $itemNum);
    } else {
        $code = 'AC-' . str_pad($aircon_id, 4, '0', STR_PAD_LEFT);
    }

    $up = $conn->prepare("UPDATE aircons SET qr_code = COALESCE(NULLIF(qr_code, ''), ?) WHERE aircon_id = ?");
    if (!$up) return false;
    $up->bind_param('si', $code, $aircon_id);
    $ok = $up->execute();
    $up->close();
    return (bool)$ok;
}
