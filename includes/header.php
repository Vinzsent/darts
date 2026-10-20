<?php
$current_script = $_SERVER['SCRIPT_NAME'] ?? '';
$asset_prefix = (strpos($current_script, '/pages/') !== false || strpos($current_script, '/actions/') !== false) ? '../' : './';

// Emit a permissive CSP via PHP so it takes precedence over any server-level header.
// The live server (vle.dcc.edu.ph) sets script-src 'none' at the vhost level;
// PHP header() replaces that with our policy before output is sent.
$csp = implode('; ', [
    "default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob: https:",
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' blob:"
        . " https://cdn.jsdelivr.net"
        . " https://cdnjs.cloudflare.com"
        . " https://cdn.datatables.net"
        . " https://code.jquery.com"
        . " https://vle.dcc.edu.ph",
    "script-src-elem 'self' 'unsafe-inline'"
        . " https://cdn.jsdelivr.net"
        . " https://cdnjs.cloudflare.com"
        . " https://cdn.datatables.net"
        . " https://code.jquery.com"
        . " https://vle.dcc.edu.ph",
    "style-src 'self' 'unsafe-inline'"
        . " https://cdn.jsdelivr.net"
        . " https://cdnjs.cloudflare.com"
        . " https://cdn.datatables.net"
        . " https://fonts.googleapis.com",
    "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com",
    "img-src 'self' data: blob: https:",
    "connect-src 'self' blob: https:",
    "worker-src 'self' blob:",
    "frame-ancestors 'self'",
]);
header('Content-Security-Policy: ' . $csp, true);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?? 'DARTS' ?></title>
  <!-- CSP meta fallback (belt-and-suspenders alongside the PHP header above) -->
  <meta http-equiv="Content-Security-Policy" content="default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob: https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net https://code.jquery.com https://vle.dcc.edu.ph; script-src-elem 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net https://code.jquery.com https://vle.dcc.edu.ph; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net https://fonts.googleapis.com; font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self' blob: https:; worker-src 'self' blob:;">

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?= $asset_prefix ?>assets/css/dark-mode.css">

  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

  <!-- Select2 CSS & Bootstrap 5 Theme -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">

  <!-- Tailwind CSS (Production Compiled - after Bootstrap so utilities take precedence) -->
  <link rel="stylesheet" href="<?= $asset_prefix ?>assets/css/tailwind.min.css">

  <!-- Custom styles -->
  <style>
    :root {
      --bs-body-bg: #f8f9fa;
      --bs-body-color: #212529;
    }

    [data-bs-theme="dark"] {
      --bs-body-bg: #212529;
      --bs-body-color: #f8f9fa;
    }

    body {
      background-color: var(--bs-body-bg);
      color: var(--bs-body-color);
      transition: background-color 0.3s ease, color 0.3s ease;
    }

    .navbar {
      z-index: 1030;
    }

    .sidebar {
      background-color: #073b1d;
    }

    .sidebar a {
      color: inherit;
      text-decoration: none;
    }

    .sidebar a:hover {
      color: inherit;
      text-decoration: none;
    }

    /* Mobile sidebar: off-canvas drawer with role menu */
    .mobile-sidebar-toggle {
      display: none;
      position: fixed;
      top: 0.75rem;
      left: 0.75rem;
      z-index: 1030;
      width: 2.75rem;
      height: 2.75rem;
      border: 1px solid rgba(255, 255, 255, 0.25);
      border-radius: 0.6rem;
      background: #073b1d;
      color: #ffffff;
      font-size: 1.15rem;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
      transition: all 0.2s ease;
    }

    .mobile-sidebar-toggle:hover,
    .mobile-sidebar-toggle:focus {
      background: #0a4f27;
      color: #ffffff;
      outline: none;
    }

    /* Fixed full-screen backdrop behind drawer */
    .mobile-sidebar-backdrop {
      position: fixed;
      inset: 0;
      width: 100vw;
      height: 100vh;
      height: 100dvh;
      background: rgba(0, 0, 0, 0.65);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      z-index: 1040;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.3s ease, visibility 0.3s ease;
    }

    .mobile-sidebar-backdrop.show {
      opacity: 1;
      visibility: visible;
    }

    .mobile-sidebar-drawer {
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      height: 100vh;
      height: 100dvh;
      width: 280px;
      max-width: 85vw;
      z-index: 1050;
      display: flex;
      flex-direction: column;
      transform: translateX(-100%);
      visibility: hidden;
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.3s ease;
      background: #073b1d;
      box-shadow: 6px 0 25px rgba(0, 0, 0, 0.45);
    }

    .mobile-sidebar-drawer.open {
      transform: translateX(0);
      visibility: visible;
    }

    .mobile-sidebar-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.12);
      background: #052c16;
    }

    .mobile-sidebar-title {
      color: #ffffff;
      font-weight: 700;
      font-size: 1.2rem;
      letter-spacing: 0.05em;
    }

    .mobile-sidebar-close {
      background: rgba(255, 255, 255, 0.1);
      border: 0;
      color: #ffffff;
      width: 2.2rem;
      height: 2.2rem;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      cursor: pointer;
      transition: background 0.2s ease;
    }

    .mobile-sidebar-close:hover {
      background: rgba(255, 255, 255, 0.2);
    }

    .mobile-sidebar-body {
      flex: 1 1 auto;
      overflow-y: auto;
      padding: 0.75rem;
    }

    /* Links inside mobile drawer - high contrast readable white */
    .mobile-sidebar-drawer a {
      color: rgba(255, 255, 255, 0.85) !important;
      text-decoration: none !important;
    }

    .mobile-sidebar-drawer a:hover,
    .mobile-sidebar-drawer a:focus {
      color: #ffffff !important;
      background-color: rgba(255, 255, 255, 0.1) !important;
    }

    .mobile-sidebar-drawer a.bg-yellow-400 {
      color: #073b1d !important;
      background-color: #facc15 !important;
      font-weight: 600 !important;
    }

    @media (max-width: 991.98px) {
      .sidebar {
        display: none !important;
      }
      .mobile-sidebar-toggle {
        display: flex !important;
      }
    }
  </style>
</head>

<body>