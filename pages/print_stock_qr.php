<?php
/**
 * Printable QR label sheet for inventory / property stock rows.
 *
 * Usage: print_stock_qr.php?type=property|supply[&id=<inventory_id>]
 *   - No id  -> one QR card per row of that table (respects ?search= filter).
 *   - With id -> a single row's label (used by the QR modal's Print button).
 *   Cards are pre-rendered as data URIs so the printed page needs no extra
 *   requests, and window.print() fires automatically on load.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/stock_qr.php';

$type = isset($_GET['type']) ? (string)$_GET['type'] : '';
$table = stock_qr_table($type);
if ($table === null) {
    http_response_code(400);
    exit('Invalid QR sheet type (expected property or supply).');
}

$one_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$where = [];
$params = [];
$types = '';
if ($one_id > 0) {
    $where[] = 'inventory_id = ?';
    $params[] = $one_id;
    $types .= 'i';
} elseif ($search !== '') {
    $where[] = 'item_name LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}
$sql = "SELECT inventory_id, item_name, category, brand, current_stock, unit,
               location, status, qr_code
        FROM `$table`"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY item_name ASC';

$rows = [];
if ($stmt = $conn->prepare($sql)) {
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();
}

// Pre-render each row's QR fresh from the current row data as a data URI,
// so printed labels always carry the item's latest details.
$cards = [];
foreach ($rows as $row) {
    $png = stock_qr_png($conn, $type, (int)$row['inventory_id']);
    $cards[] = [
        'id'       => (int)$row['inventory_id'],
        'name'     => (string)$row['item_name'],
        'category' => (string)($row['category'] ?? ''),
        'brand'    => (string)($row['brand'] ?? ''),
        'qty'      => (string)$row['current_stock'],
        'unit'     => (string)($row['unit'] ?? ''),
        'location' => (string)($row['location'] ?? ''),
        'status'   => (string)($row['status'] ?? ''),
        'data_uri' => $png ? 'data:image/png;base64,' . base64_encode($png) : '',
    ];
}

$sheet_title = $table === 'property_inventory' ? 'Property Item QR Codes' : 'Supplies QR Codes';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($sheet_title) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #073b1d; margin: 0; padding: 16px; background: #f8f9fa;
        }
        .qr-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
        }
        .qr-card {
            border: 1px solid #c9d3cc; border-radius: 8px; background: #fff;
            padding: 10px; text-align: center;
            break-inside: avoid; page-break-inside: avoid;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
        }
        .qr-card .item-no {
            display: inline-block; background: #073b1d; color: #EACA26;
            font-weight: 700; font-size: 0.72rem; border-radius: 12px;
            padding: 2px 10px; margin-bottom: 6px; max-width: 100%;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .qr-card img {
            width: 100%; max-width: 150px; height: auto; display: block; margin: 6px auto;
        }
        .qr-card .desc {
            font-size: 0.78rem; font-weight: 600; color: #111827; line-height: 1.25;
            min-height: 2.4em; margin-bottom: 4px; word-break: break-word;
        }
        .qr-card .meta { font-size: 0.72rem; color: #4b5563; line-height: 1.4; }
        .qr-card .status {
            display: inline-block; font-size: 0.65rem; font-weight: 700;
            margin-top: 4px; padding: 1px 8px; border-radius: 10px;
            background: #e9ecef; color: #212529;
        }
        .toolbar { margin-bottom: 14px; text-align: right; }
        .toolbar button {
            background: linear-gradient(135deg, #073b1d, #0a4f28);
            color: #EACA26; border: none; padding: 8px 18px;
            border-radius: 6px; font-weight: 600; cursor: pointer;
        }
        .toolbar button:hover { color: #fff; }
        .empty { text-align: center; color: #6c757d; padding: 40px; }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            @page { margin: 10mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>

    <?php if (empty($cards)): ?>
        <div class="empty">No items found<?= $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : '' ?>.</div>
    <?php else: ?>
        <div class="qr-grid">
            <?php foreach ($cards as $card): ?>
                <div class="qr-card">
                    <span class="item-no"><?= htmlspecialchars($card['name'] !== '' ? $card['name'] : ('#' . $card['id'])) ?></span>
                    <?php if ($card['data_uri']): ?>
                        <img src="<?= $card['data_uri'] ?>" alt="QR code for <?= htmlspecialchars($card['name']) ?>">
                    <?php else: ?>
                        <div style="font-size:0.7rem;color:#dc3545;margin:10px 0;">QR unavailable</div>
                    <?php endif; ?>
                    <div class="desc"><?= htmlspecialchars($card['category'] !== '' ? $card['category'] : '—') ?></div>
                    <div class="meta">
                        Qty: <?= htmlspecialchars($card['qty']) ?> <?= htmlspecialchars($card['unit']) ?>
                        <?= $card['brand'] !== '' ? '<br>' . htmlspecialchars($card['brand']) : '' ?>
                        <?= $card['location'] !== '' ? '<br>' . htmlspecialchars($card['location']) : '' ?>
                    </div>
                    <?php if ($card['status'] !== ''): ?>
                        <span class="status"><?= htmlspecialchars($card['status']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
        window.addEventListener('load', function() {
            setTimeout(function() { window.print(); }, 300);
        });
    </script>
</body>

</html>
