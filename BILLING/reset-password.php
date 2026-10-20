<?php
// Reset password (Task 2, step 2 of 2): the 6-digit code is checked, then the new password is
// written as a fresh bcrypt hash on the SAME users row the login reads - and every existing
// session for that account is invalidated (config/db.php setUserPassword() bumps token_version,
// which requireLogin() checks on every request).
//
// Reached from forgot-password.php. Works for staff AND admin accounts; it does not change where
// each role may sign in (admins still only on the Admin page).
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/staff_auth.php';
require_once __DIR__ . '/includes/verification_code.php';
require_once __DIR__ . '/includes/password_field.php';

$role = (string) ($_GET['role'] ?? 'staff');
$isAdminFlow = $role === 'admin';
$loginUrl = $isAdminFlow ? staffLoginUrl() . '?reset=1' : 'staff-login.php?reset=1';
$backTo = $isAdminFlow ? staffLoginUrl() : 'staff-login.php';

if (empty($_SESSION['staff_auth_csrf'])) {
    $_SESSION['staff_auth_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['staff_auth_csrf'];

// The target address: an explicit ?email= (validated) beats the session's, so a user can also
// finish the flow after losing their session.
if (isset($_GET['email']) && is_string($_GET['email'])) {
    $requested = strtolower(trim($_GET['email']));
    if (filter_var($requested, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['pending_reset_email'] = $requested;
    }
}
$pendingEmail = strtolower(trim((string) ($_SESSION['pending_reset_email'] ?? '')));

$doneMessage = '';
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($postedEmail !== '' && filter_var($postedEmail, FILTER_VALIDATE_EMAIL)) {
        $pendingEmail = $postedEmail;
        $_SESSION['pending_reset_email'] = $postedEmail;
    }

    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    }

    $code = trim((string) ($_POST['code'] ?? ''));
    $newPassword = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!$errors && $pendingEmail === '') {
        $errors['form'] = 'Enter the email address you requested the reset code for.';
    }

    $account = null;
    if (!$errors) {
        $find = $pdo->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
        $find->execute([$pendingEmail]);
        $account = $find->fetch(PDO::FETCH_ASSOC);
        if ($account === false) {
            // Same generic wording as everywhere else on this flow: never confirm an address exists.
            $errors['form'] = 'Invalid or expired code. Request a new code and try again.';
        }
    }

    // ORDER MATTERS: the new password is validated BEFORE the code is consumed. A typo in the
    // confirm field (or a too-short password) must not burn the code and force a whole new one -
    // only a correct submission consumes it.
    if (!$errors && $account !== null) {
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            $errors['password'] = 'Use a password between 8 and 72 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $errors['password'] = 'Include at least 1 letter and 1 number.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match.';
        } elseif (password_verify($newPassword, (string) $account['password_hash'])) {
            // The whole point of a reset: the new password must actually be new.
            $errors['password'] = 'That is your current password. Choose a different one.';
        }
    }

    if (!$errors && $account !== null) {
        $subjects = staffAuthSubjectHashes($pendingEmail);
        if (staffAuthRateLimited($pdo, 'password_reset_verify', $subjects, 20)) {
            $errors['form'] = 'Too many attempts. Please try again later.';
        } else {
            $outcome = consumeCode($pdo, $account, 'reset', $code);
            if ($outcome === 'invalid') {
                $errors['code'] = 'Invalid code. Check the 6 digits and try again.';
            } elseif ($outcome === 'expired') {
                $errors['code'] = 'Code expired. Codes only last ' . CODE_TTL_MINUTES . ' minutes - request a new one.';
            } elseif ($outcome === 'too_many') {
                $errors['code'] = 'Too many attempts. That code has been cancelled - request a new one.';
            } elseif ($outcome === 'no_code') {
                $errors['code'] = 'That code is no longer valid. Request a new one.';
            }
            if ($outcome !== 'ok') {
                recordStaffAuthAttempt($pdo, 'password_reset_verify', $subjects);
            }
        }
    }

    if (!$errors && $account !== null) {
        // ONE write path: new bcrypt hash + token_version bump (kills every old session).
        setUserPassword($pdo, (int) $account['id'], $newPassword);
        // Any outstanding codes for this account are burned, and the pending marker is cleared.
        invalidateCode($pdo, (int) $account['id'], 'reset');
        invalidateCode($pdo, (int) $account['id'], 'verify');
        unset($_SESSION['pending_reset_email']);

        if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $account['id']) {
            // The person resetting their own password is signed out here and now; everyone else's
            // session for this account dies on their next request via requireLogin()'s check.
            session_unset();
            session_destroy();
        }
        $success = true;
        $doneMessage = 'Your password has been updated and all older sessions were signed out. Redirecting you to the login page...';
    }
}

