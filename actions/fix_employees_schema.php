<?php

/**
 * ONLINE DATABASE FIX SCRIPT — employees table name columns
 *
 * Adds missing name columns to the `employees` table when the deployed database
 * has an older schema (no first_name / middle_name / last_name). Without them,
 * pages that do CONCAT(u.first_name, ' ', u.last_name) fail with:
 *   "Unknown column 'u.first_name' in 'SELECT'"
 *
 * INSTRUCTIONS:
 * 1. Upload this file to your 'actions' folder on the server.
 * 2. Access it via your browser: yourdomain.com/darts/actions/fix_employees_schema.php
 * 3. Delete this file after use for security.
 */

// Do not throw uncaught mysqli exceptions while fixing
mysqli_report(MYSQLI_REPORT_OFF);

include '../includes/db.php';

echo "<h2>DARTS Employees Table Schema Fix</h2>";

// 1. Check the table exists
$check_table = $conn->query("SHOW TABLES LIKE 'employees'");
if (!$check_table || $check_table->num_rows === 0) {
    echo "<p><span style='color:red'>ERROR: Table 'employees' does not exist.</span></p>";
    exit;
}

// 2. Show current structure for diagnosis
echo "<h3>Current columns</h3><pre>";
$cols = $conn->query("SHOW COLUMNS FROM employees");
$existing = [];
while ($cols && $c = $cols->fetch_assoc()) {
    $existing[] = $c['Field'];
    echo $c['Field'] . '  (' . $c['Type'] . ')' . "\n";
}
echo "</pre>";

// 3. Add missing name columns (after 'title', matching the local schema)
$wanted = [
    'first_name'  => "VARCHAR(100) NULL AFTER title",
    'middle_name' => "VARCHAR(100) NULL AFTER first_name",
    'last_name'   => "VARCHAR(100) NULL AFTER middle_name",
];

foreach ($wanted as $column => $definition) {
    if (in_array($column, $existing, true)) {
        echo "Column '<b>$column</b>': <span style='color:green'>already exists</span><br>";
    } else {
        echo "Column '<b>$column</b>': adding... ";
        if ($conn->query("ALTER TABLE employees ADD COLUMN $column $definition")) {
            echo "<span style='color:green'>SUCCESS</span><br>";
        } else {
            echo "<span style='color:red'>ERROR: " . htmlspecialchars($conn->error) . "</span><br>";
        }
    }
}

// 4. Re-check
echo "<h3>Resulting columns</h3><pre>";
$cols = $conn->query("SHOW COLUMNS FROM employees");
while ($cols && $c = $cols->fetch_assoc()) {
    echo $c['Field'] . '  (' . $c['Type'] . ')' . "\n";
}
echo "</pre>";

// ─────────────────────────────────────────────────────────────
// 5. purchase_orders.status ENUM must contain 'Received'
//    (marking a PO received fails with "Data truncated for column 'status'"
//     if a DB import reset the ENUM to the old list)
// ─────────────────────────────────────────────────────────────
echo "<h3>purchase_orders.status</h3>";

$col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'");
if ($col && $col->num_rows > 0) {
    $current = $col->fetch_assoc();
    echo "Current: <code>" . htmlspecialchars($current['Type']) . "</code><br>";

    if (strpos($current['Type'], "'Received'") !== false) {
        echo "<span style='color:green'>Already includes 'Received' — OK</span><br>";
    } else {
        // Rebuild ENUM keeping existing values and appending 'Received'
        $inner = substr($current['Type'], strlen('enum('), -1); // 'a','b',...
        echo "Adding 'Received'... ";
        if ($conn->query("ALTER TABLE purchase_orders MODIFY status enum($inner,'Received') DEFAULT 'Draft'")) {
            echo "<span style='color:green'>SUCCESS</span><br>";
        } else {
            echo "<span style='color:red'>ERROR: " . htmlspecialchars($conn->error) . "</span><br>";
        }
    }

    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'");
    $now = $col->fetch_assoc();
    echo "Now: <code>" . htmlspecialchars($now['Type']) . "</code><br>";
} else {
    echo "<span style='color:red'>Table/column purchase_orders.status not found.</span><br>";
}

echo "<br><b>Action complete. Please delete this script from your server for security.</b>";

$conn->close();
?>