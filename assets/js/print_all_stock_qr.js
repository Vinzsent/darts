/* ── Shared "Print All QR" flow with a live progress window ──────────────
   Used by:
     - Inventory.php            -> printAllStockQr('supply')
     - property_inventory.php   -> printAllStockQr('property')
     - property_inventory.php   -> printAllStockQr('fire')  (Fire Extinguisher)

   The print window is opened synchronously inside the button click (popup
   blockers allow that), then each row's QR image is fetched individually
   from the view_*_qr endpoints — which always generate the QR fresh from
   the live row — while the window shows a "Loaded X of N" progress counter.
   When all labels are ready the sheet is rendered and the print dialog
   opens. */

var stockQrPrintWindow = null;

function escapeHtmlStockQr(text) {
    if (text === null || text === undefined) return '';
    var div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function stockQrSheetStyles() {
    return '<style>body { padding: 20px; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; } h1 { text-align: center; color: #073b1d; margin-bottom: 30px; } .qr-print-item { page-break-inside:avoid; margin-bottom:20px; border:1px solid #dee2e6; border-radius:8px; padding:15px; display:inline-block; width:240px; vertical-align:top; text-align:center; background:#fff; } .qr-print-item img { width:120px; height:120px; object-fit:contain; border:1px solid #dee2e6; border-radius:6px; background:#fff; padding:6px; } .print-header { text-align:center; margin-bottom:30px; } @media print { body { padding: 0; } .no-print { display:none; } }</style>';
}

function stockQrLabelHtml(type, item) {
    var esc = escapeHtmlStockQr;
    var html = '<div class="qr-print-item" style="page-break-inside:avoid; margin-bottom:20px; border:1px solid #dee2e6; border-radius:8px; padding:15px; display:inline-block; width:240px; vertical-align:top; text-align:center;">';
    html += '<img src="' + item.imgUrl + '" alt="QR Code" style="width:120px;height:120px;object-fit:contain;border:1px solid #dee2e6;border-radius:6px;background:#fff;padding:6px;">';
    html += '<div class="mt-3">';
    html += '<small class="text-muted d-block">Code: <strong style="font-size:0.85rem; word-break:break-all;">' + esc(item.code) + '</strong></small>';
    html += '<small class="text-muted d-block">Item: <strong>' + esc(item.item) + '</strong></small>';
    if (type === 'fire') {
        html += '<small class="text-muted d-block">Serial: ' + esc(item.serial) + '</small>';
        html += '<small class="text-muted d-block">' + esc(item.brand) + ' ' + esc(item.model) + '</small>';
        html += '<small class="text-muted d-block">Agent: ' + esc(item.agent) + ' | Size: ' + esc(item.size) + '</small>';
        html += '<small class="text-muted d-block">' + esc([item.building, item.floor, item.area].filter(Boolean).join(', ')) + '</small>';
        html += '<small class="text-muted d-block">' + esc(item.location) + '</small>';
        html += '<small class="text-muted d-block">Status: <strong>' + esc(item.status) + '</strong>' + (item.expires ? ' | Expires: ' + esc(item.expires) : '') + '</small>';
    } else {
        if (item.category) html += '<small class="text-muted d-block">' + esc(item.category) + '</small>';
        if (item.brand) html += '<small class="text-muted d-block">' + esc(item.brand) + '</small>';
        html += '<small class="text-muted d-block">Qty: <strong>' + esc(item.qty) + ' ' + esc(item.unit) + '</strong></small>';
        html += '<small class="text-muted d-block">' + esc(item.location) + '</small>';
        html += '<small class="text-muted d-block">Status: <strong>' + esc(item.status) + '</strong></small>';
    }
    html += '</div></div>';
    return html;
}

function printAllStockQr(type) {
    var titles = { property: 'Property Item QR Codes', supply: 'Supplies QR Codes', fire: 'Fire Extinguisher QR Codes' };

    // Open the print window synchronously inside the click gesture so popup
    // blockers allow it (opening after the async fetches gets blocked).
    stockQrPrintWindow = window.open('', 'stockQrPrint', 'height=800,width=1200');
    if (!stockQrPrintWindow) {
        alert('The print window was blocked by your browser.\nPlease allow pop-ups for this site and try again.');
        return;
    }
    try {
        stockQrPrintWindow.document.write('<html><head><title>' + (titles[type] || 'QR Codes') + '</title>');
        stockQrPrintWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
        stockQrPrintWindow.document.write(stockQrSheetStyles());
        stockQrPrintWindow.document.write('</head><body>');
        stockQrPrintWindow.document.write('<div class="print-header"><h1><i class="fas fa-qrcode me-2"></i>' + (titles[type] || 'QR Codes') + '</h1><p class="text-muted" id="qr-progress">Preparing… please keep this window open.</p></div>');
        stockQrPrintWindow.document.write('<div class="qr-print-grid" id="qr-print-grid"></div>');
        stockQrPrintWindow.document.write('</body></html>');
        stockQrPrintWindow.document.close();
    } catch (e) {
        console.error('Print window write error:', e);
    }

    fetch('../actions/get_stock_qr_list.php?type=' + encodeURIComponent(type), { credentials: 'same-origin' })
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(items) {
            if (!Array.isArray(items)) {
                throw new Error((items && items.message) ? items.message : 'Unexpected response.');
            }
            if (items.length === 0) {
                alert('No items found for this QR sheet.');
                return;
            }
            var qrItems = [], pending = 0;
            var totalQr = items.length;
            var updateProgress = function() {
                try {
                    if (stockQrPrintWindow && !stockQrPrintWindow.closed) {
                        var el = stockQrPrintWindow.document.getElementById('qr-progress');
                        if (el) el.textContent = 'Loaded ' + (totalQr - pending) + ' of ' + totalQr + ' QR codes…';
                    }
                } catch (e) { /* window navigated away */ }
            };
            items.forEach(function(item) {
                var endpoint = (type === 'fire')
                    ? '../actions/view_fire_extinguisher_qr.php?id=' + encodeURIComponent(item.id)
                    : '../actions/view_stock_qr.php?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(item.id);
                pending++;
                fetch(endpoint + '&t=' + Date.now(), { credentials: 'same-origin' })
                    .then(function(res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.blob(); })
                    .then(function(blob) {
                        var label = Object.assign({}, item, { imgUrl: URL.createObjectURL(blob) });
                        qrItems.push(label);
                        pending--;
                        updateProgress();
                        if (pending === 0) renderStockQrPrint(type, qrItems);
                    })
                    .catch(function(err) {
                        console.error('QR fetch error for ' + item.id + ':', err);
                        pending--;
                        updateProgress();
                        if (pending === 0) renderStockQrPrint(type, qrItems);
                    });
            });
        })
        .catch(function(err) {
            console.error('Fetch stock QR list error:', err);
            alert('Failed to load the QR list: ' + err.message);
        });
}

