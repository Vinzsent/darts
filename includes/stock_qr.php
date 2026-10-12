<?php
/**
 * Shared helpers for inventory / property stock QR codes.
 *
 * A "stock row" is one row in either inventory table:
 *   type 'property' -> property_inventory (Property Custodian items)
 *   type 'supply'   -> inventory          (Supply In-charge supplies)
 *
 * The same payload builder is used by the view endpoint and the printable
 * label sheet so a QR always decodes to identical JSON no matter where it
 * was produced. Payload shape:
 *   {type, id, item, category, brand, color, size, qty, unit, status,
 *    location, received}
 *
 * PNGs are generated with the hand-rolled encoder in includes/qrcode.php
 * (byte mode, ECC_MEDIUM) and cached in the row's qr_code mediumblob.
 */

require_once __DIR__ . '/qrcode.php';

/** Map the public type token to its table name (null when unknown). */
function stock_qr_table(string $type): ?string
{
    if ($type === 'property') return 'property_inventory';
    if ($type === 'supply') return 'inventory';
    return null;
}

/** Public type token used inside the QR payload for a table name. */
function stock_qr_label(string $table): string
{
    return $table === 'property_inventory' ? 'PROPERTY_ITEM' : 'INVENTORY_ITEM';
}

/**
 * Build the QR payload array for one stock row, or null when the
 * type/id combination is invalid or the row does not exist.
 */
function stock_qr_payload($conn, string $type, int $id): ?array
{
    $table = stock_qr_table($type);
    if ($table === null || $id <= 0) return null;

    // Both tables share these column names (verified against SHOW CREATE).
    $sql = "SELECT inventory_id, item_name, category, brand, color, size, type,
                   current_stock, unit, status, location, date_received
            FROM `$table` WHERE inventory_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    return [
        'type'     => stock_qr_label($table),
        'id'       => (int)$row['inventory_id'],
        'item'     => (string)($row['item_name'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'brand'    => (string)($row['brand'] ?? ''),
        'color'    => (string)($row['color'] ?? ''),
        'size'     => (string)($row['size'] ?? ''),
        'qty'      => (string)($row['current_stock'] ?? '0'),
        'unit'     => (string)($row['unit'] ?? ''),
        'status'   => (string)($row['status'] ?? ''),
        'location' => (string)($row['location'] ?? ''),
        'received' => (string)($row['date_received'] ?? ''),
    ];
}

/** Generate the PNG for one stock row, or null on failure/unknown row. */
function stock_qr_png($conn, string $type, int $id): ?string
{
    $payload = stock_qr_payload($conn, $type, $id);
    if ($payload === null) return null;
    try {
        $qr = new QrCode(json_encode($payload, JSON_UNESCAPED_UNICODE), QrCode::ECC_MEDIUM);
        return $qr->toPng(4, 4);
    } catch (Throwable $e) {
        return null;
    }
}

/** Read the stored qr_code BLOB for one row (null when empty/absent). */
function stock_qr_blob($conn, string $type, int $id): ?string
{
    $table = stock_qr_table($type);
    if ($table === null || $id <= 0) return null;

    $stmt = $conn->prepare("SELECT qr_code FROM `$table` WHERE inventory_id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($blob);
    $png = null;
    if ($stmt->fetch() && $blob !== null && $blob !== '') {
        $png = $blob;
    }
    $stmt->close();
    return $png;
}

/**
 * Persist a generated PNG into the row's qr_code BLOB.
 * Uses send_long_data so binary data is written safely; failures are
 * swallowed (the view endpoint simply regenerates next time).
 */
function stock_qr_persist($conn, string $type, int $id, string $png): bool
{
    $table = stock_qr_table($type);
    if ($table === null || $id <= 0) return false;
    try {
        $up = $conn->prepare("UPDATE `$table` SET qr_code = ? WHERE inventory_id = ?");
        if (!$up) return false;
        $null = null;
        $up->bind_param('bi', $null, $id);
        $up->send_long_data(0, $png);
        $ok = $up->execute();
        $up->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}
