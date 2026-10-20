<?php
$pageTitle = 'QR Scanner';
include '../includes/auth.php';
include '../includes/db.php';
include '../includes/header.php';
?>
<?php include '../includes/sidebar.php'; ?>

<style>
  /* ─── Responsive Layout ───────────────────────────────────────────── */
  @media (min-width: 992px) {
    .scanner-main-content {
      margin-left: 270px;
      max-width: calc(100% - 290px);
      padding: 1.5rem 1.25rem;
    }
  }

  @media (max-width: 991.98px) {
    .scanner-main-content {
      margin-left: 0 !important;
      max-width: 100% !important;
      padding: 0.75rem !important;
    }
  }

  /* ─── Scanner Box & Viewfinder (Sized from qr_attendance & attendance) ── */
  .scanner-box {
    aspect-ratio: 4 / 3;
    max-height: 320px;
    background: #06110a;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }

  .scanner-box.active {
    border-color: #16a34a;
    box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.15);
  }

  .scanner-box #scanner-container,
  .scanner-box #scanner-container > * {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
  }

  /* ─── Reticle Corners ────────────────────────────────────────────── */
  .scanner-reticle-corner {
    position: absolute;
    width: 22px;
    height: 22px;
    border-color: #facc15;
    border-style: solid;
    pointer-events: none;
    z-index: 6;
  }
  .reticle-tl { top: 12px; left: 12px; border-width: 3px 0 0 3px; border-top-left-radius: 6px; }
  .reticle-tr { top: 12px; right: 12px; border-width: 3px 3px 0 0; border-top-right-radius: 6px; }
  .reticle-bl { bottom: 12px; left: 12px; border-width: 0 0 3px 3px; border-bottom-left-radius: 6px; }
  .reticle-br { bottom: 12px; right: 12px; border-width: 0 3px 3px 0; border-bottom-right-radius: 6px; }

  /* ─── Laser Scan Line ────────────────────────────────────────────── */
  @keyframes scanSweep {
    0%   { top: 10%; opacity: 0.25; }
    50%  { opacity: 1; }
    100% { top: 86%; opacity: 0.25; }
  }

  .scan-laser-line {
    position: absolute;
    left: 8%;
    right: 8%;
    height: 2px;
    background: linear-gradient(90deg, transparent, #22c55e, #facc15, #22c55e, transparent);
    box-shadow: 0 0 10px #22c55e, 0 0 18px rgba(34, 197, 94, 0.45);
    z-index: 5;
    display: none;
    animation: scanSweep 2s ease-in-out infinite alternate;
  }

  /* ─── Vignette Overlay ───────────────────────────────────────────── */
  .scanner-vignette {
    position: absolute;
    inset: 0;
    z-index: 3;
    background: radial-gradient(ellipse at center, transparent 50%, rgba(0,0,0,0.5) 100%);
    pointer-events: none;
  }

  /* ─── Empty Placeholder ──────────────────────────────────────────── */
  .empty-state-box {
    border: 2px dashed #e2e8f0;
    border-radius: 16px;
    background: #fafafa;
    padding: 3rem 1.5rem;
    text-align: center;
  }
</style>

<main class="scanner-main-content">
  <!-- Page Header -->
  <div class="d-flex align-items-center justify-content-between mb-3 bg-white p-3 rounded-3 shadow-sm border">
    <div class="d-flex align-items-center">
      <div class="bg-success text-white rounded-3 p-2 me-3 d-flex align-items-center justify-content-center shadow-sm" style="width:42px;height:42px;flex-shrink:0;">
        <i class="fas fa-qrcode fa-lg"></i>
      </div>
      <div>
        <h1 class="h5 mb-0 fw-bold text-dark">QR Scanner</h1>
        <small class="text-muted">Aircon Unit &amp; Equipment Verification</small>
      </div>
    </div>
    <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill small">
      <i class="fas fa-barcode me-1 text-primary"></i>Item Scanner
    </span>
  </div>

  <!-- Main 2-Column Grid (From qr_attendance.php & attendance.php) -->
  <div class="row g-4">

    <!-- ── Left Column: Scanner & Inputs (col-lg-5) ── -->
    <div class="col-12 col-lg-5">
      <div class="card shadow-sm border-0 rounded-4 mb-3 overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
          <h5 class="mb-0 fw-bold text-dark d-flex align-items-center" style="font-size:1.05rem;">
            <i class="fas fa-qrcode text-success me-2"></i>Scan Item QR
          </h5>
          <span id="scanner-status" class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">● Ready</span>
        </div>
        <div class="card-body p-3 p-sm-4">
          <p class="text-muted small mb-3">Allow camera access, point at the item's QR code, upload an image, or type the ID below.</p>

          <!-- Viewfinder Box -->
          <div class="scanner-box mb-3" id="scannerBox">
            <div id="scanner-container"></div>
            <div class="scanner-vignette"></div>
            <div class="scan-laser-line" id="scan-laser"></div>
            <div class="scanner-reticle-corner reticle-tl"></div>
            <div class="scanner-reticle-corner reticle-tr"></div>
            <div class="scanner-reticle-corner reticle-bl"></div>
            <div class="scanner-reticle-corner reticle-br"></div>

            <div id="cameraPlaceholder" class="d-flex flex-column align-items-center justify-content-center text-center p-3 position-absolute inset-0 w-100 h-100 text-white" style="z-index:4;pointer-events:none;background:rgba(6,17,10,0.85);">
              <i class="fas fa-camera fa-2x mb-2 text-warning opacity-75"></i>
              <span class="small text-white-50">Camera is idle. Tap <strong>Start Scanner</strong> to activate.</span>
            </div>
          </div>

          <!-- Camera Notice Alert (HTTPS / Permission check) -->
          <div id="cameraNotice" class="alert alert-warning small py-2 px-3 mb-3 d-none"></div>

          <!-- Debug Error Box -->
          <div id="scannerDebugBox" class="alert alert-danger small p-3 mb-3 d-none border-danger shadow-sm">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="fw-bold text-danger d-flex align-items-center">
                <i class="fas fa-bug me-2"></i>Scanner Debug Error
              </span>
              <div class="d-flex gap-1">
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 rounded-pill" id="btn-copy-debug" title="Copy error">
                  <i class="fas fa-copy me-1"></i>Copy
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill" id="btn-dismiss-debug" title="Dismiss">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>
            <div id="scannerDebugMsg" class="font-monospace small mb-2 text-break" style="white-space: pre-wrap; font-size: 0.8rem; background: #fff; padding: 10px; border-radius: 6px; border: 1px solid #fecaca; max-height: 180px; overflow-y: auto;"></div>
            <div class="text-muted" style="font-size: 0.75rem;">
              <span class="fw-semibold text-dark">Common fixes:</span>
              <ul class="mb-0 ps-3 mt-1">
                <li><code>NotAllowedError</code>: Permission denied. Reset camera permissions in your browser URL bar.</li>
                <li><code>NotReadableError</code>: Camera is busy (used by another tab, Zoom, Teams, or camera app).</li>
                <li><code>NotFoundError</code>: No camera hardware detected on this device.</li>
                <li><code>SecurityError</code>: HTTPS is required for camera streaming on non-localhost domains.</li>
              </ul>
            </div>
          </div>

          <!-- Camera Actions -->
          <div class="d-flex gap-2 mb-3">
            <button type="button" id="btn-toggle-camera" class="btn btn-success flex-grow-1 fw-semibold py-2 rounded-3 shadow-sm">
              <i class="fas fa-camera me-1"></i><span id="camera-btn-text">Start Scanner</span>
            </button>
            <button type="button" id="btn-demo-scan" class="btn btn-outline-secondary py-2 px-3 rounded-3" title="Scan Sample Unit">
              <i class="fas fa-play me-1"></i>Demo Scan
            </button>
          </div>

          <hr class="text-muted my-3 opacity-25">

          <!-- Manual ID / Barcode Search Form (from attendance.php) -->
          <form id="scanForm" class="mb-3" onsubmit="return false;">
            <label class="form-label small fw-semibold text-muted mb-1">
              <i class="fas fa-keyboard me-1"></i>Manual Search / Handheld Scanner
            </label>
            <div class="input-group">
              <span class="input-group-text bg-light text-muted"><i class="fas fa-barcode"></i></span>
              <input type="text" id="qr_input" name="qr_code" class="form-control" placeholder="Scan or type Aircon ID, Item #, or Serial #" autocomplete="off" autofocus>
              <button type="button" id="btn-submit-manual" class="btn btn-primary px-3 fw-semibold">
                <i class="fas fa-search me-1"></i>Find
              </button>
            </div>
            <div class="form-text small text-muted">Press Enter to lookup immediately.</div>
          </form>

          <!-- QR Image Upload Fallback (from qr_attendance.php) -->
          <div>
            <label class="form-label small fw-semibold text-muted mb-1">
              <i class="fas fa-file-image me-1"></i>Scan from QR image file
            </label>
            <input type="file" id="qrImage" class="form-control form-control-sm rounded-2" accept="image/*" capture="environment">
          </div>

        </div>
      </div>
    </div>

    <!-- ── Right Column: Scanned Item Details (col-lg-7) ── -->
    <div class="col-12 col-lg-7">

      <!-- Empty Placeholder (Before scan) -->
      <div id="empty-placeholder" class="empty-state-box mb-4">
        <div class="text-muted mb-3">
          <i class="fas fa-qrcode fa-3x text-secondary opacity-50"></i>
        </div>
        <h5 class="fw-bold text-dark mb-1">No Item Scanned Yet</h5>
        <p class="text-muted small mb-0" style="max-width: 420px; margin: 0 auto;">
          Point the camera at an aircon's printed QR code, upload a QR photo, or enter an Item ID / Serial Number on the left to inspect specifications and photos.
        </p>
      </div>

      <!-- Scanned Unit Details Card (Populated after scan) -->
      <div id="unit-detail-card" class="card shadow-sm border-0 rounded-4 mb-4 d-none">
        <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success-subtle text-success p-2 rounded-3">
              <i class="fas fa-check-circle fa-lg"></i>
            </span>
            <div>
              <h5 class="mb-0 fw-bold text-dark" id="unit-title">Aircon Unit</h5>
              <small class="text-muted" id="unit-subtitle">-</small>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span id="unit-status-badge" class="badge rounded-pill px-3 py-2 bg-success text-white">Working</span>
            <button type="button" id="btn-rescan" class="btn btn-sm btn-outline-primary rounded-3">
              <i class="fas fa-redo me-1"></i>Rescan
            </button>
            <button type="button" id="btn-clear" class="btn btn-sm btn-outline-secondary rounded-3">
              Clear
            </button>
          </div>
        </div>

        <div class="card-body p-3 p-sm-4">
          <div class="row g-3">
            <!-- QR Code Preview -->
            <div class="col-12 col-md-4 text-center">
              <div class="card border bg-light rounded-3 p-3 h-100 d-flex flex-column align-items-center justify-content-center">
                <div style="max-width:180px; width:100%;">
                  <img id="qr-preview-img" src="" alt="QR Code" class="img-fluid rounded-3 shadow-sm d-none mb-2">
                  <div id="qr-preview-spinner" class="spinner-border text-primary my-4 d-none" role="status">
                    <span class="visually-hidden">Loading…</span>
                  </div>
                  <div id="qr-preview-empty" class="text-muted small py-4">No QR image</div>
                </div>
                <small class="fw-bold text-success d-block">Official QR Code</small>
              </div>
            </div>

            <!-- Specs Grid -->
            <div class="col-12 col-md-8">
              <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                <i class="fas fa-info-circle text-primary me-2"></i>Unit Specifications
              </h6>
              <div id="detail-grid" class="row g-2"></div>
            </div>

            <!-- Pictures Gallery -->
            <div class="col-12 mt-3">
              <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                <i class="fas fa-images text-primary me-2"></i>Unit Pictures
              </h6>
              <div id="scan-gallery" class="row g-2"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Scans Session Log Card (From attendance.php) -->
      <div class="card shadow-sm border-0 rounded-4 overflow-hidden" id="recent-scans-card">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
          <h6 class="mb-0 fw-bold text-dark d-flex align-items-center">
            <i class="fas fa-history text-muted me-2"></i>Recent Scans (This Session)
          </h6>
          <span id="scan-count-badge" class="badge bg-secondary-subtle text-secondary rounded-pill">0 scanned</span>
        </div>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead class="table-light small text-uppercase text-muted">
              <tr>
                <th style="width:90px;">Time</th>
                <th>ID</th>
                <th>Unit / Model</th>
                <th>Serial #</th>
                <th>Status</th>
                <th class="text-end" style="width:80px;">Action</th>
              </tr>
            </thead>
            <tbody id="recent-scans-body"></tbody>
          </table>
          <div id="recent-scans-empty" class="text-center py-4 text-muted small">
            No scans recorded yet in this session.
          </div>
        </div>
      </div>

    </div>
  </div>
</main>

<!-- External Scripts (No inline scripts for strict CSP compliance) -->
<script src="../assets/js/html5-qrcode.min.js"></script>
<script src="../assets/js/scanner.js"></script>

<?php include '../includes/footer.php'; ?>
