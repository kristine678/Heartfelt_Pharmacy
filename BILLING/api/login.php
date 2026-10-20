<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}

$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Username or password is missing.']);
    exit;
}

// Brute-force protection (same DB-backed helper the staff login form uses): max 10 attempts
// per hour, keyed by client IP and by submitted username, without storing raw IPs or credentials.
require_once __DIR__ . '/../includes/staff_auth.php';
$rateSubjects = staffAuthSubjectHashes('api-login:' . strtolower($username));
if (staffAuthRateLimited($pdo, 'api_login', $rateSubjects, 10)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many login attempts. Please try again later.']);
    exit;
}

// Email OR username, trimmed + case-insensitive (findUserForLogin in config/db.php).
$user = findUserForLogin($pdo, $username);

if (!$user || !password_verify($password, $user['password_hash'])) {
    recordStaffAuthAttempt($pdo, 'api_login', $rateSubjects);
    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    exit;
}
// FIX (staff verification): unverified/pending accounts cannot log in through any endpoint.
if (($user['status'] ?? 'active') === 'pending' || (int)($user['email_verified'] ?? 1) !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please verify your email first.']);
    exit;
}
if (($user['status'] ?? 'active') !== 'active') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This account is inactive. Please contact an administrator.']);
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['full_name'] = (string)($user['full_name'] ?? '');
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'] ?? 'staff';
// FIX (reset must kill old sessions): stamp the password version this session was authorised with.
$_SESSION['token_version'] = (int)($user['token_version'] ?? 1);
$_SESSION['last_activity'] = time();

echo json_encode([
    'success' => true,
    'redirect' => 'index.php',
    'message' => 'Login successful.',
]);
