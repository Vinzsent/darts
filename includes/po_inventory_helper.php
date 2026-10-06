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
 *  - Supplier-aware matching: a row is stacked onto only when the item name
 *    matches AND the supplier matches the PO's supplier. A row without a
 *    recorded supplier counts as a match (and gets the PO's supplier
 *    backfilled); a row with a DIFFERENT recorded supplier never matches.
 *  - Conservative fallback: ambiguous matches create a NEW row rather than
 *    silently merging into the wrong bucket (property_inventory has many
 *    duplicate "Paint"/"Screw" rows with differing units).
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
    // Accept BOTH target names ('property' / 'supply') and already-resolved
    // table names ('property_inventory' / 'inventory'). Callers such as
    // po_find_matching_inventory() pass table names; mapping
    // 'property_inventory' back through the old '$target === property' test
    // fell through to 'inventory', so property receives searched the SUPPLY
    // table, never found their own rows, and created duplicates every time.
    if ($target === 'property_inventory') {
        return 'property_inventory';
    }
    return ($target === 'property') ? 'property_inventory' : 'inventory';
}

/**
 * Normalise the free-text `location` mark on a PO line into a canonical bucket.
 *
 * Accepts whatever the user actually types/selects ("Supply", "supply in-charge",
 * "Property", "Property Custodian", ...) and returns 'supply'|'property', or null
 * when nothing usable was marked (null = "let the receiver's role decide").
 */
function po_normalize_location($location)
{
    $s = strtolower(trim((string)$location));
    if ($s === '') {
        return null;
    }
    // "property"/"properties"/"property custodian" -> property
    if (strpos($s, 'prop') !== false) {
        return 'property';
    }
    // "supply"/"supplies"/"supply in-charge" -> supply
    if (strpos($s, 'sup') !== false) {
        return 'supply';
    }
    return null;
}

/**
 * Is the location column deployed? Older databases predate it, so callers must
 * probe instead of assuming the column exists.
 */
function po_location_column_exists($conn)
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $conn->query("SHOW COLUMNS FROM purchase_order_items LIKE 'location'");
    $exists = ($res && $res->num_rows > 0);
    if ($res) {
        $res->free();
    }
    return $exists;
}

/**
 * Where a single PO line belongs.
 *
 * Precedence: the location marked on the line wins, because that is what the
 * user explicitly chose; otherwise fall back to the receiver's role.
 */
function po_resolve_target_for_item($location, $raw_user_type, $requested = null)
{
    $marked = po_normalize_location($location);
    if ($marked !== null) {
        return $marked;
    }
    return po_resolve_target_for_role($raw_user_type, $requested);
}

/**
 * Store a location mark on a PO line, but never overwrite a mark that is already set.
 *
 * Called by the receive actions so a `location` value submitted together with the
 * receive click is persisted on the line instead of being discarded once the stock
 * has been posted — that way the Location column keeps showing Supply/Property.
 *
 * @return bool true when the value was written
 */
function po_apply_location_to_item($conn, $poi_id, $location)
{
    $normalized = po_normalize_location($location);
    $line_id = (int)$poi_id;
    if ($normalized === null || $line_id <= 0 || !po_location_column_exists($conn)) {
        return false;
    }

    $upd = $conn->prepare(
        "UPDATE purchase_order_items
         SET location = ?
         WHERE poi_id = ? AND (location IS NULL OR TRIM(location) = '')"
    );
    if (!$upd) {
        return false;
    }
    // bind_param() needs real variables (passed by reference), not expressions
    $upd->bind_param('si', $normalized, $line_id);
    $ok = $upd->execute();
    $upd->close();
    return (bool)$ok;
}

/** Default category for auto-created rows, matching existing data. */
function po_default_category($table)
{
    return ($table === 'property_inventory') ? 'Uncategorized' : 'Office Supplies (Main/ BED Campus)';
}

/**
 * Normalize a supplier name for comparison (" Ace  Hardware " == "ace hardware").
 */
function po_normalize_supplier_name($name)
{
    $s = strtolower(trim((string)$name));
    return preg_replace('/\s+/', ' ', $s);
}

