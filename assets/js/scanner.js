/**
 * DARTS QR Scanner Module
 * Based on qr_attendance and attendance scanning workflows:
 * - Live camera scanning with auto rear camera selection
 * - HTTPS / localhost capability check & user notices
 * - Manual search / handheld barcode scanner input with Enter auto-submit
 * - QR image file upload scanning via Html5Qrcode.scanFile
 * - Instant aircon details rendering, QR preview, and gallery loading
 * - Recent scans session log
 */
(function () {
    'use strict';

    var html5QrCode = null;
    var isScanning = false;
    var isTransitioning = false;  // guard: true while start/stop is in-flight
    var recentScans = [];
    var lastItemType = 'Aircon Unit';

    // DOM Elements
    var scannerStatus = document.getElementById('scanner-status');
    var cameraNotice = document.getElementById('cameraNotice');
    var scannerDebugBox = document.getElementById('scannerDebugBox');
    var scannerDebugMsg = document.getElementById('scannerDebugMsg');
    var btnCopyDebug = document.getElementById('btn-copy-debug');
    var btnDismissDebug = document.getElementById('btn-dismiss-debug');
    var scannerBox = document.getElementById('scannerBox');
    var cameraPlaceholder = document.getElementById('cameraPlaceholder');
    var scanLaser = document.getElementById('scan-laser');
    var btnToggleCamera = document.getElementById('btn-toggle-camera');
    var cameraBtnText = document.getElementById('camera-btn-text');
    var btnDemoScan = document.getElementById('btn-demo-scan');
    var qrInput = document.getElementById('qr_input');
    var btnSubmitManual = document.getElementById('btn-submit-manual');
    var qrImage = document.getElementById('qrImage');

    // Details elements
    var emptyPlaceholder = document.getElementById('empty-placeholder');
    var unitDetailCard = document.getElementById('unit-detail-card');
    var unitStatusBadge = document.getElementById('unit-status-badge');
    var unitTitle = document.getElementById('unit-title');
    var unitSubtitle = document.getElementById('unit-subtitle');
    var qrPreviewImg = document.getElementById('qr-preview-img');
    var qrPreviewSpinner = document.getElementById('qr-preview-spinner');
    var qrPreviewEmpty = document.getElementById('qr-preview-empty');
    var detailGrid = document.getElementById('detail-grid');
    var scanGallery = document.getElementById('scan-gallery');
    var btnRescan = document.getElementById('btn-rescan');
    var btnClear = document.getElementById('btn-clear');
    var recentScansBody = document.getElementById('recent-scans-body');
    var recentScansEmpty = document.getElementById('recent-scans-empty');
    var scanCountBadge = document.getElementById('scan-count-badge');

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

    function showNotice(msg, type) {
        if (!cameraNotice) return;
        if (!msg) {
            cameraNotice.classList.add('d-none');
            return;
        }
        cameraNotice.className = 'alert alert-' + (type || 'warning') + ' small py-2 px-3 mb-3';
        cameraNotice.innerHTML = msg;
        cameraNotice.classList.remove('d-none');
    }

    // --- Detailed Debug Error Handler ---
    function showDebugError(context, err) {
        if (!scannerDebugBox || !scannerDebugMsg) return;

        var errName = err ? (err.name || (err.constructor && err.constructor.name) || 'Error') : 'UnknownError';
        var errMsg = '';
        if (typeof err === 'string') {
            errMsg = err;
        } else if (err && err.message) {
            errMsg = err.message;
        } else {
            try { errMsg = JSON.stringify(err); } catch(e) { errMsg = String(err); }
        }

        var lines = [
            '[' + new Date().toLocaleTimeString() + '] Context: ' + (context || 'Scanner'),
            'Error: ' + errName + (errMsg ? ' - ' + errMsg : ''),
            'Secure Context: ' + (window.isSecureContext ? 'Yes (HTTPS/localhost)' : 'NO (Camera will be blocked by browser!)'),
            'Protocol: ' + location.protocol + ' | Host: ' + location.hostname,
            'MediaDevices API: ' + (navigator.mediaDevices && navigator.mediaDevices.getUserMedia ? 'Supported' : 'NOT Supported'),
            'User Agent: ' + navigator.userAgent
        ];

        if (err && err.stack) {
            lines.push('Stack Trace:\n' + err.stack.split('\n').slice(0, 3).join('\n'));
        }

        scannerDebugMsg.textContent = lines.join('\n');
        scannerDebugBox.classList.remove('d-none');
        try { scannerDebugBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch(e) {}
    }

    function clearDebugError() {
        if (scannerDebugBox) scannerDebugBox.classList.add('d-none');
        if (scannerDebugMsg) scannerDebugMsg.textContent = '';
    }

    function setStatus(text, bgClass) {
        if (!scannerStatus) return;
        scannerStatus.textContent = text;
        scannerStatus.className = 'badge rounded-pill px-3 py-1 ' + (bgClass || 'bg-success-subtle text-success border border-success-subtle');
    }

    // Camera Support Check (HTTPS / Localhost)
    function checkCameraSupport() {
        var isSecure = location.protocol === 'https:' ||
                       location.hostname === 'localhost' ||
                       location.hostname === '127.0.0.1' ||
                       location.hostname.endsWith('.test');

        if (!isSecure) {
            showNotice('<i class="fas fa-exclamation-triangle me-1"></i>Live camera scanning requires HTTPS or localhost. You can still type the ID or upload a QR image.', 'warning');
        }
        return isSecure;
    }

    // --- Helpers to lock/unlock the toggle button during async transitions ---
    function lockBtn() {
        isTransitioning = true;
        if (btnToggleCamera) btnToggleCamera.disabled = true;
    }

    function unlockBtn(active) {
        isTransitioning = false;
        if (!btnToggleCamera) return;
        btnToggleCamera.disabled = false;
        if (active) {
            btnToggleCamera.classList.remove('btn-success');
            btnToggleCamera.classList.add('btn-danger');
            if (cameraBtnText) cameraBtnText.textContent = 'Stop Scanner';
        } else {
            btnToggleCamera.classList.remove('btn-danger');
            btnToggleCamera.classList.add('btn-success');
            if (cameraBtnText) cameraBtnText.textContent = 'Start Scanner';
        }
    }

    // Start Scanner
    function startScanner() {
        if (isTransitioning) return;  // already starting or stopping – ignore

        if (!window.Html5Qrcode) {
            showNotice('QR scanner library is still loading. Please wait a moment.', 'info');
            return;
        }

        checkCameraSupport();

        // If already scanning, stop first
        if (isScanning && html5QrCode) {
            stopScanner();
            return;
        }

        lockBtn();
        setStatus('● Starting camera…', 'bg-warning-subtle text-warning border border-warning-subtle');

        // Destroy any stale instance so the browser re-prompts for permission
        if (html5QrCode) {
            try { html5QrCode.clear(); } catch(e) {}
            html5QrCode = null;
        }
        html5QrCode = new Html5Qrcode('scanner-container');

        var scanConfig = {
            fps: 20,
            qrbox: function (width, height) {
                var minEdge = Math.min(width, height);
                var boxSize = Math.max(220, Math.floor(minEdge * 0.85));
                return { width: boxSize, height: boxSize };
            },
            experimentalFeatures: { useBarCodeDetectorIfSupported: true }
        };

        function onCameraStarted() {
            isScanning = true;
            unlockBtn(true);
            if (cameraPlaceholder) cameraPlaceholder.classList.add('d-none');
            if (scannerBox) scannerBox.classList.add('active');
            if (scanLaser) scanLaser.style.display = 'block';
            setStatus('● Camera active', 'bg-success-subtle text-success border border-success-subtle');
            showNotice('', '');
            clearDebugError();
        }

        function onCameraError(err) {
            console.warn('Camera start error:', err);
            isScanning = false;
            html5QrCode = null;
            unlockBtn(false);
            setStatus('● Camera error', 'bg-danger-subtle text-danger border border-danger-subtle');
            showNotice('<i class="fas fa-lock me-1"></i>Camera permission denied or not accessible. See debug error details below.', 'danger');
            showDebugError('Camera Start Error', err);
        }

        function onScanSuccess(decodedText) {
            stopScanner();
            handleScanSuccess(decodedText);
        }

        function onScanError() { /* per-frame miss — normal */ }

        // Try rear/environment camera first; fall back to camera enumeration
        var cameraConstraint = { facingMode: { ideal: 'environment' } };

        html5QrCode.start(cameraConstraint, scanConfig, onScanSuccess, onScanError)
            .then(onCameraStarted)
            .catch(function () {
                Html5Qrcode.getCameras()
                    .then(function (cameras) {
                        if (!cameras || !cameras.length) {
                            var noCamErr = new Error('No camera hardware found on this device.');
                            noCamErr.name = 'NotFoundError';
                            onCameraError(noCamErr);
                            return;
                        }
                        var camId = cameras[0].id;
                        for (var i = 0; i < cameras.length; i++) {
                            var lbl = (cameras[i].label || '').toLowerCase();
                            if (lbl.indexOf('back') !== -1 || lbl.indexOf('rear') !== -1 || lbl.indexOf('environment') !== -1) {
                                camId = cameras[i].id;
                                break;
                            }
                        }
                        return html5QrCode.start(camId, scanConfig, onScanSuccess, onScanError)
                            .then(onCameraStarted)
                            .catch(onCameraError);
                    })
                    .catch(onCameraError);
            });
    }

    // Stop Scanner
    function stopScanner() {
        if (!html5QrCode || isTransitioning) return;
        lockBtn();
        html5QrCode.stop()
            .then(function () {
                try { html5QrCode.destroy(); } catch(e) {}
                html5QrCode = null;
                isScanning = false;
                if (cameraPlaceholder) cameraPlaceholder.classList.remove('d-none');
                if (scannerBox) scannerBox.classList.remove('active');
                if (scanLaser) scanLaser.style.display = 'none';
                unlockBtn(false);
                setStatus('● Ready', 'bg-success-subtle text-success border border-success-subtle');
            })
            .catch(function () {
                html5QrCode = null;
                isScanning = false;
                unlockBtn(false);
            });
    }

    // Handle Scan or Search Success
    function handleScanSuccess(raw) {
        if (!raw) return;
        var query = String(raw).trim();
        if (!query) return;

        setStatus('● Loading unit…', 'bg-primary-subtle text-primary border border-primary-subtle');

        loadUnit(query);
    }

    // Load Unit Details from Server
    function loadUnit(query) {
        var url = '../actions/view_aircon_details.php?query=' + encodeURIComponent(query);

        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.message || 'Item not found.');
                }
                lastItemType = data.item_type || 'Aircon Unit';
                renderUnitDetails(data.aircon, lastItemType);
                addToRecentScans(data.aircon, lastItemType);
                setStatus('● Unit loaded', 'bg-success-subtle text-success border border-success-subtle');
            })
            .catch(function (err) {
                setStatus('● Not found', 'bg-danger-subtle text-danger border border-danger-subtle');
                showDebugError('Item Lookup ("' + query + '")', err);
                alert(err && err.message ? err.message : 'Item not found in any inventory.');
            });
    }

    // Format Date
    function formatDate(dateString) {
        if (!dateString || dateString === '0000-00-00') return 'N/A';
        var date = new Date(dateString);
        if (isNaN(date.getTime())) return 'N/A';
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // Render Unit Details
    function renderUnitDetails(a, itemType) {
        if (!a) return;
        itemType = itemType || 'Aircon Unit';

        if (emptyPlaceholder) emptyPlaceholder.classList.add('d-none');
        if (unitDetailCard) unitDetailCard.classList.remove('d-none');

        // Titles and status
        if (unitTitle) unitTitle.textContent = (a.brand || '') + ' ' + (a.model || '') + ' (' + (a.type || itemType) + ')';
        var idLabel = itemType === 'Aircon Unit' ? 'Aircon' : itemType === 'Fire Extinguisher' ? 'FE' : (itemType === 'Property Item' ? 'Property' : 'Item');
        if (unitSubtitle) unitSubtitle.textContent = idLabel + ' #' + (a.aircon_id || '-') + ' • ' + (a.serial_number ? 'Serial: ' + a.serial_number : a.item_number || '');

        if (unitStatusBadge) {
            var st = (a.status || 'Active').toLowerCase();
            var bClass = 'bg-success text-white';
            if (st.indexOf('repair') !== -1 || st.indexOf('maintenance') !== -1) {
                bClass = 'bg-warning text-dark';
            } else if (st.indexOf('dispose') !== -1 || st.indexOf('condemn') !== -1 || st.indexOf('inactive') !== -1) {
                bClass = 'bg-danger text-white';
            }
            unitStatusBadge.className = 'badge rounded-pill px-3 py-2 ' + bClass;
            unitStatusBadge.textContent = a.status || 'Active';
        }

        // Render Fields
        if (detailGrid) {
            detailGrid.innerHTML = '';
            FIELD_MAP.forEach(function (pair) {
                var key = pair[0];
                var label = pair[1];

                var col = document.createElement('div');
                col.className = 'col-sm-6';

                var box = document.createElement('div');
                box.className = 'p-2 rounded bg-light border';

                var lab = document.createElement('div');
                lab.className = 'text-muted small';
                lab.textContent = label;

                var val = document.createElement('div');
                val.className = 'fw-bold text-dark text-truncate';

                if (key === 'date_created' || key === 'date_updated') {
                    val.textContent = formatDate(a[key]);
                } else {
                    val.textContent = (a[key] !== null && a[key] !== undefined && a[key] !== '') ? a[key] : '-';
                }

                box.appendChild(lab);
                box.appendChild(val);
                col.appendChild(box);
                detailGrid.appendChild(col);
            });
        }

        // Render QR Code preview
        renderQrPreview(a.aircon_id, itemType);

        // Render Pictures Gallery (aircon only)
        if (itemType === 'Aircon Unit') {
            renderGallery(a.aircon_id);
        } else {
            if (scanGallery) scanGallery.innerHTML = '<div class="col-12 text-center py-3 text-muted small"><i class="fas fa-image fa-2x mb-2 text-secondary opacity-50 d-block"></i>No photos for this item type.</div>';
        }

        // Scroll into view on mobile
        if (window.innerWidth < 992 && unitDetailCard) {
            unitDetailCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    // Render QR Code Preview
    function renderQrPreview(itemId, itemType) {
        if (!qrPreviewImg || !qrPreviewSpinner || !qrPreviewEmpty) return;

        qrPreviewImg.classList.add('d-none');
        qrPreviewSpinner.classList.remove('d-none');
        qrPreviewEmpty.classList.add('d-none');

        // Route to correct QR endpoint based on item type
        var endpoint;
        if (itemType === 'Aircon Unit') {
            endpoint = '../actions/view_aircon_qr.php?id=' + encodeURIComponent(itemId) + '&regen=1&t=' + Date.now();
        } else if (itemType === 'Fire Extinguisher') {
            endpoint = '../actions/view_fire_extinguisher_qr.php?id=' + encodeURIComponent(itemId) + '&t=' + Date.now();
        } else {
            // Property / Supply - use the generic item QR (qr_code column value rendered as image)
            // Show nothing if no dedicated endpoint
            qrPreviewImg.classList.add('d-none');
            qrPreviewSpinner.classList.add('d-none');
            qrPreviewEmpty.classList.remove('d-none');
            return;
        }

        fetch(endpoint, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.blob();
            })
            .then(function (blob) {
                qrPreviewImg.src = URL.createObjectURL(blob);
                qrPreviewImg.classList.remove('d-none');
                qrPreviewSpinner.classList.add('d-none');
            })
            .catch(function () {
                qrPreviewImg.classList.add('d-none');
                qrPreviewSpinner.classList.add('d-none');
                qrPreviewEmpty.classList.remove('d-none');
            });
    }

    // Render Pictures Gallery
    function renderGallery(airconId) {
        if (!scanGallery) return;

        scanGallery.innerHTML = '<div class="col-12 py-3 text-center text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading unit pictures…</div>';

        fetch('../actions/get_aircon_images.php?aircon_id=' + encodeURIComponent(airconId), {
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                scanGallery.innerHTML = '';
                if (data.success && data.images && data.images.length > 0) {
                    data.images.forEach(function (imgUrl) {
                        var col = document.createElement('div');
                        col.className = 'col-6 col-md-4 col-xl-3';

                        var card = document.createElement('div');
                        card.className = 'card h-100 overflow-hidden border shadow-sm';

                        var img = document.createElement('img');
                        img.src = '../' + imgUrl;
                        img.alt = 'Unit Picture';
                        img.style.height = '110px';
                        img.style.objectFit = 'cover';
                        img.style.cursor = 'pointer';
                        img.addEventListener('click', function () { window.open('../' + imgUrl, '_blank'); });

                        card.appendChild(img);
                        col.appendChild(card);
                        scanGallery.appendChild(col);
                    });
                } else {
                    scanGallery.innerHTML =
                        '<div class="col-12 text-center py-3 text-muted small">' +
                        '<i class="fas fa-image fa-2x mb-2 text-secondary opacity-50 d-block"></i>' +
                        'No unit photos uploaded.' +
                        '</div>';
                }
            })
            .catch(function () {
                scanGallery.innerHTML = '<div class="col-12 text-center py-3 text-danger small">Could not load pictures.</div>';
            });
    }

    // Recent Scans Session Log
    function addToRecentScans(a, itemType) {
        if (!a || !a.aircon_id) return;
        itemType = itemType || 'Aircon Unit';

        // Check duplicate
        var existingIndex = -1;
        for (var i = 0; i < recentScans.length; i++) {
            if (recentScans[i].aircon_id == a.aircon_id && recentScans[i].item_type === itemType) {
                existingIndex = i;
                break;
            }
        }
        if (existingIndex !== -1) {
            recentScans.splice(existingIndex, 1);
        }

        recentScans.unshift({
            aircon_id: a.aircon_id,
            item_type: itemType,
            brand: a.brand || '',
            model: a.model || '',
            serial_number: a.serial_number || '',
            status: a.status || 'Active',
            time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        });

        if (recentScans.length > 10) {
            recentScans.pop();
        }

        renderRecentScans();
    }

    function renderRecentScans() {
        if (!recentScansBody || !recentScansEmpty) return;

        if (scanCountBadge) {
            scanCountBadge.textContent = recentScans.length + ' scanned';
        }

        if (recentScans.length === 0) {
            recentScansEmpty.classList.remove('d-none');
            recentScansBody.innerHTML = '';
            return;
        }

        recentScansEmpty.classList.add('d-none');
        recentScansBody.innerHTML = '';

        recentScans.forEach(function (item, idx) {
            var typeBadge = item.item_type === 'Aircon Unit' ? 'bg-info-subtle text-info' : (item.item_type === 'Fire Extinguisher' ? 'bg-danger-subtle text-danger' : 'bg-primary-subtle text-primary');
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td class="text-muted small">' + item.time + '</td>' +
                '<td><strong>#' + item.aircon_id + '</strong><br><span class="badge ' + typeBadge + ' rounded-pill" style="font-size:0.65rem;">' + (item.item_type || 'Item') + '</span></td>' +
                '<td>' + (item.brand + ' ' + item.model) + '</td>' +
                '<td class="text-muted small"><code>' + (item.serial_number || '-') + '</code></td>' +
                '<td><span class="badge bg-secondary-subtle text-secondary">' + item.status + '</span></td>' +
                '<td class="text-end">' +
                '<button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 btn-reload-unit" data-id="' + item.aircon_id + '">' +
                '<i class="fas fa-eye me-1"></i>View' +
                '</button>' +
                '</td>';
            recentScansBody.appendChild(tr);
        });

        // Attach click handlers to view buttons
        var buttons = recentScansBody.querySelectorAll('.btn-reload-unit');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = this.getAttribute('data-id');
                loadUnit(id);
            });
        });
    }

    // Clear Details
    function clearDetails() {
        if (emptyPlaceholder) emptyPlaceholder.classList.remove('d-none');
        if (unitDetailCard) unitDetailCard.classList.add('d-none');
        if (detailGrid) detailGrid.innerHTML = '';
        if (scanGallery) scanGallery.innerHTML = '';
        setStatus('● Ready', 'bg-success-subtle text-success border border-success-subtle');
    }

    // Initialize Event Listeners
    function init() {
        checkCameraSupport();

        if (btnToggleCamera) {
            btnToggleCamera.addEventListener('click', startScanner);
        }

        if (btnDemoScan) {
            btnDemoScan.addEventListener('click', function () {
                loadUnit('1');
            });
        }

        if (btnRescan) {
            btnRescan.addEventListener('click', function () {
                clearDetails();
                startScanner();
            });
        }

        if (btnClear) {
            btnClear.addEventListener('click', clearDetails);
        }

        // Manual search input submit on Enter or Button Click
        if (qrInput) {
            qrInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (this.value.trim()) {
                        handleScanSuccess(this.value.trim());
                    }
                }
            });

            qrInput.addEventListener('input', function () {
                if (scannerBox) {
                    scannerBox.classList.toggle('border-primary', this.value.length > 0);
                }
            });
        }

        if (btnSubmitManual) {
            btnSubmitManual.addEventListener('click', function () {
                if (qrInput && qrInput.value.trim()) {
                    handleScanSuccess(qrInput.value.trim());
                }
            });
        }

        // QR Image Upload Scanning (from qr_attendance.php)
        if (qrImage) {
            qrImage.addEventListener('change', function () {
                if (!this.files || !this.files[0] || !window.Html5Qrcode) return;
                var file = this.files[0];
                setStatus('● Scanning image…', 'bg-primary-subtle text-primary border border-primary-subtle');

                var fileScanner = new Html5Qrcode('scanner-container');
                fileScanner.scanFile(file, true)
                    .then(function (decodedText) {
                        handleScanSuccess(decodedText);
                        fileScanner.clear();
                    })
                    .catch(function (err) {
                        setStatus('● Image scan failed', 'bg-danger-subtle text-danger border border-danger-subtle');
                        showDebugError('QR Image File Scan Failed', err);
                        alert('Could not detect a QR code from the selected image. Please try another image or type the ID.');
                        fileScanner.clear();
                    });
            });
        }

        // Copy Debug Error to Clipboard
        if (btnCopyDebug && scannerDebugMsg) {
            btnCopyDebug.addEventListener('click', function () {
                var text = scannerDebugMsg.textContent || '';
                if (!text) return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        btnCopyDebug.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
                        setTimeout(function () {
                            btnCopyDebug.innerHTML = '<i class="fas fa-copy me-1"></i>Copy';
                        }, 2000);
                    });
                } else {
                    // Fallback
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    btnCopyDebug.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
                    setTimeout(function () {
                        btnCopyDebug.innerHTML = '<i class="fas fa-copy me-1"></i>Copy';
                    }, 2000);
                }
            });
        }

        // Dismiss Debug Box
        if (btnDismissDebug) {
            btnDismissDebug.addEventListener('click', clearDebugError);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
