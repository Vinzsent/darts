<?php
$pageTitle = 'Printer Header Settings';
include '../includes/auth.php';
include '../includes/db.php';
include '../includes/header.php';

// Normalize and verify user role (Admin/Superadmin only)
$raw_user_type = $_SESSION['user_type'] ?? $_SESSION['user']['user_type'] ?? '';
$user_type = str_replace([' ', '-'], '', strtolower($raw_user_type));

if ($user_type !== 'admin' && $user_type !== 'superadmin') {
    $_SESSION['error'] = 'Access denied. Only system Administrators can modify the printing paper header.';
    header("Location: ../dashboard.php");
    exit;
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Security validation failed (Invalid CSRF token).");
        }

        // Which header tab was submitted? Row 1 = General, Row 2 = Property Custodian
        $header_role = ($_POST['header_role'] ?? '') === 'property_custodian' ? 'property_custodian' : 'general';
        $target_id   = ($header_role === 'property_custodian') ? 2 : 1;
        $logo_prefix = ($header_role === 'property_custodian') ? 'pc_logo_' : 'logo_';
        $role_label  = ($header_role === 'property_custodian') ? 'Property Custodian' : 'General';

        // Fetch the target row (may not exist yet for the Property Custodian header)
        $stmt0 = $conn->prepare("SELECT * FROM printer_header_settings WHERE id = ?");
        $stmt0->bind_param('i', $target_id);
        $stmt0->execute();
        $current = $stmt0->get_result()->fetch_assoc();
        $stmt0->close();

        $logo_path = $current['logo_path'] ?? 'assets/images/logo.png';

        // Sanitize text inputs
        $school_name = trim($_POST['school_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $telephone_number = trim($_POST['telephone_number'] ?? '');
        $fax_number = trim($_POST['fax_number'] ?? '');
        $mobile_number = trim($_POST['mobile_number'] ?? '');
        $email_address = trim($_POST['email_address'] ?? '');
        $website = trim($_POST['website'] ?? '');

        // Enforce required fields
        if (empty($school_name)) {
            throw new Exception("School Name is required for the {$role_label} header.");
        }
        if (empty($address)) {
            throw new Exception("Address is required for the {$role_label} header.");
        }

        // Validate Email format if set
        if (!empty($email_address) && !filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid Email Address format.");
        }

        // Handle File Upload for the header Logo
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Error uploading file. Code: " . $_FILES['logo']['error']);
            }

            $file = $_FILES['logo'];
            $file_size = $file['size'];
            $file_tmp = $file['tmp_name'];
            $file_name = basename($file['name']);

            // Validate file size (max 2MB)
            if ($file_size > 2 * 1024 * 1024) {
                throw new Exception("Logo file size exceeds the 2MB limit.");
            }

            // Validate file extension/mime type (Allow only images)
            $allowed_exts = ['jpg', 'jpeg', 'png'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if (!in_array($file_ext, $allowed_exts)) {
                throw new Exception("Invalid file extension. Only PNG, JPG, and JPEG are allowed.");
            }

            // Double check MIME type for security
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_tmp);
            finfo_close($finfo);

            $allowed_mimes = ['image/png', 'image/jpeg', 'image/pjpeg'];
            if (!in_array($mime_type, $allowed_mimes)) {
                throw new Exception("Invalid file type. Uploaded file is not a valid image.");
            }

            // Create upload directory if not exists
            $upload_dir = '../uploads/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // Clean filename and generate a unique name (per header, so the
            // General and Property Custodian logos never overwrite each other)
            $clean_filename = $logo_prefix . time() . '_' . uniqid() . '.' . $file_ext;
            $destination = $upload_dir . $clean_filename;

            if (move_uploaded_file($file_tmp, $destination)) {
                // Delete this header's previous uploaded logo (keep default assets logo)
                if (!empty($logo_path) && strpos($logo_path, 'uploads/') === 0 && file_exists('../' . $logo_path)) {
                    @unlink('../' . $logo_path);
                }
                $logo_path = 'uploads/' . $clean_filename;
            } else {
                throw new Exception("Failed to save the uploaded image to the server.");
            }
        }

        // Upsert the settings row (General = ID 1, Property Custodian = ID 2)
        if ($current !== null) {
            $stmt = $conn->prepare("UPDATE printer_header_settings SET 
                school_name = ?, 
                address = ?, 
                telephone_number = ?, 
                fax_number = ?, 
                mobile_number = ?, 
                email_address = ?, 
                website = ?, 
                logo_path = ? 
                WHERE id = ?");

            $stmt->bind_param(
                "ssssssssi",
                $school_name,
                $address,
                $telephone_number,
                $fax_number,
                $mobile_number,
                $email_address,
                $website,
                $logo_path,
                $target_id
            );
        } else {
            $stmt = $conn->prepare("INSERT INTO printer_header_settings 
                (id, school_name, address, telephone_number, fax_number, mobile_number, email_address, website, logo_path) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->bind_param(
                "issssssss",
                $target_id,
                $school_name,
                $address,
                $telephone_number,
                $fax_number,
                $mobile_number,
                $email_address,
                $website,
                $logo_path
            );
        }

        if (!$stmt->execute()) {
            throw new Exception("Database update failed: " . $stmt->error);
        }

        $_SESSION['message'] = $role_label . " printer header settings updated successfully.";
        header("Location: printer_header.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: printer_header.php");
        exit;
    }
}

