<?php

/**
 * Return one AIRCON unit's identity + key specs as JSON.
 *
 * GET ?id=<aircon_id>
 *   - Mirrors the payload field set used by the bundled QR encoder
 *     (actions/view_aircon_qr.php) so a scanned QR can always drive the
 *     same "view QR details" view regardless of where the scan originated.
 */

session_start();
include '../includes/auth.php';
include '../includes/db.php';

header('Content-Type: application/json');

$rawInput = trim((string)($_GET['query'] ?? $_POST['query'] ?? $_GET['id'] ?? ''));

if ($rawInput === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'QR Code, Item Number, or ID is required.']);
    exit;
}

$searchId = 0;
$searchTerm = $rawInput;
$targetType = null;

// Detect JSON QR payload (e.g. {"type":"AIRCON_UNIT","id":12,...} or {"type":"PROPERTY_ITEM",...})
if (strpos($rawInput, '{') !== false && strpos($rawInput, '}') !== false) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $targetType = $decoded['type'] ?? null;
        if (!empty($decoded['id'])) {
            $searchId = (int)$decoded['id'];
        } elseif (!empty($decoded['serial'])) {
            $searchTerm = trim((string)$decoded['serial']);
        } elseif (!empty($decoded['item'])) {
            $searchTerm = trim((string)$decoded['item']);
        }
    }
} elseif (ctype_digit($rawInput)) {
    $searchId = (int)$rawInput;
}

// Check prefix tokens: PROP-..., INV-..., AC-..., FE-...
if (stripos($searchTerm, 'PROP-') === 0) {
    $targetType = 'PROPERTY_ITEM';
    $numPart = (int)preg_replace('/[^0-9]/', '', $searchTerm);
    if ($numPart > 0) $searchId = $numPart;
} elseif (stripos($searchTerm, 'INV-') === 0) {
    $targetType = 'INVENTORY_ITEM';
    $numPart = (int)preg_replace('/[^0-9]/', '', $searchTerm);
    if ($numPart > 0) $searchId = $numPart;
} elseif (stripos($searchTerm, 'AC-') === 0) {
    $targetType = 'AIRCON_UNIT';
} elseif (stripos($searchTerm, 'FE-') === 0) {
    $targetType = 'FIRE_EXTINGUISHER';
}

$itemData = null;

// 1. Search AIRCONS
if ($targetType === null || $targetType === 'AIRCON_UNIT') {
    $sql = "SELECT aircon_id, item_number, brand, model, type, capacity, serial_number, location, area, status, campus, lifespan, date_created, date_updated, qr_code FROM aircons WHERE ";
    if ($searchId > 0) {
        $sql .= "aircon_id = ? OR qr_code = ? OR item_number = ? OR serial_number = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $strId = (string)$searchId;
            $stmt->bind_param('isss', $searchId, $strId, $strId, $strId);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                $res['item_type'] = 'Aircon Unit';
                $itemData = $res;
            }
        }
    } else {
        $sql .= "qr_code = ? OR item_number = ? OR serial_number = ? OR CAST(aircon_id AS CHAR) = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ssss', $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                $res['item_type'] = 'Aircon Unit';
                $itemData = $res;
            }
        }
    }
}