/**
 * Resolve a supplier_id back to its name, checking the supplier table the
 * given inventory table joins first, then the other one (legacy `inventory`
 * rows carry ids from either supplier table).
 *
 * @return string trimmed name, or '' when the id cannot be resolved
 */
function po_supplier_id_to_name($conn, $table, $supplier_id)
{
    $supplier_id = (int)$supplier_id;
    if ($supplier_id <= 0) {
        return '';
    }
    $primary = ($table === 'property_inventory') ? 'supplier' : 'supply_supplier';
    $secondary = ($table === 'property_inventory') ? 'supply_supplier' : 'supplier';
    foreach ([$primary, $secondary] as $t) {
        $stmt = $conn->prepare("SELECT supplier_name FROM `{$t}` WHERE supplier_id = ? LIMIT 1");
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $supplier_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && trim((string)$row['supplier_name']) !== '') {
            return trim((string)$row['supplier_name']);
        }
    }
    return '';
}

/**
 * Resolve the supplier_id to STAMP on a row created for this inventory table.
 * Only ids from the supplier table the UI joins are stored, so the Supplier
 * column keeps displaying the right name.
 *
 * @return int|null
 */
function po_supplier_id_for_table($conn, $table, $supplier_name)
{
    $name = trim((string)$supplier_name);
    if ($name === '') {
        return null;
    }
    // Primary = the supplier table this inventory page joins (display name
    // resolves directly). Fallback = the other catalog: PO suppliers are
    // chosen from `supplier`, so a supply-inventory row would otherwise lose
    // its supplier entirely when the name is not in `supply_supplier`.
    $primary = ($table === 'property_inventory') ? 'supplier' : 'supply_supplier';
    $secondary = ($table === 'property_inventory') ? 'supply_supplier' : 'supplier';
    foreach ([$primary, $secondary] as $t) {
        $stmt = $conn->prepare(
            "SELECT supplier_id FROM `{$t}`
             WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?))
             ORDER BY supplier_id ASC LIMIT 1"
        );
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return (int)$row['supplier_id'];
        }
    }
    return null;
}

/**
 * Pick the row to increment from a set of name-matching candidates, applying
 * the supplier rule.
 *
 * @param array $rows rows must carry inventory_id, stock and supplier_id
 * @param string|null $po_supplier_name supplier_name taken from the PO
 * @return array|string|null chosen row, 'ambiguous' when several compatible
 *                           rows exist (caller must not guess), or null when
 *                           no candidate is supplier-compatible
 */
function po_pick_supplier_row($conn, $table, $rows, $po_supplier_name)
{
    $po_name = po_normalize_supplier_name($po_supplier_name);

    $compatible = []; // unknown supplier or name-equal
    $exact = [];      // recorded supplier whose name equals the PO's
    foreach ($rows as $r) {
        $sid = (int)($r['supplier_id'] ?? 0);
        $row_name = ($sid > 0)
            ? po_normalize_supplier_name(po_supplier_id_to_name($conn, $table, $sid))
            : '';
        if ($sid <= 0 || $row_name === '') {
            $compatible[] = $r; // no supplier recorded -> unknown, matches
            continue;
        }
        if ($po_name !== '' && $row_name === $po_name) {
            $compatible[] = $r;
            $exact[] = $r;
        }
        // different recorded supplier -> not compatible, skip
    }

    if (count($compatible) === 0) {
        return null; // every name-match belongs to another supplier
    }

    // 1) Same recorded supplier + same name is the strongest signal.
    //    Rows are ordered by inventory_id, so the oldest row absorbs the stock.
    if (count($exact) > 0) {
        return $exact[0];
    }

    // 2) All compatible rows have no supplier recorded yet.
    if (count($compatible) === 1) {
        return $compatible[0];
    }
    if ($po_name !== '') {
        // The PO carries a supplier but the rows don't: stack onto the oldest
        // row; the caller backfills its supplier, so the NEXT receive from a
        // different supplier will no longer match it.
        return $compatible[0];
    }

    // 3) Neither side has a supplier and several rows match: don't guess
    //    (same conservative behaviour the old code had for duplicate names).
    return 'ambiguous';
}

