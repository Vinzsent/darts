<?php
/**
 * Mobile sidebar drawer.
 *
 * Reuses the same role menu as the desktop sidebar (sidebar.php), but wraps it in
 * an off-canvas drawer with a toggle button so role navigation is reachable on
 * small screens.
 *
 * This file is included by sidebar.php after the desktop sidebar has been
 * rendered, so the role variables ($user_role_norm, $sidebar_map, $sidebar_file)
 * are already available.
 */

// Re-resolve the role sidebar file (same map as sidebar.php).
$sidebar_file = $sidebar_map[$user_role_norm] ?? 'admin.php';

// Capture a copy of the role menu HTML for the mobile drawer.
$sidebar_html = '';
if (file_exists(__DIR__ . '/../sidebar/' . $sidebar_file)) {
    ob_start();
    include(__DIR__ . '/../sidebar/' . $sidebar_file);
    $sidebar_html = ob_get_clean();

    // Remove the Tailwind CDN script from the mobile copy.
    $sidebar_html = preg_replace(
        '#<script[^>]*src="https://cdn\.tailwindcss\.com"[^>]*></script>#',
        '',
        $sidebar_html
    );

    // Remove the desktop wrapper <div class="sidebar"> so the menu flows inside
    // the drawer instead of being fixed to the left.
    if (preg_match('#<div class="sidebar[^"]*"[^>]*>(.*)</div>#is', $sidebar_html, $m)) {
        $sidebar_html = $m[1];
    }
}
?>

<!-- Mobile sidebar toggle (visible on small screens) -->
<button type="button" class="mobile-sidebar-toggle d-lg-none" id="mobileSidebarToggle" aria-label="Open menu">
  <i class="fas fa-bars"></i>
</button>

<!-- Mobile off-canvas drawer -->
<div class="mobile-sidebar-drawer d-lg-none" id="mobileSidebar" role="dialog" aria-label="Sidebar navigation">
  <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop"></div>
  <div class="mobile-sidebar-header">
    <span class="mobile-sidebar-title">DARTS</span>
    <button type="button" class="mobile-sidebar-close" id="mobileSidebarClose" aria-label="Close menu">
      <i class="fas fa-times"></i>
    </button>
  </div>
  <div class="mobile-sidebar-body">
    <?= $sidebar_html ?? '' ?>
  </div>
</div>

<script>
(function () {
  var toggle = document.getElementById('mobileSidebarToggle');
  var drawer = document.getElementById('mobileSidebar');
  var backdrop = document.getElementById('mobileSidebarBackdrop');
  var close = document.getElementById('mobileSidebarClose');

  function open() {
    if (drawer) { drawer.classList.add('open'); }
    if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    if (drawer) { drawer.classList.remove('open'); }
    if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
    document.body.style.overflow = '';
  }

  if (toggle) { toggle.addEventListener('click', open); }
  if (close) { close.addEventListener('click', closeDrawer); }
  if (backdrop) { backdrop.addEventListener('click', closeDrawer); }

  // Close the drawer on Escape.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && drawer && drawer.classList.contains('open')) {
      closeDrawer();
    }
  });

  // Close the drawer when a navigation link is clicked.
  if (drawer) {
    var links = drawer.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
      links[i].addEventListener('click', closeDrawer);
    }
  }
})();
</script>