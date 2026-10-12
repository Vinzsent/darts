<?php
/**
 * Printable per-item QR codes for a purchase order.
 *
 * Usage: print_po_qr.php?id=<po_id>
 *   Renders one QR code per line item (encoded with that item's details),
 *   laid out in a cut-friendly grid, and opens the browser print dialog on load.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/qrcode.php';

$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($po_id <= 0) {
    http_response_code(400);
    exit('Invalid purchase order ID.');
}

// Header info
$po = null;
if ($stmt = $conn->prepare("SELECT po_id, po_number, po_date, supplier_name, total_amount, status FROM purchase_orders WHERE po_id = ? LIMIT 1")) {
    $stmt->bind_param('i', $po_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $po = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}
if (!$po) {
    http_response_code(404);
    exit('Purchase order not found.');
}

// Line items
$items = [];
$items_sql = "SELECT poi_id, item_number, item_description, quantity, unit_cost, line_total, is_received, qr_code
              FROM purchase_order_items WHERE po_id = ? ORDER BY item_number ASC";
if ($stmt = $conn->prepare($items_sql)) {
    $stmt->bind_param('i', $po_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = $row;
    }
    $stmt->close();
}

// Compact JSON payload encoded in a single item's QR code.
function itemQrPayload(array $po, array $it): string
{
    return json_encode([
        'po'        => $po['po_number'],
        'supplier'  => $po['supplier_name'],
        'item'      => (string)$it['item_number'],
        'desc'      => (string)$it['item_description'],
        'qty'       => (string)$it['quantity'],
        'unit_cost' => (string)$it['unit_cost'],
        'total'     => (string)$it['line_total'],
    ], JSON_UNESCAPED_UNICODE);
}

// Pre-generate each item's QR as a data URI so the printed page needs no extra requests.
$cards = [];
foreach ($items as $it) {
    $png = !empty($it['qr_code']) ? $it['qr_code'] : null;
    if ($png === null || strlen($png) < 100) {
        try {
            $qr = new QrCode(itemQrPayload($po, $it), QrCode::ECC_MEDIUM);
            $png = $qr->toPng(4, 4);
        } catch (Throwable $e) {
            $png = null;
        }
    }
    $cards[] = [
        'item_number' => $it['item_number'],
        'description' => $it['item_description'],
        'quantity'    => $it['quantity'],
        'line_total'  => $it['line_total'],
        'is_received' => (int)$it['is_received'],
        'data_uri'    => $png ? 'data:image/png;base64,' . base64_encode($png) : '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item QR Codes — <?= htmlspecialchars($po['po_number']) ?></title>
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
            font-weight: 700; font-size: 0.8rem; border-radius: 12px;
            padding: 2px 10px; margin-bottom: 6px;
        }
        .qr-card img {
            width: 100%; max-width: 150px; height: auto; display: block; margin: 6px auto;
        }
        .qr-card .desc {
            font-size: 0.78rem; font-weight: 600; color: #111827; line-height: 1.25;
            min-height: 2.4em; margin-bottom: 4px; word-break: break-word;
        }
        .qr-card .meta { font-size: 0.72rem; color: #4b5563; line-height: 1.4; }
        .qr-card .recv {
            display: inline-block; font-size: 0.65rem; font-weight: 700;
            margin-top: 4px; padding: 1px 8px; border-radius: 10px;
        }
        .recv-yes { background: #28a745; color: #fff; }
        .recv-no { background: #adb5bd; color: #212529; }
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
        <div class="empty">No items found for this purchase order.</div>
    <?php else: ?>
        <div class="qr-grid">
            <?php foreach ($cards as $card): ?>
                <div class="qr-card">
                    <span class="item-no">#<?= htmlspecialchars((string)$card['item_number']) ?></span>
                    <?php if ($card['data_uri']): ?>
                        <img src="<?= $card['data_uri'] ?>" alt="QR code for item #<?= htmlspecialchars((string)$card['item_number']) ?>">
                    <?php else: ?>
                        <div style="font-size:0.7rem;color:#dc3545;margin:10px 0;">QR unavailable</div>
                    <?php endif; ?>
                    <div class="desc"><?= htmlspecialchars((string)$card['description']) ?></div>
                    <div class="meta">
                        Qty: <?= htmlspecialchars((string)$card['quantity']) ?><br>
                        ₱<?= number_format((float)$card['line_total'], 2) ?>
                    </div>
                    <span class="recv <?= $card['is_received'] === 1 ? 'recv-yes' : 'recv-no' ?>">
                        <?= $card['is_received'] === 1 ? 'Received' : 'Pending' ?>
                    </span>
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
