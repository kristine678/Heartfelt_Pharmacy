<?php
require_once 'config/db.php';
require_once 'includes/password_field.php';

// Legacy route redirect: keep the staff route hidden and non-obvious.
if (strpos($_SERVER['REQUEST_URI'] ?? '', '/staff/') === false && !isset($_GET['legacy'])) {
    header('Location: ' . staffLoginUrl());
    exit;
}

if (isLoggedIn()) {
    header('Location: ' . staffDashboardUrl());
    exit;
}

$message = '';
// FIX (Task2 reset): banners for the password-reset flows that land on the Admin login.
if (isset($_GET['reset'])) {
    $message = 'Your password was reset and all older sessions were signed out. Sign in with your new password.';
} elseif (isset($_GET['password_changed'])) {
    $message = 'Your password changed, so you were signed out. Please sign in again.';
}
$blockedUntil = (int)($_SESSION['login_blocked_until'] ?? 0);
if ($blockedUntil > time()) {
    $message = 'Too many failed login attempts. Please try again later.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $failures = array_filter((array)($_SESSION['login_failures'] ?? []), static fn($ts) => (int)$ts > time() - 300);
    $_SESSION['login_failures'] = $failures;

    if ($username === '' || $password === '') {
        $message = 'Enter your email or username and password.';
    } elseif (count($failures) >= 5) {
        $_SESSION['login_blocked_until'] = time() + 300;
        $message = 'Too many failed login attempts. Please try again later.';
    } else {
        // Email OR username, trimmed + case-insensitive (findUserForLogin in config/db.php).
        $user = findUserForLogin($pdo, $username);
        $passwordMatches = $user && password_verify($password, $user['password_hash']);
        $accountUsable = $user
            && ($user['status'] ?? 'active') === 'active'
            && (int)($user['email_verified'] ?? 1) === 1;

        if (!$passwordMatches) {
            // Generic message for every credential failure - never reveals which part failed.
            $message = 'Incorrect username or password.';
        } elseif (($user['role'] ?? 'staff') !== 'admin') {
            // Server-side role gate: the Admin login only accepts role = admin. Checked BEFORE
            // the status message so a staff account (pending, disabled, ...) always gets the
            // same generic error here and no account state ever leaks from this page.
            $message = 'Incorrect username or password.';
        } elseif (!$accountUsable) {
            // Pre-verified admins are always usable; this only fires for a disabled admin.
            $message = 'This account is inactive. Please contact an administrator.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = (string)($user['full_name'] ?? '');
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'] ?? 'staff';
            // FIX (reset must kill old sessions): the session records which password version it was
            // created with; a later reset bumps token_version and requireLogin() ends this session.
            $_SESSION['token_version'] = (int)($user['token_version'] ?? 1);
            $_SESSION['last_activity'] = time();
            unset($_SESSION['login_failures'], $_SESSION['login_blocked_until']);
            header('Location: ' . staffDashboardUrl());
            exit;
        }

        $failures[] = time();
        $_SESSION['login_failures'] = $failures;
        if (count($failures) >= 5) {
            $_SESSION['login_blocked_until'] = time() + 300;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Heartfelt Pharmacy login for orders, bills, and customer care.">
    <title>Heartfelt Pharmacy | Login</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(currentUrlPrefix() . 'assets/css/style.css') ?>">
    <!-- FIX (logo animations): shared logo-animation trigger loaded in <head> so the login
         badge's hidden pre-pop state applies before first paint (no flash of the final logo). -->
    <script src="<?= htmlspecialchars(currentUrlPrefix() . 'assets/js/hp-logo-anim.js') ?>"></script>
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

        <div class="soft-blob blob-1"></div>
        <div class="soft-blob blob-2"></div>
        <div class="soft-blob blob-3"></div>
        <div class="soft-blob blob-4"></div>

        <div class="globe-wrap">
            <div class="globe">
                <div class="globe-rotation">
                    <span class="lat lat-1"></span>
                    <span class="lat lat-2"></span>
                    <span class="lat lat-3"></span>
                    <span class="lon lon-1"></span>
                    <span class="lon lon-2"></span>
                    <span class="lon lon-3"></span>
                    <span class="lon lon-4"></span>
                    <span class="continent continent-1"></span>
                    <span class="continent continent-2"></span>
                    <span class="continent continent-3"></span>
                </div>
            </div>
        </div>

        <div class="orbit orbit-inner">
            <div class="orbit-item item-capsule"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="18" y="12" width="28" height="40" rx="14" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><rect x="29" y="20" width="6" height="24" rx="3" fill="#ff8fbb" opacity="0.9"/><path d="M18 30h28" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round" opacity="0.8"/></svg></div>
            <div class="orbit-item item-tablet"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="16" y="18" width="32" height="28" rx="8" fill="#fffefc" stroke="#ffb3d1" stroke-width="3"/><path d="M30 24v16M24 32h16" stroke="#ff8fbb" stroke-width="4" stroke-linecap="round"/></svg></div>
            <div class="orbit-item item-bottle"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="24" y="18" width="16" height="6" rx="3" fill="#ffc9de"/><rect x="20" y="22" width="24" height="28" rx="8" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M26 28h12v14H26z" fill="#ffd7e8"/><path d="M26 35h12" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round" opacity="0.8"/></svg></div>
            <div class="orbit-item item-blister"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="14" y="18" width="36" height="28" rx="8" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><g fill="#ff8fbb"><rect x="21" y="24" width="8" height="8" rx="4"/><rect x="33" y="24" width="8" height="8" rx="4"/><rect x="21" y="33" width="8" height="8" rx="4"/><rect x="33" y="33" width="8" height="8" rx="4"/></g></svg></div>
        </div>

        <div class="orbit orbit-middle">
            <div class="orbit-item item-syringe"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="17" y="22" width="22" height="12" rx="6" fill="#fffafc" stroke="#ffb3d1" stroke-width="3"/><path d="M36 24l10-10 7 7-10 10-7-7Z" fill="#ffb3d1" opacity="0.9"/><path d="M39 28l10 10" stroke="#ff8fbb" stroke-width="4" stroke-linecap="round"/><path d="M20 28v12h16" stroke="#ff8fbb" stroke-width="4" stroke-linecap="round" fill="none"/></svg></div>
            <div class="orbit-item item-cross"><svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="18" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M32 20v24M20 32h24" stroke="#ff8fbb" stroke-width="5" stroke-linecap="round"/></svg></div>
            <div class="orbit-item item-therm"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="27" y="14" width="10" height="28" rx="5" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M30 26v18c0 4 2 7 6 7 5 0 8-4 8-8 0-5-3-8-7-12l-7-7" fill="none" stroke="#ff8fbb" stroke-width="4" stroke-linecap="round"/><circle cx="32" cy="48" r="6" fill="#ffb3d1"/></svg></div>
            <div class="orbit-item item-drops"><svg viewBox="0 0 64 64" aria-hidden="true"><path d="M29 18c-8 11-12 16-12 23 0 8 6 14 13 14s13-6 13-14c0-7-4-12-14-23Z" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M29 24c3 8 8 13 8 18 0 4-3 7-8 7s-8-3-8-7c0-5 5-10 8-18Z" fill="#ffd7e8"/><path d="M26 18c0-4 6-7 12-7" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round" fill="none"/></svg></div>
            <div class="orbit-item item-tube"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="24" y="12" width="16" height="10" rx="3" fill="#ffc9de"/><rect x="20" y="18" width="24" height="28" rx="8" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M29 24h6v8h-6z" fill="#ffd7e8"/><path d="M27 32h10" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round"/></svg></div>
        </div>

        <div class="orbit orbit-outer">
            <div class="orbit-item item-syrup"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="22" y="16" width="20" height="8" rx="3" fill="#ffc9de"/><rect x="18" y="22" width="28" height="28" rx="8" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M25 30h14v10H25z" fill="#ffd7e8"/><path d="M32 30v14" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round"/><path d="M25 32h14" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round" opacity="0.7"/></svg></div>
            <div class="orbit-item item-bandage"><svg viewBox="0 0 64 64" aria-hidden="true"><rect x="16" y="22" width="32" height="18" rx="6" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M24 22l-8 8 16 16 8-8-16-16Z" fill="#ffd7e8" opacity="0.75"/><path d="M27 25l10 10M25 35l10-10" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round"/></svg></div>
            <div class="orbit-item item-stethoscope"><svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="20" cy="22" r="10" fill="none" stroke="#ff8fbb" stroke-width="3"/><path d="M20 32v10c0 6 5 10 12 10s12-4 12-10V27" fill="none" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round"/><path d="M35 26h7v10" fill="none" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round"/><circle cx="45" cy="38" r="6" fill="#fff9fb" stroke="#ff8fbb" stroke-width="3"/></svg></div>
            <div class="orbit-item item-heart"><svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 50s-16-9-16-23c0-7 5-12 12-12 5 0 8 3 10 6 2-3 5-6 10-6 7 0 12 5 12 12 0 14-16 23-16 23Z" fill="#ffd7e8" stroke="#ff8fbb" stroke-width="3"/><path d="M19 36h26" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round"/><path d="M24 28h16" stroke="#ff8fbb" stroke-width="3" stroke-linecap="round" opacity="0.8"/></svg></div>
            <div class="orbit-item item-mortar"><svg viewBox="0 0 64 64" aria-hidden="true"><path d="M20 28h24v14c0 8-5 14-12 14s-12-6-12-14V28Z" fill="#fff9fb" stroke="#ffb3d1" stroke-width="3"/><path d="M24 24h16v8H24z" fill="#ffd7e8"/><path d="M34 18c8 0 12 4 12 10v4" stroke="#ff8fbb" stroke-width="4" stroke-linecap="round" fill="none"/></svg></div>
        </div>

        <div class="sparkles">
            <span class="spark s-1">✦</span>
            <span class="spark s-2">✦</span>
            <span class="spark s-3">✦</span>
            <span class="spark s-4">✦</span>
            <span class="spark s-5">❤</span>
            <span class="spark s-6">✦</span>
            <span class="spark s-7">❤</span>
            <span class="spark s-8">✦</span>
            <span class="spark s-9">✦</span>
            <span class="spark s-10">❤</span>
            <span class="spark s-11">✦</span>
            <span class="spark s-12">✦</span>
            <span class="spark s-13">✦</span>
            <span class="spark s-14">✦</span>
            <span class="spark s-15">❤</span>
            <span class="spark s-16">✦</span>
        </div>
        <div class="shooting-stars" aria-hidden="true">
            <span class="shooting-star star-a"></span>
            <span class="shooting-star star-b"></span>
            <span class="shooting-star star-c"></span>
        </div>
    </div>

    <a href="<?= htmlspecialchars(currentUrlPrefix() . 'index.php') ?>" class="home-button" aria-label="Home">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V18a2 2 0 0 1-2 2h-3.5v-6h-5v6H6a2 2 0 0 1-2-2v-7.5Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 20v-6h6v6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>

    <div class="login-shell">
        <div class="login-card">
            <div class="login-badge" aria-label="Heartfelt Pharmacy logo">
                <!-- FIX (logo animations): provided hp-logo SVG pasted verbatim (viewBox 0 0 120 120).
                     The pop-in plays on this element via assets/js/hp-logo-anim.js + style.css. -->
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
            <h1>Heartfelt Pharmacy</h1>
            <p>Welcome back! Sign in to care for your customers.</p>
            <?php if ($message) : ?>
                <div class="alert"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <form id="login-form" method="post" action="" data-auth-form>
                <label for="login-username">Email or Username</label>
                <input id="login-username" type="text" name="username" autocomplete="username" required>
                <!-- FIX (Task4): the password field (with its eye button) now comes from the ONE
                     shared passwordField() renderer used by every auth screen. -->
                <!-- FIX (admin login broken): 'name' is the WIRE key this handler reads ($_POST['password']).
                     passwordField() defaults name to id, so without this the browser posted
                     'login-password' and the handler saw an empty password. -->
                <?= passwordField(['id' => 'login-password', 'name' => 'password', 'label' => 'Password', 'autocomplete' => 'current-password']) ?>
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
            <!-- FIX (Task2): self-service password reset for the administrator account as well. -->
            <div class="login-secondary-link">
                <a href="<?= htmlspecialchars(currentUrlPrefix() . 'forgot-password.php?role=admin') ?>" class="browse-link">Forgot your password?</a>
            </div>
            <div class="login-secondary-link">
                <a href="<?= htmlspecialchars(currentUrlPrefix() . 'index.php') ?>" class="browse-link">Browse available medicines</a>
            </div>
            <!-- Staff self-service registration creates a Staff row in the shared users table. -->
            <div class="login-secondary-link">
                <a href="<?= htmlspecialchars(currentUrlPrefix() . 'staff-signup.php') ?>" class="browse-link">New staff? Sign up</a>
            </div>
            <!-- Staff Login authenticates against the same users table as this form. -->
            <div class="login-secondary-link">
                <a href="<?= htmlspecialchars(currentUrlPrefix() . 'staff-login.php') ?>" class="browse-link">Staff Login</a>
            </div>
        </div>
    </div>

    <!-- FIX (Bug 2): the login page previously rendered TWO copies of the animation bootstrap
         (the second one with no <script> tag printed raw JS text onto the page). The canvas scene
         now lives in assets/js/login-scene.js and the shared tab-hidden pause in assets/js/bg-pause.js. -->
    <!-- FIX (login UX): shared show/hide password eye + submit lock while posting. Loaded
         with the URL prefix so it resolves both at the root and when served from /staff/. -->
    <script src="<?= htmlspecialchars(currentUrlPrefix() . 'assets/js/login-auth.js') ?>"></script>
    <!-- FIX: these three assets 404'd on the /staff/ route (root-relative paths); load them
         with the current URL prefix so the Admin login gets its animation + scene scripts. -->
    <script src="<?= htmlspecialchars(currentUrlPrefix() . 'assets/js/login-scene.js') ?>"></script>
    <script src="<?= htmlspecialchars(currentUrlPrefix() . 'assets/js/bg-pause.js') ?>"></script>
</body>
</html>
