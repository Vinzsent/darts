<?php
session_start();
include '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$fire_extinguisher_id = isset($_POST['fire_extinguisher_id']) ? (int)$_POST['fire_extinguisher_id'] : 0;
if ($fire_extinguisher_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid fire extinguisher ID.']);
    exit();
}

// Clean up related records first (images / maintenance history) so no orphans remain.
foreach (['fire_extinguisher_images', 'fire_extinguisher_maintenance'] as $childTable) {
    $del = $conn->prepare("DELETE FROM `{$childTable}` WHERE fire_extinguisher_id = ?");
    if ($del) {
        $del->bind_param('i', $fire_extinguisher_id);
        $del->execute();
        $del->close();
    }
}

$stmt = $conn->prepare("DELETE FROM fire_extinguishers WHERE fire_extinguisher_id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error (prepare delete): ' . $conn->error]);
    exit();
}
$stmt->bind_param('i', $fire_extinguisher_id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Fire extinguisher has been deleted successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Fire extinguisher not found.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Error deleting fire extinguisher: ' . $stmt->error]);
}
$stmt->close();
$conn->close();
exit();

