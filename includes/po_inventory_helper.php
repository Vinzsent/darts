<?php
/**
 * Post received purchase-order items into inventory.
 *
 * Receiving a PO only used to flag purchase_order_items.is_received, so a fully
 * received item never became searchable for release. These helpers push the
 * quantity into `inventory` (supply) or `property_inventory` (property) and
 * record the movement in the stock log table.
 *
 * Design rules:
 *  - Idempotent: each PO line is posted at most once, guarded by the
 *    uniq_*_source_poi unique index plus an explicit lookup.
 *  - Conservative matching: only an unambiguous existing row is incremented.
 *    Ambiguous or unmatched items create a NEW row rather than silently
 *    merging into the wrong bucket (property_inventory has many duplicate
 *    "Paint"/"Screw" rows with differing units).
 *  - Never destructive: stock is only ever increased here.
 */

/**
 * Normalise a description so cosmetic differences don't defeat matching.
 * "# 3/8 Drill Bit Metal" and "3/8 drill bit metal" both become "38 drill bit metal".
 */
function po_normalize_item_name($name)
{
    $s = strtolower(trim((string)$name));
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);   // punctuation/space -> single space
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/** Which inventory table a PO item belongs to (defaults to supply). */
function po_resolve_inventory_table($target)
{
    return ($target === 'property') ? 'property_inventory' : 'inventory';
}

/** Default category for auto-created rows, matching existing data. */
function po_default_category($table)
{
    return ($table === 'property_inventory') ? 'Uncategorized' : 'Office Supplies (Main/ BED Campus)';
}

/**
 * Find an existing inventory row for this PO line.
 *
 * @return array|null ['inventory_id'=>int,'current_stock'=>int,'match'=>'exact'|'normalized'] or null
 */
function po_find_matching_inventory($conn, $table, $item_description)
{
    $safe_table = po_resolve_inventory_table($table);

    // 1) Exact case-insensitive match on the raw description.
    $sql = "SELECT inventory_id, COALESCE(current_stock,0) AS stock
            FROM `{$safe_table}`
            WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?))
            LIMIT 2";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $item_description);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();

    // Exactly one exact match is safe to increment.
    if (count($rows) === 1) {
        return ['inventory_id' => (int)$rows[0]['inventory_id'],
                'current_stock' => (int)$rows[0]['stock'],
                'match' => 'exact'];
    }
    if (count($rows) > 1) {
        // Several identical rows (common in property_inventory) — do not guess.
        return null;
    }

    // 2) Fall back to a normalised match, accepted only when unambiguous.
    $normalized = po_normalize_item_name($item_description);
    if ($normalized === '') {
        return null;
    }

    $sql2 = "SELECT inventory_id, COALESCE(current_stock,0) AS stock, item_name
             FROM `{$safe_table}`
             WHERE LOWER(TRIM(item_name)) LIKE ?
             LIMIT 50";
    $stmt2 = $conn->prepare($sql2);
    if (!$stmt2) {
        return null;
    }
    $like = '%' . $normalized . '%';
    $stmt2->bind_param('s', $like);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $candidates = [];
    while ($r2 = $res2->fetch_assoc()) {
        if (po_normalize_item_name($r2['item_name']) === $normalized) {
            $candidates[] = $r2;
        }
    }
    $stmt2->close();

    if (count($candidates) === 1) {
        return ['inventory_id' => (int)$candidates[0]['inventory_id'],
                'current_stock' => (int)$candidates[0]['stock'],
                'match' => 'normalized'];
    }

    return null; // zero or ambiguous -> caller creates a new row
}
/**
 * Decide which inventory table a received item should be posted into,
 * based on the logged-in user's role.
 *
 *   Supply In-charge  -> `inventory`            (supply office stock)
 *   Property Custodian-> `property_inventory`  (property office stock)
 *
 * The role is authoritative: the `target` sent by the browser is only honoured
 * for roles that don't map to a specific office (e.g. Admin, Purchasing Officer),
 * where the caller has to pick. Without this a client could post property goods
 * into the supply inventory (or vice-versa) by editing the request.
 *
 * @return string 'supply' or 'property'
 */
function po_resolve_target_for_role($user_type, $requested = null)
{
    $normalized = str_replace([' ', '-'], '', strtolower((string)$user_type));

    switch ($normalized) {
        case 'supplyincharge':
        case 'supplyoffice':
            return 'supply';

        case 'propertycustodian':
        case 'propertyoffice':
            return 'property';

        default:
            // Unmapped role (admin, purchasing officer, ...): fall back to the
            // caller's choice, defaulting to supply.
            return ($requested === 'property') ? 'property' : 'supply';
    }
}

