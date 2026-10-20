<?php
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heartfelt Pharmacy | Customer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <!-- FIX (Bug 2): shared dark-pink background layer (same partial as every other page). -->
    <?php require __DIR__ . '/includes/bg_fx.php'; ?>

    <div class="bg-scene" aria-hidden="true">
        <canvas class="bg-canvas"></canvas>
        <div class="bg-fallback" aria-hidden="true">
            <div class="fallback-globe"></div>
            <div class="fallback-orbit fallback-orbit-a">
                <span class="fallback-item item-pill"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="18" y="12" width="28" height="40" rx="14" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><rect x="29" y="20" width="6" height="24" rx="3" fill="#ff8fbb" opacity="0.9"/><path d="M18 30h28" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round" opacity="0.8"/></svg></span>
                <span class="fallback-item item-bottle"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="24" y="18" width="16" height="6" rx="3" fill="#ffc9de"/><rect x="20" y="22" width="24" height="28" rx="8" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M26 28h12v14H26z" fill="#ffd7e8"/><path d="M26 35h12" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round" opacity="0.8"/></svg></span>
                <span class="fallback-item item-cross"><svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="18" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M32 20v24M20 32h24" stroke="#ff8fbb" stroke-width="5" stroke-linecap="round"/></svg></span>
                <span class="fallback-item item-heart"><svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 50s-16-9-16-23c0-7 5-12 12-12 5 0 8 3 10 6 2-3 5-6 10-6 7 0 12 5 12 12 0 14-16 23-16 23Z" fill="#ffd7e8" stroke="#ff8fbb" stroke-width="3"/><path d="M19 36h26" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round"/><path d="M24 28h16" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round" opacity="0.8"/></svg></span>
            </div>
            <div class="fallback-orbit fallback-orbit-b">
                <span class="fallback-item item-tablet"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="16" y="18" width="32" height="28" rx="8" fill="#fffefc" stroke="#ffb3d1" stroke-width="3"/><path d="M30 24v16M24 32h16" stroke="#ff8fbb" stroke-width="4" stroke-linecap="round"/></svg></span>
                <span class="fallback-item item-syringe"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="17" y="22" width="22" height="12" rx="6" fill="#fffafc" stroke="#ffb3d1" stroke-width="3"/><path d="M36 24l10-10 7 7-10 10-7-7Z" fill="#ffb3d1" opacity="0.9"/><path d="M39 28l10 10" stroke="#ff8fbb" stroke-width="4" stroke-linecap="round"/><path d="M20 28v12h16" stroke="#ff8fbb" stroke-width="4" stroke-linecap="round" fill="none"/></svg></span>
                <span class="fallback-item item-bandage"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="16" y="22" width="32" height="18" rx="6" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M24 22l-8 8 16 16 8-8-16-16Z" fill="#ffd7e8" opacity="0.75"/><path d="M27 25l10 10M25 35l10-10" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round"/></svg></span>
            </div>
        </div>
    </div>

    <div class="login-shell">
        <div class="login-card customer-dashboard-card">
            <div class="login-badge" aria-label="Heartfelt Pharmacy logo">
                <svg viewBox="0 0 64 64" aria-hidden="true">
                    <path d="M32 50c-10.8-7.3-18-13.5-18-22.2C14 22.7 18.7 18 24.7 18c4 0 6.7 2 8.3 4.5C34.5 20 37.2 18 41.2 18 47.3 18 52 22.7 52 27.8 52 36.5 44.8 42.7 32 50Z" fill="#ffffff"/>
                    <path d="M32 26.5v13M25.5 33h13" stroke="#ff7cb3" stroke-width="3" stroke-linecap="round"/>
                </svg>
            </div>
            <h1>Welcome, <?= htmlspecialchars($_SESSION['username'] ?? 'Customer') ?></h1>
            <p>Your account is active and verified.</p>
            <div class="customer-dashboard-actions">
                <a href="logout.php" class="btn btn-primary">Log out</a>
            </div>
        </div>
    </div>

    <!-- FIX (Bug 2): shared background pause snippet — one place to tune tab-hidden behaviour. -->
    <script src="assets/js/bg-pause.js"></script>
</body>
</html>
