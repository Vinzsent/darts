<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $sql = "SELECT id, office_name, status FROM offices WHERE status = 'Active' OR status IS NULL ORDER BY office_name ASC";
    $result = $conn->query($sql);

    $offices = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $offices[] = [
                'id' => (int)$row['id'],
                'office_name' => (string)$row['office_name']
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'offices' => $offices
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $conn->close();
    }
}
