<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Detect JSON or AJAX request
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

$userName = $_SESSION['user']['username'] ?? $_SESSION['user']['first_name'] ?? 'System';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);

    $office_name = trim($jsonData['office_name'] ?? $_POST['office_name'] ?? '');

    if (empty($office_name)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Office name is required.']);
            exit;
        }
        $_SESSION['error'] = 'Office name is required.';
        header("Location: ../pages/offices.php");
        exit;
    }

    // Check if duplicate
    $check_stmt = $conn->prepare("SELECT id, office_name FROM offices WHERE LOWER(office_name) = LOWER(?) LIMIT 1");
    $check_stmt->bind_param("s", $office_name);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();

    if ($check_res && $check_res->num_rows > 0) {
        $existing = $check_res->fetch_assoc();
        $check_stmt->close();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            // If already exists, return it gracefully so the dropdown can select it
            echo json_encode([
                'success' => true,
                'message' => 'Office already exists.',
                'office' => [
                    'id' => (int)$existing['id'],
                    'office_name' => $existing['office_name']
                ]
            ]);
            exit;
        }
        $_SESSION['error'] = 'This office already exists.';
        header("Location: ../pages/offices.php");
        exit;
    }
    $check_stmt->close();

    $stmt = $conn->prepare("INSERT INTO offices (office_name, created_by, status) VALUES (?, ?, 'Active')");
    $stmt->bind_param("ss", $office_name, $userName);

    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        $stmt->close();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Office added successfully.',
                'office' => [
                    'id' => (int)$newId,
                    'office_name' => $office_name
                ]
            ]);
            exit;
        }

        $_SESSION['success'] = 'Office added successfully.';
    } else {
        $err = $conn->error;
        $stmt->close();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save office: ' . $err]);
            exit;
        }

        $_SESSION['error'] = 'Error adding office: ' . $err;
    }

    header("Location: ../pages/offices.php");
    exit;
}
