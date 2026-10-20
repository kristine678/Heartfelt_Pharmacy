<?php
// Shared helpers for staff signup rate limits.

// Return opaque rate-limit keys without persisting raw IP addresses.
function staffAuthSubjectHashes(?string $email = null): array
{
    $subjects = [hash('sha256', 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))];
    $normalizedEmail = strtolower(trim((string)$email));
    if ($normalizedEmail !== '') {
        $subjects[] = hash('sha256', 'email:' . $normalizedEmail);
    }
    return $subjects;
}

function staffAuthRateLimited(PDO $pdo, string $action, array $subjects, int $limit): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM staff_auth_attempts WHERE action = ? AND subject_hash = ? AND created_at >= ?');
    $since = date('Y-m-d H:i:s', time() - 3600);
    foreach ($subjects as $subject) {
        $stmt->execute([$action, $subject, $since]);
        if ((int)$stmt->fetchColumn() >= $limit) {
            return true;
        }
    }
    return false;
}

// Apply a fixed 60-second sign-up cooldown after five attempts in the preceding minute.
function staffAuthSignupCooldownRemaining(PDO $pdo, array $subjects, int $limit = 5, int $cooldownSeconds = 60): int
{
    $now = time();
    $cooldownStmt = $pdo->prepare("SELECT MAX(UNIX_TIMESTAMP(created_at)) FROM staff_auth_attempts WHERE action = 'signup_cooldown' AND subject_hash = ?");
    $attemptStmt = $pdo->prepare("SELECT COUNT(*) FROM staff_auth_attempts WHERE action = 'signup' AND subject_hash = ? AND UNIX_TIMESTAMP(created_at) > ?");
    $latestCooldown = 0;

    foreach (array_unique($subjects) as $subject) {
        $cooldownStmt->execute([$subject]);
        $latestCooldown = max($latestCooldown, (int)$cooldownStmt->fetchColumn());
    }

    $remaining = $latestCooldown > 0 ? $latestCooldown + $cooldownSeconds - $now : 0;
    if ($remaining > 0) {
        return $remaining;
    }

    foreach (array_unique($subjects) as $subject) {
        $attemptStmt->execute([$subject, $now - $cooldownSeconds]);
        if ((int)$attemptStmt->fetchColumn() >= $limit) {
            recordStaffAuthAttempt($pdo, 'signup_cooldown', $subjects);
            return $cooldownSeconds;
        }
    }

    return 0;
}

// Persist rate-limit events without storing submitted credentials.
function recordStaffAuthAttempt(PDO $pdo, string $action, array $subjects): void
{
    $stmt = $pdo->prepare('INSERT INTO staff_auth_attempts (action, subject_hash) VALUES (?, ?)');
    foreach (array_unique($subjects) as $subject) {
        $stmt->execute([$action, $subject]);
    }
}
