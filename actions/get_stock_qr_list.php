<?php
/**
 * Return the label rows for the "Print All QR" sheet as JSON.
 *
 * Usage: get_stock_qr_list.php?type=property|supply|fire[&id=<row_id>]
 *   - type=property -> property_inventory rows (QRs via view_stock_qr.php?type=property)
 *   - type=supply   -> inventory rows          (QRs via view_stock_qr.php?type=supply)
 *   - type=fire     -> fire_extinguishers rows (QRs via view_fire_extinguisher_qr.php)
 *
 * Consumed by assets/js/print_all_stock_qr.js, which fetches each row's QR
 * image with a live progress counter and renders the printable label sheet.
 * QR payloads are always generated fresh from the live rows, so printed
 * labels carry the item's current details.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/auth.php';
include '../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$type = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

function jsonOut($payload)
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$tables = ['property' => 'property_inventory', 'supply' => 'inventory'];
$where  = ($id > 0) ? ' WHERE inventory_id = ' . $id : '';

if (isset($tables[$type])) {
    $table  = $tables[$type];
    $prefix = ($type === 'property') ? 'PROP-' : 'INV-';
    $sql = "SELECT inventory_id, item_name, category, brand, current_stock, unit, status, location, qr_code
            FROM `$table`$where ORDER BY item_name ASC";
    $res = $conn->query($sql);
    $items = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'id'       => (int)$row['inventory_id'],
                'code'     => trim((string)($row['qr_code'] ?? '')) ?: ($prefix . (int)$row['inventory_id']),
                'item'     => (string)($row['item_name'] ?? ''),
                'category' => (string)($row['category'] ?? ''),
                'brand'    => (string)($row['brand'] ?? ''),
                'qty'      => (string)($row['current_stock'] ?? '0'),
                'unit'     => (string)($row['unit'] ?? ''),
                'status'   => (string)($row['status'] ?? ''),
                'location' => (string)($row['location'] ?? ''),
            ];
        }
    }
    jsonOut($items);
}

if ($type === 'fire') {
    $where = ($id > 0) ? ' WHERE fire_extinguisher_id = ' . $id : '';
    $sql = "SELECT fire_extinguisher_id, item_number, serial_number, manufacturer, model, type_agent,
                   size_capacity, building, floor, area, location, status, expiration_date, qr_code
            FROM fire_extinguishers$where ORDER BY item_number ASC";
    $res = $conn->query($sql);
    $items = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'id'       => (int)$row['fire_extinguisher_id'],
                'code'     => trim((string)($row['qr_code'] ?? '')) ?: ('FE-' . (int)$row['fire_extinguisher_id']),
                'item'     => (string)($row['item_number'] ?? ''),
                'serial'   => (string)($row['serial_number'] ?? ''),
                'brand'    => (string)($row['manufacturer'] ?? ''),
                'model'    => (string)($row['model'] ?? ''),
                'agent'    => (string)($row['type_agent'] ?? ''),
                'size'     => (string)($row['size_capacity'] ?? ''),
                'building' => (string)($row['building'] ?? ''),
                'floor'    => (string)($row['floor'] ?? ''),
                'area'     => (string)($row['area'] ?? ''),
                'location' => (string)($row['location'] ?? ''),
                'status'   => (string)($row['status'] ?? ''),
                'expires'  => (string)($row['expiration_date'] ?? ''),
            ];
        }
    }
    jsonOut($items);
}

http_response_code(400);
jsonOut(['success' => false, 'message' => 'Invalid type. Use property, supply or fire.']);
