<?php
$pageTitle = 'QR Scanner';
include '../includes/auth.php';
include '../includes/db.php';
include '../includes/header.php';
?>
<?php include '../includes/sidebar.php'; ?>

<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="fas fa-qrcode text-primary mr-2"></i>QR Scanner</h1>
        <span class="badge bg-secondary">Mobile</span>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div id="scanner-wrap" class="position-relative" style="aspect-ratio:4/3;background:#0b1214;border:2px solid #dee2e6;border-radius:14px;overflow:hidden;">
                <div id="scanner-container" class="position-absolute inset-0 w-100 h-100"></div>
                <div id="scanner-hint" class="position-absolute bottom-0 start-0 end-0 p-3 bg-black/60 text-white text-center" style="border-top:1px solid rgba(255,255,255,.18);">
                    <small>Tap <strong id="scanner-title">Start Scanner</strong> and hold a printed QR code inside the frame</small>
                </div>
                <div id="scanner-status" class="position-absolute top-3 end-3 px-2 py-1 bg-white text-dark rounded-3 text-small shadow-sm">● Ready</div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button id="btn-start" class="btn btn-lg btn-success px-4"><i class="fas fa-camera mr-2"></i>Start Scanner</button>
                <button id="btn-sample" class="btn btn-outline-secondary btn-lg"><i class="fas fa-crosshairs mr-2"></i>Use sample</button>
            </div>
            <p class="text-muted small mt-2"><i class="fas fa-mobile-alt mr-1"></i>For best results on phones, rotate the device horizontally and ensure the QR code is well lit.</p>
        </div>
    </div>
</main>

<!-- View QR details panel (shown after a successful scan) -->
<div id="detail-view" class="card shadow-sm border-0 d-none">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <h5 class="mb-0"><i class="fas fa-search text-primary mr-2"></i>Scanned Unit</h5>
            <div class="d-flex flex-wrap gap-2">
                <button id="btn-rescan" class="btn btn-sm btn-outline-primary"><i class="fas fa-redo mr-1"></i>Scan again</button>
                <button id="btn-close" class="btn btn-sm btn-outline-secondary">Close</button>
            </div>
        </div>

        <div class="row">
            <div class="col-12 mb-3">
                <div class="card border-0 bg-white rounded-3 overflow-hidden">
                    <div class="card-body p-3 text-center">
                        <div id="qr-wrap" class="mx-auto" style="max-width:220px;">
                            <img id="qr-image" src="" alt="QR code" class="d-none" style="max-width:100%;height:auto;border-radius:10px;">
                            <div id="qr-spinner" class="spinner-border text-primary d-none" role="status"><span class="visually-hidden">Loading QR…</span></div>
                            <div id="qr-empty" class="text-muted small">No QR code stored for this unit.</div>
                        </div>
                        <div id="qr-title" class="small text-muted mt-2"></div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card border-0 bg-white rounded-3">
                    <div class="card-body">
                        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-id-card text-primary me-2"></i>Unit Details</h6>
                        <div id="detail-grid" class="row g-3"></div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card border-0 bg-white rounded-3">
                    <div class="card-body">
                        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-images text-primary me-2"></i>Pictures</h6>
                        <div id="scan-gallery" class="row g-2"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/dist/html5-qrcode.min.js"></script>
<style>
    .aircon-default-pic {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        min-height: 120px;
        border: 2px dashed #dee2e6;
        border-radius: 10px;
        background: #f8f9fa;
        cursor: default;
        padding: 12px;
        text-align: center;
    }
    .aircon-default-pic img {
        width: 100px;
        height: 100px;
        object-fit: contain;
        color: var(--primary-green);
    }
    .aircon-default-caption {
        margin-top: 6px;
        font-size: 0.75rem;
        color: #6c757d;
        max-width: 220px;
    }
