<?php
require_once __DIR__ . '/../includes/db.php';

$conn->query("DROP TABLE IF EXISTS `user`");
$conn->query("CREATE OR REPLACE VIEW `user` AS SELECT * FROM `employees`");
echo "VIEW 'user' -> 'employees' created successfully!\n";