function renderStockQrPrint(type, items) {
    var printWindow = stockQrPrintWindow;
    if (!printWindow || printWindow.closed) {
        alert('The print window was closed before the QR codes were ready. Please try again.');
        return;
    }
    if (items.length === 0) {
        alert('No QR codes could be loaded.');
        return;
    }
    items.sort(function(a, b) { return parseInt(a.id, 10) - parseInt(b.id, 10); });
    var printHtml = '';
    items.forEach(function(item) {
        printHtml += stockQrLabelHtml(type, item);
    });
    var titles = { property: 'Property Item QR Codes', supply: 'Supplies QR Codes', fire: 'Fire Extinguisher QR Codes' };
    try {
        printWindow.document.open();
        printWindow.document.write('<html><head><title>' + (titles[type] || 'QR Codes') + '</title>');
        printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
        printWindow.document.write(stockQrSheetStyles());
        printWindow.document.write('</head><body>');
        printWindow.document.write('<div class="print-header"><h1><i class="fas fa-qrcode me-2"></i>' + (titles[type] || 'QR Codes') + '</h1><p class="text-muted">Total: ' + items.length + ' label(s) | Printed: ' + new Date().toLocaleString() + '</p></div>');
        printWindow.document.write('<div class="qr-print-grid">' + printHtml + '</div>');
        printWindow.document.write('<div class="text-center mt-4 no-print"><button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i> Print</button><button class="btn btn-secondary ms-2" onclick="window.close()"><i class="fas fa-times me-1"></i> Close</button></div>');
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.focus();
        setTimeout(function() { try { printWindow.print(); } catch (e) {} }, 500);
    } catch (e) {
        console.error('Print render error:', e);
        alert('Could not render the print window. Please allow pop-ups for this site and try again.');
    }
}
