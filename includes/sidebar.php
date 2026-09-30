<?php
/**
 * Dynamic Sidebar Loader
 * This file detects the user role and includes the appropriate sidebar from the sidebar/ directory.
 */

// Normalize user type
$raw_user_type = $_SESSION['user_type'] ?? $_SESSION['user']['user_type'] ?? '';
$user_role_norm = str_replace([' ', '-'], '', strtolower($raw_user_type));

// Map role to sidebar file
$sidebar_map = [
    'supplyincharge'              => 'supply_incharge.php',
    'propertycustodian'           => 'custodian.php',
    'purchasingofficer'           => 'purchasing.php',
    'purchasingstaff'             => 'purchasing.php',
    'purcashingstaff'             => 'purchasing.php', // Typos
    'purchsingstaff'              => 'purchasing.php', // Typos
    'generalsecurityoffice'       => 'gso.php',
    'generalserviceofficer'       => 'gso.php',
    'gsogeneralserviceofficer'    => 'gso.php',
    'gso'                         => 'gso.php',
    // Administrators get a consolidated sidebar covering every module.
    'admin'                       => 'admin.php',
    'superadmin'                  => 'admin.php',
];

$sidebar_file = $sidebar_map[$user_role_norm] ?? null;

// Default to a basic sidebar or admin sidebar if role not found (optional)
if (!$sidebar_file && in_array($user_role_norm, ['admin', 'superadmin'])) {
    // You might want an admin-specific sidebar here, 
    // for now let's use purchasing as it has most links or create one.
    $sidebar_file = 'purchasing.php'; 
}

if ($sidebar_file) {
    // Determine path based on current directory
    $sidebar_path = (strpos($_SERVER['PHP_SELF'], '/pages/') !== false) ? '../sidebar/' : 'sidebar/';
    include_once($sidebar_path . $sidebar_file);
}

// Keep sidebar scroll position across page loads + keep active link in view
?>
<script>
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var nav = document.querySelector('.sidebar nav');
        if (!nav) return;

        // 1. Restore saved scroll position (sidebar stays where the user left it)
        var saved = parseInt(sessionStorage.getItem('sidebarNavScroll') || '0', 10);

        // 2. Keep the active/highlighted link visible (bottom links too)
        var active = nav.querySelector('.bg-yellow-400');
        if (active) {
            // Center inside the nav container only — never scrolls the page
            // (rect-based, so it works regardless of offsetParent/positioning)
            var navRect = nav.getBoundingClientRect();
            var linkRect = active.getBoundingClientRect();
            var delta = linkRect.top - navRect.top - (nav.clientHeight / 2) + (linkRect.height / 2);
            nav.scrollTop = Math.max(0, nav.scrollTop + delta);
        } else if (saved > 0) {
            nav.scrollTop = saved;
        }

        // 3. Save position on manual scroll
        nav.addEventListener('scroll', function () {
            sessionStorage.setItem('sidebarNavScroll', String(nav.scrollTop));
        });
    });
})();
</script>