</style>
<script>
(function () {
    'use strict';

    var scannerStatus = document.getElementById('scanner-status');
    var scannerTitle = document.getElementById('scanner-title');
    var btnStart = document.getElementById('btn-start');
    var btnSample = document.getElementById('btn-sample');
    var btnRescan = document.getElementById('btn-rescan');
    var btnClose = document.getElementById('btn-close');
    var detailView = document.getElementById('detail-view');
    var qrImage = document.getElementById('qr-image');
    var qrSpinner = document.getElementById('qr-spinner');
    var qrEmpty = document.getElementById('qr-empty');
    var qrTitle = document.getElementById('qr-title');
    var detailGrid = document.getElementById('detail-grid');
    var scanGallery = document.getElementById('scan-gallery');

    var html5QrCode = null;
    var scanTimer = null;

    var FIELD_MAP = [
        ['aircon_id', 'Aircon ID'],
        ['item_number', 'Item Number'],
        ['brand', 'Brand'],
        ['model', 'Model'],
        ['type', 'Type'],
        ['capacity', 'Capacity (BTU/hr)'],
        ['serial_number', 'Serial Number'],
        ['location', 'Location'],
        ['area', 'Area'],
        ['status', 'Status'],
        ['campus', 'Campus'],
        ['lifespan', 'Lifespan (years)'],
        ['date_created', 'Date Created'],
        ['date_updated', 'Date Updated']
    ];

    function startScanner() {
        if (scanTimer) { clearTimeout(scanTimer); scanTimer = null; }
        if (html5QrCode) { stopScanner(); return; }

        scannerStatus.textContent = '● Requesting camera access…';
        scannerTitle.textContent = 'Scanning…';

        Html5Qrcode.getCameras().then(function (cameras) {
            var facing = (cameras.length > 0) ? { exact: 'environment' } : undefined;
            html5QrCode = new Html5Qrcode('scanner-container');
            return html5QrCode.start(facing, { fps: 10, qrbox: 260 }, onDecode, onError);
        }).catch(function (err) {
            scannerStatus.textContent = '● ' + (err && err.message ? err.message : 'Camera unavailable');
            btnStart.textContent = 'Restart Scanner';
            btnStart.classList.remove('btn-success');
            btnStart.classList.add('btn-danger');
        });
    }

    function stopScanner() {
        if (!html5QrCode) return;
        html5QrCode.stop().then(function () {
            html5QrCode.destroy();
            html5QrCode = null;
            scannerStatus.textContent = '● Ready';
            scannerTitle.textContent = 'Start Scanner';
            btnStart.textContent = 'Start Scanner';
            btnStart.classList.remove('btn-danger');
            btnStart.classList.add('btn-success');
        }).catch(function () { /* ignore */ });
    }

    function onDecode(decodedText) {
        if (scanTimer) { clearTimeout(scanTimer); scanTimer = null; }
        scannerStatus.textContent = '● Scanning…';

        try {
            var data = JSON.parse(decodedText);
            var id = data && data.id;
            if (!id) {
                showDetailError('The scanned code is not a valid aircon QR.');
                return;
            }
            loadUnit(String(id));
        } catch (e) {
            showDetailError('Could not parse the scanned QR code.');
        }
    }

    function onError(err) {
        if (scanTimer) { clearTimeout(scanTimer); scanTimer = null; }
        if (err && err.message && err.message.indexOf('Callback invocation failed') !== -1) return;
        scannerStatus.textContent = '● ' + String(err);
    }

    function loadUnit(id) {
        var url = '../actions/view_aircon_details.php?id=' + encodeURIComponent(id);
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data.success) throw new Error(data.message || 'Aircon not found.');
                renderDetail(data.aircon);
                renderQr(data.aircon.aircon_id);
                renderGallery(data.aircon.aircon_id);
            })
            .catch(function (err) {
                showDetailError(err && err.message ? err.message : 'Could not load unit details.');
            });
    }

    function renderDetail(a) {
        detailView.classList.remove('d-none');
        qrTitle.textContent = 'Aircon # ' + (a.aircon_id || '-');

        var grid = detailGrid;
        grid.innerHTML = '';
        FIELD_MAP.forEach(function (pair) {
            var key = pair[0];
            var label = pair[1];
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-center';

            var lc = document.createElement('div');
            lc.className = 'col-12 col-sm-3';
            var lab = document.createElement('label');
            lab.className = 'text-muted small d-block';
            lab.textContent = label;
            lc.appendChild(lab);

            var vc = document.createElement('div');
            vc.className = 'col-12 col-sm-9';
            var val = document.createElement('p');
            val.className = 'fw-bold mb-0';
            val.textContent = (key === 'aircon_id') ? '-' :
                (a[key] || a[key] === 0 ? a[key] : '-');
            vc.appendChild(val);

            row.appendChild(lc);
            row.appendChild(vc);
            grid.appendChild(row);
        });

        addField(grid, a, 'date_created', 'Date Created');
        addField(grid, a, 'date_updated', 'Date Updated');
    }

    function addField(grid, a, key, label) {
        var row = document.createElement('div');
        row.className = 'row g-2 align-items-center';
        var lc = document.createElement('div');
        lc.className = 'col-12 col-sm-3';
        var lp = document.createElement('label');
        lp.className = 'text-muted small d-block';
        lp.textContent = label;
        lc.appendChild(lp);
        var vc = document.createElement('div');
        vc.className = 'col-12 col-sm-9';
        var vp = document.createElement('p');
        vp.className = 'fw-bold mb-0';
        vp.textContent = formatDate(a[key]) || 'N/A';
        vc.appendChild(vp);
        row.appendChild(lc);
        row.appendChild(vc);
        grid.appendChild(row);
    }

    function formatDate(dateString) {
        if (!dateString || dateString === '0000-00-00') return 'N/A';
        var date = new Date(dateString);
        if (isNaN(date.getTime())) return 'N/A';
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function renderQr(airconId) {
        qrImage.classList.add('d-none');
        qrSpinner.classList.remove('d-none');
        qrEmpty.classList.add('d-none');

        fetch('../actions/view_aircon_qr.php?id=' + encodeURIComponent(airconId) + '&regen=1&t=' + Date.now(), {
            credentials: 'same-origin'
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.blob();
            })
            .then(function (blob) {
                qrImage.src = URL.createObjectURL(blob);
                qrImage.classList.remove('d-none');
                qrSpinner.classList.add('d-none');
            })
            .catch(function () {
                qrImage.classList.add('d-none');
                qrSpinner.classList.add('d-none');
                qrEmpty.classList.remove('d-none');
            });
    }

    function renderGallery(airconId) {
        scanGallery.innerHTML = '<div class="col-12"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading pictures…</span></div></div>';

        fetch('../actions/get_aircon_images.php?aircon_id=' + encodeURIComponent(airconId), {
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                scanGallery.innerHTML = '';
                if (data.success && data.images.length > 0) {
                    data.images.forEach(function (imgUrl) {
                        var col = document.createElement('div');
                        col.className = 'col-12 col-sm-6 col-md-4 col-xl-3';

                        var wrap = document.createElement('div');
                        wrap.style.position = 'relative';
                        wrap.style.height = '150px';

                        var img = document.createElement('img');
                        img.src = '../' + imgUrl;
                        img.alt = 'Aircon Picture';
                        img.className = 'img-thumbnail';
                        img.style.width = '100%';
                        img.style.height = '100%';
                        img.style.objectFit = 'cover';
                        img.style.cursor = 'pointer';
                        img.addEventListener('click', function () { window.open('../' + imgUrl, '_blank'); });

                        wrap.appendChild(img);
                        col.appendChild(wrap);
                        scanGallery.appendChild(col);
                    });
                } else {
                    var empty = document.createElement('div');
                    empty.className = 'col-12';
                    empty.innerHTML =
                        '<div class="aircon-default-pic" title="No picture uploaded for this unit">' +
                        '<img src="../uploads/DCC2.png" alt="DCC2 default logo">' +
                        '<div class="aircon-default-caption">No picture for this unit</div></div>';
                    scanGallery.appendChild(empty);
                }
            })
            .catch(function () {
                scanGallery.innerHTML = '<div class="col-12"><p class="text-danger small mb-0">Could not load pictures.</p></div>';
            });
    }

    function showDetailError(message) {
        qrEmpty.textContent = message;
        qrEmpty.style.display = 'block';
        qrImage.classList.add('d-none');
        qrSpinner.classList.add('d-none');
    }

    btnStart.addEventListener('click', startScanner);
    btnSample.addEventListener('click', function () {
        fetch('../actions/view_aircon_details.php?id=1', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) { showDetailError('Sample unit not found.'); return; }
                renderDetail(data.aircon);
                renderQr(data.aircon.aircon_id);
                renderGallery(data.aircon.aircon_id);
                detailView.classList.remove('d-none');
                qrTitle.textContent = 'Aircon # ' + data.aircon.aircon_id;
            })
            .catch(function () { showDetailError('Sample load failed.'); });
    });
    btnRescan.addEventListener('click', function () {
        stopScanner();
        detailView.classList.add('d-none');
        scanGallery.innerHTML = '';
        detailGrid.innerHTML = '';
        qrImage.classList.add('d-none');
        qrSpinner.classList.add('d-none');
        qrEmpty.classList.add('d-none');
        qrTitle.textContent = '';
    });
    btnClose.addEventListener('click', function () {
        stopScanner();
        detailView.classList.add('d-none');
        scanGallery.innerHTML = '';
        detailGrid.innerHTML = '';
        qrImage.classList.add('d-none');
        qrSpinner.classList.add('d-none');
        qrEmpty.classList.add('d-none');
        qrTitle.textContent = '';
    });

    scannerStatus.textContent = '● Ready';
})();
</script>