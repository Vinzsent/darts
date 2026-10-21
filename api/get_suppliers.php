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

    // Fetch active suppliers - match 'ACTIVE' (case-insensitive in SQL mostly, but let's be precise if needed)
    $sql = "SELECT supplier_id, supplier_name, address, city, province, zip_code 
            FROM supplier 
            WHERE status IN ('Active', 'ACTIVE')
            ORDER BY supplier_name ASC";

    $result = $conn->query($sql);

    if ($result) {
        $suppliers = [];
        while ($row = $result->fetch_assoc()) {
            // Construct full address
            $parts = [];
            if (!empty($row['address'])) $parts[] = trim((string)$row['address']);
            if (!empty($row['city'])) $parts[] = trim((string)$row['city']);
            if (!empty($row['province'])) $parts[] = trim((string)$row['province']);
            $fullAddress = implode(', ', $parts);
            if (!empty($row['zip_code'])) $fullAddress .= ' ' . trim((string)$row['zip_code']);

            $suppliers[] = [
                'id' => (string)$row['supplier_id'],
                'name' => (string)($row['supplier_name'] ?? ''),
                'address' => trim($fullAddress),
                'raw_address' => (string)($row['address'] ?? ''),
                'city' => (string)($row['city'] ?? ''),
                'province' => (string)($row['province'] ?? '')
            ];
        }

        echo json_encode([
            'success' => true,
            'suppliers' => $suppliers
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch suppliers: ' . $conn->error
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $conn->close();
    }
}
