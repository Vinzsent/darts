<?php
$pageTitle = 'QR Scanner';
include '../includes/auth.php';
include '../includes/db.php';
include '../includes/header.php';
?>
<?php include '../includes/sidebar.php'; ?>
<style>
  /* ─── Desktop layout ─────────────────────────────────────────────── */
  @media (min-width: 992px) {
    .scanner-main-content {
      margin-left: 270px;
      max-width: calc(100% - 290px);
      padding-top: 1.5rem;
    }
  }

  /* ─── Mobile layout ──────────────────────────────────────────────── */
  @media (max-width: 991.98px) {
    .scanner-main-content {
      margin-left: 0 !important;
      max-width: 100% !important;
      padding-top: 0 !important;
      padding-left: 0 !important;
      padding-right: 0 !important;
    }

    /* Full bleed scanner card on mobile */
    .scanner-card-wrap {
      border-radius: 0 !important;
      border: none !important;
      box-shadow: none !important;
    }

    /* Full viewport viewfinder on mobile */
    .scanner-viewfinder {
      aspect-ratio: unset !important;
      height: 60vw !important;
      min-height: 280px !important;
      max-height: 380px !important;
      border-radius: 0 !important;
      width: 100% !important;
    }

    .scanner-mobile-header {
      padding: 0.9rem 1rem 0.9rem 4.2rem;
      position: sticky;
      top: 0;
      z-index: 100;
      background: #fff;
      border-bottom: 1px solid #e9ecef;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }

    .scanner-btn-group {
      flex-direction: column !important;
      padding: 0 1rem;
    }

    .scanner-btn-group .btn {
      width: 100% !important;
      min-height: 54px !important;
      font-size: 1.05rem !important;
      border-radius: 14px !important;
    }

    .scanner-card-body {
      padding: 0 !important;
    }

    .scanner-hint-bar {
      margin: 0.85rem 1rem 0;
      border-radius: 12px !important;
    }

    .scanner-actions-wrap {
      padding: 1rem 1rem 1.25rem;
    }

    /* Larger reticle corners on mobile */
    .scanner-reticle-corner {
      width: 32px !important;
      height: 32px !important;
    }
  }

  /* ─── Viewfinder ─────────────────────────────────────────────────── */
  .scanner-viewfinder {
    aspect-ratio: 4 / 3;
    background: #06110a;
    border: none;
    border-radius: 0;
    overflow: hidden;
    position: relative;
    box-shadow: 0 10px 28px rgba(7, 59, 29, 0.2);
    width: 100%;
  }

  /* Camera feed fills full container */
  .scanner-viewfinder #scanner-container,
  .scanner-viewfinder #scanner-container > * {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
  }

  /* ─── Reticle corners ────────────────────────────────────────────── */
  .scanner-reticle-corner {
    position: absolute;
    width: 28px;
    height: 28px;
    border-color: #facc15;
    border-style: solid;
    pointer-events: none;
    z-index: 5;
  }
  .reticle-tl { top: 18px; left: 18px; border-width: 3px 0 0 3px; border-top-left-radius: 6px; }
  .reticle-tr { top: 18px; right: 18px; border-width: 3px 3px 0 0; border-top-right-radius: 6px; }
  .reticle-bl { bottom: 50px; left: 18px; border-width: 0 0 3px 3px; border-bottom-left-radius: 6px; }
  .reticle-br { bottom: 50px; right: 18px; border-width: 0 3px 3px 0; border-bottom-right-radius: 6px; }

  /* ─── Laser scan line ────────────────────────────────────────────── */
  @keyframes scanSweep {
    0%   { top: 12%; opacity: 0.2; }
    50%  { opacity: 1; }
    100% { top: 82%; opacity: 0.2; }
  }

  .scan-laser-line {
    position: absolute;
    left: 6%;
    right: 6%;
    height: 2px;
    background: linear-gradient(90deg, transparent, #22c55e, #facc15, #22c55e, transparent);
    box-shadow: 0 0 10px #22c55e, 0 0 20px rgba(34,197,94,0.4);
    z-index: 4;
    display: none;
    animation: scanSweep 2s ease-in-out infinite alternate;
  }
  .scanner-viewfinder.active .scan-laser-line { display: block; }

  /* ─── Status badge ───────────────────────────────────────────────── */
  .scanner-status-badge {
    position: absolute;
    top: 14px;
    right: 14px;
    z-index: 10;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    color: #073b1d;
    font-weight: 700;
    font-size: 0.78rem;
    padding: 6px 14px;
    border-radius: 50px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
    max-width: 80%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: 0.02em;
  }

  /* ─── Hint overlay ───────────────────────────────────────────────── */
  .scanner-hint-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 10;
    background: linear-gradient(to top, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0.4) 65%, transparent 100%);
    padding: 24px 16px 16px;
    color: #fff;
    text-align: center;
    font-size: 0.85rem;
    line-height: 1.4;
  }

  /* ─── Vignette overlay on viewfinder ────────────────────────────── */
  .scanner-vignette {
    position: absolute;
    inset: 0;
    z-index: 2;
    background: radial-gradient(ellipse at center, transparent 45%, rgba(0,0,0,0.55) 100%);
    pointer-events: none;
  }

  /* ─── Action buttons ─────────────────────────────────────────────── */
  #btn-start {
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
    border: none;
    box-shadow: 0 4px 16px rgba(22,163,74,0.35);
    transition: transform 0.12s, box-shadow 0.12s;
  }
  #btn-start:active { transform: scale(0.97); box-shadow: 0 2px 8px rgba(22,163,74,0.25); }

  #btn-sample {
    border: 2px solid #dee2e6;
    color: #495057;
    transition: background 0.15s, border-color 0.15s;
  }
  #btn-sample:hover { background: #f8f9fa; border-color: #adb5bd; }

  /* ─── Page header strip ─────────────────────────────────────────── */
  .scanner-page-header {
    background: #fff;
  }

  /* Desktop padding restore for card body and actions */
  @media (min-width: 992px) {
    .scanner-card-body { padding: 1.5rem !important; }
    .scanner-actions-wrap { padding: 0; }
    .scanner-hint-bar { margin: 0; }
    .scanner-mobile-header { padding: 1rem 1.5rem 1rem 1.5rem; margin-bottom: 1.5rem; }
    .scanner-viewfinder {
      aspect-ratio: 4/3 !important;
      height: auto !important;
      border-radius: 16px !important;
      min-height: unset !important;
      max-height: unset !important;
    }
  }
