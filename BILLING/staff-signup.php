<?php
// Staff sign-up (Task 1): creates the account as status='pending' + email_verified=0 and emails a
// 6-digit code. Nothing about the account becomes usable until that code is entered on
// verify-email.php, which flips it to status='active' + email_verified=1.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/staff_auth.php';
require_once __DIR__ . '/includes/verification_code.php';
require_once __DIR__ . '/includes/password_field.php';

if (isLoggedIn()) {
    header('Location: ' . staffDashboardUrl());
    exit;
}

if (empty($_SESSION['staff_auth_csrf'])) {
    $_SESSION['staff_auth_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['staff_auth_csrf'];

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $respond(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
    }
    if ((string)($_POST['action'] ?? '') !== 'signup') {
        $respond(['success' => false, 'message' => 'Invalid request.'], 400);
    }

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $subjects = staffAuthSubjectHashes($email);
    $cooldown = staffAuthSignupCooldownRemaining($pdo, $subjects);
    if ($cooldown > 0) {
        $respond([
            'success' => false,
            'cooldown' => $cooldown,
            'cooldown_until' => time() + $cooldown,
            'message' => 'Too many attempts. Please try again in ' . $cooldown . ' seconds.',
        ], 429);
    }
    recordStaffAuthAttempt($pdo, 'signup', $subjects);

    $errors = [];
    $nameLength = function_exists('mb_strlen') ? mb_strlen($fullName, 'UTF-8') : strlen($fullName);
    if ($fullName === '' || $nameLength > 150) {
        $errors['full_name'] = 'Enter a full name up to 150 characters.';
    }
    if (!preg_match('/^[A-Za-z0-9._]{3,20}$/', $username)) {
        $errors['username'] = 'Use 3-20 letters, numbers, periods, or underscores.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (strlen($password) < 8 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Use 8-72 characters with at least 1 letter and 1 number.';
    }
    if ($confirmPassword !== $password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }
    if (!$errors) {
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1');
        $duplicate->execute([$username]);
        if ($duplicate->fetchColumn()) {
            $errors['username'] = 'Username already taken.';
        }
        // Duplicate emails are rejected (case-insensitive) - the code must reach exactly one account.
        $duplicateEmail = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
        $duplicateEmail->execute([$email]);
        if ($duplicateEmail->fetchColumn()) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }
    if ($errors) {
        $respond(['success' => false, 'errors' => $errors], 422);
    }

    try {
        // The account is saved FIRST (status pending, unverified) and the code is issued after -
        // never the other way round, so a code can never exist without an account behind it.
        $insert = $pdo->prepare("INSERT INTO users (full_name, username, email, password_hash, role, status, email_verified) VALUES (?, ?, ?, ?, 'staff', 'pending', 0)");
        $insert->execute([$fullName, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $newUserId = (int)$pdo->lastInsertId();
    } catch (PDOException $exception) {
        if ((string)$exception->getCode() === '23000') {
            $respond(['success' => false, 'errors' => ['username' => 'Username already taken.']], 409);
        }
        throw $exception;
    }

    // Remember which address is pending so the "Verify your email" screen can address it and offer
    // the 60-second "Resend code" button without asking for the email again.
    $_SESSION['pending_verification_email'] = $email;

    // FIX (code flow): issue the 6-digit code (force = true, the account was created seconds ago)
    // and try to send it. The send outcome is reported to the page instead of being swallowed.
    $issued = issueCode($pdo, $newUserId, 'verify', true);
    $send = sendCodeEmail($email, $fullName, (string) $issued['code'], 'verify');

    // The account is created either way; only the delivery channel differs. 'console' is the
    // documented development fallback (no email service configured), not a failure.
    $delivery = codeDeliveryState($send);
    $respond([
        'success' => true,
        'code_sent' => $send['sent'],
        'delivery' => $delivery,
        'mail_result' => $send['result'],
        'message' => $delivery === 'sent'
            ? 'Code sent! Check your email for your 6-digit verification code.'
            : ($delivery === 'console'
                ? 'Your account was created. No email service is configured, so your code was printed in the server console.'
                : 'Your account was created, but ' . $send['message']),
        'redirect' => 'verify-email.php?sent=1&mail=' . $delivery,
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heartfelt Pharmacy | Staff Sign Up</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/hp-logo-anim.js"></script>
</head>
<body class="login-page staff-signup-page">
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
            <p>Staff account registration</p>
            <section class="staff-auth-step" aria-labelledby="signup-title">
                <h2 id="signup-title" class="staff-auth-heading">Create your staff account</h2>
                <form id="staff-signup-form" data-auth-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="signup">
                    <label for="full_name">Full Name</label>
                    <input id="full_name" name="full_name" type="text" maxlength="150" autocomplete="name" required>
                    <div class="staff-field-error" data-error="full_name" aria-live="polite"></div>
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" minlength="3" maxlength="20" pattern="[A-Za-z0-9._]{3,20}" autocomplete="username" required>
                    <div class="staff-field-error" data-error="username" aria-live="polite"></div>
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" maxlength="255" autocomplete="email" inputmode="email" placeholder="you@example.com" required>
                    <div class="staff-field-error" data-error="email" aria-live="polite"></div>
                    <!-- FIX (Task4): the eye icon now lives INSIDE both password fields, from the
                         one shared passwordField() renderer, and the confirm field shows a live
                         "Passwords match / do not match" message. -->
                    <?= passwordField([
                        'id' => 'password',
                        'label' => 'Password',
                        'autocomplete' => 'new-password',
                        'hint' => '8-72 characters, including 1 letter and 1 number.',
                    ]) ?>
                    <!-- The live "Passwords match / do not match" line sits under the CONFIRM field
                         (one message, not two), watching the field it is compared against. -->
                    <?= passwordField([
                        'id' => 'confirm_password',
                        'label' => 'Confirm Password',
                        'autocomplete' => 'new-password',
                        'error_key' => 'confirm_password',
                        'match_with' => 'password',
                        'match_label' => 'Passwords',
                    ]) ?>
                    <div id="signup-message" class="staff-auth-message" role="alert" aria-live="polite"></div>
                    <div id="signup-countdown" class="staff-signup-countdown" aria-live="polite"></div>
                    <button id="signup-submit" type="submit" class="btn btn-primary"><span>Sign Up</span></button>
                </form>
            </section>
            <div class="login-secondary-link"><a class="browse-link" href="staff-login.php">Back to Login</a></div>
        </div>
    </div>
    <script>
    (() => {
        const form = document.getElementById('staff-signup-form');
        const button = document.getElementById('signup-submit');
        const message = document.getElementById('signup-message');
        const countdown = document.getElementById('signup-countdown');
        const errors = [...form.querySelectorAll('.staff-field-error')];
        let cooldownUntil = 0;
        let ticker = 0;

        function showCooldown(seconds, until, text) {
            window.clearInterval(ticker);
            cooldownUntil = until || Math.floor(Date.now() / 1000) + seconds;
            message.textContent = text || 'Too many attempts. Please try again in 60 seconds.';
            const tick = () => {
                const remaining = Math.max(0, cooldownUntil - Math.floor(Date.now() / 1000));
                countdown.textContent = remaining
                    ? `Please try again in ${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}.`
                    : '';
                if (!remaining) {
                    window.clearInterval(ticker);
                    cooldownUntil = 0;
                    message.textContent = '';
                    button.disabled = false;
                }
            };
            tick();
            button.disabled = true;
            ticker = window.setInterval(tick, 1000);
        }

        form.addEventListener('input', (event) => {
            const error = form.querySelector(`[data-error="${event.target.name}"]`);
            if (error) error.textContent = '';
            message.textContent = '';
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            errors.forEach((error) => { error.textContent = ''; });
            message.textContent = '';
            button.disabled = true;
            button.classList.add('is-loading');
            button.querySelector('span').textContent = 'Creating account...';
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
                    body: new URLSearchParams(new FormData(form)).toString()
                });
                const result = await response.json();
                if (result.success) {
                    message.textContent = result.message;
                    if (window.showToast) window.showToast(result.message);
                    form.reset();
                    button.disabled = true;
                    // Straight to the code screen. ?mail=0 tells it the send failed, so it offers
                    // "Resend code" immediately instead of claiming an email is on its way.
                    window.setTimeout(() => { window.location.href = result.redirect || 'verify-email.php?sent=1'; }, 900);
                    return;
                }
                if (result.cooldown > 0) {
                    showCooldown(Number(result.cooldown), Number(result.cooldown_until), result.message);
                    return;
                }
                if (result.errors) {
                    Object.entries(result.errors).forEach(([field, text]) => {
                        const error = form.querySelector(`[data-error="${field}"]`);
                        if (error) error.textContent = text;
                    });
                } else {
                    message.textContent = result.message || 'Unable to create the account.';
                    if (window.showToast) window.showToast(message.textContent);
                }
            } catch (error) {
                message.textContent = 'Unable to connect. Please try again.';
                if (window.showToast) window.showToast(message.textContent);
            } finally {
                button.classList.remove('is-loading');
                button.querySelector('span').textContent = 'Sign Up';
                if (!cooldownUntil && message.textContent === '') button.disabled = false;
            }
        });
    })();
    </script>
    <!-- Shared eye toggle + passwords-match / do-not-match message (FIX Task4). -->
    <script src="assets/js/login-auth.js"></script>
    <script src="assets/js/bg-pause.js"></script>
</body>
</html>
