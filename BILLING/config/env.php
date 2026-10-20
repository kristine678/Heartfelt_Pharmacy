<?php
// Loads the project-root .env file into the PHP runtime (putenv + $_ENV + $_VALUES).
// Needed because Apache/mod_php does not read .env on its own, so getenv() would
// otherwise return false for APP_URL / TOKEN_SECRET / BILLING_SMTP_*.
//
// Rules:
//  - Real environment variables always win (never overridden).
//  - Lines: KEY=VALUE, '#' comments ignored, optional surrounding quotes stripped.
//  - Safe to include more than once (loadEnvFile() short-circuits).

function loadEnvFile(string $path): void
{
    static $loaded = false;
    if ($loaded || !is_readable($path)) {
        return;
    }
    $loaded = true;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '' || $value === '') {
            continue;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }
        $length = strlen($value);
        if ($length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

loadEnvFile(dirname(__DIR__) . '/.env');