</style>

<main class="scanner-main-content">
    <!-- ── Page Header ── -->
    <div class="scanner-mobile-header scanner-page-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
            <div class="bg-success text-white rounded-3 p-2 me-2 d-flex align-items-center justify-content-center shadow-sm" style="width:38px;height:38px;flex-shrink:0;">
                <i class="fas fa-qrcode"></i>
            </div>
            <div>
                <h1 class="h6 mb-0 fw-bold text-dark lh-1">QR Scanner</h1>
                <small class="text-muted" style="font-size:0.72rem;">Aircon Unit Verification</small>
            </div>
        </div>
        <span class="badge rounded-pill" style="background:rgba(22,163,74,0.12);color:#15803d;font-size:0.72rem;padding:5px 10px;border:1px solid rgba(22,163,74,0.25);">
            <i class="fas fa-mobile-alt me-1"></i>Mobile
        </span>
    </div>

    <!-- ── Scanner Card ── -->
    <div class="card border-0 scanner-card-wrap mb-3" style="border-radius:20px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
        <div class="scanner-card-body">

            <!-- Viewfinder -->
            <div id="scanner-wrap" class="scanner-viewfinder">
                <!-- Camera feed -->
                <div id="scanner-container" style="position:absolute;inset:0;width:100%;height:100%;"></div>

                <!-- Vignette overlay -->
                <div class="scanner-vignette"></div>

                <!-- Laser line -->
                <div class="scan-laser-line" id="scan-laser"></div>

                <!-- Reticle corners -->
                <div class="scanner-reticle-corner reticle-tl"></div>
                <div class="scanner-reticle-corner reticle-tr"></div>
                <div class="scanner-reticle-corner reticle-bl"></div>
                <div class="scanner-reticle-corner reticle-br"></div>

                <!-- Status badge -->
                <div id="scanner-status" class="scanner-status-badge">● Ready</div>

                <!-- Hint overlay -->
                <div id="scanner-hint" class="scanner-hint-overlay">
                    <div style="font-size:1.4rem;margin-bottom:4px;">📷</div>
                    <span>Tap <strong id="scanner-title">Start Scanner</strong> and hold a printed QR code inside the frame</span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="scanner-actions-wrap">
                <div class="d-flex scanner-btn-group gap-2 mb-3">
                    <button id="btn-start" class="btn btn-lg btn-success flex-grow-1 fw-semibold d-flex align-items-center justify-content-center" style="min-height:52px;border-radius:14px;font-size:1rem;">
                        <i class="fas fa-camera me-2"></i>Start Scanner
                    </button>
                    <button id="btn-sample" class="btn btn-lg btn-outline-secondary d-flex align-items-center justify-content-center" style="min-height:52px;border-radius:14px;font-size:1rem;">
                        <i class="fas fa-crosshairs me-2"></i>Use sample
                    </button>
                </div>

                <!-- Hint bar -->
                <div class="scanner-hint-bar d-flex align-items-start gap-2 p-3 rounded-3" style="background:linear-gradient(135deg,#f0fdf4,#f8fff9);border:1px solid #bbf7d0;">
                    <i class="fas fa-circle-info mt-1" style="color:#16a34a;font-size:1rem;flex-shrink:0;"></i>
                    <div style="font-size:0.82rem;color:#374151;line-height:1.5;">
                        For best results on phones, <strong>rotate the device horizontally</strong> and ensure the QR code is well lit.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View QR details panel (placed INSIDE main container for responsive width) -->
    <div id="detail-view" class="card shadow-sm border-0 rounded-4 mb-4 d-none">
        <div class="card-body p-3 p-sm-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom gap-2">
                <div class="d-flex align-items-center">
                    <span class="badge bg-primary-subtle text-primary p-2 rounded-3 me-2">
                        <i class="fas fa-check-circle"></i>
                    </span>
                    <h5 class="mb-0 fw-bold">Scanned Unit</h5>
                </div>
                <div class="d-flex flex-wrap gap-2 w-100 w-sm-auto justify-content-end">
                    <button id="btn-rescan" class="btn btn-sm btn-outline-primary flex-grow-1 flex-sm-grow-0 py-2 px-3 rounded-3">
                        <i class="fas fa-redo me-1"></i>Scan again
                    </button>
                    <button id="btn-close" class="btn btn-sm btn-outline-secondary py-2 px-3 rounded-3">
                        Close
                    </button>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12 col-md-4 text-center">
                    <div class="card border bg-light rounded-3 p-3">
                        <div id="qr-wrap" class="mx-auto" style="max-width:200px;">
                            <img id="qr-image" src="" alt="QR code" class="d-none img-fluid rounded-3 shadow-sm">
                            <div id="qr-spinner" class="spinner-border text-primary d-none my-4" role="status"><span class="visually-hidden">Loading QR…</span></div>
                            <div id="qr-empty" class="text-muted small py-4">No QR code stored.</div>
                        </div>
                        <div id="qr-title" class="small fw-bold text-success mt-2"></div>
                    </div>
                </div>

                <div class="col-12 col-md-8">
                    <div class="card border rounded-3">
                        <div class="card-body p-3">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                                <i class="fas fa-info-circle text-primary me-2"></i>Unit Details
                            </h6>
                            <div id="detail-grid" class="row g-2"></div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border rounded-3">
                        <div class="card-body p-3">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                                <i class="fas fa-images text-primary me-2"></i>Unit Pictures
                            </h6>
                            <div id="scan-gallery" class="row g-2"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="../assets/js/html5-qrcode.min.js"></script>
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

        // Check Permissions Policy if supported by browser
        if (document.permissionsPolicy && typeof document.permissionsPolicy.allowsFeature === 'function') {
            if (!document.permissionsPolicy.allowsFeature('camera')) {
                scannerStatus.textContent = '● Camera access is not allowed in this document (requires HTTPS).';
                btnStart.textContent = 'Restart Scanner';
                btnStart.classList.remove('btn-success');
                btnStart.classList.add('btn-danger');
                return;
            }
        }

        // Camera access requires a secure context (HTTPS or localhost)
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            scannerStatus.textContent = '● Camera access requires HTTPS or localhost.';
            btnStart.textContent = 'Restart Scanner';
            btnStart.classList.remove('btn-success');
            btnStart.classList.add('btn-danger');
            return;
        }

        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            scannerStatus.textContent = '● Camera API is not supported in this browser context.';
            btnStart.textContent = 'Restart Scanner';
            btnStart.classList.remove('btn-success');
            btnStart.classList.add('btn-danger');
            return;
        }

        scannerStatus.textContent = '● Requesting camera access...';
        scannerTitle.textContent = 'Scanning...';

        Html5Qrcode.getCameras().then(function (cameras) {
            if (!cameras || cameras.length === 0) {
                throw new Error('No camera found on this device.');
            }
            // Prefer environment (rear) camera, fall back to first available (e.g. webcam)
            var envCam = cameras.find(function (c) { return c.facing === 'environment'; });
            var cameraConfig = envCam ? envCam.id : cameras[0].id;
            html5QrCode = new Html5Qrcode('scanner-container');
            return html5QrCode.start(cameraConfig, { fps: 10, qrbox: 260 }, onDecode, onError).then(function () {
                var wrap = document.getElementById('scanner-wrap');
                if (wrap) wrap.classList.add('active');
                scannerStatus.textContent = '● Camera active. Hold QR code in frame';
                scannerTitle.textContent = 'Scanning…';
                btnStart.textContent = 'Stop Scanner';
                btnStart.classList.remove('btn-success', 'btn-danger');
                btnStart.classList.add('btn-warning');
            });
        }).catch(function (err) {
            var wrap = document.getElementById('scanner-wrap');
            if (wrap) wrap.classList.remove('active');
            var msg = err && err.message ? err.message : 'Camera unavailable';
            if (msg.indexOf('environment') !== -1 || msg.indexOf('facing') !== -1 ||
                msg.indexOf('constraint') !== -1) {
                msg = 'No rear camera found. Grant camera access and retry.';
            }
            scannerStatus.textContent = '● ' + msg;
            btnStart.textContent = 'Restart Scanner';
            btnStart.classList.remove('btn-success', 'btn-warning');
            btnStart.classList.add('btn-danger');
        });
    }
    function stopScanner() {
        var wrap = document.getElementById('scanner-wrap');
        if (wrap) wrap.classList.remove('active');
        if (!html5QrCode) return;
        html5QrCode.stop().then(function () {
            html5QrCode.destroy();
            html5QrCode = null;
            scannerStatus.textContent = '● Ready';
            scannerTitle.textContent = 'Start Scanner';
            btnStart.textContent = 'Start Scanner';
            btnStart.classList.remove('btn-danger', 'btn-warning');
            btnStart.classList.add('btn-success');
        }).catch(function () { /* ignore */ });
    }

    function onDecode(decodedText) {
        if (scanTimer) { clearTimeout(scanTimer); scanTimer = null; }
        var wrap = document.getElementById('scanner-wrap');
        if (wrap) wrap.classList.remove('active');
        scannerStatus.textContent = '● QR Code detected! Loading…';

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
        // Html5Qrcode triggers this callback on every video frame (10 times/second) when no QR code is in view.
        // Ignore normal scan detection misses and keep status clean.
        var str = String(err || '');
        if (str.indexOf('No MultiFormat Readers') !== -1 ||
            str.indexOf('NotFoundException') !== -1 ||
            str.indexOf('No barcode or QR code detected') !== -1 ||
            str.indexOf('parse error') !== -1) {
            if (scannerStatus.textContent !== '● Camera active. Hold QR code in frame') {
                scannerStatus.textContent = '● Camera active. Hold QR code in frame';
            }
            return;
        }
        if (err && err.message && err.message.indexOf('Callback invocation failed') !== -1) return;
        scannerStatus.textContent = '● ' + str;
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
