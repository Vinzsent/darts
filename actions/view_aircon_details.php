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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid aircon ID.']);
    exit;
}

$sql = "SELECT aircon_id, item_number, brand, model, type, capacity, "
     . "serial_number, location, area, status, campus, lifespan, "
     . "date_created, date_updated "
     . "FROM aircons WHERE aircon_id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$aircon = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$aircon) {
    echo json_encode(['success' => false, 'message' => 'Aircon not found.']);
    exit;
}

echo json_encode(['success' => true, 'aircon' => $aircon]);
