<?php
// ONE 6-digit code service, shared by two flows:
//
//   'verify' -> staff sign-up email verification (account stays status='pending' +
//               email_verified=0 until the code is entered)
//   'reset'  -> password reset for staff AND admin accounts
//
// Both flows use the SAME rules and the same code path, and each flow has its own columns, so a
// reset code can never overwrite an outstanding verification code (or the other way round).
//
// Security properties:
//   * The 6 digits come from random_int() - a cryptographically secure CSPRNG, never rand()/mt_rand().
//   * Only an HMAC-SHA256 of the code is stored (keyed with TOKEN_SECRET and the account id), so a
//     database leak cannot be replayed against another account.
//   * Codes expire after CODE_TTL_MINUTES (10) and are single-use: the stored hash is cleared in the
//     same UPDATE that consumes it, so a replay matches nothing.
//   * CODE_MAX_ATTEMPTS (5) wrong guesses burn the code; the user must request a new one.
//   * A 60-second resend cooldown is enforced server-side (not just in the UI), and asking for a new
//     code always invalidates the previous one.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/staff_auth.php';

const CODE_TTL_MINUTES = 10;
const CODE_RESEND_COOLDOWN_SECONDS = 60;
const CODE_MAX_ATTEMPTS = 5;

// Per-flow storage. Keeping the two sets of columns apart is what makes 'verify' and 'reset'
// independent: one outstanding code per flow, per account.
function codeFlowConfig(string $flow): array
{
    if ($flow === 'reset') {
        return [
            'flow' => 'reset',
            'hash' => 'reset_code_hash',
            'expires' => 'reset_expires_at',
            'attempts' => 'reset_attempts',
            'used' => 'reset_used_at',
            'sent' => 'reset_sent_at',
            'subject' => 'Your Heartfelt Pharmacy password reset code',
            'purpose' => 'reset your password',
            'console_label' => 'Password reset code',
            'done_message' => 'Your password has been updated. Please sign in with your new password.',
        ];
    }

    return [
        'flow' => 'verify',
        'hash' => 'verification_code_hash',
        'expires' => 'verification_expires_at',
        'attempts' => 'verification_attempts',
        'used' => 'verification_used_at',
        'sent' => 'verification_sent_at',
        'subject' => 'Your Heartfelt Pharmacy verification code',
        'purpose' => 'verify your email address',
        'console_label' => 'Email verification code',
        'done_message' => 'Email verified! Your account is now active.',
    ];
}

// Six digits, leading zeros preserved, generated with the CSPRNG.
function generateVerificationCode(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// The only thing ever written to the database. Keyed with the account id, so hashes are not
// transferable between accounts even if two users were handed the same code.
function codeHashFor(string $flow, int $userId, string $code): string
{
    $secret = (string) (getenv('TOKEN_SECRET') ?: 'heartfelt-pharmacy');
    return hash_hmac('sha256', $flow . ':' . $userId . ':' . $code, $secret);
}

// Seconds the account must wait before another code may be requested (0 = may send now).
function codeCooldownRemaining(array $account, string $flow): int
{
    $config = codeFlowConfig($flow);
    $sentAt = strtotime((string) ($account[$config['sent']] ?? ''));
    if ($sentAt <= 0) {
        return 0;
    }
    $remaining = $sentAt + CODE_RESEND_COOLDOWN_SECONDS - time();
    return $remaining > 0 ? $remaining : 0;
}

// True while the account holds a code that has not expired and has not been used.
function hasActiveCode(array $account, string $flow): bool
{
    $config = codeFlowConfig($flow);
    if (empty($account[$config['hash']]) || !empty($account[$config['used']])) {
        return false;
    }
    $expiresAt = strtotime((string) ($account[$config['expires']] ?? ''));
    return $expiresAt > time();
}

/**
 * Create (or replace) the code for an account and return it.
 *
 * Requesting a new code ALWAYS invalidates the previous one: the hash is overwritten and the
 * attempt counter is reset. Returns ['result' => 'issued'|'cooldown', 'code' => string|null,
 * 'cooldown' => int].
 */
function issueCode(PDO $pdo, int $userId, string $flow, bool $force = false): array
{
    $config = codeFlowConfig($flow);
    $statement = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$userId]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    if ($account === false) {
        return ['result' => 'missing_account', 'code' => null, 'cooldown' => 0];
    }

    if (!$force) {
        $cooldown = codeCooldownRemaining($account, $flow);
        if ($cooldown > 0) {
            return ['result' => 'cooldown', 'code' => null, 'cooldown' => $cooldown];
        }
    }

    $code = generateVerificationCode();
    $update = $pdo->prepare(
        "UPDATE users
         SET {$config['hash']} = ?,
             {$config['expires']} = ?,
             {$config['attempts']} = 0,
             {$config['used']} = NULL,
             {$config['sent']} = NOW()
         WHERE id = ?"
    );
    $update->execute([
        codeHashFor($flow, $userId, $code),
        date('Y-m-d H:i:s', time() + CODE_TTL_MINUTES * 60),
        $userId,
    ]);

    return ['result' => 'issued', 'code' => $code, 'cooldown' => 0];
}

