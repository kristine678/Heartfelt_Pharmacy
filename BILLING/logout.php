<?php
require_once 'config/db.php';

// Logout fully tears the session down: clear the session data, expire the
// session cookie in the browser, then destroy the session on the server, so
// the next request to any protected page redirects back to the login screen.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
header('Location: ' . staffLoginUrl());
exit;