// 2. Search PROPERTY INVENTORY
if ($itemData === null && ($targetType === null || $targetType === 'PROPERTY_ITEM')) {
    $sql = "SELECT inventory_id, item_name, category, brand, type, size, color, current_stock, unit, unit_cost, location, receiver, status, qr_code, qrcode, date_received FROM property_inventory WHERE ";
    if ($searchId > 0) {
        $sql .= "inventory_id = ? OR qr_code = ? OR qrcode = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $strId = (string)$searchId;
            $stmt->bind_param('iss', $searchId, $strId, $strId);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                $itemData = [
                    'item_type' => 'Property Item',
                    'aircon_id' => $res['inventory_id'],
                    'item_number' => $res['qr_code'] ?: ('PROP-' . $res['inventory_id']),
                    'brand' => $res['brand'] ?: 'N/A',
                    'model' => $res['item_name'],
                    'type' => $res['category'] ?: 'Property',
                    'capacity' => ($res['current_stock'] ?? 0) . ' ' . ($res['unit'] ?: 'units'),
                    'serial_number' => $res['qrcode'] ?: 'N/A',
                    'location' => $res['location'] ?: 'N/A',
                    'area' => 'Receiver: ' . ($res['receiver'] ?: 'N/A'),
                    'status' => $res['status'] ?: 'Active',
                    'campus' => 'Main Campus',
                    'lifespan' => 'Cost: ₱' . number_format((float)($res['unit_cost'] ?? 0), 2),
                    'date_created' => $res['date_received'],
                    'date_updated' => null,
                    'qr_code' => $res['qr_code']
                ];
            }
        }
    } else {
        $sql .= "qr_code = ? OR qrcode = ? OR CAST(inventory_id AS CHAR) = ? OR item_name = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ssss', $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                $itemData = [
                    'item_type' => 'Property Item',
                    'aircon_id' => $res['inventory_id'],
                    'item_number' => $res['qr_code'] ?: ('PROP-' . $res['inventory_id']),
                    'brand' => $res['brand'] ?: 'N/A',
                    'model' => $res['item_name'],
                    'type' => $res['category'] ?: 'Property',
                    'capacity' => ($res['current_stock'] ?? 0) . ' ' . ($res['unit'] ?: 'units'),
                    'serial_number' => $res['qrcode'] ?: 'N/A',
                    'location' => $res['location'] ?: 'N/A',
                    'area' => 'Receiver: ' . ($res['receiver'] ?: 'N/A'),
                    'status' => $res['status'] ?: 'Active',
                    'campus' => 'Main Campus',
                    'lifespan' => 'Cost: ₱' . number_format((float)($res['unit_cost'] ?? 0), 2),
                    'date_created' => $res['date_received'],
                    'date_updated' => null,
                    'qr_code' => $res['qr_code']
                ];
            }
        }
    }
}

// 3. Search SUPPLY INVENTORY
if ($itemData === null && ($targetType === null || $targetType === 'INVENTORY_ITEM')) {
    $sql = "SELECT inventory_id, item_name, category, brand, type, size, color, current_stock, unit, unit_cost, location, receiver, status, qr_code, qrcode, date_received FROM inventory WHERE ";
    if ($searchId > 0) {
        $sql .= "inventory_id = ? OR qr_code = ? OR qrcode = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $strId = (string)$searchId;
            $stmt->bind_param('iss', $searchId, $strId, $strId);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                $itemData = [
                    'item_type' => 'Supply Item',
                    'aircon_id' => $res['inventory_id'],
                    'item_number' => $res['qr_code'] ?: ('INV-' . $res['inventory_id']),
                    'brand' => $res['brand'] ?: 'N/A',
                    'model' => $res['item_name'],
                    'type' => $res['category'] ?: 'Supply',
                    'capacity' => ($res['current_stock'] ?? 0) . ' ' . ($res['unit'] ?: 'units'),
                    'serial_number' => $res['qrcode'] ?: 'N/A',
                    'location' => $res['location'] ?: 'N/A',
                    'area' => 'Receiver: ' . ($res['receiver'] ?: 'N/A'),
                    'status' => $res['status'] ?: 'Active',
                    'campus' => 'Main Campus',
                    'lifespan' => 'Cost: ₱' . number_format((float)($res['unit_cost'] ?? 0), 2),
                    'date_created' => $res['date_received'],
                    'date_updated' => null,
                    'qr_code' => $res['qr_code']
                ];
            }
        }
    } else {
        $sql .= "qr_code = ? OR qrcode = ? OR CAST(inventory_id AS CHAR) = ? OR item_name = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ssss', $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                $itemData = [
                    'item_type' => 'Supply Item',
                    'aircon_id' => $res['inventory_id'],
                    'item_number' => $res['qr_code'] ?: ('INV-' . $res['inventory_id']),
                    'brand' => $res['brand'] ?: 'N/A',
                    'model' => $res['item_name'],
                    'type' => $res['category'] ?: 'Supply',
                    'capacity' => ($res['current_stock'] ?? 0) . ' ' . ($res['unit'] ?: 'units'),
                    'serial_number' => $res['qrcode'] ?: 'N/A',
                    'location' => $res['location'] ?: 'N/A',
                    'area' => 'Receiver: ' . ($res['receiver'] ?: 'N/A'),
                    'status' => $res['status'] ?: 'Active',
                    'campus' => 'Main Campus',
                    'lifespan' => 'Cost: ₱' . number_format((float)($res['unit_cost'] ?? 0), 2),
                    'date_created' => $res['date_received'],
                    'date_updated' => null,
                    'qr_code' => $res['qr_code']
                ];
            }
        }
    }
}