// Burn the code without consuming it (too many wrong guesses, or an admin override).
function invalidateCode(PDO $pdo, int $userId, string $flow): void
{
    $config = codeFlowConfig($flow);
    $pdo->prepare("UPDATE users SET {$config['hash']} = NULL, {$config['expires']} = NULL WHERE id = ?")
        ->execute([$userId]);
}

/**
 * Check a submitted code.
 *
 * Returns 'ok' | 'invalid' | 'expired' | 'too_many' | 'no_code'. On 'ok' the code is consumed
 * (hash cleared + used flag set) so it can never be replayed; on 'invalid' the attempt counter
 * grows and the FIFTH wrong guess burns the code ('too_many').
 */
function consumeCode(PDO $pdo, array $account, string $flow, string $code, int $maxAttempts = CODE_MAX_ATTEMPTS): string
{
    $config = codeFlowConfig($flow);
    $userId = (int) $account['id'];
    $code = trim($code);

    if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
        return 'invalid';
    }
    if (empty($account[$config['hash']])) {
        return 'no_code';
    }

    $expiresAt = strtotime((string) ($account[$config['expires']] ?? ''));
    if ($expiresAt <= 0 || $expiresAt < time()) {
        invalidateCode($pdo, $userId, $flow);
        return 'expired';
    }

    $attempts = (int) ($account[$config['attempts']] ?? 0);
    if ($attempts >= $maxAttempts) {
        invalidateCode($pdo, $userId, $flow);
        return 'too_many';
    }

    if (!hash_equals((string) $account[$config['hash']], codeHashFor($flow, $userId, $code))) {
        $attempts++;
        $pdo->prepare("UPDATE users SET {$config['attempts']} = ? WHERE id = ?")->execute([$attempts, $userId]);
        if ($attempts >= $maxAttempts) {
            invalidateCode($pdo, $userId, $flow);
            return 'too_many';
        }
        return 'invalid';
    }

    // Correct code: consume it atomically (guarded by the hash it was matched against).
    $consume = $pdo->prepare(
        "UPDATE users
         SET {$config['hash']} = NULL,
             {$config['expires']} = NULL,
             {$config['used']} = NOW(),
             {$config['attempts']} = 0
         WHERE id = ?
           AND {$config['hash']} = ?"
    );
    $consume->execute([$userId, codeHashFor($flow, $userId, $code)]);
    if ($consume->rowCount() === 0) {
        // Lost a race against another request: the code is already gone.
        return 'no_code';
    }

    return 'ok';
}

// ---- Email delivery -------------------------------------------------------

