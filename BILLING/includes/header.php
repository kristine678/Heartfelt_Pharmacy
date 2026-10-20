<?php if (session_status() === PHP_SESSION_NONE) { session_start(); } ?>
<?php
$assetBase = '';
// TASK 2/3: the sidebar, the alert bell and the page <title> all come from ONE source — the page
// registry in includes/permissions.php — so what a user may open and what the sidebar offers can
// never drift apart. Nothing here decides access on its own: requirePageAccess() does that
// server-side for every page and endpoint.
require_once __DIR__ . '/permissions.php';

$hpNavItems = navItemsForCurrentUser();
// Extra labels for pages that are not sidebar destinations themselves.
$hpPageNames = [
    'menu' => 'Menu',
    'history' => 'History',
    'sales' => 'Sales',
    'users' => 'Users',
    'settings' => 'Settings',
    'pharmacy' => 'Pharmacy',
    'inventory' => 'Inventory Dashboard',
    'inventory_alerts' => 'Inventory Alerts',
    'help' => 'Help Center',
];
$hpPageLabel = $hpPageNames[$activePage ?? ''] ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Heartfelt Pharmacy POS dashboard for caring, efficient pharmacy service.">
    <!-- FIX (Task 4): brand name + dynamic page label. -->
    <title>Heartfelt Pharmacy | <?= htmlspecialchars($hpPageLabel) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= $assetBase ?>assets/css/style.css">
    <!-- Inventory alert pop-up: bell badge + dialog styles (loaded on every staff page). -->
    <link rel="stylesheet" href="<?= $assetBase ?>assets/css/alerts.css">
    <?php // Per-page stylesheets: a page sets $hpExtraStyles = ['assets/css/sales.css'] before
          // including this header, keeping style.css from growing with one-page rules. ?>
    <?php foreach (($hpExtraStyles ?? []) as $hpStyle) : ?>
        <link rel="stylesheet" href="<?= $assetBase . htmlspecialchars((string)$hpStyle, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
</head>
<body>
<!-- PAGE TRANSITION: ONE shared overlay partial — covers the page on load with the twirling
     logo, fades out, and intercepts internal link clicks so every navigation feels continuous. -->
<?php require __DIR__ . '/page_transition.php'; ?>
<div class="bg-anim" aria-hidden="true">
    <span class="bubble bubble-1"></span>
    <span class="bubble bubble-2"></span>
    <span class="bubble bubble-3"></span>
    <span class="bubble bubble-4"></span>
    <span class="bubble bubble-5"></span>
    <span class="bubble bubble-6"></span>
    <span class="spark spark-1">✦</span>
    <span class="spark spark-2">✦</span>
    <span class="spark spark-3">♥</span>
    <span class="spark spark-4">♥</span>
</div>
<!-- FIX (Bug 2): background layer comes from the ONE shared partial includes/bg_fx.php —
     tuning colors/counts happens in that single file only. -->
<?php require __DIR__ . '/bg_fx.php'; ?>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <!-- FIX (logo animations): provided hp-logo SVG pasted verbatim (viewBox 0 0 120 120)
                 into the existing .brand-mark badge. The 3D twirl plays on this element once per
                 staff page load via assets/js/hp-logo-anim.js + style.css. -->
            <div class="brand-mark">
                <svg class="hp-logo" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                  <defs>
                    <linearGradient id="heartGrad" x1="0" y1="0" x2="1" y2="1">
                      <stop offset="0%" stop-color="#ff8fbb"/>
                      <stop offset="100%" stop-color="#e63b80"/>
                    </linearGradient>
                  </defs>
                  <!-- heart shape -->
                  <path d="M60 104
                           C 20 78, 4 54, 4 34
                           C 4 14, 20 2, 36 6
                           C 48 9, 56 18, 60 26
                           C 64 18, 72 9, 84 6
                           C 100 2, 116 14, 116 34
                           C 116 54, 100 78, 60 104 Z"
                        fill="url(#heartGrad)" stroke="#b8265f" stroke-width="2"/>
                  <!-- first aid cross -->
                  <g fill="#ffffff">
                    <rect x="50" y="30" width="20" height="46" rx="4"/>
                    <rect x="34" y="46" width="52" height="20" rx="4"/>
                  </g>
                </svg>
            </div>
            <div class="brand-copy">
                <h2>Heartfelt Pharmacy</h2>
                <p>Your health, our heart.</p>
            </div>
        </div>

        <!-- TASK 2/3: rendered from navItemsForCurrentUser(), so a staff account shows only the
             pages it actually has (Menu, History, Help Center by default) and a granted page
             appears/disappears here the moment an admin changes it. -->
        <nav class="nav-links">
            <?php foreach ($hpNavItems as $hpNavItem) : ?>
                <a href="<?= $assetBase . htmlspecialchars($hpNavItem['url'], ENT_QUOTES, 'UTF-8') ?>"
                   class="nav-link <?= ($activePage ?? '') === $hpNavItem['page'] ? 'active' : '' ?>"
                   <?= ($activePage ?? '') === $hpNavItem['page'] ? 'aria-current="page"' : '' ?>>
                    <i class="<?= htmlspecialchars($hpNavItem['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i> <?= htmlspecialchars($hpNavItem['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (userCanAccess('inventory_alerts')) : ?>
        <!-- Inventory alert bell: badge shows how many medicines need attention;
             clicking reopens the pop-up (assets/js/alerts.js + includes/alert_popup.php).
             TASK 2: only rendered for users who hold the inventory_alerts page — staff without it
             get neither the bell nor its badge, and api/alerts.php answers 403 for them. -->
        <button type="button" class="nav-link alert-bell" id="alert-bell" aria-haspopup="dialog"
                aria-controls="medicine-alert-modal" aria-label="Inventory alerts">
            <i class="fa-solid fa-bell" aria-hidden="true"></i>
            Inventory Alerts
            <span class="alert-bell-count" id="alert-bell-count" hidden>0</span>
        </button>
        <?php endif; ?>

        <a href="<?= $assetBase ?>logout.php" class="nav-link logout-link">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </aside>

    <main class="main-content">