// Fetch current configurations
$query = $conn->query("SELECT * FROM printer_header_settings WHERE id = 1");
$settings = $query->fetch_assoc();

if (!$settings) {
    // Fallback default structure (General header)
    $settings = [
        'school_name' => 'DAVAO CENTRAL COLLEGE',
        'address' => 'Juan dela Cruz St., Toril, Davao City, Philippines',
        'telephone_number' => '(082) 291-1882',
        'fax_number' => '(082) 291-2053',
        'mobile_number' => '',
        'email_address' => 'davaocentralcollege2011@gmail.com',
        'website' => 'www.davaocentralcollege.com',
        'logo_path' => 'assets/images/logo.png'
    ];
}

// Property Custodian header (row 2). When it has not been configured yet,
// seed the form/preview from the General header values as a starting point.
$pc_row = null;
$pc_query = $conn->query("SELECT * FROM printer_header_settings WHERE id = 2");
if ($pc_query) {
    $pc_row = $pc_query->fetch_assoc();
}
$pc_configured = $pc_row && trim((string)($pc_row['school_name'] ?? '')) !== '';
$pc_settings = $pc_row ?: $settings;
?>

<style>
    :root {
        --dcc-green: #073b1d;
        --dcc-green-light: #0a4f28;
        --dcc-gold: #EACA26;
        --light-bg: #f4f6f9;
        --card-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        --transition: all 0.25s ease-in-out;
    }

    body {
        background-color: var(--light-bg);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .wrap {
        margin-left: 280px;
        min-height: 100vh;
        padding: 2rem;
    }

    .page-header {
        background: linear-gradient(135deg, var(--dcc-green) 0%, var(--dcc-green-light) 100%);
        color: white;
        padding: 1.5rem 2.5rem;
        border-radius: 16px;
        box-shadow: var(--card-shadow);
        margin-bottom: 2rem;
    }

    .page-title {
        font-size: 1.6rem;
        font-weight: 700;
        margin: 0;
    }

    .page-subtitle {
        font-size: 0.9rem;
        opacity: 0.85;
        margin-top: 4px;
    }

    /* ─── Header Tabs ─── */
    .header-tabs {
        display: flex;
        gap: 10px;
        background: white;
        border-radius: 14px;
        box-shadow: var(--card-shadow);
        padding: 8px;
        margin-bottom: 2rem;
        list-style: none;
        flex-wrap: wrap;
    }

    .header-tab {
        flex: 1 1 260px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        border: none;
        background: transparent;
        padding: 0.85rem 1.25rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.95rem;
        color: #4a5568;
        transition: var(--transition);
        text-align: left;
    }

    .header-tab i {
        font-size: 1.15rem;
    }

    .header-tab:hover {
        background: #f0f7f4;
        color: var(--dcc-green);
    }

    .header-tab.active {
        background: linear-gradient(135deg, var(--dcc-green) 0%, var(--dcc-green-light) 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(7, 59, 29, 0.25);
    }

    .tab-label {
        display: flex;
        flex-direction: column;
        line-height: 1.25;
    }

    .tab-sub {
        font-size: 0.72rem;
        font-weight: 500;
        color: #718096;
    }

    .header-tab.active .tab-sub {
        color: rgba(255, 255, 255, 0.75);
    }

    .content-grid {
        display: flex;
        flex-direction: column;
        gap: 2rem;
    }

    /* Modern Preview Card mimicking letterhead paper */
    .preview-card {
        background: white;
        border-radius: 12px;
        box-shadow: var(--card-shadow);
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }

    .preview-header {
        background-color: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .preview-paper {
        background: #ffffff;
        padding: 2.5rem;
        position: relative;
        min-height: 200px;
    }

    .letterhead-container {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .letterhead-logo {
        width: 85px;
        height: 85px;
        object-fit: contain;
    }

    .letterhead-details {
        flex: 1;
    }

    .letterhead-title {
        font-family: 'Georgia', serif;
        font-weight: 700;
        font-size: 1.85rem;
        color: var(--dcc-green);
        margin: 0;
        line-height: 1.2;
    }

    .letterhead-text {
        font-size: 0.95rem;
        color: #4a5568;
        margin: 2px 0 0 0;
        line-height: 1.4;
    }

    .letterhead-subtext {
        font-size: 0.85rem;
        color: #718096;
        margin: 2px 0 0 0;
    }

    /* Green banner at bottom of printing header */
    .letterhead-banner {
        background-color: var(--dcc-green);
        color: white;
        padding: 0.5rem 1.5rem;
        font-size: 0.8rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-radius: 4px;
    }

    .letterhead-banner span {
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }

    /* Settings Form Styles */
    .settings-card {
        background: white;
        border-radius: 16px;
        box-shadow: var(--card-shadow);
        padding: 2.5rem;
    }

    .form-section-title {
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--dcc-green);
        border-bottom: 2px solid #edf2f7;
        padding-bottom: 0.5rem;
        margin-bottom: 1.5rem;
    }

    .form-group-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .form-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #4a5568;
        margin-bottom: 0.4rem;
        display: block;
    }

    .form-control {
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.65rem 0.85rem;
        font-size: 0.9rem;
        transition: var(--transition);
    }

    .form-control:focus {
        border-color: var(--dcc-green);
        box-shadow: 0 0 0 3px rgba(7, 59, 29, 0.15);
    }

    /* Custom File Input design */
    .file-upload-wrapper {
        border: 2px dashed #cbd5e0;
        border-radius: 10px;
        padding: 1.5rem;
        text-align: center;
        background-color: #f7fafc;
        position: relative;
        cursor: pointer;
        transition: var(--transition);
    }

    .file-upload-wrapper:hover {
        border-color: var(--dcc-green);
        background-color: #f0f7f4;
    }

    .file-upload-icon {
        font-size: 2rem;
        color: #a0aec0;
        margin-bottom: 0.5rem;
    }

    .file-upload-text {
        font-size: 0.85rem;
        color: #4a5568;
    }

    .file-upload-subtext {
        font-size: 0.75rem;
        color: #718096;
        margin-top: 2px;
    }

    .file-upload-input {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }

    .btn-submit {
        background: linear-gradient(135deg, var(--dcc-green) 0%, var(--dcc-green-light) 100%);
        color: white;
        font-weight: 600;
        padding: 0.75rem 2rem;
        border: none;
        border-radius: 10px;
        font-size: 0.95rem;
        box-shadow: 0 4px 12px rgba(7, 59, 29, 0.2);
        transition: var(--transition);
    }

    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(7, 59, 29, 0.35);
        color: white;
    }

    /* Info note on the Property Custodian tab */
    .pc-info-note {
        background: #fffbeb;
        border-left: 4px solid var(--dcc-gold);
        color: #92400e;
        border-radius: 10px;
        padding: 0.9rem 1.25rem;
        margin-bottom: 1.5rem;
        font-size: 0.88rem;
        line-height: 1.5;
    }

    /* Alerts styling */
    .alert-modern {
        border-radius: 12px;
        border: none;
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .alert-success-modern {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        color: #065f46;
        border-left: 5px solid #10b981;
    }

    .alert-danger-modern {
        background: linear-gradient(135deg, #fee2e2, #fecaca);
        color: #991b1b;
        border-left: 5px solid #ef4444;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .wrap {
            margin-left: 0;
            padding: 1rem;
        }

        .page-header {
            padding: 1.25rem;
        }

        .preview-paper {
            padding: 1.25rem;
        }

        .letterhead-container {
            flex-direction: column;
            text-align: center;
            gap: 1rem;
        }

        .letterhead-title {
            font-size: 1.4rem;
        }

        .letterhead-banner {
            flex-direction: column;
            gap: 0.3rem;
            text-align: center;
        }

        .settings-card {
            padding: 1.25rem;
        }

        .header-tab {
            flex: 1 1 100%;
        }
    }
</style>

<?php include '../includes/sidebar.php'; ?>

<div class="wrap">

    <!-- Toast notifications -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-success-modern alert-modern alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= htmlspecialchars($_SESSION['message']);
            unset($_SESSION['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger-modern alert-modern alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($_SESSION['error']);
            unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-print me-2"></i>Printer Paper Header Settings</h1>
        <p class="page-subtitle">Customize and preview the letterhead designs printed on system sheets — one general header and a dedicated Property Custodian header</p>
    </div>

    <!-- Header Tabs -->
    <ul class="nav header-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="header-tab active" data-bs-toggle="tab" data-bs-target="#tab-general" type="button" role="tab" aria-selected="true">
                <i class="fas fa-building"></i>
                <span class="tab-label">General Header
                    <small class="tab-sub">Default letterhead for all system sheets</small>
                </span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="header-tab" data-bs-toggle="tab" data-bs-target="#tab-property-custodian" type="button" role="tab" aria-selected="false">
                <i class="fas fa-warehouse"></i>
                <span class="tab-label">Property Custodian Header
                    <small class="tab-sub">Used on property reports printed by the custodian</small>
                </span>
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ═══════════════ TAB 1: GENERAL HEADER ═══════════════ -->
        <div class="tab-pane fade show active" id="tab-general" role="tabpanel">
            <div class="content-grid">

                <!-- Interactive Letterhead Live Preview -->
                <div class="preview-card">
                    <div class="preview-header">
                        <span class="text-sm font-semibold text-gray-500 uppercase"><i class="fas fa-eye me-1"></i> Live Interactive Preview</span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle font-medium text-xs">Dynamic Printout Format</span>
                    </div>

                    <div class="preview-paper">
                        <div class="letterhead-container">
                            <img id="preview-logo" class="letterhead-logo" src="../<?= htmlspecialchars($settings['logo_path']) ?>" alt="School Logo" onerror="this.src='../assets/images/logo.png'">
                            <div class="letterhead-details">
                                <h2 style="font-family: arial;" id="preview-school-name" class="letterhead-title"><?= htmlspecialchars(strtoupper($settings['school_name'])) ?></h2>
                                <p id="preview-address" class="letterhead-text"><?= htmlspecialchars($settings['address']) ?></p>
                                <p id="preview-telefax" class="letterhead-subtext">
                                    <?php
                                    $tel_fax = [];
                                    if (!empty($settings['telephone_number'])) $tel_fax[] = 'Tel. No. ' . $settings['telephone_number'];
                                    if (!empty($settings['fax_number'])) $tel_fax[] = 'Fax No. ' . $settings['fax_number'];
                                    if (!empty($settings['mobile_number'])) $tel_fax[] = 'Mobile: ' . $settings['mobile_number'];
                                    echo htmlspecialchars(implode(' / ', $tel_fax));
                                    ?>
                                </p>
                            </div>
                        </div>

                        <div class="letterhead-banner">
                            <span id="preview-email"><i class="fas fa-envelope"></i> Email Address: <?= htmlspecialchars($settings['email_address']) ?></span>
                            <span id="preview-website"><i class="fas fa-globe"></i> Website: <?= htmlspecialchars($settings['website']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Configuration Settings Form -->
                <div class="settings-card">
                    <form action="printer_header.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="header_role" value="general">

                        <!-- School Profile Details -->
                        <div class="form-section-title">
                            <i class="fas fa-school me-2"></i> School Information
                        </div>

                        <div class="form-group-grid">
                            <div>
                                <label class="form-label" for="school_name">Name of School *</label>
                                <input type="text" id="school_name" name="school_name" class="form-control w-full" value="<?= htmlspecialchars($settings['school_name']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label" for="address">Address *</label>
                                <input type="text" id="address" name="address" class="form-control w-full" value="<?= htmlspecialchars($settings['address']) ?>" required>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="form-section-title">
                            <i class="fas fa-phone-alt me-2"></i> Contact Details
                        </div>

                        <div class="form-group-grid col-3">
                            <div>
                                <label class="form-label" for="telephone_number">Telephone Number</label>
                                <input type="text" id="telephone_number" name="telephone_number" class="form-control w-full" value="<?= htmlspecialchars($settings['telephone_number']) ?>" placeholder="e.g. (082) 291-1882">
                            </div>
                            <div>
                                <label class="form-label" for="fax_number">Fax Number</label>
                                <input type="text" id="fax_number" name="fax_number" class="form-control w-full" value="<?= htmlspecialchars($settings['fax_number']) ?>" placeholder="e.g. (082) 291-2053">
                            </div>
                            <div>
                                <label class="form-label" for="mobile_number">Mobile Number</label>
                                <input type="text" id="mobile_number" name="mobile_number" class="form-control w-full" value="<?= htmlspecialchars($settings['mobile_number']) ?>" placeholder="e.g. +639123456789">
                            </div>
                        </div>

                        <div class="form-group-grid">
                            <div>
                                <label class="form-label" for="email_address">Email Address</label>
                                <input type="email" id="email_address" name="email_address" class="form-control w-full" value="<?= htmlspecialchars($settings['email_address']) ?>" placeholder="e.g. info@school.edu.ph">
                            </div>
                            <div>
                                <label class="form-label" for="website">Website URL</label>
                                <input type="text" id="website" name="website" class="form-control w-full" value="<?= htmlspecialchars($settings['website']) ?>" placeholder="e.g. www.school.edu.ph">
                            </div>
                        </div>

                        <!-- Logo Uploader -->
                        <div class="form-section-title">
                            <i class="fas fa-image me-2"></i> School Logo
                        </div>

                        <div class="mb-4">
                            <label class="form-label">School Logo Image</label>
                            <div class="file-upload-wrapper">
                                <i class="fas fa-cloud-upload-alt file-upload-icon"></i>
                                <div class="file-upload-text">Drag and drop your logo file here, or click to browse</div>
                                <div class="file-upload-subtext">Supported formats: PNG, JPG, JPEG (Max 2MB)</div>
                                <input type="file" id="logo" name="logo" class="file-upload-input" accept="image/png, image/jpeg, image/jpg">
                            </div>
                            <div id="file-selected-name" class="text-xs text-gray-500 mt-2 font-medium" style="display: none;"></div>
                        </div>

                        <!-- Action Button -->
                        <div class="text-end mt-4">
                            <button type="submit" class="btn-submit">
                                <i class="fas fa-save me-2"></i> Save General Header
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>

        <!-- ═══════════════ TAB 2: PROPERTY CUSTODIAN HEADER ═══════════════ -->
        <div class="tab-pane fade" id="tab-property-custodian" role="tabpanel">
            <div class="content-grid">

                <!-- Info note -->
                <div class="pc-info-note">
                    <i class="fas fa-info-circle me-2"></i>
                    This letterhead is applied to <strong>Property Reports & printed property sheets</strong> when the
                    <strong>Property Custodian</strong> account prints them — every other role keeps using the General header.
                    Until you save it for the first time, the form below is seeded with the General header values.
                </div>

                <!-- Interactive Letterhead Live Preview -->
                <div class="preview-card">
                    <div class="preview-header">
                        <span class="text-sm font-semibold text-gray-500 uppercase"><i class="fas fa-eye me-1"></i> Live Interactive Preview</span>
                        <span class="badge <?= $pc_configured ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-dark border border-warning-subtle' ?> font-medium text-xs">
                            <?= $pc_configured ? 'Configured for Property Custodian' : 'Not configured yet — seeded from General' ?>
                        </span>
                    </div>

                    <div class="preview-paper">
                        <div class="letterhead-container">
                            <img id="pc-preview-logo" class="letterhead-logo" src="../<?= htmlspecialchars($pc_settings['logo_path']) ?>" alt="School Logo" onerror="this.src='../assets/images/logo.png'">
                            <div class="letterhead-details">
                                <h2 style="font-family: arial;" id="pc-preview-school-name" class="letterhead-title"><?= htmlspecialchars(strtoupper($pc_settings['school_name'])) ?></h2>
                                <p id="pc-preview-address" class="letterhead-text"><?= htmlspecialchars($pc_settings['address']) ?></p>
                                <p id="pc-preview-telefax" class="letterhead-subtext">
                                    <?php
                                    $pc_tel_fax = [];
                                    if (!empty($pc_settings['telephone_number'])) $pc_tel_fax[] = 'Tel. No. ' . $pc_settings['telephone_number'];
                                    if (!empty($pc_settings['fax_number'])) $pc_tel_fax[] = 'Fax No. ' . $pc_settings['fax_number'];
                                    if (!empty($pc_settings['mobile_number'])) $pc_tel_fax[] = 'Mobile: ' . $pc_settings['mobile_number'];
                                    echo htmlspecialchars(implode(' / ', $pc_tel_fax));
                                    ?>
                                </p>
                            </div>
                        </div>

                        <div class="letterhead-banner">
                            <span id="pc-preview-email"><i class="fas fa-envelope"></i> Email Address: <?= htmlspecialchars($pc_settings['email_address']) ?></span>
                            <span id="pc-preview-website"><i class="fas fa-globe"></i> Website: <?= htmlspecialchars($pc_settings['website']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Configuration Settings Form -->
                <div class="settings-card">
                    <form action="printer_header.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="header_role" value="property_custodian">

                        <!-- School Profile Details -->
                        <div class="form-section-title">
                            <i class="fas fa-school me-2"></i> School Information
                        </div>

                        <div class="form-group-grid">
                            <div>
                                <label class="form-label" for="pc_school_name">Name of School *</label>
                                <input type="text" id="pc_school_name" name="school_name" class="form-control w-full" value="<?= htmlspecialchars($pc_settings['school_name']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label" for="pc_address">Address *</label>
                                <input type="text" id="pc_address" name="address" class="form-control w-full" value="<?= htmlspecialchars($pc_settings['address']) ?>" required>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="form-section-title">
                            <i class="fas fa-phone-alt me-2"></i> Contact Details
                        </div>

                        <div class="form-group-grid col-3">
                            <div>
                                <label class="form-label" for="pc_telephone_number">Telephone Number</label>
                                <input type="text" id="pc_telephone_number" name="telephone_number" class="form-control w-full" value="<?= htmlspecialchars($pc_settings['telephone_number']) ?>" placeholder="e.g. (082) 291-1882">
                            </div>
                            <div>
                                <label class="form-label" for="pc_fax_number">Fax Number</label>
                                <input type="text" id="pc_fax_number" name="fax_number" class="form-control w-full" value="<?= htmlspecialchars($pc_settings['fax_number']) ?>" placeholder="e.g. (082) 291-2053">
                            </div>
                            <div>
                                <label class="form-label" for="pc_mobile_number">Mobile Number</label>
                                <input type="text" id="pc_mobile_number" name="mobile_number" class="form-control w-full" value="<?= htmlspecialchars($pc_settings['mobile_number']) ?>" placeholder="e.g. +639123456789">
                            </div>
                        </div>

                        <div class="form-group-grid">
                            <div>
                                <label class="form-label" for="pc_email_address">Email Address</label>
                                <input type="email" id="pc_email_address" name="email_address" class="form-control w-full" value="<?= htmlspecialchars($pc_settings['email_address']) ?>" placeholder="e.g. info@school.edu.ph">
                            </div>
                            <div>
                                <label class="form-label" for="pc_website">Website URL</label>
                                <input type="text" id="pc_website" name="website" class="form-control w-full" value="<?= htmlspecialchars($pc_settings['website']) ?>" placeholder="e.g. www.school.edu.ph">
                            </div>
                        </div>

                        <!-- Logo Uploader -->
                        <div class="form-section-title">
                            <i class="fas fa-image me-2"></i> School Logo
                        </div>

                        <div class="mb-4">
                            <label class="form-label">School Logo Image</label>
                            <div class="file-upload-wrapper">
                                <i class="fas fa-cloud-upload-alt file-upload-icon"></i>
                                <div class="file-upload-text">Drag and drop your logo file here, or click to browse</div>
                                <div class="file-upload-subtext">Supported formats: PNG, JPG, JPEG (Max 2MB)</div>
                                <input type="file" id="pc_logo" name="logo" class="file-upload-input" accept="image/png, image/jpeg, image/jpg">
                            </div>
                            <div id="pc-file-selected-name" class="text-xs text-gray-500 mt-2 font-medium" style="display: none;"></div>
                        </div>

                        <!-- Action Button -->
                        <div class="text-end mt-4">
                            <button type="submit" class="btn-submit">
                                <i class="fas fa-save me-2"></i> Save Property Custodian Header
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>

    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Bind one live-preview set. `prefix` is '' for the General tab and
        // 'pc_' for the Property Custodian tab (input and preview ids).
        function bindHeaderPreview(prefix) {
            const el = (suffix) => document.getElementById(prefix + suffix);

            const schoolNameInput = el('school_name');
            if (!schoolNameInput) return;

            const addressInput = el('address');
            const telInput = el('telephone_number');
            const faxInput = el('fax_number');
            const mobileInput = el('mobile_number');
            const emailInput = el('email_address');
            const websiteInput = el('website');
            const logoInput = el('logo');
            const fileSelectedName = el('file-selected-name');

            const previewName = el('preview-school-name');
            const previewAddress = el('preview-address');
            const previewTelefax = el('preview-telefax');
            const previewEmail = el('preview-email');
            const previewWebsite = el('preview-website');
            const previewLogo = el('preview-logo');

            function updatePreview() {
                previewName.textContent = schoolNameInput.value.toUpperCase();
                previewAddress.textContent = addressInput.value;

                let telefaxParts = [];
                if (telInput.value.trim() !== '') {
                    telefaxParts.push('Tel. No. ' + telInput.value.trim());
                }
                if (faxInput.value.trim() !== '') {
                    telefaxParts.push('Fax No. ' + faxInput.value.trim());
                }
                if (mobileInput.value.trim() !== '') {
                    telefaxParts.push('Mobile: ' + mobileInput.value.trim());
                }
                previewTelefax.textContent = telefaxParts.join(' / ');

                previewEmail.innerHTML = '<i class="fas fa-envelope"></i> Email Address: ' + emailInput.value.trim();
                previewWebsite.innerHTML = '<i class="fas fa-globe"></i> Website: ' + websiteInput.value.trim();
            }

            schoolNameInput.addEventListener('input', updatePreview);
            addressInput.addEventListener('input', updatePreview);
            telInput.addEventListener('input', updatePreview);
            faxInput.addEventListener('input', updatePreview);
            mobileInput.addEventListener('input', updatePreview);
            emailInput.addEventListener('input', updatePreview);
            websiteInput.addEventListener('input', updatePreview);

            // Preview logo as soon as selected
            logoInput.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    // Show file name
                    fileSelectedName.textContent = "Selected: " + file.name + " (" + (file.size / 1024 / 1024).toFixed(2) + " MB)";
                    fileSelectedName.style.display = 'block';

                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewLogo.src = e.target.result;
                    }
                    reader.readAsDataURL(file);
                } else {
                    fileSelectedName.style.display = 'none';
                }
            });
        }

        bindHeaderPreview('');     // General header tab
        bindHeaderPreview('pc_');  // Property Custodian header tab
    });
</script>

<?php include '../includes/footer.php'; ?>
