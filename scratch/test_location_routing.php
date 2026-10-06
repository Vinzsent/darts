<?php
/**
 * TEMPORARY functional test for the purchase-order `location` routing.
 * Everything runs inside a transaction that is rolled back at the end.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli('127.0.0.1', 'root', '', 'darts');
if ($conn->connect_error) {
    die("connect failed: " . $conn->connect_error . "\n");
}
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/../includes/po_inventory_helper.php';

$_SESSION['user'] = [
    'id' => 0,
    'first_name' => 'Test',
    'last_name' => 'Taker',
    'user_type' => 'Supply In-charge',
];
$_SESSION['user_type'] = 'Supply In-charge';

$pass = 0;
$fail = 0;
function check($label, $actual, $expected)
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "PASS  {$label} => " . var_export($actual, true) . "\n";
    } else {
        $fail++;
        echo "FAIL  {$label} => got " . var_export($actual, true)
            . " expected " . var_export($expected, true) . "\n";
    }
}

echo "== 1. po_normalize_location ==\n";
check('"supply"', po_normalize_location('supply'), 'supply');
check('"Supply"', po_normalize_location('Supply'), 'supply');
check('"Supply In-charge"', po_normalize_location('Supply In-charge'), 'supply');
check('"supplies"', po_normalize_location('supplies'), 'supply');
check('"property"', po_normalize_location('property'), 'property');
check('"Property Custodian"', po_normalize_location('Property Custodian'), 'property');
check('"" (empty -> null)', po_normalize_location(''), null);
check('NULL (-> null)', po_normalize_location(null), null);
check('"random text"', po_normalize_location('somewhere else'), null);

echo "\n== 2. column probe ==\n";
check('location column exists', po_location_column_exists($conn), true);

echo "\n== 3. precedence: location beats role ==\n";
// A Supply In-charge receives a line marked "property" -> must go to property_inventory
check('property mark + supply role', po_resolve_target_for_item('property', 'Supply In-charge', null), 'property');
// A Property Custodian receives a line marked "supply" -> must go to inventory
check('supply mark + property role', po_resolve_target_for_item('supply', 'Property Custodian', null), 'supply');
// Unmarked line -> role decides (unchanged legacy behaviour)
check('unmarked + supply role', po_resolve_target_for_item('', 'Supply In-charge', null), 'supply');
check('unmarked + property role', po_resolve_target_for_item(null, 'Property Custodian', null), 'property');

echo "\n== 4. end-to-end posting (rolled back) ==\n";
$conn->begin_transaction();

// created_by has an FK to employees, so borrow a real id for the test
$emp = $conn->query("SELECT id FROM employees ORDER BY id ASC LIMIT 1")->fetch_assoc();
$creator = $emp ? (int)$emp['id'] : 0;
echo "using employees.id = {$creator}\n";

$po_sql = "INSERT INTO purchase_orders (po_number, po_date, supplier_name, supplier_address, payment_method, cash_amount, total_amount, created_by)
           VALUES ('ZZTEST-LOC-1', '2026-01-01', 'TEST SUPPLIER', 'TEST ADDRESS', 'Check', 0, 0, {$creator})";
$conn->query($po_sql);
$po_id = (int)$conn->insert_id;
echo "test po_id = {$po_id}\n";

// Two identical-description lines on the same PO, one per destination.
$lines = [
    ['num' => 1, 'loc' => 'supply', 'desc' => 'ZZTEST Routing Item A'],
    ['num' => 2, 'loc' => 'property', 'desc' => 'ZZTEST Routing Item B'],
];
foreach ($lines as $l) {
    // UNIQUE(po_id, item_number) — so each test line needs its own item_number
    $conn->query("INSERT INTO purchase_order_items
                  (po_id, item_number, item_description, quantity, unit_cost, line_total, location)
                  VALUES ({$po_id}, {$l['num']}, '{$l['desc']}', 5, 10.00, 50.00, '{$l['loc']}')");
}

$rows = [];
$r = $conn->query("SELECT poi_id, item_description, quantity, unit_cost, location FROM purchase_order_items WHERE po_id = {$po_id}");
while ($row = $r->fetch_assoc()) {
    $rows[] = $row;
}
check('2 test lines created', count($rows), 2);

$po_row = ['po_id' => $po_id, 'po_number' => 'ZZTEST-LOC-1'];
foreach ($rows as $line) {
    // Simulate a SUPPLY IN-CHARGE account receiving: role says supply,
    // but a line marked 'property' must still land in property_inventory.
    $res = po_post_item_to_inventory($conn, $po_row, $line, 'supply', $creator, '2026-01-01 10:00:00');
    echo "  line '{$line['item_description']}' (location={$line['location']}) -> "
        . "status={$res['status']} target={$res['target']} msg={$res['message']}\n";
    check("resolved target for '{$line['location']}'", $res['target'], $line['location']);
}

$inv = $conn->query("SELECT COUNT(*) c FROM inventory WHERE item_name LIKE 'ZZTEST Routing%'")->fetch_assoc()['c'];
$pinv = $conn->query("SELECT COUNT(*) c FROM property_inventory WHERE item_name LIKE 'ZZTEST Routing%'")->fetch_assoc()['c'];
check('supply line landed in `inventory`', (int)$inv, 1);
check('property line landed in `property_inventory`', (int)$pinv, 1);

$q = $conn->query("SELECT item_name, current_stock, receiver FROM property_inventory WHERE item_name LIKE 'ZZTEST Routing%'")->fetch_assoc();
echo "  property_inventory row: " . json_encode($q) . "\n";
// The Property page filters on receiver LIKE '%Property Custodian%' — a supply
// in-charge receiving a property line must still show up there.
check(
    'property row is visible to the Property Inventory page',
    stripos((string)$q['receiver'], 'Property Custodian') !== false,
    true
);
check('property row stock', (int)$q['current_stock'], 5);

echo "\n== 5. idempotency still holds ==\n";
$line = $rows[1];
$res2 = po_post_item_to_inventory($conn, $po_row, $line, 'supply', $creator, '2026-01-01 11:00:00');
check('re-posting the same line is skipped', $res2['status'], 'skipped');
$pinv2 = $conn->query("SELECT COUNT(*) c FROM property_inventory WHERE item_name LIKE 'ZZTEST Routing%'")->fetch_assoc()['c'];
check('no duplicate property row', (int)$pinv2, 1);

echo "\n== 6. po_apply_location_to_item (posted with the receive click) ==\n";
// A line with no mark yet, one already marked supply, one with junk text.
$extra = [
    ['num' => 3, 'loc' => 'NULL', 'desc' => 'ZZTEST Apply A'],
    ['num' => 4, 'loc' => "'supply'", 'desc' => 'ZZTEST Apply B'],
    ['num' => 5, 'loc' => "''", 'desc' => 'ZZTEST Apply C'],
];
foreach ($extra as $e) {
    $conn->query("INSERT INTO purchase_order_items
                  (po_id, item_number, item_description, quantity, unit_cost, line_total, location)
                  VALUES ({$po_id}, {$e['num']}, '{$e['desc']}', 1, 1.00, 1.00, {$e['loc']})");
}
$ids = [];
$q = $conn->query("SELECT poi_id, location FROM purchase_order_items WHERE po_id = {$po_id} AND item_number >= 3 ORDER BY item_number");
while ($x = $q->fetch_assoc()) {
    $ids[$x['poi_id']] = $x['location'];
}

foreach ($ids as $id => $before) {
    po_apply_location_to_item($conn, $id, 'property');
    $after = $conn->query("SELECT location FROM purchase_order_items WHERE poi_id = {$id}")->fetch_assoc()['location'];
    // NULL/empty get filled in; an explicit 'supply' mark is never overwritten
    $expected = ($before === 'supply') ? 'supply' : 'property';
    check("poi {$id} (was " . var_export($before, true) . ")", $after, $expected);
}

check('invalid location rejected', po_apply_location_to_item($conn, array_key_first($ids), 'somewhere else'), false);
check('invalid location left the row alone',
    $conn->query("SELECT location FROM purchase_order_items WHERE poi_id = " . array_key_first($ids))->fetch_assoc()['location'],
    'property');
check('missing poi_id rejected', po_apply_location_to_item($conn, 0, 'supply'), false);

echo "\n== 7. unmarked line records the bucket the role chose ==\n";
// A Property Custodian receives a line nobody marked: routing uses the role,
// and the effective destination must now be visible in the Location column.
$conn->query("INSERT INTO purchase_order_items
              (po_id, item_number, item_description, quantity, unit_cost, line_total, location)
              VALUES ({$po_id}, 6, 'ZZTEST Unmarked C', 2, 5.00, 10.00, NULL)");
$unmarked = $conn->query("SELECT poi_id FROM purchase_order_items WHERE po_id = {$po_id} AND item_number = 6")->fetch_assoc();

$res7 = po_post_item_to_inventory($conn, $po_row, [
    'poi_id' => (int)$unmarked['poi_id'],
    'item_description' => 'ZZTEST Unmarked C',
    'quantity' => 2,
    'unit_cost' => 5.00,
    'location' => '',
], 'property', $creator, '2026-01-01 12:00:00');
check('routed by role (property)', $res7['target'], 'property');
check('location column now filled',
    $conn->query("SELECT location FROM purchase_order_items WHERE poi_id = " . (int)$unmarked['poi_id'])->fetch_assoc()['location'],
    'property');

echo "\n== 8. backfill logic for lines received long ago ==\n";
// Simulate history: stock posted BEFORE the link table existed, so only the
// source_poi_id stamped on the inventory row can tell us where it went.
// Reuse the inventory rows created in section 4 (they carry source_poi_id).
$seed = [];
foreach ([['property_inventory', 'ZZTEST Routing Item B'], ['inventory', 'ZZTEST Routing Item A']] as $pair) {
    $row = $conn->query("SELECT inventory_id, source_poi_id FROM `{$pair[0]}` WHERE item_name = '{$pair[1]}' LIMIT 1")->fetch_assoc();
    if ($row && $row['source_poi_id']) {
        $seed[] = ['table' => $pair[0], 'src' => (int)$row['source_poi_id'], 'expect' => ($pair[0] === 'property_inventory') ? 'property' : 'supply'];
    }
}
check('2 historical inventory rows found', count($seed), 2);

foreach ($seed as $s) {
    // Blank the line the way it looked before the location column existed, and
    // remove its link row so only the source_poi_id fallback can resolve it.
    $conn->query("DELETE FROM po_item_inventory_links WHERE poi_id = {$s['src']}");
    $conn->query("UPDATE purchase_order_items SET location = NULL, is_received = 1 WHERE poi_id = {$s['src']}");
}

// The same fallback statements the backfill action runs, in the same order.
$conn->query("UPDATE `purchase_order_items` poi
              JOIN `property_inventory` pi ON pi.source_poi_id = poi.poi_id
              SET poi.location = 'property'
              WHERE poi.is_received = 1 AND (poi.location IS NULL OR TRIM(poi.location) = '')");
$conn->query("UPDATE `purchase_order_items` poi
              JOIN `inventory` i ON i.source_poi_id = poi.poi_id
              SET poi.location = 'supply'
              WHERE poi.is_received = 1 AND (poi.location IS NULL OR TRIM(poi.location) = '')");

foreach ($seed as $s) {
    $got = $conn->query("SELECT location FROM purchase_order_items WHERE poi_id = {$s['src']}")->fetch_assoc()['location'];
    check("backfill via {$s['table']}.source_poi_id", $got, $s['expect']);
}

// A received line we know nothing about must be reported, never guessed.
$conn->query("INSERT INTO purchase_order_items
              (po_id, item_number, item_description, quantity, unit_cost, line_total, location, is_received)
              VALUES ({$po_id}, 10, 'ZZTEST Hist Unknown', 1, 1.00, 1.00, NULL, 1)");
$unknown = $conn->query("SELECT location FROM purchase_order_items WHERE po_id = {$po_id} AND item_number = 10")->fetch_assoc()['location'];
check('unresolvable line left NULL (reported, not guessed)', $unknown, null);

echo "\n== 9. supply item is visible to the Supply Inventory filter ==\n";
// Post a supply-marked line the way the app does, then query it the way the
// inventory list does — first with the OLD exact filter, then with the fixed one.
$conn->query("INSERT INTO purchase_order_items
              (po_id, item_number, item_description, quantity, unit_cost, line_total, location, is_received)
              VALUES ({$po_id}, 11, 'ZZTEST Visibility', 4, 3.00, 12.00, 'supply', 1)");
$vis = $conn->query("SELECT poi_id FROM purchase_order_items WHERE po_id = {$po_id} AND item_number = 11")->fetch_assoc();
po_post_item_to_inventory($conn, $po_row, [
    'poi_id' => (int)$vis['poi_id'],
    'item_description' => 'ZZTEST Visibility',
    'quantity' => 4,
    'unit_cost' => 3.00,
    'location' => 'supply',
], 'supply', $creator, '2026-01-01 13:00:00');

$recv = $conn->query("SELECT receiver FROM inventory WHERE item_name = 'ZZTEST Visibility' LIMIT 1")->fetch_assoc();
echo "  stored receiver = " . var_export($recv['receiver'] ?? null, true) . "\n";

$old = $conn->query("SELECT COUNT(*) c FROM inventory i
                     WHERE i.receiver = 'Supply In-charge'
                       AND i.item_name = 'ZZTEST Visibility'")->fetch_assoc()['c'];
$new = $conn->query("SELECT COUNT(*) c FROM inventory i
                     WHERE (i.receiver = 'Supply In-charge' OR i.receiver LIKE '%Supply In-charge%')
                       AND i.item_name = 'ZZTEST Visibility'")->fetch_assoc()['c'];

check('OLD exact filter hid the item (the bug)', (int)$old, 0);
check('FIXED filter finds the item', (int)$new, 1);

echo "\n== 10. an unmarked line still lands in the right table ==\n";
// The PO form no longer has a Location column, so EVERY new line is unmarked.
// This is the guard for that: routing must fall back to the receiving role.
$cases = [
    ['ZZTEST NoMark Supply', 'supply', 'inventory'],
    ['ZZTEST NoMark Property', 'property', 'property_inventory'],
];
$n = 20;
foreach ($cases as [$desc, $role, $table]) {
    $conn->query("INSERT INTO purchase_order_items
                  (po_id, item_number, item_description, quantity, unit_cost, line_total, location, is_received)
                  VALUES ({$po_id}, {$n}, '{$desc}', 3, 2.00, 6.00, NULL, 1)");
    $line = $conn->query("SELECT poi_id FROM purchase_order_items WHERE po_id = {$po_id} AND item_number = {$n}")->fetch_assoc();

    $r = po_post_item_to_inventory($conn, $po_row, [
        'poi_id' => (int)$line['poi_id'],
        'item_description' => $desc,
        'quantity' => 3,
        'unit_cost' => 2.00,
        'location' => '',   // nothing marked: the form no longer sends one
    ], $role, $creator, '2026-01-01 14:00:00');

    $other = ($table === 'inventory') ? 'property_inventory' : 'inventory';
    $hit = (int)$conn->query("SELECT COUNT(*) c FROM `{$table}` WHERE item_name = '{$desc}'")->fetch_assoc()['c'];
    $miss = (int)$conn->query("SELECT COUNT(*) c FROM `{$other}` WHERE item_name = '{$desc}'")->fetch_assoc()['c'];

    check("unmarked line received by '{$role}' -> {$table}", $hit, 1);
    check("unmarked line did NOT leak into {$other}", $miss, 0);
    check("stock recorded for '{$desc}'", $r['status'] !== 'skipped', true);
    $n++;
}

$conn->rollback();
echo "\nrolled back.\n";
$left = $conn->query("SELECT COUNT(*) c FROM purchase_order_items WHERE po_id = {$po_id}")->fetch_assoc()['c'];
check('test rows cleaned up', (int)$left, 0);

echo "\n====================\nPASS: {$pass}   FAIL: {$fail}\n";
exit($fail > 0 ? 1 : 0);