/**
 * Find an existing inventory row for this PO line.
 *
 * Matching is by item name (exact, then normalized) filtered by supplier:
 * same supplier + same name stacks onto the row; a row without a recorded
 * supplier also matches; rows from a different recorded supplier never match.
 *
 * @return array|null ['inventory_id'=>int,'current_stock'=>int,'match'=>'exact'|'normalized'] or null
 */
function po_find_matching_inventory($conn, $table, $item_description, $supplier_name = null)
{
    $safe_table = po_resolve_inventory_table($table);

    // 1) Exact case-insensitive match on the raw description.
    //    LIMIT 50 (was 2): the supplier rule needs every name-matching row to
    //    decide which one is compatible before giving up.
    $sql = "SELECT inventory_id, COALESCE(current_stock,0) AS stock, supplier_id
            FROM `{$safe_table}`
            WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?))
            ORDER BY inventory_id ASC
            LIMIT 50";
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

    if (count($rows) > 0) {
        $pick = po_pick_supplier_row($conn, $safe_table, $rows, $supplier_name);
        if (is_array($pick)) {
            return ['inventory_id' => (int)$pick['inventory_id'],
                    'current_stock' => (int)$pick['stock'],
                    'match' => 'exact'];
        }
        if ($pick === 'ambiguous') {
            // Several compatible rows (common in property_inventory) — do not guess.
            return null;
        }
        // Every exact-name row belongs to a different recorded supplier:
        // fall through to the normalized pass, which may still surface a
        // compatible row whose raw spelling differs.
    }

    // 2) Fall back to a normalised match, accepted only when unambiguous.
    $normalized = po_normalize_item_name($item_description);
    if ($normalized === '') {
        return null;
    }

    $sql2 = "SELECT inventory_id, COALESCE(current_stock,0) AS stock, item_name, supplier_id
             FROM `{$safe_table}`
             WHERE LOWER(TRIM(item_name)) LIKE ?
             ORDER BY inventory_id ASC
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

    if (count($candidates) > 0) {
        $pick = po_pick_supplier_row($conn, $safe_table, $candidates, $supplier_name);
        if (is_array($pick)) {
            return ['inventory_id' => (int)$pick['inventory_id'],
                    'current_stock' => (int)$pick['stock'],
                    'match' => 'normalized'];
        }
        // null (no supplier-compatible row) or 'ambiguous' -> new row
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
 * Build a descriptive receiver string containing the receiver's full name and role.
 * e.g. "Juan Dela Cruz (Property Custodian)"
 */
function po_build_receiver_string($conn, $user_id = null, $target = 'supply')
{
    $name = '';
    $role = '';

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $u = $_SESSION['user'];
        $first = trim((string)($u['first_name'] ?? ''));
        $middle = trim((string)($u['middle_name'] ?? ''));
        $last = trim((string)($u['last_name'] ?? ''));
        $parts = array_filter([$first]);
        if ($middle !== '') {
            $parts[] = strtoupper(substr($middle, 0, 1)) . '.';
        }
        if ($last !== '') {
            $parts[] = $last;
        }
        $name = trim(implode(' ', array_filter($parts)));
        if ($name === '') {
            $name = trim((string)($u['name'] ?? $u['username'] ?? ''));
        }
        $role = trim((string)($u['user_type'] ?? $_SESSION['user_type'] ?? ''));
    }

    if ($name === '' && $user_id) {
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, username, user_type FROM `user` WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $first = trim((string)($row['first_name'] ?? ''));
                $middle = trim((string)($row['middle_name'] ?? ''));
                $last = trim((string)($row['last_name'] ?? ''));
                $parts = array_filter([$first]);
                if ($middle !== '') {
                    $parts[] = strtoupper(substr($middle, 0, 1)) . '.';
                }
                if ($last !== '') {
                    $parts[] = $last;
                }
                $name = trim(implode(' ', array_filter($parts)));
                if ($name === '') {
                    $name = trim((string)($row['username'] ?? ''));
                }
                if ($role === '') {
                    $role = trim((string)($row['user_type'] ?? ''));
                }
            }
            $stmt->close();
        }
    }

    $target_role = ($target === 'property') ? 'Property Custodian' : 'Supply In-charge';
    if ($role === '') {
        $role = $target_role;
    }
    if ($name === '') {
        $name = $role;
    }

    if (strcasecmp($role, $target_role) !== 0 && stripos($role, $target_role) === false) {
        return "{$name} ({$role} - {$target_role})";
    }

    return "{$name} ({$role})";
}

