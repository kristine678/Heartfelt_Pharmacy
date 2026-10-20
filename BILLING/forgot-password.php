<?php
// Forgot password (Task 2, step 1 of 2): the user enters their email and - if that address belongs
// to an account - a 6-digit reset code is emailed. The response is ALWAYS the same generic sentence,
// so this page can never be used to discover which emails are registered.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/staff_auth.php';
require_once __DIR__ . '/includes/verification_code.php';

$role = (string) ($_GET['role'] ?? 'staff');
$backTo = $role === 'admin' ? staffLoginUrl() : 'staff-login.php';

if (isLoggedIn()) {
    header('Location: ' . staffDashboardUrl());
    exit;
}
if (empty($_SESSION['staff_auth_csrf'])) {
    $_SESSION['staff_auth_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['staff_auth_csrf'];

$genericMessage = 'If this email is registered, a 6-digit reset code was sent to it.';
$done = false;
$message = '';
$messageKind = 'info';

// The reset screen's "Resend reset code" button posts here too, asking for JSON so the page stays put.
$wantsJson = (string) ($_POST['format'] ?? '') === 'json'
    || strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
$respondJson = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $message = 'Your session expired. Refresh the page and try again.';
        $messageKind = 'error';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        // Rate limit BEFORE the lookup, keyed by IP + submitted address (hashes only).
        $subjects = staffAuthSubjectHashes($email);
        if (staffAuthRateLimited($pdo, 'password_reset_request', $subjects, 10)) {
            $message = 'Too many reset requests. Please try again in an hour.';
            $messageKind = 'error';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Enter a valid email address.';
            $messageKind = 'error';
        } else {
            $subjects = staffAuthSubjectHashes($email);
            recordStaffAuthAttempt($pdo, 'password_reset_request', $subjects);

            $find = $pdo->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
            $find->execute([$email]);
            $account = $find->fetch(PDO::FETCH_ASSOC);

            if ($account !== false) {
                // The account exists: issue + send the code. The user is told the same generic
                // sentence either way, but a delivery problem is logged (and reported) so the
                // failure is never silent.
                $issued = issueCode($pdo, (int) $account['id'], 'reset');
                if ($issued['result'] === 'cooldown') {
                    // Still report success shape; the countdown lives on the next step.
                    $_SESSION['pending_reset_email'] = $email;
                    $done = true;
                } elseif ($issued['result'] === 'issued') {
                    $send = sendCodeEmail($email, (string) $account['full_name'], (string) $issued['code'], 'reset');
                    $_SESSION['pending_reset_email'] = $email;
                    $done = true;
                    if (!codeDeliveryOk($send)) {
                        // Not silent: the SMTP reason is shown, and the code is in the server log.
                        $message = 'Your code was created, but ' . $send['message'];
                        $messageKind = 'error';
                    }
                } else {
                    $message = 'Unable to create a reset code right now. Please try again.';
                    $messageKind = 'error';
                }
            } else {
                // Unknown address: identical wording, and a deliberate delay-free no-op.
                $done = true;
                error_log('[Heartfelt Pharmacy] Password reset requested for an unknown email address.');
            }

            if ($done && $message === '') {
                $message = $genericMessage;
                $messageKind = 'info';
            }
        }
    }
}

// ONE JSON exit for every POST outcome (rate limit, invalid email, sent, unknown address), so the
// reset screen's resend button always gets a machine-readable answer.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $wantsJson) {
    $respondJson([
        'success' => $done,
        'message' => $message !== '' ? $message : $genericMessage,
    ], $done ? 200 : 422);
}

$pendingEmail = strtolower(trim((string) ($_SESSION['pending_reset_email'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heartfelt Pharmacy | Forgot Password</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/hp-logo-anim.js"></script>
</head>
<body class="login-page staff-auth-page">
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
            <p>Reset your password</p>

            <?php if ($message !== '') : ?>
                <div class="alert<?= $messageKind === 'error' ? ' alert-error' : '' ?>" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($done) : ?>
                <p class="muted verify-copy">
                    Enter the 6-digit code on the next screen. It expires in <?= CODE_TTL_MINUTES ?> minutes
                    and works only once. Check your spam folder if it does not arrive within a minute.
                </p>
                <p class="muted verify-copy">
                    After you set a new password, every older session (and the old password) stops working
                    straight away.
                </p>
                <a class="btn btn-primary" href="reset-password.php?role=<?= htmlspecialchars($role === 'admin' ? 'admin' : 'staff', ENT_QUOTES, 'UTF-8') ?><?= $pendingEmail !== '' ? '&email=' . urlencode($pendingEmail) : '' ?>">Enter reset code</a>
            <?php else : ?>
                <form method="post" action="" data-auth-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <label for="reset-email">Email</label>
                    <input id="reset-email" name="email" type="email" inputmode="email" autocomplete="email"
                           placeholder="you@example.com" required>
                    <button type="submit" class="btn btn-primary"><span>Send reset code</span></button>
                </form>
                <p class="muted verify-copy" style="margin-top:12px;">
                    For your security we always show the same message: this screen never reveals whether
                    an email address has an account.
                </p>
            <?php endif; ?>

            <div class="login-secondary-link">
                <a href="<?= htmlspecialchars($backTo, ENT_QUOTES, 'UTF-8') ?>" class="browse-link">Back to Login</a>
            </div>
        </div>
    </div>
    <script src="assets/js/login-auth.js"></script>
    <script src="assets/js/bg-pause.js"></script>
</body>
</html>
