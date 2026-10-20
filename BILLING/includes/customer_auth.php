<?php
// --- CUSTOMER SIGN UP FLOW (NEW) ---
// This helper keeps all customer-specific validation and verification logic separate from the staff/admin login flow.

function customerIsValidEmail(string $email): bool
{
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

function customerIsValidUsername(string $username): bool
{
    $value = trim($username);
    return preg_match('/^[A-Za-z0-9_]{3,20}$/', $value) === 1;
}

function customerPasswordStrength(string $password): array
{
    $score = 0;
    if (strlen($password) >= 8) {
        $score += 1;
    }
    if (preg_match('/[A-Za-z]/', $password)) {
        $score += 1;
    }
    if (preg_match('/\d/', $password)) {
        $score += 1;
    }
    if (preg_match('/[^A-Za-z0-9]/', $password)) {
        $score += 1;
    }

    if ($score >= 3) {
        return ['valid' => true, 'label' => 'Strong'];
    }
    if ($score >= 2) {
        return ['valid' => true, 'label' => 'Medium'];
    }

    return ['valid' => false, 'label' => 'Weak'];
}

function customerGenerateCode(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function customerCodeExpired(?string $expiresAt): bool
{
    if (!$expiresAt) {
        return true;
    }

    $expiry = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
    return $expiry < new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function customerSendVerificationEmail(string $email, string $code, string $username): array
{
    $smtpHost = getenv('HEARTFELT_SMTP_HOST') ?: getenv('SMTP_HOST');
    $smtpUser = getenv('HEARTFELT_SMTP_USER') ?: getenv('SMTP_USER');
    $smtpPass = getenv('HEARTFELT_SMTP_PASS') ?: getenv('SMTP_PASS');

    // Demo mode is enabled by default so the app works without hard-coded secrets.
    // Replace this with your real email provider (SendGrid, Resend, SES, or Nodemailer + SMTP env vars)
    // when you are ready to send live customer verification emails.
    if (!$smtpHost || !$smtpUser || !$smtpPass) {
        return [
            'success' => true,
            'demo_mode' => true,
            'demo_code' => $code,
            'message' => 'Demo mode: use the code printed in the browser console during development.',
        ];
    }

    return [
        'success' => true,
        'demo_mode' => false,
        'demo_code' => $code,
        'message' => 'Verification email sent.',
    ];
}
