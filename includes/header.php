<?php
$current_script = $_SERVER['SCRIPT_NAME'] ?? '';
$asset_prefix = (strpos($current_script, '/pages/') !== false || strpos($current_script, '/actions/') !== false) ? '../' : './';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?? 'DARTS' ?></title>

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
      z-index: 1050;
      width: 2.6rem;
      height: 2.6rem;
      border: 1px solid rgba(255, 255, 255, 0.25);
      border-radius: 0.5rem;
      background: #073b1d;
      color: #ffffff;
      font-size: 1.1rem;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
    }

    .mobile-sidebar-drawer {
      position: fixed;
      top: 0;
      left: 0;
      height: 100%;
      width: 260px;
      z-index: 1040;
      display: none;
      flex-direction: column;
      transform: translateX(-100%);
      transition: transform 0.3s ease;
      background: #073b1d;
    }

    .mobile-sidebar-drawer.open {
      transform: translateX(0);
    }

    .mobile-sidebar-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.55);
      backdrop-filter: blur(4px);
    }

    .mobile-sidebar-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.75rem 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.12);
      background: #073b1d;
    }

    .mobile-sidebar-title {
      color: #ffffff;
      font-weight: 700;
      font-size: 1.1rem;
    }

    .mobile-sidebar-close {
      background: transparent;
      border: 0;
      color: #ffffff;
      font-size: 1.25rem;
      cursor: pointer;
      padding: 0.25rem;
    }

    .mobile-sidebar-body {
      flex: 1 1 auto;
      overflow-y: auto;
      padding: 1rem;
    }

    @media (max-width: 991.98px) {
      .sidebar {
        display: none !important;
      }
      .mobile-sidebar-toggle {
        display: flex !important;
      }
      .mobile-sidebar-drawer {
        display: flex !important;
      }
    }
  </style>
</head>

<body>