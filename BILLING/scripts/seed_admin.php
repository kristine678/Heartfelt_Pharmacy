<?php
/**
 * Seed / reset the administrator account.
 *
 * Credentials are read from the .env file in the project root (preferred) or from
 * real environment variables -- nothing sensitive lives in source code. Real
 * environment variables win over the file, so CI/production can override .env.
 *
 * Usage:
 *   Bash:        php scripts/seed_admin.php
 *   PowerShell:  php scripts\seed_admin.php
 *   CMD:         php scripts\seed_admin.php
 *
 * .env keys read:
 *   ADMIN_USERNAME   (default: admin)
 *   ADMIN_PASSWORD   (required, at least 8 characters)
 *   ADMIN_FULL_NAME  (default: Administrator)
 *   ADMIN_EMAIL      (optional; must be a valid address when set)
 *
 * The password is stored ONLY as a bcrypt hash (PASSWORD_BCRYPT), never in plain text.
 * Re-running with a changed ADMIN_PASSWORD rotates the admin password and repairs the
 * account in place (role=admin, status=active, email_verified=1).
 */

if (PHP_SAPI !== 'cli') {
    // Never expose the seeder over HTTP.
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// Minimal .env loader (no dependencies): KEY=VALUE lines, '#' comments ignored,
// existing environment variables are never overridden.
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '' || $value === '') {
            continue;
        }
        $valueLength = strlen($value);
        if ($valueLength >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$valueLength - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

// DB connection + the app's own idempotent migrations, so the users table and its
// full_name / status / created_at / email / email_verified columns exist even on a
// brand-new database.
require __DIR__ . '/../config/db.php';

$username = trim((string)(getenv('ADMIN_USERNAME') ?: 'admin'));
$fullName = trim((string)(getenv('ADMIN_FULL_NAME') ?: 'Administrator'));
$email = trim((string)(getenv('ADMIN_EMAIL') ?: ''));
$password = (string)(getenv('ADMIN_PASSWORD') ?: '');

if ($username === '' || $password === '') {
    fwrite(STDERR, "ADMIN_PASSWORD (and optionally ADMIN_USERNAME) must be set in the .env file or as environment variables.\n");
    fwrite(STDERR, "Example .env line: ADMIN_PASSWORD=your-chosen-password\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "ADMIN_PASSWORD must be at least 8 characters long.\n");
    exit(1);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ADMIN_EMAIL is not a valid email address.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

// Parameterised upsert: creates the admin, or repairs an existing record in place
// (bcrypt hash, role=admin, status=active, email_verified=1). Relies on users.username
// being UNIQUE (see migrate_user_management_columns() in config/db.php).
$statement = $pdo->prepare(
    "INSERT INTO users (username, password_hash, role, status, full_name, email, email_verified)
     VALUES (?, ?, 'admin', 'active', ?, ?, 1)
     ON DUPLICATE KEY UPDATE
        password_hash = VALUES(password_hash),
        role = 'admin',
        status = 'active',
        full_name = VALUES(full_name),
        email = COALESCE(VALUES(email), email),
        email_verified = 1"
);
$statement->execute([$username, $hash, $fullName, $email !== '' ? $email : null]);

// Clear stale login rate-limit rows left behind by earlier failed attempts, so a
// freshly seeded admin can sign in immediately instead of waiting out a lockout window.
$pdo->exec("DELETE FROM staff_auth_attempts WHERE action IN ('staff_login', 'api_login')");

echo "Admin account '{$username}' is ready (role=admin, status=active, email_verified=1).\n";
echo 'Stored hash: ' . substr($hash, 0, 7) . '...' . substr($hash, -4) . " (bcrypt)\n";
echo 'Credentials source: ' . (is_readable($envFile) ? $envFile : 'environment variables') . "\n";
echo "Reminder: change the default admin password before going live --\n";
echo "          edit ADMIN_PASSWORD in .env and re-run this script.\n";
