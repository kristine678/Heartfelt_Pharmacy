<?php
require_once 'config/db.php';
require_once 'includes/verification_code.php';
require_once 'includes/password_field.php';
// TASK 3: the page registry + permission writes used by the "Manage access" modal below.
require_once 'includes/permissions.php';

// FIX (Task2): SERVER-SIDE admin gate for this page AND its JSON endpoints. requireLogin()
// refreshes the session first (timeout/status), then any non-admin gets HTTP403 - JSON for
// the page's AJAX callers, plain text when a staff user types the URL into the browser.
requireLogin();
// TASK 2/3: this page is admin-only and never grantable. A staff account that TYPES the URL is sent
// to the Menu with the shared message (instead of a bare 403 page), while this page's own AJAX
// callers (POST action / JSON Accept) still get a JSON 403, which is what assets/js expects.
$wantsJson = isset($_POST['action'])
    || (strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false)
    || (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest');
requirePageAccess('users', $wantsJson);

$activePage = 'users';
$roles = ['admin', 'staff'];
if (empty($_SESSION['users_csrf_token'])) {
    $_SESSION['users_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['users_csrf_token'];

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

    $action = (string)($_POST['action'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);
    $currentUserId = (int)$_SESSION['user_id'];

    if ($action === 'save') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $role = (string)($_POST['role'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $errors = [];
        $nameLength = function_exists('mb_strlen') ? mb_strlen($fullName, 'UTF-8') : strlen($fullName);

        if ($fullName === '' || $nameLength > 150) {
            $errors[] = 'Enter a full name up to 150 characters.';
        }
        if (!preg_match('/^[A-Za-z0-9._]{3,20}$/', $username)) {
            $errors[] = 'Use 3-20 letters, numbers, periods, or underscores for the username.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address or leave it blank.';
        }
        if (!in_array($role, $roles, true)) {
            $errors[] = 'Choose a valid role.';
        }
        if (($userId === 0 && $password === '') || $password !== '') {
            if (strlen($password) < 8 || strlen($password) > 72) {
                $errors[] = 'Password must be 8-72 characters.';
            }
            if ($confirmPassword !== $password) {
                $errors[] = 'Passwords do not match.';
            }
        }
        if ($userId < 0) {
            $errors[] = 'Invalid user.';
        }
        if ($errors) {
            $respond(['success' => false, 'message' => implode(' ', $errors)], 422);
        }

        $duplicate = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(username)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
        $duplicate->execute([$username, $userId]);
        if ($duplicate->fetchColumn()) {
            $respond(['success' => false, 'message' => 'Username already taken.'], 409);
        }
        if ($email !== '') {
            $duplicateEmail = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
            $duplicateEmail->execute([$email, $userId]);
            if ($duplicateEmail->fetchColumn()) {
                $respond(['success' => false, 'message' => 'That email address is already in use.'], 409);
            }
        }

        try {
            if ($userId === 0) {
                // Admin-created accounts start active + email-verified (the admin IS the approval).
                $insert = $pdo->prepare("INSERT INTO users (full_name, username, email, password_hash, role, status, email_verified) VALUES (?, ?, ?, ?, ?, 'active', 1)");
                $insert->execute([$fullName, $username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $role]);
                $_SESSION['alert'] = 'User added successfully.';
            } else {
                $exists = $pdo->prepare('SELECT id, role, status FROM users WHERE id = ?');
                $exists->execute([$userId]);
                $existingUser = $exists->fetch();
                if (!$existingUser) {
                    $respond(['success' => false, 'message' => 'That user no longer exists.'], 404);
                }
                if ($existingUser['role'] === 'admin' && $role !== 'admin' && $existingUser['status'] === 'active') {
                    $activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
                    if ($activeAdmins <= 1) {
                        $respond(['success' => false, 'message' => 'You cannot change the role of the last active administrator.'], 422);
                    }
                }
                if ($password !== '') {
                    $update = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, password_hash = ?, role = ? WHERE id = ?');
                    $update->execute([$fullName, $username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $role, $userId]);
                } else {
                    $update = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, role = ? WHERE id = ?');
                    $update->execute([$fullName, $username, $email !== '' ? $email : null, $role, $userId]);
                }
                $_SESSION['alert'] = 'User updated successfully.';
            }
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '23000') {
                $respond(['success' => false, 'message' => 'Username already taken.'], 409);
            }
            throw $exception;
        }
        $respond(['success' => true]);
    }

    if ($action === 'reset_password') {
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if ($userId <= 0 || strlen($password) < 8 || strlen($password) > 72 || $confirmPassword !== $password) {
            $respond(['success' => false, 'message' => 'Enter a matching password between 8 and 72 characters.'], 422);
        }
        // FIX (old password kept working): the update used to write the hash and nothing else, so
        // every session that had authenticated with the OLD password stayed valid. setUserPassword()
        // writes the new hash AND bumps token_version, which requireLogin() checks on every request -
        // the previous credential (and any open tab) is now useless.
        $target = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1');
        $target->execute([$userId]);
        $targetUser = $target->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            $respond(['success' => false, 'message' => 'That user no longer exists.'], 404);
        }
        if (password_verify($password, (string) $targetUser['password_hash'])) {
            $respond(['success' => false, 'message' => 'That is already this user\'s password. Choose a different one.'], 422);
        }
        setUserPassword($pdo, $userId, $password);
        // An admin reset also kills any outstanding verification/reset code for that account.
        invalidateCode($pdo, $userId, 'verify');
        invalidateCode($pdo, $userId, 'reset');
        $_SESSION['alert'] = 'Password reset successfully. That user was signed out everywhere.';
        $respond(['success' => true, 'message' => 'Password reset. The user must sign in again with the new password.']);
    }

    if ($action === 'toggle_status') {
        $status = (string)($_POST['status'] ?? '');
        if ($userId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            $respond(['success' => false, 'message' => 'Invalid status change.'], 422);
        }
        if ($status === 'inactive' && $userId === $currentUserId) {
            $respond(['success' => false, 'message' => 'You cannot deactivate your own account.'], 422);
        }
        $target = $pdo->prepare('SELECT role, status FROM users WHERE id = ? LIMIT 1');
        $target->execute([$userId]);
        $targetUser = $target->fetch();
        if (!$targetUser) {
            $respond(['success' => false, 'message' => 'That user no longer exists.'], 404);
        }
        if ($status === 'inactive' && $targetUser['role'] === 'admin' && $targetUser['status'] === 'active') {
            $activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($activeAdmins <= 1) {
                $respond(['success' => false, 'message' => 'You cannot deactivate the last active administrator.'], 422);
            }
        }
        if ($status === 'active') {
            // FIX (Task2): activating a pending/unverified account IS the admin's verification
            // override - "Activate" must actually let the person log in, so it also marks the
            // email verified and burns any outstanding token (it was sent to the old address).
            $update = $pdo->prepare('UPDATE users SET status = ?, email_verified = 1, verification_token_hash = NULL, verification_expires_at = NULL WHERE id = ?');
        } else {
            $update = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        }
        $update->execute([$status, $userId]);
        $_SESSION['alert'] = $status === 'active' ? 'User activated.' : 'User deactivated.';
        $respond(['success' => true]);
    }

    if ($action === 'delete') {
        if ($userId <= 0) {
            $respond(['success' => false, 'message' => 'Invalid user.'], 422);
        }
        if ($userId === $currentUserId) {
            $respond(['success' => false, 'message' => 'You cannot delete your own account.'], 422);
        }

        $target = $pdo->prepare('SELECT role, status FROM users WHERE id = ? LIMIT 1');
        $target->execute([$userId]);
        $targetUser = $target->fetch();
        if (!$targetUser) {
            $respond(['success' => false, 'message' => 'That user no longer exists.'], 404);
        }
        if ($targetUser['role'] === 'admin' && $targetUser['status'] === 'active') {
            $activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($activeAdmins <= 1) {
                $respond(['success' => false, 'message' => 'You cannot delete the last active administrator.'], 422);
            }
        }
        $delete = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $delete->execute([$userId]);
        $_SESSION['alert'] = 'User deleted.';
        $respond(['success' => true]);
    }

    // TASK 3: grant/revoke a staff member's extra pages. Admin-only (this whole file is behind the
    // admin gate above), CSRF-checked above, and validated against the grantable page list before
    // anything is written — an unknown or admin-only page name is rejected outright.
    if ($action === 'save_pages') {
        $submitted = $_POST['pages'] ?? [];
        if (!is_array($submitted)) {
            $submitted = [$submitted];
        }

        $clean = [];
        foreach ($submitted as $page) {
            $page = trim((string)$page);
            if ($page === '') {
                continue;
            }
            if (!in_array($page, grantablePages(), true)) {
                $respond(['success' => false, 'message' => 'That page cannot be granted to a staff account.'], 422);
            }
            if (!in_array($page, $clean, true)) {
                $clean[] = $page;
            }
        }

        $target = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ? LIMIT 1');
        $target->execute([$userId]);
        $targetUser = $target->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            $respond(['success' => false, 'message' => 'That user no longer exists.'], 404);
        }
        if ((string)$targetUser['role'] === 'admin') {
            $respond(['success' => false, 'message' => 'Administrators already have access to every page.'], 422);
        }

        $actor = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $actor->execute([$currentUserId]);
        $actorLabel = 'admin #' . $currentUserId . ' ' . (string)($actor->fetchColumn() ?: '');

        $result = setUserPages($pdo, $userId, $clean, $currentUserId, trim($actorLabel));
        if (!$result['success']) {
            $respond(['success' => false, 'message' => $result['message']], 422);
        }

        // The staff member's very next request re-reads user_pages, so the change (including a
        // revocation) applies immediately - no re-signup, no waiting for a session to expire.
        $grants = $result['pages'];
        $_SESSION['alert'] = 'Access updated for ' . (string)$targetUser['username'] . ': ' . ($grants ? pageLabels($grants) : 'default pages only') . '.';
        $respond([
            'success' => true,
            'message' => $_SESSION['alert'],
            'pages' => $grants,
            'labels' => array_map('pageLabel', $grants),
        ]);
    }

    if ($action === 'resend_verification') {
        $target = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $target->execute([$userId]);
        $targetUser = $target->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            $respond(['success' => false, 'message' => 'That user no longer exists.'], 404);
        }
        if ((string) ($targetUser['role'] ?? '') === 'admin') {
            $respond(['success' => false, 'message' => 'The administrator account is already verified.'], 422);
        }
        // FIX (code flow): the resend now issues a 6-digit code (invalidating the previous one)
        // instead of a link, and reports a real delivery failure instead of a success message.
        $targetEmail = strtolower(trim((string) ($targetUser['email'] ?? '')));
        if ($targetEmail === '' || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            $respond(['success' => false, 'message' => 'That account has no valid email address.'], 422);
        }
        if ((int) ($targetUser['email_verified'] ?? 0) === 1 && (string) ($targetUser['status'] ?? '') === 'active') {
            $respond(['success' => false, 'message' => 'That account is already verified - nothing to send.'], 409);
        }
        $issued = issueCode($pdo, (int) $targetUser['id'], 'verify');
        if ($issued['result'] === 'cooldown') {
            $respond([
                'success' => false,
                'cooldown' => (int) $issued['cooldown'],
                'message' => 'Please wait ' . (int) $issued['cooldown'] . ' seconds before sending another code.',
            ], 429);
        }
        if ($issued['result'] !== 'issued') {
            $respond(['success' => false, 'message' => 'Unable to issue a verification code right now.'], 500);
        }
        $send = sendCodeEmail($targetEmail, (string) ($targetUser['full_name'] ?? ''), (string) $issued['code'], 'verify');
        if (!codeDeliveryOk($send)) {
            $respond(['success' => false, 'message' => $send['message']], 502);
        }
        $message = codeDeliveryState($send) === 'console'
            ? 'A new verification code was created for ' . $targetEmail . ' and printed in the server console (no email service configured).'
            : 'A new verification code was sent to ' . $targetEmail . '.';
        $_SESSION['alert'] = $message;
        $respond([
            'success' => true,
            'message' => $message,
            'cooldown' => CODE_RESEND_COOLDOWN_SECONDS,
        ]);
    }

    $respond(['success' => false, 'message' => 'Invalid request.'], 400);
}