// The email body: subject per the spec, the code in large text, and the 10-minute expiry note.
function codeEmailHtml(string $name, string $code, string $flow): string
{
    $config = codeFlowConfig($flow);
    $minutes = CODE_TTL_MINUTES;
    $safeName = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');
    $safePurpose = htmlspecialchars($config['purpose'], ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#fff5f9;'
        . 'font-family:Arial,Helvetica,sans-serif;color:#4a1030;">'
        . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #ffd7e8;'
        . 'border-radius:18px;padding:28px;">'
        . '<h1 style="margin:0 0 8px;font-size:20px;color:#b8265f;">Heartfelt Pharmacy</h1>'
        . "<p style=\"margin:0 0 18px;font-size:15px;\">Hi {$safeName}, use this code to {$safePurpose}:</p>"
        . '<div style="margin:0 0 18px;padding:18px 0;text-align:center;background:#fff0f6;'
        . 'border-radius:14px;">'
        . '<span style="font-size:40px;font-weight:800;letter-spacing:10px;color:#e63b80;">'
        . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>'
        . '</div>'
        . '<p style="margin:0 0 10px;font-size:15px;">This code expires in '
        . '<strong>' . $minutes . ' minutes</strong> and can be used only once.</p>'
        . '<p style="margin:0;font-size:13px;color:#8a5570;">If you did not request this, you can '
        . 'safely ignore this email.</p>'
        . '</div></body></html>';
}

function codeEmailText(string $name, string $code, string $flow): string
{
    $config = codeFlowConfig($flow);
    return 'Hi ' . ($name !== '' ? $name : 'there') . ",\n\n"
        . 'Your Heartfelt Pharmacy code to ' . $config['purpose'] . " is:\n\n"
        . "    {$code}\n\n"
        . 'It expires in ' . CODE_TTL_MINUTES . " minutes and can be used only once.\n"
        . "If you did not request this, you can safely ignore this email.\n";
}

/**
 * Send the code and report exactly what happened.
 *
 * Returns ['sent' => bool, 'result' => 'sent'|'dev_console'|'failed', 'message' => string].
 *
 * When no email service is configured (no credentials, or BILLING_MAIL_ENABLED=false) the code is
 * printed to the server console (Apache error log on XAMPP) instead, which is the documented
 * development fallback. Every send failure is logged with the SMTP reason, never swallowed.
 */
function sendCodeEmail(string $email, string $name, string $code, string $flow): array
{
    $config = codeFlowConfig($flow);
    $subject = $config['subject'];
    $html = codeEmailHtml($name, $code, $flow);
    $text = codeEmailText($name, $code, $flow);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log('[Heartfelt Pharmacy] Code email skipped: invalid recipient "' . $email . '"');
        return [
            'sent' => false,
            'result' => 'failed',
            'message' => 'That email address is not valid, so the code could not be sent.',
        ];
    }

    // Development convenience: always echo the code to the server console when enabled, so a
    // failed/slow mail provider can never block testing.
    $logEveryCode = strtolower((string) (getenv('EMAIL_DEBUG_LOG_CODES') ?: 'false')) === 'true';

    if (!mailIsConfigured()) {
        error_log(
            '[Heartfelt Pharmacy] ' . $config['console_label'] . ' for ' . $email . ': ' . $code
            . ' (valid ' . CODE_TTL_MINUTES . ' minutes) - no email service configured, '
            . 'so this is the development console fallback.'
        );
        return [
            'sent' => false,
            'result' => 'dev_console',
            'message' => 'Email sending is not configured, so the code was printed to the server console (development mode).',
        ];
    }

    $result = sendMail($email, $subject, $html, null, true);
    if (empty($result['sent'])) {
        error_log(
            '[Heartfelt Pharmacy] Failed to send ' . $config['console_label'] . ' to ' . $email
            . ': ' . (string) ($result['message'] ?? 'unknown SMTP error')
            . ' | code (dev fallback): ' . $code
            . ' (valid ' . CODE_TTL_MINUTES . ' minutes)'
        );
        return [
            'sent' => false,
            'result' => 'failed',
            'message' => 'We could not send the email right now (' . (string) ($result['message'] ?? 'SMTP error')
                . '). Please try again, or ask an administrator to check the mail settings.',
        ];
    }

    if ($logEveryCode) {
        error_log('[Heartfelt Pharmacy] ' . $config['console_label'] . ' for ' . $email . ': ' . $code);
    }

    return [
        'sent' => true,
        'result' => 'sent',
        'message' => 'We sent a 6-digit code to ' . $email . '. Check your inbox (and your spam folder).',
    ];
}

/**
 * Did the code reach the user by SOME supported route?
 *
 * 'sent'      - the email provider accepted it;
 * 'dev_console' - no email service is configured, so the documented development fallback printed
 *                 the code to the server console. That is a success for the flow (the developer can
 *                 read the code), just not an emailed one, so the UI must not claim a delivery
 *                 failure. Only a real 'failed' (SMTP error) is an error.
 */
function codeDeliveryOk(array $send): bool
{
    return !empty($send['sent']) || ($send['result'] ?? '') === 'dev_console';
}

// Short tag used in redirects / JSON so the screen can word the notice correctly.
function codeDeliveryState(array $send): string
{
    if (!empty($send['sent'])) {
        return 'sent';
    }
    return ($send['result'] ?? '') === 'dev_console' ? 'console' : 'failed';
}

// Plain-text copy kept for callers that prefer it (e.g. future plain-text-only providers).
function codeEmailParts(string $name, string $code, string $flow): array
{
    $config = codeFlowConfig($flow);
    return ['subject' => $config['subject'], 'html' => codeEmailHtml($name, $code, $flow), 'text' => codeEmailText($name, $code, $flow)];
}
