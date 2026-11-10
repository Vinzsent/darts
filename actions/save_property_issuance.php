<?php
require_once __DIR__ . '/../includes/auth.php';
session_start();
require_once __DIR__ . '/../includes/db.php';

// ── Role gate: Property Custodian / Supply In-charge / Admin issue slips ──
$raw_role = $_SESSION['user_type'] ?? ($_SESSION['user']['user_type'] ?? '');
$role = str_replace([' ', '-'], '', strtolower($raw_role));
$allowed_roles = ['propertycustodian', 'supplyincharge', 'admin', 'superadmin'];
if (!in_array($role, $allowed_roles, true)) {
    $_SESSION['error'] = 'Access denied. You do not have permission to create issuance slips.';
    header('Location: ../pages/property_issuance.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/property_issuance.php');
    exit;
}

// ── Gather + validate the slip header ──────────────────────────────────────
$transferring_dept = trim($_POST['transferring_dept'] ?? '');
$receiving_dept    = trim($_POST['receiving_dept'] ?? '');
$reason_transfer   = trim($_POST['reason_transfer'] ?? '');
$transfer_date     = trim($_POST['transfer_date'] ?? '');
$transfer_time     = trim($_POST['transfer_time'] ?? '');
$items             = $_POST['items'] ?? [];

$errors = [];
if ($transferring_dept === '') $errors[] = 'Transferring Department/Unit/Office is required.';
if ($receiving_dept === '')    $errors[] = 'Receiving Department/Unit/Office is required.';
if ($reason_transfer === '')   $errors[] = 'Reason of Transfer is required.';

$dt = DateTime::createFromFormat('Y-m-d', $transfer_date);
if (!$dt || $dt->format('Y-m-d') !== $transfer_date) $errors[] = 'Date of Transfer is invalid.';
$tt = DateTime::createFromFormat('H:i', $transfer_time);
if (!$tt || $tt->format('H:i') !== $transfer_time) $errors[] = 'Time is invalid.';

if (!is_array($items) || count($items) === 0) $errors[] = 'At least one item is required.';
if (is_array($items) && count($items) > 100)  $errors[] = 'Too many item rows (max 100).';

// ── Parse + validate the item rows ─────────────────────────────────────────
// item_id carries the source table: "property:<id>" or "supply:<id>".
$parsed = [];
if (is_array($items)) {
    foreach ($items as $row) {
        $qty = (int)($row['quantity'] ?? 0);
        $key = trim((string)($row['item_id'] ?? ''));
        if ($key === '' && $qty <= 0) continue; // empty row

        if (!preg_match('/^(property|supply):(\d+)$/', $key, $m)) {
            $errors[] = 'Invalid item selection detected.';
            break;
        }
        $src = $m[1];
        $iid = (int)$m[2];

        // Server-side source enforcement: the dropdown is role-filtered, but a
        // hand-crafted POST must not issue from the other role's inventory.
        if ($role === 'propertycustodian' && $src !== 'property') {
            $errors[] = 'You can only issue items from the property inventory.';
            break;
        }
        if ($role === 'supplyincharge' && $src !== 'supply') {
            $errors[] = 'You can only issue items from the supply inventory.';
            break;
        }
        if ($qty < 1) {
            $errors[] = 'Item quantities must be at least 1.';
            break;
        }

        $parsed[] = [
            'src'               => $src,
            'id'                => $iid,
            'qty'               => $qty,
            'description'       => trim((string)($row['description'] ?? '')),
            'serial_number'     => trim((string)($row['serial_number'] ?? '')),
            'transferring_code' => trim((string)($row['transferring_code'] ?? '')),
            'receiving_code'    => trim((string)($row['receiving_code'] ?? '')),
        ];
    }
}
if (count($parsed) === 0 && count($errors) === 0) $errors[] = 'At least one item is required.';

if (!empty($errors)) {
    $_SESSION['error'] = implode(' ', $errors);
    header('Location: ../pages/property_issuance.php');
    exit;
}

$created_by = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));

