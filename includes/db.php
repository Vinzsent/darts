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
    die('Database connection failed. Please check your database settings.');
}