/**
 * Record the movement in the relevant stock log table.
 * Failures here are non-fatal: stock is already updated.
 */
function po_write_stock_log($conn, $table, $inv_id, $qty, $prev, $new, $notes, $user_id)
{
    $log_table = ($table === 'property_inventory') ? 'property_stock_logs' : 'stock_logs';
    $chk = $conn->query("SHOW TABLES LIKE '{$log_table}'");
    if (!$chk || $chk->num_rows === 0) {
        return;
    }

    $sql = "INSERT INTO `{$log_table}` (inventory_id, movement_type, quantity, previous_stock, new_stock, notes, created_by)
            VALUES (?, 'IN', ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    // bind_param requires variables by reference, so every value must be a
    // plain variable (inline casts and expressions raise ArgumentCountError).
    $p_inv  = (int)$inv_id;
    $p_qty  = (int)$qty;
    $p_prev = (int)$prev;
    $p_new  = (int)$new;
    $p_uid  = $user_id ? (int)$user_id : 0;
    $stmt->bind_param('iiiisi', $p_inv, $p_qty, $p_prev, $p_new, $notes, $p_uid);
    $stmt->execute();
    $stmt->close();
}

/**
 * Remember that a PO line was posted into a specific inventory row.
 *
 * An inventory row can hold only ONE source_poi_id (UNIQUE index), so when several
 * lines of the same PO land on one shared row, only the first can use that column.
 * This table keeps the remaining lines protected against double-posting.
 *
 * Failures are non-fatal: stock is already updated by the time this runs.
 */
function po_record_item_link($conn, $table, $inventory_id, $poi_id, $po_id, $item_name, $qty)
{
    if ($poi_id <= 0) {
        return false;
    }
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO po_item_inventory_links (poi_id, inventory_table, inventory_id, po_id, item_name, quantity_added)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return false;
    }
    // bind_param needs variables passed by reference, so nothing may be inlined.
    $inv_table = (string)$table;
    $inv_id    = (int)$inventory_id;
    $po_id_val = (int)$po_id;
    $name      = (string)$item_name;
    $qty_val   = (float)$qty;

    $stmt->bind_param('issids', $poi_id, $inv_table, $inv_id,
                       $po_id_val, $name, $qty_val);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

/**
 * Post a single received PO line into inventory.
 *
 * @return array ['status' => 'created'|'incremented'|'skipped'|'error', 'message'=>string, ...]
 */
function po_post_item_to_inventory($conn, $po, $item, $target = 'supply', $user_id = null)
{
    $table = po_resolve_inventory_table($target);
    $poi_id = (int)$item['poi_id'];
    $po_id  = (int)$po['po_id'];
    $desc   = trim((string)$item['item_description']);
    $qty    = (int)round((float)$item['quantity']);
    $unit_cost = (float)$item['unit_cost'];

    if ($poi_id <= 0 || $desc === '') {
        return ['status' => 'error', 'message' => 'Missing PO line data.'];
    }
    if ($qty <= 0) {
        // Nothing physically arrived — record the flag but do not inflate stock.
        return ['status' => 'skipped', 'message' => 'Quantity is 0; not added to stock.'];
    }

    // Idempotency guard, part 1: the link table. A PO line that was merged into a
    // shared inventory row is recorded here, because the inventory row can only
    // carry ONE source_poi_id and would otherwise lose its protection.
    $lk = $conn->prepare("SELECT inventory_id FROM po_item_inventory_links WHERE poi_id = ? LIMIT 1");
    if ($lk) {
        $lk->bind_param('i', $poi_id);
        $lk->execute();
        $lk_res = $lk->get_result();
        if ($lk_res && $lk_res->num_rows > 0) {
            $lk->close();
            return ['status' => 'skipped', 'message' => 'Already added to inventory.'];
        }
        $lk->close();
    }

    // Idempotency guard, part 2: the direct source_poi_id on the inventory row.
    $chk = $conn->prepare("SELECT inventory_id FROM `{$table}` WHERE source_poi_id = ? LIMIT 1");
    if ($chk) {
        $chk->bind_param('i', $poi_id);
        $chk->execute();
        $chk_res = $chk->get_result();
        if ($chk_res && $chk_res->num_rows > 0) {
            $chk->close();
            return ['status' => 'skipped', 'message' => 'Already added to inventory.'];
        }
        $chk->close();
    }

    // Prefer a row already posted from THIS same PO with the same description.
    // A PO often repeats an item on several lines; without this, each repeat would
    // create yet another row instead of adding to the first one.
    $sib = $conn->prepare(
        "SELECT inventory_id, COALESCE(current_stock,0) AS stock
         FROM `{$table}`
         WHERE source_po_id = ? AND LOWER(TRIM(item_name)) = LOWER(TRIM(?))
         ORDER BY inventory_id ASC LIMIT 1"
    );
    if ($sib) {
        $sib->bind_param('is', $po_id, $desc);
        $sib->execute();
        $sib_res = $sib->get_result();
        if ($sib_res && $sib_row = $sib_res->fetch_assoc()) {
            $sib->close();
            $match = ['inventory_id' => (int)$sib_row['inventory_id'],
                      'current_stock' => (int)$sib_row['stock'],
                      'match' => 'same-po'];
        } else {
            $sib->close();
            $match = po_find_matching_inventory($conn, $table, $desc);
        }
    } else {
        $match = po_find_matching_inventory($conn, $table, $desc);
    }
    $po_number = (string)($po['po_number'] ?? '');

    if ($match) {
        $inv_id = (int)$match['inventory_id'];
        $prev   = (int)$match['current_stock'];
        $new    = $prev + $qty;

        // Backfill receiver too — a matched row created before this fix may have
        // a NULL receiver and would stay hidden behind the page's receiver filter.
        $receiver_label = ($table === 'property_inventory') ? 'Property Custodian' : 'Supply In-charge';
        // Do NOT overwrite source_poi_id here: it is UNIQUE and already belongs to
        // the row's first PO line. This line is tracked in po_item_inventory_links.
        $upd = $conn->prepare("UPDATE `{$table}` SET current_stock = ?, receiver = COALESCE(NULLIF(receiver, ''), ?) WHERE inventory_id = ?");
        if (!$upd) {
            return ['status' => 'error', 'message' => 'DB error: ' . $conn->error];
        }
        $upd->bind_param('isi', $new, $receiver_label, $inv_id);
        if (!$upd->execute()) {
            $msg = 'DB error: ' . $upd->error;
            $upd->close();
            return ['status' => 'error', 'message' => $msg];
        }
        $upd->close();

        po_record_item_link($conn, $table, $inv_id, $poi_id, $po_id, $desc, $qty);

        po_write_stock_log($conn, $table, $inv_id, $qty, $prev, $new,
            "Received from PO {$po_number} ({$match['match']} match)", $user_id);

        return ['status' => 'incremented', 'inventory_id' => $inv_id,
                'previous_stock' => $prev, 'new_stock' => $new, 'match' => $match['match'],
                'message' => "Added {$qty} to existing inventory item."];
    }

    // No confident match — create a new row so the item is at least findable.
    $category = po_default_category($table);
    // `receiver` matters: property_inventory.php filters on
    // receiver = 'Property Custodian', so a row posted without it would be invisible.
    $receiver_label = ($table === 'property_inventory') ? 'Property Custodian' : 'Supply In-charge';
    // PO lines carry no brand, and a NULL brand triggers "Passing null to
    // htmlspecialchars()" deprecation warnings wherever the row is displayed.
    $brand_label = 'N/A';
    $ins = $conn->prepare(
        "INSERT INTO `{$table}` (item_name, category, current_stock, quantity, unit, unit_cost, brand, status, receiver, source_po_id, source_poi_id, date_created)
         VALUES (?, ?, ?, ?, 'pcs', ?, ?, 'Active', ?, ?, ?, NOW())"
    );
    if (!$ins) {
        return ['status' => 'error', 'message' => 'DB error: ' . $conn->error];
    }
    // Placeholders in order: item_name, category, current_stock, quantity,
    // unit_cost, brand, receiver, source_po_id, source_poi_id -> "ss"+"ii"+"d"+"s"+"s"+"ii"
    $ins->bind_param('ssiidssii', $desc, $category, $qty, $qty, $unit_cost, $brand_label, $receiver_label, $po_id, $poi_id);
    if (!$ins->execute()) {
        $msg = 'DB error: ' . $ins->error;
        $ins->close();
        return ['status' => 'error', 'message' => $msg];
    }
    $new_id = (int)$conn->insert_id;
    $ins->close();

    po_record_item_link($conn, $table, $new_id, $poi_id, $po_id, $desc, $qty);

    po_write_stock_log($conn, $table, $new_id, $qty, 0, $qty,
        "Received from PO {$po_number} (new item)", $user_id);

    return ['status' => 'created', 'inventory_id' => $new_id, 'new_stock' => $qty,
            'message' => 'Created new inventory item from received PO line.'];
}