$cooldownRemaining = 0;
if ($pendingEmail !== '') {
    $lookup = $pdo->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
    $lookup->execute([$pendingEmail]);
    $row = $lookup->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        $cooldownRemaining = codeCooldownRemaining($row, 'reset');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heartfelt Pharmacy | Reset Password</title>
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
            <p>Set a new password</p>

            <?php if ($success) : ?>
                <div class="alert alert-success" role="status"><?= htmlspecialchars($doneMessage, ENT_QUOTES, 'UTF-8') ?></div>
                <a class="btn btn-primary" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Go to Login</a>
                <script>window.setTimeout(function () { window.location.href = <?= json_encode($loginUrl) ?>; }, 2200);</script>
            <?php else : ?>
                <?php if (!empty($errors['form'])) : ?>
                    <div class="alert alert-error" role="alert"><?= htmlspecialchars($errors['form'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <ol class="reset-steps">
                    <li><span aria-hidden="true">1.</span> Enter the 6-digit code from your email.</li>
                    <li><span aria-hidden="true">2.</span> Choose a new password (at least 8 characters, 1 letter, 1 number).</li>
                </ol>

                <form id="reset-form" method="post" action="" data-auth-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <label for="reset-email">Email</label>
                    <input id="reset-email" name="email" type="email" inputmode="email" autocomplete="email"
                           value="<?= htmlspecialchars($pendingEmail, ENT_QUOTES, 'UTF-8') ?>" placeholder="you@example.com" required>

                    <label for="code-1">Reset code</label>
                    <div class="code-input-group" data-code-group role="group" aria-label="6-digit reset code">
                        <?php for ($i = 1; $i <= 6; $i++) : ?>
                            <input id="code-<?= $i ?>" class="code-box" data-code-box type="text" inputmode="numeric"
                                   pattern="[0-9]*" maxlength="1" autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>"
                                   aria-label="Digit <?= $i ?> of 6">
                        <?php endfor; ?>
                        <input type="hidden" name="code" data-code-value>
                    </div>
                    <div class="staff-field-error" data-error="code" aria-live="polite"><?= htmlspecialchars((string) ($errors['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>

                    <!-- FIX (Task4): both new-password fields get the shared eye icon, and the confirm
                         field shows a live "Passwords match / do not match" message. -->
                    <!-- FIX (reset submit broken): 'name' is the wire key the handler reads
                         ($_POST['password'] / $_POST['confirm_password']), not the DOM id. -->
                    <?= passwordField([
                        'id' => 'new-password',
                        'name' => 'password',
                        'label' => 'New Password',
                        'autocomplete' => 'new-password',
                        'hint' => '8-72 characters, including 1 letter and 1 number.',
                        'error_key' => 'password',
                        'error_text' => (string) ($errors['password'] ?? ''),
                    ]) ?>
                    <?= passwordField([
                        'id' => 'confirm-password',
                        'name' => 'confirm_password',
                        'label' => 'Confirm New Password',
                        'autocomplete' => 'new-password',
                        'error_key' => 'confirm_password',
                        'match_with' => 'new-password',
                        'error_text' => (string) ($errors['confirm_password'] ?? ''),
                    ]) ?>

                    <button id="reset-submit" type="submit" class="btn btn-primary"><span>Reset Password</span></button>
                </form>

                <div id="resend-block" data-cooldown-until="<?= (int) (time() + $cooldownRemaining) ?>" style="margin-top:12px;">
                    <button id="resend-button" type="button" class="btn btn-secondary" <?= $cooldownRemaining === 0 ? '' : 'disabled' ?>>Resend reset code</button>
                    <div id="resend-countdown" class="staff-signup-countdown" aria-live="polite"></div>
                    <div id="resend-message" class="staff-auth-message" role="alert" aria-live="polite"></div>
                </div>
            <?php endif; ?>

            <div class="login-secondary-link">
                <a href="<?= htmlspecialchars($backTo, ENT_QUOTES, 'UTF-8') ?>" class="browse-link">Back to Login</a>
            </div>
        </div>
    </div>
    <script>
    (() => {
        const block = document.getElementById('resend-block');
        if (!block) return;
        const button = document.getElementById('resend-button');
        const countdownEl = document.getElementById('resend-countdown');
        const messageEl = document.getElementById('resend-message');
        const emailInput = document.getElementById('reset-email');
        let cooldownUntil = Number(block.dataset.cooldownUntil || 0);
        let ticker = 0;

        function tick() {
            const remaining = Math.max(0, cooldownUntil - Math.floor(Date.now() / 1000));
            if (remaining > 0) {
                countdownEl.textContent = `You can request a new code in ${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}.`;
                button.disabled = true;
            } else {
                countdownEl.textContent = '';
                button.disabled = false;
                window.clearInterval(ticker);
                ticker = 0;
            }
        }

        if (cooldownUntil > Math.floor(Date.now() / 1000)) {
            ticker = window.setInterval(tick, 1000);
            tick();
        }

        button.addEventListener('click', async () => {
            const email = emailInput ? emailInput.value.trim() : '';
            messageEl.textContent = '';
            if (email === '') {
                messageEl.textContent = 'Enter your email address first.';
                return;
            }
            button.disabled = true;
            button.classList.add('is-loading');
            try {
                // The resend endpoint is the same one forgot-password.php uses: it always answers
                // with the generic sentence and re-issues (invalidating) the old code. Asking for
                // JSON keeps the page in place instead of navigating away.
                const response = await fetch('forgot-password.php?role=<?= $isAdminFlow ? 'admin' : 'staff' ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
                    body: new URLSearchParams({
                        email: email,
                        format: 'json',
                        csrf_token: <?= json_encode($csrfToken) ?>
                    }).toString()
                });
                const result = await response.json();
                messageEl.textContent = result.message || 'If this email is registered, a new code was sent.';
                if (window.showToast) window.showToast(messageEl.textContent);
                cooldownUntil = Math.floor(Date.now() / 1000) + 60;
                if (!ticker) ticker = window.setInterval(tick, 1000);
                tick();
            } catch (error) {
                messageEl.textContent = 'Unable to connect. Please try again.';
                button.disabled = false;
            } finally {
                button.classList.remove('is-loading');
                if (cooldownUntil <= Math.floor(Date.now() / 1000)) button.disabled = false;
            }
        });
    })();
    </script>
    <script src="assets/js/login-auth.js"></script>
    <script src="assets/js/bg-pause.js"></script>
</body>
</html>