// ── Save everything atomically: header, stock decrements, movement logs ──
$conn->begin_transaction();
try {
    // 1) Slip header
    $stmt = $conn->prepare("INSERT INTO property_issuance
        (transferring_dept, receiving_dept, reason_transfer, transfer_date, transfer_time, created_by)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sssssi', $transferring_dept, $receiving_dept, $reason_transfer, $transfer_date, $transfer_time, $created_by);
    $stmt->execute();
    $stmt->close();
    $issuance_id = $conn->insert_id;

    // 2) Reusable per-item statements
    $selProp = $conn->prepare("SELECT item_name, current_stock FROM property_inventory WHERE inventory_id = ? FOR UPDATE");
    $updProp = $conn->prepare("UPDATE property_inventory SET current_stock = current_stock - ? WHERE inventory_id = ?");
    $logProp = $conn->prepare("INSERT INTO property_stock_logs
        (inventory_id, movement_type, quantity, previous_stock, new_stock, notes, created_by, receiver)
        VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)");
    $selSup  = $conn->prepare("SELECT item_name, current_stock FROM inventory WHERE inventory_id = ? FOR UPDATE");
    $updSup  = $conn->prepare("UPDATE inventory SET current_stock = current_stock - ? WHERE inventory_id = ?");
    $logSup  = $conn->prepare("INSERT INTO stock_logs
        (inventory_id, movement_type, quantity, previous_stock, new_stock, notes, created_by, receiver)
        VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)");
    $insItem = $conn->prepare("INSERT INTO property_issuance_items
        (issuance_id, source, inventory_id, item_name, quantity, description, serial_number, transferring_code, receiving_code)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($parsed as $it) {
        if ($it['src'] === 'property') {
            $sel = $selProp; $upd = $updProp; $log = $logProp;
        } else {
            $sel = $selSup;  $upd = $updSup;  $log = $logSup;
        }

        // Lock + read the current stock (row-locked until commit)
        $sid = $it['id'];
        $sel->bind_param('i', $sid);
        $sel->execute();
        $itemRow = $sel->get_result()->fetch_assoc();
        if (!$itemRow) throw new Exception("Item no longer exists ({$it['src']} #{$sid}).");

        $previous = (int)$itemRow['current_stock'];
        if ($previous < $it['qty']) {
            throw new Exception("Insufficient stock for '{$itemRow['item_name']}'. Available: {$previous}, requested: {$it['qty']}.");
        }
        $new = $previous - $it['qty'];

        // Decrement
        $upd->bind_param('ii', $it['qty'], $sid);
        $upd->execute();

        // Movement log
        $notes = "Issued via slip #{$issuance_id} to {$receiving_dept}";
        if ($reason_transfer !== '') $notes .= " — {$reason_transfer}";
        $log->bind_param('iiiisis', $sid, $it['qty'], $previous, $new, $notes, $created_by, $receiving_dept);
        $log->execute();

        // Slip line item (item_name snapshotted from the DB row)
        $iname = (string)$itemRow['item_name'];
        $src   = $it['src'];
        $qty   = $it['qty'];
        $desc  = $it['description'];
        $ser   = $it['serial_number'];
        $tcode = $it['transferring_code'];
        $rcode = $it['receiving_code'];
        $insItem->bind_param('isisissss', $issuance_id, $src, $sid, $iname, $qty, $desc, $ser, $tcode, $rcode);
        $insItem->execute();
    }

    $selProp->close(); $updProp->close(); $logProp->close();
    $selSup->close();  $updSup->close();  $logSup->close();
    $insItem->close();

    $conn->commit();

    $_SESSION['message'] = "Property Issuance/Transfer Slip #{$issuance_id} saved successfully. Stock levels and movement logs have been updated.";
    header('Location: ../pages/property_issuance.php');
    exit;

} catch (Throwable $e) {
    // Discard everything: stock decrements, logs, and the slip header
    $conn->rollback();
    $_SESSION['error'] = 'Failed to save the issuance slip: ' . $e->getMessage();
    header('Location: ../pages/property_issuance.php');
    exit;
}