/**
 * Record the movement in the relevant stock log table.
 * Failures here are non-fatal: stock is already updated.
 */
function po_write_stock_log($conn, $table, $inv_id, $qty, $prev, $new, $notes, $user_id, $receiver = '')
{
    $log_table = ($table === 'property_inventory') ? 'property_stock_logs' : 'stock_logs';
    $chk = $conn->query("SHOW TABLES LIKE '{$log_table}'");
    if (!$chk || $chk->num_rows === 0) {
        return;
    }

    $sql = "INSERT INTO `{$log_table}` (inventory_id, movement_type, quantity, previous_stock, new_stock, notes, created_by, receiver)
            VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)";
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
    $p_rec  = (string)$receiver;
    $stmt->bind_param('iiiisis', $p_inv, $p_qty, $p_prev, $p_new, $notes, $p_uid, $p_rec);
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

    // Types (was 'issids'): poi_id=i, inventory_table=s, inventory_id=i,
    // po_id=i, item_name=s, quantity_added=d. The old string bound item_name
    // as a DOUBLE, so 'testing' was stored as 0 and quantity as a string.
    $stmt->bind_param('isiisd', $poi_id, $inv_table, $inv_id,
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
function po_post_item_to_inventory($conn, $po, $item, $target = 'supply', $user_id = null, $received_date = null)
{
    // A location marked on the PO line ("supply" / "property") always wins over the
    // receiver's role, so each line lands in the inventory table that was chosen for it.
    $marked_location = po_normalize_location($item['location'] ?? '');
    if ($marked_location !== null) {
        $target = $marked_location;
    } elseif (po_location_column_exists($conn)) {
        // The line was never marked, so the receiver's role decided. Write that
        // effective destination back to the line, otherwise the Location column
        // would stay NULL forever on items that were received without a mark.
        po_apply_location_to_item($conn, $item['poi_id'] ?? 0, $target);
    }

    $table = po_resolve_inventory_table($target);
    $poi_id = (int)$item['poi_id'];
    $po_id  = (int)$po['po_id'];
    $desc   = trim((string)$item['item_description']);
    $qty    = (int)round((float)$item['quantity']);
    $unit_cost = (float)$item['unit_cost'];
    // The PO's supplier drives the stacking rule: same supplier + same item
    // name adds to the current stock instead of creating another row.
    $po_supplier_name = trim((string)($po['supplier_name'] ?? ''));
    if (empty($received_date)) {
        $received_date = date('Y-m-d H:i:s');
    }

    if ($poi_id <= 0 || $desc === '') {
        return ['status' => 'error', 'message' => 'Missing PO line data.', 'target' => $target];
    }
    if ($qty <= 0) {
        // Nothing physically arrived — record the flag but do not inflate stock.
        return ['status' => 'skipped', 'message' => 'Quantity is 0; not added to stock.', 'target' => $target];
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
            return ['status' => 'skipped', 'message' => 'Already added to inventory.', 'target' => $target];
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
            return ['status' => 'skipped', 'message' => 'Already added to inventory.', 'target' => $target];
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
            $match = po_find_matching_inventory($conn, $table, $desc, $po_supplier_name);
        }
    } else {
        $match = po_find_matching_inventory($conn, $table, $desc, $po_supplier_name);
    }
    $po_number = (string)($po['po_number'] ?? '');

    if ($match) {
        $inv_id = (int)$match['inventory_id'];
        $prev   = (int)$match['current_stock'];
        $new    = $prev + $qty;

        $receiver_str = po_build_receiver_string($conn, $user_id, $target);
        $p_user_id = $user_id ? (int)$user_id : null;

        // Update stock, receiver name/role, date_received, and audit columns.
        $upd = $conn->prepare("UPDATE `{$table}` SET current_stock = ?, receiver = ?, date_received = ?, last_updated_by = ?, date_updated = NOW() WHERE inventory_id = ?");
        if (!$upd) {
            return ['status' => 'error', 'message' => 'DB error: ' . $conn->error];
        }
        $upd->bind_param('issii', $new, $receiver_str, $received_date, $p_user_id, $inv_id);
        if (!$upd->execute()) {
            $msg = 'DB error: ' . $upd->error;
            $upd->close();
            return ['status' => 'error', 'message' => $msg];
        }
        $upd->close();

        // Record the PO's supplier on a row that never had one, so future
        // receives from a different supplier no longer stack onto it.
        $backfill_sid = po_supplier_id_for_table($conn, $table, $po_supplier_name);
        if ($backfill_sid !== null) {
            $bf = $conn->prepare(
                "UPDATE `{$table}` SET supplier_id = ?
                 WHERE inventory_id = ? AND (supplier_id IS NULL OR supplier_id = 0)"
            );
            if ($bf) {
                $bf->bind_param('ii', $backfill_sid, $inv_id);
                $bf->execute();
                $bf->close();
            }
        }

        po_record_item_link($conn, $table, $inv_id, $poi_id, $po_id, $desc, $qty);

        po_write_stock_log($conn, $table, $inv_id, $qty, $prev, $new,
            "Received from PO {$po_number} ({$match['match']} match)", $user_id, $receiver_str);

        return ['status' => 'incremented', 'inventory_id' => $inv_id,
                'previous_stock' => $prev, 'new_stock' => $new, 'match' => $match['match'],
                'target' => $target,
                'message' => "Added {$qty} to existing inventory item."];
    }

    // No confident match — create a new row with receiver name/role and date_received.
    $category = po_default_category($table);
    $receiver_str = po_build_receiver_string($conn, $user_id, $target);
    $brand_label = 'N/A';
    $p_user_id = $user_id ? (int)$user_id : null;
    // Stamp the PO's supplier so the next receive of the same item can find
    // this row (and a different supplier will NOT stack onto it).
    $p_supplier_id = po_supplier_id_for_table($conn, $table, $po_supplier_name);

    $ins = $conn->prepare(
        "INSERT INTO `{$table}` (item_name, category, current_stock, quantity, unit, unit_cost, brand, status, receiver, source_po_id, source_poi_id, date_created, date_received, created_by, supplier_id)
         VALUES (?, ?, ?, ?, 'pcs', ?, ?, 'Active', ?, ?, ?, NOW(), ?, ?, ?)"
    );
    if (!$ins) {
        return ['status' => 'error', 'message' => 'DB error: ' . $conn->error];
    }
    // Placeholders in order: item_name, category, current_stock, quantity,
    // unit_cost, brand, receiver, source_po_id, source_poi_id, date_received,
    // created_by, supplier_id -> "ss"+"ii"+"d"+"s"+"s"+"ii"+"s"+"i"+"i"
    $ins->bind_param('ssiidssiisii', $desc, $category, $qty, $qty, $unit_cost, $brand_label, $receiver_str, $po_id, $poi_id, $received_date, $p_user_id, $p_supplier_id);
    if (!$ins->execute()) {
        $msg = 'DB error: ' . $ins->error;
        $ins->close();
        return ['status' => 'error', 'message' => $msg];
    }
    $new_id = (int)$conn->insert_id;
    $ins->close();

    po_record_item_link($conn, $table, $new_id, $poi_id, $po_id, $desc, $qty);

    po_write_stock_log($conn, $table, $new_id, $qty, 0, $qty,
        "Received from PO {$po_number} (new item)", $user_id, $receiver_str);

    return ['status' => 'created', 'inventory_id' => $new_id, 'new_stock' => $qty,
            'target' => $target,
            'message' => 'Created new inventory item from received PO line.'];
}
