<?php
// NEW: Independent login endpoint for accounts created through staff self-registration.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/staff_auth.php';

if (isLoggedIn()) {
    header('Location: ' . staffDashboardUrl());
    exit;
}
if (empty($_SESSION['staff_auth_csrf'])) {
    $_SESSION['staff_auth_csrf'] = bin2hex(random_bytes(32));
}
$message = '';
$infoMessage = '';
$showResendLink = false;
$csrfToken = $_SESSION['staff_auth_csrf'];

// Informational banners for the flows that end here (Task 1 code verification, Task 2 reset).
if (isset($_GET['verified'])) {
    $infoMessage = 'Email verified! Your account is active - sign in below.';
} elseif (isset($_GET['reset'])) {
    $infoMessage = 'Your password was reset and all older sessions were signed out. Sign in with your new password.';
} elseif (isset($_GET['password_changed'])) {
    $message = 'Your password changed, so you were signed out. Please sign in again.';
} elseif (isset($_GET['expired'])) {
    $message = 'Your session expired after a period of inactivity. Please sign in again.';
}

// Staff and administrators authenticate against the same managed users table.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!hash_equals($csrfToken, $token)) {
        $message = 'Your session expired. Refresh the page and try again.';
    } elseif ($username === '' || $password === '') {
        $message = 'Enter your username and password.';
    } else {
        $subjects = staffAuthSubjectHashes('staff-login:' . strtolower($username));
        if (staffAuthRateLimited($pdo, 'staff_login', $subjects, 10)) {
            $message = 'Too many sign-in attempts. Please try again later.';
        } else {
            // Email OR username, trimmed + case-insensitive (findUserForLogin in config/db.php).
            $account = findUserForLogin($pdo, $username);
            if (!$account || !password_verify($password, $account['password_hash'])) {
                // Only FAILED attempts are recorded, so a successful sign-in never eats the quota.
                recordStaffAuthAttempt($pdo, 'staff_login', $subjects);
                $message = 'Incorrect email or password.';
            } elseif (($account['role'] ?? 'staff') !== 'staff') {
                // Server-side role gate: administrators must use the Admin login page. The same
                // generic message as a wrong password keeps the account's role from leaking.
                recordStaffAuthAttempt($pdo, 'staff_login', $subjects);
                $message = 'Incorrect email or password.';
            } elseif (($account['status'] ?? 'active') === 'pending' || (int)($account['email_verified'] ?? 1) !== 1) {
                // FIX (staff verification): the password was CORRECT, but the email has not been
                // verified yet. Not counted as a failed attempt; the resend screen is offered so
                // the user can request a fresh link (60s cooldown).
                $pendingEmail = strtolower(trim((string) ($account['email'] ?? '')));
                if ($pendingEmail !== '' && filter_var($pendingEmail, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['pending_verification_email'] = $pendingEmail;
                }
                $message = 'Please verify your email first.';
                $showResendLink = true;
            } elseif (($account['status'] ?? 'active') !== 'active') {
                recordStaffAuthAttempt($pdo, 'staff_login', $subjects);
                $message = 'This account is inactive. Please contact an administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$account['id'];
                $_SESSION['full_name'] = (string)$account['full_name'];
                $_SESSION['username'] = (string)$account['username'];
                $_SESSION['role'] = (string)$account['role'];
                // FIX (reset must kill old sessions): remember WHICH password version authorised
                // this session. requireLogin() compares it on every request, so a later password
                // reset (self-service or admin) ends this session instead of honouring the old one.
                $_SESSION['token_version'] = (int)($account['token_version'] ?? 1);
                $_SESSION['last_activity'] = time();
                header('Location: ' . staffDashboardUrl());
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heartfelt Pharmacy | Staff Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/hp-logo-anim.js"></script>
</head>
<body class="login-page staff-auth-page">
    <!-- NEW: Shared animated bubbles/sparkles used by the dashboard backdrop. -->
    <div class="bg-anim" aria-hidden="true">
        <span class="bubble bubble-1"></span><span class="bubble bubble-2"></span><span class="bubble bubble-3"></span>
        <span class="bubble bubble-4"></span><span class="bubble bubble-5"></span><span class="bubble bubble-6"></span>
        <span class="spark spark-1">✦</span><span class="spark spark-2">✦</span><span class="spark spark-3">♥</span><span class="spark spark-4">♥</span>
    </div>
    <?php require __DIR__ . '/includes/bg_fx.php'; ?>
    <a href="index.php" class="home-button" aria-label="Home"><span aria-hidden="true">⌂</span></a>
    <div class="login-shell">
        <div class="login-card staff-auth-card">
            <div class="login-badge" aria-label="Heartfelt Pharmacy logo">
                <svg class="hp-logo" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <defs><linearGradient id="heartGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#ff8fbb"/><stop offset="100%" stop-color="#e63b80"/></linearGradient></defs>
                    <path d="M60 104C20 78 4 54 4 34 4 14 20 2 36 6 48 9 56 18 60 26 64 18 72 9 84 6 100 2 116 14 116 34c0 20-16 44-56 70Z" fill="url(#heartGrad)" stroke="#b8265f" stroke-width="2"/>
                    <g fill="#fff"><rect x="50" y="30" width="20" height="46" rx="4"/><rect x="34" y="46" width="52" height="20" rx="4"/></g>
                </svg>
            </div>
            <h1>Heartfelt Pharmacy</h1>
            <p>Staff Login</p>
            <?php if ($infoMessage !== '') : ?>
                <div class="alert alert-success" role="status"><?= htmlspecialchars($infoMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($message !== '') : ?>
                <div class="alert staff-login-alert" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($showResendLink) : ?>
                <!-- FIX (code flow): "Please verify your email first" leads straight to the code
                     screen, carrying the address so the user only has to type the 6 digits. -->
                <div class="login-secondary-link">
                    <a href="verify-email.php<?= !empty($_SESSION['pending_verification_email']) ? '?email=' . urlencode((string) $_SESSION['pending_verification_email']) : '' ?>" class="browse-link">Enter verification code</a>
                </div>
            <?php endif; ?>
            <form method="post" action="staff-login.php" data-auth-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label for="staff-username">Email or Username</label>
                <input id="staff-username" type="text" name="username" autocomplete="username" required>
                <label for="staff-password">Password</label>
                <div class="password-field">
                    <input id="staff-password" type="password" name="password" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path class="eye-outline" d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12Z" fill="none" stroke="currentColor" stroke-width="1.8"/>
                            <circle class="eye-outline" cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                            <path class="eye-slash" d="M4 4l16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                <button type="submit" class="btn btn-primary">Staff Login</button>
            </form>
            <!-- FIX (Task2): self-service password reset for staff accounts. -->
            <div class="login-secondary-link"><a href="forgot-password.php?role=staff" class="browse-link">Forgot your password?</a></div>
            <div class="login-secondary-link"><a href="staff-signup.php" class="browse-link">New staff? Sign up</a></div>
            <div class="login-secondary-link"><a href="staff/" class="browse-link">Back to Login</a></div>
        </div>
    </div>
    <!-- Shared show/hide password eye + submit lock while posting. -->
    <script src="assets/js/login-auth.js"></script>
    <script src="assets/js/bg-pause.js"></script>
</body>
</html>