$search = trim((string)($_GET['search'] ?? ''));
$filterRole = (string)($_GET['role'] ?? 'all');
$filterStatus = (string)($_GET['status'] ?? 'all');
if (!in_array($filterRole, array_merge(['all'], $roles), true)) {
    $filterRole = 'all';
}
$validStatuses = ['pending', 'active', 'inactive'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = 'all';
}
$query = "SELECT id, full_name, username, email, role, status, email_verified, created_at FROM users WHERE 1 = 1";
$params = [];
if ($search !== '') {
    $query .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($filterRole !== 'all') {
    $query .= ' AND role = ?';
    $params[] = $filterRole;
}
if ($filterStatus !== 'all') {
    $query .= ' AND status = ?';
    $params[] = $filterStatus;
}
$query .= ' ORDER BY id DESC';
$statement = $pdo->prepare($query);
$statement->execute($params);
$users = $statement->fetchAll();

// TASK 3: every stored grant in ONE query (avoids a query per row), grouped by user id.
$grantsByUser = [];
foreach ($pdo->query('SELECT user_id, page FROM user_pages')->fetchAll(PDO::FETCH_ASSOC) as $grantRow) {
    $grantsByUser[(int)$grantRow['user_id']][] = (string)$grantRow['page'];
}

include 'includes/header.php';
?>
<div class="page-title users-page-title">
    <div class="users-title-group">
        <h1>Users</h1>
        <div class="users-title-actions">
            <button type="button" class="btn btn-secondary" id="refresh-users-button"><i class="fa-solid fa-rotate"></i> Refresh</button>
            <button type="button" class="btn btn-primary" id="add-user-button">Add User</button>
        </div>
    </div>
    <form method="get" class="filter-row users-filter">
        <input type="search" name="search" placeholder="Search name, username or email" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" aria-label="Search name, username or email">
        <select name="role" aria-label="Filter by role">
            <option value="all" <?= $filterRole === 'all' ? 'selected' : '' ?>>All roles</option>
            <?php foreach ($roles as $role) : ?>
                <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" <?= $filterRole === $role ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="Filter by status">
            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All statuses</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending verification</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Disabled</option>
        </select>
        <button class="btn btn-primary" type="submit">Filter</button>
    </form>
</div>
<?php if (isset($_SESSION['alert'])) : ?>
    <div class="alert"><?= htmlspecialchars((string)$_SESSION['alert'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>
<div class="table-wrap">
    <?php if (!$users) : ?>
        <div class="empty-state">No users match your search.</div>
    <?php else : ?>
        <table class="table-basic users-table">
            <thead>                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Access</th>
                        <th>Status</th>
                        <th>Verified</th>
                        <th>Joined</th>
                        <th>Action</th>
                    </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user) :
                    $fullName = (string)$user['full_name'];
                    $rawStatus = (string)$user['status'];
                    $userStatus = in_array($rawStatus, ['pending', 'active', 'inactive'], true) ? $rawStatus : 'active';
                    $statusLabels = ['pending' => 'Pending verification', 'active' => 'Active', 'inactive' => 'Disabled'];
                    $statusLabel = $statusLabels[$userStatus];
                    $isVerified = (int)($user['email_verified'] ?? 0) === 1;
                    $userEmail = trim((string)($user['email'] ?? ''));
                    $isRowAdmin = (string)$user['role'] === 'admin';
                ?>
                    <tr<?= $userStatus === 'pending' ? ' class="user-row-pending"' : '' ?>>
                        <td><?= (int)$user['id'] ?></td>
                        <td><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $userEmail !== '' ? htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') : '<span class="muted">&mdash;</span>' ?></td>
                        <td><?= htmlspecialchars(ucfirst((string)$user['role']), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php
                        // TASK 3: the pages this row may open. The locked-on defaults are shown
                        // muted; every extra grant the admin gave is a highlighted badge.
                        $rowGrants = $isRowAdmin ? [] : array_values(array_intersect(grantablePages(), $grantsByUser[(int)$user['id']] ?? []));
                        $rowGrantKeys = implode(',', $rowGrants);
                        $rowGrantLabels = $rowGrants ? pageLabels($rowGrants) : '';
                        ?>
                        <td class="access-cell">
                            <?php if ($isRowAdmin) : ?>
                                <span class="access-badge admin" title="Administrators have access to every page">All pages</span>
                            <?php else : ?>
                                <span class="access-badge default" title="Included for every staff account">Menu · History · Help</span>
                                <?php foreach ($rowGrants as $grantedPage) : ?>
                                    <span class="access-badge granted"><?= htmlspecialchars(pageLabel($grantedPage), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="user-status-badge <?= htmlspecialchars($userStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><span class="user-verified-badge <?= $isVerified ? 'yes' : 'no' ?>"><?= $isVerified ? 'Yes' : 'No' ?></span></td>
                        <td><?= htmlspecialchars((string)$user['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <div class="row-actions users-row-actions">
                                <button type="button" class="btn btn-secondary user-edit-button"
                                    data-id="<?= (int)$user['id'] ?>"
                                    data-full-name="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>"
                                    data-username="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-email="<?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?>"
                                    data-role="<?= htmlspecialchars((string)$user['role'], ENT_QUOTES, 'UTF-8') ?>">Edit</button>
                                <button type="button" class="btn btn-primary user-reset-button" data-id="<?= (int)$user['id'] ?>" data-username="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>">Reset Password</button>
                                <button type="button" class="btn <?= $userStatus === 'active' ? 'btn-secondary' : 'btn-primary' ?> user-status-button" data-id="<?= (int)$user['id'] ?>" data-status="<?= $userStatus === 'active' ? 'inactive' : 'active' ?>"><?= $userStatus === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                <?php if (!$isRowAdmin) : ?>
                                    <!-- TASK 3: opens the page-permission modal for this staff row. -->
                                    <button type="button" class="btn btn-secondary user-access-button"
                                        data-id="<?= (int)$user['id'] ?>"
                                        data-username="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-pages="<?= htmlspecialchars($rowGrantKeys, ENT_QUOTES, 'UTF-8') ?>"
                                        data-pages-label="<?= htmlspecialchars($rowGrantLabels, ENT_QUOTES, 'UTF-8') ?>">Manage access</button>
                                <?php endif; ?>
                                <?php if (!$isRowAdmin) : ?>
                                    <button type="button" class="btn btn-secondary user-resend-button" data-id="<?= (int)$user['id'] ?>" data-username="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>">Resend Verification</button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-danger user-delete-button" data-id="<?= (int)$user['id'] ?>" data-username="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>">Delete</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="users-modal" id="user-modal" aria-hidden="true">
    <div class="users-modal-card" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
        <button type="button" class="users-modal-close" id="user-modal-close" aria-label="Close dialog">&times;</button>
        <h2 id="user-modal-title">Add User</h2>
        <form id="user-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" id="user-form-action" value="save">
            <input type="hidden" name="user_id" id="user-form-id" value="0">
            <div class="users-form-fields" id="user-details-fields">
                <label for="user-full-name">Full Name</label>
                <input id="user-full-name" name="full_name" type="text" maxlength="150" required>
                <label for="user-username">Username</label>
                <input id="user-username" name="username" type="text" minlength="3" maxlength="20" pattern="[A-Za-z0-9._]{3,20}" required>
                <label for="user-email">Email <span class="muted" style="font-weight:400;">(optional)</span></label>
                <input id="user-email" name="email" type="email" maxlength="255" autocomplete="email">
                <label for="user-role">Role</label>
                <select id="user-role" name="role" required>
                    <?php foreach ($roles as $role) : ?>
                        <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="users-form-fields" id="user-password-fields">
                <!-- FIX (Task4): the admin modal uses the same shared password fields, so admin-set
                     passwords get the eye icon and the live match message too. -->
                <!-- FIX (admin modal broken): 'name' is the wire key this page's handler reads
                     ($_POST['password'] / $_POST['confirm_password']); the ids stay for the JS. -->
                <?= passwordField([
                    'id' => 'user-password',
                    'name' => 'password',
                    'label' => 'Password',
                    'autocomplete' => 'new-password',
                    'required' => false,
                    'match_with' => 'user-confirm-password',
                ]) ?>
                <?= passwordField([
                    'id' => 'user-confirm-password',
                    'name' => 'confirm_password',
                    'label' => 'Confirm Password',
                    'autocomplete' => 'new-password',
                    'required' => false,
                    'error_key' => 'user-confirm-password',
                    'match_with' => 'user-password',
                ]) ?>
            </div>
            <div id="user-form-message" class="users-form-message" role="alert" aria-live="polite"></div>
            <div class="pink-modal-actions">
                <button type="button" class="btn btn-secondary" id="user-modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary" id="user-modal-save">Save User</button>
            </div>
        </form>
    </div>
</div>

<!-- TASK 3: "Manage access" — grant or revoke extra pages for a staff account. The locked-on
     defaults (Menu, History, Help Center) are shown but cannot be switched off, and Users/Setting
     are intentionally absent: those stay administrator-only. -->
<div class="users-modal" id="perm-modal" aria-hidden="true">
    <div class="users-modal-card" role="dialog" aria-modal="true" aria-labelledby="perm-modal-title">
        <button type="button" class="users-modal-close" id="perm-modal-close" aria-label="Close dialog">&times;</button>
        <h2 id="perm-modal-title">Manage access</h2>
        <p class="muted perm-subtitle" id="perm-modal-sub">Choose the extra pages this staff account can open.</p>
        <!-- Own container class (not .users-form-fields) so the text-input styling there never
             leaks onto these checkboxes. -->
        <div class="perm-fields">
            <p class="perm-group-title">Always available</p>
            <div class="perm-locked">
                <?php foreach (defaultStaffPages() as $defaultPage) : ?>
                    <label class="perm-locked-item">
                        <input type="checkbox" checked disabled>
                        <?= htmlspecialchars(pageLabel($defaultPage), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="perm-group-title">Extra pages</p>
            <div class="perm-list">
                <?php foreach (grantablePages() as $grantablePage) : ?>
                    <label class="perm-option">
                        <input type="checkbox" class="perm-checkbox" value="<?= htmlspecialchars($grantablePage, ENT_QUOTES, 'UTF-8') ?>">
                        <span>
                            <strong><?= htmlspecialchars(pageLabel($grantablePage), ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars(pageDescription($grantablePage), ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="perm-note"><i class="fa-solid fa-lock" aria-hidden="true"></i> Users and Setting stay administrator-only and cannot be granted.</p>
        </div>
        <div id="perm-form-message" class="users-form-message" role="alert" aria-live="polite"></div>
        <div class="pink-modal-actions">
            <button type="button" class="btn btn-secondary" id="perm-modal-cancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="perm-modal-save">Save access</button>
        </div>
    </div>
</div>
<script>
// TASK 3: Manage-access modal. Sends the ticked pages to this same page's save_pages action; the
// server re-validates every page name against the grantable list and writes an audit-log entry.
(() => {
    const modal = document.getElementById('perm-modal');
    if (!modal) {
        return;
    }

    const triggeredBy = document; // the buttons live in the table above
    const checkboxes = Array.from(modal.querySelectorAll('.perm-checkbox'));
    const message = document.getElementById('perm-form-message');
    const saveButton = document.getElementById('perm-modal-save');
    const subtitle = document.getElementById('perm-modal-sub');
    let currentUserId = 0;

    function csrfToken() {
        return document.querySelector('#user-form input[name="csrf_token"]')?.value || '';
    }

    function open(button) {
        currentUserId = button.dataset.id;
        const granted = (button.dataset.pages || '').split(',').filter((value) => value !== '');
        checkboxes.forEach((checkbox) => {
            checkbox.checked = granted.includes(checkbox.value);
        });
        subtitle.textContent = 'Choose the extra pages ' + (button.dataset.username || 'this staff member') + ' can open.';
        message.textContent = '';
        modal.classList.add('visible');
        modal.setAttribute('aria-hidden', 'false');
        checkboxes[0]?.focus();
    }

    function close() {
        modal.classList.remove('visible');
        modal.setAttribute('aria-hidden', 'true');
    }

    function toast(text) {
        if (typeof window.showToast === 'function') {
            window.showToast(text);
        } else {
            window.alert(text);
        }
    }

    triggeredBy.querySelectorAll('.user-access-button').forEach((button) => {
        button.addEventListener('click', () => open(button));
    });
    document.getElementById('perm-modal-close').addEventListener('click', close);
    document.getElementById('perm-modal-cancel').addEventListener('click', close);
    modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('visible')) close();
    });

    saveButton.addEventListener('click', async () => {
        const body = new URLSearchParams();
        body.set('action', 'save_pages');
        body.set('user_id', String(currentUserId));
        body.set('csrf_token', csrfToken());
        checkboxes.forEach((checkbox) => {
            if (checkbox.checked) body.append('pages[]', checkbox.value);
        });

        saveButton.disabled = true;
        const originalLabel = saveButton.textContent;
        saveButton.textContent = 'Saving…';
        message.textContent = '';
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
                body: body.toString()
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result || !result.success) {
                message.textContent = result?.message || 'Unable to save the access changes.';
                saveButton.disabled = false;
                saveButton.textContent = originalLabel;
                return;
            }
            close();
            toast(result.message || 'Access updated.');
            window.setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            message.textContent = 'Unable to connect. Please try again.';
            saveButton.disabled = false;
            saveButton.textContent = originalLabel;
        }
    });
})();
</script>
<script>
(() => {
    const modal = document.getElementById('user-modal');
    const form = document.getElementById('user-form');
    const title = document.getElementById('user-modal-title');
    const actionInput = document.getElementById('user-form-action');
    const idInput = document.getElementById('user-form-id');
    const detailsFields = document.getElementById('user-details-fields');
    const passwordInput = document.getElementById('user-password');
    const confirmPasswordInput = document.getElementById('user-confirm-password');
    const message = document.getElementById('user-form-message');

    function openModal(mode, user = {}) {
        form.reset();
        message.textContent = '';
        actionInput.value = mode === 'reset' ? 'reset_password' : 'save';
        idInput.value = user.id || 0;
        const resetOnly = mode === 'reset';
        detailsFields.hidden = resetOnly;
        detailsFields.querySelectorAll('input, select').forEach((input) => { input.disabled = resetOnly; });
        // FIX (Add/Edit User modal never opened): this used to write to #user-password-optional,
        // an element that no longer exists now that the shared passwordField() renders its own
        // label — the write threw a TypeError and the modal never appeared. The label's text is
        // updated instead, keeping the "optional when editing" hint.
        const passwordLabel = document.querySelector('label[for="user-password"]');
        if (passwordLabel) {
            passwordLabel.textContent = mode === 'edit' ? 'Password (optional)' : 'Password';
        }
        passwordInput.required = mode === 'add' || resetOnly;
        confirmPasswordInput.required = mode === 'add' || resetOnly;
        title.textContent = resetOnly ? `Reset password for ${user.username}` : mode === 'edit' ? 'Edit User' : 'Add User';
        document.getElementById('user-modal-save').textContent = resetOnly ? 'Reset Password' : mode === 'edit' ? 'Save Changes' : 'Add User';
        if (mode === 'edit') {
            document.getElementById('user-full-name').value = user.fullName || '';
            document.getElementById('user-username').value = user.username || '';
            document.getElementById('user-email').value = user.email || '';
            document.getElementById('user-role').value = user.role || 'staff';
        }
        modal.classList.add('visible');
        modal.setAttribute('aria-hidden', 'false');
        (resetOnly ? passwordInput : document.getElementById('user-full-name')).focus();
    }

    function closeModal() {
        modal.classList.remove('visible');
        modal.setAttribute('aria-hidden', 'true');
    }

    async function submitAction(values) {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
            body: new URLSearchParams(values).toString()
        });
        return response.json();
    }

    document.getElementById('add-user-button').addEventListener('click', () => openModal('add'));
    document.getElementById('refresh-users-button').addEventListener('click', () => window.location.reload());
    document.querySelectorAll('.user-resend-button').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const result = await submitAction({
                    action: 'resend_verification',
                    user_id: button.dataset.id,
                    csrf_token: form.elements.namedItem('csrf_token').value
                });
                if (result.success) {
                    if (window.showToast) { window.showToast(result.message); } else { window.alert(result.message); }
                    window.location.reload();
                    return;
                }
                if (window.showToast) { window.showToast(result.message || 'Unable to resend the email.'); } else { window.alert(result.message); }
                button.disabled = false;
            } catch (error) {
                window.alert('Unable to connect. Please try again.');
                button.disabled = false;
            }
        });
    });
    document.querySelectorAll('.user-edit-button').forEach((button) => {
        button.addEventListener('click', () => openModal('edit', {
            id: button.dataset.id,
            fullName: button.dataset.fullName,
            username: button.dataset.username,
            email: button.dataset.email,
            role: button.dataset.role
        }));
    });
    document.querySelectorAll('.user-reset-button').forEach((button) => {
        button.addEventListener('click', () => openModal('reset', { id: button.dataset.id, username: button.dataset.username }));
    });
    document.getElementById('user-modal-close').addEventListener('click', closeModal);
    document.getElementById('user-modal-cancel').addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal.classList.contains('visible')) closeModal(); });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        message.textContent = '';
        const values = Object.fromEntries(new FormData(form).entries());
        try {
            const result = await submitAction(values);
            if (!result.success) {
                message.textContent = result.message || 'Unable to save user.';
                return;
            }
            window.location.reload();
        } catch (error) {
            message.textContent = 'Unable to connect. Please try again.';
        }
    });

    document.querySelectorAll('.user-status-button').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const result = await submitAction({
                    action: 'toggle_status',
                    user_id: button.dataset.id,
                    status: button.dataset.status,
                    csrf_token: form.elements.namedItem('csrf_token').value
                });
                if (result.success) {
                    window.location.reload();
                } else {
                    window.alert(result.message || 'Unable to change user status.');
                    button.disabled = false;
                }
            } catch (error) {
                window.alert('Unable to connect. Please try again.');
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('.user-delete-button').forEach((button) => {
        button.addEventListener('click', () => {
            window.confirmAction({
                title: 'Delete this user?',
                message: `Permanently delete ${button.dataset.username}? This cannot be undone.`,
                confirmLabel: 'Delete user',
                requireText: 'DELETE',
                onConfirm: async (confirmed) => {
                    if (!confirmed) return;
                    button.disabled = true;
                    try {
                        const result = await submitAction({
                            action: 'delete',
                            user_id: button.dataset.id,
                            csrf_token: form.elements.namedItem('csrf_token').value
                        });
                        if (result.success) {
                            window.location.reload();
                        } else {
                            window.alert(result.message || 'Unable to delete user.');
                            button.disabled = false;
                        }
                    } catch (error) {
                        window.alert('Unable to connect. Please try again.');
                        button.disabled = false;
                    }
                }
            });
        });
    });
})();
</script>
<!-- FIX (Task4): the shared eye toggle + passwords-match message for the modal's password fields. -->
<script src="assets/js/login-auth.js"></script>
<?php include 'includes/footer.php'; ?>
