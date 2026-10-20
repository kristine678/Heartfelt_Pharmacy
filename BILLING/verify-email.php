<?php
// "Verify your email" screen (Task 1, code flow).
//
//  GET                     -> the 6-box code screen (+ Resend code button with a 60s countdown)
//  GET ?sent=1&mail=0|1    -> same screen right after sign-up, with the real send outcome
//  GET ?email=...          -> prefilled target, used by the Staff login "Please verify your email
//                             first" link (and by anyone who lost the session)
//  POST action=verify      -> checks the 6-digit code (JSON)
//  POST action=resend      -> issues + sends a NEW code, invalidating the previous one (JSON)
//
// Nothing here reveals whether an address has an account: verification responses only ever talk
// about the code that was submitted.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/staff_auth.php';
require_once __DIR__ . '/includes/verification_code.php';

if (isLoggedIn()) {
    header('Location: ' . staffDashboardUrl());
    exit;
}
if (empty($_SESSION['staff_auth_csrf'])) {
    $_SESSION['staff_auth_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['staff_auth_csrf'];

// The address the code was sent to: explicit ?email= (validated) wins, otherwise the session's.
if (isset($_GET['email']) && is_string($_GET['email'])) {
    $requested = strtolower(trim($_GET['email']));
    if (filter_var($requested, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['pending_verification_email'] = $requested;
    }
}
$pendingEmail = strtolower(trim((string) ($_SESSION['pending_verification_email'] ?? '')));

// Look the pending account up so the screen can show the masked address and the cooldown.
$account = null;
if ($pendingEmail !== '' && filter_var($pendingEmail, FILTER_VALIDATE_EMAIL)) {
    $find = $pdo->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
    $find->execute([$pendingEmail]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        $account = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $respond = static function (array $payload, int $status = 200): void {
        http_response_code($status);
        echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    };

    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $respond(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
    }
    $action = (string) ($_POST['action'] ?? '');
    $submittedEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($submittedEmail !== '' && filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
        $pendingEmail = $submittedEmail;
        $account = null;
        $find = $pdo->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
        $find->execute([$pendingEmail]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $account = $row;
        }
    }
    if ($submittedEmail !== '' && $submittedEmail !== (string) ($_SESSION['pending_verification_email'] ?? '')) {
        $_SESSION['pending_verification_email'] = $submittedEmail;
    }

    // ---- Resend: a NEW code, which invalidates the previous one -----------------------------
    if ($action === 'resend') {
        if ($account === null) {
            $respond(['success' => false, 'message' => 'Enter the email address you signed up with to get a new code.'], 422);
        }
        if ((int) ($account['email_verified'] ?? 0) === 1 && (string) ($account['status'] ?? '') === 'active') {
            $respond(['success' => false, 'message' => 'This email is already verified - just sign in.'], 409);
        }
        $subjects = staffAuthSubjectHashes($pendingEmail);
        if (staffAuthRateLimited($pdo, 'code_resend', $subjects, 10)) {
            $respond(['success' => false, 'message' => 'Too many codes requested. Please try again in an hour.'], 429);
        }

        // 60-second cooldown is enforced here (server-side), not just by the countdown in the UI.
        $issued = issueCode($pdo, (int) $account['id'], 'verify');
        if ($issued['result'] === 'cooldown') {
            $respond([
                'success' => false,
                'cooldown' => (int) $issued['cooldown'],
                'cooldown_until' => time() + (int) $issued['cooldown'],
                'message' => 'Please wait ' . (int) $issued['cooldown'] . ' seconds before requesting another code.',
            ], 429);
        }
        if ($issued['result'] !== 'issued') {
            $respond(['success' => false, 'message' => 'Unable to issue a new code right now. Please try again.'], 500);
        }
        recordStaffAuthAttempt($pdo, 'code_resend', $subjects);

        $send = sendCodeEmail($pendingEmail, (string) $account['full_name'], (string) $issued['code'], 'verify');
        if (!codeDeliveryOk($send)) {
            // The code WAS replaced; only delivery failed. Say so plainly, with the SMTP reason.
            $respond([
                'success' => false,
                'code_issued' => true,
                'delivery' => 'failed',
                'cooldown' => CODE_RESEND_COOLDOWN_SECONDS,
                'cooldown_until' => time() + CODE_RESEND_COOLDOWN_SECONDS,
                'message' => $send['message'],
            ], 502);
        }
        $delivery = codeDeliveryState($send);
        $respond([
            'success' => true,
            'delivery' => $delivery,
            'message' => $delivery === 'console'
                ? 'A new 6-digit code was created and printed to the server console (no email service is configured). Your previous code no longer works.'
                : 'A new 6-digit code was sent to ' . $pendingEmail . '. The previous code no longer works.',
            'cooldown' => CODE_RESEND_COOLDOWN_SECONDS,
            'cooldown_until' => time() + CODE_RESEND_COOLDOWN_SECONDS,
        ]);
    }

    // ---- Verify: the submitted 6-digit code -------------------------------------------------
    if ($action === 'verify') {
        if ($account === null) {
            $respond(['success' => false, 'message' => 'Enter the email address you signed up with, then the code we sent you.'], 422);
        }
        // Brute-force guard: a 6-digit space is small, so wrong codes are capped per IP+address on
        // top of the 5-attempts-per-code limit enforced inside consumeCode().
        $subjects = staffAuthSubjectHashes($pendingEmail);
        if (staffAuthRateLimited($pdo, 'code_verify', $subjects, 20)) {
            $respond(['success' => false, 'message' => 'Too many attempts. Please try again later.'], 429);
        }

        $code = (string) ($_POST['code'] ?? '');
        $outcome = consumeCode($pdo, $account, 'verify', $code);
        if ($outcome === 'ok') {
            // Activation: verified + active, in one statement, so the account becomes usable now.
            $activate = $pdo->prepare("UPDATE users SET email_verified = 1, status = 'active' WHERE id = ?");
            $activate->execute([(int) $account['id']]);
            unset($_SESSION['pending_verification_email']);
            $respond([
                'success' => true,
                'message' => 'Email verified! Your account is now active. Redirecting you to the Staff login...',
                'redirect' => 'staff-login.php?verified=1',
            ]);
        }

        recordStaffAuthAttempt($pdo, 'code_verify', $subjects);

        $messages = [
            'invalid' => 'Invalid code. Check the 6 digits and try again.',
            'expired' => 'Code expired. Codes only last ' . CODE_TTL_MINUTES . ' minutes - request a new one.',
            'too_many' => 'Too many attempts. That code has been cancelled - request a new one.',
            'no_code' => 'That code is no longer valid. Request a new one.',
        ];
        $message = $messages[$outcome] ?? 'Invalid code.';
        // A burned code always unlocks the Resend button immediately (no pointless 60s wait).
        $cooldown = in_array($outcome, ['expired', 'too_many', 'no_code'], true) ? 0 : codeCooldownRemaining($account, 'verify');
        $respond([
            'success' => false,
            'outcome' => $outcome,
            'cooldown' => $cooldown,
            'cooldown_until' => $cooldown > 0 ? time() + $cooldown : 0,
            'message' => $message,
        ], 422);
    }

    $respond(['success' => false, 'message' => 'Invalid request.'], 400);
}

$notice = '';
$noticeKind = 'info'; // info | success | error
if (isset($_GET['sent'])) {
    $delivery = (string) ($_GET['mail'] ?? 'sent');
    if ($delivery === 'failed') {
        $noticeKind = 'error';
        $notice = 'Your account was created, but we could not send the email. Use "Resend code" below, or ask an administrator to check the mail settings.';
    } elseif ($delivery === 'console') {
        // Development mode with no email service: the code is in the server console, as documented.
        $noticeKind = 'info';
        $notice = 'Your account was created. No email service is configured, so your 6-digit code was printed in the server console (Apache error log).';
    } else {
        $noticeKind = 'info';
        $notice = 'We sent a 6-digit code to ' . ($pendingEmail !== '' ? $pendingEmail : 'your email')
            . '. Enter it below to activate your account.';
    }
}
if (($_GET['code'] ?? '') === 'expired') {
    $noticeKind = 'error';
    $notice = 'That code has expired. Request a new one below.';
}

$cooldownRemaining = $account !== null ? codeCooldownRemaining($account, 'verify') : 0;
$hasAccount = $account !== null;
// Never print a raw email address into HTML without escaping; the screen shows it as text only.
$displayEmail = $account !== null ? (string) $account['email'] : $pendingEmail;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heartfelt Pharmacy | Verify Email</title>
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
            <p>Verify your email</p>
            <div class="verify-icon" aria-hidden="true">✉</div>

            <?php if ($notice !== '') : ?>
                <div class="alert<?= $noticeKind === 'success' ? ' alert-success' : ($noticeKind === 'error' ? ' alert-error' : '') ?>" role="alert"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <p class="muted verify-copy">
                Enter the 6-digit code we sent
                <?php if ($hasAccount) : ?>to <strong><?= htmlspecialchars($displayEmail, ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>.
                It expires in <?= CODE_TTL_MINUTES ?> minutes and can be used once.
            </p>

            <form id="verify-form" data-auth-form novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="verify">
                <?php if (!$hasAccount) : ?>
                    <!-- Session lost (new browser/after sign-out): let the user name the address again. -->
                    <label for="verify-email">Email</label>
                    <input id="verify-email" name="email" type="email" inputmode="email" autocomplete="email"
                           placeholder="you@example.com" value="<?= htmlspecialchars($displayEmail, ENT_QUOTES, 'UTF-8') ?>" required>
                <?php else : ?>
                    <input type="hidden" name="email" value="<?= htmlspecialchars($displayEmail, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>

                <label for="code-1">Verification code</label>
                <!-- Six single-character boxes: numeric keypad on mobile, auto-advance, paste support
                     and auto-submit when the 6th digit lands (assets/js/login-auth.js). -->
                <div class="code-input-group" data-code-group data-auto-submit="true" role="group" aria-label="6-digit verification code">
                    <?php for ($i = 1; $i <= 6; $i++) : ?>
                        <input id="code-<?= $i ?>" class="code-box" data-code-box type="text" inputmode="numeric"
                               pattern="[0-9]*" maxlength="1" autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>"
                               aria-label="Digit <?= $i ?> of 6"<?= $i === 1 ? ' autofocus' : '' ?>>
                    <?php endfor; ?>
                    <input type="hidden" name="code" data-code-value>
                </div>
                <div id="verify-message" class="staff-auth-message" role="alert" aria-live="polite"></div>

                <button id="verify-submit" type="submit" class="btn btn-primary"><span>Verify</span></button>
            </form>

            <div id="resend-block" data-cooldown-until="<?= (int) (time() + $cooldownRemaining) ?>" style="margin-top:12px;">
                <button id="resend-button" type="button" class="btn btn-secondary" <?= $hasAccount && $cooldownRemaining === 0 ? '' : 'disabled' ?>>
                    <span>Resend code</span>
                </button>
                <div id="resend-countdown" class="staff-signup-countdown" aria-live="polite"></div>
                <div id="resend-message" class="staff-auth-message" role="alert" aria-live="polite"></div>
            </div>

            <div class="login-secondary-link"><a href="staff-login.php" class="browse-link">Back to Staff Login</a></div>
            <div class="login-secondary-link"><a href="staff-signup.php" class="browse-link">New staff? Sign up</a></div>
        </div>
    </div>
    <script>
    (() => {
        const verifyForm = document.getElementById('verify-form');
        const verifyButton = document.getElementById('verify-submit');
        const verifyMessage = document.getElementById('verify-message');
        const resendBlock = document.getElementById('resend-block');
        const resendButton = document.getElementById('resend-button');
        const resendMessage = document.getElementById('resend-message');
        const resendCountdown = document.getElementById('resend-countdown');
        const csrfToken = <?= json_encode($csrfToken) ?>;
        const hasAccount = <?= $hasAccount ? 'true' : 'false' ?>;
        let cooldownUntil = Number(resendBlock.dataset.cooldownUntil || 0);
        let ticker = 0;

        // ---- 60-second resend countdown -------------------------------------------------------
        function tick() {
            const remaining = Math.max(0, cooldownUntil - Math.floor(Date.now() / 1000));
            if (remaining > 0) {
                resendCountdown.textContent = `You can request a new code in ${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}.`;
                resendButton.disabled = true;
            } else {
                resendCountdown.textContent = '';
                resendButton.disabled = false;
                window.clearInterval(ticker);
                ticker = 0;
            }
        }

        function startCooldown(seconds, until) {
            cooldownUntil = until || Math.floor(Date.now() / 1000) + seconds;
            if (!ticker) ticker = window.setInterval(tick, 1000);
            tick();
        }

        if (!hasAccount) {
            resendButton.disabled = false;
        } else if (cooldownUntil > Math.floor(Date.now() / 1000)) {
            ticker = window.setInterval(tick, 1000);
            tick();
        }

        function clearCodeBoxes() {
            document.querySelectorAll('[data-code-box]').forEach((box) => { box.value = ''; box.classList.remove('is-filled'); });
            const hidden = document.querySelector('[data-code-value]');
            if (hidden) hidden.value = '';
            const first = document.getElementById('code-1');
            if (first) first.focus();
        }

        function post(payload) {
            return fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
                body: new URLSearchParams(payload).toString()
            }).then(async (response) => {
                try {
                    return await response.json();
                } catch (error) {
                    return { success: false, message: `Unexpected server response (HTTP ${response.status}).` };
                }
            });
        }

        // ---- Verify ---------------------------------------------------------------------------
        verifyForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const data = new FormData(verifyForm);
            const code = String(data.get('code') || '');
            const email = String(data.get('email') || '');
            verifyMessage.textContent = '';
            if (!/^\d{6}$/.test(code)) {
                verifyMessage.textContent = 'Enter all 6 digits of the code.';
                return;
            }
            if (email === '') {
                verifyMessage.textContent = 'Enter the email address you signed up with.';
                return;
            }
            verifyButton.disabled = true;
            verifyButton.classList.add('is-loading');
            try {
                const result = await post({ action: 'verify', code: code, email: email, csrf_token: csrfToken });
                if (result.success) {
                    verifyMessage.textContent = result.message;
                    verifyMessage.classList.add('is-success');
                    if (window.showToast) window.showToast(result.message);
                    window.setTimeout(() => { window.location.href = result.redirect || 'staff-login.php?verified=1'; }, 1200);
                    return;
                }
                verifyMessage.textContent = result.message || 'Invalid code.';
                if (window.showToast) window.showToast(verifyMessage.textContent);
                clearCodeBoxes();
                if (result.cooldown_until) {
                    startCooldown(Number(result.cooldown || 60), Number(result.cooldown_until));
                } else {
                    resendButton.disabled = false;
                }
            } catch (error) {
                verifyMessage.textContent = 'Unable to connect. Please try again.';
            } finally {
                verifyButton.classList.remove('is-loading');
                verifyButton.disabled = false;
            }
        });

        // ---- Resend ---------------------------------------------------------------------------
        resendButton.addEventListener('click', async () => {
            const emailInput = document.getElementById('verify-email');
            const email = emailInput ? emailInput.value.trim() : (document.querySelector('input[name="email"]') || {}).value || '';
            resendMessage.textContent = '';
            verifyMessage.textContent = '';
            resendButton.disabled = true;
            resendButton.classList.add('is-loading');
            try {
                const result = await post({ action: 'resend', email: email, csrf_token: csrfToken });
                resendMessage.textContent = result.message || '';
                if (window.showToast && resendMessage.textContent) window.showToast(resendMessage.textContent);
                if (result.success) {
                    startCooldown(Number(result.cooldown || 60), Number(result.cooldown_until || 0));
                    clearCodeBoxes();
                } else if (result.cooldown_until) {
                    startCooldown(Number(result.cooldown || 60), Number(result.cooldown_until || 0));
                } else {
                    resendButton.disabled = false;
                }
            } catch (error) {
                resendMessage.textContent = 'Unable to connect. Please try again.';
                resendButton.disabled = false;
            } finally {
                resendButton.classList.remove('is-loading');
                if (cooldownUntil <= Math.floor(Date.now() / 1000)) resendButton.disabled = false;
            }
        });
    })();
    </script>
    <script src="assets/js/login-auth.js"></script>
    <script src="assets/js/bg-pause.js"></script>
</body>
</html>