// 4. Search FIRE EXTINGUISHERS
if ($itemData === null && ($targetType === null || $targetType === 'FIRE_EXTINGUISHER')) {
    $sql = "SELECT fire_extinguisher_id, item_number, serial_number, manufacturer, model, type_agent, size_capacity, building, floor, area, location, status, pressure_status, condition_status, expiration_date, date_created, qr_code FROM fire_extinguishers WHERE ";
    if ($searchId > 0) {
        $sql .= "fire_extinguisher_id = ? OR qr_code = ? OR barcode_qr = ? OR item_number = ? OR serial_number = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $strId = (string)$searchId;
            $stmt->bind_param('issss', $searchId, $strId, $strId, $strId, $strId);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                $itemData = [
                    'item_type' => 'Fire Extinguisher',
                    'aircon_id' => $res['fire_extinguisher_id'],
                    'item_number' => $res['qr_code'] ?: ('FE-' . $res['fire_extinguisher_id']),
                    'brand' => $res['manufacturer'] ?: 'N/A',
                    'model' => $res['model'] ?: 'Fire Extinguisher',
                    'type' => $res['type_agent'] ?: 'FE Agent',
                    'capacity' => $res['size_capacity'] ?: 'N/A',
                    'serial_number' => $res['serial_number'] ?: 'N/A',
                    'location' => ($res['building'] ? $res['building'] . ', ' : '') . ($res['location'] ?: 'N/A'),
                    'area' => 'Pressure: ' . ($res['pressure_status'] ?: 'Normal') . ' | ' . ($res['condition_status'] ?: 'Good'),
                    'status' => $res['status'] ?: 'Active',
                    'campus' => 'Main Campus',
                    'lifespan' => 'Expires: ' . ($res['expiration_date'] ?: 'N/A'),
                    'date_created' => $res['date_created'],
                    'date_updated' => null,
                    'qr_code' => $res['qr_code']
                ];
            }
        }
    } else {
        $sql .= "qr_code = ? OR barcode_qr = ? OR item_number = ? OR serial_number = ? OR CAST(fire_extinguisher_id AS CHAR) = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sssss', $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                $itemData = [
                    'item_type' => 'Fire Extinguisher',
                    'aircon_id' => $res['fire_extinguisher_id'],
                    'item_number' => $res['qr_code'] ?: ('FE-' . $res['fire_extinguisher_id']),
                    'brand' => $res['manufacturer'] ?: 'N/A',
                    'model' => $res['model'] ?: 'Fire Extinguisher',
                    'type' => $res['type_agent'] ?: 'FE Agent',
                    'capacity' => $res['size_capacity'] ?: 'N/A',
                    'serial_number' => $res['serial_number'] ?: 'N/A',
                    'location' => ($res['building'] ? $res['building'] . ', ' : '') . ($res['location'] ?: 'N/A'),
                    'area' => 'Pressure: ' . ($res['pressure_status'] ?: 'Normal') . ' | ' . ($res['condition_status'] ?: 'Good'),
                    'status' => $res['status'] ?: 'Active',
                    'campus' => 'Main Campus',
                    'lifespan' => 'Expires: ' . ($res['expiration_date'] ?: 'N/A'),
                    'date_created' => $res['date_created'],
                    'date_updated' => null,
                    'qr_code' => $res['qr_code']
                ];
            }
        }
    }
}

$conn->close();

if (!$itemData) {
    echo json_encode(['success' => false, 'message' => 'Item not found in any inventory (Aircon, Property, Supply, or Fire Extinguisher).']);
    exit;
}

echo json_encode([
    'success' => true,
    'item_type' => $itemData['item_type'],
    'aircon' => $itemData
]);
