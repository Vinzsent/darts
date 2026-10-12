<?php
$host   = '127.0.0.1';
$user   = 'root';
$pass   = '';
$dbname = 'darts';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($host, $user, $pass, $dbname);

if (mysqli_connect_error()) {
    $errMsg = '(' . mysqli_connect_errno() . ') ' . mysqli_connect_error();
    error_log('Database connection failed: ' . $errMsg);
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isJson = (stripos($accept, 'application/json') !== false)
        || (stripos($script, '/actions/') !== false)
        || (stripos($script, '/api/') !== false)
        || isset($_GET['ajax']);
    if ($isJson) {
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        die(json_encode(['success' => false, 'message' => 'Database connection failed. Please check your database settings.']));
    }
    die('Database connection failed. Please check your database settings.');
}
