<?php
require_once __DIR__ . '/../includes/db.php';

foreach (['employees', 'employee'] as $t) {
    $r = $conn->query("DESCRIBE `$t`");
    if ($r) {
        echo "Table: $t\n";
        while ($row = $r->fetch_assoc()) {
            echo " - {$row['Field']} ({$row['Type']})\n";
        }
        echo "\nSample rows:\n";
        $data = $conn->query("SELECT * FROM `$t` LIMIT 3");
        if ($data) {
            while ($d = $data->fetch_assoc()) {
                print_r($d);
            }
        }
    }
}
